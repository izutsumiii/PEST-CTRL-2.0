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
    WHERE pr.product_id = ? 
    ORDER BY pr.created_at DESC
    LIMIT 20
");
$reviewsStmt->execute([$productId]);
$reviews = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);

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
if (isLoggedIn()) {
    $userId = $_SESSION['user_id'];
    $checkReviewed = $pdo->prepare("SELECT 1 FROM product_reviews WHERE user_id = ? AND product_id = ? LIMIT 1");
    $checkReviewed->execute([$userId, $productId]);
    $hasReviewed = (bool)$checkReviewed->fetchColumn();

    if (!$hasReviewed) {
        $checkDelivered = $pdo->prepare("SELECT 1
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE o.user_id = ? AND oi.product_id = ? AND o.status = 'delivered'
            LIMIT 1");
        $checkDelivered->execute([$userId, $productId]);
        $canReview = (bool)$checkDelivered->fetchColumn();
    }
}

// Handle review submission
$reviewMessage = '';
if (isset($_POST['submit_review']) && isLoggedIn()) {
    $rating = intval($_POST['rating']);
    $reviewText = sanitizeInput($_POST['review_text']);
    
    if (addProductReview($productId, $rating, $reviewText)) {
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
    const toast = document.createElement('div');
    toast.className = 'floating-toast';
    toast.style.cssText = 'position:fixed; left:50%; bottom:24px; transform:translateX(-50%); background:#ffffff; color:#130325; border:1px solid #e5e7eb; border-radius:12px; padding:12px 16px; z-index:10000; box-shadow:0 10px 30px rgba(0,0,0,0.2); max-width:90%; width:auto; text-align:center; font-weight:700;';
    toast.textContent = 'Review submitted successfully!';
    document.body.appendChild(toast);
    setTimeout(function(){
        toast.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
        toast.style.opacity = '0';
        toast.style.transform = 'translate(-50%, 10px)';
        setTimeout(function(){ if (toast.parentElement) toast.remove(); }, 400);
    }, 2500);
});
</script>
<?php endif; ?>

<style>
/* Base Styles */
body {
    background: #f0f2f5;
    color: #130325;
    min-height: 100vh;
}

/* Container backgrounds - different shade of white */
.product-info-section,
.seller-section,
.product-details-section,
.reviews-section {
    background: #ffffff !important;
}

main {
    background: transparent !important;
    padding: 8px 0 80px 0 !important; /* reduce top spacing */
    margin: 0 !important;
}

/* Container */
.product-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

/* Breadcrumb */
.breadcrumb-nav {
    background: rgba(30, 15, 50, 0.3);
    padding: 12px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 13px;
}

.breadcrumb-nav a {
    color: #FFD736;
    text-decoration: none;
    transition: color 0.3s;
}

.breadcrumb-nav a:hover {
    color: #e6c230;
}

.breadcrumb-nav span {
    color: rgba(19, 3, 37, 0.6);
    margin: 0 8px;
}

/* Main Product Grid */
.product-main {
    margin: 16px 0 32px 0; /* pull content up */
}

/* Image Gallery */
.image-gallery {
    flex: 0 0 300px;
    height: fit-content;
}

.main-image-container {
    background: #ffffff;
    border: 2px solid #666666;
    padding: 15px;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 300px;
    max-width: 300px;
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
    background: rgba(30, 15, 50, 0.4);
    border: 2px solid rgba(255, 215, 54, 0.15);
    padding: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
    aspect-ratio: 1;
    display: flex;
    align-items: center;
    justify-content: center;
}

.thumbnail:hover,
.thumbnail.active {
    border-color: #FFD736;
    transform: translateY(-2px);
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
    background: #ffffff;
    padding: 20px;
    display: flex;
    gap: 30px;
    align-items: flex-start;
}

/* Product Details */
.product-details {
    flex: 1;
}

.product-title {
    font-size: 28px;
    font-weight: 900;
    color: #130325;
    margin-bottom: 20px;
    line-height: 1.2;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Rating Section */
.rating-section {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid rgba(19, 3, 37, 0.2);
}

.stars {
    color: #FFD736;
    font-size: 16px;
    letter-spacing: 1px;
}

.rating-text {
    color: rgba(19, 3, 37, 0.7);
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
    background: rgba(255, 215, 54, 0.1);
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 15px;
    border: 1px solid rgba(255, 215, 54, 0.3);
}

.price-label {
    font-size: 12px;
    color: rgba(19, 3, 37, 0.6);
    margin-bottom: 5px;
}

.price-amount {
    font-size: 24px;
    font-weight: 900;
    color: #130325;
    line-height: 1;
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
    background: rgba(255, 255, 255, 0.5);
    border-radius: 6px;
    border: 1px solid rgba(19, 3, 37, 0.1);
}

.info-icon {
    color: #FFD736;
    font-size: 14px;
    width: 20px;
    text-align: center;
}

.info-label {
    color: rgba(19, 3, 37, 0.7);
    font-size: 11px;
    margin-right: 5px;
}

.info-value {
    color: #130325;
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
    margin: 20px 0;
    padding: 15px;
    background: rgba(255, 255, 255, 0.5);
    border-radius: 8px;
    border: 1px solid rgba(19, 3, 37, 0.1);
}

.quantity-selector {
    display: flex;
    align-items: center;
    gap: 15px;
}

.quantity-label {
    font-size: 12px;
    color: #130325;
    font-weight: 600;
}

.quantity-control {
    display: flex;
    align-items: center;
    border: 1px solid #130325;
    border-radius: 6px;
    overflow: hidden;
}

.qty-btn {
    background: #f8f9fa;
    border: none;
    padding: 8px 12px;
    cursor: pointer;
    font-size: 16px;
    font-weight: bold;
    color: #130325;
    transition: background 0.2s;
}

.qty-btn:hover {
    background: #e9ecef;
}

.qty-input {
    border: none;
    padding: 8px 12px;
    text-align: center;
    width: 60px;
    font-size: 14px;
    font-weight: 600;
    color: #130325;
    background: white;
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
    background: #FFD736;
    color: #130325;
    border: 2px solid #130325;
    padding: 12px 16px;
    border-radius: 6px; /* minimal rounding */
    font-size: 14px;
    font-weight: 600;
    text-transform: uppercase;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: auto;
    min-width: 140px;
    height: 48px;
    flex-shrink: 0;
}

.btn-add-to-cart:hover {
    background: #130325;
    color: #FFD736;
    transform: translateY(-1px);
}

.btn-buy-now {
    background: #130325;
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 6px; /* minimal rounding */
    font-size: 14px;
    font-weight: 600;
    text-transform: uppercase;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-left: 10px;
    width: auto;
    min-width: 160px;
}

.btn-buy-now:hover {
    background: #FFD736;
    color: #130325;
    transform: translateY(-1px);
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
    margin: 30px 0;
    padding: 20px;
    background: #ffffff;
}

.seller-profile {
    display: flex;
    align-items: center;
    gap: 15px;
}

.seller-avatar {
    width: 60px;
    height: 60px;
    background: #FFD736;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: #130325;
}

.seller-info h3 {
    margin: 0;
    font-size: 18px;
    color: #130325;
    font-weight: 700;
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
    margin: 30px 0;
    padding: 20px;
    background: #ffffff;
}

.product-category h3,
.product-description h3 {
    margin: 0 0 10px 0;
    font-size: 16px;
    color: #130325;
    font-weight: 700;
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
    margin: 30px 0;
    padding: 20px;
    background: #ffffff;
}

.reviews-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid rgba(19, 3, 37, 0.2);
}

.reviews-header h3 {
    margin: 0;
    font-size: 18px;
    color: #130325;
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

.review-item {
    padding: 15px 0;
    border-bottom: 1px solid rgba(19, 3, 37, 0.1);
}

.review-item:last-child {
    border-bottom: none;
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
    color: #FFD736;
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
    text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.3);
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
            </div>

            <!-- Review List -->
            <div class="reviews-list">
                <?php if (empty($reviews)): ?>
                    <div class="no-reviews">
                        <p>No reviews yet. Be the first to review this product!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($reviews as $review): ?>
                        <div class="review-item">
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
                            <div class="review-text"><?php echo nl2br(htmlspecialchars($review['review_text'])); ?></div>
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

                        <form method="POST" action="">
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
    
    // Make AJAX call
    fetch('ajax/buy-now.php', {
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
            // Redirect to checkout
            window.location.href = data.redirect_url;
        } else {
            // Show error message
            alert(data.message || 'Failed to process buy now request');
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
</script>

            </div>

        </div>

 <?php require_once 'includes/footer.php'; ?>
