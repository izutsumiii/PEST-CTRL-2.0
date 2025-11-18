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
    background: var(--bg-light);
    min-height: 100vh;
    color: var(--text-dark);
    margin: 0;
    padding: 0;
    text-align: center;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
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
    grid-template-columns: 220px 1fr;
    gap: 20px;
    max-width: 1600px;
    margin: 0 auto;
    padding: 0 20px;
}

/* Filters Sidebar - Visible on desktop */
.filters {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 8px;
    padding: 16px;
    margin-top: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    position: sticky;
    top: 100px;
    max-height: calc(100vh - 120px);
    overflow-y: auto;
    overflow-x: visible;
    z-index: 1000;
    isolation: isolate;
}

/* Fix for select dropdowns - allow them to render outside filters container */
.filters .filter-group {
    isolation: isolate;
}

.filters select {
    position: relative;
    z-index: 1001;
}

/* Custom Scrollbar for Filters */
.filters::-webkit-scrollbar {
    width: 8px;
}

.filters::-webkit-scrollbar-track {
    background: var(--bg-light);
    border-radius: 4px;
}

.filters::-webkit-scrollbar-thumb {
    background: var(--primary-dark);
    border-radius: 4px;
    border: 2px solid var(--bg-light);
}

.filters::-webkit-scrollbar-thumb:hover {
    background: #0a0118;
}

/* Firefox scrollbar */
.filters {
    scrollbar-width: thin;
    scrollbar-color: var(--primary-dark) var(--bg-light);
}

.products-main {
    width: 100%;
    margin-top: 20px;
}

.products-list h2 {
    color: var(--text-dark);
    margin: 0 0 16px 0;
    font-size: 1.35rem;
    font-weight: 600;
    text-align: left;
    letter-spacing: -0.3px;
}

.applied-filters {
    margin: 20px 0;
    text-align: center;
}

.filter-tag {
    background: var(--primary-dark);
    color: var(--bg-white);
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11px;
    margin: 4px 6px;
    display: inline-block;
    font-weight: 600;
}

/* Products Grid - 4 columns */
.products-grid {
    display: grid !important;
    grid-template-columns: repeat(4, 1fr) !important;
    gap: 16px !important;
    width: 100% !important;
    margin: 0 auto !important;
    text-align: center !important;
}

/* Product Card - Smaller than index.php and seller.php */
.product-card {
    position: relative !important;
    background: var(--bg-white) !important;
    border: 1px solid var(--border-light) !important;
    border-radius: 8px !important;
    padding: 6px 5px !important;
    text-align: center !important;
    transition: all 0.2s ease !important;
    display: flex !important;
    flex-direction: column !important;
    width: 100% !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05) !important;
}

.product-card:hover {
    transform: translateY(-2px) !important;
    border-color: var(--primary-dark) !important;
    box-shadow: 0 4px 12px rgba(19, 3, 37, 0.15) !important;
}

/* Product Image - Smaller */
.product-card img {
    width: 100% !important;
    height: 90px !important;
    object-fit: cover !important;
    border-radius: 5px !important;
    margin-bottom: 4px !important;
}

/* Product Checkbox - Modern Design */
.product-checkbox {
    position: absolute !important;
    top: 6px !important;
    right: 6px !important;
    background: rgba(19, 3, 37, 0.08) !important;
    padding: 4px 6px !important;
    border: 1px solid rgba(19, 3, 37, 0.2) !important;
    border-radius: 5px !important;
    display: flex !important;
    align-items: center !important;
    gap: 4px !important;
    z-index: 2 !important;
    transition: all 0.2s ease !important;
}

.product-checkbox:hover {
    background: rgba(19, 3, 37, 0.15) !important;
    border-color: var(--primary-dark) !important;
}

.product-checkbox input[type="checkbox"] {
    accent-color: var(--primary-dark) !important;
    transform: scale(1.2) !important;
}

.product-checkbox label {
    margin: 0 !important;
    color: #130325 !important;
    font-size: 0.8rem !important;
    font-weight: 600 !important;
    text-transform: uppercase !important;
}

