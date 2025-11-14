<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

if (!isset($_GET['id'])) {
    header("Location: products.php");
    exit();
}

$productId = intval($_GET['id']);

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

// Get categories for breadcrumb
$allCategoriesStmt = $pdo->prepare("SELECT id, name, parent_id FROM categories");
$allCategoriesStmt->execute();
$allCategories = $allCategoriesStmt->fetchAll(PDO::FETCH_ASSOC);

$categoryLookup = [];
foreach ($allCategories as $cat) {
    $categoryLookup[$cat['id']] = $cat;
}

$categoryPath = [];
if (!empty($product['category_id'])) {
    $currentCatId = (int)$product['category_id'];
    $path = [];
    $visited = [];
    $maxDepth = 10;
    $depth = 0;
    
    while ($currentCatId && isset($categoryLookup[$currentCatId])) {
        if (isset($visited[$currentCatId])) break;
        $visited[$currentCatId] = true;
        $currentCat = $categoryLookup[$currentCatId];
        array_unshift($path, $currentCat['name']);
        $currentCatId = (int)$currentCat['parent_id'];
        $depth++;
        if ($depth >= $maxDepth) break;
    }
    $categoryPath = $path;
}

// Get images and reviews
$imagesStmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ?");
$imagesStmt->execute([$productId]);
$images = $imagesStmt->fetchAll(PDO::FETCH_ASSOC);

$reviewsStmt = $pdo->prepare("
    SELECT pr.*, u.username 
    FROM product_reviews pr 
    JOIN users u ON pr.user_id = u.id 
    WHERE pr.product_id = ? AND pr.is_hidden = FALSE
    ORDER BY pr.created_at DESC
    LIMIT 20
");
$reviewsStmt->execute([$productId]);
$reviews = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);

// If user is the reviewer, also fetch their hidden reviews
$userHiddenReviews = [];
if (isLoggedIn()) {
    $hiddenStmt = $pdo->prepare("
        SELECT pr.*, u.username 
        FROM product_reviews pr 
        JOIN users u ON pr.user_id = u.id 
        WHERE pr.product_id = ? AND pr.is_hidden = TRUE AND pr.user_id = ?
        ORDER BY pr.created_at DESC
    ");
    $hiddenStmt->execute([$productId, $_SESSION['user_id']]);
    $userHiddenReviews = $hiddenStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Merge user's hidden reviews with regular reviews
    $reviews = array_merge($reviews, $userHiddenReviews);
    
    // Sort by created_at DESC
    usort($reviews, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
}

// Calculate average rating
$avgRating = 0;
$reviewCount = count($reviews);
if ($reviewCount > 0) {
    $totalRating = array_sum(array_column($reviews, 'rating'));
    $avgRating = $totalRating / $reviewCount;
}

// Check if user can review
$canReview = false;
$hasReviewed = false;
$eligibleOrderId = null;

if (isLoggedIn()) {
    $userId = $_SESSION['user_id'];
    
    // Get a completed/delivered order for this product that hasn't been reviewed yet
    $checkOrder = $pdo->prepare("
        SELECT o.id 
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        LEFT JOIN product_reviews pr ON pr.order_id = o.id AND pr.product_id = oi.product_id AND pr.user_id = o.user_id
        WHERE o.user_id = ? 
          AND oi.product_id = ? 
          AND o.status IN ('delivered', 'completed')
          AND pr.id IS NULL
        ORDER BY o.completed_at DESC
        LIMIT 1
    ");
    $checkOrder->execute([$userId, $productId]);
    $eligibleOrderId = $checkOrder->fetchColumn();
    
    if ($eligibleOrderId) {
        $canReview = true;
        error_log("Product #{$productId} - User #{$userId} - Can review from order #{$eligibleOrderId}");
    } else {
        // Check if they've reviewed from ALL their orders
        $checkAnyOrder = $pdo->prepare("
            SELECT COUNT(*) FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE o.user_id = ? AND oi.product_id = ? AND o.status IN ('delivered', 'completed')
        ");
        $checkAnyOrder->execute([$userId, $productId]);
        $totalOrders = $checkAnyOrder->fetchColumn();
        
        if ($totalOrders > 0) {
            $hasReviewed = true;
            error_log("Product #{$productId} - User #{$userId} - Already reviewed all orders");
        }
    }
}

// Handle review submission
$reviewMessage = '';
if (isset($_POST['submit_review']) && isLoggedIn()) {
    $rating = intval($_POST['rating']);
    $reviewText = sanitizeInput($_POST['review_text']);
    
    if ($eligibleOrderId && addProductReview($productId, $rating, $reviewText, $eligibleOrderId)) {
        header("Location: product-detail.php?id=" . $productId . "&review_success=1");
        exit();
    } else {
        $reviewMessage = "Error submitting review.";
    }
}

if (isset($_GET['review_success']) && $_GET['review_success'] == '1') {
    $reviewMessage = "Review submitted successfully!";
}

$sellerDisplayName = trim($product['seller_first_name'] . ' ' . $product['seller_last_name']) ?: $product['seller_name'];

// Get seller's product count
$sellerProductCountStmt = $pdo->prepare("
    SELECT COUNT(*) as product_count 
    FROM products 
    WHERE seller_id = ?
");
$sellerProductCountStmt->execute([$product['seller_id']]);
$sellerStats = $sellerProductCountStmt->fetch();
$sellerProductCount = $sellerStats['product_count'];

require_once 'includes/header.php';
?>

<?php if (isset($_GET['review_success']) && $_GET['review_success'] == '1'): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    showReviewSuccessModal();
});
</script>
<?php endif; ?>

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
    color: var(--text-dark);
    min-height: 100vh;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}

.product-info-section,
.seller-section,
.product-details-section,
.reviews-section {
    background: var(--bg-white) !important;
}

main {
    background: transparent !important;
    padding: 0 0 60px 0 !important;
    margin: 0 !important;
}

main h1,
main h2,
main h3 {
    text-shadow: none !important;
}

.product-container {
    max-width: 1600px;
    margin: 0 auto;
    padding: 12px 20px;
}

/* Breadcrumb */
.breadcrumb-nav {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    padding: 8px 14px;
    border-radius: 8px;
    margin-bottom: 12px;
    font-size: 12px;
}

.breadcrumb-nav a {
    color: var(--primary-dark);
    text-decoration: none;
    transition: color 0.2s;
    font-weight: 500;
}

.breadcrumb-nav a:hover {
    color: var(--accent-yellow);
}

.breadcrumb-nav span {
    color: var(--text-light);
    margin: 0 6px;
}

/* Main Product Grid */
.product-main {
    margin: 12px 0 20px 0;
}

/* Image Gallery */
.image-gallery {
    flex: 0 0 300px;
    height: fit-content;
}

.main-image-container {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    padding: 12px;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 280px;
    max-width: 100%;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.main-image {
    max-width: 100%;
    max-height: 300px;
    width: auto;
    height: auto;
    object-fit: contain;
}

.thumbnail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
    gap: 10px;
}

.thumbnail {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    padding: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
    aspect-ratio: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
}

.thumbnail:hover,
.thumbnail.active {
    border-color: var(--primary-dark);
    box-shadow: 0 2px 6px rgba(19, 3, 37, 0.15);
    transform: translateY(-1px);
}

/* Promotional Banners */
.promotional-banners {
    display: flex;
    gap: 8px;
    margin-top: 15px;
    margin-bottom: 15px;
}

.banner-item {
    flex: 1;
    padding: 8px 12px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 600;
    text-align: center;
    justify-content: center;
}

.banner-spaylater {
    background: #007bff;
    color: white;
}

.banner-shipping {
    background: #17a2b8;
    color: white;
}

.banner-discount {
    background: #fd7e14;
    color: white;
}

.banner-item i {
    font-size: 10px;
}

.thumbnail img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

/* Product Info */
.product-info-section {
    background: var(--bg-white);
    padding: 14px 16px;
    display: flex;
    gap: 20px;
    align-items: flex-start;
    border: 1px solid var(--border-light);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    margin-bottom: 16px;
}

/* Product Details */
.product-details {
    flex: 1;
}

.product-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--text-dark);
    margin-bottom: 12px;
    line-height: 1.3;
    letter-spacing: -0.3px;
}

