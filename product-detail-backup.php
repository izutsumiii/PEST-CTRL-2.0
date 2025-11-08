<?php
require_once 'includes/header.php';
require_once 'config/database.php';

if (!isset($_GET['id'])) {
    header("Location: products.php");
    exit();
}

$productId = intval($_GET['id']);
$product = getProductById($productId);
// Get product category information
// Get product category information
$categoryStmt = $pdo->prepare("
    SELECT c.id, c.name, c.parent_id
    FROM categories c
    WHERE c.id = (SELECT category_id FROM products WHERE id = ?)
");
$categoryStmt->execute([$productId]);
$category = $categoryStmt->fetch(PDO::FETCH_ASSOC);

// Build category breadcrumb
$categoryPath = [];
if ($category) {
    $currentCat = $category;
    $categoryPath[] = $currentCat['name'];
    
    // Traverse up to get all parent categories
    while ($currentCat['parent_id']) {
        $parentStmt = $pdo->prepare("SELECT id, name, parent_id FROM categories WHERE id = ?");
        $parentStmt->execute([$currentCat['parent_id']]);
        $currentCat = $parentStmt->fetch(PDO::FETCH_ASSOC);
        if ($currentCat) {
            array_unshift($categoryPath, $currentCat['name']);
        }
    }
}
$categoryStmt->execute([$productId]);
$category = $categoryStmt->fetch(PDO::FETCH_ASSOC);

// Build category breadcrumb
$categoryBreadcrumb = [];
if ($category) {
    // Get full category path
    $currentCat = $category;
    $categoryBreadcrumb[] = $currentCat;
    
    // Traverse up to get all parent categories
    while ($currentCat['parent_id']) {
        $parentStmt = $pdo->prepare("SELECT id, name, parent_id FROM categories WHERE id = ?");
        $parentStmt->execute([$currentCat['parent_id']]);
        $currentCat = $parentStmt->fetch(PDO::FETCH_ASSOC);
        if ($currentCat) {
            array_unshift($categoryBreadcrumb, $currentCat);
        }
    }
}
$images = getProductImages($productId);
$reviews = getProductReviews($productId);
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
if (!$product) {
    echo "<p>Product not found.</p>";
    require_once 'includes/footer.php';
    exit();
}
?>

<h1><?php echo $product['name']; ?></h1>

<div class="product-detail">
    <div class="product-images">
        <?php if (!empty($images)): ?>
            <?php foreach ($images as $image): ?>
                <img src="<?php echo $image['image_url']; ?>" alt="<?php echo $product['name']; ?>">
            <?php endforeach; ?>
        <?php else: ?>
            <img src="<?php echo $product['image_url']; ?>" alt="<?php echo $product['name']; ?>">
        <?php endif; ?>
    </div>
    
    <div class="product-info">
        <p class="price">₱<?php echo $product['price']; ?></p>
        <p class="stock"><?php echo $product['stock_quantity']; ?> in stock</p>
        <p class="seller">Sold by: <?php echo $product['seller_name']; ?></p>
        <?php if (!empty($categoryPath)): ?>
        <p class="category-info">Category: <?php echo htmlspecialchars(implode(' › ', $categoryPath)); ?></p>
        <?php endif; ?>
        <p class="rating">Rating: <?php echo $product['rating']; ?> (<?php echo $product['review_count']; ?> reviews)</p>
        
        <?php if ($product['stock_quantity'] > 0): ?>
            <form method="POST" action="cart.php">
                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                <label for="quantity">Quantity:</label>
                <input type="number" id="quantity" name="quantity" value="1" min="1" max="<?php echo $product['stock_quantity']; ?>">
                <button type="submit" name="add_to_cart">Add to Cart</button>
            </form>
        <?php else: ?>
            <p>Out of Stock</p>
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
                <h4><?php echo $review['username']; ?></h4>
                <p>Rating: <?php echo $review['rating']; ?>/5</p>
                <p><?php echo $review['review_text']; ?></p>
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
            <?php echo $reviewMessage; ?>
        <?php endif; ?>
        
        <form method="POST" action="">
            <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
            <label for="rating">Rating:</label>
            <select id="rating" name="rating" required>
                <option value="1">1 Star</option>
                <option value="2">2 Stars</option>
                <option value="3">3 Stars</option>
                <option value="4">4 Stars</option>
                <option value="5">5 Stars</option>
            </select>
            <label for="review_text">Review:</label>
            <textarea id="review_text" name="review_text" required></textarea>
            <button type="submit" name="submit_review">Submit Review</button>
        </form>
    </div>
<?php else: ?>
    <p><a href="login.php">Login</a> to leave a review.</p>
<?php endif; ?>
<style>
    /* Category Info */
.category-info {
    color: #7f8c8d;
    font-style: italic;
    font-size: 1rem;
    padding: 8px 15px;
    background-color: #f0f3f5;
    border-radius: 25px;
    display: inline-block;
    width: fit-content;
    border-left: 4px solid #667eea;
}

@media (max-width: 768px) {
    .category-info {
        font-size: 0.95rem;
        padding: 6px 12px;
    }
}
/* Category Breadcrumb */
.product-category-breadcrumb {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 15px 25px;
    border-radius: 10px;
    margin-bottom: 30px;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}

.breadcrumb-label {
    font-weight: 700;
    color: #ffffff;
    font-size: 1rem;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.breadcrumb-separator {
    color: #ffffff;
    font-size: 1.2rem;
    opacity: 0.7;
}

.breadcrumb-link {
    color: #ffffff;
    text-decoration: none;
    font-weight: 600;
    font-size: 1rem;
    padding: 5px 12px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 20px;
    transition: all 0.3s ease;
}

.breadcrumb-link:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
}

.breadcrumb-link:last-child {
    background: rgba(255, 255, 255, 0.3);
    font-weight: 700;
}

/* Responsive breadcrumb */
@media (max-width: 768px) {
    .product-category-breadcrumb {
        padding: 12px 20px;
        gap: 8px;
    }
    
    .breadcrumb-label {
        font-size: 0.9rem;
    }
    
    .breadcrumb-link {
        font-size: 0.9rem;
        padding: 4px 10px;
    }
}

@media (max-width: 480px) {
    .product-category-breadcrumb {
        padding: 10px 15px;
    }
    
    .breadcrumb-label {
        width: 100%;
        margin-bottom: 5px;
    }
}
/* Product Detail Page Styles */

/* General Styling */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    line-height: 1.6;
    color: #333;
    background-color: #f8f9fa;
}

