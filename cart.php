<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Check if user is logged in BEFORE including header
if (!isLoggedIn()) {
    header("Location: login.php");
    exit();
}

require_once 'includes/header.php';

// spacer below fixed header
echo '<div style="height:5px"></div>';

// Handle add to cart from product detail page
if (isset($_POST['add_to_cart'])) {
    $productId = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity']);
    
    $result = addToCart($productId, $quantity);
    if ($result['success']) {
        $successMessage = "Product added to cart!";
    } else {
        $errorMessage = $result['message'];
    }
}

// Handle update quantity
if (isset($_POST['update_cart'])) {
    $hasErrors = false;
    $messages = [];
    
    foreach ($_POST['quantities'] as $productId => $quantity) {
        $result = updateCartQuantity(intval($productId), intval($quantity));
        if (!$result['success']) {
            $hasErrors = true;
            $messages[] = $result['message'];
        }
    }
    
    if (!$hasErrors) {
        $successMessage = "Cart updated successfully!";
    } else {
        $errorMessage = implode(', ', $messages);
    }
}

// Get cart items directly from database - SIMPLE APPROACH
$userId = $_SESSION['user_id'];

// CRITICAL: Do NOT clear buy_now session here!
// Buy_now items are in session ONLY, not in cart table
// Buy_now session should only be cleared AFTER checkout completes
// cart.php should ONLY display items from cart table, buy_now items are invisible here

// First, get all cart items with product info - USE LEFT JOIN to keep cart rows even if product missing
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

error_log('Cart.php - LEFT JOIN query returned ' . count($allCartItems) . ' rows for user ' . $userId);

// Check raw cart count as authoritative source
$rawCartCountStmt = $pdo->prepare("SELECT COUNT(*) as count FROM cart WHERE user_id = ?");
$rawCartCountStmt->execute([$userId]);
$rawCartCount = (int)($rawCartCountStmt->fetchColumn() ?? 0);
error_log('Cart.php - Raw cart count (authoritative): ' . $rawCartCount);

// Group by seller manually
$groupedCart = [];
$cartTotal = 0;

foreach ($allCartItems as $row) {
    // Normalize product data when missing (same as checkout page)
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
    
    if (!isset($groupedCart[$sellerId])) {
        $groupedCart[$sellerId] = [
            'seller_id' => $sellerId,
            'seller_display_name' => $sellerName,
            'items' => [],
            'subtotal' => 0,
            'item_count' => 0
        ];
    }
    
    $itemTotal = $price * $qty;
    $cartTotal += $itemTotal;
    
    $groupedCart[$sellerId]['items'][] = [
        'product_id' => $productId,
        'name' => $name,
        'price' => $price,
        'quantity' => $qty,
        'image_url' => $image,
        'stock_quantity' => $stock,
        'status' => $status,
        'product_exists' => $exists
    ];
    
    $groupedCart[$sellerId]['subtotal'] += $itemTotal;
    $groupedCart[$sellerId]['item_count'] += $qty;
}