/* Rating Section */
.rating-section {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border-light);
}

.stars {
    color: var(--accent-yellow);
    font-size: 14px;
    letter-spacing: 0.5px;
}

.rating-text {
    color: var(--text-light);
    font-size: 12px;
}

.review-link {
    color: #130325;
    text-decoration: none;
    font-size: 16px;
    font-weight: 600;
    margin-left: 10px;
}

.review-link:hover {
    text-decoration: underline;
}

/* Price Section */
.price-section {
    background: rgba(19, 3, 37, 0.04);
    padding: 12px 14px;
    border-radius: 8px;
    margin-bottom: 12px;
    border: 1px solid var(--border-light);
}

.price-label {
    font-size: 11px;
    color: var(--text-light);
    margin-bottom: 4px;
    font-weight: 500;
}

.price-amount {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--primary-dark);
    line-height: 1.2;
}

/* Stock & Seller Info */
.info-grid {
    display: grid;
    gap: 10px;
    margin-bottom: 20px;
}

.info-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: var(--bg-light);
    border-radius: 6px;
    border: 1px solid var(--border-light);
}

.info-icon {
    color: var(--primary-dark);
    font-size: 13px;
    width: 18px;
    text-align: center;
}

.info-label {
    color: var(--text-light);
    font-size: 11px;
    margin-right: 4px;
    font-weight: 500;
}

.info-value {
    color: var(--text-dark);
    font-weight: 600;
    font-size: 11px;
}

.stock-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}

.stock-available {
    background: #d4edda;
    color: #155724;
}

.stock-low {
    background: #fff3cd;
    color: #856404;
}

.stock-out {
    background: #f8d7da;
    color: #721c24;
}

/* Quantity & Add to Cart */
.purchase-section {
    background: rgba(255, 255, 255, 0.03);
    padding: 20px;
    border-radius: 10px;
    border: 1px solid rgba(255, 215, 54, 0.15);
}

.quantity-selector {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 15px;
}

.quantity-label {
    font-weight: 700;
    color: #F9F9F9;
    font-size: 14px;
}

.quantity-control {
    display: flex;
    align-items: center;
    border: 1px solid rgba(255, 215, 54, 0.3);
    border-radius: 8px;
    overflow: hidden;
}

.qty-btn {
    background: rgba(255, 215, 54, 0.1);
    border: none;
    color: #FFD736;
    width: 40px;
    height: 40px;
    font-size: 20px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s;
}

.qty-btn:hover {
    background: rgba(255, 215, 54, 0.2);
}

.qty-input {
    width: 70px;
    height: 40px;
    text-align: center;
    border: none;
    background: rgba(30, 15, 50, 0.5);
    color: #F9F9F9;
    font-weight: 700;
    font-size: 16px;
}

/* Quantity Selector */
.quantity-section {
    margin: 16px 0;
    padding: 12px 14px;
    background: var(--bg-light);
    border-radius: 8px;
    border: 1px solid var(--border-light);
}

.quantity-selector {
    display: flex;
    align-items: center;
    gap: 12px;
}

.quantity-label {
    font-size: 12px;
    color: var(--text-dark);
    font-weight: 600;
}

.quantity-control {
    display: flex;
    align-items: center;
    border: 1px solid var(--border-light);
    border-radius: 6px;
    overflow: hidden;
    background: var(--bg-white);
}

.qty-btn {
    background: var(--bg-white);
    border: none;
    padding: 6px 10px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    color: var(--primary-dark);
    transition: background 0.2s;
}

.qty-btn:hover {
    background: var(--bg-light);
}

.qty-input {
    border: none;
    padding: 6px 10px;
    text-align: center;
    width: 50px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dark);
    background: var(--bg-white);
}

.stock-status {
    font-size: 11px;
    color: #28a745;
    font-weight: 600;
    background: rgba(40, 167, 69, 0.1);
    padding: 4px 8px;
    border-radius: 4px;
}

.action-buttons {
    display: flex;
    gap: 10px;
}

.btn-add-to-cart {
    background: var(--accent-yellow);
    color: var(--primary-dark);
    border: 1px solid var(--accent-yellow);
    padding: 10px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: auto;
    min-width: 130px;
    height: 40px;
    flex-shrink: 0;
}

.btn-add-to-cart:hover {
    background: #ffd020;
    border-color: #ffd020;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(255, 215, 54, 0.3);
}

