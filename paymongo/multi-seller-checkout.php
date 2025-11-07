<?php
// Ensure session is started before anything else
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once 'config.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: ../login.php");
    exit();
}

$userId = $_SESSION['user_id'];

// DEBUG: Check cart immediately
$debugCartCheck = $pdo->prepare("SELECT COUNT(*) as count, SUM(quantity) as total_qty FROM cart WHERE user_id = ?");
$debugCartCheck->execute([$userId]);
$debugCartResult = $debugCartCheck->fetch(PDO::FETCH_ASSOC);

// Get ALL cart items for detailed debugging
$debugAllCart = $pdo->prepare("SELECT * FROM cart WHERE user_id = ?");
$debugAllCart->execute([$userId]);
$debugAllCartItems = $debugAllCart->fetchAll(PDO::FETCH_ASSOC);

error_log('CHECKOUT DEBUG - User ID: ' . $userId);
error_log('CHECKOUT DEBUG - Cart items count: ' . ($debugCartResult['count'] ?? 0));
error_log('CHECKOUT DEBUG - Cart total quantity: ' . ($debugCartResult['total_qty'] ?? 0));
error_log('CHECKOUT DEBUG - GET buy_now param: ' . (isset($_GET['buy_now']) ? $_GET['buy_now'] : 'NOT SET'));
error_log('CHECKOUT DEBUG - Session buy_now_active: ' . (isset($_SESSION['buy_now_active']) ? 'YES' : 'NO'));