// Emergency recovery if needed
if (empty($groupedCart) && $rawCartCount > 0) {
    error_log('Cart.php - Emergency recovery: rawCartCount > 0 but groupedCart empty');
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
        if (!isset($groupedCart[$sId])) {
            $groupedCart[$sId] = [
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
        $groupedCart[$sId]['items'][] = [
            'product_id' => (int)$item['product_id'],
            'name' => $item['name'] ?? 'Unknown Product',
            'price' => $price,
            'quantity' => $qty,
            'image_url' => $item['image_url'] ?? 'images/placeholder.jpg',
            'stock_quantity' => (int)$item['stock_quantity'],
            'status' => $item['status'] ?? 'active',
            'product_exists' => 1
        ];
        $groupedCart[$sId]['subtotal'] += $itTotal;
        $groupedCart[$sId]['item_count'] += $qty;
        $cartTotal += $itTotal;
    }
    error_log('Cart.php - Emergency recovery: ' . count($groupedCart) . ' seller groups');
}

// Format cart total
$cartTotal = number_format($cartTotal, 2);

// Debug logging
error_log('Cart.php - Found ' . count($allCartItems) . ' cart items for user ' . $userId);
error_log('Cart.php - Grouped into ' . count($groupedCart) . ' seller groups');
error_log('Cart.php - Cart total: ' . $cartTotal);

// Handle URL error parameters (e.g., from buy now redirects)
// BUT: If we have items in cart, don't show the error (items were successfully added)
$shouldRedirect = false;
if (isset($_GET['error'])) {
    if (empty($groupedCart)) {
        // No items in cart, show the error
        $errorParam = $_GET['error'];
        switch ($errorParam) {
            case 'buy_now_session_expired':
                $errorMessage = "Buy Now session expired. Please try again.";
                break;
            case 'buy_now_failed':
                $errorMessage = "Buy Now failed. The product may no longer be available. Please add it to cart instead.";
                break;
            case 'invalid_buy_now':
                $errorMessage = "Invalid Buy Now request. Please try again.";
                break;
            case 'seller_not_found':
                $errorMessage = "Product seller not found. Please try again or contact support.";
                break;
            case 'cart_empty':
                $errorMessage = "Your cart is empty. Please add items to your cart before checkout.";
                break;
            default:
                $errorMessage = "An error occurred. Please try again.";
        }
    } else {
        // Items are in cart, so buy now succeeded - mark for JavaScript redirect
        $shouldRedirect = true;
    }
}
?>

<?php if ($shouldRedirect): ?>
<script>
    // Redirect to clean URL without error parameter
    window.location.replace('cart.php');
</script>
<?php endif; ?>

<div class="cart-container">
    <h1 class="page-title">Your Cart</h1>

    <?php if (isset($successMessage)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>

    <?php if (isset($errorMessage)): ?>
        <div class="alert alert-error buy-now-error">
            <div class="error-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="error-content">
                <strong>Buy Now Failed</strong>
                <p><?php echo htmlspecialchars($errorMessage); ?></p>
            </div>
            <button type="button" class="error-close" onclick="this.parentElement.style.display='none'">
                <i class="fas fa-times"></i>
            </button>
        </div>
    <?php endif; ?>

    <?php if (empty($groupedCart)): ?>
        <div class="empty-cart">
            <div class="empty-cart-icon">🛒</div>
            <p>Your cart is empty.</p>
            <a href="products.php" class="btn btn-primary">Continue Shopping</a>
        </div>
    <?php else: ?>
        <form method="POST" action="" id="cart-form">
            <div class="cart-content">
                <div class="cart-table-container">
                    <div class="cart-select-all">
                        <input type="checkbox" id="select-all" checked>
                        <label for="select-all">Select All</label>
                    </div>
                    
                    <?php foreach ($groupedCart as $sellerId => $sellerGroup): ?>
                        <div class="seller-group">
                            <div class="seller-header">
                                <a href="seller.php?seller_id=<?php echo (int)$sellerId; ?>" class="seller-link">
                                    <i class="fas fa-store"></i>
                                    <span><?php echo htmlspecialchars($sellerGroup['seller_display_name']); ?></span>
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                                <span class="seller-item-count"><?php echo $sellerGroup['item_count']; ?> item<?php echo $sellerGroup['item_count'] > 1 ? 's' : ''; ?></span>
                            </div>
                            
                            <div class="cart-items">
                                <?php foreach ($sellerGroup['items'] as $item): ?>
                                <div class="cart-item" data-product-id="<?php echo $item['product_id']; ?>">
                                    <div class="cart-item-checkbox">
                                        <input type="checkbox" class="select-item" name="selected[]" value="<?php echo $item['product_id']; ?>" checked>
                                    </div>
                                    <div class="cart-item-image">
                                        <img src="<?php echo htmlspecialchars($item['image_url']); ?>" 
                                             alt="<?php echo htmlspecialchars($item['name']); ?>">
                                    </div>
                                    <div class="cart-item-details">
                                        <h4 class="cart-item-name"><?php echo htmlspecialchars($item['name']); ?></h4>
                                        <p class="cart-item-stock">Stock: <?php echo $item['stock_quantity']; ?> available</p>
                                    </div>
                                    <div class="cart-item-price">
                                        <span class="price-label">Price</span>
                                        <span class="price-value">₱<?php echo number_format($item['price'], 2); ?></span>
                                    </div>
                                    <div class="cart-item-quantity">
                                        <span class="qty-label">Quantity</span>
                                        <div class="quantity-controls">
                                            <button type="button" class="qty-btn" onclick="updateQuantity(<?php echo $item['product_id']; ?>, -1)">-</button>
                                            <input type="number" 
                                                   name="quantities[<?php echo $item['product_id']; ?>]" 
                                                   value="<?php echo $item['quantity']; ?>" 
                                                   min="1" 
                                                   max="<?php echo $item['stock_quantity']; ?>"
                                                   class="qty-input"
                                                   onchange="calculateItemTotal(<?php echo $item['product_id']; ?>, <?php echo $item['price']; ?>)">
                                            <button type="button" class="qty-btn" onclick="updateQuantity(<?php echo $item['product_id']; ?>, 1)">+</button>
                                        </div>
                                    </div>
                                    <div class="cart-item-total">
                                        <span class="total-label">Total</span>
                                        <span class="total-value">₱<span id="total-<?php echo $item['product_id']; ?>"><?php echo number_format($item['price'] * $item['quantity'], 2); ?></span></span>
                                    </div>
                                    <div class="cart-item-action">
                                        <button type="button" 
                                                class="btn-remove" 
                                                title="Remove item"
                                                onclick="removeCartItem(<?php echo $item['product_id']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                
                                <div class="seller-subtotal">
                                    <span>Seller Subtotal:</span>
                                    <strong>₱<?php echo number_format($sellerGroup['subtotal'], 2); ?></strong>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="cart-summary">
                    <div class="cart-summary-header">
                        <h3>Order Summary</h3>
                    </div>
                    <div class="cart-summary-content">
                        <div class="summary-row">
                            <span>Total</span>
                            <strong>₱<span id="cart-total"><?php echo $cartTotal; ?></span></strong>
                        </div>
                    </div>
                    <div class="cart-actions">
                        <a href="products.php" class="btn btn-continue">Continue Shopping</a>
                        <button type="button" class="btn btn-checkout" id="proceed-checkout-btn">Proceed to Checkout</button>
                    </div>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>



<style>
:root {
    --primary-dark: #130325;
    --accent-yellow: #FFD736;
    --text-dark: #1a1a1a;
    --text-light: #6b7280;
    --border-light: #e5e7eb;
    --bg-light: #f9fafb;
    --bg-white: #ffffff;
    --success-green: #10b981;
    --error-red: #ef4444;
}

body {
    background: var(--bg-light) !important;
    color: var(--text-dark);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}

.cart-container {
    max-width: 1600px;
    margin: 0 auto;
    padding: 12px 20px;
}

h1, .page-title {
    color: var(--text-dark);
    margin: 0 0 16px 0;
    font-size: 1.35rem;
    font-weight: 600;
    letter-spacing: -0.3px;
}

/* Alert messages */
.alert {
    padding: 10px 14px;
    margin: 0 0 14px 0;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 500;
    border: 1px solid;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.alert-success {
    background: rgba(16, 185, 129, 0.1);
    color: var(--success-green);
    border-color: rgba(16, 185, 129, 0.2);
}

.alert-error {
    background: rgba(239, 68, 68, 0.1);
    color: var(--error-red);
    border-color: rgba(239, 68, 68, 0.2);
}

/* Enhanced Buy Now Error Styling - Bottom notification */
.buy-now-error {
    position: fixed;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 18px;
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    margin: 0;
    max-width: 500px;
    width: 90%;
    z-index: 10000;
    animation: slideInUp 0.3s ease-out;
}

.buy-now-error .error-icon {
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--primary-dark);
    border-radius: 50%;
    color: var(--accent-yellow);
    font-size: 18px;
}

.buy-now-error .error-content {
    flex: 1;
    min-width: 0;
}

.buy-now-error .error-content strong {
    display: block;
    font-size: 14px;
    color: var(--text-dark);
    margin-bottom: 3px;
    font-weight: 700;
}

.buy-now-error .error-content p {
    margin: 0;
    color: var(--text-dark);
    font-size: 13px;
    line-height: 1.4;
    font-weight: 500;
}

.buy-now-error .error-close {
    flex-shrink: 0;
    background: transparent;
    border: none;
    color: var(--text-dark);
    font-size: 18px;
    cursor: pointer;
    padding: 4px;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.2s ease;
    opacity: 0.7;
}

.buy-now-error .error-close:hover {
    background: var(--bg-light);
    opacity: 1;
}

@keyframes slideInUp {
    from {
        opacity: 0;
        transform: translate(-50%, 20px);
    }
    to {
        opacity: 1;
        transform: translate(-50%, 0);
    }
}

/* Auto-hide animation */
@keyframes slideOutDown {
    from {
        opacity: 1;
        transform: translate(-50%, 0);
    }
    to {
        opacity: 0;
        transform: translate(-50%, 20px);
    }
}

.buy-now-error.hiding {
    animation: slideOutDown 0.3s ease-out forwards;
}

/* Empty cart */
.empty-cart {
    text-align: center;
    padding: 60px 20px;
    background: var(--bg-white);
    border-radius: 12px;
    border: 1px solid var(--border-light);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.empty-cart-icon {
    font-size: 64px;
    margin-bottom: 16px;
    opacity: 0.5;
}

.empty-cart p {
    font-size: 15px;
    color: var(--text-light);
    margin-bottom: 20px;
}

/* Cart content layout */
.cart-content {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 16px;
    margin-bottom: 16px;
}

.cart-select-all {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    margin-bottom: 10px;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-dark);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.cart-select-all input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: var(--primary-dark);
}

.cart-select-all label {
    cursor: pointer;
    margin: 0;
}

/* Seller group */
.seller-group {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    margin-bottom: 14px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.seller-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    background: var(--primary-dark);
    color: var(--bg-white);
}

.seller-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--bg-white);
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    transition: color 0.2s ease;
}

