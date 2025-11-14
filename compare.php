<?php
require_once 'includes/header.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

// Get product IDs from URL parameter
$productIds = isset($_GET['products']) ? $_GET['products'] : '';
if (empty($productIds)) {
    header('Location: products.php');
    exit();
}

// Clean and validate product IDs
$productIdArray = array_filter(array_map('intval', explode(',', $productIds)));
if (empty($productIdArray) || count($productIdArray) < 2 || count($productIdArray) > 4) {
    header('Location: products.php?error=invalid_comparison');
    exit();
}

// Fetch products with their details, ratings, and reviews
$placeholders = str_repeat('?,', count($productIdArray) - 1) . '?';
$sql = "SELECT p.*, c.name as category_name,
               COALESCE(AVG(r.rating), 0) as rating,
               COUNT(r.id) as review_count
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN reviews r ON p.id = r.product_id
        WHERE p.id IN ($placeholders)
        GROUP BY p.id
        ORDER BY FIELD(p.id, " . implode(',', $productIdArray) . ")";

$stmt = $pdo->prepare($sql);
$stmt->execute($productIdArray);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If we don't have enough products, redirect back
if (count($products) < 2) {
    header('Location: products.php?error=products_not_found');
    exit();
}

// Get recent reviews for each product separately
$recentReviews = [];
foreach ($productIdArray as $productId) {
    $reviewSql = "SELECT rating, comment, created_at, 
                         COALESCE(user_id, 'Anonymous') as reviewer
                  FROM reviews 
                  WHERE product_id = ? 
                  ORDER BY created_at DESC 
                  LIMIT 3";
    $reviewStmt = $pdo->prepare($reviewSql);
    $reviewStmt->execute([$productId]);
    $recentReviews[$productId] = $reviewStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get product features/specifications for comparison
$featuresSql = "SELECT product_id, feature_name, feature_value 
                FROM product_features 
                WHERE product_id IN ($placeholders)
                ORDER BY product_id, feature_name";
$featuresStmt = $pdo->prepare($featuresSql);
$featuresStmt->execute($productIdArray);
$allFeatures = $featuresStmt->fetchAll(PDO::FETCH_ASSOC);

// Organize features by product
$productFeatures = [];
$allFeatureNames = [];
foreach ($allFeatures as $feature) {
    $productFeatures[$feature['product_id']][$feature['feature_name']] = $feature['feature_value'];
    $allFeatureNames[] = $feature['feature_name'];
}
$allFeatureNames = array_unique($allFeatureNames);

// Find best values
$minPrice = min(array_column($products, 'price'));
$maxRating = max(array_column($products, 'rating'));

function statusBadge($status, $bg, $fg) {
    return '<span class="badge" style="background:'.$bg.';color:'.$fg.';padding:4px 8px;border-radius:12px;font-weight:600;">'.htmlspecialchars($status).'</span>';
}
?>

<style>
  html, body { background:#f8f9fa !important; margin:0; padding:0; }
  
  /* Fix cart badge size - more aggressive override */
  .cart-notification {
    width: 20px !important;
    height: 20px !important;
    font-size: 12px !important;
    min-width: 20px !important;
    max-width: 20px !important;
    line-height: 1 !important;
    padding: 0 !important;
    margin: 0 !important;
    box-sizing: border-box !important;
  }
  
  /* Also target the mobile version */
  #cart-notification-mobile {
    width: 20px !important;
    height: 20px !important;
    font-size: 12px !important;
    min-width: 20px !important;
    max-width: 20px !important;
    line-height: 1 !important;
    padding: 0 !important;
    margin: 0 !important;
    box-sizing: border-box !important;
  }
  
  /* Target all possible cart badge selectors */
  .nav-links .cart-notification,
  .nav-links #cart-notification,
  .nav-links #cart-notification-mobile,
  .cart-notification,
  #cart-notification,
  #cart-notification-mobile {
    width: 20px !important;
    height: 20px !important;
    font-size: 12px !important;
    min-width: 20px !important;
    max-width: 20px !important;
    line-height: 1 !important;
    padding: 0 !important;
    margin: 0 !important;
    box-sizing: border-box !important;
    border-radius: 50% !important;
  }
</style>

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

.compare-container {
    max-width: 1600px;
    margin: 0 auto;
    padding: 12px 20px;
}

.compare-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.back-arrow {
    color: var(--bg-white);
    text-decoration: none;
    font-size: 16px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    padding: 6px 8px;
    border-radius: 8px;
    width: 32px;
    height: 32px;
    background: var(--primary-dark);
    border: 1px solid var(--primary-dark);
}

