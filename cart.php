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
echo '<div style="height:40px"></div>';

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

$groupedCart = getCartItemsGroupedBySeller();
$cartTotal = getMultiSellerCartTotal();
?>

<h1>YOUR CART</h1>

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
                            <td colspan="6" style="background:#130325;font-weight:700;color:#ffffff;">
                                Seller: <?php echo htmlspecialchars($sellerGroup['seller_display_name']); ?>
                                <span style="font-weight:400;color:#cccccc;">(<?php echo $sellerGroup['item_count']; ?> items)</span>
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
                                        onclick="removeCartItem(<?php echo $item['product_id']; ?>)">×</button>
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
    margin: 20px 0 20px 60px;
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
    padding: 15px;
    text-align: left;
    font-weight: 600;
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

@media (max-width: 768px) {
    .cart-table-container { padding: 0 15px; }
    #select-all, .select-item { transform: scale(1.5); }
}

.cart-table td {
    padding: 15px;
    border-bottom: 1px solid #e0e0e0;
    color: #130325;
    background: #ffffff;
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
    background: #ffffff;
    color: #130325;
    border: 2px solid #130325;
}

.btn-primary:hover {
    background: #f0f0f0;
    color: #130325;
}

.btn-remove {
    background: #dc3545;
    color: #ffffff;
    border: none;
    border-radius: 4px;
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

<!-- Simple remove confirmation modal -->
<div id="removeModal" class="remove-modal" style="display:none;">
    <div class="remove-modal-content">
        <h3>Remove item?</h3>
        <p>Do you want to remove this product from your cart?</p>
        <div class="remove-modal-actions">
            <button id="removeCancel" class="btn btn-secondary">Cancel</button>
            <button id="removeConfirm" class="btn btn-remove" style="width:auto;height:auto;border-radius:4px;">Remove</button>
        </div>
    </div>
    <div class="remove-modal-backdrop"></div>
    <style>
    .remove-modal { position: fixed; inset: 0; z-index: 10000; }
    .remove-modal-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.5); }
    .remove-modal-content { position: relative; z-index: 10001; max-width: 400px; margin: 15vh auto; background: #fff; color: #130325; border-radius: 8px; padding: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.25); }
    .remove-modal-content h3 { margin: 0 0 10px 0; font-size: 14px; font-weight: 600; color: #000; }
    .remove-modal-content p { margin: 0 0 16px 0; color: #000; font-size: 12px; font-weight: 500; }
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
    const confirmBtn = document.getElementById('removeConfirm');

    function closeModal() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        window.pendingRemoveProductId = null;
    }

    cancelBtn.addEventListener('click', closeModal);
    confirmBtn.addEventListener('click', confirmRemoveItem);
    
    modal.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-modal-backdrop')) closeModal();
    });
});

// AJAX-based cart item removal
function removeCartItem(productId) {
    // Store the product ID for the modal
    window.pendingRemoveProductId = productId;
    
    // Show the CSS modal
    const modal = document.getElementById('removeModal');
    modal.style.display = 'block';
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
            const form = document.getElementById('cart-form');
            // Gather selected items and append to checkout URL
            const selected = Array.from(document.querySelectorAll('.select-item:checked')).map(cb => cb.value);
            const params = new URLSearchParams();
            if (selected.length) params.set('selected', selected.join(','));
            // Submit quantity updates first to persist changes
            const formData = new FormData(form);
            formData.append('update_cart', '1');
            fetch('cart.php', { method: 'POST', body: formData })
            .finally(() => {
                const qs = params.toString();
                window.location.href = 'paymongo/multi-seller-checkout.php' + (qs ? ('?' + qs) : '');

            });
        });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>