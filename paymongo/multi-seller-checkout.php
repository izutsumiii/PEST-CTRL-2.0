<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once 'config.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: ../login.php");
    exit();
}

$userId = $_SESSION['user_id'];

// Check if this is a buy now request
$isBuyNow = isset($_GET['buy_now']) && $_GET['buy_now'] == '1';
$buyNowItem = null;

if ($isBuyNow && isset($_SESSION['buy_now_item'])) {
    $buyNowItem = $_SESSION['buy_now_item'];
    // Clear the buy now session after retrieving it
    unset($_SESSION['buy_now_item']);
}

// Load user profile for auto-fill
$userProfile = null;
try {
    $stmt = $pdo->prepare("SELECT first_name, last_name, email, phone, address FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userProfile = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $userProfile = null;
}

// Get cart items grouped by seller
// Support selected-only checkout via query param ?selected=1,2,3
$selectedIds = [];
if (!empty($_GET['selected'])) {
    $selectedIds = array_values(array_filter(array_map('intval', explode(',', $_GET['selected']))));
}

// If this is a buy now request, create a single-item cart
if ($isBuyNow && $buyNowItem) {
    // Get seller info for the buy now item
    $stmt = $pdo->prepare("SELECT seller_id FROM products WHERE id = ?");
    $stmt->execute([$buyNowItem['id']]);
    $sellerId = $stmt->fetchColumn();
    
    if ($sellerId) {
        // Get seller info
        $stmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
        $stmt->execute([$sellerId]);
        $seller = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Create single-item group
        $groupedItems = [
            $sellerId => [
                'seller_id' => $sellerId,
                'seller_name' => $seller['username'] ?? 'Unknown Seller',
                'seller_display_name' => $seller['username'] ?? 'Unknown Seller',
                'seller_email' => $seller['email'] ?? '',
                'items' => [
                    [
                        'product_id' => $buyNowItem['id'],
                        'name' => $buyNowItem['name'],
                        'price' => $buyNowItem['price'],
                        'quantity' => $buyNowItem['quantity'],
                        'image_url' => $buyNowItem['image_url'],
                        'seller_id' => $sellerId
                    ]
                ],
                'subtotal' => $buyNowItem['total'],
                'item_count' => $buyNowItem['quantity']
            ]
        ];
    } else {
        $groupedItems = [];
    }
} else {
    $groupedItems = getCartItemsGroupedBySeller();
}
if (!empty($selectedIds)) {
    // Filter items to selected product IDs only, recompute subtotals and item counts
    foreach ($groupedItems as $sid => &$sg) {
        $sg['items'] = array_values(array_filter($sg['items'], function($it) use ($selectedIds) {
            return in_array((int)$it['product_id'], $selectedIds, true);
        }));
        $sg['subtotal'] = 0;
        $sg['item_count'] = 0;
        foreach ($sg['items'] as $it) {
            $sg['subtotal'] += $it['price'] * $it['quantity'];
            $sg['item_count'] += $it['quantity'];
        }
        if (empty($sg['items'])) {
            unset($groupedItems[$sid]);
        }
    }                       
    unset($sg);
}

// Recompute grand total from filtered groups
$grandTotal = 0;
foreach ($groupedItems as $sg) { $grandTotal += $sg['subtotal']; }

// Handle form submission
$errors = [];
$success = false;

// Handle remove item via AJAX (before any output)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_item') {
    header('Content-Type: application/json');
    if (!isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit();
    }
    $productId = (int)($_POST['product_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
        $stmt->execute([$_SESSION['user_id'], $productId]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error removing item']);
    }
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
    $shippingAddress = trim($_POST['shipping_address'] ?? '');
    $paymentMethod = $_POST['payment_method'] ?? '';
    $customerName = trim($_POST['customer_name'] ?? '');
    $customerEmail = trim($_POST['customer_email'] ?? '');
    $customerPhone = trim($_POST['customer_phone'] ?? '');
    
    // Validation
    if (empty($shippingAddress)) {
        $errors[] = 'Shipping address is required';
    }
    if (empty($paymentMethod) || $paymentMethod === '') {
        $errors[] = 'Please select a payment method';
    }
    
    if (empty($errors)) {
        // Process multi-seller checkout
        if (method_exists($pdo, 'query')) { ensureAutoIncrementPrimary('payment_transactions'); ensureAutoIncrementPrimary('orders'); }
        $result = processMultiSellerCheckout($shippingAddress, $paymentMethod, $customerName, $customerEmail, $customerPhone);
        
        if ($result['success']) {
            // Route PayMongo payments to PayMongo checkout
            if (in_array($paymentMethod, ['card', 'gcash', 'paymaya', 'grab_pay', 'billease'])) {
                error_log('Attempting PayMongo checkout for transaction: ' . $result['payment_transaction_id']);
                $redirectUrl = createPayMongoCheckoutSession($result['payment_transaction_id'], $customerName, $customerEmail, $customerPhone, $shippingAddress, $groupedItems, $grandTotal, $paymentMethod);
                if ($redirectUrl) {
                    error_log('PayMongo redirect URL: ' . $redirectUrl);
                    header("Location: " . $redirectUrl);
                    exit();
                } else {
                    // Fallback: redirect to payment page if PayMongo fails
                    error_log('PayMongo checkout failed for transaction: ' . $result['payment_transaction_id'] . ' - redirecting to payment page');
                    header("Location: multi-seller-payment.php?transaction_id=" . $result['payment_transaction_id']);
                    exit();
                }
            } else {
                // COD payments - update payment status to completed and go directly to success page
                try {
                    $stmt = $pdo->prepare("UPDATE payment_transactions SET status = 'completed' WHERE id = ?");
                    $stmt->execute([$result['payment_transaction_id']]);
                    
                    $stmt = $pdo->prepare("UPDATE orders SET payment_status = 'completed' WHERE payment_transaction_id = ?");
                    $stmt->execute([$result['payment_transaction_id']]);
                    
                    // Create notification for COD order
                    if (function_exists('createOrderNotification')) {
                        $stmt = $pdo->prepare("SELECT id FROM orders WHERE payment_transaction_id = ?");
                        $stmt->execute([$result['payment_transaction_id']]);
                        $orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        
                        foreach ($orderIds as $orderId) {
                            $message = "Order #" . str_pad($orderId, 6, '0', STR_PAD_LEFT) . " has been placed successfully with Cash on Delivery (COD).";
                            createOrderNotification($userId, $orderId, $message, 'order_placed');
                            
                            // Create seller notification
                            require_once '../includes/seller_notification_functions.php';
                            
                            // Get all sellers from this order with product details
                            $stmt = $pdo->prepare("
                                SELECT DISTINCT p.seller_id, u.username as seller_name,
                                       GROUP_CONCAT(p.name SEPARATOR ', ') as product_names
                                FROM order_items oi
                                JOIN products p ON oi.product_id = p.id
                                JOIN users u ON p.seller_id = u.id
                                WHERE oi.order_id = ?
                                GROUP BY p.seller_id, u.username
                            ");
                            $stmt->execute([$orderId]);
                            $sellers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            // Notify each seller
                            foreach ($sellers as $seller) {
                                createSellerNotification(
                                    $seller['seller_id'],
                                    '🎉 New Order Received!',
                                    'You have a new order #' . str_pad($orderId, 6, '0', STR_PAD_LEFT) . ' for: ' . $seller['product_names'] . '. Please review and process it.',
                                    'success',
                                    'view-orders.php'
                                );
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log('Error updating COD payment status: ' . $e->getMessage());
                }
                
                header("Location: order-success.php?transaction_id=" . $result['payment_transaction_id']);
                exit();
            }
        } else {
            $errors[] = $result['message'];
        }
    }
}

// If cart is empty, redirect to cart page BEFORE sending any output
if (empty($groupedItems)) {
    header("Location: ../cart.php");
    exit();
}

// If cart is empty, redirect to cart page BEFORE sending any output
if (empty($groupedItems)) {
    header("Location: ../cart.php");
    exit();
}

// Include header only after all potential redirects/headers
require_once '../includes/header.php';
?>

<main style="background: #ffffff; min-height: 100vh; padding: 0 20px 20px 20px;">
<div class="checkout-container">
    <h1>Checkout</h1>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="checkout-content">
        <div class="order-summary">
            <h2>Order Summary</h2>

            <div class="order-items" id="order-items-container">
                <?php foreach ($groupedItems as $sellerId => $sellerGroup): ?>
                    <div class="seller-group" data-seller-id="<?php echo $sellerId; ?>">
                        <div class="seller-header">
                            <h3>
                                <i class="fas fa-store"></i>
                                Seller: <?php echo htmlspecialchars($sellerGroup['seller_display_name']); ?>
                            </h3>
                            <div class="seller-total">
                                Subtotal: ₱<?php echo number_format($sellerGroup['subtotal'], 2); ?>
                            </div>
                        </div>

                        <?php foreach ($sellerGroup['items'] as $item): ?>
                            <div class="order-item" data-product-id="<?php echo $item['product_id']; ?>">
                                <img src="<?php echo htmlspecialchars('../' . $item['image_url']); ?>"
                                     alt="<?php echo htmlspecialchars($item['name']); ?>"
                                     class="item-image">
                                <div class="item-details">
                                    <h4><?php echo htmlspecialchars($item['name']); ?></h4>
                                    <p>Price: ₱<?php echo number_format($item['price'], 2); ?></p>
                                    <p>Quantity: <?php echo $item['quantity']; ?></p>
                                </div>
                                <div class="item-total">
                                    ₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?>
                                </div>
                                <button class="remove-item-btn" 
                                        data-product-id="<?php echo $item['product_id']; ?>"
                                        title="Remove item">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="order-total" id="order-total">
                <strong>Grand Total: ₱<?php echo number_format($grandTotal, 2); ?></strong>
            </div>
        </div>

        <div class="checkout-form" id="checkout-form">
            <h2>Billing & Shipping Information</h2>

            <?php if ($userProfile): ?>
                <div class="alert alert-info">
                    <strong>Information Auto-filled:</strong> Your profile information has been automatically filled. 
                    <a href="#" id="useProfileBtn" style="margin-left:10px; color: #1976d2; text-decoration: underline; font-weight: 600;">Use My Profile</a>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="ms-checkout-form">
                <div class="form-group">
                    <label for="customer_name">Full Name</label>
                    <input type="text" id="customer_name" name="customer_name"
                           value="<?php echo htmlspecialchars($_POST['customer_name'] ?? (trim(($userProfile['first_name'] ?? '') . ' ' . ($userProfile['last_name'] ?? '')))); ?>">
                </div>

                <div class="form-group">
                    <label for="customer_email">Email Address</label>
                    <input type="email" id="customer_email" name="customer_email"
                           value="<?php echo htmlspecialchars($_POST['customer_email'] ?? ($userProfile['email'] ?? '')); ?>">
                </div>

                <div class="form-group">
                    <label for="customer_phone">Phone Number</label>
                    <input type="tel" id="customer_phone" name="customer_phone"
                           value="<?php echo htmlspecialchars($_POST['customer_phone'] ?? ($userProfile['phone'] ?? '')); ?>">
                </div>

                <div class="form-group">
                    <label for="shipping_address">Shipping Address *</label>
                    <textarea id="shipping_address" name="shipping_address" rows="3" required><?php echo htmlspecialchars($_POST['shipping_address'] ?? ($userProfile['address'] ?? '')); ?></textarea>
                </div>

                <div class="form-group payment-method-group">
                    <label for="payment_method" class="payment-method-label">Payment Method *</label>
                    <div id="payment-warning" class="payment-warning" style="display: none;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Important:</strong> Please select your preferred payment method. This cannot be changed after checkout.
                    </div>
                    <select id="payment_method" name="payment_method" required>
                        <option value="">Please select a payment method</option>
                        <option value="card" <?php echo ($_POST['payment_method'] ?? '') === 'card' ? 'selected' : ''; ?>>Debit/Credit Card</option>
                        <option value="gcash" <?php echo ($_POST['payment_method'] ?? '') === 'gcash' ? 'selected' : ''; ?>>GCash</option>
                        <option value="paymaya" <?php echo ($_POST['payment_method'] ?? '') === 'paymaya' ? 'selected' : ''; ?>>PayMaya</option>
                        <option value="grab_pay" <?php echo ($_POST['payment_method'] ?? '') === 'grab_pay' ? 'selected' : ''; ?>>GrabPay</option>
                        <option value="billease" <?php echo ($_POST['payment_method'] ?? '') === 'billease' ? 'selected' : ''; ?>>Billease</option>
                        <option value="cod" <?php echo ($_POST['payment_method'] ?? '') === 'cod' ? 'selected' : ''; ?>>Cash on Delivery (COD)</option>
                    </select>
                    <small class="payment-method-note">Choose your preferred payment method to continue</small>
                </div>

                <div class="form-actions">
                    <a href="../cart.php" class="btn btn-secondary">Back to Cart</a>
                    <button type="button" id="reviewOrderBtn" class="btn btn-yellow">
                        <i class="fas fa-eye"></i> Review Order
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
</main>

<!-- Custom Remove Confirmation Popup -->
<div id="removeConfirmModal" class="remove-confirm-modal" style="display: none;">
    <div class="remove-confirm-content">
        <div class="remove-confirm-header">
            <h3>Remove Item</h3>
            <button class="close-btn" onclick="hideRemoveConfirm()">&times;</button>
        </div>
        <div class="remove-confirm-body">
            <p>Are you sure you want to remove this item from checkout?</p>
        </div>
        <div class="remove-confirm-footer">
            <button class="btn-cancel" onclick="hideRemoveConfirm()">Cancel</button>
            <button class="btn-confirm" onclick="confirmRemove()">Remove Item</button>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>

<style>
/* Custom Remove Confirmation Popup Styles */
.remove-confirm-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    z-index: 10000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.remove-confirm-content {
    background: #f0f0f0;
    border: 1px solid var(--border-light);
    border-radius: 12px;
    max-width: 400px;
    width: 90%;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
    animation: popupSlideIn 0.3s ease-out;
}

.remove-confirm-header {
    background: var(--primary-dark);
    padding: 20px;
    border-radius: 12px 12px 0 0;
    border-bottom: 1px solid var(--border-light);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.remove-confirm-header h3 {
    color: #ffffff;
    margin: 0;
    font-size: 1.3rem;
    font-weight: 700;
}

.close-btn {
    background: none;
    border: none;
    color: #ffffff;
    font-size: 24px;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.3s;
}

.close-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: scale(1.1);
}

.remove-confirm-body {
    padding: 25px 20px;
    text-align: center;
}

.remove-confirm-body p {
    color: var(--text-dark);
    margin: 0;
    font-size: 1.1rem;
    line-height: 1.5;
}

.remove-confirm-footer {
    padding: 20px;
    display: flex;
    gap: 15px;
    justify-content: center;
}

.btn-cancel, .btn-confirm {
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    font-size: 1rem;
}

.btn-cancel {
    background: #6c757d;
    color: #ffffff;
}

.btn-cancel:hover {
    background: #5a6268;
    transform: translateY(-2px);
}

.btn-confirm {
    background: #dc3545;
    color: #ffffff;
}

.btn-confirm:hover {
    background: #c82333;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
}

@keyframes popupSlideIn {
    from {
        opacity: 0;
        transform: scale(0.8) translateY(-50px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}

/* Modern White Container Styles */
:root {
    --primary-dark: #130325;
    --primary-light: #ffffff;
    --accent-yellow: #FFD736;
    --text-dark: #130325;
    --border-light: #e9ecef;
}

body { 
    background: #ffffff !important; 
    min-height: 100vh; 
    color: var(--text-dark);
}

.checkout-container { 
    max-width: 1200px; 
    margin: 0 auto; 
    padding: 10px 20px 20px 20px; 
}

h1 { 
    color: var(--text-dark); 
    text-align: center; 
    margin: 20px 0; 
    font-size: 1.6rem; 
    border-bottom: 3px solid var(--primary-dark); 
    padding-bottom: 10px; 
}

.alert { 
    padding: 12px 16px; 
    border-radius: 8px; 
    margin-bottom: 20px; 
    border: 1px solid var(--border-light);
}

.alert-info { 
    background-color: #e3f2fd; 
    border: 1px solid #2196f3; 
    color: #1976d2; 
}

.alert-warning { 
    background-color: #fff3e0; 
    border: 1px solid #ff9800; 
    color: #f57c00; 
}

.alert-error { 
    background-color: #ffebee; 
    border: 1px solid #f44336; 
    color: #d32f2f; 
}

.alert ul { 
    margin: 0; 
    padding-left: 20px; 
}

.checkout-content { 
    display: grid; 
    grid-template-columns: 1fr 1fr; 
    gap: 40px; 
    margin-top: 20px; 
}

.order-summary { 
    background-color: #f0f0f0; 
    border: 1px solid var(--border-light); 
    padding: 25px; 
    border-radius: 12px; 
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
}

.order-items { 
    margin: 20px 0; 
}

.order-item { 
    display: flex; 
    align-items: center; 
    padding: 15px 0; 
    border-bottom: 1px solid var(--border-light); 
    position: relative; 
}

.order-item:last-child { 
    border-bottom: none; 
}

.item-image { 
    width: 60px; 
    height: 60px; 
    object-fit: cover; 
    border-radius: 8px; 
    margin-right: 15px; 
    border: 2px solid var(--border-light);
}

.item-details { 
    flex-grow: 1; 
}

.item-details h4 { 
    margin: 0 0 5px 0; 
    font-size: 16px; 
    color: var(--text-dark);
    font-weight: 600;
}

.item-details p { 
    margin: 2px 0; 
    color: #6c757d; 
    font-size: 14px; 
}

/* Make all price text dark */
.item-details p, .review-item-info, .seller-total, .item-total, .order-total, .review-subtotal {
    color: var(--text-dark) !important;
}

.item-total { 
    font-weight: bold; 
    font-size: 16px; 
    margin-right: 10px; 
    color: var(--text-dark) !important; 
}

.remove-item-btn { 
    background-color: #dc3545; 
    color: white; 
    border: none; 
    border-radius: 50%; 
    width: 24px; 
    height: 24px; 
    font-size: 16px; 
    font-weight: bold; 
    cursor: pointer; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    transition: all 0.2s ease; 
    line-height: 1; 
}

.remove-item-btn:hover { 
    background-color: #c82333; 
    transform: scale(1.1); 
}

.remove-item-btn:disabled { 
    background-color: #6c757d; 
    cursor: not-allowed; 
    transform: none; 
}

.order-total { 
    border-top: 2px solid var(--primary-dark); 
    padding-top: 15px; 
    font-size: 18px; 
    text-align: right; 
    color: var(--text-dark) !important; 
}

.checkout-form { 
    background-color: #f0f0f0; 
    border: 1px solid var(--border-light); 
    padding: 25px; 
    border-radius: 12px; 
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
}

.checkout-form h2, .order-summary h2 { 
    color: var(--text-dark); 
    border-bottom: 2px solid var(--primary-dark); 
    padding-bottom: 10px; 
    margin-bottom: 20px; 
    font-weight: 600;
    font-size: 1.3rem;
}

.form-group { 
    margin-bottom: 20px; 
}

.form-group label { 
    color: var(--text-dark); 
    font-weight: 600; 
    margin-bottom: 5px; 
    display: block; 
}

.form-group input, .form-group textarea, .form-group select { 
    width: 100%; 
    padding: 12px 16px; 
    border: 1px solid var(--border-light); 
    border-radius: 8px; 
    font-size: 14px; 
    transition: border-color 0.3s; 
    background: #ffffff;
    color: var(--text-dark);
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.form-group input:focus, .form-group textarea:focus, .form-group select:focus { 
    outline: none; 
    border-color: var(--primary-dark); 
    box-shadow: 0 0 0 2px rgba(19, 3, 37, 0.1); 
}

#payment_method { 
    background: #ffffff; 
    border: 1px solid var(--border-light); 
    color: var(--text-dark); 
    font-size: 15px; 
    padding: 12px 16px; 
    border-radius: 8px; 
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

#payment_method:focus { 
    border-color: var(--primary-dark); 
    box-shadow: 0 0 0 2px rgba(19, 3, 37, 0.1); 
}

#payment_method option { 
    background: #ffffff; 
    color: var(--text-dark); 
    padding: 8px; 
}

#payment_method.error { 
    border-color: #dc3545 !important; 
    box-shadow: 0 0 0 2px rgba(220, 53, 69, 0.1) !important; 
    background-color: rgba(220, 53, 69, 0.05) !important;
}

#payment_method.error:focus { 
    border-color: #dc3545 !important; 
    box-shadow: 0 0 0 2px rgba(220, 53, 69, 0.1) !important; 
}

.payment-method-group { 
    background: #f5f5f5; 
    border: 1px solid var(--border-light); 
    border-radius: 8px; 
    padding: 16px; 
    margin: 20px 0; 
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.payment-method-label { 
    font-size: 16px; 
    font-weight: 600; 
    color: var(--text-dark); 
    margin-bottom: 8px; 
    display: block; 
}

.payment-method-note { 
    color: #6c757d; 
    font-size: 13px; 
    margin-top: 6px; 
    display: block; 
}

.payment-warning { 
    background: #fff3e0; 
    border: 1px solid #ff9800; 
    border-radius: 8px; 
    padding: 12px; 
    margin: 10px 0; 
    color: #f57c00; 
    font-size: 14px; 
    display: flex; 
    align-items: center; 
    gap: 8px; 
}

.payment-warning i { 
    font-size: 16px; 
}

.payment-warning strong { 
    color: #f57c00; 
}

/* Custom Notification Styles */
.custom-notification {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 10000;
    max-width: 400px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    animation: slideInRight 0.3s ease-out;
}

.custom-notification-warning {
    background: linear-gradient(135deg, #ffc107, #ff8f00);
    border: 1px solid #ff8f00;
    color: #1a0a2e;
}

.custom-notification-info {
    background: linear-gradient(135deg, #17a2b8, #138496);
    border: 1px solid #138496;
    color: white;
}

.notification-content {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 20px;
    font-weight: 500;
}

.notification-content i {
    font-size: 18px;
    flex-shrink: 0;
}

.notification-content span {
    flex-grow: 1;
    line-height: 1.4;
}

.notification-close {
    background: none;
    border: none;
    color: inherit;
    font-size: 20px;
    font-weight: bold;
    cursor: pointer;
    padding: 0;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: background-color 0.2s;
    flex-shrink: 0;
}

.notification-close:hover {
    background-color: rgba(0, 0, 0, 0.1);
}

@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@media (max-width: 768px) {
    .custom-notification {
        top: 10px;
        right: 10px;
        left: 10px;
        max-width: none;
    }
}
.form-actions { 
    display: flex; 
    justify-content: space-between; 
    gap: 15px; 
    margin-top: 30px; 
}

.btn { 
    padding: 12px 24px; 
    border: none; 
    border-radius: 8px; 
    cursor: pointer; 
    font-size: 14px; 
    text-decoration: none; 
    text-align: center; 
    transition: all 0.3s; 
    font-weight: 600;
}

.btn-secondary { 
    background-color: #6c757d; 
    color: white; 
}

.btn-secondary:hover { 
    background-color: #5a6268; 
    transform: translateY(-1px);
}

.btn-primary { 
    background-color: var(--primary-dark); 
    color: white; 
    font-weight: 600; 
}

.btn-primary:hover { 
    background-color: #0f0220; 
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(19, 3, 37, 0.3);
}

.btn-yellow { 
    background-color: var(--accent-yellow); 
    color: var(--text-dark); 
    font-weight: 600; 
}

.btn-yellow:hover { 
    background-color: #e6c230; 
    transform: translateY(-1px); 
    box-shadow: 0 4px 12px rgba(255, 215, 54, 0.3); 
}
/* Seller grouping visuals with white containers */
.seller-group { 
    margin-bottom: 30px; 
    border: 1px solid var(--border-light); 
    border-radius: 12px; 
    padding: 20px; 
    background: #f0f0f0; 
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
}

.seller-header { 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    padding-bottom: 15px; 
    margin-bottom: 15px; 
    border-bottom: 2px solid var(--primary-dark); 
}

.seller-header h3 { 
    color: var(--text-dark); 
    margin: 0; 
    font-size: 18px; 
    display: flex; 
    align-items: center; 
    gap: 8px; 
    font-weight: 600;
}

.seller-total { 
    color: var(--text-dark) !important; 
    font-weight: bold; 
    font-size: 16px; 
}

.seller-group .order-item { 
    border-bottom: 1px solid var(--border-light); 
}

.seller-group .order-item:last-child { 
    border-bottom: none; 
}
@media (max-width: 768px) {
  .checkout-content { grid-template-columns: 1fr; gap: 20px; }
  .form-actions { flex-direction: column; }
  .order-item { flex-direction: column; text-align: center; padding: 20px 15px; }
  .item-image { margin: 0 0 10px 0; }
  .item-total { margin-right: 0; margin-top: 10px; }
  .remove-item-btn { position: absolute; top: 10px; right: 10px; }
}
</style>

<style>
/* Review/payment modals with white containers */
.review-modal { 
    position: fixed; 
    inset: 0; 
    z-index: 100000; 
    background: rgba(0,0,0,0.8); 
    display: none; 
    overflow-y: auto; 
}

.review-modal-content { 
    background-color: #f0f0f0; 
    margin: 2% auto; 
    padding: 30px; 
    border: 1px solid var(--border-light); 
    border-radius: 12px; 
    width: 90%; 
    max-width: 900px; 
    max-height: 90vh; 
    overflow-y: auto; 
    animation: slideDown 0.3s ease; 
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
}

.review-modal-header { 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    margin-bottom: 25px; 
    padding-bottom: 15px; 
    border-bottom: 2px solid var(--primary-dark); 
}

.review-modal-header h2 { 
    color: var(--text-dark); 
    margin: 0; 
    font-weight: 600;
}

.close-modal { 
    color: var(--text-dark); 
    font-size: 32px; 
    font-weight: bold; 
    cursor: pointer; 
    transition: color 0.3s; 
    line-height: 1; 
}

.close-modal:hover { 
    color: var(--primary-dark); 
}

.review-section h3 { 
    color: var(--text-dark); 
    margin-bottom: 15px; 
    font-size: 20px; 
    font-weight: 600;
}

.review-info-grid { 
    display: grid; 
    grid-template-columns: repeat(2, 1fr); 
    gap: 15px; 
    background: #f5f5f5; 
    padding: 20px; 
    border-radius: 8px; 
    border: 1px solid var(--border-light); 
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.review-info-item { 
    color: var(--text-dark); 
}

.review-info-item strong { 
    color: var(--primary-dark); 
    display: block; 
    margin-bottom: 5px; 
    font-weight: 600;
}

.review-seller-group { 
    background: #f5f5f5; 
    border: 1px solid var(--border-light); 
    border-radius: 8px; 
    padding: 20px; 
    margin-bottom: 20px; 
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.review-seller-header { 
    color: var(--text-dark); 
    font-size: 18px; 
    margin-bottom: 15px; 
    padding-bottom: 10px; 
    border-bottom: 1px solid var(--border-light); 
    font-weight: 600;
}

.review-item { 
    display: flex; 
    align-items: center; 
    padding: 12px 0; 
    border-bottom: 1px solid var(--border-light); 
    color: var(--text-dark); 
}

.review-item:last-child { 
    border-bottom: none; 
}
.review-item img { 
    width: 50px; 
    height: 50px; 
    object-fit: cover; 
    border-radius: 8px; 
    margin-right: 15px; 
    border: 2px solid var(--border-light);
}

.review-item-details { 
    flex-grow: 1; 
}

.review-item-name { 
    font-weight: 600; 
    margin-bottom: 5px; 
    color: var(--text-dark);
}

.review-item-info { 
    font-size: 14px; 
    color: #6c757d; 
}

.review-subtotal { 
    text-align: right; 
    padding-top: 10px; 
    margin-top: 10px; 
    border-top: 1px solid var(--border-light); 
    color: var(--text-dark) !important; 
    font-weight: bold; 
}
.review-grand-total { 
    text-align: right; 
    font-size: 24px; 
    color: var(--text-dark); 
    margin-top: 20px; 
    padding-top: 20px; 
    border-top: 2px solid var(--primary-dark); 
    font-weight: 700;
}

.review-actions { 
    display: flex; 
    gap: 15px; 
    justify-content: flex-end; 
    margin-top: 30px; 
}

.btn.btn-primary { 
    background-color: var(--primary-dark); 
    color: #fff; 
    font-weight: 600; 
}

.btn.btn-primary:hover { 
    background-color: #0f0220; 
    transform: translateY(-1px);
}

.btn.btn-secondary { 
    background-color: #6c757d; 
    color: #fff; 
}

.btn.btn-secondary:hover { 
    background-color: #5a6268; 
    transform: translateY(-1px);
}
@keyframes slideDown { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
@media (max-width: 768px) { .review-modal-content { width:95%; padding:20px; margin:5% auto; } .review-info-grid { grid-template-columns: 1fr; } .review-actions { flex-direction: column; } .review-actions .btn { width:100%; } }
</style>

<script>
// Replace the existing JavaScript section with this enhanced version

// Custom notification function
function showCustomNotification(message, type = 'info') {
    // Remove existing notifications
    const existingNotifications = document.querySelectorAll('.custom-notification');
    existingNotifications.forEach(notification => notification.remove());
    
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `custom-notification custom-notification-${type}`;
    notification.innerHTML = `
        <div class="notification-content">
            <i class="fas fa-${type === 'warning' ? 'exclamation-triangle' : 'info-circle'}"></i>
            <span>${message}</span>
            <button class="notification-close" onclick="this.parentElement.parentElement.remove()">×</button>
        </div>
    `;
    
    // Add to page
    document.body.appendChild(notification);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 5000);
}

document.addEventListener('DOMContentLoaded', function() {
    const paymentMethodSelect = document.getElementById('payment_method');
    const paymentWarning = document.getElementById('payment-warning');
    const reviewBtn = document.getElementById('reviewOrderBtn');
    const checkoutForm = document.getElementById('ms-checkout-form');
    
    // Hide warning and reset border when payment method is selected
    if (paymentMethodSelect) {
        paymentMethodSelect.addEventListener('change', function() {
            if (this.value && this.value !== '') {
                paymentWarning.style.display = 'none';
                this.classList.remove('error');
                this.style.borderColor = '#ced4da';
            }
        });
    }
    
    // Function to validate payment method
    function validatePaymentMethod() {
        const paymentMethod = paymentMethodSelect.value;
        
        if (!paymentMethod || paymentMethod === '') {
            // Show warning message
            paymentWarning.style.display = 'flex';
            
            // Highlight the payment method field
            paymentMethodSelect.classList.add('error');
            paymentMethodSelect.style.borderColor = '#dc3545';
            paymentMethodSelect.focus();
            
            // Scroll to payment method section smoothly
            paymentMethodSelect.scrollIntoView({ 
                behavior: 'smooth', 
                block: 'center' 
            });
            
             // Show custom styled notification
             showCustomNotification('Please select a payment method before proceeding with checkout.', 'warning');
            
            return false;
        }
        
        return true;
    }
    
    // Validate on Review Order button click
    if (reviewBtn) {
        reviewBtn.addEventListener('click', function(e) {
            // Prevent default action if validation fails
            if (!validatePaymentMethod()) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
            
            // Proceed with opening review modal (existing code below)
            const modal = document.createElement('div');
            modal.className = 'review-modal';
            modal.id = 'msReviewModal';

            const name = (document.getElementById('customer_name')?.value || '').trim();
            const email = (document.getElementById('customer_email')?.value || '').trim();
            const phone = (document.getElementById('customer_phone')?.value || '').trim();
            const address = (document.getElementById('shipping_address')?.value || '').trim();
            const paymentMethod = (document.getElementById('payment_method')?.value || '').trim();

            let sellerGroupsHtml = '';
            const itemsBySeller = <?php echo json_encode($groupedItems); ?>;
            for (const sid in itemsBySeller) {
                const sg = itemsBySeller[sid];
                const itemsHtml = sg.items.map(item => `
                    <div class="review-item">
                        <img src="../${item.image_url || ''}" alt="${item.name}">
                        <div class="review-item-details">
                            <div class="review-item-name">${item.name}</div>
                            <div class="review-item-info">Qty: ${item.quantity} × ₱${parseFloat(item.price).toFixed(2)} = ₱${(item.quantity * item.price).toFixed(2)}</div>
                        </div>
                    </div>
                `).join('');
                sellerGroupsHtml += `
                    <div class="review-seller-group">
                        <div class="review-seller-header">
                            <i class="fas fa-store"></i> Seller: ${sg.seller_display_name}
                        </div>
                        ${itemsHtml}
                        <div class="review-subtotal">Subtotal: ₱${parseFloat(sg.subtotal).toFixed(2)}</div>
                    </div>
                `;
            }

            function pmLabel(method){
                const map = {
                    card: 'Debit/Credit Card',
                    gcash: 'GCash',
                    paymaya: 'PayMaya',
                    grab_pay: 'GrabPay',
                    billease: 'Billease',
                    cod: 'Cash on Delivery (COD)'
                };
                return map[method] || 'Debit/Credit Card';
            }

            modal.innerHTML = `
                <div class="review-modal-content">
                    <div class="review-modal-header">
                        <h2><i class="fas fa-clipboard-check"></i> Review Your Order</h2>
                        <span class="close-modal">&times;</span>
                    </div>
                    <div class="review-section">
                        <h3><i class="fas fa-user"></i> Customer Information</h3>
                        <div class="review-info-grid">
                            <div class="review-info-item"><strong>Name:</strong>${name}</div>
                            <div class="review-info-item"><strong>Email:</strong>${email}</div>
                            <div class="review-info-item"><strong>Phone:</strong>${phone}</div>
                            <div class="review-info-item"><strong>Payment Method:</strong>${pmLabel(paymentMethod)}</div>
                        </div>
                        <div class="review-info-grid" style="margin-top: 15px; grid-template-columns: 1fr;">
                            <div class="review-info-item"><strong>Shipping Address:</strong>${address}</div>
                        </div>
                    </div>
                    <div class="review-section">
                        <h3><i class="fas fa-shopping-cart"></i> Order Items</h3>
                        ${sellerGroupsHtml}
                    </div>
                    <div class="review-grand-total">
                        <strong>Grand Total: ₱<?php echo number_format($grandTotal, 2); ?></strong>
                    </div>
                    <div class="review-actions">
                        <a href="../cart.php" class="btn btn-secondary" id="msReviewBack">Back to Cart</a>
                        <button type="button" class="btn btn-primary" id="msReviewProceed">Checkout</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
            
            const closeEls = [modal.querySelector('.close-modal'), modal.querySelector('#msReviewClose')];
            closeEls.forEach(el => el && el.addEventListener('click', () => { 
                modal.remove(); 
                document.body.style.overflow=''; 
            }));
            
            modal.addEventListener('click', e => { 
                if (e.target === modal) { 
                    modal.remove(); 
                    document.body.style.overflow=''; 
                } 
            });
            
            const proceedBtn = modal.querySelector('#msReviewProceed');
            if (proceedBtn) {
                proceedBtn.addEventListener('click', () => {
                    // Double-check payment method before final submission
                    if (!validatePaymentMethod()) {
                        modal.remove();
                        document.body.style.overflow = '';
                        return;
                    }
                    
                    modal.remove();
                    document.body.style.overflow = '';
                    
                    // Submit the form
                    const checkoutInput = document.createElement('input');
                    checkoutInput.type = 'hidden';
                    checkoutInput.name = 'checkout';
                    checkoutInput.value = '1';
                    checkoutForm.appendChild(checkoutInput);
                    checkoutForm.submit();
                });
            }
        });
    }
    
    // Also validate on direct form submission (backup validation)
    if (checkoutForm) {
        checkoutForm.addEventListener('submit', function(e) {
            if (!validatePaymentMethod()) {
                e.preventDefault();
                return false;
            }
        });
    }
});
</script>

<?php
// PayMongo checkout session creation function
function createPayMongoCheckoutSession($transactionId, $customerName, $customerEmail, $customerPhone, $shippingAddress, $groupedItems, $grandTotal, $paymentMethod = 'card') {
    global $pdo;
    
    try {
        // Build line items for PayMongo
        $lineItems = [];
        foreach ($groupedItems as $sellerGroup) {
            foreach ($sellerGroup['items'] as $item) {
                $lineItems[] = [
                    'currency' => 'PHP',
                    'amount' => (int)($item['price'] * 100), // Convert to centavos
                    'name' => $item['name'],
                    'quantity' => $item['quantity']
                ];
            }
        }
        
        // Build billing information
        $billing = [
            'name' => $customerName,
            'email' => $customerEmail,
            'phone' => $customerPhone,
            'address' => [
                'line1' => $shippingAddress,
                'city' => 'Manila',
                'state' => 'Metro Manila',
                'country' => 'PH',
                'postal_code' => '1000'
            ]
        ];
        
        // PayMongo API call
        $apiUrl = 'https://api.paymongo.com/v1/checkout_sessions';
        $apiKey = PAYMONGO_SECRET_KEY;
        
        // Map payment method to PayMongo payment method types
        $paymentMethodMap = [
            'card' => ['card'],
            'gcash' => ['gcash'],
            'paymaya' => ['paymaya'],
            'grab_pay' => ['grab_pay'],
            'billease' => ['billease']
        ];
        
        $paymentMethodTypes = $paymentMethodMap[$paymentMethod] ?? ['card'];
        
        $payload = [
            'data' => [
                'attributes' => [
                    'line_items' => $lineItems,
                    'payment_method_types' => $paymentMethodTypes,
                    'success_url' => getSuccessUrl($transactionId),
                    'cancel_url' => getCancelUrl(),
                    'billing' => $billing,
                    'reference_number' => 'P-C-' . $transactionId,
                    'statement_descriptor' => 'PEST-CTRL',
                    'send_email_receipt' => true,
                    'show_description' => false
                ]
            ]
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($apiKey . ':')
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        // Log the response for debugging
        error_log('PayMongo API Response - HTTP Code: ' . $httpCode);
        error_log('PayMongo API Response - Body: ' . $response);
        if ($curlError) {
            error_log('PayMongo API cURL Error: ' . $curlError);
        }
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (isset($data['data']['attributes']['checkout_url'])) {
                return $data['data']['attributes']['checkout_url'];
            } else {
                error_log('PayMongo API - No checkout_url in response: ' . json_encode($data));
            }
        } else {
            error_log('PayMongo API - HTTP Error ' . $httpCode . ': ' . $response);
        }
        
        return false;
    } catch (Exception $e) {
        error_log('PayMongo checkout session creation failed: ' . $e->getMessage());
        return false;
    }
}
?>
