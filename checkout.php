<?php
// Move all processing logic to the top before any includes


require_once 'config/database.php';
require_once 'includes/functions.php';

// Handle item removal via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_item') {
    header('Content-Type: application/json');
    
    $productId = (int)($_POST['product_id'] ?? 0);
    $checkoutType = $_POST['checkout_type'] ?? 'cart';
    
    if ($checkoutType === 'buy_now') {
        // For buy now, we can't remove items - redirect to previous page
        echo json_encode([
            'success' => false, 
            'message' => 'Cannot remove items from Buy Now checkout',
            'redirect' => 'javascript:history.back()'
        ]);
        exit();
    }
    
    // Handle cart item removal
    try {
        if (isLoggedIn()) {
            // Remove from database cart
            $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
            $stmt->execute([$_SESSION['user_id'], $productId]);
            $affected = $stmt->rowCount();
        } else {
            // Remove from session cart
            $affected = 0;
            if (isset($_SESSION['cart'])) {
                foreach ($_SESSION['cart'] as $key => $item) {
                    if ($item['product_id'] == $productId) {
                        unset($_SESSION['cart'][$key]);
                        $_SESSION['cart'] = array_values($_SESSION['cart']); // Reindex array
                        $affected = 1;
                        break;
                    }
                }
            }
        }
        
        if ($affected > 0) {
            echo json_encode(['success' => true, 'message' => 'Item removed successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Item not found in cart']);
        }
    } catch (Exception $e) {
        error_log('Remove item error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error removing item']);
    }
    exit();
}

// Check if user is logged in (optional - comment out if guest checkout allowed)
/*
if (!isLoggedIn()) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit();
}
*/
// Group items by seller and get seller information

