<?php
require_once 'includes/header.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

// Get search query and filters
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$category = isset($_GET['category']) ? intval($_GET['category']) : 0;
$minPrice = isset($_GET['min_price']) ? floatval($_GET['min_price']) : 0;
$maxPrice = isset($_GET['max_price']) ? floatval($_GET['max_price']) : 0;
$minRating = isset($_GET['min_rating']) ? floatval($_GET['min_rating']) : 0;
$maxRating = isset($_GET['max_rating']) ? floatval($_GET['max_rating']) : 5;
$minReviews = isset($_GET['min_reviews']) ? intval($_GET['min_reviews']) : 0;
$inStock = isset($_GET['in_stock']) ? true : false;
$sort = isset($_GET['sort']) ? sanitizeInput($_GET['sort']) : 'created_at';
$order = isset($_GET['order']) ? sanitizeInput($_GET['order']) : 'DESC';

// If the user picked a specific star value (1-4) but no max_rating, treat it as an exact bucket [X, X+1)
if ($minRating > 0 && $minRating < 5 && !isset($_GET['max_rating'])) {
    $maxRating = min(5, $minRating + 0.999);
}
// If 5 stars selected and no max provided, cap to 5 exactly
if ($minRating >= 5 && !isset($_GET['max_rating'])) {
    $minRating = 5;
    $maxRating = 5;
}

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = isset($_GET['per_page']) ? max(1, min(60, intval($_GET['per_page']))) : 24;

$filters = [
    'category' => $category,
    'min_price' => $minPrice,
    'max_price' => $maxPrice,
    'in_stock' => $inStock,
    'min_rating' => $minRating,
    'max_rating' => $maxRating,
    'min_reviews' => $minReviews,
    'sort' => $sort,
    'order' => $order,
    'page' => $page,
    'per_page' => $perPage
];

// Get products based on search and filters
if (function_exists('searchProductsWithRatingEnhanced')) {
    $products = searchProductsWithRatingEnhanced($search, $filters);
    $totalCount = function_exists('countProductsWithRatingEnhanced') ? countProductsWithRatingEnhanced($search, $filters) : count(searchProductsWithRating($search, $filters));
} else {
    $allProducts = searchProductsWithRating($search, $filters);
    $totalCount = count($allProducts);
    $offset = ($page - 1) * $perPage;
    $products = array_slice($allProducts, $offset, $perPage);
}
$totalPages = max(1, (int)ceil($totalCount / $perPage));

// Get categories for filter
$stmt = $pdo->prepare("SELECT * FROM categories");
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get selected categories
$selectedCategories = [];
if (isset($_GET['categories']) && is_array($_GET['categories'])) {
    $selectedCategories = array_map('intval', $_GET['categories']);
} elseif (isset($_GET['category']) && $_GET['category'] > 0) {
    $selectedCategories = [intval($_GET['category'])];
}

function getAllChildCategories($pdo, $parentIds) {
    if (empty($parentIds)) return [];
    $placeholders = str_repeat('?,', count($parentIds) - 1) . '?';
    $stmt = $pdo->prepare("SELECT id FROM categories WHERE parent_id IN ($placeholders)");
    $stmt->execute($parentIds);
    $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($children)) {
        return array_merge($children, getAllChildCategories($pdo, $children));
    }
    return [];
}
// Get selected categories FIRST
$selectedCategories = [];
if (isset($_GET['categories']) && is_array($_GET['categories'])) {
    $selectedCategories = array_map('intval', $_GET['categories']);
} elseif (isset($_GET['category']) && $_GET['category'] > 0) {
    $selectedCategories = [intval($_GET['category'])];
}

// Build complete category list including children
if (!empty($selectedCategories)) {
    $allCategoryIds = array_merge($selectedCategories, getAllChildCategories($pdo, $selectedCategories));
    $filters['categories'] = array_unique($allCategoryIds);
} else {
    $filters['categories'] = [];
}

