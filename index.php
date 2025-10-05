<?php 
require_once 'includes/header.php';
require_once 'config/database.php';
require_once 'includes/functions.php';
// this is index.php
// Get featured products
$stmt = $pdo->prepare("SELECT 
                          p.id,
                          p.name,
                          p.price,
                          p.image_url,
                          COALESCE(AVG(r.rating), 0) as rating,
                          COUNT(DISTINCT r.id) as review_count,
                          COALESCE(SUM(oi.quantity), 0) as total_sold
                      FROM products p
                      LEFT JOIN reviews r ON p.id = r.product_id
                      LEFT JOIN order_items oi ON p.id = oi.product_id
                      LEFT JOIN orders o ON oi.order_id = o.id
                      WHERE p.status = 'active' 
                      AND (o.status NOT IN ('cancelled', 'refunded') OR o.status IS NULL)
                      GROUP BY p.id, p.name, p.price, p.image_url
                      ORDER BY total_sold DESC, p.created_at DESC
                      LIMIT 5");
$stmt->execute();
$featuredProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php
// Helper to resolve product image path robustly
function resolveProductImageUrl($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) return $url;
    if (strpos($url, 'assets/') === 0) return str_replace(' ', '%20', $url);
    // Assume bare filename stored, serve from uploads
    return 'assets/uploads/' . str_replace(' ', '%20', $url);
}
?>

<section class="featured-products">
    <div class="container">
        <h2 style="margin-top: 60px; margin-bottom: 40px; color: #F9F9F9; text-transform: uppercase;">Featured Products</h2>
    <div class="products-grid">
        <?php 
        $isLoggedIn = isset($_SESSION['user_id']);
        foreach ($featuredProducts as $product): ?>
            <div class="product-card">
                <div class="product-checkbox">
                    <input type="checkbox"
                           class="compare-checkbox"
                           data-product-id="<?php echo $product['id']; ?>"
                           data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                           data-product-price="<?php echo $product['price']; ?>"
                           data-product-image="<?php echo htmlspecialchars($featImg); ?>"
                           data-product-rating="<?php echo $product['rating']; ?>"
                           data-product-reviews="<?php echo $product['review_count']; ?>"
                           onchange="toggleCompare(this)">
                    <label>Compare</label>
                </div>
                <?php $featImg = resolveProductImageUrl($product['image_url'] ?? ''); ?>
                <img loading="lazy" src="<?php echo htmlspecialchars($featImg); ?>" 
                     alt="<?php echo htmlspecialchars($product['name']); ?>">
                <h3 class="product-name"><?php echo htmlspecialchars($product['name']); ?></h3>
                <p class="price">₱<?php echo number_format($product['price'], 2); ?></p>
                <p class="rating">
                    Rating: <?php echo number_format($product['rating'], 1); ?> 
                    (<?php echo $product['review_count']; ?> reviews)
                </p>
                <div class="product-actions">
                    <a href="product-detail.php?id=<?php echo $product['id']; ?>" 
                       class="view-details-link">View Details</a>
                    <button onclick="handleAddToCart(<?php echo $product['id']; ?>, 1)" 
                            class="btn btn-cart" 
                            data-product-id="<?php echo $product['id']; ?>">
                        <i class="fas fa-shopping-cart"></i> Add to Cart
                    </button>
                    <button onclick="handleBuyNow(<?php echo $product['id']; ?>, 1)" 
                            class="btn btn-buy"
                            data-buy-product-id="<?php echo $product['id']; ?>">
                        Buy Now
                    </button>
                </div>
            </div>
<script>
function handleAddToCart(productId, quantity = 1) {
    var isLoggedIn = <?php echo json_encode($isLoggedIn); ?>;
    if (!isLoggedIn) {
        window.location.href = 'login.php';
        return;
    }
    addToCart(productId, quantity);
}

function handleBuyNow(productId, quantity = 1) {
    var isLoggedIn = <?php echo json_encode($isLoggedIn); ?>;
    if (!isLoggedIn) {
        window.location.href = 'login.php';
        return;
    }
    buyNow(productId, quantity);
}