// Get user information if logged in
$userData = null;
if (isLoggedIn()) {
    $stmt = $pdo->prepare("SELECT first_name, last_name, email, phone, address FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
}

$checkoutItems = [];
$checkoutTotal = 0;
$checkoutType = 'cart'; // 'cart' or 'buy_now'

// Check if this is a "buy now" checkout
if (isset($_GET['buy_now']) && isset($_SESSION['buy_now_item'])) {
    $checkoutType = 'buy_now';
    $buyNowItem = $_SESSION['buy_now_item'];
    
    // ... your existing buy_now validation code ...
    
    $checkoutItems[] = [
        'product_id' => $buyNowItem['id'],
        'name' => $buyNowItem['name'],
        'price' => $buyNowItem['price'],
        'quantity' => $buyNowItem['quantity'],
        'image_url' => $buyNowItem['image_url'],
        'total' => $buyNowItem['total']
    ];
    
    $checkoutTotal = $buyNowItem['total'];
    
} else {
    // Regular cart checkout
    $checkoutType = 'cart';
    
    // Get cart items
    if (isLoggedIn()) {
        $checkoutItems = getCartItems(); // Database cart
    } else {
        $checkoutItems = getSessionCartForDisplay(); // Session cart
    }
    
    if (empty($checkoutItems)) {
        header("Location: cart.php?message=Your cart is empty");
        exit();
    }
    
    // Validate cart
    if (isLoggedIn()) {
        $validation = validateCartForCheckout();
    } else {
        $validation = validateSessionCart();
    }
    
    if (!$validation['success']) {
        $_SESSION['checkout_errors'] = $validation['errors'] ?? [$validation['message']];
        header("Location: cart.php");
        exit();
    }
    
    // Calculate total
    foreach ($checkoutItems as $item) {
        $checkoutTotal += isset($item['total']) ? $item['total'] : ($item['price'] * $item['quantity']);
    }
}

// NOW add the seller grouping code AFTER $checkoutItems is populated
// Group items by seller and get seller information
$itemsBySeller = [];
$sellerInfo = [];

foreach ($checkoutItems as $item) {
    // Get seller information for this product
    $stmt = $pdo->prepare("
        SELECT p.seller_id, u.first_name, u.last_name, u.email, u.phone 
        FROM products p 
        LEFT JOIN users u ON p.seller_id = u.id 
        WHERE p.id = ?
    ");
    $stmt->execute([$item['product_id']]);
    $seller = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $sellerId = $seller['seller_id'] ?? 0;
    $sellerName = trim(($seller['first_name'] ?? 'Unknown') . ' ' . ($seller['last_name'] ?? 'Seller'));
    
    // Group items by seller
    if (!isset($itemsBySeller[$sellerId])) {
        $itemsBySeller[$sellerId] = [];
        $sellerInfo[$sellerId] = [
            'name' => $sellerName,
            'email' => $seller['email'] ?? '',
            'phone' => $seller['phone'] ?? '',
            'total' => 0
        ];
    }
    
    $itemsBySeller[$sellerId][] = $item;
    $sellerInfo[$sellerId]['total'] += isset($item['total']) ? $item['total'] : ($item['price'] * $item['quantity']);
}

$hasMultipleSellers = count($itemsBySeller) > 1;
// Check if this is a "buy now" checkout
if (isset($_GET['buy_now']) && isset($_SESSION['buy_now_item'])) {
    $checkoutType = 'buy_now';
    $buyNowItem = $_SESSION['buy_now_item'];
    
    // Check if buy now item is still valid (not too old - 30 minutes)
    if ((time() - $buyNowItem['timestamp']) > 1800) {
        unset($_SESSION['buy_now_item']);
        header("Location: index.php?message=Session expired, please try again");
        exit();
    }
    
    // Validate product is still available and price hasn't changed significantly
    $stmt = $pdo->prepare("SELECT id, name, price, stock_quantity, status, image_url FROM products WHERE id = ?");
    $stmt->execute([$buyNowItem['id']]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product || $product['status'] !== 'active') {
        unset($_SESSION['buy_now_item']);
        header("Location: index.php?message=Product no longer available");
        exit();
    }
    
    if ($product['stock_quantity'] < $buyNowItem['quantity']) {
        unset($_SESSION['buy_now_item']);
        $stockMessage = $product['stock_quantity'] == 0 ? "Product is out of stock" : "Only {$product['stock_quantity']} items available";
        header("Location: index.php?message=" . urlencode($stockMessage));
        exit();
    }
    
    // Update price if it changed (and update total)
    if (abs($product['price'] - $buyNowItem['price']) > 0.01) {
        $_SESSION['buy_now_item']['price'] = (float)$product['price'];
        $_SESSION['buy_now_item']['total'] = (float)$product['price'] * $buyNowItem['quantity'];
        $buyNowItem = $_SESSION['buy_now_item']; // Update local copy
        
        // Optional: Show price change warning
        $_SESSION['price_change_warning'] = "Price has been updated from $" . number_format($buyNowItem['price'], 2) . " to $" . number_format($product['price'], 2);
    }
    
    // Update image URL if changed
    if ($product['image_url'] !== $buyNowItem['image_url']) {
        $_SESSION['buy_now_item']['image_url'] = $product['image_url'] ?? 'images/placeholder.jpg';
        $buyNowItem = $_SESSION['buy_now_item'];
    }
    
    $checkoutItems[] = [
        'product_id' => $buyNowItem['id'],
        'name' => $buyNowItem['name'],
        'price' => $buyNowItem['price'],
        'quantity' => $buyNowItem['quantity'],
        'image_url' => $buyNowItem['image_url'],
        'total' => $buyNowItem['total']
    ];
    
    $checkoutTotal = $buyNowItem['total'];
    
} else {
    // Regular cart checkout
    $checkoutType = 'cart';
    
    // Get cart items (use session or database based on your implementation)
    if (isLoggedIn()) {
        $checkoutItems = getCartItems(); // Database cart
    } else {
        $checkoutItems = getSessionCartForDisplay(); // Session cart
    }
    
    if (empty($checkoutItems)) {
        header("Location: cart.php?message=Your cart is empty");
        exit();
    }
    
    // Validate cart
    if (isLoggedIn()) {
        $validation = validateCartForCheckout();
    } else {
        $validation = validateSessionCart();
    }
    
    if (!$validation['success']) {
        $_SESSION['checkout_errors'] = $validation['errors'] ?? [$validation['message']];
        header("Location: cart.php");
        exit();
    }
    
    // Calculate total
    foreach ($checkoutItems as $item) {
        $checkoutTotal += isset($item['total']) ? $item['total'] : ($item['price'] * $item['quantity']);
    }
}

// Pre-fill form data with user information or POST data
$formData = [
    'customer_name' => '',
    'customer_email' => '',
    'customer_phone' => '',
    'shipping_address' => '',
    'payment_method' => ''
];

// Handle form submission - BEFORE any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $errors = [];
    
    // Validate form data
    $customerName = trim($_POST['customer_name'] ?? '');
    $customerEmail = trim($_POST['customer_email'] ?? '');
    $customerPhone = trim($_POST['customer_phone'] ?? '');
    $shippingAddress = trim($_POST['shipping_address'] ?? '');
    $paymentMethod = $_POST['payment_method'] ?? '';
    
    if (empty($customerName)) $errors[] = 'Name is required';
    if (empty($customerEmail) || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email is required';
    }
    if (empty($customerPhone)) $errors[] = 'Phone number is required';
    if (empty($shippingAddress)) $errors[] = 'Shipping address is required';
    if (empty($paymentMethod)) $errors[] = 'Payment method is required';
    
    // Re-validate items before placing order
    if ($checkoutType === 'buy_now') {
        // Validate buy now item one more time
        if (!isset($_SESSION['buy_now_item'])) {
            $errors[] = 'Buy now session expired. Please try again.';
        } else {
            $stmt = $pdo->prepare("SELECT id, price, stock_quantity, status FROM products WHERE id = ?");
            $stmt->execute([$_SESSION['buy_now_item']['id']]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product || $product['status'] !== 'active') {
                $errors[] = 'Product is no longer available';
            } elseif ($product['stock_quantity'] < $_SESSION['buy_now_item']['quantity']) {
                $errors[] = 'Insufficient stock available';
            }
        }
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Ensure PK is auto-increment to avoid duplicate '0' errors
            if (method_exists($pdo, 'query')) { ensureAutoIncrementPrimary('orders'); }

            // Create order
            $stmt = $pdo->prepare("INSERT INTO orders (user_id, shipping_address, payment_method, total_amount, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
            $userId = isLoggedIn() ? $_SESSION['user_id'] : null;
            $stmt->execute([$userId, $shippingAddress, $paymentMethod, $checkoutTotal]);
            $orderId = $pdo->lastInsertId();
            
            // Store customer information for guest orders
            if (!isLoggedIn()) {
                $_SESSION['guest_order_' . $orderId] = [
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'customer_phone' => $customerPhone,
                    'order_id' => $orderId,
                    'order_type' => $checkoutType
                ];
            }
            
            // Add order items and update stock
            foreach ($checkoutItems as $item) {
                $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
                $stmt->execute([$orderId, $item['product_id'], $item['quantity'], $item['price']]);
                
                // Update product stock
                $stmt = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?");
                $stmt->execute([$item['quantity'], $item['product_id']]);
            }
            
            $pdo->commit();
            
            // Clear appropriate session data
            if ($checkoutType === 'buy_now') {
                // Only clear buy now session
                unset($_SESSION['buy_now_item']);
                unset($_SESSION['price_change_warning']);
            } else {
                // Clear cart session/database
                if (isLoggedIn()) {
                    // Clear database cart
                    $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                } else {
                    // Clear session cart
                    $_SESSION['cart'] = [];
                }
            }
            
            // Redirect to PayMongo success page (even for COD)
            header("Location: paymongo/payment-success.php?order_id=" . $orderId . "&type=" . $checkoutType . "&payment_method=cash_on_delivery");
            exit();
            
        } catch (Exception $e) {
            $pdo->rollback();
            $errors[] = 'Error processing order. Please try again.';
            error_log('Checkout error: ' . $e->getMessage());
            if ($e instanceof PDOException) {
                echo "<pre>SQLSTATE: " . htmlspecialchars($e->getCode()) . "\n" . htmlspecialchars($e->getMessage()) . "</pre>";
            }
        }
    }
    
    // Store form data for re-display if there were errors
    $formData = [
        'customer_name' => $customerName,
        'customer_email' => $customerEmail,
        'customer_phone' => $customerPhone,
        'shipping_address' => $shippingAddress,
        'payment_method' => $paymentMethod
    ];
} elseif ($userData) {
    // Auto-fill with user's profile data only if not a POST request
    $formData = [
        'customer_name' => trim(($userData['first_name'] ?? '') . ' ' . ($userData['last_name'] ?? '')),
        'customer_email' => $userData['email'] ?? '',
        'customer_phone' => $userData['phone'] ?? '',
        'shipping_address' => $userData['address'] ?? '',
        'payment_method' => ''
    ];
}

// NOW include header after all processing is done
require_once 'includes/header.php';
?>

<main style="background: #130325; min-height: 100vh; padding: 20px;">
<div class="checkout-container">
    <h1>Checkout</h1>
    
    <?php if ($checkoutType === 'buy_now'): ?>
        <div class="checkout-type-info">
            <span class="badge badge-primary">Buy Now Checkout</span>
            <p class="checkout-note">You are purchasing this item directly without adding it to your cart.</p>
        </div>
    <?php endif; ?>
    
    <?php if (isLoggedIn() && $userData): ?>
        <div class="auto-fill-info">
            <div class="alert alert-info">
                <strong>Information Auto-filled:</strong> Your profile information has been automatically filled in the form below. 
                You can modify any field if needed.
                <a href="profile.php" class="update-profile-link">Update Profile</a>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['price_change_warning'])): ?>
        <div class="alert alert-warning">
            <strong>Price Update:</strong> <?php echo htmlspecialchars($_SESSION['price_change_warning']); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($errors) && !empty($errors)): ?>
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
    
    <?php if ($hasMultipleSellers): ?>
        <div class="alert alert-info">
            <strong>Multiple Sellers:</strong> Your order contains items from <?php echo count($itemsBySeller); ?> different sellers. 
            You'll need to complete separate payments for each seller.
        </div>
    <?php endif; ?>
    
    <div class="order-items" id="order-items-container">
        <?php foreach ($itemsBySeller as $sellerId => $sellerItems): ?>
            <div class="seller-group" data-seller-id="<?php echo $sellerId; ?>">
                <div class="seller-header">
                    <h3>
                        <i class="fas fa-store"></i> 
                        Seller: <?php echo htmlspecialchars($sellerInfo[$sellerId]['name']); ?>
                    </h3>
                    <div class="seller-total">
                        Subtotal: $<?php echo number_format($sellerInfo[$sellerId]['total'], 2); ?>
                    </div>
                </div>
                
                <?php foreach ($sellerItems as $item): ?>
                    <div class="order-item" data-product-id="<?php echo $item['product_id']; ?>">
                        <img src="<?php echo htmlspecialchars($item['image_url'] ?? 'images/placeholder.jpg'); ?>" 
                             alt="<?php echo htmlspecialchars($item['name']); ?>" 
                             class="item-image">
                        <div class="item-details">
                            <h4><?php echo htmlspecialchars($item['name']); ?></h4>
                            <p>Price: $<?php echo number_format($item['price'], 2); ?></p>
                            <p>Quantity: <?php echo $item['quantity']; ?></p>
                        </div>
                        <div class="item-total">
                            $<?php echo number_format(isset($item['total']) ? $item['total'] : ($item['price'] * $item['quantity']), 2); ?>
                        </div>
                        <?php if ($checkoutType === 'cart'): ?>
                            <button class="remove-item-btn" 
                                    data-product-id="<?php echo $item['product_id']; ?>"
                                    title="Remove item">
                                ×
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
    
    <div class="order-total" id="order-total">
        <strong>Grand Total: $<?php echo number_format($checkoutTotal, 2); ?></strong>
    </div>
    
    <?php if ($checkoutType === 'buy_now'): ?>
        <div class="buy-now-info">
            <small class="text-muted">
                <i>Note: This purchase will not affect your shopping cart.</i>
            </small>
        </div>
    <?php endif; ?>
    
    <div id="empty-cart-message" style="display: none;" class="empty-cart-notice">
        <p>Your cart is empty. <a href="index.php">Continue shopping</a></p>
    </div>