.btn-buy-now {
    background: var(--primary-dark);
    color: var(--bg-white);
    border: 1px solid var(--primary-dark);
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin-left: 8px;
    width: auto;
    min-width: 140px;
    height: 40px;
}

.btn-buy-now:hover {
    background: #0a0118;
    border-color: #0a0118;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(19, 3, 37, 0.3);
}

/* Social Sharing & Favorites */
.social-section {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 30px;
    padding: 20px;
    background: rgba(255, 255, 255, 0.5);
    border-radius: 8px;
    border: 1px solid rgba(19, 3, 37, 0.1);
}

.social-sharing {
    display: flex;
    align-items: center;
    gap: 15px;
}

.social-label {
    font-size: 12px;
    color: #130325;
    font-weight: 600;
}

.social-buttons {
    display: flex;
    gap: 8px;
}

.social-btn {
    width: 32px;
    height: 32px;
    border: none;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    transition: all 0.3s ease;
}

.social-btn.facebook {
    background: #1877f2;
    color: white;
}

.social-btn.twitter {
    background: #1da1f2;
    color: white;
}

.social-btn.pinterest {
    background: #e60023;
    color: white;
}

.social-btn.messenger {
    background: #0084ff;
    color: white;
}

.social-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

.favorite-btn {
    background: transparent;
    border: 1px solid #dc3545;
    color: #dc3545;
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.favorite-btn:hover {
    background: #dc3545;
    color: white;
}

.favorite-btn.favorited {
    background: #dc3545;
    color: white;
}

.favorite-btn.favorited i {
    color: white;
}

/* Seller Section */
.seller-section {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 20px 0;
    padding: 14px 16px;
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.seller-profile {
    display: flex;
    align-items: center;
    gap: 12px;
}

.seller-avatar {
    width: 48px;
    height: 48px;
    background: var(--primary-dark);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: var(--bg-white);
    font-weight: 600;
}

.seller-info h3 {
    margin: 0;
    font-size: 15px;
    color: var(--text-dark);
    font-weight: 600;
}

.seller-name-row { display:flex; align-items:center; justify-content:space-between; gap:10px; }
.btn-visit { padding:6px 14px; min-width: 96px; font-size:0.85rem; border-radius:6px; background:transparent; color:#130325; border:1px solid #130325; font-weight:600; cursor:pointer; }
.btn-visit:hover { background:#130325; color:#ffffff; }

.seller-type {
    margin: 5px 0 0 0;
    font-size: 12px;
    color: #28a745;
    font-weight: 600;
}

.seller-details {
    display: flex;
    align-items: center;
    gap: 20px;
}

.seller-stats {
    display: flex;
    gap: 20px;
}

.stat-item {
    text-align: center;
}

.stat-number {
    display: block;
    font-size: 18px;
    font-weight: 700;
    color: #130325;
}

.stat-label {
    font-size: 11px;
    color: rgba(19, 3, 37, 0.7);
}

.view-store-btn {
    background: #007bff;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.3s;
}

.view-store-btn:hover {
    background: #0056b3;
}

/* Product Details Section */
.product-details-section {
    margin: 20px 0;
    padding: 14px 16px;
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.product-category h3,
.product-description h3 {
    margin: 0 0 10px 0;
    font-size: 1.1rem;
    color: var(--text-dark);
    font-weight: 600;
    padding: 6px 10px;
    background: rgba(19, 3, 37, 0.04);
    border-radius: 6px;
    display: inline-block;
}

.product-category p {
    margin: 0 0 20px 0;
    font-size: 14px;
    color: rgba(19, 3, 37, 0.8);
}

.description-content {
    font-size: 14px;
    color: #130325;
    line-height: 1.6;
}

/* Reviews Section */
.reviews-section {
    margin: 20px 0;
    padding: 14px 16px;
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.reviews-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border-light);
}

.reviews-header h3 {
    margin: 0;
    font-size: 1.1rem;
    color: var(--text-dark);
    font-weight: 600;
    font-weight: 700;
}

.overall-rating {
    display: flex;
    align-items: center;
    gap: 10px;
}

.rating-score {
    font-size: 32px;
    font-weight: 700;
    color: #130325;
}

.rating-stars {
    font-size: 18px;
    color: #FFD736;
}

.rating-text {
    font-size: 16px;
    color: #130325;
    font-weight: 600;
}

.reviews-list {
    margin-bottom: 30px;
}

/* No Reviews Message */
.no-reviews-container {
    text-align: center;
    padding: 60px 20px;
    background: rgba(255, 215, 54, 0.05);
    border-radius: 10px;
    border: 1px dashed rgba(255, 215, 54, 0.3);
}

.no-reviews-text {
    font-size: 18px;
    color: rgba(19, 3, 37, 0.5);
    font-weight: 600;
    margin: 0;
}

/* Review Filter */
.review-filter {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid rgba(19, 3, 37, 0.1);
}

.filter-label {
    font-size: 14px;
    font-weight: 600;
    color: #130325;
}

.star-filter-dropdown {
    padding: 8px 12px;
    border: 1px solid rgba(19, 3, 37, 0.2);
    border-radius: 6px;
    background: #ffffff;
    color: #130325;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.star-filter-dropdown:hover {
    border-color: #FFD736;
}

.star-filter-dropdown:focus {
    outline: none;
    border-color: #FFD736;
    box-shadow: 0 0 0 3px rgba(255, 215, 54, 0.1);
}

.review-item {
    padding: 15px 0;
    border-bottom: 1px solid rgba(19, 3, 37, 0.1);
    transition: opacity 0.3s ease;
}

.review-item:last-child {
    border-bottom: none;
}

.review-item.hidden {
    display: none;
}

/* Hidden Review Styling */
.review-item.hidden-review {
    opacity: 0.6;
    background: rgba(108, 117, 125, 0.05);
}

.hidden-review-message {
    padding: 12px 16px;
    background: rgba(108, 117, 125, 0.1);
    border-left: 3px solid #6c757d;
    border-radius: 4px;
    display: flex;
    align-items: center;
    gap: 10px;
    color: rgba(19, 3, 37, 0.5);
}

.hidden-review-message i {
    font-size: 16px;
    color: #6c757d;
}

.hidden-review-message em {
    font-size: 14px;
    font-style: italic;
}

/* Seller Reply Styling */
.seller-reply-container {
    margin-top: 15px;
    padding: 12px 16px;
    background: rgba(255, 215, 54, 0.08);
    border-left: 3px solid #FFD736;
    border-radius: 4px;
}

.seller-reply-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.seller-reply-header i {
    color: #FFD736;
    font-size: 14px;
}

.seller-label {
    font-weight: 700;
    color: #130325;
    font-size: 13px;
}

.reply-date {
    font-size: 11px;
    color: rgba(19, 3, 37, 0.4);
    margin-left: auto;
}

.seller-reply-text {
    color: #130325;
    font-size: 14px;
    line-height: 1.5;
}

/* Review Success Modal */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.35);
    z-index: 99999;
    align-items: center;
    justify-content: center;
}

.modal-dialog {
    width: 420px;
    max-width: 90vw;
    background: #ffffff;
    border: none;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    animation: modalSlideIn 0.3s ease;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modal-header {
    padding: 8px 12px;
    background: #130325;
    color: #F9F9F9;
    border-bottom: none;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-radius: 12px 12px 0 0;
}

.modal-title {
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 0.3px;
    margin: 0;
}

.modal-close {
    background: transparent;
    border: none;
    color: #F9F9F9;
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
    padding: 0;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: opacity 0.2s;
}

.modal-close:hover {
    opacity: 0.8;
}

.modal-body {
    padding: 16px;
    color: #130325;
    font-size: 12px;
}

.modal-actions {
    display: flex;
    gap: 8px;
    justify-content: center;
    padding: 0 12px 12px 12px;
}

.btn-primary-y {
    background: linear-gradient(135deg, #FFD736 0%, #FFC107 100%);
    color: #130325;
    border: none;
    border-radius: 8px;
    padding: 6px 16px;
    font-weight: 700;
    font-size: 12px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s ease;
}

.btn-primary-y:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(255, 215, 54, 0.3);
}

.review-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.reviewer-name {
    font-size: 14px;
    font-weight: 600;
    color: #130325;
}

.review-date {
    font-size: 12px;
    color: rgba(19, 3, 37, 0.6);
}

.review-rating {
    margin-bottom: 8px;
    font-size: 14px;
    color: #FFD736;
}

.review-text {
    font-size: 13px;
    color: #130325;
    line-height: 1.5;
}

.no-reviews {
    text-align: center;
    padding: 40px 20px;
    color: rgba(19, 3, 37, 0.6);
}

.review-form-container {
    margin-top: 30px;
    padding: 20px;
    background: rgba(255, 255, 255, 0.5);
    border: 1px solid rgba(19, 3, 37, 0.1);
}

.review-form-container h4 {
    margin: 0 0 15px 0;
    font-size: 16px;
    color: #130325;
    font-weight: 700;
}

/* Tabs Section */
.tabs-container {
    background: rgba(255, 255, 255, 0.8);
    overflow: hidden;
    margin-bottom: 30px;
}

.tabs-header {
    display: flex;
    border-bottom: 1px solid rgba(19, 3, 37, 0.2);
}

.tab-btn {
    flex: 1;
    background: transparent;
    border: none;
    color: rgba(19, 3, 37, 0.6);
    padding: 18px 20px;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s;
    border-bottom: 3px solid transparent;
}

.tab-btn:hover {
    color: #130325;
    background: rgba(255, 215, 54, 0.1);
}

.tab-btn.active {
    color: #FFD736;
    background: rgba(255, 215, 54, 0.1);
    border-bottom-color: #FFD736;
}

.tab-content {
    display: none;
    padding: 30px;
}

.tab-content.active {
    display: block;
}

/* Description */
.description-content {
    color: #130325;
    line-height: 1.8;
    font-size: 15px;
    max-height: 120px;
    overflow: hidden;
    transition: max-height 0.3s ease;
}

.description-content.expanded {
    max-height: none;
}

.read-more-btn {
    background: #130325;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    margin-top: 10px;
    transition: background 0.3s;
}

.read-more-btn:hover {
    background: #FFD736;
    color: #130325;
}

/* Reviews */
.review-summary {
    display: flex;
    gap: 40px;
    padding: 20px;
    background: rgba(255, 215, 54, 0.05);
    border-radius: 10px;
    margin-bottom: 30px;
}

.review-score {
    text-align: center;
}

.review-score-number {
    font-size: 48px;
    font-weight: 900;
    color: #FFD736;
    line-height: 1;
}

.review-score-stars {
    color: #FFD736;
    font-size: 18px;
    margin: 10px 0;
}

.review-score-count {
    color: rgba(19, 3, 37, 0.6);
    font-size: 13px;
}

.review-list {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.review-item {
    background: rgba(255, 255, 255, 0.03);
    padding: 20px;
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.05);
}

.review-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.reviewer-name {
    font-weight: 700;
    color: #130325;
    font-size: 15px;
}

.review-date {
    color: rgba(19, 3, 37, 0.5);
    font-size: 12px;
}

.review-rating {
    color: #FFD736;
    font-size: 16px;
    margin-bottom: 10px;
}

.review-text {
    color: #130325;
    line-height: 1.6;
    font-size: 14px;
}

/* Add Review Form */
.review-form-container {
    background: rgba(255, 215, 54, 0.05);
    padding: 25px;
    border-radius: 10px;
    border: 1px solid rgba(255, 215, 54, 0.2);
    margin-top: 30px;
}

.review-form-title {
    font-size: 18px;
    font-weight: 800;
    color: #130325;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    color: #130325;
    font-weight: 700;
    margin-bottom: 10px;
    font-size: 14px;
}

.star-rating {
    display: flex;
    flex-direction: row-reverse;
    gap: 10px;
    justify-content: flex-end;
}

.star-rating input {
    display: none;
}

.star-rating label {
    font-size: 32px;
    color: rgba(255, 215, 54, 0.3);
    cursor: pointer;
    transition: all 0.2s;
    text-shadow: none;
}

.star-rating label:hover,
.star-rating label:hover ~ label {
    color: #FFD736;
    transform: scale(1.1);
    text-shadow: 0 0 10px rgba(255, 215, 54, 0.8);
}

.star-rating input:checked ~ label {
    color: #FFD736;
    text-shadow: 0 0 10px rgba(255, 215, 54, 0.8);
}

.form-textarea {
    width: 100%;
    padding: 15px;
    background: rgba(255, 255, 255, 0.8);
    border: 1px solid rgba(19, 3, 37, 0.2);
    border-radius: 8px;
    color: #130325;
    font-size: 14px;
    resize: vertical;
    min-height: 120px;
}

.btn-submit-review {
    background: linear-gradient(135deg, #FFD736 0%, #f0c419 100%);
    color: #130325;
    border: none;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-submit-review:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(255, 215, 54, 0.4);
}

.alert {
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 14px;
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

/* Responsive */
@media (max-width: 1200px) {
    .product-main {
        grid-template-columns: 400px 1fr;
        gap: 30px;
    }
}

@media (max-width: 968px) {
    .product-main {
        grid-template-columns: 1fr;
    }
    
    .image-gallery {
        position: relative;
        top: 0;
    }
    
    .action-buttons {
        flex-direction: column;
    }
}
</style>

<main>
    <div class="product-container">

        <!-- Main Product Section -->
        <div class="product-main">
            <!-- Product Info -->
            <div class="product-info-section">
                <!-- Image Gallery -->
                <div class="image-gallery">
                    <div class="main-image-container">
                        <img id="mainImage" class="main-image" 
                             src="<?php echo htmlspecialchars(!empty($images) ? $images[0]['image_url'] : $product['image_url']); ?>" 
                             alt="<?php echo htmlspecialchars($product['name']); ?>">
                    </div>
                    
                    <?php if (!empty($images) && count($images) > 1): ?>
                    <div class="thumbnail-grid">
                        <?php foreach ($images as $index => $image): ?>
                            <div class="thumbnail <?php echo $index === 0 ? 'active' : ''; ?>" 
                                 onclick="changeImage('<?php echo htmlspecialchars($image['image_url']); ?>', this)">
                                <img src="<?php echo htmlspecialchars($image['image_url']); ?>" 
                                     alt="Product thumbnail">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Product Details -->
                <div class="product-details">
                <h1 class="product-title"><?php echo htmlspecialchars($product['name']); ?></h1>
                
                <!-- Rating -->
                <div class="rating-section">
                    <div class="stars">
                        <?php
                        $rating = round($avgRating * 2) / 2;
                        for ($i = 1; $i <= 5; $i++) {
                            if ($i <= floor($rating)) {
                                echo '★';
                            } elseif ($i == ceil($rating) && $rating - floor($rating) >= 0.5) {
                                echo '★';
                            } else {
                                echo '☆';
                            }
                        }
                        ?>
                    </div>
                    <span class="rating-text"><?php echo number_format($avgRating, 1); ?> out of 5</span>
                    <a href="#reviews-tab" class="review-link"><?php echo $reviewCount; ?> reviews</a>
                </div>

                <!-- Price -->
                <div class="price-section">
                    <div class="price-label">Price:</div>
                    <div class="price-amount">₱<?php echo number_format($product['price'], 2); ?></div>
                </div>

                <!-- Info Grid -->
                <div class="info-grid">
                    <div class="info-item">
                        <i class="fas fa-box info-icon"></i>
                        <span class="info-label">Stock:</span>
                        <span class="info-value">
                            <span class="stock-badge <?php 
                                echo $product['stock_quantity'] <= 0 ? 'stock-out' : 
                                     ($product['stock_quantity'] <= 10 ? 'stock-low' : 'stock-in'); 
                            ?>">
                                <?php 
                                if ($product['stock_quantity'] <= 0) {
                                    echo 'Out of Stock';
                                } elseif ($product['stock_quantity'] <= 10) {
                                    echo 'Low Stock (' . $product['stock_quantity'] . ' left)';
                                } else {
                                    echo 'In Stock (' . $product['stock_quantity'] . ' available)';
                                }
                                ?>
                            </span>
                        </span>
                    </div>
                    
                    <div class="info-item">
                        <i class="fas fa-store info-icon"></i>
                        <span class="info-label">Sold by:</span>
                        <span class="info-value"><?php echo htmlspecialchars($sellerDisplayName); ?></span>
                    </div>
                </div>

                <!-- Quantity Selector -->
                <?php if ($product['stock_quantity'] > 0): ?>
                <div class="quantity-section">
                    <div class="quantity-selector">
                        <span class="quantity-label">Quantity:</span>
                        <div class="quantity-control">
                            <button type="button" class="qty-btn" onclick="decreaseQty()">−</button>
                            <input type="number" id="quantity" class="qty-input" value="1" min="1" max="<?php echo $product['stock_quantity']; ?>" readonly>
                            <button type="button" class="qty-btn" onclick="increaseQty()">+</button>
                        </div>
                        <span class="stock-status">IN STOCK</span>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="action-buttons">
                    <?php if ($product['stock_quantity'] <= 0): ?>
                        <div class="stock-message">
                            <i class="fas fa-exclamation-triangle"></i>
                            Out of Stock - This product is currently unavailable.
                        </div>
                    <?php else: ?>
                        <button type="button" class="btn-add-to-cart" onclick="addToCart()" title="Add to Cart">
                            <i class="fas fa-shopping-cart"></i>
                            Add to Cart
                        </button>
                        <button type="button" class="btn-buy-now" onclick="buyNow()">
                            Buy Now
                        </button>
                    <?php endif; ?>
                </div>
                </div>
            </div>
        </div>

        <!-- Seller Section -->
        <div class="seller-section">
                <div class="seller-profile">
                <div class="seller-avatar">
                    <i class="fas fa-store"></i>
                </div>
                <div class="seller-info">
                    <div class="seller-name-row">
                        <h3 class="seller-name"><?php echo htmlspecialchars($sellerDisplayName); ?></h3>
                        <button class="btn-visit" onclick="window.location.href='seller.php?seller_id=<?php echo (int)$product['seller_id']; ?>'">Visit</button>
                    </div>
                    <p class="seller-type">Verified Seller</p>
                </div>
            </div>
            <div class="seller-details">
                <div class="seller-stats">
                    <div class="stat-item">
                        <span class="stat-number"><?php echo $sellerProductCount; ?></span>
                        <span class="stat-label">Products</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?php echo date('Y', strtotime($product['created_at'])); ?></span>
                        <span class="stat-label">Since</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number">Active</span>
                        <span class="stat-label">Status</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Product Details Section -->
        <div class="product-details-section">
            <div class="product-category">
                <h3>Product Category</h3>
                <p><?php echo htmlspecialchars($categoryPath[count($categoryPath) - 1] ?? 'General'); ?></p>
            </div>
            <div class="product-description">
                <h3>Product Description</h3>
                <div class="description-content" id="description-content">
                    <?php echo nl2br(htmlspecialchars($product['description'])); ?>
                </div>
                <button class="read-more-btn" onclick="toggleDescription()" id="read-more-btn">Read More</button>
            </div>
        </div>

        <!-- Reviews Section -->
        <div class="reviews-section" id="reviews-tab">
            <div class="reviews-header">
                <h3>Product Reviews</h3>
                <?php if (!empty($reviews)): ?>
                <div class="overall-rating">
                    <div class="rating-score"><?php echo number_format($avgRating, 1); ?></div>
                    <div class="rating-stars">
                        <?php
                        for ($i = 1; $i <= 5; $i++) {
                            echo $i <= round($avgRating) ? '★' : '☆';
                        }
                        ?>
                    </div>
                    <div class="rating-text">out of 5 stars</div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($reviews)): ?>
            <!-- Star Filter -->
            <div class="review-filter">
                <label for="starFilter" class="filter-label">Filter by rating:</label>
                <select id="starFilter" class="star-filter-dropdown" onchange="filterReviewsByStars()">
                    <option value="all">All Reviews (<?php echo count($reviews); ?>)</option>
                    <option value="5">5 Stars</option>
                    <option value="4">4 Stars</option>
                    <option value="3">3 Stars</option>
                    <option value="2">2 Stars</option>
                    <option value="1">1 Star</option>
                </select>
            </div>
            <?php endif; ?>

            <!-- Review List -->
            <div class="reviews-list">
                <?php if (empty($reviews)): ?>
                    <div class="no-reviews-container">
                        <p class="no-reviews-text">No Reviews Yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($reviews as $review): ?>
                        <div class="review-item <?php echo $review['is_hidden'] ? 'hidden-review' : ''; ?>" data-rating="<?php echo $review['rating']; ?>">
                            <div class="review-header">
                                <span class="reviewer-name"><?php echo htmlspecialchars($review['username']); ?></span>
                                <span class="review-date"><?php echo date('F j, Y', strtotime($review['created_at'])); ?></span>
                            </div>
                            <div class="review-rating">
                                <?php
                                for ($i = 1; $i <= 5; $i++) {
                                    echo $i <= $review['rating'] ? '★' : '☆';
                                }
                                ?>
                            </div>
                            
                            <?php if ($review['is_hidden']): ?>
                                <div class="hidden-review-message">
                                    <i class="fas fa-eye-slash"></i>
                                    <em>This review is hidden by the admin.</em>
                                </div>
                            <?php else: ?>
                                <div class="review-text"><?php echo nl2br(htmlspecialchars($review['review_text'])); ?></div>
                                
                                <?php if (!empty($review['seller_reply'])): ?>
                                    <div class="seller-reply-container">
                                        <div class="seller-reply-header">
                                            <i class="fas fa-store"></i>
                                            <span class="seller-label">Seller</span>
                                            <span class="reply-date"><?php echo date('F j, Y', strtotime($review['seller_replied_at'])); ?></span>
                                        </div>
                                        <div class="seller-reply-text"><?php echo nl2br(htmlspecialchars($review['seller_reply'])); ?></div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Add Review Form -->
            <?php if (isLoggedIn() && !$hasReviewed && $canReview && empty($_GET['review_success'])): ?>
                <div class="review-form-container">
                    <h4>Write a Review</h4>
                    
<?php if (!empty($reviewMessage) && strpos($reviewMessage, 'Error') !== false): ?>
    <div class="alert alert-error">
        <?php echo htmlspecialchars($reviewMessage); ?>
    </div>
<?php endif; ?>

                        <form method="POST" action="" id="reviewForm">
                            <div class="form-group">
                                <label class="form-label">Your Rating</label>
                                <div class="star-rating">
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
                                <div id="ratingWarning" style="display: none; color: var(--error-red); font-size: 12px; margin-top: 8px; padding: 8px; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 6px;">
                                    <i class="fas fa-exclamation-triangle"></i> Please select a rating before submitting your review.
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="review_text">Your Review</label>
                                <textarea name="review_text" id="review_text" class="form-textarea" 
                                          placeholder="Share your experience with this product..." required></textarea>
                            </div>
                            
                            <button type="submit" name="submit_review" class="btn-submit-review">
                                <i class="fas fa-paper-plane"></i> Submit
                            </button>
                        </form>
                </div>
            <?php endif; ?>
        </div>

    </div>
</main>

<script>
// Image Gallery
function changeImage(imageSrc, thumbnail) {
    document.getElementById('mainImage').src = imageSrc;
    
    // Update active thumbnail
    document.querySelectorAll('.thumbnail').forEach(thumb => {
        thumb.classList.remove('active');
    });
    thumbnail.classList.add('active');
}

// Quantity Controls
const maxQty = <?php echo $product['stock_quantity']; ?>;

function increaseQty() {
    const qtyInput = document.getElementById('quantity');
    const currentQty = parseInt(qtyInput.value);
    if (currentQty < maxQty) {
        qtyInput.value = currentQty + 1;
    }
}

function decreaseQty() {
    const qtyInput = document.getElementById('quantity');
    const currentQty = parseInt(qtyInput.value);
    if (currentQty > 1) {
        qtyInput.value = currentQty - 1;
    }
}

function addToCart() {
    const qtyInput = document.getElementById('quantity');
    const quantity = qtyInput.value;
    const productId = <?php echo $product['id']; ?>;
    const button = document.querySelector('.btn-add-to-cart');
    const originalText = button.innerHTML;
    
    // Validate quantity
    if (quantity <= 0) {
        alert('Please enter a valid quantity');
        return;
    }
    
    // Show loading state
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
    button.disabled = true;
    
    // Make AJAX call
    fetch('ajax/add-to-cart.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            product_id: productId,
            quantity: parseInt(quantity)
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message
            button.innerHTML = '<i class="fas fa-check"></i> Added!';
            button.style.background = '#28a745';
            
            // Update cart count if available
            const cartBadge = document.querySelector('.cart-notification');
            if (cartBadge && data.cartCount !== undefined) {
                cartBadge.textContent = data.cartCount;
                cartBadge.style.display = data.cartCount > 0 ? 'block' : 'none';
            }
            
            // Reset button after 2 seconds
            setTimeout(() => {
                button.innerHTML = originalText;
                button.disabled = false;
                button.style.background = '';
            }, 2000);
        } else {
            // Show error message
            alert(data.message || 'Failed to add item to cart');
            button.innerHTML = originalText;
            button.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
        button.innerHTML = originalText;
        button.disabled = false;
    });
}

// Review form validation
document.addEventListener('DOMContentLoaded', function() {
    const reviewForm = document.getElementById('reviewForm');
    if (reviewForm) {
        // Pre-select rating from URL parameter if present
        const urlParams = new URLSearchParams(window.location.search);
        const ratingParam = urlParams.get('rating');
        if (ratingParam) {
            const rating = parseInt(ratingParam);
            if (rating >= 1 && rating <= 5) {
                const ratingInput = reviewForm.querySelector('input[name="rating"][value="' + rating + '"]');
                if (ratingInput) {
                    ratingInput.checked = true;
                    // Update visual state of stars
                    const allLabels = reviewForm.querySelectorAll('.star-rating label');
                    allLabels.forEach((label, index) => {
                        const labelRating = parseInt(label.getAttribute('for').replace('star', ''));
                        if (labelRating <= rating) {
                            label.style.color = '#FFD736';
                            label.style.textShadow = '0 0 10px rgba(255, 215, 54, 0.8)';
                        } else {
                            label.style.color = 'rgba(255, 215, 54, 0.3)';
                            label.style.textShadow = 'none';
                        }
                    });
                }
            }
        }
        
        reviewForm.addEventListener('submit', function(e) {
            const ratingInputs = reviewForm.querySelectorAll('input[name="rating"]');
            let ratingSelected = false;
            
            ratingInputs.forEach(input => {
                if (input.checked) {
                    ratingSelected = true;
                }
            });
            
            if (!ratingSelected) {
                e.preventDefault();
                const warningDiv = document.getElementById('ratingWarning');
                if (warningDiv) {
                    warningDiv.style.display = 'block';
                    // Scroll to warning
                    warningDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    // Hide warning after 5 seconds
                    setTimeout(() => {
                        warningDiv.style.display = 'none';
                    }, 5000);
                }
                return false;
            }
        });
        
        // Hide warning when rating is selected
        const ratingInputs = reviewForm.querySelectorAll('input[name="rating"]');
        ratingInputs.forEach(input => {
            input.addEventListener('change', function() {
                const warningDiv = document.getElementById('ratingWarning');
                if (warningDiv) {
                    warningDiv.style.display = 'none';
                }
            });
        });
    }
});

function buyNow() {
    const qtyInput = document.getElementById('quantity');
    const quantity = qtyInput.value;
    const productId = <?php echo $product['id']; ?>;
    const button = document.querySelector('.btn-buy-now');
    const originalText = button.innerHTML;
    
    // Validate quantity
    if (quantity <= 0) {
        alert('Please enter a valid quantity');
        return;
    }
    
    // Check if user is logged in
    <?php if (!isLoggedIn()): ?>
        window.location.href = 'login.php';
        return;
    <?php endif; ?>
    
    // Show loading state
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    button.disabled = true;
    
    // Make AJAX call - checkout only this item (no cart items)
    fetch('ajax/buy-now.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin', // Include session cookie
        body: JSON.stringify({
            product_id: productId,
            quantity: parseInt(quantity),
            include_cart: false
        })
    })
    .then(response => {
        console.log('Buy Now Response Status:', response.status);
        console.log('Buy Now Response Headers:', response.headers);
        
        // Check if response is actually JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            return response.text().then(text => {
                console.error('Buy Now - Non-JSON Response:', text);
                throw new Error('Server returned invalid response. Please try again.');
            });
        }
        
        return response.json();
    })
    .then(data => {
        console.log('Buy Now Response Data:', data);
        if (data.success) {
            // Redirect directly to checkout (no confirmation needed)
            window.location.href = data.redirect_url || 'paymongo/multi-seller-checkout.php?buy_now=1';
        } else {
            // Show styled error message instead of plain alert
            console.error('Buy Now Failed:', data);
            let errorMessage = data.message || 'Failed to process buy now request';
            
            // Include debug info if available
            if (data.debug) {
                console.error('Buy Now Debug Info:', data.debug);
                if (data.debug.addToCartResult) {
                    errorMessage += '\n\nDebug: ' + JSON.stringify(data.debug.addToCartResult, null, 2);
                }
            }
            
            showBuyNowError(errorMessage);
            button.innerHTML = originalText;
            button.disabled = false;
        }
    })
    .catch(error => {
        console.error('Buy Now Fetch Error:', error);
        console.error('Error Details:', error.message, error.stack);
        showBuyNowError('Network error. Please check your connection and try again.');
        button.innerHTML = originalText;
        button.disabled = false;
    });
}

