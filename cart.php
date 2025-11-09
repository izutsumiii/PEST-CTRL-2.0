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
echo '<div style="height:20px"></div>';

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

<h1>YOUR CART</h1>

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
        <p>Your cart is empty.</p>
        <a href="products.php" class="btn btn-primary">Continue Shopping</a>
    </div>
<?php else: ?>
    <form method="POST" action="" id="cart-form">
        <div class="cart-table-container">
            <table class="cart-table">
                <thead>
                    <tr>
                        <th style="width:40px; text-align:center;"><input type="checkbox" id="select-all" checked></th>
                        <th>Product</th>
                        <th>Price</th>
                        <th>Quantity</th>
                        <th>Total</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groupedCart as $sellerId => $sellerGroup): ?>
                        <tr>
                            <td colspan="6" style="background:#130325;font-weight:700;color:#ffffff;text-align:left;">
                                <a href="seller.php?seller_id=<?php echo (int)$sellerId; ?>" style="color:#ffffff;text-decoration:none;display:inline-flex;align-items:center;gap:8px;">
                                    <i class="fas fa-store"></i>
                                    <?php echo htmlspecialchars($sellerGroup['seller_display_name']); ?>
                                    <i class="fas fa-chevron-right" style="font-size:12px;"></i>
                                </a>
                                <span style="font-weight:400;color:#cccccc;margin-left:15px;">(<?php echo $sellerGroup['item_count']; ?> items)</span>
                            </td>
                        </tr>
                        <?php foreach ($sellerGroup['items'] as $item): ?>
                        <tr data-product-id="<?php echo $item['product_id']; ?>">
                            <td style="text-align:center;">
                                <input type="checkbox" class="select-item" name="selected[]" value="<?php echo $item['product_id']; ?>" checked>
                            </td>
                            <td class="product-info">
                                <img src="<?php echo htmlspecialchars($item['image_url']); ?>" 
                                     alt="<?php echo htmlspecialchars($item['name']); ?>" 
                                     class="product-image">
                                <div class="product-details">
                                    <h4><?php echo htmlspecialchars($item['name']); ?></h4>
                                    <p class="stock-info">Stock: <?php echo $item['stock_quantity']; ?> available</p>
                                </div>
                            </td>
                            <td class="price">₱<?php echo number_format($item['price'], 2); ?></td>
                            <td class="quantity">
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
                            </td>
                            <td class="item-total">₱<span id="total-<?php echo $item['product_id']; ?>"><?php echo number_format($item['price'] * $item['quantity'], 2); ?></span></td>
                            <td class="actions">
                                <button type="button" 
                                        class="btn btn-remove" 
                                        title="Remove item"
                                        onclick="removeCartItem(<?php echo $item['product_id']; ?>)"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td></td>
                            <td colspan="3" style="text-align:right;font-weight:700;">Seller Subtotal</td>
                            <td colspan="2" style="font-weight:700;">₱<?php echo number_format($sellerGroup['subtotal'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="cart-total">
                        <td></td>
                        <td colspan="3"><strong>Total</strong></td>
                        <td><strong>₱<span id="cart-total"><?php echo $cartTotal; ?></span></strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        
        <div class="cart-actions">
            <!-- <button type="submit" name="update_cart" class="btn btn-update">Update Cart</button> -->
            <a href="products.php" class="btn btn-continue">Continue Shopping</a>
            <button type="button" class="btn btn-checkout" id="proceed-checkout-btn">Proceed to Checkout</button>
        </div>
    </form>
<?php endif; ?>



<style>

/* Shopping Cart Styles */

body {
    background-color: #f8f9fa !important;
    color: #130325 !important;
}

h1 {
    color: #130325 !important;
    text-align: left;
    margin: 10px 0 20px 60px;
    font-size: 1.8rem;
}

/* Alert messages */
.alert {
    padding: 15px;
    margin: 20px 0;
    border-radius: 5px;
    font-weight: 500;
}

.alert-success {
    background: #2d5a2d;
    color: #90ee90;
    border: 1px solid #4a7c4a;
}

.alert-error {
    background: #5a2d2d;
    color: #ffb3b3;
    border: 1px solid #7c4a4a;
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
    background: #ffffff;
    border: 2px solid #130325;
    border-radius: 8px;
    box-shadow: 0 4px 16px rgba(19, 3, 37, 0.2);
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
    background: #130325;
    border-radius: 50%;
    color: #FFD736;
    font-size: 18px;
}

.buy-now-error .error-content {
    flex: 1;
    min-width: 0;
}

.buy-now-error .error-content strong {
    display: block;
    font-size: 15px;
    color: #130325;
    margin-bottom: 3px;
    font-weight: 700;
}

.buy-now-error .error-content p {
    margin: 0;
    color: #130325;
    font-size: 14px;
    line-height: 1.4;
    font-weight: 500;
}

.buy-now-error .error-close {
    flex-shrink: 0;
    background: transparent;
    border: none;
    color: #130325;
    font-size: 20px;
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
    background: rgba(19, 3, 37, 0.1);
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
    padding: 40px 20px;
    background: #ffffff;
    border-radius: 8px;
    margin: 20px 60px;
    border: 1px solid rgba(0, 0, 0, 0.1);
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.empty-cart p {
    font-size: 1.2rem;
    color: #130325;
    margin-bottom: 20px;
}

/* Cart table container */
.cart-table-container {
    overflow-x: auto;
    margin: 20px 60px;
    padding: 0 20px;
}

.cart-table {
    width: 100%;
    border-collapse: collapse;
    background: #ffffff;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid rgba(0, 0, 0, 0.1);
}

.cart-table th {
    background: #130325;
    color: #ffffff;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    font-size: 0.85rem;
    border-bottom: 1px solid rgba(0, 0, 0, 0.1);
}

.cart-table th:first-child, .cart-table td:first-child {
    text-align: center;
}

/* Enlarge and emphasize selection checkboxes */
#select-all,
.select-item {
    transform: scale(1.35);
    transform-origin: center center;
    cursor: pointer;
    accent-color: var(--accent-yellow);
}
.select-item:focus-visible { outline: 2px solid #130325; outline-offset: 2px; }
.cart-table th:first-child, .cart-table td:first-child { border-right: 1px solid rgba(0, 0, 0, 0.1); }

/* Seller link styles */
.cart-table td a[href*="seller.php"] {
    transition: all 0.3s ease;
}

.cart-table td a[href*="seller.php"]:hover {
    color: #FFD736 !important;
    text-decoration: underline;
}

.cart-table td a[href*="seller.php"]:hover .fas.fa-chevron-right {
    transform: translateX(3px);
}

@media (max-width: 768px) {
    .cart-table-container { padding: 0 15px; }
    #select-all, .select-item { transform: scale(1.5); }
}

.cart-table td {
    padding: 12px;
    border-bottom: 1px solid #e0e0e0;
    color: #130325;
    background: #ffffff;
    font-size: 0.85rem;
}

.cart-table tbody tr:hover {
    background: #f8f9fa;
}

/* Product info cell */
.product-info {
    display: flex;
    align-items: center;
    gap: 15px;
}

.product-image {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border-radius: 5px;
}

.product-details h4 {
    margin: 0 0 5px 0;
    color: #130325;
    font-size: 0.9rem;
    font-weight: 600;
}

.stock-info {
    margin: 0;
    font-size: 0.9rem;
    color: #666666;
}

/* Price and totals */
.price, .item-total {
    font-weight: 600;
    color: #FFD736;
}

/* Quantity controls */
.quantity-controls {
    display: flex;
    align-items: center;
    gap: 5px;
}

.qty-btn {
    width: 30px;
    height: 30px;
    border: 1px solid #ddd;
    background: #f8f9fa;
    cursor: pointer;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
}

.qty-btn:hover {
    background: #e9ecef;
}

.qty-input {
    width: 60px;
    text-align: center;
    border: 1px solid #ddd;
    padding: 5px;
    border-radius: 4px;
}

/* Cart total row */
.cart-total {
    background: #f8f9fa;
    font-weight: bold;
}

.cart-total td {
    border-bottom: none;
    color: #130325;
}

/* Buttons */
.btn {
    padding: 4px 10px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    font-size: 0.75rem;
    text-align: center;
    transition: all 0.3s ease;
}

.btn-primary {
    background: #ffffff;
    color: #130325;
    border: 2px solid #130325;
}

.btn-primary:hover {
    background: #f0f0f0;
    color: #130325;
}

.btn-remove {
    background: transparent;
    color: #dc3545;
    border: none;
    border-radius: 0;
    width: 32px;
    height: 32px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.2s ease, color 0.2s ease;
}

.btn-remove:hover {
    background: transparent;
    color: #b02a37;
    transform: scale(1.08);
}

/* Solid danger button for confirmations */
.btn-danger {
    background: #dc3545;
    color: #ffffff;
    border: none;
    border-radius: 6px;
    padding: 8px 14px;
    font-size: 0.85rem;
    font-weight: 700;
}

.btn-danger:hover { background: #c82333; }

.btn-update {
    background: #28a745;
    color: white;
}

.btn-update:hover {
    background: #218838;
}

.btn-continue {
    background: #ffffff;
    color: #130325;
    border: 2px solid #130325;
}

.btn-continue:hover {
    background: #f0f0f0;
    color: #130325;
}

.btn-checkout {
    background: var(--accent-yellow);
    color: var(--primary-dark);
    font-weight: 700;
}

.btn-checkout:hover {
    background: #e6c230;
}

/* Cart actions */
.cart-actions {
    display: flex !important;
    gap: 15px;
    justify-content: flex-end;
    margin: 30px 60px 30px 60px !important;
    flex-wrap: wrap;
}

/* Responsive design */
@media (max-width: 768px) {
    .cart-table {
        font-size: 0.9rem;
    }
    
    .cart-table th,
    .cart-table td {
        padding: 10px 8px;
    }
    
    .product-info {
        flex-direction: column;
        text-align: center;
    }
    
    .product-image {
        width: 50px;
        height: 50px;
    }
    
    .cart-actions {
        flex-direction: column;
        align-items: center;
    }
    
    .btn {
        width: 200px;
    }
    
    .qty-input {
        width: 50px;
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
        background: #ffffff;
        border-radius: 12px;
        padding: 0;
        max-width: 400px;
        width: 90%;
        box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        animation: slideDown 0.3s ease;
    }
    .remove-modal-header {
        background: #130325;
        color: #ffffff;
        padding: 16px 20px;
        border-radius: 12px 12px 0 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .remove-modal-header h3 {
        margin: 0;
        font-size: 14px;
        font-weight: 700;
        color: #ffffff;
    }
    .remove-modal-body {
        padding: 20px;
        color: #130325;
    }
    .remove-modal-body p {
        margin: 0;
        font-size: 13px;
        line-height: 1.5;
        color: #130325;
    }
    .remove-modal-footer {
        padding: 16px 24px;
        border-top: 1px solid #e5e7eb;
        display: flex;
        gap: 10px;
        justify-content: flex-end;
    }
    .remove-cancel-btn {
        padding: 8px 20px;
        background: #f3f4f6;
        color: #130325;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
        font-size: 14px;
        transition: all 0.2s ease;
    }
    .remove-cancel-btn:hover {
        background: #e5e7eb;
    }
    .remove-confirm-btn {
        padding: 8px 20px;
        background: #130325;
        color: #ffffff;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
        font-size: 14px;
        transition: all 0.2s ease;
    }
    .remove-confirm-btn:hover {
        background: #0a0218;
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
    const row = input.closest('tr');
    const priceText = row.querySelector('.price').textContent;
    const price = parseFloat(priceText.replace('₱', ''));
    
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
    const rows = document.querySelectorAll('tbody tr[data-product-id]');
    rows.forEach(function(row){
        const cb = row.querySelector('.select-item');
        if (cb && cb.checked) {
            const totalEl = row.querySelector('.item-total span');
            if (totalEl) {
                const value = parseFloat(totalEl.textContent);
                if (!isNaN(value)) total += value;
            }
        }
    });
    
    document.getElementById('cart-total').textContent = total.toFixed(2);
    
    // Cart notification removed
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