</div>
        
        <div class="checkout-form" id="checkout-form">
            <h2>Billing & Shipping Information</h2>
            <?php if (!isLoggedIn()): ?>
                <div class="guest-checkout-note">
                    <p><strong>Guest Checkout:</strong> Create an account to save your information for faster checkout next time.</p>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="customer_name">Full Name *</label>
                    <input type="text" id="customer_name" name="customer_name" 
                           value="<?php echo htmlspecialchars($formData['customer_name']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="customer_email">Email Address *</label>
                    <input type="email" id="customer_email" name="customer_email" 
                           value="<?php echo htmlspecialchars($formData['customer_email']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="customer_phone">Phone Number *</label>
                    <input type="tel" id="customer_phone" name="customer_phone" 
                           value="<?php echo htmlspecialchars($formData['customer_phone']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="shipping_address">Shipping Address *</label>
                    <textarea id="shipping_address" name="shipping_address" rows="3" required><?php echo htmlspecialchars($formData['shipping_address']); ?></textarea>
                </div>
                
                <div class="form-group payment-method-group">
                    <label for="payment_method" class="payment-method-label">💳 Payment Method *</label>
                    <select id="payment_method" name="payment_method" required>
                        <option value="">⚠️ Please select a payment method</option>
                        <option value="card" <?php echo ($formData['payment_method'] === 'card' || $formData['payment_method'] === 'credit_card' || $formData['payment_method'] === 'debit_card') ? 'selected' : ''; ?>>💳 Credit/Debit Card</option>
                        <option value="gcash" <?php echo ($formData['payment_method'] === 'gcash') ? 'selected' : ''; ?>>📱 GCash</option>
                        <option value="grab_pay" <?php echo ($formData['payment_method'] === 'grab_pay') ? 'selected' : ''; ?>>🚗 GrabPay</option>
                        <option value="paymaya" <?php echo ($formData['payment_method'] === 'paymaya') ? 'selected' : ''; ?>>💳 PayMaya</option>
                        <option value="billease" <?php echo ($formData['payment_method'] === 'billease') ? 'selected' : ''; ?>>🏦 Billease</option>
                        <option value="cash_on_delivery" <?php echo ($formData['payment_method'] === 'cash_on_delivery') ? 'selected' : ''; ?>>💰 Cash on Delivery</option>
                    </select>
                    <small class="payment-method-note">Choose your preferred payment method to continue</small>
                </div>
                
                    <div class="form-actions">
                        <?php if ($checkoutType === 'buy_now'): ?>
                            <a href="javascript:history.back()" class="btn btn-secondary">Go Back</a>
                        <?php else: ?>
                            <a href="cart.php" class="btn btn-secondary">Back to Cart</a>
                        <?php endif; ?>
                        <button type="button" id="reviewOrderBtn" class="btn btn-primary">
                            <i class="fas fa-eye"></i> Review Order
                        </button>
                    </div>
            </form>
        </div>
    </div>
