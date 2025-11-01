<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

$sellerId = isset($_GET['seller_id']) ? (int)$_GET['seller_id'] : 0;
if ($sellerId <= 0) {
    header('Location: index.php');
    exit();
}

// Fetch seller info
$stmt = $pdo->prepare("SELECT id, username, first_name, last_name, email, created_at FROM users WHERE id = ?");
$stmt->execute([$sellerId]);
$seller = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$seller) {
    header('Location: index.php');
    exit();
}

$sellerName = trim(($seller['first_name'] ?? '') . ' ' . ($seller['last_name'] ?? '')) ?: ($seller['username'] ?? 'Seller');

// Pagination & sorting
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 24;
$sort = isset($_GET['sort']) ? sanitizeInput($_GET['sort']) : 'created_at';
$order = isset($_GET['order']) ? sanitizeInput($_GET['order']) : 'DESC';
$validSorts = ['name', 'price', 'rating', 'review_count', 'created_at'];
$validOrders = ['ASC', 'DESC'];
if (!in_array($sort, $validSorts)) { $sort = 'created_at'; }
if (!in_array($order, $validOrders)) { $order = 'DESC'; }

$offset = ($page - 1) * $perPage;

// Count products
$countStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM products WHERE status='active' AND seller_id = ?");
$countStmt->execute([$sellerId]);
$totalCount = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
$totalPages = max(1, (int)ceil($totalCount / $perPage));

// Fetch products for seller
$sql = "SELECT id, name, price, image_url, rating, review_count, stock_quantity FROM products WHERE status='active' AND seller_id = ? ORDER BY $sort $order LIMIT $perPage OFFSET $offset";
$prodStmt = $pdo->prepare($sql);
$prodStmt->execute([$sellerId]);
$products = $prodStmt->fetchAll(PDO::FETCH_ASSOC);

function resolveProductImageUrlLocal($url) {
    $url = trim((string)$url);
    if ($url === '') return 'assets/images/placeholder.jpg';
    if (preg_match('#^https?://#i', $url)) return $url;
    if (strpos($url, 'assets/') === 0) return str_replace(' ', '%20', $url);
    return 'assets/uploads/' . str_replace(' ', '%20', $url);
}
?>

<style>
/* EXACT MATCH TO PRODUCTS.PHP STYLING */

/* Body background - Light like products.php */
body {
    background: #f0f2f5;
    min-height: 100vh;
    color: #130325;
    margin: 0;
    padding: 0;
}

/* Main container */
main { 
    background: transparent !important; 
    margin: 0 !important; 
    padding: 8px 0 60px 0 !important; 
}

.seller-container { 
    max-width: 1400px; 
    margin: 0 auto; 
    padding: 10px 20px; 
}

/* Seller Header */
.seller-header { 
    background: #ffffff; 
    border: 1px solid rgba(0,0,0,0.1); 
    border-radius: 12px; 
    padding: 16px 20px; 
    display: flex; 
    align-items: center; 
    gap: 16px; 
    margin-bottom: 20px;
}

.seller-avatar { 
    width: 56px; 
    height: 56px; 
    border-radius: 50%; 
    background: #FFD736; 
    color: #130325; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    font-weight: 800; 
    font-size: 1.5rem;
}

.seller-meta { 
    display: flex; 
    flex-direction: column; 
}

.seller-name { 
    font-size: 1.2rem; 
    font-weight: 800; 
    color: #130325; 
}

.seller-sub { 
    color: #6b7280; 
    font-size: 0.9rem; 
}

/* Sorting Container - EXACT MATCH TO PRODUCTS.PHP */
.sorting-bar {
    background: #f0f2f5;
    padding: 15px 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(19, 3, 37, 0.1);
    border: 2px solid #f0f2f5;
    border-radius: 8px;
}

.sorting-bar form {
    display: flex;
    align-items: center;
    gap: 15px;
}

.sorting-bar label {
    color: #130325;
    font-weight: 700;
    font-size: 0.9rem;
    text-transform: uppercase;
    margin: 0;
}

.sort-select,
.order-select {
    background: #130325;
    color: white;
    border: 2px solid #130325;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    min-width: 160px;
}

.sort-select:focus,
.order-select:focus {
    outline: none;
    background: #FFD736;
    color: #130325;
}

/* Products Grid - EXACT MATCH TO PRODUCTS.PHP */
.products-grid {
    display: flex !important;
    gap: 25px !important;
    overflow: visible !important;
    position: relative !important;
    width: 100% !important;
    justify-content: center !important;
    max-width: none !important;
    margin: 0 auto !important;
    text-align: center !important;
    grid-template-columns: none !important;
    flex-wrap: wrap !important;
}