// Compare functionality (copied behavior from products.php)
let compareProducts = [];
const maxCompare = 4;
function toggleCompare(checkbox) {
    const productId = checkbox.dataset.productId;
    const productName = checkbox.dataset.productName;
    const productPrice = checkbox.dataset.productPrice;
    const productImage = checkbox.dataset.productImage;
    const productRating = checkbox.dataset.productRating || '0';
    const productReviews = checkbox.dataset.productReviews || '0';
    if (checkbox.checked) {
        if (compareProducts.length >= maxCompare) {
            checkbox.checked = false;
            alert(`You can only compare up to ${maxCompare} products at a time.`);
            return;
        }
        if (compareProducts.some(p => p.id === productId)) {
            checkbox.checked = false;
            return;
        }
        compareProducts.push({ id: productId, name: productName, price: productPrice, image: productImage, rating: productRating, reviews: productReviews });
    } else {
        compareProducts = compareProducts.filter(p => p.id !== productId);
    }
    updateCompareBar();
}

function updateCompareBar() {
    const compareBar = document.getElementById('compare-bar');
    const compareCount = document.getElementById('compare-count');
    const compareItems = document.getElementById('compare-items');
    const compareBtn = document.getElementById('compare-btn');
    if (!compareBar || !compareCount || !compareItems || !compareBtn) return;
    compareCount.textContent = compareProducts.length;
    if (compareProducts.length > 0) {
        compareBar.style.display = 'block';
        compareBtn.disabled = compareProducts.length < 2;
        compareItems.innerHTML = compareProducts.map(product => `
            <div class="compare-item">
                <img src="${product.image || 'default-image.jpg'}" alt="${product.name}" onerror="this.src='default-image.jpg'">
                <span title="${product.name}">${product.name}</span>
                <button onclick="removeFromCompare('${product.id}')" class="remove-compare" title="Remove">×</button>
            </div>
        `).join('');
    } else {
        compareBar.style.display = 'none';
    }
}

function removeFromCompare(productId) {
    compareProducts = compareProducts.filter(p => p.id !== productId);
    const checkbox = document.querySelector(`.compare-checkbox[data-product-id="${productId}"]`);
    if (checkbox) checkbox.checked = false;
    updateCompareBar();
}

function clearCompare() {
    compareProducts = [];
    document.querySelectorAll('.compare-checkbox').forEach(cb => cb.checked = false);
    updateCompareBar();
}

function compareSelected() {
    if (compareProducts.length < 2) {
        alert('Please select at least 2 products to compare.');
        return;
    }
    const productIds = compareProducts.map(p => p.id).join(',');
    window.location.href = `compare.php?products=${productIds}`;
}

document.addEventListener('DOMContentLoaded', function() {
    const compareBtn = document.getElementById('compare-btn');
    const clearCompareBtn = document.getElementById('clear-compare');
    if (compareBtn) compareBtn.addEventListener('click', compareSelected);
    if (clearCompareBtn) clearCompareBtn.addEventListener('click', clearCompare);
});
</script>
        <?php endforeach; ?>
    </div>
    </div>
</section>
<!-- Compare Products Bar (copied from products.php) -->
<div id="compare-bar" class="compare-bar" style="display: none;">
    <div class="compare-content">
        <h4>Compare Products (<span id="compare-count">0</span>/4)</h4>
        <div id="compare-items"></div>
        <div class="compare-actions">
            <button id="compare-btn" class="btn btn-compare" disabled>Compare Selected</button>
            <button id="clear-compare" class="btn btn-clear">Clear All</button>
        </div>
    </div>
    </div>