// Load user profile for auto-fill
$userProfile = null;
try {
    $stmt = $pdo->prepare("SELECT first_name, last_name, display_name, email, phone, address FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userProfile = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $userProfile = null;
}

// CRITICAL FIX: More lenient Buy Now detection
// Accept if EITHER the GET parameter OR session variable exists
$isBuyNow = (isset($_GET['buy_now']) && $_GET['buy_now'] == '1') || isset($_SESSION['buy_now_active']) || isset($_SESSION['buy_now_data']);
error_log('CHECKOUT - Buy Now Active: ' . ($isBuyNow ? 'YES' : 'NO'));

$groupedItems = [];
$grandTotal = 0;

if ($isBuyNow && isset($_SESSION['buy_now_data'])) {
    // Buy Now mode - use session data ONLY
    error_log('CHECKOUT - Using Buy Now session data');
    
    $buyNowData = $_SESSION['buy_now_data'];
    $sellerId = $buyNowData['seller']['seller_id'];
    
    $groupedItems = [
        $sellerId => [
            'seller_id' => $sellerId,
            'seller_name' => $buyNowData['seller']['seller_name'],
            'seller_display_name' => $buyNowData['seller']['seller_display_name'],
            'items' => [
                $buyNowData['product']
            ],
            'subtotal' => $buyNowData['total'],
            'item_count' => $buyNowData['quantity']
        ]
    ];
    
    $grandTotal = $buyNowData['total'];
    
    error_log('CHECKOUT - Buy Now items loaded: ' . json_encode($groupedItems));
    
} else {
    // Regular checkout - use cart from database
    error_log('CHECKOUT - Using regular cart from database');
    error_log('CHECKOUT - User ID: ' . $userId);
    
    // Primary: fetch cart rows with LEFT JOIN (allows missing products)
    $cartItemsStmt = $pdo->prepare("
        SELECT 
            c.id as cart_id,
            c.product_id,
            c.quantity,
            p.id AS product_exists,
            p.name,
            p.price,
            p.image_url,
            p.stock_quantity,
            p.status,
            p.seller_id,
            COALESCE(u.display_name, CONCAT(u.first_name, ' ', u.last_name), u.username, 'Unknown Seller') as seller_display_name
        FROM cart c
        LEFT JOIN products p ON c.product_id = p.id
        LEFT JOIN users u ON p.seller_id = u.id
        WHERE c.user_id = ?
        ORDER BY p.seller_id IS NULL, p.seller_id, p.name
    ");
    $cartItemsStmt->execute([$userId]);
    $allCartItems = $cartItemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log('CHECKOUT - LEFT JOIN query returned ' . count($allCartItems) . ' rows');
    
    // Check raw cart count
    $rawCartCountStmt = $pdo->prepare("SELECT COUNT(*) as count FROM cart WHERE user_id = ?");
    $rawCartCountStmt->execute([$userId]);
    $rawCartCount = (int)($rawCartCountStmt->fetchColumn() ?? 0);
    error_log('CHECKOUT - Raw cart count (authoritative): ' . $rawCartCount);
    
    $rawCount = ['count' => $rawCartCount];
    
    // If no cart rows at DB level, safe to redirect
    if ($rawCartCount === 0 && !$isBuyNow) {
        error_log('CHECKOUT - Cart truly empty at DB level — redirecting');
        header("Location: ../cart.php?error=cart_empty");
        exit();
    }
    
    // Build groupedItems from allCartItems
    $groupedItems = [];
    $grandTotal = 0;
    
    foreach ($allCartItems as $row) {
        $productId = (int)($row['product_id'] ?? 0);
        $exists = !empty($row['product_exists']);
        $name = $row['name'] ?? ($exists ? 'Unnamed product' : 'Product no longer available');
        $price = isset($row['price']) && $row['price'] !== null ? (float)$row['price'] : 0.0;
        $image = $row['image_url'] ?? 'images/placeholder.jpg';
        $stock = isset($row['stock_quantity']) ? (int)$row['stock_quantity'] : 0;
        $status = $row['status'] ?? ($exists ? 'active' : 'missing');
        $sellerId = isset($row['seller_id']) ? (int)$row['seller_id'] : 0;
        $sellerName = $row['seller_display_name'] ?? 'Unknown Seller';
        $qty = max(0, (int)$row['quantity']);
        
        if (!isset($groupedItems[$sellerId])) {
            $groupedItems[$sellerId] = [
                'seller_id' => $sellerId,
                'seller_display_name' => $sellerName,
                'items' => [],
                'subtotal' => 0,
                'item_count' => 0
            ];
        }
        
        $itemTotal = $price * $qty;
        $groupedItems[$sellerId]['items'][] = [
            'product_id' => $productId,
            'name' => $name,
            'price' => $price,
            'quantity' => $qty,
            'image_url' => $image,
            'stock_quantity' => $stock,
            'status' => $status,
            'product_exists' => $exists
        ];
        
        $groupedItems[$sellerId]['subtotal'] += $itemTotal;
        $groupedItems[$sellerId]['item_count'] += $qty;
        $grandTotal += $itemTotal;
    }
    
    error_log('CHECKOUT - After grouping: ' . count($groupedItems) . ' seller groups, grandTotal = ' . $grandTotal);
    
    // Emergency recovery if needed
    if (empty($groupedItems) && $rawCartCount > 0) {
        error_log('CHECKOUT - Emergency recovery: rawCartCount > 0 but groupedItems empty');
        $emergencyStmt = $pdo->prepare("
            SELECT c.product_id, c.quantity, p.name, p.price, p.image_url, p.stock_quantity, p.status, p.seller_id,
                   COALESCE(u.display_name, CONCAT(u.first_name, ' ', u.last_name), u.username, 'Unknown Seller') as seller_display_name
            FROM cart c
            INNER JOIN products p ON c.product_id = p.id
            LEFT JOIN users u ON p.seller_id = u.id
            WHERE c.user_id = ?
        ");
        $emergencyStmt->execute([$userId]);
        $emergencyItems = $emergencyStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($emergencyItems as $item) {
            $sId = (int)($item['seller_id'] ?? 0);
            if (!isset($groupedItems[$sId])) {
                $groupedItems[$sId] = [
                    'seller_id' => $sId,
                    'seller_display_name' => $item['seller_display_name'] ?? 'Unknown Seller',
                    'items' => [],
                    'subtotal' => 0,
                    'item_count' => 0
                ];
            }
            $qty = max(0, (int)$item['quantity']);
            $price = isset($item['price']) ? (float)$item['price'] : 0.0;
            $itTotal = $price * $qty;
            
            $groupedItems[$sId]['items'][] = [
                'product_id' => (int)$item['product_id'],
                'name' => $item['name'] ?? 'Unknown Product',
                'price' => $price,
                'quantity' => $qty,
                'image_url' => $item['image_url'] ?? 'images/placeholder.jpg',
                'stock_quantity' => (int)($item['stock_quantity'] ?? 0),
                'status' => $item['status'] ?? 'active',
                'product_exists' => 1
            ];
            
            $groupedItems[$sId]['subtotal'] += $itTotal;
            $groupedItems[$sId]['item_count'] += $qty;
            $grandTotal += $itTotal;
        }
        error_log('CHECKOUT - Emergency rebuild: ' . count($groupedItems) . ' seller groups');
    }
}

error_log('CHECKOUT - FINAL RESULT: ' . count($groupedItems) . ' seller groups, grandTotal: ' . $grandTotal);

// CRITICAL: Save backup BEFORE any filtering
$groupedItemsBackup = [];
$itemsCountBeforeFilter = 0;

// ALWAYS save backup regardless of isBuyNow
foreach ($groupedItems as $sid => $sg) {
    $groupedItemsBackup[$sid] = [
        'seller_id' => $sg['seller_id'],
        'seller_display_name' => $sg['seller_display_name'],
        'items' => $sg['items'], // Deep copy
        'subtotal' => $sg['subtotal'],
        'item_count' => $sg['item_count']
    ];
    $itemsCountBeforeFilter += count($sg['items'] ?? []);
}
error_log('CHECKOUT - Backup saved: ' . $itemsCountBeforeFilter . ' items in ' . count($groupedItemsBackup) . ' seller groups');

// Handle selected items filtering ONLY if ?selected= parameter exists
if (!$isBuyNow && !empty($_GET['selected'])) {
    $selectedIds = array_values(array_filter(array_map('intval', explode(',', $_GET['selected']))));
    error_log('CHECKOUT - Filtering to selected IDs: ' . implode(', ', $selectedIds));
    error_log('CHECKOUT - Items before filter: ' . $itemsCountBeforeFilter);
    
    if (!empty($selectedIds)) {
        $filteredGroupedItems = [];
        $itemsAfterFilter = 0;
        
        foreach ($groupedItems as $sid => $sg) {
            $filteredItems = array_values(array_filter($sg['items'], function($it) use ($selectedIds) {
                return in_array((int)$it['product_id'], $selectedIds, true);
            }));
            
            if (!empty($filteredItems)) {
                $filteredGroupedItems[$sid] = [
                    'seller_id' => $sg['seller_id'],
                    'seller_display_name' => $sg['seller_display_name'],
                    'items' => $filteredItems,
                    'subtotal' => 0,
                    'item_count' => 0
                ];
                
                foreach ($filteredItems as $it) {
                    $filteredGroupedItems[$sid]['subtotal'] += $it['price'] * $it['quantity'];
                    $filteredGroupedItems[$sid]['item_count'] += $it['quantity'];
                }
                
                $itemsAfterFilter += count($filteredItems);
            }
        }
        
        error_log('CHECKOUT - Items after filter: ' . $itemsAfterFilter);
        
        // CRITICAL: Only apply filter if it didn't remove everything
        // If filter removed all items but we have items in backup, ignore the filter
        if ($itemsAfterFilter > 0) {
            $groupedItems = $filteredGroupedItems;
            error_log('CHECKOUT - Filter applied: ' . count($groupedItems) . ' seller groups');
        } else {
            error_log('CHECKOUT - WARNING: Filter removed ALL items! Ignoring filter and keeping original items.');
            // Keep original groupedItems (don't apply filter)
        }
    }
}

// Recompute grand total from current groupedItems
$grandTotal = 0;
foreach ($groupedItems as $sg) {
    $grandTotal += $sg['subtotal'];
}

error_log('CHECKOUT - Final groupedItems count: ' . count($groupedItems) . ', Final grandTotal: ' . $grandTotal);

// Handle form submission
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
    $shippingAddress = trim($_POST['shipping_address'] ?? '');
    $paymentMethod = $_POST['payment_method'] ?? '';
    $customerName = trim($_POST['customer_name'] ?? '');
    $customerEmail = trim($_POST['customer_email'] ?? '');
    $customerPhone = trim($_POST['customer_phone'] ?? '');
    
    if (empty($shippingAddress)) {
        $errors[] = 'Shipping address is required';
    }
    if (empty($paymentMethod)) {
        $errors[] = 'Please select a payment method';
    }
    
    if (empty($errors)) {
        if (method_exists($pdo, 'query')) {
            ensureAutoIncrementPrimary('payment_transactions');
            ensureAutoIncrementPrimary('orders');
        }
        
        // For PayMongo payments, only create payment transaction (NOT orders yet)
        // Orders will be created AFTER payment is confirmed
        $createOrdersImmediately = !in_array($paymentMethod, ['card', 'gcash', 'paymaya', 'grab_pay', 'billease']);
        
        $result = processMultiSellerCheckout($shippingAddress, $paymentMethod, $customerName, $customerEmail, $customerPhone, $isBuyNow, $createOrdersImmediately);
        
        if ($result['success']) {
            if ($isBuyNow) {
                unset($_SESSION['buy_now_active']);
                unset($_SESSION['buy_now_data']);
                unset($_SESSION['buy_now_item']);
                unset($_SESSION['is_buy_now_checkout']);
                unset($_SESSION['buy_now_product_id']);
            }
            
            if (in_array($paymentMethod, ['card', 'gcash', 'paymaya', 'grab_pay', 'billease'])) {
                error_log('CHECKOUT - Creating PayMongo checkout session for transaction: ' . $result['payment_transaction_id']);
                // Store cart items in session for order creation after payment success
                $_SESSION['pending_checkout_items'] = $groupedItems;
                $_SESSION['pending_checkout_grand_total'] = $grandTotal;
                $_SESSION['pending_checkout_transaction_id'] = $result['payment_transaction_id'];
                $_SESSION['pending_customer_name'] = $customerName;
                $_SESSION['pending_customer_email'] = $customerEmail;
                $_SESSION['pending_customer_phone'] = $customerPhone;
                $_SESSION['pending_shipping_address'] = $shippingAddress;
                
                $redirectUrl = createPayMongoCheckoutSession($result['payment_transaction_id'], $customerName, $customerEmail, $customerPhone, $shippingAddress, $groupedItems, $grandTotal, $paymentMethod);
                if ($redirectUrl) {
                    error_log('CHECKOUT - PayMongo redirect URL: ' . $redirectUrl);
                    
                    // Extract checkout_session_id from redirect URL and store it
                    // PayMongo redirect URL format: https://checkout.paymongo.com/cs_XXXXX...
                    if (preg_match('/\/cs_([a-zA-Z0-9]+)/', $redirectUrl, $matches)) {
                        $checkoutSessionId = $matches[1];
                        error_log('CHECKOUT - Extracted checkout_session_id: ' . $checkoutSessionId);
                        
                        // Store checkout_session_id in payment_transactions
                        try {
                            $stmt = $pdo->prepare("UPDATE payment_transactions SET paymongo_session_id = ? WHERE id = ?");
                            $stmt->execute([$checkoutSessionId, $result['payment_transaction_id']]);
                            error_log('CHECKOUT - Stored checkout_session_id in payment_transactions');
                        } catch (Exception $e) {
                            error_log('CHECKOUT - Error storing checkout_session_id: ' . $e->getMessage());
                        }
                    }
                    
                    header("Location: " . $redirectUrl);
                    exit();
                } else {
                    error_log('CHECKOUT - PayMongo checkout session creation failed, redirecting to payment page');
                    // Clear pending checkout session if PayMongo fails
                    unset($_SESSION['pending_checkout_items']);
                    unset($_SESSION['pending_checkout_grand_total']);
                    unset($_SESSION['pending_checkout_transaction_id']);
                    // Fallback: redirect to payment page if PayMongo fails
                    header("Location: multi-seller-payment.php?transaction_id=" . $result['payment_transaction_id']);
                    exit();
                }
            } else {
                // COD
                try {
                    $stmt = $pdo->prepare("UPDATE payment_transactions SET payment_status = 'completed' WHERE id = ?");
                    $stmt->execute([$result['payment_transaction_id']]);
                    $stmt = $pdo->prepare("UPDATE orders SET payment_status = 'completed' WHERE payment_transaction_id = ?");
                    $stmt->execute([$result['payment_transaction_id']]);
                    
                    if (function_exists('createOrderNotification')) {
                        $stmt = $pdo->prepare("SELECT id FROM orders WHERE payment_transaction_id = ?");
                        $stmt->execute([$result['payment_transaction_id']]);
                        $orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        
                        foreach ($orderIds as $orderId) {
                            $message = "Order #" . str_pad($orderId, 6, '0', STR_PAD_LEFT) . " has been placed successfully with COD.";
                            createOrderNotification($userId, $orderId, $message, 'order_placed');
                        }
                    }
                } catch (Exception $e) {
                    error_log('Error updating COD payment: ' . $e->getMessage());
                }
                
                header("Location: order-success.php?transaction_id=" . $result['payment_transaction_id']);
                exit();
            }
        } else {
            $errors[] = $result['message'];
        }
    }
}

// Final check before displaying
$debugTotalItems = 0;
foreach ($groupedItems as $sg) {
    $debugTotalItems += count($sg['items'] ?? []);
}

$debugInfo = [
    'user_id' => $userId,
    'isBuyNow' => $isBuyNow,
    'groupedItems_count' => count($groupedItems),
    'total_items_in_groups' => $debugTotalItems,
    'raw_cart_count' => $rawCount['count'] ?? 0,
    'grandTotal' => $grandTotal,
    'groupedItems_empty' => ($debugTotalItems === 0) ? 'YES' : 'NO'
];

// FINAL RESTORE CHECK
if (isset($groupedItemsBackup) && isset($itemsCountBeforeFilter)) {
    if ($debugTotalItems === 0 && $itemsCountBeforeFilter > 0) {
        error_log('CHECKOUT - FINAL RESTORE: Restoring backup');
        $groupedItems = $groupedItemsBackup;
        $debugTotalItems = $itemsCountBeforeFilter;
        $debugInfo['total_items_in_groups'] = $debugTotalItems;
        $debugInfo['groupedItems_empty'] = 'NO';
        
        $grandTotal = 0;
        foreach ($groupedItems as $sg) {
            $grandTotal += $sg['subtotal'];
        }
        $debugInfo['grandTotal'] = $grandTotal;
    }
}

// If truly empty, redirect
if (empty($groupedItems) && !$isBuyNow && ($rawCount['count'] ?? 0) == 0) {
    header("Location: ../cart.php?error=cart_empty");
    exit();
}

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

            <div class="order-items">
                <?php
                // Use raw cart count as final authority
                $isEmpty = ($debugInfo['raw_cart_count'] == 0 && $debugInfo['total_items_in_groups'] == 0);
                
                if ($isEmpty): ?>
                    <div class="empty-cart-message">
                        <p>Your cart is empty.</p>
                        <a href="../products.php" class="btn btn-primary">Continue Shopping</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($groupedItems as $sellerId => $sellerGroup): ?>
                        <div class="seller-group">
                            <div class="seller-header">
                                <h3><i class="fas fa-store"></i> Seller: <?php echo htmlspecialchars($sellerGroup['seller_display_name']); ?></h3>
                                <div class="seller-total">Subtotal: ₱<?php echo number_format($sellerGroup['subtotal'], 2); ?></div>
                            </div>

                            <?php foreach ($sellerGroup['items'] as $item): ?>
                                <div class="order-item">
                                    <img src="<?php echo htmlspecialchars('../' . $item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="item-image">
                                    <div class="item-details">
                                        <h4><?php echo htmlspecialchars($item['name']); ?></h4>
                                        <p>Price: ₱<?php echo number_format($item['price'], 2); ?></p>
                                        <p>Quantity: <?php echo $item['quantity']; ?></p>
                                    </div>
                                    <div class="item-total">₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="order-total">
                <strong>Grand Total: ₱<?php echo number_format($grandTotal, 2); ?></strong>
            </div>
        </div>

        <?php if (!$isEmpty): ?>
        <!-- Review Order Modal -->
        <div id="reviewOrderModal" class="review-modal" style="display: none;">
            <div class="review-modal-content">
                <div class="review-modal-header">
                    <h2 style="font-size: 20px; margin: 0; color: var(--primary-dark);">Review Your Order</h2>
                    <button type="button" class="review-modal-close" id="closeReviewModal">&times;</button>
                </div>
                <div class="review-modal-body">
                    <div class="review-order-summary">
                        <?php foreach ($groupedItems as $sellerId => $sellerGroup): ?>
                            <div class="review-seller-group">
                                <h3 style="font-size: 16px; color: var(--primary-dark); margin-bottom: 10px;">
                                    <i class="fas fa-store"></i> <?php echo htmlspecialchars($sellerGroup['seller_display_name']); ?>
                                </h3>
                                <?php foreach ($sellerGroup['items'] as $item): ?>
                                    <div class="review-item">
                                        <img src="../<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="review-item-image">
                                        <div class="review-item-info">
                                            <div class="review-item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                            <div class="review-item-details">Qty: <?php echo $item['quantity']; ?> × ₱<?php echo number_format($item['price'], 2); ?></div>
                                        </div>
                                        <div class="review-item-price">₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?></div>
                                    </div>
                                <?php endforeach; ?>
                                <div class="review-seller-subtotal">
                                    Subtotal: ₱<?php echo number_format($sellerGroup['subtotal'], 2); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="review-grand-total">
                            <strong style="font-size: 20px;">Grand Total: ₱<?php echo number_format($grandTotal, 2); ?></strong>
                        </div>
                    </div>
                    <div class="review-shipping-info">
                        <h4 style="font-size: 14px; color: var(--primary-dark); margin-bottom: 8px;">Shipping Address:</h4>
                        <p id="reviewShippingAddress" style="font-size: 13px; color: var(--text-light); margin: 0;"></p>
                    </div>
                    <div class="review-payment-info">
                        <h4 style="font-size: 14px; color: var(--primary-dark); margin-bottom: 8px;">Payment Method:</h4>
                        <p id="reviewPaymentMethod" style="font-size: 13px; color: var(--accent-yellow); font-weight: 600; margin: 0;"></p>
                    </div>
                </div>
                <div class="review-modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancelReviewBtn">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmCheckoutBtn">Proceed to Checkout</button>
                </div>
            </div>
        </div>

        <div class="checkout-form">
            <h2>Billing & Shipping Information</h2>

            <form method="POST" action="" id="ms-checkout-form">
                <div class="form-group">
                    <label for="customer_name">Full Name*</label>
                    <input type="text" id="customer_name" name="customer_name" value="<?php echo htmlspecialchars($_POST['customer_name'] ?? (trim(($userProfile['first_name'] ?? '') . ' ' . ($userProfile['last_name'] ?? '')))); ?>">
                </div>

                <div class="form-group">
                    <label for="customer_email">Email Address</label>
                    <input type="email" id="customer_email" name="customer_email" value="<?php echo htmlspecialchars($_POST['customer_email'] ?? ($userProfile['email'] ?? '')); ?>">
                </div>

                <div class="form-group">
                    <label for="customer_phone">Phone Number*</label>
                    <input type="tel" id="customer_phone" name="customer_phone" value="<?php echo htmlspecialchars($_POST['customer_phone'] ?? ($userProfile['phone'] ?? '')); ?>">
                </div>

                <div class="form-group">
                    <label for="shipping_address">Shipping Address *</label>
                    <textarea id="shipping_address" name="shipping_address" rows="3" required><?php echo htmlspecialchars($_POST['shipping_address'] ?? ($userProfile['address'] ?? '')); ?></textarea>
                </div>

                <div class="form-group payment-method-group">
                    <label for="payment_method">Payment Method *</label>
                    <select id="payment_method" name="payment_method" required>
                        <option value="">Please select a payment method</option>
                        <option value="card">Debit/Credit Card</option>
                        <option value="gcash">GCash</option>
                        <option value="paymaya">PayMaya</option>
                        <option value="grab_pay">GrabPay</option>
                        <option value="billease">Billease</option>
                        <option value="cod">Cash on Delivery (COD)</option>
                    </select>
                    <div id="payment-method-warning" class="payment-method-warning" style="display: none;">
                        <i class="fas fa-exclamation-triangle"></i> Please select a payment method before proceeding.
                    </div>
                </div>

                <div class="form-actions">
                    <a href="../cart.php" class="btn btn-secondary">Back to Cart</a>
                    <button type="button" id="reviewOrderBtn" class="btn btn-yellow"><i class="fas fa-eye"></i> Review Order</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
</main>

<?php require_once '../includes/footer.php'; ?>

<style>
:root {
    --primary-dark: #130325;
    --accent-yellow: #FFD736;
    --text-dark: #130325;
    --border-light: #e9ecef;
}

body { background: #ffffff !important; color: var(--text-dark); }
.checkout-container { max-width: 1200px; margin: 0 auto; padding: 10px 20px 20px 20px; }
h1 { color: var(--text-dark); text-align: center; margin: 20px 0; font-size: 1.6rem; border-bottom: 3px solid var(--primary-dark); padding-bottom: 10px; }

.alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
.alert-error { background-color: #ffebee; border: 1px solid #f44336; color: #d32f2f; }
.alert ul { margin: 0; padding-left: 20px; }

.checkout-content { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 20px; }
.order-summary, .checkout-form { background-color: #f0f0f0; border: 1px solid var(--border-light); padding: 25px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
.order-summary h2, .checkout-form h2 { color: var(--text-dark); border-bottom: 2px solid var(--primary-dark); padding-bottom: 10px; margin-bottom: 20px; font-weight: 600; font-size: 1.3rem; }

.seller-group { margin-bottom: 30px; border: 1px solid var(--border-light); border-radius: 12px; padding: 20px; background: #f0f0f0; }
.seller-header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 15px; margin-bottom: 15px; border-bottom: 2px solid var(--primary-dark); }
.seller-header h3 { color: var(--text-dark); margin: 0; font-size: 18px; display: flex; align-items: center; gap: 8px; font-weight: 600; }
.seller-total { color: var(--text-dark); font-weight: bold; font-size: 16px; }

.order-item { display: flex; align-items: center; padding: 15px 0; border-bottom: 1px solid var(--border-light); }
.order-item:last-child { border-bottom: none; }
.item-image { width: 60px; height: 60px; object-fit: cover; border-radius: 8px; margin-right: 15px; border: 2px solid var(--border-light); }
.item-details { flex-grow: 1; }
.item-details h4 { margin: 0 0 5px 0; font-size: 16px; color: var(--text-dark); font-weight: 600; }
.item-details p { margin: 2px 0; color: #6c757d; font-size: 14px; }
.item-total { font-weight: bold; font-size: 16px; color: var(--text-dark); }

.order-total { border-top: 2px solid var(--primary-dark); padding-top: 15px; font-size: 18px; text-align: right; color: var(--text-dark); }

.form-group { margin-bottom: 20px; }
.form-group label { color: var(--text-dark); font-weight: 600; margin-bottom: 5px; display: block; }
.form-group input, .form-group textarea, .form-group select { width: 100%; padding: 12px 16px; border: 1px solid var(--border-light); border-radius: 8px; font-size: 14px; background: #ffffff; color: var(--text-dark); }
.form-group input:focus, .form-group textarea:focus, .form-group select:focus { outline: none; border-color: var(--primary-dark); box-shadow: 0 0 0 2px rgba(19, 3, 37, 0.1); }

.payment-method-warning {
    margin-top: 8px;
    padding: 10px 12px;
    background-color: #fff3cd;
    border: 1px solid #ffc107;
    border-left: 4px solid #ff9800;
    border-radius: 4px;
    color: #856404;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.payment-method-warning i {
    color: #ff9800;
    font-size: 16px;
}

.payment-method-group select:invalid + .payment-method-warning {
    display: flex;
}

.form-actions { display: flex; justify-content: space-between; gap: 15px; margin-top: 30px; }
.btn { padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; text-decoration: none; text-align: center; transition: all 0.3s; font-weight: 600; }
.btn-secondary { background-color: #6c757d; color: white; }
.btn-secondary:hover { background-color: #5a6268; }
.btn-primary { background-color: var(--primary-dark); color: white; }
.btn-primary:hover { background-color: #0f0220; }
.btn-yellow { background-color: var(--accent-yellow); color: var(--text-dark); }
.btn-yellow:hover { background-color: #e6c230; }

.empty-cart-message { text-align: center; padding: 40px; }
.empty-cart-message p { font-size: 1.2rem; color: var(--text-dark); margin-bottom: 20px; }

/* Review Order Modal */
.review-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 10000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.review-modal-content {
    background: white;
    border-radius: 8px;
    width: 90%;
    max-width: 1000px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    display: flex;
    flex-direction: column;
}

.review-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    border-bottom: 2px solid var(--primary-dark);
    background: var(--primary-dark);
    color: white;
}

.review-modal-header h2 {
    color: white !important;
    font-size: 20px !important;
    margin: 0 !important;
}

.review-modal-close {
    background: none;
    border: none;
    font-size: 28px;
    color: white;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
}

.review-modal-close:hover {
    opacity: 0.7;
}

.review-modal-body {
    padding: 20px;
    flex: 1;
    overflow-y: auto;
}

.review-order-summary {
    margin-bottom: 20px;
}

.review-seller-group {
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid rgba(255, 215, 54, 0.3);
}

.review-seller-group:last-child {
    border-bottom: none;
}

.review-item {
    display: flex;
    align-items: center;
    padding: 8px 0;
    gap: 12px;
}

.review-item-image {
    width: 50px;
    height: 50px;
    object-fit: cover;
    border-radius: 4px;
}

.review-item-info {
    flex: 1;
}

.review-item-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--primary-dark);
    margin-bottom: 4px;
}

.review-item-details {
    font-size: 12px;
    color: var(--text-light);
}

.review-item-price {
    font-size: 14px;
    font-weight: 600;
    color: var(--accent-yellow);
}

.review-seller-subtotal {
    text-align: right;
    margin-top: 8px;
    font-size: 14px;
    font-weight: 600;
    color: var(--primary-dark);
}

.review-grand-total {
    text-align: right;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 2px solid var(--accent-yellow);
}

.review-shipping-info,
.review-payment-info {
    margin-top: 15px;
    padding: 12px;
    background: rgba(255, 215, 54, 0.1);
    border-radius: 6px;
}

.review-modal-footer {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    padding: 15px 20px;
    border-top: 1px solid var(--border-light);
    background: #f8f9fa;
}

@media (max-width: 768px) {
    .checkout-content { grid-template-columns: 1fr; }
    .form-actions { flex-direction: column; }
    
    .review-modal-content {
        width: 95%;
        max-height: 95vh;
    }
    
    .review-modal-header {
        padding: 12px 15px;
    }
    
    .review-modal-body {
        padding: 15px;
    }
    
    .review-modal-footer {
        flex-direction: column;
    }
    
    .review-modal-footer .btn {
        width: 100%;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const reviewBtn = document.getElementById('reviewOrderBtn');
    const checkoutForm = document.getElementById('ms-checkout-form');
    const paymentMethodSelect = document.getElementById('payment_method');
    const paymentMethodWarning = document.getElementById('payment-method-warning');
    
    // Show/hide warning based on payment method selection
    if (paymentMethodSelect && paymentMethodWarning) {
        // Check on page load
        if (!paymentMethodSelect.value) {
            paymentMethodWarning.style.display = 'flex';
        }
        
        // Update on change
        paymentMethodSelect.addEventListener('change', function() {
            if (this.value) {
                paymentMethodWarning.style.display = 'none';
            } else {
                paymentMethodWarning.style.display = 'flex';
            }
        });
        
        // Show warning when user tries to interact with form without selecting
        paymentMethodSelect.addEventListener('focus', function() {
            if (!this.value) {
                paymentMethodWarning.style.display = 'flex';
            }
        });
    }
    
    // Review Order Modal
    const reviewModal = document.getElementById('reviewOrderModal');
    const closeReviewModal = document.getElementById('closeReviewModal');
    const cancelReviewBtn = document.getElementById('cancelReviewBtn');
    const confirmCheckoutBtn = document.getElementById('confirmCheckoutBtn');
    const reviewShippingAddress = document.getElementById('reviewShippingAddress');
    const reviewPaymentMethod = document.getElementById('reviewPaymentMethod');
    const shippingAddressField = document.getElementById('shipping_address');
    
    // Payment method labels
    const paymentMethodLabels = {
        'card': 'Debit/Credit Card',
        'gcash': 'GCash',
        'paymaya': 'PayMaya',
        'grab_pay': 'GrabPay',
        'billease': 'Billease',
        'cod': 'Cash on Delivery (COD)'
    };
    
    // Open review modal
    function openReviewModal() {
        if (reviewModal && shippingAddressField && paymentMethodSelect) {
            reviewShippingAddress.textContent = shippingAddressField.value || 'Not provided';
            const selectedMethod = paymentMethodSelect.value;
            reviewPaymentMethod.textContent = paymentMethodLabels[selectedMethod] || selectedMethod || 'Not selected';
            reviewModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
    }
    
    // Close review modal
    function closeModal() {
        if (reviewModal) {
            reviewModal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }
    
    // Close modal handlers
    if (closeReviewModal) {
        closeReviewModal.addEventListener('click', closeModal);
    }
    if (cancelReviewBtn) {
        cancelReviewBtn.addEventListener('click', closeModal);
    }
    
    // Close modal when clicking outside
    if (reviewModal) {
        reviewModal.addEventListener('click', function(e) {
            if (e.target === reviewModal) {
                closeModal();
            }
        });
    }
    
    // Review Order Button - Show modal instead of submitting
    if (reviewBtn) {
        reviewBtn.addEventListener('click', function() {
            const paymentMethod = paymentMethodSelect ? paymentMethodSelect.value : '';
            if (!paymentMethod) {
                // Show warning
                if (paymentMethodWarning) {
                    paymentMethodWarning.style.display = 'flex';
                }
                // Focus on select
                if (paymentMethodSelect) {
                    paymentMethodSelect.focus();
                    paymentMethodSelect.style.borderColor = '#f44336';
                    setTimeout(function() {
                        paymentMethodSelect.style.borderColor = '';
                    }, 2000);
                }
                return;
            }
            
            // Hide warning if payment method is selected
            if (paymentMethodWarning) {
                paymentMethodWarning.style.display = 'none';
            }
            
            // Show review modal
            openReviewModal();
        });
    }
    
    // Confirm checkout from modal
    if (confirmCheckoutBtn) {
        confirmCheckoutBtn.addEventListener('click', function() {
            closeModal();
            // Submit form
            const checkoutInput = document.createElement('input');
            checkoutInput.type = 'hidden';
            checkoutInput.name = 'checkout';
            checkoutInput.value = '1';
            checkoutForm.appendChild(checkoutInput);
            checkoutForm.submit();
        });
    }
    
    // Also validate on form submit
    if (checkoutForm && paymentMethodSelect && paymentMethodWarning) {
        checkoutForm.addEventListener('submit', function(e) {
            if (!paymentMethodSelect.value) {
                e.preventDefault();
                paymentMethodWarning.style.display = 'flex';
                paymentMethodSelect.focus();
                paymentMethodSelect.style.borderColor = '#f44336';
                setTimeout(function() {
                    paymentMethodSelect.style.borderColor = '';
                }, 2000);
                return false;
            }
        });
    }
});
</script>

<?php
function createPayMongoCheckoutSession($transactionId, $customerName, $customerEmail, $customerPhone, $shippingAddress, $groupedItems, $grandTotal, $paymentMethod = 'card') {
    global $pdo;
    
    try {
        $lineItems = [];
        foreach ($groupedItems as $sellerGroup) {
            foreach ($sellerGroup['items'] as $item) {
                $lineItems[] = [
                    'currency' => 'PHP',
                    'amount' => (int)($item['price'] * 100),
                    'name' => $item['name'],
                    'quantity' => $item['quantity']
                ];
            }
        }
        
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
        
        $apiUrl = 'https://api.paymongo.com/v1/checkout_sessions';
        $apiKey = PAYMONGO_SECRET_KEY;
        
        $paymentMethodMap = [
            'card' => ['card'],
            'gcash' => ['gcash'],
            'paymaya' => ['paymaya'],
            'grab_pay' => ['grab_pay'],
            'billease' => ['billease']
        ];
        
        $paymentMethodTypes = $paymentMethodMap[$paymentMethod] ?? ['card'];
        
        $successUrl = getSuccessUrl($transactionId);
        $cancelUrl = getCancelUrl($transactionId);
        
        // Log URLs being sent to PayMongo
        error_log('PayMongo Checkout Session - Success URL: ' . $successUrl);
        error_log('PayMongo Checkout Session - Cancel URL: ' . $cancelUrl);
        error_log('PayMongo Checkout Session - Transaction ID: ' . $transactionId);
        
        $payload = [
            'data' => [
                'attributes' => [
                    'line_items' => $lineItems,
                    'payment_method_types' => $paymentMethodTypes,
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                    'billing' => $billing,
                    'reference_number' => 'P-C-' . $transactionId,
                    'statement_descriptor' => 'PEST-CTRL',
                    'send_email_receipt' => true,
                    'show_description' => false
                ]
            ]
        ];
        
        error_log('PayMongo Checkout Session - Full payload: ' . json_encode($payload));
        
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
        
        error_log('PayMongo Checkout Session - HTTP Code: ' . $httpCode);
        error_log('PayMongo Checkout Session - Response: ' . substr($response, 0, 1000));
        error_log('PayMongo Checkout Session - Success URL sent: ' . $payload['data']['attributes']['success_url']);
        error_log('PayMongo Checkout Session - Cancel URL sent: ' . $payload['data']['attributes']['cancel_url']);
        
        if ($curlError) {
            error_log('PayMongo Checkout Session - cURL Error: ' . $curlError);
            return false;
        }
        
        if ($httpCode === 200 || $httpCode === 201) {
            $data = json_decode($response, true);
            if (!$data) {
                error_log('PayMongo Checkout Session - Failed to decode JSON response');
                return false;
            }
            
            if (isset($data['data']['attributes']['checkout_url'])) {
                $checkoutUrl = $data['data']['attributes']['checkout_url'];
                error_log('PayMongo Checkout Session - Success! Checkout URL: ' . $checkoutUrl);
                
                // Also log the checkout session ID for reference
                if (isset($data['data']['id'])) {
                    $sessionId = $data['data']['id'];
                    error_log('PayMongo Checkout Session - Session ID: ' . $sessionId);
                    
                    // Store checkout_session_id in payment_transactions
                    try {
                        require_once '../config/database.php';
                        $stmt = $pdo->prepare("UPDATE payment_transactions SET paymongo_session_id = ? WHERE id = ?");
                        $stmt->execute([$sessionId, $transactionId]);
                        error_log('PayMongo Checkout Session - Stored session ID in payment_transactions');
                    } catch (Exception $e) {
                        error_log('PayMongo Checkout Session - Error storing session ID: ' . $e->getMessage());
                    }
                }
                
                return $checkoutUrl;
            } else {
                error_log('PayMongo Checkout Session - No checkout_url in response. Full response: ' . json_encode($data));
                if (isset($data['errors'])) {
                    error_log('PayMongo Checkout Session - API Errors: ' . json_encode($data['errors']));
                }
            }
        } else {
            error_log('PayMongo Checkout Session - HTTP Error ' . $httpCode . ': ' . substr($response, 0, 1000));
            $errorData = json_decode($response, true);
            if (isset($errorData['errors'])) {
                error_log('PayMongo Checkout Session - Errors: ' . json_encode($errorData['errors']));
            }
        }
        
        return false;
    } catch (Exception $e) {
        error_log('PayMongo error: ' . $e->getMessage());
        return false;
    }
}
?>