/* Product Card - EXACT MATCH TO PRODUCTS.PHP */
.product-card {
    position: relative !important;
    background: rgba(26, 10, 46, 0.6) !important;
    border: 1px solid rgba(255, 215, 54, 0.2) !important;
    border-radius: 16px !important;
    padding: 15px !important;
    text-align: center !important;
    transition: all 0.3s ease !important;
    display: flex !important;
    flex-direction: column !important;
    flex: 0 0 19% !important;
    min-width: 200px !important;
    max-width: 250px !important;
}

.product-card:hover {
    transform: translateY(-8px) !important;
    border-color: #FFD736 !important;
    box-shadow: 0 15px 35px rgba(255, 215, 54, 0.2) !important;
}

/* Product Image - EXACT MATCH */
.product-card img {
    width: 100% !important;
    height: 160px !important;
    object-fit: cover !important;
    border-radius: 12px !important;
    margin-bottom: 10px !important;
}

/* Product Name - EXACT MATCH */
.product-card .product-name {
    color: #212529 !important;
    font-size: 1.1rem !important;
    font-weight: 700 !important;
    margin: 5px 0 !important;
    min-height: 35px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}

/* Price - EXACT MATCH */
.product-card .price {
    color: #212529 !important;
    font-weight: 800 !important;
    font-size: 1.5rem !important;
    margin: 5px 0 !important;
}

/* Rating - EXACT MATCH */
.product-card .rating {
    color: #FFD736 !important;
    font-size: 1.3rem !important;
    margin: 5px 0 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
}

.product-card .rating-text {
    color: #212529 !important;
    font-size: 0.9rem !important;
    display: inline !important;
    margin: 0 !important;
    font-weight: 700 !important;
}

/* Stock - EXACT MATCH */
.stock {
    color: #28a745 !important;
    font-size: 0.9rem !important;
    margin: 12px 0 !important;
    font-weight: 600 !important;
}

/* Product Actions - EXACT MATCH */
.product-actions {
    display: flex !important;
    flex-direction: column !important;
    gap: 10px !important;
    margin-top: auto !important;
}

.cart-actions {
    display: flex !important;
    gap: 8px !important;
    align-items: center !important;
}

.btn-cart-icon {
    background: #FFD736 !important;
    color: #130325 !important;
    border: 2px solid #FFD736 !important;
    border-radius: 6px !important;
    padding: 6px !important;
    cursor: pointer !important;
    font-size: 12px !important;
    width: 32px !important;
    height: 32px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    transition: all 0.3s ease !important;
    flex-shrink: 0 !important;
}

.btn-cart-icon:hover {
    background: #ffde62 !important;
    border-color: #FFD736 !important;
    color: #130325 !important;
    transform: translateY(-2px) !important;
}

.btn-buy-now {
    background: #130325 !important;
    color: white !important;
    border: 2px solid #FFD736 !important;
    padding: 6px 12px !important;
    cursor: pointer !important;
    font-size: 0.8rem !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    transition: all 0.3s ease !important;
    text-decoration: none !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    flex: 1 !important;
    height: 32px !important;
    border-radius: 6px !important;
}

.btn-buy-now:hover {
    background: #FFD736 !important;
    color: #130325 !important;
    transform: translateY(-2px) !important;
}

/* Buttons - EXACT MATCH */
.btn {
    padding: 12px 20px !important;
    border: none !important;
    border-radius: 8px !important;
    cursor: pointer !important;
    font-size: 0.95rem !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    transition: all 0.3s ease !important;
    text-decoration: none !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
}

.btn-details {
    background: #130325 !important;
    color: #ffffff !important;
    border: 2px solid #130325 !important;
}

.btn-details:hover {
    background: #FFD736 !important;
    color: #130325 !important;
    transform: translateY(-2px) !important;
    text-decoration: none !important;
}

/* Pagination */
.pagination {
    display: flex;
    gap: 6px;
    justify-content: center;
    margin-top: 30px;
}

.pagination a {
    padding: 8px 14px;
    border: 2px solid #130325;
    border-radius: 6px;
    color: #130325;
    text-decoration: none;
    font-size: 0.9rem;
    font-weight: 600;
    transition: all 0.3s ease;
}

.pagination a:hover {
    background: #FFD736;
    border-color: #FFD736;
    color: #130325;
}

.pagination a.active {
    background: #130325;
    color: #fff;
    border-color: #130325;
}

/* Responsive */
@media (max-width: 768px) {
    .products-grid {
        justify-content: center !important;
    }
    
    .product-card {
        flex: 0 0 45% !important;
        min-width: 150px !important;
    }
}