<section class="all-products">
    <div class="container">
        <h2 style="margin-top: 60px; margin-bottom: 40px; color: #F9F9F9; text-transform: uppercase;">All Available Products</h2>
        <?php
        // Use the same enhanced search and pagination as products.php for consistency and performance
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $perPage = isset($_GET['per_page']) ? max(1, min(60, intval($_GET['per_page']))) : 24;

        $filters = [
            'in_stock' => true,
            'sort' => 'created_at',
            'order' => 'DESC',
            'page' => $page,
            'per_page' => $perPage
        ];

        if (function_exists('searchProductsWithRatingEnhanced')) {
            $allProducts = searchProductsWithRatingEnhanced('', $filters);
            $totalCount = function_exists('countProductsWithRatingEnhanced') ? countProductsWithRatingEnhanced('', $filters) : count($allProducts);
        } else {
            // Fallback query without pagination
            $stmt = $pdo->prepare("SELECT * FROM products WHERE status = 'active' AND stock_quantity > 0 ORDER BY created_at DESC");
            $stmt->execute();
            $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $totalCount = count($all);
            $offset = ($page - 1) * $perPage;
            $allProducts = array_slice($all, $offset, $perPage);
        }
        $totalPages = max(1, (int)ceil($totalCount / $perPage));

        if (count($allProducts) > 0): ?>
            <div class="products-grid">
                <?php foreach ($allProducts as $product): ?>
                    <div class="product-card">
                        <div class="product-checkbox">
                            <input type="checkbox" 
                                   class="compare-checkbox" 
                                   data-product-id="<?php echo $product['id']; ?>"
                                   data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                                   data-product-price="<?php echo $product['price']; ?>"
                                   data-product-image="<?php echo htmlspecialchars($img); ?>"
                                   data-product-rating="<?php echo $product['rating'] ?? 0; ?>"
                                   data-product-reviews="<?php echo $product['review_count'] ?? 0; ?>"
                                   onchange="toggleCompare(this)">
                            <label>Compare</label>
                        </div>
                        <?php $img = resolveProductImageUrl($product['image_url'] ?? ''); ?>
                        <img loading="lazy" src="<?php echo htmlspecialchars($img); ?>" 
                             alt="<?php echo htmlspecialchars($product['name']); ?>">
                        <h3 class="product-name"><?php echo htmlspecialchars($product['name']); ?></h3>
                        <p class="price">₱<?php echo number_format($product['price'], 2); ?></p>
                        <p class="rating">
                            Rating: <?php echo number_format($product['rating'] ?? 0, 1); ?> 
                            (<?php echo $product['review_count'] ?? 0; ?> reviews)
                        </p>
                        <div class="product-actions">
                            <a href="product-detail.php?id=<?php echo $product['id']; ?>" 
                               class="view-details-link">View Details</a>
                            <button onclick="handleAddToCart(<?php echo $product['id']; ?>, 1)" 
                                    class="btn btn-cart" 
                                    data-product-id="<?php echo $product['id']; ?>">
                                <i class="fas fa-shopping-cart"></i> Add to Cart
                            </button>
                            <button onclick="handleBuyNow(<?php echo $product['id']; ?>, 1)" 
                                    class="btn btn-buy"
                                    data-buy-product-id="<?php echo $product['id']; ?>">
                                Buy Now
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($totalPages > 1): ?>
            <div class="pagination" style="display:flex; gap:8px; justify-content:center; margin: 20px 0;">
                <?php
                $params = $_GET;
                $params['per_page'] = $perPage;
                for ($p = 1; $p <= $totalPages; $p++) {
                    $params['page'] = $p;
                    $url = 'index.php?' . http_build_query($params);
                    $isActive = $p === $page;
                    echo '<a href="' . htmlspecialchars($url) . '" style="padding:8px 12px;border:1px solid #ddd;border-radius:4px;'
                        . ($isActive ? 'background:#FFD736;color:#130325;font-weight:700;' : 'background:#fff;color:#130325;')
                        . '">' . $p . '</a>';
                }
                ?>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <p style="text-align: center; color: #F9F9F9; padding: 40px 0;">
                No products available at the moment.
            </p>
        <?php endif; ?>
    </div>
</section>
<!-- <section class="categories">
    <div class="container">
        <h2>Shop by Category</h2>
    <?php
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE parent_id IS NULL");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="categories-list">
        <?php foreach ($categories as $category): ?>
            <a href="products.php?category=<?php echo $category['id']; ?>" 
               class="category-link">
                <?php echo htmlspecialchars($category['name']); ?>
            </a>
        <?php endforeach; ?>
    </div>
    </div>
</section> -->

<!-- Cart notification -->
<div id="cart-notification" class="cart-notification" style="display: none;">
    <span id="notification-message"></span>
</div>