// NOW fetch products with the updated filters
if (function_exists('searchProductsWithRatingEnhanced')) {
    $products = searchProductsWithRatingEnhanced($search, $filters);
    $totalCount = function_exists('countProductsWithRatingEnhanced') ? countProductsWithRatingEnhanced($search, $filters) : count(searchProductsWithRating($search, $filters));
} else {
    // Fallback: Manual filtering if function doesn't support categories
    $allProducts = searchProductsWithRating($search, $filters);
    
    // Filter by categories if selected
    if (!empty($filters['categories'])) {
        $allProducts = array_filter($allProducts, function($product) use ($filters) {
            return in_array($product['category_id'], $filters['categories']);
        });
    }
    
    $totalCount = count($allProducts);
    $offset = ($page - 1) * $perPage;
    $products = array_slice($allProducts, $offset, $perPage);
}
$totalPages = max(1, (int)ceil($totalCount / $perPage));

if (!function_exists('resolveProductImageUrl')) {
    function resolveProductImageUrl($url) {
        $url = trim((string)$url);
        if ($url === '') return '';
        if (preg_match('#^https?://#i', $url)) return $url;
        if (strpos($url, 'assets/') === 0) return str_replace(' ', '%20', $url);
        return 'assets/uploads/' . str_replace(' ', '%20', $url);
    }
}
?>

<style>
    .category-item {
    margin: 5px 0;
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 4px 0;
}

.category-item label {
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 0.75rem;
    color: #130325;
    flex: 1;
    padding: 2px 4px;
    border-radius: 4px;
    transition: background 0.2s;
}

.category-item label:hover {
    background: rgba(255, 215, 54, 0.1);
}

.category-name {
    color: #130325;
    font-weight: 600;
    font-size: 0.8rem;
    padding: 4px 0;
}
/* EXACT MATCH TO INDEX.PHP STYLING */

/* Body background - Light like index.php */
body {
    background: #f0f2f5;
    min-height: 100vh;
    color: #130325;
    margin: 0;
    padding: 0;
    text-align: center;
}

/* Override text-align for header and navigation */
.site-header,
.site-header *,
.navbar,
.navbar *,
.dropdown,
.dropdown * {
    text-align: left !important;
}

/* Products Layout */
.products-layout {
    display: grid;
    grid-template-columns: 300px 1fr;
    align-items: start;
    gap: 30px;
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 20px;
}

.products-main {
    min-width: 0;
    grid-column: 2;
    grid-row: 1;
    margin-top: 20px;
}

.products-list h2 {
    color: #130325;
    margin: 0 0 25px 0;
    font-size: 1.8rem;
    font-weight: 700;
    text-align: center;
}

.applied-filters {
    margin: 20px 0;
    text-align: center;
}

.filter-tag {
    background: #FFD736;
    color: #130325;
    padding: 8px 16px;
    border-radius: 25px;
    font-size: 0.8rem;
    margin: 5px 8px;
    display: inline-block;
    font-weight: 600;
}

/* Products Grid - EXACT MATCH TO INDEX.PHP */
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

/* Product Card - EXACT MATCH TO INDEX.PHP */
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

/* Product Checkbox - EXACT MATCH */
.product-checkbox {
    position: absolute !important;
    top: 15px !important;
    right: 15px !important;
    background: rgba(255, 215, 54, 0.15) !important;
    padding: 8px 12px !important;
    border: 1px solid rgba(255, 215, 54, 0.4) !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    z-index: 2 !important;
    transition: all 0.3s ease !important;
}

.product-checkbox:hover {
    background: rgba(255, 215, 54, 0.25) !important;
}

.product-checkbox input[type="checkbox"] {
    accent-color: #FFD736 !important;
    transform: scale(1.3) !important;
}

.product-checkbox label {
    margin: 0 !important;
    color: #130325 !important;
    font-size: 0.8rem !important;
    font-weight: 600 !important;
    text-transform: uppercase !important;
}

