// Enhanced addToCart function with stock validation
function addToCart(productId, quantity = 1) {
    // Show loading state
    const button = document.querySelector(`button[data-product-id="${productId}"]`);
    const originalHTML = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    button.disabled = true;
    
    // Make AJAX request to add item to cart
    fetch('ajax/add-to-cart.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            product_id: productId,
            quantity: quantity
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success notification
            showNotification(data.message, 'success');
            
            // Update cart notification
            if (data.cartCount !== undefined) {
                updateCartNotification(data.cartCount);
            }
            
            // Keep the original cart icon, no text change
            button.innerHTML = originalHTML;
            button.disabled = false;
        } else {
            // Show error notification
            showNotification(data.message || 'Error adding to cart', 'error');
            button.innerHTML = originalHTML;
            button.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error adding to cart', 'error');
        button.innerHTML = originalHTML;
        button.disabled = false;
    });
}

// Load cart count when page loads
document.addEventListener('DOMContentLoaded', function() {
    loadCartCount();
});

// Also load cart count immediately if DOM is already loaded
if (document.readyState === 'loading') {
    // DOM is still loading, wait for DOMContentLoaded
} else {
    // DOM is already loaded, load cart count immediately
    loadCartCount();
}

// Force cart notification update on page visibility change (when user returns to tab)
document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
        loadCartCount();
    }
});

// Force refresh cart notification (can be called from other scripts)
window.refreshCartNotification = function() {
    loadCartCount();
};

// Cart notification system
function updateCartNotification(count) {
    const notifications = document.querySelectorAll('.cart-notification');
    const safeCount = parseInt(count, 10) || 0;
    
    notifications.forEach(notification => {
        notification.textContent = safeCount;
        if (safeCount > 0) {
            notification.classList.add('show');
        } else {
            notification.classList.remove('show');
        }
    });
}

function loadCartCount() {
    // Try fetch first
    fetch('ajax/cart-handler.php?action=get_count')
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        const count = data && data.success ? data.count : 0;
        updateCartNotification(count);
    })
    .catch(error => {
        console.warn('Cart count fetch failed (likely antivirus interference):', error.message);
        // Fallback: try XMLHttpRequest
        tryXMLHttpRequest();
    });
}

function tryXMLHttpRequest() {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', 'ajax/cart-handler.php?action=get_count', true);
    xhr.timeout = 5000; // 5 second timeout
    
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    const data = JSON.parse(xhr.responseText);
                    const count = data && data.success ? data.count : 0;
                    updateCartNotification(count);
                } catch (e) {
                    console.warn('Failed to parse cart count response');
                    updateCartNotification(0);
                }
            } else {
                console.warn('XMLHttpRequest failed, setting cart count to 0');
                updateCartNotification(0);
            }
        }
    };
    
    xhr.ontimeout = function() {
        console.warn('Cart count request timed out');
        updateCartNotification(0);
    };
    
    xhr.onerror = function() {
        console.warn('XMLHttpRequest error, setting cart count to 0');
        updateCartNotification(0);
    };
    
    xhr.send();
}

// Updated Buy now function - creates separate buy now session
function buyNow(productId, quantity = 1) {
    // Show loading state
    const button = document.querySelector(`button[onclick*="buyNow(${productId})"]`);
    let originalText = 'Buy Now';
    if (button) {
        originalText = button.textContent;
        button.textContent = 'Processing...';
        button.disabled = true;
    }
    
    // Make AJAX request to prepare buy now item
    fetch('ajax/buy-now.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            product_id: productId,
            quantity: quantity
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Redirect to checkout with buy now parameter
            window.location.href = 'paymongo/multi-seller-checkout.php?buy_now=1';
        } else {
            // Show error notification
            showNotification(data.message || 'Error processing request', 'error');
            if (button) {
                button.textContent = originalText;
                button.disabled = false;
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error processing request', 'error');
        if (button) {
            button.textContent = originalText;
            button.disabled = false;
        }
    });
}
function addToCartAndCheckout(productId, quantity = 1) {
    fetch('ajax/add-to-cart.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            product_id: productId,
            quantity: quantity
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Redirect to regular cart checkout
            window.location.href = 'paymongo/multi-seller-checkout.php';
        } else {
            showNotification(data.message || 'Error processing request', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error processing request', 'error');
    });
}
// Enhanced updateCartQuantity function with stock validation
function updateCartQuantity(productId, quantity) {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'cart-ajax.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    // Update cart total
                    const cartTotal = document.getElementById('cart-total');
                    if (cartTotal) {
                        cartTotal.textContent = '$' + response.total;
                    }
                    
                    // Update stock message if exists
                    const stockMessage = document.getElementById('stock-message-' + productId);
                    if (stockMessage) {
                        updateStockMessage(productId, quantity);
                    }
                    
                    loadCartCount();
                    showNotification(response.message, 'success');
                } else {
                    showNotification(response.message, 'error');
                    // Reload the page to sync with server if there's a stock issue
                    if (response.message.includes('available')) {
                        setTimeout(() => { location.reload(); }, 2000);
                    }
                }
            } catch (e) {
                console.error('Error updating cart quantity', e);
                showNotification('Error updating cart. Please try again.', 'error');
            }
        }
    };
    xhr.send('action=update_quantity&product_id=' + productId + '&quantity=' + quantity);
}