<!-- Buy Now notification -->
<div id="buy-now-notification" class="buy-now-notification" style="display: none;">
    <span id="buy-now-message"></span>
</div>

<script>
// Add to cart function (unchanged)
function addToCart(productId, quantity = 1) {
    console.log('Adding to cart:', productId, quantity); // Debug log
    
    // Show loading state
    const button = document.querySelector(`button[data-product-id="${productId}"]`);
    if (!button) {
        console.error('Cart button not found for product:', productId);
        return;
    }
    
    const originalText = button.textContent;
    button.textContent = 'Adding...';
    button.disabled = true;
    
    // Make AJAX request to add item to cart
    fetch('ajax/cart-handler.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            product_id: productId,
            quantity: quantity
        })
    })
    .then(response => {
        console.log('Cart response status:', response.status); // Debug log
        return response.json();
    })
    .then(data => {
        console.log('Cart response data:', data); // Debug log
        
        if (data.success) {
            // Show success notification
            showNotification('Product added to cart!', 'success');
            
            // Update cart count if you have a cart counter in header
            if (typeof updateCartCount === 'function') {
                updateCartCount(data.cartCount);
            }
            
            // Temporarily change button text
            button.textContent = '✓ Added';
            setTimeout(() => {
                button.textContent = originalText;
                button.disabled = false;
            }, 2000);
        } else {
            // Show error notification
            showNotification(data.message || 'Error adding to cart', 'error');
            button.textContent = originalText;
            button.disabled = false;
        }
    })
    .catch(error => {
        console.error('Cart Error:', error);
        showNotification('Error adding to cart', 'error');
        button.textContent = originalText;
        button.disabled = false;
    });
}

// Improved Buy Now function with better error handling
function buyNow(productId, quantity = 1) {
    console.log('Buy Now clicked:', productId, quantity); // Debug log
    
    // Validate inputs
    if (!productId || productId <= 0) {
        console.error('Invalid product ID:', productId);
        showBuyNowNotification('Invalid product selected', 'error');
        return;
    }
    
    if (!quantity || quantity <= 0) {
        console.error('Invalid quantity:', quantity);
        showBuyNowNotification('Invalid quantity specified', 'error');
        return;
    }
    
    // Show loading state
    const button = document.querySelector(`button[data-buy-product-id="${productId}"]`);
    if (!button) {
        console.error('Buy Now button not found for product:', productId);
        showBuyNowNotification('Button not found', 'error');
        return;
    }
    
    const originalText = button.textContent;
    button.textContent = 'Processing...';
    button.disabled = true;
    
    console.log('Sending buy now request...'); // Debug log
    
    // Make AJAX request to buy now handler
    fetch('ajax/buy-now.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            product_id: parseInt(productId),
            quantity: parseInt(quantity)
        })
    })
    .then(response => {
        console.log('Buy Now response status:', response.status); // Debug log
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        return response.text().then(text => {
            console.log('Raw response:', text); // Debug log
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('JSON parse error:', e, 'Raw text:', text);
                throw new Error('Invalid JSON response');
            }
        });
    })
    .then(data => {
        console.log('Buy Now response data:', data); // Debug log
        
        if (data.success) {
            // Show buy now notification
            showBuyNowNotification('Redirecting to checkout...', 'success');
            
            // Short delay before redirect for user feedback
            setTimeout(() => {
                console.log('Redirecting to:', data.redirect_url); // Debug log
                window.location.href = data.redirect_url;
            }, 1500);
        } else {
            // Show error notification
            const errorMessage = data.message || 'Error processing buy now request';
            console.error('Buy Now failed:', errorMessage);
            showBuyNowNotification(errorMessage, 'error');
            button.textContent = originalText;
            button.disabled = false;
        }
    })
    .catch(error => {
        console.error('Buy Now Error:', error);
        showBuyNowNotification('Error processing request: ' + error.message, 'error');
        button.textContent = originalText;
        button.disabled = false;
    });
}

// Show cart notification function
function showNotification(message, type = 'success') {
    const notification = document.getElementById('cart-notification');
    const messageElement = document.getElementById('notification-message');
    
    if (!notification || !messageElement) {
        console.error('Cart notification elements not found');
        return;
    }
    
    messageElement.textContent = message;
    notification.className = 'cart-notification ' + type;
    notification.style.display = 'block';
    
    // Hide after 3 seconds
    setTimeout(() => {
        notification.style.display = 'none';
    }, 3000);
}

