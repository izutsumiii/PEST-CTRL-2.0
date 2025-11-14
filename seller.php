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
$stmt = $pdo->prepare("SELECT id, username, first_name, last_name, display_name, email, created_at FROM users WHERE id = ?");
$stmt->execute([$sellerId]);
$seller = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$seller) {
    header('Location: index.php');
    exit();
}

$sellerName = $seller['display_name'] ?? trim(($seller['first_name'] ?? '') . ' ' . ($seller['last_name'] ?? '')) ?: ($seller['username'] ?? 'Seller');

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
    background: var(--bg-light);
    min-height: 100vh;
    color: var(--text-dark);
    margin: 0;
    padding: 0;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}

main { 
    background: transparent !important; 
    margin: 0 !important; 
    padding: 0 0 60px 0 !important; 
}

.seller-container { 
    max-width: 1600px; 
    margin: 0 auto; 
    padding: 12px 20px; 
}

/* Seller Header */
.seller-header { 
    background: var(--bg-white); 
    border: 1px solid var(--border-light); 
    border-radius: 12px; 
    padding: 14px 16px; 
    display: flex; 
    align-items: center; 
    gap: 12px; 
    margin-bottom: 16px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.seller-avatar { 
    width: 48px; 
    height: 48px; 
    border-radius: 50%; 
    background: var(--primary-dark); 
    color: var(--bg-white); 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    font-weight: 600; 
    font-size: 1.2rem;
}

.seller-meta { 
    display: flex; 
    flex-direction: column; 
}

.seller-name { 
    font-size: 1.1rem; 
    font-weight: 600; 
    color: var(--text-dark); 
}

.seller-sub { 
    color: var(--text-light); 
    font-size: 0.85rem; 
}

/* Sorting Container */
.sorting-bar {
    background: var(--bg-white);
    padding: 12px 16px;
    margin-bottom: 16px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    border: 1px solid var(--border-light);
    border-radius: 8px;
}

.sorting-bar form {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.sorting-bar label {
    color: var(--text-dark);
    font-weight: 600;
    font-size: 12px;
    margin: 0;
}

.sort-select,
.order-select {
    background: var(--bg-white);
    color: var(--text-dark);
    border: 1.5px solid var(--border-light);
    padding: 6px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    min-width: 140px;
    transition: all 0.2s;
}

.sort-select:focus,
.order-select:focus {
    outline: none;
    border-color: var(--primary-dark);
    box-shadow: 0 0 0 3px rgba(19, 3, 37, 0.1);
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
    background: var(--bg-white) !important;
    border: 1px solid var(--border-light) !important;
    border-radius: 12px !important;
    padding: 12px !important;
    text-align: center !important;
    transition: all 0.2s ease !important;
    display: flex !important;
    flex-direction: column !important;
    flex: 0 0 19% !important;
    min-width: 200px !important;
    max-width: 250px !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05) !important;
}

.product-card:hover {
    transform: translateY(-2px) !important;
    border-color: var(--primary-dark) !important;
    box-shadow: 0 4px 12px rgba(19, 3, 37, 0.15) !important;
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
    max-height: 50px !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    display: -webkit-box !important;
    -webkit-line-clamp: 2 !important;
    -webkit-box-orient: vertical !important;
    line-height: 1.4 !important;
}

/* Price - EXACT MATCH */
.product-card .price {
    color: #212529 !important;
    font-weight: 700 !important;
    font-size: 1.2rem !important;
    margin: 4px 0 !important;
}

/* Rating - EXACT MATCH */
.product-card .rating {
    color: var(--accent-yellow) !important;
    font-size: 1.1rem !important;
    margin: 4px 0 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
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
    flex-direction: row !important;
    gap: 8px !important;
    align-items: center !important;
    margin-top: auto !important;
    margin-bottom: 0 !important;
    padding-top: 8px !important;
}

.btn-cart-icon {
    background: var(--accent-yellow) !important;
    color: var(--primary-dark) !important;
    border: 1px solid var(--accent-yellow) !important;
    border-radius: 6px !important;
    padding: 4px !important;
    cursor: pointer !important;
    font-size: 10px !important;
    width: 24px !important;
    height: 24px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    transition: all 0.2s ease !important;
    flex-shrink: 0 !important;
}

.btn-cart-icon:hover {
    background: #ffd020 !important;
    border-color: #ffd020 !important;
    color: var(--primary-dark) !important;
    transform: translateY(-1px) !important;
}

/* Buttons - EXACT MATCH */
.btn {
    padding: 4px 10px !important;
    border: none !important;
    border-radius: 4px !important;
    cursor: pointer !important;
    font-size: 0.75rem !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    transition: all 0.3s ease !important;
    text-decoration: none !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 4px !important;
}

.btn-details {
    background: #130325 !important;
    color: #ffffff !important;
    border: 2px solid #130325 !important;
    padding: 4px 10px !important;
    font-size: 0.75rem !important;
    flex: 1 !important;
    justify-content: center !important;
    border-radius: 4px !important;
    margin: 0 !important;
    line-height: 1.2 !important;
    min-height: auto !important;
    height: 24px !important;
    text-align: center !important;
}

.btn-details i {
    font-size: 0.6rem !important;
    margin-right: 2px !important;
}

.btn-details:hover {
    background: #0a0118 !important;
    color: var(--bg-white) !important;
    transform: translateY(-1px) !important;
    text-decoration: none !important;
}

/* Pagination */
.pagination {
    display: flex;
    gap: 6px;
    justify-content: center;
    margin-top: 24px;
    flex-wrap: wrap;
}

.pagination a {
    padding: 6px 12px;
    border: 1px solid var(--border-light);
    border-radius: 6px;
    color: var(--text-dark);
    text-decoration: none;
    font-size: 12px;
    font-weight: 500;
    transition: all 0.2s ease;
    background: var(--bg-white);
}

.pagination a:hover {
    background: var(--primary-dark);
    border-color: var(--primary-dark);
    color: var(--bg-white);
}

.pagination a.active {
    background: var(--primary-dark);
    color: var(--bg-white);
    border-color: var(--primary-dark);
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
    
    .seller-container {
        padding: 10px 12px;
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
                <button onclick="addToCart(<?php echo (int)$p['id']; ?>, 1)" 
                        class="btn-cart-icon" 
                        data-product-id="<?php echo (int)$p['id']; ?>"
                        title="Add to Cart">
                    <i class="fas fa-shopping-cart"></i>
                </button>
                <a href="product-detail.php?id=<?php echo (int)$p['id']; ?>" 
                   class="btn btn-details">
                    View Details
                </a>
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


<?php require_once 'includes/footer.php'; ?>