function toggleFavorite() {
    const favoriteBtn = document.querySelector('.favorite-btn');
    const icon = favoriteBtn.querySelector('i');
    
    if (favoriteBtn.classList.contains('favorited')) {
        favoriteBtn.classList.remove('favorited');
        icon.className = 'far fa-heart';
    } else {
        favoriteBtn.classList.add('favorited');
        icon.className = 'fas fa-heart';
    }
}

function toggleDescription() {
    const descriptionContent = document.getElementById('description-content');
    const readMoreBtn = document.getElementById('read-more-btn');
    
    if (descriptionContent.classList.contains('expanded')) {
        descriptionContent.classList.remove('expanded');
        readMoreBtn.textContent = 'Read More';
    } else {
        descriptionContent.classList.add('expanded');
        readMoreBtn.textContent = 'Read Less';
    }
}


// Tabs
function openTab(tabName) {
    // Hide all tabs
    const tabContents = document.querySelectorAll('.tab-content');
    tabContents.forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active from all buttons
    const tabButtons = document.querySelectorAll('.tab-btn');
    tabButtons.forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab
    document.getElementById(tabName).classList.add('active');
    event.target.classList.add('active');
}

// Buy Now function is now implemented above with AJAX

// Add to Cart form handler (if form exists)
document.getElementById('addToCartForm')?.addEventListener('submit', function(e) {
    e.preventDefault(); // Prevent form submission
    addToCart(); // Use our new addToCart function
});