// Show buy now notification function
function showBuyNowNotification(message, type = 'success') {
    const notification = document.getElementById('buy-now-notification');
    const messageElement = document.getElementById('buy-now-message');
    
    if (!notification || !messageElement) {
        console.error('Buy Now notification elements not found');
        return;
    }
    
    messageElement.textContent = message;
    notification.className = 'buy-now-notification ' + type;
    notification.style.display = 'block';
    
    // Hide after 4 seconds (unless it's a success redirect)
    if (type !== 'success') {
        setTimeout(() => {
            notification.style.display = 'none';
        }, 4000);
    }
}

// Update cart count in header (if you have a cart counter)
function updateCartCount(count) {
    const cartCounter = document.querySelector('.cart-count');
    if (cartCounter) {
        cartCounter.textContent = count;
        
        // Add a little animation to draw attention
        cartCounter.style.transform = 'scale(1.2)';
        setTimeout(() => {
            cartCounter.style.transform = 'scale(1)';
        }, 200);
    }
}

// Function to get current cart count (useful for page load)
function loadCartCount() {
    fetch('ajax/cart-handler.php?action=get_count')
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateCartCount(data.count);
        }
    })
    .catch(error => {
        console.error('Error loading cart count:', error);
    });
}

// Load cart count when page loads
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing...'); // Debug log
    loadCartCount();
    
    // Test if buy now buttons exist
    const buyNowButtons = document.querySelectorAll('[data-buy-product-id]');
    console.log('Found buy now buttons:', buyNowButtons.length); // Debug log
});
</script>

<style>
/* Body background */
body {
    background: linear-gradient(135deg, #1a0a2e 0%, #16213e 100%);
    min-height: 100vh;
    color: #F9F9F9;
}

/* Text color overrides for better contrast */
h1, h2, h3, h4, h5, h6 {
    color: #F9F9F9;
}

p, span, div {
    color: #F9F9F9;
}

/* Product grid styles */
.products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.product-card {
    position: relative;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 15px;
    text-align: center;
    transition: transform 0.2s, box-shadow 0.2s;
    background: #f8f9fa;
    color: #333;
}

.product-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.view-details-link {
    background-color: #007bff;
    color: #F9F9F9;
    text-decoration: none;
    font-size: 0.9em;
    padding: 8px 16px;
    border-radius: 4px;
    display: block;
    text-align: center;
    margin-bottom: 10px;
    transition: background-color 0.3s, transform 0.2s;
    font-weight: 500;
}

.view-details-link:hover {
    background-color: #0056b3;
    transform: translateY(-2px);
    text-decoration: none;
}

.product-name {
    margin: 10px 0;
    font-size: 1.3em;
    font-weight: bold;
    color: #130325;
}

.price {
    font-weight: bold;
    color: #130325;
    font-size: 1.1em;
    margin: 8px 0;
}

.rating {
    color: #666;
    font-size: 0.9em;
    letter-spacing: -2px;
}

/* Product action buttons */
.product-actions {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 15px;
}

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    transition: background-color 0.3s, transform 0.2s;
    text-decoration: none;
    display: inline-block;
    text-align: center;
}

.view-details-link {
    color: #130325;
    text-decoration: underline;
    font-size: 0.9em;
    text-align: center;
    margin-bottom: 10px;
    transition: color 0.2s;
}

.view-details-link:hover {
    color: #FFD736;
}

.btn-cart {
    background-color: #FFD736;
    color: #130325;
    font-weight: 600;
}

.btn-cart:hover:not(:disabled) {
    background-color: #e6c230;
}

.btn-cart:disabled {
    background-color: #6c757d;
    color: #F9F9F9;
    opacity: 0.6;
    cursor: not-allowed;
}

.btn-buy {
    background-color: #130325;
    color: #F9F9F9;
    font-weight: bold;
}

.btn-buy:hover:not(:disabled) {
    background-color: rgba(19, 3, 37, 0.8);
}