.back-arrow:hover {
    color: var(--bg-white);
    background: #0a0118;
    border-color: #0a0118;
    transform: translateX(-2px);
    box-shadow: 0 2px 6px rgba(19, 3, 37, 0.3);
}

.back-arrow i {
    margin: 0;
}

.compare-header h1 {
    color: var(--text-dark);
    margin: 0;
    font-size: 1.35rem;
    font-weight: 600;
    letter-spacing: -0.3px;
}

.compare-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
}

.compare-card {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    padding: 14px;
    position: relative;
    display: flex;
    flex-direction: column;
    min-height: 500px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    transition: all 0.2s ease;
}

.compare-card:hover {
    border-color: var(--primary-dark);
    box-shadow: 0 4px 12px rgba(19, 3, 37, 0.15);
    transform: translateY(-2px);
}

.compare-card.best {
    border: 2px solid var(--primary-dark);
    box-shadow: 0 4px 16px rgba(19, 3, 37, 0.2);
}

.best-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    background: var(--primary-dark);
    color: var(--bg-white);
    padding: 4px 10px;
    border-radius: 999px;
    border: 1px solid var(--primary-dark);
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    z-index: 2;
}

.compare-image {
    text-align: center;
    margin-bottom: 12px;
}

.compare-image img {
    width: 140px;
    height: 140px;
    object-fit: cover;
    border-radius: 8px;
    border: 1px solid var(--border-light);
}

.compare-name {
    color: var(--text-dark);
    margin: 0 0 10px 0;
    text-align: center;
    font-size: 14px;
    font-weight: 600;
    line-height: 1.4;
}

.compare-name a {
    text-decoration: none;
    color: var(--text-dark);
}

.compare-name a:hover {
    color: var(--primary-dark);
}

.compare-price {
    text-align: center;
    margin-bottom: 10px;
    color: var(--primary-dark);
    font-weight: 700;
    font-size: 18px;
}

.compare-rating {
    background: rgba(19, 3, 37, 0.04);
    padding: 8px;
    border-radius: 6px;
    margin-bottom: 10px;
    text-align: center;
    border: 1px solid rgba(19, 3, 37, 0.1);
}

.compare-rating-stars {
    color: var(--accent-yellow);
    margin-bottom: 4px;
    font-size: 14px;
}

.compare-rating-value {
    color: var(--text-dark);
    font-weight: 600;
    font-size: 13px;
}

.compare-rating-count {
    color: var(--text-light);
    opacity: 0.7;
    font-size: 11px;
}

.compare-stock {
    text-align: center;
    margin-bottom: 10px;
}

.compare-category {
    text-align: center;
    margin-bottom: 10px;
}

.compare-category span {
    background: var(--bg-light);
    color: var(--text-dark);
    padding: 4px 10px;
    border-radius: 999px;
    font-weight: 600;
    border: 1px solid var(--border-light);
    font-size: 11px;
}

.compare-description {
    background: var(--bg-light);
    padding: 10px;
    border-radius: 6px;
    margin-bottom: 10px;
    border: 1px solid var(--border-light);
}

.compare-description-title {
    color: var(--text-dark);
    margin-bottom: 6px;
    font-weight: 600;
    font-size: 12px;
}

.compare-description-text {
    color: var(--text-light);
    line-height: 1.5;
    font-size: 11px;
}

.compare-specs {
    background: var(--bg-light);
    padding: 10px;
    border-radius: 6px;
    margin-bottom: 10px;
    border: 1px solid var(--border-light);
}

.compare-specs-title {
    color: var(--text-dark);
    margin-bottom: 8px;
    font-weight: 600;
    font-size: 12px;
}

.compare-spec-item {
    display: flex;
    justify-content: space-between;
    padding: 4px 0;
    border-bottom: 1px solid rgba(19, 3, 37, 0.1);
    font-size: 11px;
}

.compare-spec-item:last-child {
    border-bottom: none;
}

.compare-spec-label {
    color: var(--text-light);
    opacity: 0.8;
}

.compare-spec-value {
    color: var(--text-dark);
    font-weight: 600;
}

.compare-actions {
    display: flex;
    flex-direction: row;
    gap: 8px;
    align-items: center;
    margin-top: auto;
}