/* Product Name - EXACT MATCH */
.products-layout .product-card .product-name,
.products-list .product-card .product-name {
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
.products-layout .product-card .price,
.products-list .product-card .price {
    color: #212529 !important;
    font-weight: 800 !important;
    font-size: 1.5rem !important;
    margin: 5px 0 !important;
}

/* Rating - EXACT MATCH */
.products-layout .product-card .rating,
.products-list .product-card .rating {
    color: #FFD736 !important;
    font-size: 1.3rem !important;
    margin: 5px 0 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
}

.products-layout .product-card .rating-text,
.products-list .product-card .rating-text {
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
    gap: 8px !important;
    margin-top: auto !important;
    margin-bottom: 0 !important;
    padding-top: 8px !important;
}

.cart-actions {
    display: flex !important;
    gap: 8px !important;
    align-items: center !important;
}

.btn-cart-icon {
    background: #FFD736 !important;
    color: #130325 !important;
    border: 2px solid #FFD736 !important; /* match yellow */
    border-radius: 4px !important; /* slight rounding */
    padding: 4px !important;
    cursor: pointer !important;
    font-size: 10px !important;
    width: 24px !important;
    height: 24px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    transition: all 0.3s ease !important;
    flex-shrink: 0 !important;
}

.btn-cart-icon:hover {
    background: #ffde62 !important; /* lighter yellow hover */
    border-color: #FFD736 !important;
    color: #130325 !important;
    transform: translateY(-2px) !important;
}

.btn-buy-now {
    background: #130325 !important;
    color: white !important;
    border: 2px solid #FFD736 !important;
    padding: 4px 10px !important;
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
    flex: 1 !important;
    height: 24px !important;
}

.btn-buy-now:hover {
    background: #FFD736 !important;
    color: #130325 !important;
    transform: translateY(-2px) !important;
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
    padding: 1px 4px !important;
    font-size: 0.6rem !important;
    width: 100% !important;
    justify-content: center !important;
    border-radius: 2px !important;
    margin: 0 !important;
    line-height: 1.2 !important;
    min-height: auto !important;
    height: auto !important;
}

.btn-details i {
    font-size: 0.6rem !important;
    margin-right: 2px !important;
}

.btn-details:hover {
    background: #FFD736 !important;
    color: #130325 !important;
    transform: translateY(-2px) !important;
    text-decoration: none !important;
}

.btn-cart {
    background: #007bff !important;
    color: #130325 !important;
}

.btn-cart:hover {
    background: #0056b3 !important;
    transform: translateY(-2px) !important;
}

/* Filters Section */
.filters {
    background: transparent;
    border: none;
    border-radius: 0;
    padding: 10px;
    margin: 80px 0 20px -20px;
    box-shadow: none;
    color: #130325;
    grid-column: 1;
    grid-row: 1;
    max-width: 300px;
    align-self: start;
    position: sticky;
    top: 100px;
    max-height: none;
    overflow-y: visible;
}

.filters h2 {
    color: #130325;
    margin-bottom: 20px;
    font-size: 1.1rem;
    font-weight: 500;
    text-align: center;
    border-bottom: 2px solid #130325;
    padding-bottom: 10px;
}

.filter-group {
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid rgba(255, 215, 54, 0.1);
}

.filter-group:last-child {
    border-bottom: none;
}

.filter-group h3 {
    color: #130325;
    font-size: 0.85rem;
    font-weight: 500;
    margin-bottom: 12px;
}

.filter-group select,
.filter-group input[type="number"] {
    width: 100%;
    padding: 6px 10px;
    border: 1px solid rgba(255, 215, 54, 0.3);
    border-radius: 0;
    background: rgba(26, 10, 46, 0.4);
    color: #130325;
    font-size: 0.8rem;
}

.filter-group select:focus,
.filter-group input:focus {
    outline: none;
    border-color: #FFD736;
    background: rgba(26, 10, 46, 0.6);
}

.price-range {
    display: flex;
    align-items: center;
    gap: 10px;
}

.price-range input {
    flex: 1;
}

.price-range span {
    color: #130325;
    font-weight: 500;
}

.rating-filter {
    background: rgba(19, 3, 37, 0.08); /* dark purple transparent */
    padding: 8px;
    border-radius: 0;
}

.rating-options label {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 8px 0;
    cursor: pointer;
    color: #130325;
    font-size: 0.8rem;
}

.rating-options input[type="radio"] {
    accent-color: #FFD736;
}

.rating-stars {
    font-size: 1.1rem;
}

.rating-stars .star-filled {
    color: #FFD736;
}

.rating-stars .star-empty {
    color: #2d1b4e; /* dark purple for better contrast */
}

.filter-group label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    color: #130325;
    font-size: 0.8rem;
}