.btn-buy:disabled {
    background-color: #6c757d;
    color: #F9F9F9;
    opacity: 0.6;
    cursor: not-allowed;
}

/* Compare button styling to match products.php */
.btn-compare {
    background-color: #130325;
    color: #F9F9F9;
    font-weight: bold;
    border: 2px solid #FFD736;
}

.btn-compare:hover:not(:disabled) {
    background-color: rgba(19, 3, 37, 0.8);
    border-color: #e6c230;
}

/* Compare Bar Styles (copied from products.php) */
.compare-bar { position: fixed; bottom: 0; left: 0; right: 0; background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.15); z-index: 1000; padding: 20px; border-top: 3px solid #007bff; animation: slideUp 0.3s ease-out; }
@keyframes slideUp { from { transform: translateY(100%); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.compare-content { max-width: 1400px; margin: 0 auto; display: flex; align-items: center; gap: 25px; flex-wrap: wrap; }
.compare-content h4 { margin: 0; color: #2c3e50; font-size: 1.1rem; font-weight: 600; }
#compare-items { display: flex; gap: 15px; flex: 1; flex-wrap: wrap; }
.compare-item { display: flex; align-items: center; gap: 10px; background: rgba(248, 249, 250, 0.9); padding: 12px 16px; border-radius: 25px; font-size: 0.9rem; border: 2px solid #dee2e6; transition: all 0.3s ease; font-weight: 500; }
.compare-item:hover { background: rgba(0, 123, 255, 0.1); border-color: #007bff; transform: translateY(-2px); }
.compare-item img { width: 35px; height: 35px; object-fit: cover; border-radius: 8px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); }
.compare-item span { max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.remove-compare { background: rgba(220, 53, 69, 0.1); border: none; color: #dc3545; font-size: 16px; cursor: pointer; padding: 4px; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; border-radius: 50%; transition: all 0.3s ease; font-weight: bold; }
.remove-compare:hover { background: #dc3545; color: white; transform: rotate(90deg); }
.compare-actions { display: flex; gap: 15px; }

/* Product card compare checkbox (same as products.php) */
.product-checkbox {
    position: absolute;
    top: 15px;
    right: 15px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 8px 12px;
    border-radius: 20px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.85rem;
    font-weight: 500;
    z-index: 10;
    border: 2px solid transparent;
    transition: all 0.3s ease;
}

.product-checkbox:hover {
    background: rgba(255, 255, 255, 1);
    border-color: #007bff;
    transform: scale(1.05);
}

.product-checkbox input[type="checkbox"] {
    accent-color: #007bff;
    transform: scale(1.2);
}

/* Categories section */
/* Categories section - Reduced to 80% */
.categories {
    margin: 16px 0;
}
.categories h2 {
    font-size: 0.96em;
    margin-bottom: 12px;
}
.categories-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6.4px;
    margin-top: 8px;
}
.category-link {
    padding: 4.8px 9.6px;
    background-color: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 9.6px;
    text-decoration: none;
    color: #333;
    transition: background-color 0.3s, transform 0.2s;
    font-size: 0.68em;
}
.category-link:hover {
    background-color: #e9ecef;
    transform: translateY(-1.6px);
}

.buy-now-notification {
    top: 80px; /* Position below cart notification */
}

.cart-notification.success, .buy-now-notification.success {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.cart-notification.error, .buy-now-notification.error {
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

@keyframes slideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

/* Responsive design */
@media (max-width: 768px) {
    .product-card img {
        height: 200px; /* Increased from 150px */
        object-fit: contain;
    }
}
    
.product-card img {
    width: 100%;
    max-width: 200px;
    height: 200px;
    object-fit: cover;
    margin-bottom: 10px;
}
    
    .product-actions {
        font-size: 12px;
    }
    
    .btn {
        padding: 6px 12px;
        font-size: 12px;
    }
    
    .cart-notification, .buy-now-notification {
        right: 10px;
        top: 10px;
        max-width: calc(100vw - 40px);
    }
    
    .buy-now-notification {
        top: 70px;
    }
</style>

<?php require_once 'includes/footer.php'; ?>
<?php require_once 'includes/footer.php'; ?>