.seller-link:hover {
    color: rgba(255, 255, 255, 0.9);
    opacity: 0.9;
}

.seller-link .fas.fa-chevron-right {
    font-size: 12px;
    transition: transform 0.2s ease;
}

.seller-link:hover .fas.fa-chevron-right {
    transform: translateX(3px);
}

.seller-item-count {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.8);
    font-weight: 400;
}

/* Cart items */
.cart-items {
    padding: 10px;
}

.cart-item {
    display: grid;
    grid-template-columns: 40px 80px 1fr 100px 140px 100px 50px;
    gap: 10px;
    align-items: center;
    padding: 10px;
    border-bottom: 1px solid var(--border-light);
    transition: background 0.2s ease;
}

.cart-item:last-child {
    border-bottom: none;
}

.cart-item:hover {
    background: var(--bg-light);
}

.cart-item-checkbox {
    display: flex;
    align-items: center;
    justify-content: center;
}

.cart-item-checkbox input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: var(--primary-dark);
}

.cart-item-image {
    width: 70px;
    height: 70px;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid var(--border-light);
}

.cart-item-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.cart-item-details {
    min-width: 0;
}

.cart-item-name {
    margin: 0 0 4px 0;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dark);
    line-height: 1.4;
}

.cart-item-stock {
    margin: 0;
    font-size: 11px;
    color: var(--text-light);
}

