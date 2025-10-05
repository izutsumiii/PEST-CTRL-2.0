<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

// Check if user is logged in BEFORE including header
if (!isLoggedIn()) {
    header("Location: login.php");
    exit();
}

require_once 'includes/header.php';

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

// Handle remove from cart
if (isset($_GET['remove'])) {
    $productId = intval($_GET['remove']);
    if (removeFromCart($productId)) {
        $successMessage = "Item removed from cart!";
    } else {
        $errorMessage = "Error removing item from cart.";
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

$groupedCart = getCartItemsGroupedBySeller();
$cartTotal = getMultiSellerCartTotal();
?>

<h1>Shopping Cart</h1>

<?php if (isset($successMessage)): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div>
<?php endif; ?>

<?php if (isset($errorMessage)): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div>
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
                        <th style="width:40px; text-align:center;"><input type="checkbox" id="select-all"></th>
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
                            <td colspan="6" style="background:#f1f3f5;font-weight:700;color:#333;">
                                Seller: <?php echo htmlspecialchars($sellerGroup['seller_display_name']); ?>
                                <span style="font-weight:400;color:#666;">(<?php echo $sellerGroup['item_count']; ?> items)</span>
                            </td>
                        </tr>
                        <?php foreach ($sellerGroup['items'] as $item): ?>
                        <tr data-product-id="<?php echo $item['product_id']; ?>">
                            <td style="text-align:center;">
                                <input type="checkbox" class="select-item" name="selected[]" value="<?php echo $item['product_id']; ?>">
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
                                <a href="cart.php?remove=<?php echo $item['product_id']; ?>" 
                                   class="btn btn-remove" 
                                   title="Remove item">×</a>
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

h1 {
    color: #333;
    text-align: center;
    margin: 20px 0;
    font-size: 2rem;
}

/* Alert messages */
.alert {
    padding: 15px;
    margin: 20px 0;
    border-radius: 5px;
    font-weight: 500;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

/* Empty cart */
.empty-cart {
    text-align: center;
    padding: 40px 20px;
    background: #f8f9fa;
    border-radius: 8px;
    margin: 20px 0;
}

.empty-cart p {
    font-size: 1.2rem;
    color: #666;
    margin-bottom: 20px;
}

/* Cart table container */
.cart-table-container {
    overflow-x: auto;
    margin: 20px auto;
    max-width: 1200px;
    padding: 0 20px;
}

.cart-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border-radius: 8px;
    overflow: hidden;
}

.cart-table th {
    background: #007bff;
    color: white;
    padding: 15px;
    text-align: left;
    font-weight: 600;
}

.cart-table th:first-child, .cart-table td:first-child {
    text-align: center;
}

/* Enlarge selection checkboxes */
#select-all,
.select-item {
    transform: scale(1.35);
    transform-origin: center center;
    cursor: pointer;
    accent-color: #007bff;
}

@media (max-width: 768px) {
    .cart-table-container { padding: 0 15px; }
    #select-all, .select-item { transform: scale(1.5); }
}

.cart-table td {
    padding: 15px;
    border-bottom: 1px solid #eee;
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
    color: #333;
}

.stock-info {
    margin: 0;
    font-size: 0.9rem;
    color: #666;
}

/* Price and totals */
.price, .item-total {
    font-weight: 600;
    color: #333;
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
}

/* Buttons */
.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    font-size: 1rem;
    text-align: center;
    transition: all 0.3s ease;
}

.btn-primary {
    background: #007bff;
    color: white;
}

.btn-primary:hover {
    background: #0056b3;
}

.btn-remove {
    background: #dc3545;
    color: #ffffff;
    border: none;
    border-radius: 50%;
    width: 36px;
    height: 36px;
    font-size: 18px;
    font-weight: 800;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
}

.btn-remove:hover {
    background: #c82333;
    transform: scale(1.06);
    box-shadow: 0 2px 8px rgba(220, 53, 69, 0.35);
}

.btn-update {
    background: #28a745;
    color: white;
}

.btn-update:hover {
    background: #218838;
}

.btn-continue {
    background: #6c757d;
    color: white;
}

.btn-continue:hover {
    background: #5a6268;
}

.btn-checkout {
    background: #fd7e14;
    color: white;
}

.btn-checkout:hover {
    background: #e8610e;
}

/* Cart actions */
.cart-actions {
    display: flex;
    gap: 15px;
    justify-content: center;
    margin: 30px 0;
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

<!-- Simple remove confirmation modal -->
<div id="removeModal" class="remove-modal" style="display:none;">
    <div class="remove-modal-content">
        <h3>Remove item?</h3>
        <p>Do you want to remove this product from your cart?</p>
        <div class="remove-modal-actions">
            <button id="removeCancel" class="btn btn-secondary">Cancel</button>
            <a id="removeConfirmLink" href="#" class="btn btn-remove" style="width:auto;height:auto;border-radius:4px;">Remove</a>
        </div>
    </div>
    <div class="remove-modal-backdrop"></div>
    <style>
    .remove-modal { position: fixed; inset: 0; z-index: 10000; }
    .remove-modal-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.5); }
    .remove-modal-content { position: relative; z-index: 10001; max-width: 400px; margin: 15vh auto; background: #fff; color: #130325; border-radius: 8px; padding: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.25); }
    .remove-modal-content h3 { margin: 0 0 10px 0; }
    .remove-modal-content p { margin: 0 0 16px 0; color: #444; }
    .remove-modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
    .btn.btn-secondary { background: #6c757d; color: #fff; }
    .btn.btn-secondary:hover { background: #5a6268; }
    </style>
</div>

<script>
// CSS modal confirm for Remove
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('removeModal');
    const cancelBtn = document.getElementById('removeCancel');
    const confirmLink = document.getElementById('removeConfirmLink');
    let pendingHref = null;

    function openModal(href) {
        pendingHref = href;
        confirmLink.setAttribute('href', href);
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }
    function closeModal() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        pendingHref = null;
    }

    cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-modal-backdrop')) closeModal();
    });

    document.querySelectorAll('.btn.btn-remove[title="Remove item"]').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const href = this.getAttribute('href');
            openModal(href);
        });
    });

    // Select-all behavior
    const selectAll = document.getElementById('select-all');
    const itemChecks = document.querySelectorAll('.select-item');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            itemChecks.forEach(cb => { cb.checked = selectAll.checked; });
        });
        itemChecks.forEach(cb => {
            cb.addEventListener('change', function() {
                const allChecked = Array.from(itemChecks).every(x => x.checked);
                const noneChecked = Array.from(itemChecks).every(x => !x.checked);
                selectAll.indeterminate = !allChecked && !noneChecked;
                if (allChecked) selectAll.checked = true;
                if (noneChecked) selectAll.checked = false;
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
    const itemTotals = document.querySelectorAll('[id^="total-"]');
    
    itemTotals.forEach(function(element) {
        const value = parseFloat(element.textContent);
        total += value;
    });
    
    document.getElementById('cart-total').textContent = total.toFixed(2);
}


// Automatically update cart before proceeding to checkout
document.addEventListener('DOMContentLoaded', function() {
    const checkoutBtn = document.getElementById('proceed-checkout-btn');
    if (checkoutBtn) {
        checkoutBtn.addEventListener('click', function(e) {
            const form = document.getElementById('cart-form');
            // Submit cart update first
            const formData = new FormData(form);
            formData.append('update_cart', '1');
            fetch('cart.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.ok ? response.text() : Promise.reject())
            .then(() => {
                // After update, redirect to multi-seller checkout
                window.location.href = 'multi-seller-checkout.php';
            })
            .catch(() => {
                // Fallback: still redirect if update fails
                window.location.href = 'multi-seller-checkout.php';
            });
        });
    }
});
</script>

<?php  ?>