</div>
</main>

<script>
// Remove item functionality
document.addEventListener('DOMContentLoaded', function() {
    const removeButtons = document.querySelectorAll('.remove-item-btn');
    
    removeButtons.forEach(button => {
        button.addEventListener('click', function() {
            const productId = this.dataset.productId;
            const orderItem = this.closest('.order-item');
            
            openConfirm('Are you sure you want to remove this item?', ()=>{
                // Show loading state
                this.disabled = true;
                this.textContent = '...';
                
                // Send AJAX request
                const formData = new FormData();
                formData.append('action', 'remove_item');
                formData.append('product_id', productId);
                formData.append('checkout_type', '<?php echo $checkoutType; ?>');
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Remove the item from DOM
                        orderItem.style.transition = 'opacity 0.3s ease';
                        orderItem.style.opacity = '0';
                        
                        setTimeout(() => {
                            orderItem.remove();
                            
                            // Check if any items left
                            const remainingItems = document.querySelectorAll('.order-item');
                            if (remainingItems.length === 0) {
                                // Show empty cart message and hide checkout form
                                document.getElementById('empty-cart-message').style.display = 'block';
                                document.getElementById('checkout-form').style.display = 'none';
                                document.getElementById('order-total').textContent = 'Total: ₱0.00';
                            } else {
                                // Recalculate total
                                updateOrderTotal();
                            }
                        }, 300);
                    } else {
                        alert(data.message || 'Error removing item');
                        // Reset button state
                        this.disabled = false;
                        this.textContent = '×';
                        
                        if (data.redirect) {
                            eval(data.redirect);
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error removing item');
                    // Reset button state
                    this.disabled = false;
                    this.textContent = '×';
                });
            });
        });
    });
    
    function updateOrderTotal() {
        let total = 0;
        const itemTotals = document.querySelectorAll('.item-total');
        
        itemTotals.forEach(itemTotal => {
            const priceText = itemTotal.textContent.replace('₱', '').replace(',', '');
            total += parseFloat(priceText) || 0;
        });
        
        document.getElementById('order-total').innerHTML = '<strong>Total: $' + total.toFixed(2) + '</strong>';
    }
});
</script>

<style>
body {
    background: #130325 !important;
    min-height: 100vh;
}

.checkout-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

h1 {
    color: var(--primary-light);
    text-align: center;
    margin: 20px 0;
    font-size: 2rem;
    border-bottom: 3px solid var(--accent-yellow);
    padding-bottom: 10px;
}

