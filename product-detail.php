<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

if (!isset($_GET['id'])) {
    header("Location: products.php");
    exit();
}

$productId = intval($_GET['id']);

// OPTIMIZED: Single query to get all product data with seller info
$__debugMode = isset($_GET['debug']) && $_GET['debug'] == '1';
// Server-side request tracing to error log
$__reqStart = microtime(true);
error_log('[product-detail] START id=' . (isset($_GET['id']) ? (int)$_GET['id'] : 0));
register_shutdown_function(function() use ($__reqStart) {
    error_log('[product-detail] END after ' . number_format(microtime(true) - $__reqStart, 4) . 's');
});

// Ultra-light mode to bypass heavy rendering for diagnostics
if (isset($_GET['lite']) && $_GET['lite'] == '1') {
    $productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $stmt = $pdo->prepare("SELECT id, name, price, stock_quantity FROM products WHERE id = ? LIMIT 1");
    $stmt->execute([$productId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode(['ok' => (bool)$p, 'product' => $p]);
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    exit;
}
if ($__debugMode) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
}

if ($__debugMode) echo "<!-- DEBUG START " . date('Y-m-d H:i:s') . " -->\n";

$tProductStart = microtime(true);
$stmt = $pdo->prepare("
    SELECT 
        p.*, 
        u.username as seller_name,
        u.first_name as seller_first_name,
        u.last_name as seller_last_name,
        c.id as category_id,
        c.name as category_name,
        c.parent_id as category_parent_id
    FROM products p 
    JOIN users u ON p.seller_id = u.id 
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.id = ?
");
$stmt->execute([$productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);
if ($__debugMode) echo "<!-- product query: " . number_format(microtime(true) - $tProductStart, 4) . "s -->\n";

if (!$product) {
    echo "<p>Product not found.</p>";
    require_once 'includes/footer.php';
    exit();
}

// OPTIMIZED: Get all categories in one query to build breadcrumb efficiently
$allCategoriesStmt = $pdo->prepare("SELECT id, name, parent_id FROM categories");
$allCategoriesStmt->execute();
$allCategories = $allCategoriesStmt->fetchAll(PDO::FETCH_ASSOC);

// Build category lookup array for efficient traversal
$categoryLookup = [];
foreach ($allCategories as $cat) {
    $categoryLookup[$cat['id']] = $cat;
}

// Build category breadcrumb efficiently with cycle/overflow guards
$categoryPath = [];
$categoryBreadcrumb = [];

if (!empty($product['category_id'])) {
    $currentCatId = (int)$product['category_id'];
    $path = [];
    $visited = [];
    $maxDepth = 32; // hard cap to avoid infinite loops
    $depth = 0;
    
    while ($currentCatId && isset($categoryLookup[$currentCatId])) {
        if (isset($visited[$currentCatId])) { // cycle detected
            break;
        }
        $visited[$currentCatId] = true;
        $currentCat = $categoryLookup[$currentCatId];
        array_unshift($path, $currentCat['name']);
        array_unshift($categoryBreadcrumb, $currentCat);
        $currentCatId = (int)$currentCat['parent_id'];
        $depth++;
        if ($depth >= $maxDepth) { // depth cap reached
            break;
        }
    }
    $categoryPath = $path;
}

// OPTIMIZED: Get product images and reviews in parallel queries
$tImagesStart = microtime(true);
$imagesStmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ?");
$reviewsStmt = $pdo->prepare("
    SELECT pr.*, u.username 
    FROM product_reviews pr 
    JOIN users u ON pr.user_id = u.id 
    WHERE pr.product_id = ? 
    ORDER BY pr.created_at DESC
    LIMIT 20
");

// Execute both queries
$imagesStmt->execute([$productId]);
$reviewsStmt->execute([$productId]);

$images = $imagesStmt->fetchAll(PDO::FETCH_ASSOC);
if ($__debugMode) echo "<!-- images query: " . number_format(microtime(true) - $tImagesStart, 4) . "s (" . count($images) . " rows) -->\n";

$tReviewsStart = microtime(true);
$reviews = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);
if ($__debugMode) echo "<!-- reviews query: " . number_format(microtime(true) - $tReviewsStart, 4) . "s (" . count($reviews) . " rows) -->\n";

// Release session lock ASAP to avoid blocking concurrent AJAX requests
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
if ($__debugMode) echo "<!-- DEBUG END -->\n";

// Determine if current user can submit a review (must have a delivered order for this product and not reviewed yet)
$canReview = false;
$hasReviewed = false;
if (isLoggedIn()) {
    try {
        $userId = $_SESSION['user_id'];
        // Check if already reviewed
        $checkReviewed = $pdo->prepare("SELECT 1 FROM product_reviews WHERE user_id = ? AND product_id = ? LIMIT 1");
        $checkReviewed->execute([$userId, $productId]);
        $hasReviewed = (bool)$checkReviewed->fetchColumn();

        if (!$hasReviewed) {
            // Check delivered purchase
            $checkDelivered = $pdo->prepare("SELECT 1
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                WHERE o.user_id = ? AND oi.product_id = ? AND o.status = 'delivered'
                LIMIT 1");
            $checkDelivered->execute([$userId, $productId]);
            $canReview = (bool)$checkDelivered->fetchColumn();
        }
    } catch (Exception $e) {
        // Fallback to backend validation only
        $canReview = false;
    }
}

// Handle review submission
$reviewMessage = '';
if (isset($_POST['submit_review']) && isLoggedIn()) {
    $rating = intval($_POST['rating']);
    $reviewText = sanitizeInput($_POST['review_text']);
    
    if (addProductReview($productId, $rating, $reviewText)) {
        // Redirect to prevent resubmission on refresh
        header("Location: product-detail.php?id=" . $productId . "&review_success=1");
        exit();
    } else {
        $reviewMessage = "Error submitting review. You may have already reviewed this product or not purchased it.";
    }
}

// Check for success message
if (isset($_GET['review_success']) && $_GET['review_success'] == '1') {
    $reviewMessage = "Review submitted successfully!";
}

// Calculate seller display name
$sellerDisplayName = trim($product['seller_first_name'] . ' ' . $product['seller_last_name']) ?: $product['seller_name'];

// Include header after redirects to avoid headers already sent
require_once 'includes/header.php';
?>

<style>
/* Base */
html, body { margin:0; padding:0; min-height:100vh; background:#130325 !important; }
main { background:transparent !important; margin:0 !important; padding:20px 0 80px 0 !important; min-height:calc(100vh - 80px) !important; }

/* Product Detail Container */
.product-detail { display:grid; grid-template-columns: 1.1fr 0.9fr; gap:32px; margin:24px 0; background:#1a0a2e; padding:24px; border-radius:16px; box-shadow:0 10px 30px rgba(0,0,0,0.35); border:1px solid #2d1b4e; }

/* Gallery */
.product-images { display:flex; flex-direction:column; gap:12px; }
.product-images img { width:100%; height:auto; border-radius:12px; border:1px solid rgba(255,215,54,0.25); background:#0e0620; box-shadow:0 6px 18px rgba(0,0,0,0.35); }

/* Info */
.product-info { background:#140826; padding:20px; border-radius:12px; border:1px solid #2d1b4e; }
.product-info h1 { color:#F9F9F9; font-size:1.9rem; font-weight:800; letter-spacing:.2px; margin-bottom:12px; }
.price { font-size:1.8rem; font-weight:900; color:#FFD736; margin-bottom:8px; }
.stock { color:#28a745; font-weight:700; margin-bottom:8px; }
.low-stock { color:#ffc107; font-weight:700; }
.out-stock { color:#dc3545; font-weight:700; }
.seller { color:#d7d1e2; margin-bottom:10px; }
.category-info { color:#d7d1e2; font-size:.92rem; margin-bottom:10px; }
.rating { color:#FFD736; font-weight:900; letter-spacing:.4px; margin-bottom:16px; }

.quantity-form { display:flex; align-items:center; gap:10px; margin-bottom:12px; }
.quantity-form label { font-weight:700; color:#d7d1e2; }
.quantity-form input { width:90px; padding:10px 12px; border:1px solid #2d1b4e; border-radius:10px; background:#0f0820; color:#F9F9F9; }
.add-to-cart-btn { background:linear-gradient(135deg,#FFD736,#f0c419); color:#130325; border:2px solid #FFD736; padding:12px 16px; border-radius:10px; font-weight:800; cursor:pointer; transition:all .2s ease; }
.add-to-cart-btn:hover { transform:translateY(-1px); box-shadow:0 10px 22px rgba(255,215,54,0.35); }

/* Description */
.product-description { background:#140826; padding:18px; border-radius:12px; margin:20px 0; box-shadow:0 10px 30px rgba(0,0,0,0.35); border:1px solid #2d1b4e; }
.product-description h2 { color:#F9F9F9; margin-bottom:10px; padding-bottom:8px; border-bottom:2px solid #FFD736; font-size:1.2rem; font-weight:800; }
.description-content { color:#e6e1ee; line-height:1.7; font-size:1rem; }

/* Reviews */
.product-reviews { background:#140826; padding:22px; border-radius:12px; margin:20px 0; box-shadow:0 10px 30px rgba(0,0,0,0.35); border:1px solid #2d1b4e; }
.product-reviews h2 { color:#F9F9F9; margin-bottom:16px; padding-bottom:8px; border-bottom:2px solid #FFD736; font-size:1.2rem; font-weight:800; }
.review { border-bottom:1px solid rgba(255,215,54,0.2); padding:15px 0; }
.review:last-child { border-bottom:none; }
.review h4 { color:#FFD736; margin-bottom:6px; font-size:.95rem; font-weight:800; }
.review p { margin-bottom:6px; color:#e6e1ee; font-size:.98rem; }
.review small { color:#bbb6c6; font-size:.82rem; }

.add-review { background:#140826; padding:22px; border-radius:12px; margin:20px 0; box-shadow:0 10px 30px rgba(0,0,0,0.35); border:1px solid #2d1b4e; }
.add-review h3 { color:#F9F9F9; margin-bottom:15px; padding-bottom:8px; border-bottom:2px solid #FFD736; font-size:1.2rem; font-weight:800; }
.review-form { display:flex; flex-direction:column; gap:15px; }
.form-group { display:flex; flex-direction:column; gap:6px; align-items:flex-start; text-align:left; }
.form-group label { font-weight:700; color:#d7d1e2; }
.review-form select, .review-form textarea { padding:12px; border:1px solid #2d1b4e; border-radius:10px; font-size:1rem; background:#0f0820; color:#F9F9F9; }
.review-form textarea { height:110px; resize:vertical; }
.star-rating { display:flex; flex-direction:row-reverse; gap:8px; justify-content:flex-start; margin:0; }
.star-rating input { display:none; }
.star-rating label { font-size:22px; color:#6b5c86; cursor:pointer; transition:transform .1s ease, color .15s ease; }
.star-rating label:hover, .star-rating label:hover ~ label { color:#FFD736; transform:translateY(-1px); }
.star-rating input:checked ~ label { color:#FFD736; }
.submit-review-btn { background:linear-gradient(135deg,#FFD736,#f0c419); color:#130325; border:2px solid #FFD736; padding:12px 16px; border-radius:10px; font-weight:800; cursor:pointer; transition:all .2s ease; align-self:flex-start; }
.submit-review-btn:hover { transform:translateY(-1px); box-shadow:0 10px 22px rgba(255,215,54,0.35); }

.message { padding:10px; border-radius:6px; margin-bottom:15px; }
.message.success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.message.error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }

/* Responsive */
@media (max-width: 1024px) { .product-detail { grid-template-columns:1fr; gap:20px; margin:20px 0; } }
@media (max-width: 768px) { .quantity-form { flex-direction:column; align-items:flex-start; } }
</style>

<main>
<div style="max-width: 1200px; margin: 0 auto; padding: 20px;">
<div class="product-detail">
    <div class="product-images">
        <?php if (!empty($images)): ?>
            <?php foreach ($images as $image): ?>
                <img src="<?php echo htmlspecialchars($image['image_url']); ?>" 
                     alt="<?php echo htmlspecialchars($product['name']); ?>">
            <?php endforeach; ?>
        <?php else: ?>
            <img src="<?php echo htmlspecialchars($product['image_url']); ?>" 
                 alt="<?php echo htmlspecialchars($product['name']); ?>">
        <?php endif; ?>
    </div>
    
    <div class="product-info">
        <h1><?php echo htmlspecialchars($product['name']); ?></h1>
        <p class="price">₱<?php echo number_format($product['price'], 2); ?></p>
        <p class="stock"><?php echo $product['stock_quantity']; ?> in stock</p>
        <p class="seller">Sold by: <?php echo htmlspecialchars($sellerDisplayName); ?></p>
        
        <?php if (!empty($categoryPath)): ?>
            <p class="category-info">Category: <?php echo htmlspecialchars(implode(' › ', $categoryPath)); ?></p>
        <?php endif; ?>
        
        <p class="rating">Rating: <?php echo number_format($product['rating'], 1); ?> (<?php echo $product['review_count']; ?> reviews)</p>
        
        <?php if ($product['stock_quantity'] > 0): ?>
            <form method="POST" action="cart.php" class="quantity-form">
                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                <label for="quantity">Quantity:</label>
                <input type="number" id="quantity" name="quantity" value="1" min="1" max="<?php echo $product['stock_quantity']; ?>">
                <button type="submit" name="add_to_cart" class="add-to-cart-btn">Add to Cart</button>
            </form>
        <?php else: ?>
            <p style="color: #dc3545; font-weight: 600;">Out of Stock</p>
        <?php endif; ?>
    </div>
</div>

<div class="product-description">
    <h2>Description</h2>
    <div class="description-content">
        <p><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
    </div>
</div>

<div class="product-reviews">
    <h2>Customer Reviews</h2>
    <?php if (empty($reviews)): ?>
        <p>No reviews yet.</p>
    <?php else: ?>
        <?php foreach ($reviews as $review): ?>
            <div class="review">
                <h4><?php echo htmlspecialchars($review['username']); ?></h4>
                <p>Rating: <?php echo $review['rating']; ?>/5</p>
                <p><?php echo htmlspecialchars($review['review_text']); ?></p>
                <p><small><?php echo date('F j, Y', strtotime($review['created_at'])); ?></small></p>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if (isLoggedIn()): ?>
    <div class="add-review">
        <h3>Add Your Review</h3>
        
        <!-- Display any messages -->
        <?php if (!empty($reviewMessage)): ?>
            <div class="message <?php echo strpos($reviewMessage, 'Error') !== false ? 'error' : 'success'; ?>">
                <?php echo htmlspecialchars($reviewMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($hasReviewed): ?>
            <div class="message success">You've already reviewed this product.</div>
        <?php elseif (!$canReview): ?>
            <div class="message error">You can only review this product after your order is delivered.</div>
        <?php else: ?>
            <form method="POST" class="review-form">
                <div class="form-group">
                    <label>Rating:</label>
                    <div class="star-rating" role="radiogroup" aria-label="Rating">
                        <input type="radio" id="star5" name="rating" value="5" required>
                        <label for="star5" title="5 stars">★</label>
                        <input type="radio" id="star4" name="rating" value="4">
                        <label for="star4" title="4 stars">★</label>
                        <input type="radio" id="star3" name="rating" value="3">
                        <label for="star3" title="3 stars">★</label>
                        <input type="radio" id="star2" name="rating" value="2">
                        <label for="star2" title="2 stars">★</label>
                        <input type="radio" id="star1" name="rating" value="1">
                        <label for="star1" title="1 star">★</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="review_text">Review:</label>
                    <textarea name="review_text" id="review_text" required 
                              placeholder="Share your experience with this product..."></textarea>
                </div>
                
                <button type="submit" name="submit_review" class="submit-review-btn">Submit Review</button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>
</div>
</main>

<?php require_once 'includes/footer.php'; ?>