// Enhanced removeCartItem function
function removeCartItem(productId) {
    openConfirm('Are you sure you want to remove this item from your cart?', function() {
        // proceed with removal
        // original caller should continue after this modal confirms
    });
    return;
    
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'cart-ajax.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    // Update UI
                    const cartTotal = document.getElementById('cart-total');
                    if (cartTotal) {
                        cartTotal.textContent = '$' + response.total;
                    }
                    
                    const itemRow = document.getElementById('cart-item-' + productId);
                    if (itemRow) {
                        itemRow.remove();
                    }
                    
                    loadCartCount();
                    showNotification(response.message, 'success');
                    
                    // Check if cart is empty
                    if (response.count === 0) {
                        document.querySelector('.cart-table').innerHTML = '<p>Your cart is empty.</p>';
                    }
                } else {
                    showNotification(response.message, 'error');
                }
            } catch (e) {
                console.error('Error removing cart item', e);
                showNotification('Error removing item. Please try again.', 'error');
            }
        }
    };
    xhr.send('action=remove_item&product_id=' + productId);
}

// Function to validate cart before checkout
function validateCartBeforeCheckout() {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', 'cart-ajax.php?action=validate_cart', true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                const response = JSON.parse(xhr.responseText);
                if (!response.success) {
                    showNotification(response.message, 'error');
                    // Prevent form submission if cart is invalid
                    const checkoutForm = document.getElementById('checkout-form');
                    if (checkoutForm) {
                        checkoutForm.addEventListener('submit', function(e) {
                            e.preventDefault();
                            showNotification('Please fix cart issues before checkout.', 'error');
                        });
                    }
                }
            } catch (e) {
                console.error('Error validating cart', e);
            }
        }
    };
    xhr.send();
}

// Function to show notifications
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

// Function to update stock message for a product
function updateStockMessage(productId, quantity) {
    const stockMessage = document.getElementById('stock-message-' + productId);
    if (!stockMessage) return;
    
    const maxStock = parseInt(stockMessage.getAttribute('data-max-stock'));
    if (quantity > maxStock) {
        stockMessage.style.display = 'block';
        stockMessage.textContent = `Only ${maxStock} available. You cannot add more than this.`;
        stockMessage.style.color = '#F44336';
    } else {
        stockMessage.style.display = 'none';
    }
}

/* ============================
   Rating Filter Functionality
   ============================ */

// Toggle rating filter via checkbox
function toggleRatingFilter(checkbox) {
    const form = checkbox.closest('form');
    const minRatingSelect = form.querySelector('select[name="min_rating_select"]');
    
    if (checkbox.checked) {
        minRatingSelect.value = checkbox.value;
    } else {
        minRatingSelect.value = "0";
    }
    
    form.submit();
}

// Update rating filter via select
function updateRatingFilter(select) {
    const form = select.closest('form');
    const checkboxes = form.querySelectorAll('input[name="min_rating"]');
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    
    if (select.value > 0) {
        const checkbox = form.querySelector(`input[name="min_rating"][value="${select.value}"]`);
        if (checkbox) {
            checkbox.checked = true;
        }
    }
    
    form.submit();
}

// Initialize rating filters
function initRatingFilters() {
    const ratingCheckboxes = document.querySelectorAll('input[name="min_rating"]');
    const ratingSelect = document.querySelector('select[name="min_rating_select"]');
    
    ratingCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            if (this.checked) {
                ratingSelect.value = this.value;
            }
        });
    });
    
    if (ratingSelect) {
        ratingSelect.addEventListener('change', function() {
            ratingCheckboxes.forEach(checkbox => {
                checkbox.checked = (checkbox.value === this.value);
            });
        });
    }
}

/* ============================
   Document Ready Initializers
   ============================ */
document.addEventListener('DOMContentLoaded', function() {
    loadCartCount();
    
    // Cart: Add to Cart buttons
    const addToCartButtons = document.querySelectorAll('.add-to-cart-btn');
    addToCartButtons.forEach(button => {
        button.addEventListener('click', function() {
            const productId = this.getAttribute('data-product-id');
            const quantityInput = document.getElementById('quantity-' + productId);
            const quantity = quantityInput ? parseInt(quantityInput.value) : 1;
            addToCart(productId, quantity);
        });
    });
    
    // Cart: Quantity inputs
    const quantityInputs = document.querySelectorAll('.cart-quantity');
    quantityInputs.forEach(input => {
        input.addEventListener('change', function() {
            const productId = this.getAttribute('data-product-id');
            const quantity = this.value;
            updateCartQuantity(productId, quantity);
        });
    });
    
    // Cart: Remove buttons
    const removeButtons = document.querySelectorAll('.remove-from-cart');
    removeButtons.forEach(button => {
        button.addEventListener('click', function() {
            const productId = this.getAttribute('data-product-id');
            removeCartItem(productId);
        });
    });
    
    // Validate cart before checkout
    if (document.getElementById('checkout-form')) {
        validateCartBeforeCheckout();
    }
    
    // Init rating filters
    initRatingFilters();
    
    // Clear filters button
    const clearFiltersBtn = document.querySelector('.clear-filters');
    if (clearFiltersBtn) {
        clearFiltersBtn.addEventListener('click', function(e) {
            e.preventDefault();
            window.location.href = 'products.php';
        });
    }
});