/* Product Name - Smaller */
.products-layout .product-card .product-name,
.products-list .product-card .product-name {
    color: #212529 !important;
    font-size: 10px !important;
    font-weight: 600 !important;
    margin: 2px 0 !important;
    min-height: 24px !important;
    max-height: 28px !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    display: -webkit-box !important;
    -webkit-line-clamp: 2 !important;
    -webkit-box-orient: vertical !important;
    line-height: 1.2 !important;
}

/* Price - Smaller */
.products-layout .product-card .price,
.products-list .product-card .price {
    color: #212529 !important;
    font-weight: 700 !important;
    font-size: 12px !important;
    margin: 2px 0 !important;
}

/* Rating - Smaller */
.products-layout .product-card .rating,
.products-list .product-card .rating {
    color: var(--accent-yellow) !important;
    font-size: 10px !important;
    margin: 2px 0 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 2px !important;
}

.products-layout .product-card .rating-text,
.products-list .product-card .rating-text {
    color: #212529 !important;
    font-size: 9px !important;
    display: inline !important;
    margin: 0 !important;
    font-weight: 700 !important;
}

/* Stock - EXACT MATCH */
.stock {
    color: #28a745 !important;
    font-size: 9px !important;
    margin: 4px 0 !important;
    font-weight: 600 !important;
}

/* Product Actions - Updated Layout */
.product-actions {
    display: flex !important;
    flex-direction: row !important;
    gap: 5px !important;
    align-items: center !important;
    margin-top: auto !important;
    margin-bottom: 0 !important;
    padding-top: 5px !important;
}

.btn-cart-icon {
    background: var(--accent-yellow) !important;
    color: var(--primary-dark) !important;
    border: 1px solid var(--accent-yellow) !important;
    border-radius: 5px !important;
    padding: 3px !important;
    cursor: pointer !important;
    font-size: 9px !important;
    width: 22px !important;
    height: 22px !important;
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

/* Buy Now button removed from product cards - kept in product-detail.php only */

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
    padding: 3px 8px !important;
    font-size: 9px !important;
    flex: 1 !important;
    justify-content: center !important;
    border-radius: 4px !important;
    margin: 0 !important;
    line-height: 1.2 !important;
    min-height: auto !important;
    height: 20px !important;
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

.btn-cart {
    background: #007bff !important;
    color: #130325 !important;
}

.btn-cart:hover {
    background: #0056b3 !important;
    transform: translateY(-2px) !important;
}

/* Filters Section - Removed duplicate, using the one above */

.filters h2 {
    color: var(--text-dark);
    margin-bottom: 14px;
    font-size: 1.1rem;
    font-weight: 600;
    text-align: left;
    padding: 6px 10px;
    background: rgba(19, 3, 37, 0.04);
    border-radius: 6px;
    display: inline-block;
    border-bottom: none;
    padding-bottom: 6px;
}

.filter-group {
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid rgba(19, 3, 37, 0.1);
    position: relative;
    z-index: 1;
    overflow: visible;
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
    border: 1.5px solid var(--border-light);
    border-radius: 6px;
    background: var(--bg-white);
    color: var(--text-dark);
    font-size: 12px;
    position: relative;
    z-index: 1001;
    cursor: pointer;
}

/* Ensure select dropdown options are visible */
.filter-group select option {
    background: var(--bg-white);
    color: var(--text-dark);
    padding: 8px;
}

.filter-group select:focus,
.filter-group input:focus {
    outline: none;
    border-color: var(--primary-dark);
    box-shadow: 0 0 0 3px rgba(19, 3, 37, 0.1);
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
    accent-color: var(--primary-dark);
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
    accent-color: var(--primary-dark);
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
    background: #0a0118;
    color: var(--bg-white);
    transform: translateY(-1px);
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
    background: #dc2626;
    color: var(--bg-white);
    transform: translateY(-1px);
}

/* Sorting Container */
.sorting-container {
    background: var(--bg-white);
    padding: 12px 16px;
    margin-bottom: 16px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    border: 1px solid var(--border-light);
    border-radius: 8px;
}

.sorting-wrapper {
    display: flex;
    align-items: center;
    gap: 15px;
    flex: 1;
}

.sort-label {
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
}

.sort-select:focus,
.order-select:focus {
    outline: none;
    background: var(--bg-white);
    color: var(--text-dark);
    border-color: var(--primary-dark);
    box-shadow: 0 0 0 3px rgba(19, 3, 37, 0.1);
}

/* Ensure dropdown options are white */
.sort-select option,
.order-select option {
    background: var(--bg-white);
    color: var(--text-dark);
}

/* Filter Button - Hidden on desktop, shown on mobile */
.btn-filter {
    background: var(--primary-dark) !important;
    color: var(--bg-white) !important;
    border: 1px solid var(--primary-dark) !important;
    padding: 8px 16px !important;
    border-radius: 6px !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    display: none !important;
    align-items: center !important;
    gap: 6px !important;
    transition: all 0.2s ease !important;
    margin-left: auto !important;
}

.btn-filter:hover {
    background: var(--accent-yellow) !important;
    color: var(--primary-dark) !important;
    border-color: var(--accent-yellow) !important;
    transform: translateY(-1px) !important;
}

.btn-filter i {
    font-size: 12px !important;
}

/* Filter Modal */
.filter-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 10000;
    align-items: center;
    justify-content: center;
}

