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

<main style="background:#f8f9fa; min-height:100vh; padding: 40px 0 60px 0;">
  <div style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
    
    <!-- Header with back arrow and title -->
    <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 30px;">
      <a href="products.php" style="color: #130325; text-decoration: none; font-size: 1.8rem; font-weight: 600; display: flex; align-items: center; gap: 12px;">
        <i class="fas fa-arrow-left"></i>
      </a>
      <h1 style="color: #130325; margin: 0; font-size: 1.5rem; font-weight: 700;">Compare Products</h1>
    </div>

    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap:15px;">
      <?php foreach ($products as $product): 
        $isBestPrice = ($product['price'] == $minPrice);
        $rating = floatval($product['rating']);
        $isBestRating = ($rating == $maxRating && $rating > 0);
        $fullStars = floor($rating);
        $hasHalfStar = ($rating - $fullStars) >= 0.5;
        $emptyStars = 5 - $fullStars - ($hasHalfStar ? 1 : 0);
        $stock = intval($product['stock_quantity'] ?? 0);
      ?>
        <div style="background:#ffffff; border:<?php echo ($isBestPrice || $isBestRating) ? '3px solid #130325' : '1px solid rgba(0,0,0,0.1)'; ?>; border-radius:8px; padding:15px; position:relative; display:flex; flex-direction:column; min-height:500px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
          
          <?php if ($isBestPrice || $isBestRating): ?>
            <div style="position:absolute; top:10px; right:10px; background:#130325; color:#FFD736; padding:6px 12px; border-radius:20px; border:1px solid #130325;">
              <i class="fas fa-star" style="margin-right:4px;"></i>
              <?php if ($isBestPrice) echo 'Best Price'; ?>
              <?php if ($isBestPrice && $isBestRating) echo ' & '; ?>
              <?php if ($isBestRating) echo 'Top Rated'; ?>
            </div>
          <?php endif; ?>

          <!-- Product Image -->
          <div style="text-align:center; margin-bottom:15px;">
            <img src="<?php echo htmlspecialchars($product['image_url'] ?? 'default-image.jpg'); ?>" 
                 alt="<?php echo htmlspecialchars($product['name']); ?>"
                 style="width:180px; height:180px; object-fit:cover; border-radius:10px; border:2px solid #2d1b4e;"
                 onerror="this.src='default-image.jpg'">
          </div>

          <!-- Product Name -->
          <h3 style="color:#130325; margin:0 0 10px 0; text-align:center;">
            <a href="product-detail.php?id=<?php echo $product['id']; ?>" style="text-decoration:none; color:#130325;">
              <?php echo htmlspecialchars($product['name']); ?>
            </a>
          </h3>

          <!-- Price -->
          <div style="text-align:center; margin-bottom:15px;">
            <div style="color:#130325; font-weight:700; font-size:24px;">₱<?php echo number_format($product['price'], 2); ?></div>
          </div>

          <!-- Rating -->
          <div style="background:rgba(255,215,54,0.1); padding:10px; border-radius:6px; margin-bottom:12px; text-align:center;">
            <div style="color:#FFD736; margin-bottom:5px;">
              <?php echo str_repeat('★', $fullStars); ?>
              <?php echo $hasHalfStar ? '☆' : ''; ?>
              <?php echo str_repeat('☆', $emptyStars); ?>
            </div>
            <div style="color:#130325; font-weight:600;"><?php echo number_format($rating, 1); ?>/5.0</div>
            <div style="color:#130325; opacity:0.7;">(<?php echo $product['review_count']; ?> reviews)</div>
          </div>

          <!-- Stock Status -->
          <div style="text-align:center; margin-bottom:15px;">
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
          <div style="text-align:center; margin-bottom:12px;">
            <span style="background:#f8f9fa; color:#130325; padding:6px 12px; border-radius:12px; font-weight:600; border:1px solid rgba(0,0,0,0.1);">
              <?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?>
            </span>
          </div>

          <!-- Description -->
          <div style="background:#f8f9fa; padding:10px; border-radius:6px; margin-bottom:12px;">
            <div style="color:#130325; margin-bottom:5px; font-weight:600;">Description</div>
            <div style="color:#130325; opacity:0.8; line-height:1.5;">
              <?php echo htmlspecialchars(substr($product['description'] ?? '', 0, 120)); ?>
              <?php if (strlen($product['description'] ?? '') > 120): ?>
                <a href="product-detail.php?id=<?php echo $product['id']; ?>" style="color:#1e3a8a; text-decoration:none;">...read more</a>
              <?php endif; ?>
            </div>
          </div>

          <!-- Product Features -->
          <?php if (!empty($allFeatureNames)): ?>
            <div style="background:#f8f9fa; padding:10px; border-radius:6px; margin-bottom:12px;">
              <div style="color:#130325; margin-bottom:8px; font-weight:600;">Specifications</div>
              <?php foreach ($allFeatureNames as $featureName): ?>
                <div style="display:flex; justify-content:space-between; padding:4px 0; border-bottom:1px solid rgba(0,0,0,0.1);">
                  <span style="color:#130325; opacity:0.8;"><?php echo htmlspecialchars($featureName); ?>:</span>
                  <span style="color:#130325; font-weight:600;">
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
          <div style="display:flex; flex-direction:row; gap:8px; margin-top:auto;">
            <a href="product-detail.php?id=<?php echo $product['id']; ?>" 
               style="background:#130325; border:none; color:#ffffff; padding:10px; border-radius:6px; text-decoration:none; text-align:center; font-weight:600; font-size:14px; transition:all 0.3s; flex:1;">
              <i class="fas fa-eye" style="margin-right:6px;"></i>View Details
            </a>
            <button onclick="addToCart(<?php echo $product['id']; ?>, 1)" 
                    data-product-id="<?php echo $product['id']; ?>"
                    style="background:#FFD736; border:none; color:#130325; padding:10px; border-radius:6px; cursor:pointer; font-weight:600; font-size:14px; transition:all 0.3s; flex:1;">
              <i class="fas fa-shopping-cart" style="margin-right:6px;"></i>Add to Cart
            </button>
          </div>

        </div>
      <?php endforeach; ?>
    </div>

    <?php if (empty($products)): ?>
      <div style="background:#1a0a2e; border:1px solid #2d1b4e; color:#F9F9F9; border-radius:8px; padding:20px; text-align:center;">
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