.checkout-type-info {
    background-color: var(--primary-dark);
    border: 1px solid var(--accent-yellow);
    border-radius: 4px; 
    padding: 15px;
    margin-bottom: 20px;
}

.badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
}

.badge-primary {
    background-color: var(--accent-yellow);
    color: var(--primary-dark);
}

.checkout-note {
    margin: 10px 0 0 0;
    color: var(--primary-light);
    font-size: 14px;
}

.alert {
    padding: 12px 16px;
    border-radius: 4px;
    margin-bottom: 20px;
}

.alert-info {
    background-color: var(--primary-dark);
    border: 1px solid var(--accent-yellow);
    color: var(--accent-yellow);
}

.alert-warning {
    background-color: var(--primary-dark);
    border: 1px solid #ffc107;
    color: #ffc107;
}

.alert-error {
    background-color: var(--primary-dark);
    border: 1px solid #dc3545;
    color: #dc3545;
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
    background-color: var(--primary-dark);
    border: 1px solid var(--accent-yellow);
    padding: 20px;
    border-radius: 8px;
}

.order-items {
    margin: 20px 0;
}

.order-item {
    display: flex;
    align-items: center;
    padding: 15px 0;
    border-bottom: 1px solid rgba(255, 215, 54, 0.3);
    position: relative;
}

.order-item:last-child {
    border-bottom: none;
}

.item-image {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border-radius: 4px;
    margin-right: 15px;
}

.item-details {
    flex-grow: 1;
}

.item-details h4 {
    margin: 0 0 5px 0;
    font-size: 16px;
}

.item-details p {
    margin: 2px 0;
    color: #666;
    font-size: 14px;
}

.item-total {
    font-weight: bold;
    font-size: 16px;
    margin-right: 10px;
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
    border-top: 2px solid var(--accent-yellow);
    padding-top: 15px;
    font-size: 18px;
    text-align: right;
    color: var(--primary-light);
}

.buy-now-info {
    margin-top: 15px;
    text-align: center;
}

.empty-cart-notice {
    text-align: center;
    padding: 40px 20px;
    color: var(--primary-light);
    font-style: italic;
}

.empty-cart-notice a {
    color: var(--accent-yellow);
    text-decoration: none;
}

.empty-cart-notice a:hover {
    text-decoration: underline;
}

.text-muted {
    color: var(--primary-light);
}

.checkout-form {
    background-color: var(--primary-dark);
    border: 1px solid var(--accent-yellow);
    padding: 20px;
    border-radius: 8px;
}

.checkout-form h2 {
    color: var(--primary-light);
    border-bottom: 2px solid var(--accent-yellow);
    padding-bottom: 10px;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    color: var(--primary-light);
    font-weight: 500;
    margin-bottom: 5px;
    display: block;
}

.form-group input,
.form-group textarea,
.form-group select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 14px;
    transition: border-color 0.3s;
}

.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {
    outline: none;
    border-color: var(--accent-yellow);
    box-shadow: 0 0 0 2px rgba(255, 215, 54, 0.25);
}

/* Payment method specific styling */
#payment_method {
    background: var(--primary-dark);
    border: 2px solid var(--accent-yellow);
    color: var(--primary-light);
    font-weight: 500;
    font-size: 16px;
    padding: 12px 15px;
}

#payment_method:focus {
    border-color: var(--accent-yellow);
    box-shadow: 0 0 0 3px rgba(255, 215, 54, 0.3);
}

#payment_method option {
    background: var(--primary-dark);
    color: var(--primary-light);
    padding: 10px;
}

/* Error styling for payment method */
#payment_method.error {
    border-color: #dc3545;
    box-shadow: 0 0 0 2px rgba(220, 53, 69, 0.25);
}

#payment_method.error:focus {
    border-color: #dc3545;
    box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.3);
}

/* Payment method group styling */
.payment-method-group {
    background: rgba(255, 215, 54, 0.1);
    border: 2px solid var(--accent-yellow);
    border-radius: 8px;
    padding: 20px;
    margin: 20px 0;
}

.payment-method-label {
    font-size: 18px;
    font-weight: 600;
    color: var(--accent-yellow);
    margin-bottom: 10px;
    display: block;
}

.payment-method-note {
    color: var(--primary-light);
    font-size: 14px;
    margin-top: 8px;
    display: block;
    opacity: 0.8;
}

.form-actions {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    margin-top: 30px;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    text-decoration: none;
    text-align: center;
    transition: background-color 0.3s;
}

.btn-secondary {
    background-color: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background-color: #5a6268;
}

.btn-primary {
    background-color: #007bff;
    color: white;
    font-weight: 500;
}

.btn-primary:hover {
    background-color: #0056b3;
}

/* Responsive design */
@media (max-width: 768px) {
    .checkout-content {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .order-item {
        flex-direction: column;
        text-align: center;
        padding: 20px 15px;
    }
    
    .item-image {
        margin: 0 0 10px 0;
    }
    
    .item-total {
        margin-right: 0;
        margin-top: 10px;
    }
    
    .remove-item-btn {
        position: absolute;
        top: 10px;
        right: 10px;
    }
}
/* Seller grouping styles */
.seller-group {
    margin-bottom: 30px;
    border: 2px solid rgba(255, 215, 54, 0.3);
    border-radius: 8px;
    padding: 15px;
    background: rgba(255, 215, 54, 0.05);
}

.seller-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 15px;
    margin-bottom: 15px;
    border-bottom: 2px solid var(--accent-yellow);
}