.cart-item-price,
.cart-item-quantity,
.cart-item-total {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.price-label,
.qty-label,
.total-label {
    font-size: 11px;
    color: var(--text-light);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.price-value {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-dark);
}

.quantity-controls {
    display: flex;
    align-items: center;
    gap: 6px;
}

.qty-btn {
    width: 28px;
    height: 28px;
    border: 1px solid var(--border-light);
    background: var(--bg-white);
    color: var(--text-dark);
    cursor: pointer;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.2s ease;
}

.qty-btn:hover {
    background: var(--bg-light);
    border-color: var(--primary-dark);
}

.qty-input {
    width: 50px;
    text-align: center;
    border: 1px solid var(--border-light);
    padding: 6px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
}

.total-value {
    font-size: 14px;
    font-weight: 700;
    color: var(--primary-dark);
}

.cart-item-action {
    display: flex;
    align-items: center;
    justify-content: center;
}

.btn-remove {
    background: transparent;
    color: var(--error-red);
    border: none;
    width: 32px;
    height: 32px;
    font-size: 14px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: all 0.2s ease;
}

.btn-remove:hover {
    background: rgba(239, 68, 68, 0.1);
    transform: scale(1.1);
}

.seller-subtotal {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    margin-top: 8px;
    background: var(--bg-light);
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    color: var(--text-dark);
}

.seller-subtotal strong {
    font-size: 15px;
    color: var(--primary-dark);
}

/* Cart summary */
.cart-summary {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    height: fit-content;
    position: sticky;
    top: 20px;
}

.cart-summary-header {
    padding: 12px 14px;
    border-bottom: 1px solid var(--border-light);
}

.cart-summary-header h3 {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
    color: var(--text-dark);
    letter-spacing: -0.3px;
    padding: 5px 8px;
    background: rgba(19, 3, 37, 0.04);
    border-radius: 6px;
    display: inline-block;
}

.cart-summary-content {
    padding: 12px 14px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    font-size: 13px;
}

.summary-row span {
    color: var(--text-dark);
    font-weight: 500;
}

.summary-row strong {
    font-size: 16px;
    font-weight: 700;
    color: var(--primary-dark);
    letter-spacing: -0.3px;
}

/* Buttons */
.btn {
    padding: 8px 14px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 600;
    text-align: center;
    transition: all 0.2s ease;
    gap: 5px;
}

.btn-primary {
    background: var(--bg-white);
    color: var(--primary-dark);
    border: 1px solid var(--primary-dark);
}

.btn-primary:hover {
    background: var(--primary-dark);
    color: var(--bg-white);
}

.btn-continue {
    background: var(--bg-white);
    color: var(--primary-dark);
    border: 1px solid var(--primary-dark);
}

.btn-continue:hover {
    background: var(--primary-dark);
    color: var(--bg-white);
}

.btn-checkout {
    background: var(--primary-dark);
    color: var(--bg-white);
    font-weight: 700;
}

.btn-checkout:hover {
    background: #0a0118;
    transform: translateY(-1px);
}

.btn-checkout:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.cart-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 12px 14px;
    border-top: 1px solid var(--border-light);
}