.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

/* Product Title */
h1 {
    font-size: 2.5rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 30px;
    text-align: center;
    border-bottom: 3px solid #3498db;
    padding-bottom: 15px;
}

/* Product Detail Layout */
.product-detail {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 40px;
    margin-bottom: 40px;
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
}

/* Product Images */
.product-images {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.product-images img {
    width: 100%;
    height: auto;
    max-height: 400px;
    object-fit: cover;
    border-radius: 8px;
    border: 2px solid #e9ecef;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.product-images img:hover {
    transform: scale(1.05);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

/* Product Info */
.product-info {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.product-info p {
    font-size: 1.1rem;
    margin-bottom: 10px;
}

.price {
    font-size: 2rem !important;
    font-weight: bold;
    color: #e74c3c;
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.stock {
    color: #27ae60;
    font-weight: 600;
    padding: 8px 15px;
    background-color: #d5f4e6;
    border-radius: 25px;
    display: inline-block;
    width: fit-content;
}

.seller {
    color: #7f8c8d;
    font-style: italic;
}

.rating {
    color: #f39c12;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.rating::before {
    content: "⭐";
    font-size: 1.2rem;
}

/* Form Styling */
form {
    background: #f8f9fa;
    padding: 25px;
    border-radius: 10px;
    border: 2px solid #e9ecef;
    margin-top: 20px;
}

label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #2c3e50;
}

input[type="number"],
input[type="text"],
textarea,
select {
    width: 100%;
    padding: 12px;
    border: 2px solid #ddd;
    border-radius: 6px;
    font-size: 1rem;
    margin-bottom: 15px;
    transition: border-color 0.3s ease, box-shadow 0.3s ease;
}

input[type="number"]:focus,
input[type="text"]:focus,
textarea:focus,
select:focus {
    outline: none;
    border-color: #3498db;
    box-shadow: 0 0 10px rgba(52, 152, 219, 0.3);
}

textarea {
    height: 120px;
    resize: vertical;
}

button {
    background: linear-gradient(135deg, #3498db, #2980b9);
    color: white;
    padding: 15px 30px;
    border: none;
    border-radius: 8px;
    font-size: 1.1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 1px;
}

button:hover {
    background: linear-gradient(135deg, #2980b9, #21618c);
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(52, 152, 219, 0.4);
}

button:active {
    transform: translateY(0);
}

/* Out of Stock */
.product-info p:last-child {
    color: #e74c3c;
    font-weight: bold;
    background-color: #fadbd8;
    padding: 15px;
    border-radius: 8px;
    text-align: center;
    font-size: 1.2rem;
}

/* Description Section */
/* Description Section */
.product-description {
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    margin-bottom: 30px;
}

.product-description h2 {
    color: #2c3e50;
    margin-bottom: 20px;
    font-size: 1.8rem;
    border-bottom: 2px solid #3498db;
    padding-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.product-description h2::before {
    content: "📋";
    font-size: 1.5rem;
}

.description-content {
    background: #f8f9fa;
    padding: 25px;
    border-radius: 8px;
    border-left: 4px solid #3498db;
    max-height: 300px;
    overflow-y: auto;
    overflow-x: hidden;
}

.description-content p {
    font-size: 1.05rem;
    line-height: 1.8;
    color: #444;
    text-align: justify;
    margin: 0;
    white-space: pre-wrap;
    word-wrap: break-word;
}

/* Custom Scrollbar for Description */
.description-content::-webkit-scrollbar {
    width: 8px;
}

.description-content::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.description-content::-webkit-scrollbar-thumb {
    background: #3498db;
    border-radius: 10px;
}

.description-content::-webkit-scrollbar-thumb:hover {
    background: #2980b9;
}

/* Firefox scrollbar */
.description-content {
    scrollbar-width: thin;
    scrollbar-color: #3498db #f1f1f1;
}

/* Responsive Description */
@media (max-width: 768px) {
    .product-description {
        padding: 20px;
    }
    
    .product-description h2 {
        font-size: 1.5rem;
    }
    
    .description-content {
        padding: 20px;
        max-height: 250px;
    }
    
    .description-content p {
        font-size: 1rem;
        text-align: left;
    }
}

@media (max-width: 480px) {
    .product-description {
        padding: 15px;
    }
    
    .product-description h2 {
        font-size: 1.3rem;
    }
    
    .description-content {
        padding: 15px;
        max-height: 200px;
    }
    
    .description-content p {
        font-size: 0.95rem;
    }
}

/* Reviews Section */
.product-reviews {
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    margin-bottom: 30px;
}

.product-reviews h2 {
    color: #2c3e50;
    margin-bottom: 25px;
    font-size: 1.8rem;
    border-bottom: 2px solid #3498db;
    padding-bottom: 10px;
}

.review {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 10px;
    border-left: 4px solid #3498db;
    margin-bottom: 20px;
    transition: transform 0.3s ease;
}

.review:hover {
    transform: translateX(5px);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
}

.review h4 {
    color: #2c3e50;
    font-size: 1.2rem;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.review h4::before {
    content: "👤";
    font-size: 1rem;
}

.review p:first-of-type {
    color: #f39c12;
    font-weight: 600;
    margin-bottom: 10px;
}

.review p:first-of-type::before {
    content: "⭐ ";
}

.review p:nth-of-type(2) {
    font-size: 1rem;
    line-height: 1.6;
    margin-bottom: 10px;
    color: #555;
}

.review small {
    color: #7f8c8d;
    font-style: italic;
}

/* Add Review Section */
.add-review {
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    margin-bottom: 30px;
}

.add-review h3 {
    color: #2c3e50;
    margin-bottom: 20px;
    font-size: 1.5rem;
    border-bottom: 2px solid #3498db;
    padding-bottom: 10px;
}

/* Success/Error Messages */
.add-review p {
    padding: 15px;
    border-radius: 8px;
    margin-top: 15px;
    font-weight: 600;
}

.add-review p:contains("successfully") {
    background-color: #d5f4e6;
    color: #27ae60;
    border: 2px solid #27ae60;
}

.add-review p:contains("Error") {
    background-color: #fadbd8;
    color: #e74c3c;
    border: 2px solid #e74c3c;
}

/* Login Link */
body > p:last-child {
    text-align: center;
    font-size: 1.1rem;
    padding: 20px;
    background: white;
    border-radius: 10px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
}

body > p:last-child a {
    color: #3498db;
    text-decoration: none;
    font-weight: 600;
    transition: color 0.3s ease;
}

body > p:last-child a:hover {
    color: #2980b9;
    text-decoration: underline;
}

/* Responsive Design */
@media (max-width: 768px) {
    .container {
        padding: 15px;
    }
    
    h1 {
        font-size: 2rem;
        margin-bottom: 20px;
    }
    
    .product-detail {
        grid-template-columns: 1fr;
        gap: 25px;
        padding: 20px;
    }
    
    .price {
        font-size: 1.8rem !important;
    }
    
    .product-images img {
        max-height: 300px;
    }
    
    button {
        padding: 12px 25px;
        font-size: 1rem;
    }
    
    .product-description,
    .product-reviews,
    .add-review {
        padding: 20px;
    }
    
    .product-description h2,
    .product-reviews h2 {
        font-size: 1.5rem;
    }
}

@media (max-width: 480px) {
    h1 {
        font-size: 1.7rem;
    }
    
    .product-detail,
    .product-description,
    .product-reviews,
    .add-review {
        padding: 15px;
    }
    
    .price {
        font-size: 1.5rem !important;
    }
    
    .review {
        padding: 15px;
    }
    
    button {
        padding: 10px 20px;
        font-size: 0.95rem;
    }
}

/* Animation for page load */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.product-detail,
.product-description,
.product-reviews,
.add-review {
    animation: fadeInUp 0.6s ease-out;
}


</style>


<?php require_once 'includes/footer.php'; ?>