/* Add to Cart Button - Small Icon Button, Yellow (LEFT SIDE) */
.compare-btn-cart {
    background: var(--accent-yellow);
    color: var(--primary-dark);
    border: 1px solid var(--accent-yellow);
    border-radius: 6px;
    padding: 6px;
    cursor: pointer;
    font-size: 10px;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.compare-btn-cart:hover {
    background: #ffd020;
    border-color: #ffd020;
    color: var(--primary-dark);
    transform: translateY(-1px);
}

/* View Details Button - Large, Dark Purple (RIGHT SIDE) */
.compare-btn {
    background: var(--primary-dark);
    border: 1px solid var(--primary-dark);
    color: var(--bg-white);
    padding: 6px 10px;
    border-radius: 6px;
    text-decoration: none;
    text-align: center;
    font-weight: 600;
    font-size: 12px;
    transition: all 0.2s ease;
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    line-height: 1.2;
    min-height: 28px;
}

.compare-btn:hover {
    background: #0a0118;
    border-color: #0a0118;
    transform: translateY(-1px);
    color: var(--bg-white);
    text-decoration: none;
}

.compare-btn i {
    font-size: 10px;
}

.compare-empty {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    color: var(--text-light);
    border-radius: 12px;
    padding: 40px 20px;
    text-align: center;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

@media (max-width: 968px) {
    .compare-container {
        padding: 10px 12px;
        max-width: 100%;
    }
    
    .compare-header h1 {
        font-size: 1.2rem;
    }
    
    .compare-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    
    .compare-card {
        min-height: auto;
        padding: 12px;
    }
}
</style>

<main style="background: var(--bg-light); min-height: 100vh; padding: 10px 0 60px 0;">
  <div class="compare-container">
    
    <!-- Header with back arrow and title -->
    <div class="compare-header">
      <a href="products.php" class="back-arrow" title="Back to Products">
        <i class="fas fa-arrow-left"></i>
      </a>
      <h1>Compare Products</h1>
    </div>

    <div class="compare-grid">
      <?php foreach ($products as $product): 
        $isBestPrice = ($product['price'] == $minPrice);
        $rating = floatval($product['rating']);
        $isBestRating = ($rating == $maxRating && $rating > 0);
        $fullStars = floor($rating);
        $hasHalfStar = ($rating - $fullStars) >= 0.5;
        $emptyStars = 5 - $fullStars - ($hasHalfStar ? 1 : 0);
        $stock = intval($product['stock_quantity'] ?? 0);
      ?>
        <div class="compare-card <?php echo ($isBestPrice || $isBestRating) ? 'best' : ''; ?>">
          
          <?php if ($isBestPrice || $isBestRating): ?>
            <div class="best-badge">
              <i class="fas fa-star" style="margin-right:3px;"></i>
              <?php if ($isBestPrice) echo 'Best Price'; ?>
              <?php if ($isBestPrice && $isBestRating) echo ' & '; ?>
              <?php if ($isBestRating) echo 'Top Rated'; ?>
            </div>
          <?php endif; ?>
 
          <!-- Product Image -->
          <div class="compare-image">
            <img src="<?php echo htmlspecialchars($product['image_url'] ?? 'default-image.jpg'); ?>" 
                 alt="<?php echo htmlspecialchars($product['name']); ?>"
                 onerror="this.src='default-image.jpg'">
          </div>

          <!-- Product Name -->
          <h3 class="compare-name">
            <a href="product-detail.php?id=<?php echo $product['id']; ?>">
              <?php echo htmlspecialchars($product['name']); ?>
            </a>
          </h3>

          <!-- Price -->
          <div class="compare-price">₱<?php echo number_format($product['price'], 2); ?></div>

          <!-- Rating -->
          <div class="compare-rating">
            <div class="compare-rating-stars">
              <?php echo str_repeat('★', $fullStars); ?>
              <?php echo $hasHalfStar ? '☆' : ''; ?>
              <?php echo str_repeat('☆', $emptyStars); ?>
            </div>
            <div class="compare-rating-value"><?php echo number_format($rating, 1); ?>/5.0</div>
            <div class="compare-rating-count">(<?php echo $product['review_count']; ?> reviews)</div>
          </div>

          <!-- Stock Status -->
          <div class="compare-stock">
            <?php 
            if ($stock > 20) {
                echo statusBadge("In Stock ($stock)", '#28a745', '#ffffff');
            } elseif ($stock > 0) {
                echo statusBadge("Low Stock ($stock)", '#ffc107', '#130325');
            } else {
                echo statusBadge("Out of Stock", '#dc3545', '#ffffff');
            }
            ?>
          </div>

          <!-- Category -->
          <div class="compare-category">
            <span><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></span>
          </div>

          <!-- Description -->
          <div class="compare-description">
            <div class="compare-description-title">Description</div>
            <div class="compare-description-text">
              <?php echo htmlspecialchars(substr($product['description'] ?? '', 0, 100)); ?>
              <?php if (strlen($product['description'] ?? '') > 100): ?>
                <a href="product-detail.php?id=<?php echo $product['id']; ?>" style="color: var(--primary-dark); text-decoration: none;">...read more</a>
              <?php endif; ?>
            </div>
          </div>

          <!-- Product Features -->
          <?php if (!empty($allFeatureNames)): ?>
            <div class="compare-specs">
              <div class="compare-specs-title">Specifications</div>
              <?php foreach ($allFeatureNames as $featureName): ?>
                <div class="compare-spec-item">
                  <span class="compare-spec-label"><?php echo htmlspecialchars($featureName); ?>:</span>
                  <span class="compare-spec-value">
                    <?php 
                    if (isset($productFeatures[$product['id']][$featureName])) {
                        echo htmlspecialchars($productFeatures[$product['id']][$featureName]);
                    } else {
                        echo '<span style="opacity:0.6; font-style:italic;">N/A</span>';
                    }
                    ?>
                  </span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <!-- Spacer to push buttons to bottom -->
          <div style="flex:1;"></div>

          <!-- Action Buttons -->
          <div class="compare-actions">
            <button onclick="addToCart(<?php echo $product['id']; ?>, 1)" 
                    data-product-id="<?php echo $product['id']; ?>"
                    class="compare-btn-cart"
                    title="Add to Cart">
              <i class="fas fa-shopping-cart"></i>
            </button>
            <a href="product-detail.php?id=<?php echo $product['id']; ?>" class="compare-btn">
              <i class="fas fa-eye"></i>View Details
            </a>
          </div>

        </div>
      <?php endforeach; ?>
    </div>

    <?php if (empty($products)): ?>
      <div class="compare-empty">
        No products to compare.
      </div>
    <?php endif; ?>
  </div>
</main>

<!-- Cart notification -->
<div id="cart-notification" class="cart-notification" style="display: none;">
    <span id="notification-message"></span>
</div>

<script>
function addToCart(productId, quantity = 1) {
    const button = document.querySelector(`button[data-product-id="${productId}"]`);
    if (!button) return;
    
    const originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
    button.disabled = true;
    
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
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Product added to cart!', 'success');
            button.innerHTML = '<i class="fas fa-check"></i> Added';
            setTimeout(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            }, 2000);
        } else {
            showNotification(data.message || 'Error adding to cart', 'error');
            button.innerHTML = originalText;
            button.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error adding to cart', 'error');
        button.innerHTML = originalText;
        button.disabled = false;
    });
}