.cart-actions .btn {
    width: 100%;
}

/* Responsive design */
@media (max-width: 968px) {
    .cart-container {
        padding: 10px 12px;
        max-width: 100%;
    }
    
    h1, .page-title {
        font-size: 1.2rem;
        margin-bottom: 12px;
    }
    
    .cart-content {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .cart-summary {
        position: static;
    }
    
    .cart-item {
        grid-template-columns: 40px 60px 1fr;
        gap: 10px;
        padding: 10px;
    }
    
    .cart-item-image {
        width: 60px;
        height: 60px;
    }
    
    .cart-item-price,
    .cart-item-quantity,
    .cart-item-total {
        grid-column: 3;
        flex-direction: row;
        justify-content: space-between;
        align-items: center;
    }
    
    .cart-item-action {
        grid-column: 3;
        grid-row: 4;
        justify-content: flex-end;
    }
    
    .price-label,
    .qty-label,
    .total-label {
        display: none;
    }
    
    .price-value,
    .total-value {
        font-size: 13px;
    }
    
    .quantity-controls {
        gap: 4px;
    }
    
    .qty-btn {
        width: 26px;
        height: 26px;
        font-size: 12px;
    }
    
    .qty-input {
        width: 45px;
        padding: 4px;
        font-size: 12px;
    }
}

@media (max-width: 640px) {
    .cart-item {
        grid-template-columns: 30px 50px 1fr;
        gap: 8px;
        padding: 8px;
    }
    
    .cart-item-image {
        width: 50px;
        height: 50px;
    }
    
    .cart-item-name {
        font-size: 13px;
    }
    
    .cart-item-stock {
        font-size: 11px;
    }
    
    .seller-header {
        padding: 10px 12px;
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
    }
    
    .seller-link {
        font-size: 13px;
    }
    
    .seller-item-count {
        font-size: 11px;
    }
}


</style>

<!-- Remove Item Confirmation Modal -->
<div id="removeModal" class="remove-modal" style="display:none;">
    <div class="remove-modal-content">
        <div class="remove-modal-header">
            <i class="fas fa-trash" style="font-size: 16px; color: #FFD736;"></i>
            <h3>Remove Item</h3>
        </div>
        <div class="remove-modal-body">
            <p>Do you want to remove this product from your cart?</p>
        </div>
        <div class="remove-modal-footer">
            <button id="removeCancel" class="remove-cancel-btn">Cancel</button>
            <button id="removeConfirm" class="remove-confirm-btn">Remove</button>
        </div>
    </div>
    <style>
    .remove-modal {
        position: fixed;
        z-index: 10000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .remove-modal-content {
        background: var(--bg-white);
        border-radius: 12px;
        padding: 0;
        max-width: 400px;
        width: 90%;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        border: 1px solid var(--border-light);
        animation: slideDown 0.3s ease;
    }
    .remove-modal-header {
        background: var(--primary-dark);
        color: var(--bg-white);
        padding: 14px 18px;
        border-radius: 12px 12px 0 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .remove-modal-header h3 {
        margin: 0;
        font-size: 14px;
        font-weight: 700;
        color: var(--bg-white);
    }
    .remove-modal-body {
        padding: 18px;
        color: var(--text-dark);
    }
    .remove-modal-body p {
        margin: 0;
        font-size: 13px;
        line-height: 1.5;
        color: var(--text-dark);
    }
    .remove-modal-footer {
        padding: 14px 18px;
        border-top: 1px solid var(--border-light);
        display: flex;
        gap: 10px;
        justify-content: flex-end;
    }
    .remove-cancel-btn {
        padding: 8px 18px;
        background: var(--bg-white);
        color: var(--text-dark);
        border: 1px solid var(--border-light);
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        font-size: 14px;
        transition: all 0.2s ease;
    }
    .remove-cancel-btn:hover {
        background: var(--bg-light);
    }
    .remove-confirm-btn {
        padding: 8px 18px;
        background: var(--primary-dark);
        color: var(--bg-white);
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        font-size: 14px;
        transition: all 0.2s ease;
    }
    .remove-confirm-btn:hover {
        background: #0a0118;
    }
    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    </style>
</div>

<script>
// CSS modal confirm for Remove
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('removeModal');
    const cancelBtn = document.getElementById('removeCancel');
    const confirmBtn = document.getElementById('removeConfirm');

    function closeModal() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        window.pendingRemoveProductId = null;
    }

    cancelBtn.addEventListener('click', closeModal);
    confirmBtn.addEventListener('click', confirmRemoveItem);
    
    modal.addEventListener('click', function(e) {
        if (e.target === modal) closeModal();
    });
});