.filter-group input[type="checkbox"] {
    accent-color: #FFD736;
}

.filter-actions {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 20px;
}

.filter-actions button,
.filter-group button {
    background: #130325;
    color: white;
    border: 2px solid #130325; /* match background */
    padding: 4px 10px;
    border-radius: 4px; /* slight rounding */
    font-size: 0.75rem;
    font-weight: 700;
    cursor: pointer;
    text-transform: uppercase;
    transition: all 0.3s ease;
}

.filter-actions button:hover,
.filter-group button:hover {
    background: #FFD736;
    color: #130325;
    transform: translateY(-2px);
}

.clear-filters {
    text-align: center;
    color: white;
    text-decoration: none;
    font-size: 0.75rem;
    padding: 4px 10px;
    border: 2px solid #130325; /* match background */
    border-radius: 4px; /* slight rounding */
    background: #130325;
    font-weight: 700;
    text-transform: uppercase;
    transition: all 0.3s ease;
    display: block;
}

.clear-filters:hover {
    background: #dc3545;
    color: #130325;
    transform: translateY(-2px);
}

/* Sorting Container */
.sorting-container {
    background: #f0f2f5;
    padding: 15px 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(19, 3, 37, 0.1);
    border: 2px solid #f0f2f5; /* match background */
    border-radius: 8px; /* slight rounding */
}

.sorting-wrapper {
    display: flex;
    align-items: center;
    gap: 15px;
}

.sort-label {
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
    border: 2px solid #130325; /* match background */
    padding: 8px 12px;
    border-radius: 6px; /* slight rounding */
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    min-width: 160px; /* widened for better readability */
}

.sort-select:focus,
.order-select:focus {
    outline: none;
    background: #FFD736;
    color: #130325;
}

/* Category Tree */
.category-tree {
    max-height: 300px;
    overflow-y: auto;
}

.category-item {
    margin: 5px 0;
    display: flex;
    align-items: center;
    gap: 5px;
}

.category-toggle {
    cursor: pointer;
    font-size: 12px;
    width: 20px;
    display: inline-block;
    color: #130325;
    user-select: none;
}

.category-children {
    margin-left: 10px;
}

.category-item label {
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 0.75rem;
    color: #130325;
}

.category-name {
    color: #130325;
    font-weight: 500;
    font-size: 0.8rem;
}

/* Compare Bar - EXACT MATCH TO INDEX.PHP */
.compare-bar {
    display: none;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: #FFD736;
    box-shadow: 0 -5px 20px rgba(0, 0, 0, 0.3);
    z-index: 1000;
    padding: 20px;
    border-top: 3px solid #130325;
}

.compare-content {
    max-width: 1400px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.compare-content h4 {
    margin: 0;
    color: #ffffff;
    font-size: 1.1rem;
    font-weight: 700;
}

#compare-items {
    display: flex;
    gap: 12px;
    flex: 1;
    flex-wrap: wrap;
}

.compare-item {
    display: flex;
    align-items: center;
    gap: 10px;
    background: rgba(255, 215, 54, 0.15);
    padding: 10px 15px;
    border: 2px solid #FFD736;
}

.compare-item img {
    width: 35px;
    height: 35px;
    object-fit: cover;
}

.compare-item span {
    color: #ffffff;
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-weight: 600;
}