.seller-header h3 {
    color: var(--accent-yellow);
    margin: 0;
    font-size: 18px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.seller-total {
    color: var(--primary-light);
    font-weight: bold;
    font-size: 16px;
}

.seller-group .order-item {
    border-bottom: 1px solid rgba(255, 215, 54, 0.2);
}

.seller-group .order-item:last-child {
    border-bottom: none;
}

/* Review Modal */
.review-modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.8);
    overflow-y: auto;
}

.review-modal-content {
    background-color: var(--primary-dark);
    margin: 2% auto;
    padding: 30px;
    border: 2px solid var(--accent-yellow);
    border-radius: 12px;
    width: 90%;
    max-width: 900px;
    max-height: 90vh;
    overflow-y: auto;
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from {
        transform: translateY(-50px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.review-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    padding-bottom: 15px;
    border-bottom: 2px solid var(--accent-yellow);
}

.review-modal-header h2 {
    color: var(--accent-yellow);
    margin: 0;
}

.close-modal {
    color: var(--primary-light);
    font-size: 32px;
    font-weight: bold;
    cursor: pointer;
    transition: color 0.3s;
    line-height: 1;
}

.close-modal:hover {
    color: var(--accent-yellow);
}

.review-section {
    margin-bottom: 30px;
}

.review-section h3 {
    color: var(--accent-yellow);
    margin-bottom: 15px;
    font-size: 20px;
}

.review-info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
    background: rgba(255, 215, 54, 0.05);
    padding: 20px;
    border-radius: 8px;
    border: 1px solid rgba(255, 215, 54, 0.3);
}

.review-info-item {
    color: var(--primary-light);
}

.review-info-item strong {
    color: var(--accent-yellow);
    display: block;
    margin-bottom: 5px;
}

.review-seller-group {
    background: rgba(255, 215, 54, 0.05);
    border: 1px solid rgba(255, 215, 54, 0.3);
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.review-seller-header {
    color: var(--accent-yellow);
    font-size: 18px;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid rgba(255, 215, 54, 0.3);
}

.review-item {
    display: flex;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid rgba(255, 215, 54, 0.2);
    color: var(--primary-light);
}

.review-item:last-child {
    border-bottom: none;
}

.review-item img {
    width: 50px;
    height: 50px;
    object-fit: cover;
    border-radius: 4px;
    margin-right: 15px;
}

.review-item-details {
    flex-grow: 1;
}

.review-item-name {
    font-weight: 500;
    margin-bottom: 5px;
}

.review-item-info {
    font-size: 14px;
    opacity: 0.8;
}

.review-subtotal {
    text-align: right;
    padding-top: 10px;
    margin-top: 10px;
    border-top: 1px solid rgba(255, 215, 54, 0.3);
    color: var(--primary-light);
    font-weight: bold;
}

.review-grand-total {
    text-align: right;
    font-size: 24px;
    color: var(--accent-yellow);
    margin-top: 20px;
    padding-top: 20px;
    border-top: 2px solid var(--accent-yellow);
}

.review-actions {
    display: flex;
    gap: 15px;
    justify-content: flex-end;
    margin-top: 30px;
}

.review-actions .btn {
    min-width: 150px;
}

@media (max-width: 768px) {
    .review-modal-content {
        width: 95%;
        padding: 20px;
        margin: 5% auto;
    }
    
    .review-info-grid {
        grid-template-columns: 1fr;
    }
    
    .review-actions {
        flex-direction: column;
    }
    
    .review-actions .btn {
        width: 100%;
    }
}
</style>

<script>
// PayMongo Payment Processing
async function processPayment() {
    const form = document.querySelector('form');
    const formData = new FormData(form);
    
    // Validate form
    if (!validateCheckoutForm()) {
        return;
    }
    
    const paymentMethod = document.getElementById('payment_method').value;
    
    // Handle Cash on Delivery
    if (paymentMethod === 'cash_on_delivery') {
        // Submit form normally for COD
        form.submit();
        return;
    }
    
    // Handle Online Payment with PayMongo
    showLoading(true);
    
    try {
        // Debug: Log form data
        console.log('Form data:', {
            customer_name: formData.get('customer_name'),
            customer_email: formData.get('customer_email'),
            customer_phone: formData.get('customer_phone'),
            shipping_address: formData.get('shipping_address'),
            payment_method: formData.get('payment_method')
        });
        
        // Determine payment method types based on selection
        let paymentMethodTypes = [];
        if (paymentMethod === 'card') {
            paymentMethodTypes = ['card'];
        } else if (paymentMethod === 'gcash') {
            paymentMethodTypes = ['gcash'];
        } else if (paymentMethod === 'grab_pay') {
            paymentMethodTypes = ['grab_pay'];
        } else if (paymentMethod === 'paymaya') {
            paymentMethodTypes = ['paymaya'];
        } else if (paymentMethod === 'billease') {
            paymentMethodTypes = ['billease'];
        } else {
            // Fallback - should not happen due to validation
            paymentMethodTypes = ['card'];
        }
        
        console.log('Selected payment method:', paymentMethod);
        console.log('PayMongo payment method types:', paymentMethodTypes);
        
        // Prepare checkout data
        const itemsFromServer = <?php echo json_encode(array_map(function($it){
            return [
                'name' => (string)$it['name'],
                'quantity' => (int)$it['quantity'],
                'price' => (float)$it['price'],
                'description' => (string)$it['name']
            ];
        }, $checkoutItems)); ?>;
        const checkoutData = {
            amount: <?php echo $checkoutTotal; ?>,
            currency: 'PHP',
            payment_method_types: paymentMethodTypes,
            send_email_receipt: true,
            show_description: false,
            show_line_items: true,
            receipt_email: formData.get('customer_email'),
            success_url: 'http://localhost/PEST-CTRL_VER.1.3/paymongo/payment-success.php',
            cancel_url: 'http://localhost/PEST-CTRL_VER.1.3/paymongo/payment-cancel.php',
            order_id: 'order_' + Date.now(),
            customer_email: formData.get('customer_email'),
            customer: {
                first_name: (formData.get('customer_name') || '').split(' ')[0] || '',
                last_name: (formData.get('customer_name') || '').split(' ').slice(1).join(' ') || '',
                email: formData.get('customer_email') || '',
                phone: formData.get('customer_phone') || null
            },
            items: itemsFromServer
        };
        
        // Debug: Log checkout data
        console.log('Checkout data being sent:', checkoutData);
        
        // Store payment data in session storage for success page
        const paymentData = {
            checkout_session_id: null, // Will be updated after successful creation
            amount: checkoutData.amount,
            payment_method: paymentMethod,
            customer_email: checkoutData.customer_email,
            customer_name: checkoutData.customer.first_name + ' ' + checkoutData.customer.last_name,
            order_id: checkoutData.order_id,
            items: checkoutData.items
        };
        console.log('Stored payment data:', paymentData);
        
        // Create checkout session
        const result = await createCheckoutSession(checkoutData);
        
        if (result.success) {
            // Update payment data with actual checkout session ID
            paymentData.checkout_session_id = result.checkout_session_id;
            
            // Store in session storage
            sessionStorage.setItem('paymentData', JSON.stringify(paymentData));
            
            // Redirect to PayMongo checkout page
            window.location.href = result.checkout_url;
        } else {
            showMessage(result.error || 'Failed to create checkout session. Please try again.', 'error');
        }
        
    } catch (error) {
        console.error('Payment error:', error);
        console.error('Error details:', error.message);
        showMessage('Payment error: ' + error.message, 'error');
    } finally {
        showLoading(false);
    }
}

// Create PayMongo checkout session
async function createCheckoutSession(checkoutData) {
    try {
        const response = await fetch('paymongo/create-checkout-session.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(checkoutData)
        });
        
        if (!response.ok) {
            const errorText = await response.text();
            throw new Error(`HTTP ${response.status}: ${errorText}`);
        }
        
        const result = await response.json();
        console.log('PayMongo API response:', result);
        return result;
        
    } catch (error) {
        console.error('Checkout session creation error:', error);
        return {
            success: false,
            error: error.message
        };
    }
}

// Form validation
function validateCheckoutForm() {
    const requiredFields = [
        { id: 'customer_name', name: 'Customer Name' },
        { id: 'customer_email', name: 'Email' },
        { id: 'shipping_address', name: 'Shipping Address' },
        { id: 'payment_method', name: 'Payment Method' }
    ];
    
    for (let field of requiredFields) {
        const input = document.getElementById(field.id);
        if (!input.value.trim()) {
            showMessage(`${field.name} is required!`, 'error');
            input.focus();
            return false;
        }
    }
    
    // Validate email format
    const email = document.getElementById('customer_email').value;
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        showMessage('Please enter a valid email address!', 'error');
        document.getElementById('customer_email').focus();
        return false;
    }
    
    // Validate payment method selection
    const paymentMethod = document.getElementById('payment_method').value;
    const paymentMethodField = document.getElementById('payment_method');
    if (!paymentMethod || paymentMethod === '') {
        showMessage('Please select a payment method!', 'error');
        paymentMethodField.classList.add('error');
        paymentMethodField.focus();
        return false;
    } else {
        paymentMethodField.classList.remove('error');
    }
    
    return true;
}