@media (max-width: 576px) {
    .product-card {
        flex: 0 0 100% !important;
        max-width: 300px !important;
    }
}
</style>

<main>
  <div class="seller-container">
    <div class="seller-header">
      <div class="seller-avatar">
        <?php echo strtoupper(substr($sellerName, 0, 1)); ?>
      </div>
      <div class="seller-meta">
        <div class="seller-name"><?php echo htmlspecialchars($sellerName); ?></div>
        <div class="seller-sub">Member since <?php echo date('Y', strtotime($seller['created_at'])); ?> • <?php echo htmlspecialchars($seller['email']); ?></div>
      </div>
    </div>

    <div class="sorting-bar">
      <form method="GET">
        <input type="hidden" name="seller_id" value="<?php echo $sellerId; ?>">
        <label>SORT BY</label>
        <select name="sort" class="sort-select" onchange="this.form.submit()">
          <option value="created_at" <?php echo $sort==='created_at'?'selected':''; ?>>Newest</option>
          <option value="price" <?php echo $sort==='price'?'selected':''; ?>>Price</option>
          <option value="name" <?php echo $sort==='name'?'selected':''; ?>>Name</option>
          <option value="rating" <?php echo $sort==='rating'?'selected':''; ?>>Rating</option>
          <option value="review_count" <?php echo $sort==='review_count'?'selected':''; ?>>Most Reviews</option>
        </select>
        <select name="order" class="order-select" onchange="this.form.submit()">
          <option value="DESC" <?php echo $order==='DESC'?'selected':''; ?>>Descending</option>
          <option value="ASC" <?php echo $order==='ASC'?'selected':''; ?>>Ascending</option>
        </select>
      </form>
    </div>

    <?php if (empty($products)): ?>
      <p style="text-align:center; color:#6c757d; padding:40px;">No products found for this seller.</p>
    <?php else: ?>
      <div class="products-list">
        <div class="products-grid">
          <?php foreach ($products as $p): 
            $img = resolveProductImageUrlLocal($p['image_url'] ?? ''); 
          ?>
            <div class="product-card">
              <img loading="lazy" src="<?php echo htmlspecialchars($img); ?>" alt="<?php echo htmlspecialchars($p['name']); ?>">
              <h3 class="product-name"><?php echo htmlspecialchars($p['name']); ?></h3>
              <p class="price">₱<?php echo number_format($p['price'], 2); ?></p>
              <div class="rating">
                <?php
                  $rating = (float)($p['rating'] ?? 0);
                  $fullStars = floor($rating);
                  $hasHalfStar = ($rating - $fullStars) >= 0.5;
                  for ($i = 1; $i <= 5; $i++) {
                      if ($i <= $fullStars) {
                          echo '★';
                      } elseif ($i == $fullStars + 1 && $hasHalfStar) {
                          echo '☆';
                      } else {
                          echo '☆';
                      }
                  }
                ?>
                <span class="rating-text">(<?php echo number_format($p['rating'] ?? 0, 1); ?>)</span>
              </div>
              <div class="stock"><?php echo (int)($p['stock_quantity'] ?? 0); ?> in stock</div>
              <div class="product-actions">
                <a href="product-detail.php?id=<?php echo (int)$p['id']; ?>" 
                   class="btn btn-details">
                    <i class="fas fa-eye"></i> View Details
                </a>
                <div class="cart-actions">
                  <button onclick="addToCart(<?php echo (int)$p['id']; ?>, 1)" 
                          class="btn-cart-icon" 
                          data-product-id="<?php echo (int)$p['id']; ?>"
                          title="Add to Cart">
                      <i class="fas fa-shopping-cart"></i>
                  </button>
                  <button onclick="handleBuyNow(<?php echo (int)$p['id']; ?>, 1)" 
                          class="btn btn-buy-now" 
                          data-product-id="<?php echo (int)$p['id']; ?>">
                      Buy Now
                  </button>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($totalPages > 1): ?>
        <div class="pagination">
          <?php for ($i=1;$i<=$totalPages;$i++): ?>
            <a href="?seller_id=<?php echo $sellerId; ?>&sort=<?php echo urlencode($sort); ?>&order=<?php echo urlencode($order); ?>&page=<?php echo $i; ?>" class="<?php echo $i===$page?'active':''; ?>"><?php echo $i; ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

  </div>
</main>

<script>
function handleBuyNow(productId, quantity = 1) {
    // Add to cart and redirect to checkout
    addToCart(productId, quantity);
    window.location.href = 'cart.php';
}
</script>

<?php require_once 'includes/footer.php'; ?>