.remove-compare {
    background: none;
    border: none;
    color: #dc3545;
    font-size: 20px;
    cursor: pointer;
    padding: 0;
    width: 25px;
    height: 25px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.3s ease;
}

.remove-compare:hover {
    background: rgba(220, 53, 69, 0.2);
}

.compare-actions {
    display: flex;
    gap: 12px;
}

.btn-compare {
    background: #FFD736 !important;
    color: #130325 !important;
    font-weight: 700;
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
    padding: 10px 20px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-compare:hover:not(:disabled) {
    background: #e6c230 !important;
    color: #130325 !important;
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
    transform: translateY(-1px);
}

.btn-compare:disabled {
    background: #6c757d;
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

.btn-clear {
    background: #dc3545;
    color: #130325 !important;
}

.btn-clear:hover {
    background: #c82333;
}

/* Responsive */
@media (max-width: 1200px) {
    .products-layout {
        grid-template-columns: 250px 1fr;
        gap: 20px;
    }
}

@media (max-width: 768px) {
    .products-layout {
        grid-template-columns: 1fr;
    }
    
    .filters {
        margin: 20px 0;
        position: relative;
        top: 0;
        max-height: none;
    }
    
    .products-main {
        margin-top: 20px;
    }
    
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

<!-- Compare Products Bar -->
<div id="compare-bar" class="compare-bar">
    <div class="compare-content">
        <h4>Compare (<span id="compare-count">0</span>/4)</h4>
        <div id="compare-items"></div>
        <div class="compare-actions">
            <button id="compare-btn" class="btn btn-compare" disabled>Compare</button>
            <button id="clear-compare" class="btn btn-clear">Clear</button>
        </div>
    </div>
</div>

<div class="products-layout">
    <!-- Filters Sidebar -->
    <div class="filters">
        <h2>FILTERS</h2>
        <form method="GET">
            <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
            
            <div class="filter-group">
                <h3>Category</h3>
                <div class="category-tree">
                    <?php
                    function buildCategoryTree($categories) {
                        $tree = [];
                        $lookup = [];
                        foreach ($categories as $category) {
                            $lookup[$category['id']] = $category;
                            $lookup[$category['id']]['children'] = [];
                        }
                        foreach ($categories as $category) {
                            if ($category['parent_id']) {
                                $lookup[$category['parent_id']]['children'][] = &$lookup[$category['id']];
                            } else {
                                $tree[] = &$lookup[$category['id']];
                            }
                        }
                        return $tree;
                    }
                    
                    function renderCategoryTree($categories, $selectedCategories = [], $level = 0) {
                        foreach ($categories as $category) {
                            $hasChildren = !empty($category['children']);
                            $isSelected = in_array($category['id'], $selectedCategories);
                            $indent = $level * 20;
                            $isMainCategory = $category['parent_id'] == 0;
                            
                            // Clean category name - remove emojis and decode HTML entities
                            $displayName = preg_replace('/^[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}\s]+/u', '', (string)$category['name']);
                            $displayName = html_entity_decode($displayName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            $displayName = trim($displayName);
                            
                            echo '<div class="category-item" style="margin-left: ' . $indent . 'px;">';
                            
                            if ($hasChildren) {
                                echo '<span id="toggle-' . $category['id'] . '" class="category-toggle" onclick="toggleCategoryChildren(' . $category['id'] . ')">▶</span>';
                            } else {
                                // Add spacing for items without children to align with those that have the toggle
                                echo '<span style="display: inline-block; width: 20px;"></span>';
                            }
                            
                            if ($isMainCategory) {
                                echo '<span class="category-name" style="font-weight: 600; cursor: pointer;" onclick="toggleCategoryChildren(' . $category['id'] . ')">';
                                echo htmlspecialchars($displayName);
                                echo '</span>';
                            } else {
                                echo '<label style="cursor: pointer; flex: 1;">';
                                echo '<input type="checkbox" ';
                                echo 'name="categories[]" ';
                                echo 'value="' . $category['id'] . '" ';
                                echo 'onchange="handleCategorySelection(this)" ';
                                if ($isSelected) echo 'checked ';
                                echo 'data-parent="' . $category['parent_id'] . '" ';
                                echo 'style="margin-right: 6px;">';
                                echo htmlspecialchars($displayName);
                                echo '</label>';
                            }
                            
                            echo '</div>';
                            
                            if ($hasChildren) {
                                echo '<div id="children-' . $category['id'] . '" class="category-children" style="display: ' . ($isSelected || hasSelectedChild($category['children'], $selectedCategories) ? 'block' : 'none') . ';">';
                                renderCategoryTree($category['children'], $selectedCategories, $level + 1);
                                echo '</div>';
                            }
                        }
                    }
                    
                    // Helper function to check if any child is selected
                    function hasSelectedChild($children, $selectedCategories) {
                        foreach ($children as $child) {
                            if (in_array($child['id'], $selectedCategories)) {
                                return true;
                            }
                            if (!empty($child['children']) && hasSelectedChild($child['children'], $selectedCategories)) {
                                return true;
                            }
                        }
                        return false;
                    }
                    
                    $categoryTree = buildCategoryTree($categories);
                    renderCategoryTree($categoryTree, $selectedCategories);
                    ?>
                </div>
            </div>
            
            <div class="filter-group">
                <h3>Price Range</h3>
                <div class="price-range">
                    <input type="number" name="min_price" placeholder="Min" value="<?php echo $minPrice; ?>" step="0.01" min="0">
                    <span>to</span>
                    <input type="number" name="max_price" placeholder="Max" value="<?php echo $maxPrice; ?>" step="0.01" min="0">
                </div>
                <button type="submit" style="width: 100%; margin-top: 10px;">Apply</button>
            </div>
            
            <div class="filter-group">
                <h3>Rating</h3>
                <div class="rating-filter">
                    <div class="rating-options">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <label>
                            <input type="radio" name="min_rating" value="<?php echo $i; ?>" 
                            <?php echo $minRating == $i ? 'checked' : ''; ?>
                            onchange="this.form.submit()">
                                <span class="rating-stars">
                                    <?php echo str_repeat('<span class=\'star-filled\'>★</span>', $i)
                                        . str_repeat('<span class=\'star-empty\'>☆</span>', 5 - $i); ?>
                                </span>
                            </label>
                        <?php endfor; ?>
                        <label>
                        <input type="radio" name="min_rating" value="0" 
                        <?php echo $minRating == 0 ? 'checked' : ''; ?>
                        onchange="this.form.submit()">
                            Any Rating
                        </label>
                    </div>
                </div>
            </div>
            
            
            
            <div class="filter-actions">
                <a href="products.php" class="clear-filters">CLEAR ALL</a>
            </div>
        </form>
    </div>
    
    <!-- Products Main -->
    <div class="products-main">
        <!-- Sorting Container -->
        <div class="sorting-container">
            <form method="GET" class="sorting-form">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                <input type="hidden" name="categories" value="<?php echo htmlspecialchars(implode(',', $selectedCategories)); ?>">
                <input type="hidden" name="min_price" value="<?php echo $minPrice; ?>">
                <input type="hidden" name="max_price" value="<?php echo $maxPrice; ?>">
                <input type="hidden" name="min_rating" value="<?php echo $minRating; ?>">
                <input type="hidden" name="min_reviews" value="<?php echo $minReviews; ?>">
                <input type="hidden" name="in_stock" value="<?php echo $inStock ? '1' : ''; ?>">
                
                <div class="sorting-wrapper">
                    <label class="sort-label">SORT BY</label>
                    <select name="sort" class="sort-select" onchange="this.form.submit()">
                        <option value="created_at" <?php echo $sort == 'created_at' ? 'selected' : ''; ?>>Newest</option>
                        <option value="price" <?php echo $sort == 'price' ? 'selected' : ''; ?>>Price</option>
                        <option value="name" <?php echo $sort == 'name' ? 'selected' : ''; ?>>Name</option>
                        <option value="rating" <?php echo $sort == 'rating' ? 'selected' : ''; ?>>Rating</option>
                        <option value="review_count" <?php echo $sort == 'review_count' ? 'selected' : ''; ?>>Most Reviews</option>
                    </select>
                    <select name="order" class="order-select" onchange="this.form.submit()">
                        <option value="DESC" <?php echo $order == 'DESC' ? 'selected' : ''; ?>>Descending</option>
                        <option value="ASC" <?php echo $order == 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                    </select>
                </div>
            </form>
        </div>
        
        <div class="products-list">
            <?php if ($search !== ''): ?>
                <h2>Search Results (<?php echo count($products); ?> found)</h2>
            <?php endif; ?>
            
            <div class="applied-filters">
                <?php if ($minRating > 0): ?>
                    <?php if ($minRating >= 5): ?>
                        <span class="filter-tag">Rating: 5 stars</span>
                    <?php else: ?>
                        <span class="filter-tag">Rating: <?php echo (int)$minRating; ?>–<?php echo (int)$minRating + 1; ?> stars</span>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if ($minReviews > 0): ?>
                    <span class="filter-tag">Reviews: <?php echo $minReviews; ?>+</span>
                <?php endif; ?>
                
                <?php if ($inStock): ?>
                   
                <?php endif; ?>
            </div>
            
            <?php if (empty($products)): ?>
                <p style="text-align: center; color: #6c757d; padding: 40px;">No products found matching your criteria.</p>
            <?php else: ?>
                <div class="products-grid">
                    <?php foreach ($products as $product): 
                        $listImg = resolveProductImageUrl($product['image_url'] ?? '');
                    ?>
                        <div class="product-card">
                            <div class="product-checkbox">
                                <input type="checkbox"
                                       class="compare-checkbox"
                                       data-product-id="<?php echo $product['id']; ?>"
                                       data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                                       data-product-price="<?php echo $product['price']; ?>"
                                       data-product-image="<?php echo htmlspecialchars($listImg); ?>"
                                       data-product-rating="<?php echo $product['rating'] ?? 0; ?>"
                                       data-product-reviews="<?php echo $product['review_count'] ?? 0; ?>"
                                       onchange="toggleCompare(this)">
                                <label>Compare</label>
                            </div>
                            <img loading="lazy" src="<?php echo htmlspecialchars($listImg ?: 'assets/images/placeholder.jpg'); ?>" 
                                 alt="<?php echo htmlspecialchars($product['name']); ?>">
                            <h3 class="product-name"><?php echo htmlspecialchars($product['name']); ?></h3>
                            <p class="price">₱<?php echo number_format($product['price'], 2); ?></p>
                            <div class="rating">
                                <?php
                                    $rating = $product['rating'] ?? 0;
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
                                <span class="rating-text">(<?php echo number_format($product['rating'] ?? 0, 1); ?>)</span>
                            </div>
                            <div class="stock"><?php echo $product['stock_quantity']; ?> in stock</div>
                            <div class="product-actions">
                                <a href="product-detail.php?id=<?php echo $product['id']; ?>" 
                                   class="btn btn-details">
                                    <i class="fas fa-eye"></i> View Details
                                </a>
                                <div class="cart-actions">
                                    <button onclick="addToCart(<?php echo $product['id']; ?>, 1)" 
                                            class="btn-cart-icon" 
                                            data-product-id="<?php echo $product['id']; ?>"
                                            title="Add to Cart">
                                        <i class="fas fa-shopping-cart"></i>
                                    </button>
                                    <button onclick="handleBuyNow(<?php echo $product['id']; ?>, 1)" 
                                            class="btn btn-buy-now" 
                                            data-product-id="<?php echo $product['id']; ?>">
                                        Buy Now
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
// Category hierarchy management
function toggleCategoryChildren(categoryId) {
    const childrenDiv = document.getElementById(`children-${categoryId}`);
    const toggleIcon = document.getElementById(`toggle-${categoryId}`);
    
    if (childrenDiv) {
        if (childrenDiv.style.display === 'none' || childrenDiv.style.display === '') {
            childrenDiv.style.display = 'block';
            if (toggleIcon) toggleIcon.textContent = '▼';
        } else {
            childrenDiv.style.display = 'none';
            if (toggleIcon) toggleIcon.textContent = '▶';
        }
    }
}

function handleCategorySelection(checkbox) {
    // Get the form
    const form = checkbox.closest('form');
    if (!form) return;
    
    // Get all checked category checkboxes
    const checkedBoxes = form.querySelectorAll('input[name="categories[]"]:checked');
    
    // Create URL parameters
    const url = new URL(window.location.href);
    
    // Remove existing category parameters
    url.searchParams.delete('categories[]');
    
    // Add all checked categories
    checkedBoxes.forEach(box => {
        url.searchParams.append('categories[]', box.value);
    });
    
    // Preserve search parameter
    const searchInput = form.querySelector('input[name="search"]');
    if (searchInput && searchInput.value) {
        url.searchParams.set('search', searchInput.value);
    }
    
    // Preserve other filter parameters
    const minPrice = form.querySelector('input[name="min_price"]');
    const maxPrice = form.querySelector('input[name="max_price"]');
    const minRating = form.querySelector('input[name="min_rating"]:checked');
    
    if (minPrice && minPrice.value) {
        url.searchParams.set('min_price', minPrice.value);
    }
    if (maxPrice && maxPrice.value) {
        url.searchParams.set('max_price', maxPrice.value);
    }
    if (minRating && minRating.value) {
        url.searchParams.set('min_rating', minRating.value);
    }
    
    // Redirect to new URL
    window.location.href = url.toString();
}

// Initialize category tree on page load
document.addEventListener('DOMContentLoaded', function() {
    // Open parent categories if any child is selected
    const selectedCheckboxes = document.querySelectorAll('input[name="categories[]"]:checked');
    selectedCheckboxes.forEach(checkbox => {
        const parentId = checkbox.getAttribute('data-parent');
        if (parentId) {
            const childrenDiv = document.getElementById(`children-${parentId}`);
            const toggleIcon = document.getElementById(`toggle-${parentId}`);
            if (childrenDiv) {
                childrenDiv.style.display = 'block';
                if (toggleIcon) toggleIcon.textContent = '▼';
            }
        }
    });
});

// Compare functionality
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
        
        compareProducts.push({
            id: productId,
            name: productName,
            price: productPrice,
            image: productImage,
            rating: productRating,
            reviews: productReviews
        });
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
        
        compareItems.innerHTML = compareProducts.map(product => 
            `<div class="compare-item">
                <img src="${product.image || 'default-image.jpg'}" 
                     alt="${product.name}" 
                     onerror="this.src='default-image.jpg'">
                <span title="${product.name}">${product.name}</span>
                <button onclick="removeFromCompare('${product.id}')" 
                        class="remove-compare" 
                        title="Remove">×</button>
            </div>`
        ).join('');
    } else {
        compareBar.style.display = 'none';
    }
}

function removeFromCompare(productId) {
    compareProducts = compareProducts.filter(p => p.id !== productId);
    const checkbox = document.querySelector(`input[data-product-id="${productId}"]`);
    if (checkbox) {
        checkbox.checked = false;
    }
    updateCompareBar();
}

function clearCompare() {
    compareProducts = [];
    document.querySelectorAll('.compare-checkbox').forEach(checkbox => {
        checkbox.checked = false;
    });
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

function handleBuyNow(productId, quantity = 1) {
    // Add to cart and redirect to checkout
    addToCart(productId, quantity);
    window.location.href = 'cart.php';
}

document.addEventListener('DOMContentLoaded', function() {
    const compareBtn = document.getElementById('compare-btn');
    const clearCompareBtn = document.getElementById('clear-compare');
    
    if (compareBtn) {
        compareBtn.addEventListener('click', compareSelected);
    }
    
    if (clearCompareBtn) {
        clearCompareBtn.addEventListener('click', clearCompare);
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