// AJAX-based cart item removal
function removeCartItem(productId) {
    // Store the product ID for the modal
    window.pendingRemoveProductId = productId;
    
    // Show the CSS modal
    const modal = document.getElementById('removeModal');
    modal.style.display = 'flex';
}

// Function to actually remove the item (called from modal)
function confirmRemoveItem() {
    const productId = window.pendingRemoveProductId;
    if (!productId) return;
    
    // Hide the modal
    const modal = document.getElementById('removeModal');
    modal.style.display = 'none';
    
    fetch('ajax/cart-handler.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'remove_item',
            product_id: productId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message
            showNotification('Item removed from cart!', 'success');
            
            // Update cart notification
            if (data.count !== undefined && typeof updateCartNotification === 'function') {
                updateCartNotification(data.count);
            }
            
            // Check if cart is empty and show empty cart message
            if (data.count === 0) {
                // Replace the entire cart table with empty cart message
                const cartTable = document.querySelector('.cart-table-container');
                if (cartTable) {
                    cartTable.innerHTML = `
                        <div class="empty-cart">
                            <p>Your cart is empty.</p>
                            <a href="products.php" class="btn btn-primary">Continue Shopping</a>
                        </div>
                    `;
                }
            } else {
                // Reload the cart table to ensure proper seller grouping
                setTimeout(() => {
                    location.reload();
                }, 1000);
            }
        } else {
            showNotification(data.message || 'Error removing item', 'error');
        }
    })
    .catch(error => {
        console.error('Error removing cart item:', error);
        showNotification('Error removing item', 'error');
    });
}

// Show notification function
function showNotification(message, type) {
    // Remove existing notifications
    const existingNotifications = document.querySelectorAll('.notification');
    existingNotifications.forEach(notification => notification.remove());
    
    // Create new notification
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.textContent = message;
    notification.style.cssText = `
        position: fixed;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        padding: 15px 20px;
        border-radius: 8px;
        color: white;
        z-index: 1000;
        max-width: 400px;
        text-align: center;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        font-weight: 500;
    `;
    
    if (type === 'success') {
        notification.style.backgroundColor = '#4CAF50';
    } else {
        notification.style.backgroundColor = '#F44336';
    }
    
    document.body.appendChild(notification);
    
    // Remove notification after 3 seconds
    setTimeout(() => {
        notification.remove();
    }, 3000);
}