.filter-modal.active {
    display: flex;
}

.filter-modal-content {
    background: var(--bg-white);
    border-radius: 12px;
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
    animation: slideDown 0.3s ease;
    overflow: hidden;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.filter-modal-header {
    background: var(--primary-dark);
    color: var(--bg-white);
    padding: 16px 20px;
    border-radius: 12px 12px 0 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.filter-modal-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
}

.filter-modal-close {
    background: transparent;
    border: none;
    color: var(--bg-white);
    font-size: 24px;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: background 0.2s ease;
}

.filter-modal-close:hover {
    background: rgba(255, 255, 255, 0.1);
}

.filter-modal-body {
    padding: 20px;
    max-height: calc(90vh - 140px);
    overflow-y: auto;
    overflow-x: hidden;
}

/* Custom Scrollbar for Filter Modal */
.filter-modal-body::-webkit-scrollbar {
    width: 8px;
}

.filter-modal-body::-webkit-scrollbar-track {
    background: var(--bg-light);
    border-radius: 4px;
    margin: 4px 0;
}

.filter-modal-body::-webkit-scrollbar-thumb {
    background: var(--primary-dark);
    border-radius: 4px;
    border: 2px solid var(--bg-light);
}

.filter-modal-body::-webkit-scrollbar-thumb:hover {
    background: #0a0118;
}

/* Firefox scrollbar for filter modal */
.filter-modal-body {
    scrollbar-width: thin;
    scrollbar-color: var(--primary-dark) var(--bg-light);
}

.filter-modal-footer {
    padding: 16px 20px;
    border-top: 1px solid var(--border-light);
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.btn-clear-filters-modal,
.btn-apply-filters {
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.2s ease;
}

.btn-clear-filters-modal {
    background: var(--bg-light);
    color: var(--text-dark);
    border: 1px solid var(--border-light);
}

.btn-clear-filters-modal:hover {
    background: #e5e7eb;
}

.btn-apply-filters {
    background: var(--primary-dark);
    color: var(--bg-white);
}

.btn-apply-filters:hover {
    background: #0a0118;
    transform: translateY(-1px);
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

/* Compare Bar - Modern Design */
.compare-bar {
    display: none;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: var(--primary-dark);
    box-shadow: 0 -2px 8px rgba(0, 0, 0, 0.2);
    z-index: 1000;
    padding: 10px 14px;
    border-top: 2px solid var(--primary-dark);
}

.compare-bar.show {
    display: block !important;
}

.compare-content {
    max-width: 1600px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.compare-content h4 {
    margin: 0 !important;
    color: var(--bg-white) !important;
    font-size: 0.9rem !important;
    font-weight: 600 !important;
    letter-spacing: -0.2px !important;
}

#compare-items {
    display: flex;
    gap: 10px;
    flex: 1;
    flex-wrap: wrap;
}

.compare-item {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.1);
    padding: 6px 10px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.compare-item img {
    width: 32px;
    height: 32px;
    object-fit: cover;
    border-radius: 4px;
    border: 1px solid var(--border-light);
}

.compare-item span {
    color: var(--bg-white) !important;
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-weight: 500;
    font-size: 11px;
}

.remove-compare {
    background: none;
    border: none;
    color: var(--bg-white);
    font-size: 18px;
    cursor: pointer;
    padding: 0;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.2s ease;
    opacity: 0.8;
}

.remove-compare:hover {
    background: rgba(255, 255, 255, 0.2);
    opacity: 1;
}

.compare-actions {
    display: flex;
    gap: 12px;
}

.btn-compare {
    background: var(--accent-yellow) !important;
    color: var(--primary-dark) !important;
    font-weight: 600 !important;
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
    padding: 8px 14px !important;
    border-radius: 6px !important;
    cursor: pointer !important;
    font-size: 12px !important;
    transition: all 0.2s ease !important;
    text-transform: none !important;
    letter-spacing: normal !important;
}

.btn-compare:hover:not(:disabled) {
    background: #ffd020 !important;
    color: var(--primary-dark) !important;
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
    transform: translateY(-1px) !important;
}

.btn-compare:disabled {
    background: var(--text-light) !important;
    color: var(--bg-white) !important;
    opacity: 0.5 !important;
    cursor: not-allowed !important;
    transform: none !important;
}

.btn-clear {
    background: var(--error-red) !important;
    color: var(--bg-white) !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    padding: 8px 14px !important;
    border-radius: 6px !important;
    border: none !important;
    cursor: pointer !important;
    text-transform: none !important;
    letter-spacing: normal !important;
    transition: all 0.2s ease !important;
}

.btn-clear:hover {
    background: #dc2626 !important;
    color: var(--bg-white) !important;
    transform: translateY(-1px) !important;
}

/* Responsive */
@media (max-width: 1200px) {
    .products-layout {
        grid-template-columns: 200px 1fr;
        gap: 20px;
    }
}

@media (max-width: 968px) {
    body {
        overflow-x: hidden !important;
        width: 100% !important;
        max-width: 100vw !important;
    }
    
    .products-layout {
        grid-template-columns: 1fr !important;
        padding: 0 10px !important;
        gap: 15px !important;
        width: 100% !important;
        max-width: 100vw !important;
        box-sizing: border-box !important;
    }
    
    /* Hide filters sidebar on mobile */
    .filters {
        display: none !important;
    }
    
    /* Show filter button on mobile */
    .btn-filter {
        display: inline-flex !important;
    }
    
    .filters h2 {
        font-size: 1rem !important;
        margin-bottom: 15px !important;
    }
    
    .filter-group {
        margin-bottom: 15px !important;
    }
    
    .filter-group h3 {
        font-size: 0.8rem !important;
    }
    
    .category-tree {
        max-height: 200px !important;
    }
    
    .products-main {
        margin-top: 10px !important;
        width: 100% !important;
        max-width: 100vw !important;
        box-sizing: border-box !important;
    }
    
    /* Sorting Container Mobile */
    .sorting-container {
        padding: 10px !important;
        margin-bottom: 15px !important;
    }
    
    .sorting-wrapper {
        flex-direction: column !important;
        gap: 8px !important;
        align-items: stretch !important;
    }
    
    .sort-label {
        font-size: 0.8rem !important;
        text-align: center !important;
    }
    
    .sort-select,
    .order-select {
        width: 100% !important;
        min-width: auto !important;
        padding: 6px 8px !important;
        font-size: 0.8rem !important;
    }
    
    /* Products Grid - 3x3 Layout */
    .products-grid {
        display: grid !important;
        grid-template-columns: repeat(3, 1fr) !important;
        gap: 8px !important;
        justify-content: center !important;
        width: 100% !important;
        max-width: 100vw !important;
        padding: 0 5px !important;
        box-sizing: border-box !important;
    }
    
    .product-card {
        flex: none !important;
        width: 100% !important;
        min-width: auto !important;
        max-width: none !important;
        padding: 8px !important;
        box-sizing: border-box !important;
    }
    
    .product-card img {
        height: 120px !important;
    }
    
    .product-checkbox {
        top: 8px !important;
        right: 8px !important;
        padding: 4px 6px !important;
    }
    
    .product-checkbox label {
        font-size: 0.6rem !important;
    }
    
    .product-checkbox input[type="checkbox"] {
        transform: scale(1) !important;
    }
    
    .products-layout .product-card .product-name,
    .products-list .product-card .product-name {
        font-size: 0.85rem !important;
        min-height: 30px !important;
        margin: 3px 0 !important;
    }
    
    .products-layout .product-card .price,
    .products-list .product-card .price {
        font-size: 1rem !important;
        margin: 3px 0 !important;
    }
    
    .products-layout .product-card .rating,
    .products-list .product-card .rating {
        font-size: 1rem !important;
        margin: 3px 0 !important;
    }
    
    .products-layout .product-card .rating-text,
    .products-list .product-card .rating-text {
        font-size: 0.75rem !important;
    }
    
    .stock {
        font-size: 0.75rem !important;
        margin: 8px 0 !important;
    }
    
    .btn-details {
        font-size: 0.6rem !important;
        padding: 3px 6px !important;
    }
    
    .btn-cart-icon {
        width: 20px !important;
        height: 20px !important;
        font-size: 8px !important;
    }
    
    /* Buy Now button removed from product cards */
    
    /* Compare Bar Mobile */
    .compare-bar {
        padding: 12px !important;
    }
    
    .compare-content {
        flex-direction: column !important;
        gap: 10px !important;
    }
    
    .compare-content h4 {
        font-size: 0.8rem !important;
    }
    
    #compare-items {
        gap: 8px !important;
        width: 100% !important;
    }
    
    .compare-item {
        padding: 6px 10px !important;
        font-size: 0.75rem !important;
    }
    
    .compare-item img {
        width: 25px !important;
        height: 25px !important;
    }
    
    .compare-item span {
        font-size: 0.7rem !important;
        max-width: 100px !important;
    }
    
    .compare-actions {
        width: 100% !important;
        flex-direction: row !important;
    }
    
    .btn-compare,
    .btn-clear {
        font-size: 0.75rem !important;
        padding: 8px 12px !important;
        flex: 1 !important;
    }
}

@media (max-width: 480px) {
    .products-grid {
        grid-template-columns: repeat(3, 1fr) !important;
        gap: 6px !important;
        padding: 0 5px !important;
    }
    
    .product-card {
        padding: 6px !important;
    }
    
    .product-card img {
        height: 90px !important;
    }
    
    .products-layout .product-card .product-name,
    .products-list .product-card .product-name {
        font-size: 0.75rem !important;
        min-height: 28px !important;
    }
    
    .products-layout .product-card .price,
    .products-list .product-card .price {
        font-size: 0.9rem !important;
    }
    
    .products-layout .product-card .rating,
    .products-list .product-card .rating {
        font-size: 0.9rem !important;
    }
    
    .stock {
        font-size: 0.7rem !important;
    }
    
    .btn-details {
        font-size: 0.55rem !important;
        padding: 2px 4px !important;
    }
    
    .btn-cart-icon {
        width: 18px !important;
        height: 18px !important;
        font-size: 7px !important;
    }
    
    /* Buy Now button removed from product cards */
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

<!-- Filter Modal -->
<div id="filterModal" class="filter-modal">
    <div class="filter-modal-content">
        <div class="filter-modal-header">
            <h3>Filters</h3>
            <button class="filter-modal-close" onclick="closeFilterModal()">&times;</button>
        </div>
        <div class="filter-modal-body">
            <form method="GET" id="filterForm">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                
                <div class="filter-group">
                    <h3>Category</h3>
                    <div class="category-tree">
                        <?php
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
                </div>
                
                <div class="filter-group">
                    <h3>Rating</h3>
                    <div class="rating-filter">
                        <div class="rating-options">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <label>
                                <input type="radio" name="min_rating" value="<?php echo $i; ?>" 
                                <?php echo $minRating == $i ? 'checked' : ''; ?>>
                                    <span class="rating-stars">
                                        <?php echo str_repeat('<span class=\'star-filled\'>★</span>', $i)
                                            . str_repeat('<span class=\'star-empty\'>☆</span>', 5 - $i); ?>
                                    </span>
                                </label>
                            <?php endfor; ?>
                            <label>
                            <input type="radio" name="min_rating" value="0" 
                            <?php echo $minRating == 0 ? 'checked' : ''; ?>>
                                Any Rating
                            </label>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <div class="filter-modal-footer">
            <button type="button" class="btn-clear-filters-modal" onclick="clearFilters()">Clear All</button>
            <button type="button" class="btn-apply-filters" onclick="applyFilters()">Apply Filters</button>
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
                <button type="button" class="btn-filter" onclick="openFilterModal()">
                    <i class="fas fa-filter"></i> Filters
                </button>
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
                                    $reviewCount = $product['review_count'] ?? 0;
                                    
                                    if ($reviewCount > 0) {
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
                                        echo '<span class="rating-text">(' . number_format($rating, 1) . ')</span>';
                                    } else {
                                        echo '<span class="no-reviews-text" style="color: #999; font-size: 0.9rem;">No reviews yet</span>';
                                    }
                                ?>
                            </div>
                            <div class="stock"><?php echo $product['stock_quantity']; ?> in stock</div>
                            <div class="product-actions">
                                <button onclick="addToCart(<?php echo $product['id']; ?>, 1)" 
                                        class="btn-cart-icon" 
                                        data-product-id="<?php echo $product['id']; ?>"
                                        title="Add to Cart">
                                    <i class="fas fa-shopping-cart"></i>
                                </button>
                                <a href="product-detail.php?id=<?php echo $product['id']; ?>" 
                                   class="btn btn-details">
                                    View Details
                                </a>
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
        compareBar.classList.add('show');
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
        compareBar.classList.remove('show');
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
// handleBuyNow function removed from products.php - Buy Now is only available in product-detail.php

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

// Filter Modal Functions
function openFilterModal() {
    document.getElementById('filterModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeFilterModal() {
    document.getElementById('filterModal').classList.remove('active');
    document.body.style.overflow = '';
}

function applyFilters() {
    document.getElementById('filterForm').submit();
}

function clearFilters() {
    window.location.href = 'products.php<?php echo $search ? '?search=' . urlencode($search) : ''; ?>';
}

// Close modal when clicking outside
document.getElementById('filterModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeFilterModal();
    }
});

// ========== DEBUGGER FOR FILTER DROPDOWN ==========
console.log('🔍 [FILTER DEBUG] Starting filter dropdown debugger...');

document.addEventListener('DOMContentLoaded', function() {
    console.log('🔍 [FILTER DEBUG] DOM Content Loaded');
    
    // Find all select elements in filters
    const filtersSidebar = document.querySelector('.filters');
    const allSelects = document.querySelectorAll('.filters select, .filter-group select');
    
    console.log('🔍 [FILTER DEBUG] Filters sidebar found:', filtersSidebar ? 'YES' : 'NO');
    console.log('🔍 [FILTER DEBUG] Number of select elements found:', allSelects.length);
    
    if (filtersSidebar) {
        const computedStyle = window.getComputedStyle(filtersSidebar);
        console.log('🔍 [FILTER DEBUG] Filters sidebar styles:', {
            overflowX: computedStyle.overflowX,
            overflowY: computedStyle.overflowY,
            zIndex: computedStyle.zIndex,
            position: computedStyle.position,
            width: computedStyle.width,
            height: computedStyle.height
        });
    }
    
    // Debug each select element
    allSelects.forEach((select, index) => {
        console.log(`🔍 [FILTER DEBUG] Select #${index + 1}:`, {
            id: select.id,
            name: select.name,
            className: select.className,
            parentElement: select.parentElement?.className,
            visible: select.offsetWidth > 0 && select.offsetHeight > 0,
            computedStyles: {
                zIndex: window.getComputedStyle(select).zIndex,
                position: window.getComputedStyle(select).position,
                overflow: window.getComputedStyle(select).overflow,
                display: window.getComputedStyle(select).display,
                visibility: window.getComputedStyle(select).visibility,
                pointerEvents: window.getComputedStyle(select).pointerEvents
            }
        });
        
        // Add click event listener
        select.addEventListener('click', function(e) {
            console.log(`🔍 [FILTER DEBUG] Select #${index + 1} CLICKED!`, {
                target: e.target,
                currentTarget: e.currentTarget,
                bubbles: e.bubbles,
                cancelable: e.cancelable,
                defaultPrevented: e.defaultPrevented,
                timeStamp: e.timeStamp
            });
            
            const rect = select.getBoundingClientRect();
            console.log(`🔍 [FILTER DEBUG] Select position:`, {
                top: rect.top,
                left: rect.left,
                width: rect.width,
                height: rect.height,
                visible: rect.width > 0 && rect.height > 0
            });
            
            // Check for overlaying elements
            const elementAtPoint = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
            console.log(`🔍 [FILTER DEBUG] Element at center point:`, elementAtPoint);
            
            // Check parent overflow
            let parent = select.parentElement;
            let level = 0;
            while (parent && level < 5) {
                const parentStyle = window.getComputedStyle(parent);
                console.log(`🔍 [FILTER DEBUG] Parent level ${level} (${parent.className}):`, {
                    overflowX: parentStyle.overflowX,
                    overflowY: parentStyle.overflowY,
                    zIndex: parentStyle.zIndex,
                    position: parentStyle.position
                });
                parent = parent.parentElement;
                level++;
            }
        });
        
        // Add focus event listener
        select.addEventListener('focus', function(e) {
            console.log(`🔍 [FILTER DEBUG] Select #${index + 1} FOCUSED!`);
            
            // FIX: Temporarily remove overflow from parent when select is focused
            const filtersContainer = select.closest('.filters');
            if (filtersContainer) {
                filtersContainer.style.overflow = 'visible';
                console.log('🔍 [FILTER DEBUG] Removed overflow from filters container on focus');
            }
        });
        
        select.addEventListener('blur', function() {
            const filtersContainer = select.closest('.filters');
            if (filtersContainer) {
                filtersContainer.style.overflow = '';
                filtersContainer.style.overflowY = 'auto';
                filtersContainer.style.overflowX = 'visible';
                console.log('🔍 [FILTER DEBUG] Restored overflow on filters container on blur');
            }
        });
        
        // Add mousedown event listener
        select.addEventListener('mousedown', function(e) {
            console.log(`🔍 [FILTER DEBUG] Select #${index + 1} MOUSEDOWN!`, {
                button: e.button,
                clientX: e.clientX,
                clientY: e.clientY
            });
            
            // FIX: Remove overflow before dropdown opens
            const filtersContainer = select.closest('.filters');
            if (filtersContainer) {
                filtersContainer.style.overflow = 'visible';
                console.log('🔍 [FILTER DEBUG] Removed overflow on mousedown');
            }
        });
        
        // Check if select is actually clickable
        select.addEventListener('mouseenter', function() {
            console.log(`🔍 [FILTER DEBUG] Select #${index + 1} MOUSE ENTER`);
        });
    });
    
    // Check for any elements that might be overlaying the filters
    const filtersRect = filtersSidebar?.getBoundingClientRect();
    if (filtersRect) {
        console.log('🔍 [FILTER DEBUG] Filters sidebar bounding rect:', filtersRect);
        
        // Check elements at different points
        const checkPoints = [
            { x: filtersRect.left + 10, y: filtersRect.top + 10, label: 'Top-left' },
            { x: filtersRect.left + filtersRect.width / 2, y: filtersRect.top + filtersRect.height / 2, label: 'Center' },
            { x: filtersRect.right - 10, y: filtersRect.bottom - 10, label: 'Bottom-right' }
        ];
        
        checkPoints.forEach(point => {
            const element = document.elementFromPoint(point.x, point.y);
            console.log(`🔍 [FILTER DEBUG] Element at ${point.label}:`, {
                tag: element?.tagName,
                className: element?.className,
                id: element?.id,
                zIndex: element ? window.getComputedStyle(element).zIndex : 'N/A'
            });
        });
    }
    
    // Monitor for any style changes
    if (filtersSidebar) {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
                    console.log('🔍 [FILTER DEBUG] Filters sidebar style changed:', filtersSidebar.style.cssText);
                }
            });
        });
        
        observer.observe(filtersSidebar, {
            attributes: true,
            attributeFilter: ['style', 'class']
        });
        
        console.log('🔍 [FILTER DEBUG] MutationObserver attached to filters sidebar');
    }
    
    console.log('🔍 [FILTER DEBUG] Debugger setup complete!');
    console.log('🔍 [FILTER DEBUG] Try clicking on a filter dropdown and check the console for logs.');
});
</script>

<?php require_once 'includes/footer.php'; ?>