// Smooth scroll to reviews when clicking review link
document.querySelector('.review-link')?.addEventListener('click', function(e) {
    e.preventDefault();
    document.querySelector('.tab-btn:nth-child(2)').click();
    document.getElementById('reviews').scrollIntoView({ behavior: 'smooth', block: 'start' });
});

// Auto-scroll to reviews section if hash is present
if (window.location.hash === '#reviews-tab') {
    // Wait for page to load completely
    setTimeout(function() {
        const reviewsSection = document.getElementById('reviews-tab');
        if (reviewsSection) {
            reviewsSection.scrollIntoView({ 
                behavior: 'smooth', 
                block: 'start',
                inline: 'nearest'
            });
        }
    }, 100);
}

// Show styled Buy Now error message
function showBuyNowError(message) {
    // Remove any existing error notification
    const existing = document.getElementById('buy-now-error-notification');
    if (existing) {
        existing.remove();
    }
    
    // Create error notification
    const errorDiv = document.createElement('div');
    errorDiv.id = 'buy-now-error-notification';
    errorDiv.className = 'buy-now-error-notification';
    errorDiv.innerHTML = `
        <div class="error-icon">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="error-content">
            <strong>Buy Now Failed</strong>
            <p>${message}</p>
        </div>
        <button type="button" class="error-close" onclick="this.parentElement.remove()">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    // Find the action-buttons container to place notification below it
    const actionButtons = document.querySelector('.action-buttons');
    
    if (actionButtons && actionButtons.parentElement) {
        // Insert right after the action-buttons container (below the buttons)
        actionButtons.insertAdjacentElement('afterend', errorDiv);
    } else {
        // Fallback: Find Buy Now button and insert after its parent
        const buyNowButton = document.querySelector('.btn-buy-now');
        if (buyNowButton && buyNowButton.parentElement) {
            buyNowButton.parentElement.insertAdjacentElement('afterend', errorDiv);
        } else {
            // Last fallback: Insert at the top of main content
            const mainContent = document.querySelector('main') || document.body;
            mainContent.insertBefore(errorDiv, mainContent.firstChild);
        }
    }
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (errorDiv.parentElement) {
            errorDiv.style.opacity = '0';
            errorDiv.style.transform = 'translateY(-10px)';
            setTimeout(() => errorDiv.remove(), 300);
        }
    }, 5000);
}

// Filter reviews by star rating
function filterReviewsByStars() {
    const filterValue = document.getElementById('starFilter').value;
    const reviewItems = document.querySelectorAll('.review-item[data-rating]');
    
    reviewItems.forEach(item => {
        const rating = item.getAttribute('data-rating');
        
        if (filterValue === 'all') {
            item.classList.remove('hidden');
            item.style.display = '';
        } else if (rating === filterValue) {
            item.classList.remove('hidden');
            item.style.display = '';
        } else {
            item.classList.add('hidden');
            item.style.display = 'none';
        }
    });
}
</script>

<style>
.buy-now-error-notification {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 18px;
    background: #fff5f5;
    border-left: 4px solid #ff6b6b;
    border-radius: 6px;
    box-shadow: 0 2px 8px rgba(255, 107, 107, 0.1);
    margin: 15px auto;
    max-width: 600px;
    position: relative;
    animation: slideInDown 0.3s ease-out;
    z-index: 1000;
    transition: all 0.3s ease;
    width: 100%;
    box-sizing: border-box;
    text-align: left;
}

.buy-now-error-notification .error-icon {
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #ff6b6b;
    border-radius: 50%;
    color: white;
    font-size: 18px;
}

.buy-now-error-notification .error-content {
    flex: 1;
    min-width: 0;
}

.buy-now-error-notification .error-content strong {
    display: block;
    font-size: 15px;
    color: #c92a2a;
    margin-bottom: 3px;
    font-weight: 600;
}

.buy-now-error-notification .error-content p {
    margin: 0;
    color: #862e2e;
    font-size: 14px;
    line-height: 1.4;
}

.buy-now-error-notification .error-close {
    flex-shrink: 0;
    background: transparent;
    border: none;
    color: #c92a2a;
    font-size: 16px;
    cursor: pointer;
    padding: 4px;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.2s ease;
    opacity: 0.7;
}

.buy-now-error-notification .error-close:hover {
    background: rgba(201, 42, 42, 0.1);
    opacity: 1;
}

@keyframes slideInDown {
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

        </div>

<!-- Review Success Modal -->
<div id="reviewSuccessModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" style="display: none;">
    <div class="modal-dialog" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div class="modal-title">Review Submitted!</div>
            <button type="button" class="modal-close" onclick="closeReviewSuccessModal()">×</button>
        </div>
        <div class="modal-body">
            <div style="text-align: center; padding: 10px 0;">
                <i class="fas fa-check-circle" style="font-size: 48px; color: #28a745; margin-bottom: 12px;"></i>
                <p style="margin: 0; color: #130325; font-size: 14px; line-height: 1.5; font-weight: 500;">
                    Thank you for your review! Your feedback helps other customers make informed decisions.
                </p>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-primary-y" onclick="closeReviewSuccessModal()">
                <i class="fas fa-check"></i> Got it!
            </button>
        </div>
    </div>
</div>

<script>
function showReviewSuccessModal() {
    const modal = document.getElementById('reviewSuccessModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        
        // Clean URL by removing review_success parameter
        const url = new URL(window.location.href);
        url.searchParams.delete('review_success');
        window.history.replaceState({}, document.title, url.toString());
    }
}

function closeReviewSuccessModal() {
    const modal = document.getElementById('reviewSuccessModal');
    if (modal) {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        
        // Scroll to reviews section after closing
        const reviewsTab = document.getElementById('reviews-tab');
        if (reviewsTab) {
            reviewsTab.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('reviewSuccessModal');
        if (modal && modal.style.display === 'flex') {
            closeReviewSuccessModal();
        }
    }
});

// Close modal when clicking outside
document.addEventListener('click', function(e) {
    const modal = document.getElementById('reviewSuccessModal');
    if (modal && e.target === modal) {
        closeReviewSuccessModal();
    }
});
</script>

 <?php require_once 'includes/footer.php'; ?>