// Select-all behavior
document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('select-all');
    const itemChecks = document.querySelectorAll('.select-item');
    if (selectAll) {
        // default to all selected
        selectAll.checked = true;
        itemChecks.forEach(cb => { cb.checked = true; });
        selectAll.addEventListener('change', function() {
            itemChecks.forEach(cb => { cb.checked = selectAll.checked; });
            calculateCartTotal();
        });
        itemChecks.forEach(cb => {
            cb.addEventListener('change', function() {
                const allChecked = Array.from(itemChecks).every(x => x.checked);
                const noneChecked = Array.from(itemChecks).every(x => !x.checked);
                selectAll.indeterminate = !allChecked && !noneChecked;
                if (allChecked) selectAll.checked = true;
                if (noneChecked) selectAll.checked = false;
                calculateCartTotal();
            });
        });
    }

    // Auto-dismiss success notifications after 3 seconds
    const successAlerts = document.querySelectorAll('.alert-success');
    if (successAlerts.length) {
        setTimeout(() => {
            successAlerts.forEach(a => {
                a.style.transition = 'opacity 0.4s ease';
                a.style.opacity = '0';
                setTimeout(() => { a.style.display = 'none'; }, 400);
            });
        }, 1500);
    }
});

// Update quantity with plus/minus buttons
function updateQuantity(productId, change) {
    const input = document.querySelector(`input[name="quantities[${productId}]"]`);
    const currentValue = parseInt(input.value);
    const maxValue = parseInt(input.max);
    const minValue = parseInt(input.min);
    
    let newValue = currentValue + change;
    
    // Ensure value is within bounds
    if (newValue < minValue) newValue = minValue;
    if (newValue > maxValue) newValue = maxValue;
    
    input.value = newValue;
    
    // Calculate new item total
    const item = input.closest('.cart-item');
    const priceText = item.querySelector('.price-value').textContent;
    const price = parseFloat(priceText.replace('₱', '').replace(/,/g, ''));
    
    calculateItemTotal(productId, price);
}

// Calculate item total when quantity changes
function calculateItemTotal(productId, price) {
    const input = document.querySelector(`input[name="quantities[${productId}]"]`);
    const quantity = parseInt(input.value);
    const total = price * quantity;
    
    document.getElementById(`total-${productId}`).textContent = total.toFixed(2);
    
    // Recalculate cart total
    calculateCartTotal();
}

// Calculate total cart amount
function calculateCartTotal() {
    let total = 0;
    const items = document.querySelectorAll('.cart-item[data-product-id]');
    items.forEach(function(item){
        const cb = item.querySelector('.select-item');
        if (cb && cb.checked) {
            const totalEl = item.querySelector('.total-value span');
            if (totalEl) {
                const value = parseFloat(totalEl.textContent);
                if (!isNaN(value)) total += value;
            }
        }
    });
    
    document.getElementById('cart-total').textContent = total.toFixed(2);
}


// Automatically update cart before proceeding to checkout
document.addEventListener('DOMContentLoaded', function() {
    // Refresh cart notification when cart page loads
    if (typeof refreshCartNotification === 'function') {
        refreshCartNotification();
    }
    
    const checkoutBtn = document.getElementById('proceed-checkout-btn');
    if (checkoutBtn) {
        checkoutBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const form = document.getElementById('cart-form');
            if (!form) {
                console.error('Cart form not found');
                alert('Error: Cart form not found. Please refresh the page.');
                return;
            }
            
            // Gather selected items and append to checkout URL
            const selected = Array.from(document.querySelectorAll('.select-item:checked')).map(cb => cb.value);
            const params = new URLSearchParams();
            if (selected.length) {
                params.set('selected', selected.join(','));
            }
            
            // Disable button to prevent double-click
            checkoutBtn.disabled = true;
            checkoutBtn.textContent = 'Processing...';
            
            // Submit quantity updates first to persist changes
            const formData = new FormData(form);
            formData.append('update_cart', '1');
            
            fetch('cart.php', { 
                method: 'POST', 
                body: formData 
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Failed to update cart');
                }
                return response.text();
            })
            .then(() => {
                // Redirect to checkout
                const qs = params.toString();
                window.location.href = 'paymongo/multi-seller-checkout.php' + (qs ? ('?' + qs) : '');
            })
            .catch(error => {
                console.error('Checkout error:', error);
                alert('Error updating cart. Please try again.');
                checkoutBtn.disabled = false;
                checkoutBtn.textContent = 'Proceed to Checkout';
            });
        });
    }
});

// Auto-dismiss Buy Now error notification after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const buyNowError = document.querySelector('.buy-now-error');
    
    if (buyNowError) {
        // Auto-hide after 5 seconds
        setTimeout(function() {
            buyNowError.classList.add('hiding');
            setTimeout(function() {
                buyNowError.style.display = 'none';
            }, 300); // Match animation duration
        }, 5000);
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>