function showNotification(message, type = 'success') {
    const notification = document.getElementById('cart-notification');
    const messageElement = document.getElementById('notification-message');
    
    if (!notification || !messageElement) return;
    
    messageElement.textContent = message;
    notification.className = 'cart-notification ' + type;
    notification.style.display = 'block';
    
    setTimeout(() => {
        notification.style.display = 'none';
    }, 3000);
}

// Add hover effects
document.addEventListener('DOMContentLoaded', function() {
    const buttons = document.querySelectorAll('button, a[href*="product-detail"]');
    buttons.forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
            this.style.boxShadow = '0 4px 12px rgba(255,215,54,0.3)';
        });
        btn.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = 'none';
        });
    });
});
</script>

<style>
.cart-notification {
    position: fixed;
    top: 100px;
    right: 20px;
    padding: 15px 25px;
    border-radius: 8px;
    font-weight: 600;
    z-index: 10000;
    animation: slideIn 0.3s ease-out;
}

.cart-notification.success {
    background: #28a745;
    color: #ffffff;
    border: 1px solid #1e7e34;
}

 .cart-notification.error {
     background: #dc3545;
     color: #ffffff;
     border: 1px solid #bd2130;
 }

 .back-to-products-btn:hover {
     background: #e6c230 !important;
     border-color: #e6c230 !important;
     color: #130325 !important;
     transform: translateY(-2px);
     box-shadow: 0 4px 12px rgba(255, 215, 54, 0.3);
 }

@keyframes slideIn {
    from {
        transform: translateX(400px);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@media (max-width: 768px) {
    main {
        padding: 40px 0 60px 0 !important;
    }
    
    div[style*="grid-template-columns"] {
        grid-template-columns: 1fr !important;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>