// Add event listener to remove error styling when payment method is selected
document.addEventListener('DOMContentLoaded', function() {
    const paymentMethodField = document.getElementById('payment_method');
    if (paymentMethodField) {
        paymentMethodField.addEventListener('change', function() {
            if (this.value && this.value !== '') {
                this.classList.remove('error');
            }
        });
    }
});

// Show loading state
function showLoading(show) {
    const btn = document.getElementById('processPaymentBtn');
    if (show) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    } else {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-credit-card"></i> Continue to Payment';
    }
}

// Show message
function showMessage(message, type) {
    // Create message element if it doesn't exist
    let messageEl = document.getElementById('messageBox');
    if (!messageEl) {
        messageEl = document.createElement('div');
        messageEl.id = 'messageBox';
        messageEl.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 5px;
            color: white;
            font-weight: 500;
            z-index: 10000;
            max-width: 400px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        `;
        document.body.appendChild(messageEl);
    }
    
    messageEl.textContent = message;
    messageEl.style.backgroundColor = type === 'error' ? '#dc3545' : '#28a745';
    messageEl.style.display = 'block';
    
    // Auto-hide after 5 seconds
    setTimeout(() => {
        messageEl.style.display = 'none';
    }, 5000);
}

// Remove item functionality
document.addEventListener('DOMContentLoaded', function() {
    // Add event listener to payment button
    const paymentBtn = document.getElementById('processPaymentBtn');
    if (paymentBtn) {
        paymentBtn.addEventListener('click', processPayment);
    }
    
    const removeButtons = document.querySelectorAll('.remove-item-btn');
    
    removeButtons.forEach(button => {
        button.addEventListener('click', function() {
            const productId = this.dataset.productId;
            const orderItem = this.closest('.order-item');
            
            if (confirm('Are you sure you want to remove this item?')) {
                // Show loading state
                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                
                // Remove from DOM immediately
                orderItem.remove();
                
                // Update totals (you might want to recalculate)
                showMessage('Item removed from order', 'success');
            }
        });
    });
});
// Review Order Modal
document.addEventListener('DOMContentLoaded', function() {
    const reviewBtn = document.getElementById('reviewOrderBtn');
    
    if (reviewBtn) {
        reviewBtn.addEventListener('click', showReviewModal);
    }
});

function showReviewModal() {
    // Validate form first
    if (!validateCheckoutForm()) {
        return;
    }
    
    // Get form values directly from input fields
    const customerName = document.getElementById('customer_name').value;
    const customerEmail = document.getElementById('customer_email').value;
    const customerPhone = document.getElementById('customer_phone').value;
    const shippingAddress = document.getElementById('shipping_address').value;
    const paymentMethod = document.getElementById('payment_method').value;
    
    // Create modal
    const modal = document.createElement('div');
    modal.className = 'review-modal';
    modal.id = 'reviewModal';
    
    const itemsBySeller = <?php echo json_encode($itemsBySeller); ?>;
    const sellerInfo = <?php echo json_encode($sellerInfo); ?>;
    const hasMultipleSellers = <?php echo $hasMultipleSellers ? 'true' : 'false'; ?>;
    
    let sellerGroupsHtml = '';
    for (let sellerId in itemsBySeller) {
        const seller = sellerInfo[sellerId];
        const items = itemsBySeller[sellerId];
        
        let itemsHtml = items.map(item => `
            <div class="review-item">
                <img src="${item.image_url || 'images/placeholder.jpg'}" alt="${item.name}">
                <div class="review-item-details">
                    <div class="review-item-name">${item.name}</div>
                    <div class="review-item-info">
                        Qty: ${item.quantity} × $${parseFloat(item.price).toFixed(2)} = 
                        $${(item.quantity * item.price).toFixed(2)}
                    </div>
                </div>
            </div>
        `).join('');
        
        sellerGroupsHtml += `
            <div class="review-seller-group">
                <div class="review-seller-header">
                    <i class="fas fa-store"></i> Seller: ${seller.name}
                </div>
                ${itemsHtml}
                <div class="review-subtotal">
                    Subtotal: $${parseFloat(seller.total).toFixed(2)}
                </div>
            </div>
        `;
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
                    <div class="review-info-item">
                        <strong>Name:</strong>
                        ${customerName}
                    </div>
                    <div class="review-info-item">
                        <strong>Email:</strong>
                        ${customerEmail}
                    </div>
                    <div class="review-info-item">
                        <strong>Phone:</strong>
                        ${customerPhone}
                    </div>
                    <div class="review-info-item">
                        <strong>Payment Method:</strong>
                        ${getPaymentMethodLabel(paymentMethod)}
                    </div>
                </div>
                <div class="review-info-grid" style="margin-top: 15px; grid-template-columns: 1fr;">
                    <div class="review-info-item">
                        <strong>Shipping Address:</strong>
                        ${shippingAddress}
                    </div>
                </div>
            </div>
            
            ${hasMultipleSellers ? `
                <div class="alert alert-warning" style="margin-bottom: 20px;">
                    <strong>Multiple Sellers Notice:</strong> You will need to complete separate payments for each seller.
                </div>
            ` : ''}
            
            <div class="review-section">
                <h3><i class="fas fa-shopping-cart"></i> Order Items</h3>
                ${sellerGroupsHtml}
            </div>
            
            <div class="review-grand-total">
                <strong>Grand Total: $<?php echo number_format($checkoutTotal, 2); ?></strong>
            </div>
            
            <div class="review-actions">
                <button type="button" class="btn btn-secondary" onclick="closeReviewModal()">
                    <i class="fas fa-edit"></i> Edit Order
                </button>
                <button type="button" class="btn btn-primary" onclick="confirmAndPlaceOrder()">
                    <i class="fas fa-check-circle"></i> Confirm & Place Order
                </button>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    modal.style.display = 'block';
    
    // Close modal when clicking X or outside
    const closeBtn = modal.querySelector('.close-modal');
    closeBtn.onclick = closeReviewModal;
    
    modal.onclick = function(event) {
        if (event.target === modal) {
            closeReviewModal();
        }
    };
}

function getPaymentMethodLabel(method) {
    const labels = {
        'card': '💳 Credit/Debit Card',
        'credit_card': '💳 Credit/Debit Card',
        'debit_card': '💳 Credit/Debit Card',
        'gcash': '📱 GCash',
        'grab_pay': '🚗 GrabPay',
        'paymaya': '💳 PayMaya',
        'billease': '🏦 Billease',
        'cash_on_delivery': '💰 Cash on Delivery'
    };
    return labels[method] || method;
}

function closeReviewModal() {
    const modal = document.getElementById('reviewModal');
    if (modal) {
        modal.style.display = 'none';
        modal.remove();
    }
}

function confirmAndPlaceOrder() {
    closeReviewModal();
    processPayment();
}
</script>

<?php require_once 'includes/footer.php'; ?>