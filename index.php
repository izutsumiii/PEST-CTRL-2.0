<?php 
require_once 'includes/header.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

// Get featured products (5 random products)
$stmt = $pdo->prepare("SELECT 
                          p.id,
                          p.name,
                          p.price,
                          p.image_url,
                          p.stock_quantity,
                          COALESCE(AVG(r.rating), 0) as rating,
                          COUNT(DISTINCT r.id) as review_count,
                          COALESCE(SUM(oi.quantity), 0) as total_sold
                      FROM products p
                      LEFT JOIN reviews r ON p.id = r.product_id
                      LEFT JOIN order_items oi ON p.id = oi.product_id
                      LEFT JOIN orders o ON oi.order_id = o.id
                      WHERE p.status = 'active' 
                      AND (o.status NOT IN ('cancelled', 'refunded') OR o.status IS NULL)
                      GROUP BY p.id, p.name, p.price, p.image_url, p.stock_quantity
                      ORDER BY RAND()
                      LIMIT 5");
$stmt->execute();
$featuredProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load categories for shop-by sections
$stmtCats = $pdo->prepare("SELECT id, name, parent_id FROM categories");
$stmtCats->execute();
$allCategories = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

$categoryNameToId = [];
$idToCategory = [];
$childrenByParent = [];

foreach ($allCategories as $cat) {
    $name = (string)$cat['name'];
    $clean = preg_replace('/^[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}\s]+/u', '', $name);
    $clean = html_entity_decode($clean, ENT_QUOTES, 'UTF-8');
    $key = strtolower(trim($clean));
    if ($key !== '') $categoryNameToId[$key] = (int)$cat['id'];
    $cat['clean_name'] = $clean;
    $idToCategory[(int)$cat['id']] = $cat;
    $parentId = isset($cat['parent_id']) && $cat['parent_id'] ? (int)$cat['parent_id'] : 0;
    if (!isset($childrenByParent[$parentId])) $childrenByParent[$parentId] = [];
    $childrenByParent[$parentId][] = $cat;
}

function getChildrenByParentId($parentId) {
    global $childrenByParent;
    return isset($childrenByParent[$parentId]) ? $childrenByParent[$parentId] : [];
}

function iconForCategory($name) {
    $n = strtolower(trim($name));
    $map = [
        'ants' => 'fa-bug', 'bed' => 'fa-bed', 'roach' => 'fa-bug', 'cockroach' => 'fa-bug',
        'mosquito' => 'fa-mosquito', 'fly' => 'fa-wind', 'tick' => 'fa-shield-virus',
        'termite' => 'fa-house-chimney-crack', 'mouse' => 'fa-mouse', 'rat' => 'fa-mouse',
        'spider' => 'fa-spider', 'snake' => 'fa-staff-snake'
    ];
    foreach ($map as $key => $icon) {
        if (strpos($n, $key) !== false) return $icon;
    }
    return 'fa-shield-alt';
}

function resolveProductImageUrl($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
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
    text-align: center;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}

/* Dark purple container spanning full width - Featured Products */
.dark-purple-container {
    background: var(--primary-dark);
    width: 100%;
    margin: 0;
    padding: 30px 0;
    position: relative;
    overflow: hidden;
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

/* Hero Slideshow */
.hero-slideshow {
    margin-top: 80px;
    margin-bottom: 20px;
}

.hero-layout {
    display: flex;
    gap: 16px;
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 15px;
}

.promo-banners {
    display: flex;
    flex-direction: column;
    gap: 12px;
    width: 280px;
    flex-shrink: 0;
}

.promo-banner {
    background: linear-gradient(135deg, var(--accent-yellow), #ffd020);
    padding: 12px;
    display: flex;
    align-items: center;
    gap: 12px;
    height: 120px;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    border: 1px solid var(--border-light);
}

.promo-content {
    flex: 1;
}

.promo-content h3 {
    color: var(--primary-dark);
    font-size: 14px;
    font-weight: 700;
    margin: 0 0 4px 0;
    letter-spacing: -0.3px;
}

.promo-content p {
    color: var(--text-dark);
    font-size: 11px;
    margin: 0 0 8px 0;
    line-height: 1.4;
}

.promo-btn {
    background: var(--primary-dark);
    color: var(--bg-white);
    padding: 6px 12px;
    font-size: 11px;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
    border-radius: 6px;
    transition: all 0.2s ease;
}

.promo-btn:hover {
    background: var(--accent-yellow);
    color: var(--primary-dark);
}

.promo-image {
    width: 70px;
    height: 70px;
    overflow: hidden;
    border-radius: 6px;
    border: 1px solid var(--border-light);
}

.promo-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.slideshow-container {
    position: relative;
    flex: 1;
    overflow: hidden;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    border: 1px solid var(--border-light);
}

.hero-prev, .hero-next {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 16px;
    color: var(--primary-dark);
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 50%;
    transition: all 0.2s ease;
    z-index: 100;
    opacity: 0;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.hero-prev:hover, .hero-next:hover {
    background: var(--accent-yellow);
    border-color: var(--accent-yellow);
}

.hero-prev { left: 10px; }
.hero-next { right: 10px; }

.slideshow-container:hover .hero-prev,
.slideshow-container:hover .hero-next {
    opacity: 1;
}

.slide {
    display: none;
    position: relative;
    height: 280px;
}

.slide.active {
    display: block;
    animation: fadeIn 0.5s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.slide img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.slide-content {
    position: absolute;
    bottom: 80px;
    left: 50%;
    transform: translateX(-50%);
}

.shop-now-btn {
    background: var(--accent-yellow);
    color: var(--primary-dark);
    padding: 10px 18px;
    text-decoration: none;
    font-weight: 700;
    font-size: 14px;
    border-radius: 8px;
    transition: all 0.2s ease;
    display: inline-block;
}

.shop-now-btn:hover {
    background: #ffd020;
    color: var(--primary-dark);
    transform: translateY(-1px);
    text-decoration: none;
}

.dots-container {
    position: absolute;
    bottom: 30px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 12px;
    z-index: 10;
}

.dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.5);
    cursor: pointer;
    transition: all 0.2s ease;
    border: 1px solid transparent;
}

.dot.active {
    background: var(--accent-yellow);
    border-color: var(--bg-white);
    transform: scale(1.2);
}

/* Quick Features */
.quick-features {
    max-width: 1200px;
    margin: 0 auto 40px;
    padding: 0 15px;
}

.features-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 16px;
}

.feature-item {
    text-align: center;
    padding: 16px 12px;
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    transition: all 0.2s ease;
    position: relative;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.feature-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.feature-icon {
    width: 44px;
    height: 44px;
    background: var(--accent-yellow);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 12px auto;
    font-size: 18px;
    color: var(--primary-dark);
    transition: all 0.2s ease;
}

.feature-item:hover .feature-icon {
    transform: scale(1.05);
}

.feature-item h3 {
    color: var(--text-dark);
    font-size: 14px;
    font-weight: 700;
    margin: 0 0 6px 0;
    letter-spacing: -0.3px;
}

.feature-item p {
    color: var(--text-light);
    font-size: 12px;
    margin: 0;
    line-height: 1.4;
}

/* Featured Products Section */
.featured-products {
    margin: 0 auto 40px auto;
    text-align: center;
}

.featured-products .hero-layout {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0;
}

/* Mobile Swipe Hint - Hidden by default */
.mobile-swipe-hint {
    display: none;
    color: var(--primary-dark);
    font-size: 12px;
    margin-top: 8px;
    font-weight: 500;
    animation: pulse 2s infinite;
    opacity: 0.8;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.6; }
}

.section-header {
    text-align: center;
    margin-bottom: 24px;
}

.section-title {
    color: var(--bg-white);
    font-size: 1.35rem;
    font-weight: 600;
    margin: 0 0 8px 0;
    letter-spacing: -0.3px;
}

.section-subtitle {
    color: rgba(255, 255, 255, 0.8);
    font-size: 13px;
    margin: 0;
    line-height: 1.5;
}

section.featured-products .products-grid {
    display: flex !important;
    gap: 20px !important;
    overflow: visible !important;
    position: relative !important;
    width: 100% !important;
    justify-content: center !important;
    max-width: none !important;
    margin: 0 auto !important;
    text-align: center !important;
    grid-template-columns: none !important;
}

section.featured-products .products-container {
     display: flex !important;
     gap: 25px !important;
     width: 100% !important;
     justify-content: center !important;
     align-items: flex-start !important;
     flex-wrap: nowrap !important;
     padding: 0 !important;
     margin: 0 auto !important;
     max-width: 100% !important;
     text-align: center !important;
 }

 .product-card {
     position: relative;
     background: var(--bg-white);
     border: 1px solid var(--border-light);
     border-radius: 12px;
     padding: 12px;
     text-align: center;
     transition: all 0.2s ease;
     display: flex;
     flex-direction: column;
    flex: 0 0 19%;
    min-width: 220px;
    height: auto;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
 }

.product-card:hover {
    transform: translateY(-2px);
    border-color: var(--primary-dark);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.product-checkbox {
    position: absolute;
    top: 10px;
    right: 10px;
    background: var(--bg-white);
    padding: 6px 10px;
    border: 1px solid var(--border-light);
    border-radius: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
    z-index: 2;
    transition: all 0.2s ease;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.product-checkbox:hover {
    background: var(--bg-light);
    border-color: var(--primary-dark);
}

.product-checkbox input[type="checkbox"] {
    accent-color: var(--accent-yellow);
    width: 16px;
    height: 16px;
}

.product-checkbox label {
    margin: 0;
    color: var(--text-dark);
    font-size: 11px;
    font-weight: 600;
}

 .product-card img {
     width: 100%;
     height: 140px;
     object-fit: cover;
     border-radius: 8px;
     margin-bottom: 8px;
     border: 1px solid var(--border-light);
 }

section.featured-products .product-card .product-name {
     color: var(--text-dark) !important;
     font-size: 14px !important;
     font-weight: 600 !important;
     margin: 4px 0 !important;
     min-height: 32px !important;
     max-height: 40px !important;
     overflow: hidden !important;
     text-overflow: ellipsis !important;
     display: -webkit-box !important;
     -webkit-line-clamp: 2 !important;
     -webkit-box-orient: vertical !important;
     line-height: 1.4 !important;
 }

section.featured-products .product-card .price {
     color: var(--text-dark) !important;
     font-weight: 700 !important;
     font-size: 15px !important;
     margin: 4px 0 !important;
 }

section.featured-products .product-card .rating {
     color: var(--accent-yellow) !important;
     font-size: 13px !important;
     margin: 4px 0 !important;
     display: flex !important;
     align-items: center !important;
     justify-content: center !important;
     gap: 6px !important;
 }

section.featured-products .product-card .rating-text {
    color: var(--text-light) !important;
    font-size: 12px !important;
    display: inline !important;
    margin: 0 !important;
    font-weight: 500 !important;
}

.stock {
    color: var(--success-green);
    font-size: 11px;
    margin: 6px 0;
    font-weight: 500;
}

.product-actions {
    display: flex;
    flex-direction: row;
    gap: 6px;
    align-items: center;
    margin-top: auto;
    margin-bottom: 0;
    padding-top: 8px;
}

.btn-cart-icon {
    background: var(--accent-yellow);
    color: var(--primary-dark);
    border: 1px solid var(--accent-yellow);
    border-radius: 6px;
    padding: 6px;
    cursor: pointer;
    font-size: 12px;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.btn-cart-icon:hover {
    background: #ffd020;
    border-color: #ffd020;
    color: var(--primary-dark);
    transform: translateY(-1px);
}

.btn {
    padding: 6px 12px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    transition: all 0.2s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
}

.btn-details {
    background: var(--primary-dark) !important;
    color: var(--bg-white) !important;
    border: 1px solid var(--primary-dark) !important;
    padding: 6px 12px !important;
    font-size: 12px !important;
    flex: 1 !important;
    justify-content: center !important;
    border-radius: 6px !important;
    margin: 0 !important;
    line-height: 1.2 !important;
    min-height: auto !important;
    height: 28px !important;
    text-align: center !important;
}

.btn-details i {
    font-size: 10px !important;
    margin-right: 4px !important;
}

.btn-details:hover {
    background: var(--accent-yellow) !important;
    color: var(--primary-dark) !important;
    border-color: var(--accent-yellow) !important;
    transform: translateY(-1px);
    text-decoration: none;
}

/* ============================================
   CATEGORY CAROUSEL - AMAZON STYLE REDESIGN
   ============================================ */

.category-carousel-section {
    background: var(--bg-light);
    padding: 40px 0;
    margin: 40px 0;
    position: relative;
}

.category-carousel-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 15px;
}

.category-carousel-header {
    text-align: center;
    margin-bottom: 24px;
}

.category-carousel-title {
    font-size: 1.35rem;
    font-weight: 600;
    color: var(--primary-dark);
    margin: 0 0 8px 0;
    letter-spacing: -0.3px;
    position: relative;
    display: inline-block;
}

.category-carousel-title::after {
    content: '';
    position: absolute;
    bottom: -8px;
    left: 50%;
    transform: translateX(-50%);
    width: 60px;
    height: 2px;
    background: var(--accent-yellow);
    border-radius: 1px;
}

.category-carousel-subtitle {
    font-size: 14px;
    color: var(--text-light);
    margin: 12px 0 0 0;
    font-weight: 400;
}

.category-carousel-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    gap: 30px;
    padding: 0 60px;
}

.category-carousel {
    flex: 1;
    overflow: hidden;
    position: relative;
}

.category-type-slide {
    display: none;
    animation: fadeInSlide 0.5s ease;
}

.category-type-slide.active {
    display: block;
}

@keyframes fadeInSlide {
    from {
        opacity: 0;
        transform: translateX(20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.categories-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-top: 20px;
}

.category-card {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    padding: 16px 12px;
    text-align: center;
    text-decoration: none;
    transition: all 0.2s ease;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    position: relative;
    overflow: hidden;
}

.category-card:hover {
    transform: translateY(-2px);
    text-decoration: none;
    border-color: var(--primary-dark);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.category-icon {
    width: 64px;
    height: 64px;
    background: var(--primary-dark);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: var(--bg-white);
    transition: all 0.2s ease;
    position: relative;
}

.category-card:hover .category-icon {
    background: var(--accent-yellow);
    color: var(--primary-dark);
    transform: scale(1.05);
}

.category-name {
    color: var(--text-dark);
    font-size: 13px;
    font-weight: 600;
    text-transform: capitalize;
    margin: 0;
    line-height: 1.4;
    letter-spacing: -0.3px;
    transition: color 0.2s ease;
}

.category-card:hover .category-name {
    color: var(--primary-dark);
}

.carousel-nav {
    background: var(--bg-white);
    color: var(--primary-dark);
    border: 1px solid var(--border-light);
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 16px;
    transition: all 0.2s ease;
    z-index: 10;
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.carousel-prev {
    left: -20px;
}

.carousel-next {
    right: -20px;
}

.carousel-nav:hover {
    background: var(--accent-yellow);
    color: var(--primary-dark);
    border-color: var(--accent-yellow);
    transform: translateY(-50%) scale(1.05);
}

.carousel-nav:active {
    transform: translateY(-50%) scale(0.95);
}

.carousel-nav:disabled {
    background: var(--bg-light);
    border-color: var(--border-light);
    color: var(--text-light);
    cursor: not-allowed;
    opacity: 0.5;
}

.carousel-nav:disabled:hover {
    transform: translateY(-50%) scale(1);
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
    .categories-grid {
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
    }
    
    .carousel-nav {
        width: 36px;
        height: 36px;
        font-size: 14px;
    }
    
    .carousel-prev {
        left: -18px;
    }
    
    .carousel-next {
        right: -18px;
    }
}

@media (max-width: 968px) {
    .hero-slideshow {
        margin-top: 80px;
        margin-bottom: 16px;
    }
    
    .hero-layout {
        flex-direction: column;
        gap: 12px;
        padding: 0 12px;
    }
    
    .promo-banners {
        width: 100%;
        flex-direction: row;
        gap: 10px;
    }
    
    .promo-banner {
        flex: 1;
        height: 100px;
        padding: 10px;
    }
    
    .promo-content h3 {
        font-size: 13px;
    }
    
    .promo-content p {
        font-size: 10px;
    }
    
    .promo-image {
        width: 50px;
        height: 50px;
    }
    
    .slideshow-container {
        order: -1;
    }
    
    .slide {
        height: 240px;
    }
    
    .dark-purple-container {
        padding: 30px 0;
    }
    
    .section-title {
        font-size: 1.2rem;
    }
    
    .section-subtitle {
        font-size: 12px;
    }
    
    .category-carousel-section {
        padding: 30px 0;
        margin: 30px 0;
    }
    
    .category-carousel-title {
        font-size: 1.2rem;
    }
    
    .category-carousel-subtitle {
        font-size: 12px;
    }
    
    .category-carousel-wrapper {
        padding: 0 40px;
    }
    
    .categories-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        margin-top: 20px;
    }
    
    .category-card {
        padding: 12px 8px;
    }
    
    .category-icon {
        width: 56px;
        height: 56px;
        font-size: 20px;
    }
    
    .category-name {
        font-size: 12px;
    }
    
    .carousel-prev {
        left: -15px;
    }
    
    .carousel-next {
        right: -15px;
    }
    
    .quick-features {
        margin-bottom: 30px;
        padding: 0 12px;
    }
    
    .features-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    
    .feature-item {
        padding: 12px 10px;
    }
    
    .feature-icon {
        width: 40px;
        height: 40px;
        font-size: 16px;
        margin-bottom: 10px;
    }
    
    .feature-item h3 {
        font-size: 13px;
    }
    
    .feature-item p {
        font-size: 11px;
    }
}

@media (max-width: 640px) {
    .hero-slideshow {
        margin-top: 70px;
        margin-bottom: 12px;
    }
    
    .hero-layout {
        padding: 0 10px;
        gap: 10px;
    }
    
    .promo-banner {
        height: 90px;
        padding: 8px;
        gap: 8px;
    }
    
    .promo-content h3 {
        font-size: 12px;
        margin-bottom: 3px;
    }
    
    .promo-content p {
        font-size: 9px;
        margin-bottom: 6px;
    }
    
    .promo-btn {
        padding: 5px 10px;
        font-size: 10px;
    }
    
    .promo-image {
        width: 45px;
        height: 45px;
    }
    
    .slide {
        height: 200px;
    }
    
    .hero-prev, .hero-next {
        width: 32px;
        height: 32px;
        font-size: 14px;
    }
    
    .dark-purple-container {
        padding: 24px 0;
    }
    
    .section-title {
        font-size: 1.2rem;
    }
    
    .section-subtitle {
        font-size: 11px;
    }
    
    .category-carousel-section {
        padding: 24px 0;
        margin: 24px 0;
    }
    
    .category-carousel-title {
        font-size: 1.2rem;
    }
    
    .category-carousel-subtitle {
        font-size: 12px;
    }
    
    .category-carousel-wrapper {
        padding: 0 35px;
    }
    
    .carousel-prev {
        left: -12px;
    }
    
    .carousel-next {
        right: -12px;
    }
    
    .carousel-nav {
        width: 32px;
        height: 32px;
        font-size: 14px;
    }
    
    .categories-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
        margin-top: 16px;
    }
    
    .category-card {
        padding: 10px 6px;
    }
    
    .category-icon {
        width: 48px;
        height: 48px;
        font-size: 18px;
    }
    
    .category-name {
        font-size: 11px;
    }
    
    .quick-features {
        margin-bottom: 24px;
        padding: 0 10px;
    }
    
    .features-grid {
        grid-template-columns: 1fr;
        gap: 10px;
    }
    
    .feature-item {
        padding: 12px;
    }
    
    .feature-icon {
        width: 36px;
        height: 36px;
        font-size: 14px;
    }
    
    .feature-item h3 {
        font-size: 12px;
    }
    
    .feature-item p {
        font-size: 11px;
    }
    
    .compare-content {
        flex-direction: column;
        gap: 12px;
    }
    
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

/* Additional Mobile Optimizations */
@media screen and (max-width: 480px) {
    /* Fix body and main containers */
    body {
        overflow-x: hidden !important;
        width: 100% !important;
        max-width: 100vw !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    
    /* Overall container - minimized */
    .dark-purple-container {
        padding: 25px 0;
        width: 100% !important;
        max-width: 100vw !important;
        margin: 0 !important;
        overflow-x: hidden !important;
        box-sizing: border-box;
    }
    
    /* All sections should not overflow */
    section {
        width: 100%;
        max-width: 100vw;
        overflow-x: hidden;
        box-sizing: border-box;
    }
    
    /* Section headers - smaller */
    .section-title {
        font-size: 1.1rem;
        margin-bottom: 8px;
        padding: 0 12px;
    }
    
    .section-subtitle {
        font-size: 11px;
        margin-bottom: 16px;
        padding: 0 12px;
    }
    
    /* Hero Section - minimized and centered */
    .hero-layout {
        flex-direction: column;
        padding: 0;
        gap: 12px;
        width: 100%;
        margin: 0 auto;
        max-width: 100vw;
        box-sizing: border-box;
    }
    
    .promo-banners {
        width: 100%;
        gap: 10px;
        padding: 0 12px;
        box-sizing: border-box;
        display: flex;
        justify-content: center;
        flex-wrap: wrap;
    }
    
    .promo-banner {
        padding: 15px;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
    }
    
    .promo-banner h3 {
        font-size: 14px;
    }
    
    .promo-banner p {
        font-size: 11px;
    }
    
    .slideshow-container {
        height: 220px;
        width: calc(100% - 24px);
        margin: 0 auto;
        max-width: 100vw;
        box-sizing: border-box;
    }
    
    .hero-slide {
        height: 220px;
        padding: 15px;
        width: 100%;
        box-sizing: border-box;
    }
    
    .hero-slide h2 {
        font-size: 18px;
    }
    
    .hero-slide p {
        font-size: 12px;
    }
    
    .hero-btn {
        padding: 8px 16px;
        font-size: 11px;
    }
    
    /* Hide swipe hint - using grid not scroll */
    .mobile-swipe-hint {
        display: none !important;
    }
    
    /* Featured Products Section */
    .featured-products {
        margin: 0 auto 40px auto;
        width: 100%;
        overflow-x: hidden;
    }
    
    .featured-products .hero-layout {
        width: 100%;
        padding: 0;
    }
    
    .section-header {
        margin-bottom: 20px;
        width: 100%;
    }
    
    /* Featured Products - 2x2 GRID layout (4 items only) - NARROWER CONTAINER */
    .products-container,
    section.featured-products .products-container {
        display: grid !important;
        grid-template-columns: repeat(2, 1fr) !important;
        grid-template-rows: repeat(2, auto) !important;
        gap: 15px !important;
        padding: 0 40px !important;
        width: 100% !important;
        max-width: 400px !important;
        margin: 0 auto !important;
        box-sizing: border-box !important;
        flex-wrap: wrap !important;
    }
    
    /* Limit to 4 items only on mobile */
    .product-card:nth-child(n+5) {
        display: none !important;
    }
    
    /* Product cards - Compact size within narrow container */
    .product-card {
        padding: 6px;
        display: flex;
        flex-direction: column;
        box-sizing: border-box;
        width: 100%;
        height: auto;
    }
    
    .product-image {
        height: 100%;
        width: 100%;
        aspect-ratio: 1/1;
        object-fit: cover;
        border-radius: 5px;
    }
    
    .product-title {
        font-size: 9px;
        line-height: 1.3;
        margin: 4px 0 3px 0;
        min-height: 24px;
        overflow: hidden;
        text-overflow: ellipsis;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
    }
    
    .product-price {
        font-size: 11px;
        font-weight: 700;
        margin: 3px 0;
    }
    
    .product-rating {
        font-size: 8px;
        margin: 2px 0;
    }
    
    /* Make sure add to cart button is visible */
    .add-to-cart-btn,
    .product-actions button {
        display: block !important;
        width: 100%;
        padding: 6px 3px !important;
        font-size: 8px !important;
        font-weight: 600;
        margin-top: 3px;
        box-sizing: border-box;
        line-height: 1.3;
        border: 1px solid var(--accent-yellow) !important;
    }
    
    .product-card img {
        height: 100px;
    }
    
    section.featured-products .product-card .product-name {
        font-size: 10px !important;
        min-height: 28px !important;
        max-height: 36px !important;
    }
    
    section.featured-products .product-card .price {
        font-size: 12px !important;
    }
    
    section.featured-products .product-card .rating {
        font-size: 10px !important;
    }
    
    .stock {
        font-size: 9px;
    }
    
    .btn-cart-icon {
        width: 24px;
        height: 24px;
        font-size: 10px;
    }
    
    .btn-details {
        font-size: 9px !important;
        height: 24px !important;
        padding: 4px 8px !important;
    }
    
    .product-actions {
        display: flex !important;
        flex-direction: row;
        gap: 6px;
        width: 100%;
        align-items: center;
    }
    
    /* Hide scroll arrows on grid layout */
    .scroll-arrow {
        display: none !important;
    }
    
    /* Shop by Category Section */
    .shop-by-category {
        width: 100%;
        overflow-x: hidden;
        margin: 40px 0;
    }
    
    /* Shop by Category - maintain 3 column grid like desktop */
    .shop-by-grid {
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
        padding: 0 12px;
        width: 100%;
        margin: 0;
        box-sizing: border-box;
    }
    
    .category-card {
        padding: 12px 6px;
        width: 100%;
        box-sizing: border-box;
    }
    
    .category-icon {
        font-size: 22px;
        margin-bottom: 5px;
    }
    
    .category-name {
        font-size: 10px;
    }
    
    /* Features Section */
    .features-section {
        width: 100%;
        overflow-x: hidden;
        padding: 30px 0;
    }
    
    /* Features Section - 2 columns on tablet */
    .features-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
        padding: 0 12px;
        width: 100%;
        margin: 0;
        box-sizing: border-box;
    }
    
    .feature-item {
        padding: 12px;
        width: 100%;
        box-sizing: border-box;
    }
    
    .feature-icon {
        font-size: 24px;
        margin-bottom: 6px;
    }
    
    .feature-item h3 {
        font-size: 13px;
        margin-bottom: 4px;
    }
    
    .feature-item p {
        font-size: 11px;
        line-height: 1.4;
    }
}

/* Mobile (480px and below) */
@media screen and (max-width: 480px) {
    /* Fix body and containers */
    body {
        overflow-x: hidden !important;
        width: 100% !important;
        max-width: 100vw !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    
    /* Hero Section */
    .dark-purple-container {
        padding: 20px 0;
        width: 100% !important;
        max-width: 100vw !important;
        margin: 0 !important;
        overflow-x: hidden !important;
        box-sizing: border-box;
    }
    
    /* All sections should not overflow */
    section {
        width: 100%;
        max-width: 100vw;
        overflow-x: hidden;
        box-sizing: border-box;
    }
    
    .section-title {
        font-size: 1rem;
        padding: 0 10px;
    }
    
    .section-subtitle {
        font-size: 10px;
        padding: 0 10px;
    }
    
    .hero-layout {
        padding: 0;
        gap: 10px;
        width: 100%;
        margin: 0 auto;
        max-width: 100vw;
        box-sizing: border-box;
    }
    
    .promo-banners {
        width: 100%;
        padding: 0 10px;
        box-sizing: border-box;
        display: flex;
        justify-content: center;
    }
    
    .promo-banner {
        padding: 12px;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
    }
    
    .promo-banner h3 {
        font-size: 13px;
    }
    
    .promo-banner p {
        font-size: 10px;
    }
    
    .promo-image {
        width: 50px;
        height: 50px;
    }
    
    .slideshow-container {
        height: 200px;
        width: calc(100% - 20px);
        margin: 0 auto;
        max-width: 100vw;
        box-sizing: border-box;
    }
    
    .hero-slide {
        height: 200px;
        padding: 12px;
        width: 100%;
        box-sizing: border-box;
    }
    
    .hero-slide h2 {
        font-size: 16px;
    }
    
    .hero-slide p {
        font-size: 11px;
    }
    
    .hero-btn {
        padding: 7px 14px;
        font-size: 10px;
    }
    
    /* Featured Products Section */
    .featured-products {
        width: 100%;
        overflow-x: hidden;
        margin: 0 auto 30px auto;
    }
    
    /* Featured Products - 2x2 GRID on small mobile - NARROWER CONTAINER */
    .products-container,
    section.featured-products .products-container {
        display: grid !important;
        grid-template-columns: repeat(2, 1fr) !important;
        grid-template-rows: repeat(2, auto) !important;
        gap: 12px !important;
        padding: 0 30px !important;
        width: 100% !important;
        max-width: 350px !important;
        margin: 0 auto !important;
        box-sizing: border-box !important;
        flex-wrap: wrap !important;
    }
    
    /* Limit to 4 items only on mobile */
    .product-card:nth-child(n+5) {
        display: none !important;
    }
    
    /* Product cards - Compact within narrow container on small mobile */
    .product-card {
        padding: 5px;
        display: flex;
        flex-direction: column;
        box-sizing: border-box;
        width: 100%;
        height: auto;
    }
    
    .product-image {
        height: 100%;
        width: 100%;
        aspect-ratio: 1/1;
        object-fit: cover;
        border-radius: 5px;
    }
    
    .product-title {
        font-size: 8px;
        min-height: 22px;
        line-height: 1.3;
        margin: 3px 0 2px 0;
        overflow: hidden;
        text-overflow: ellipsis;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
    }
    
    .product-price {
        font-size: 10px;
        font-weight: 700;
        margin: 2px 0;
    }
    
    .product-rating {
        font-size: 7px;
        margin: 1px 0;
    }
    
    .add-to-cart-btn,
    .product-actions button {
        padding: 5px 2px !important;
        font-size: 7px !important;
        width: 100%;
        box-sizing: border-box;
        margin-top: 2px;
        line-height: 1.3;
        display: block !important;
        border: 1px solid var(--accent-yellow) !important;
    }
    
    .product-card img {
        height: 90px;
    }
    
    section.featured-products .product-card .product-name {
        font-size: 9px !important;
        min-height: 26px !important;
        max-height: 32px !important;
    }
    
    section.featured-products .product-card .price {
        font-size: 11px !important;
    }
    
    section.featured-products .product-card .rating {
        font-size: 9px !important;
    }
    
    .stock {
        font-size: 8px;
    }
    
    .btn-cart-icon {
        width: 22px;
        height: 22px;
        font-size: 9px;
    }
    
    .btn-details {
        font-size: 8px !important;
        height: 22px !important;
        padding: 3px 6px !important;
    }
    
    .product-actions {
        display: flex !important;
        flex-direction: row;
        gap: 5px;
        width: 100%;
        align-items: center;
    }
    
    /* Hide scroll arrows */
    .scroll-arrow {
        display: none !important;
    }
    
    /* Shop by Category Section */
    .shop-by-category {
        width: 100%;
        overflow-x: hidden;
    }
    
    /* Shop by Category */
    .shop-by-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
        padding: 0 10px;
        width: 100%;
        margin: 0;
        box-sizing: border-box;
    }
    
    .category-card {
        padding: 12px 8px;
        width: 100%;
        box-sizing: border-box;
    }
    
    .category-icon {
        font-size: 24px;
        margin-bottom: 6px;
    }
    
    .category-name {
        font-size: 10px;
    }
    
    /* Features Section */
    .features-section {
        width: 100%;
        overflow-x: hidden;
    }
    
    .features-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
        padding: 0 10px;
        width: 100%;
        margin: 0;
        box-sizing: border-box;
    }
    
    .feature-item {
        padding: 10px;
        width: 100%;
        box-sizing: border-box;
    }
    
    .feature-icon {
        font-size: 22px;
    }
    
    .feature-item h3 {
        font-size: 12px;
    }
    
    .feature-item p {
        font-size: 10px;
    }
    
    /* Section titles */
    .section-title {
        font-size: 1rem;
        padding: 0 10px;
        margin-bottom: 16px;
    }
    
    /* Product buttons */
    .product-actions {
        flex-direction: row;
        gap: 8px;
        align-items: center;
    }
    
    .product-actions .btn-details {
        flex: 1;
    }
}

/* Extra Small (360px and below) */
@media screen and (max-width: 360px) {
    .hero-layout {
        padding: 0 8px;
    }
    
    .promo-banner {
        min-width: 200px;
        height: 110px;
    }
    
    .slideshow-container {
        height: 200px;
    }
    
    .hero-slide {
        height: 200px;
    }
    
    .hero-slide h2 {
        font-size: 20px;
    }
    
    .featured-grid {
        padding: 0 8px;
    }
    
    .shop-by-grid {
        padding: 0 8px;
    }
    
    .category-icon {
        font-size: 28px;
    }
}
</style>

<!-- Hero Slideshow -->
<section class="hero-slideshow">
    <div class="hero-layout">
        <!-- Main Slideshow -->
        <div class="slideshow-container">
            <div class="slide active">
                <img src="assets/uploads/1759552181_Imidart.jpg" alt="Pest Control Products">
            </div>
            
            <div class="slide">
                <img src="assets/uploads/1759501037_INSECTICIDES-Mahishmati.jpg" alt="Insecticides">
            </div>
            
            <div class="slide">
                <img src="assets/uploads/1759552123_mouse trap.jpg" alt="Mouse Traps">
            </div>
            
            <div class="slide">
                <img src="assets/uploads/1759552310_304-Advion-Roach-Bait-Gel-Syngenta.jpg.thumb_450x450.jpg" alt="Roach Control">
            </div>
            
            <div class="slide">
                <img src="assets/uploads/1759552519_391-Tempo-1pc-Dust-envu.jpg.thumb_450x450.jpg" alt="Pest Control Dust">
            </div>
            
            <button class="hero-prev" onclick="changeSlide(-1)">
                <i class="fas fa-chevron-left"></i>
            </button>
            <button class="hero-next" onclick="changeSlide(1)">
                <i class="fas fa-chevron-right"></i>
            </button>
            
            <div class="dots-container">
                <span class="dot active" onclick="currentSlide(1)"></span>
                <span class="dot" onclick="currentSlide(2)"></span>
                <span class="dot" onclick="currentSlide(3)"></span>
                <span class="dot" onclick="currentSlide(4)"></span>
                <span class="dot" onclick="currentSlide(5)"></span>
            </div>
        </div>
        
        <!-- Promotional Banners -->
        <div class="promo-banners">
            <div class="promo-banner">
                <div class="promo-content">
                    <h3>Special Offer</h3>
                    <p>Get 20% off on all rodent control products</p>
                    <a href="products.php" class="promo-btn">Shop Now</a>
                </div>
                <div class="promo-image">
                    <img src="assets/uploads/1759552123_mouse trap.jpg" alt="Special Offer">
                </div>
            </div>
            
            <div class="promo-banner">
                <div class="promo-content">
                    <h3>New Arrivals</h3>
                    <p>Latest eco-friendly pest control solutions</p>
                    <a href="products.php" class="promo-btn">Explore</a>
                </div>
                <div class="promo-image">
                    <img src="assets/uploads/1759501037_INSECTICIDES-Mahishmati.jpg" alt="New Arrivals">
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Quick Features -->
<section class="quick-features">
    <div class="features-grid">
        <div class="feature-item">
            <div class="feature-icon"><i class="fas fa-shield-alt"></i></div>
            <h3>Pro-Grade Products</h3>
            <p>Professional quality for DIY solutions</p>
        </div>
        <div class="feature-item">
            <div class="feature-icon"><i class="fas fa-shipping-fast"></i></div>
            <h3>Fast Delivery</h3>
            <p>Nationwide shipping across Philippines</p>
        </div>
        <div class="feature-item">
            <div class="feature-icon"><i class="fas fa-headset"></i></div>
            <h3>Expert Support</h3>
            <p>Free advice from pest control specialists</p>
        </div>
        <div class="feature-item">
            <div class="feature-icon"><i class="fas fa-undo-alt"></i></div>
            <h3>Easy Returns</h3>
            <p>Hassle-free 30-day return policy</p>
        </div>
    </div>
</section>

<!-- Dark Purple Container for Featured Products -->
<div class="dark-purple-container">
    <!-- Featured Products -->
    <section class="featured-products">
        <div class="hero-layout">
            <div class="section-header">
                <h2 class="section-title">Featured Products</h2>
                <p class="section-subtitle">Best-selling pest control solutions</p>
                <p class="mobile-swipe-hint"><i class="fas fa-hand-point-right"></i> Swipe to see more</p>
            </div>
            
            <div class="products-grid">
                <div class="products-container" id="productsContainer">
                    <?php 
                    $isLoggedIn = isset($_SESSION['user_id']);
                    foreach ($featuredProducts as $product): 
                        $featImg = resolveProductImageUrl($product['image_url'] ?? '');
                    ?>
                        <div class="product-card">
                            <div class="product-checkbox">
                                <input type="checkbox"
                                       class="compare-checkbox"
                                       data-product-id="<?php echo $product['id']; ?>"
                                       data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                                       data-product-price="<?php echo $product['price']; ?>"
                                       data-product-image="<?php echo htmlspecialchars($featImg); ?>"
                                       data-product-rating="<?php echo $product['rating']; ?>"
                                       data-product-reviews="<?php echo $product['review_count']; ?>"
                                       onchange="toggleCompare(this)">
                                <label>Compare</label>
                            </div>
                            <img loading="lazy" src="<?php echo htmlspecialchars($featImg); ?>" 
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
                            <div class="stock"><?php echo $product['stock_quantity'] ?? 0; ?> in stock</div>
                            <div class="product-actions">
                                <button onclick="handleAddToCart(<?php echo $product['id']; ?>, 1)" 
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
            </div>
        </div>
    </section>
</div>

<!-- Category Carousel - Amazon Style -->
<section class="category-carousel-section">
    <div class="category-carousel-container">
        <div class="category-carousel-header">
            <h2 class="category-carousel-title" id="carousel-title">Shop By Pest</h2>
            <p class="category-carousel-subtitle" id="carousel-subtitle">Find the right solution for your pest problem</p>
        </div>
        
        <div class="category-carousel-wrapper">
            <button class="carousel-nav carousel-prev" onclick="changeCategoryType(-1)">
                <i class="fas fa-chevron-left"></i>
            </button>
            
            <div class="category-carousel" id="category-carousel">
                <?php
                $categoryTypes = [
                    [
                        'title' => 'Shop By Pest',
                        'subtitle' => 'Find the right solution for your pest problem',
                        'parent_id' => 75
                    ],
                    [
                        'title' => 'Shop By Formulation',
                        'subtitle' => 'Choose the right application method',
                        'parent_id' => 78
                    ],
                    [
                        'title' => 'Shop By Product Type',
                        'subtitle' => 'Browse by product category',
                        'parent_id' => 76
                    ]
                ];
                
                foreach ($categoryTypes as $index => $type) {
                    $children = getChildrenByParentId($type['parent_id']);
                    $limitedChildren = array_slice($children, 0, 8);
                    
                    echo '<div class="category-type-slide' . ($index === 0 ? ' active' : '') . '" data-type="' . $index . '">';
                    echo '<div class="categories-grid">';
                    
                    foreach ($limitedChildren as $child) {
                        $hrefChild = 'products.php?categories[]=' . (int)$child['id'];
                        $icon = iconForCategory($child['clean_name']);
                        echo '<a class="category-card" href="' . htmlspecialchars($hrefChild) . '">
                                <div class="category-icon"><i class="fas ' . htmlspecialchars($icon) . '"></i></div>
                                <h3 class="category-name">' . htmlspecialchars($child['clean_name']) . '</h3>
                              </a>';
                    }
                    
                    echo '</div>';
                    echo '</div>';
                }
                ?>
            </div>
            
            <button class="carousel-nav carousel-next" onclick="changeCategoryType(1)">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>
</section>

<!-- Compare Bar -->
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

<script>
const isLoggedIn = <?php echo json_encode($isLoggedIn); ?>;

function handleAddToCart(productId, quantity = 1) {
    if (!isLoggedIn) {
        window.location.href = 'login.php';
        return;
    }
    addToCart(productId, quantity);
}

// handleBuyNow function removed from index.php - Buy Now is only available in product-detail.php

// Scroll products on mobile with arrow buttons
function scrollProducts(direction) {
    const container = document.getElementById('productsContainer');
    if (!container) return;
    
    const scrollAmount = 105; // Scroll by approximately one card width (100px + gap)
    
    if (direction === 'left') {
        container.scrollBy({
            left: -scrollAmount,
            behavior: 'smooth'
        });
    } else {
        container.scrollBy({
            left: scrollAmount,
            behavior: 'smooth'
        });
    }
}

// Slideshow
let currentSlideIndex = 0;
const slides = document.querySelectorAll('.slide');
const dots = document.querySelectorAll('.dot');

function showSlide(index) {
    slides.forEach(slide => slide.classList.remove('active'));
    dots.forEach(dot => dot.classList.remove('active'));
    if (slides[index]) slides[index].classList.add('active');
    if (dots[index]) dots[index].classList.add('active');
}

window.changeSlide = function(direction) {
    currentSlideIndex += direction;
    if (currentSlideIndex >= slides.length) currentSlideIndex = 0;
    else if (currentSlideIndex < 0) currentSlideIndex = slides.length - 1;
    showSlide(currentSlideIndex);
};

window.currentSlide = function(index) {
    currentSlideIndex = index - 1;
    showSlide(currentSlideIndex);
};

let slideInterval = setInterval(() => changeSlide(1), 3000);

const slideshowContainer = document.querySelector('.slideshow-container');
if (slideshowContainer) {
    slideshowContainer.addEventListener('mouseenter', () => clearInterval(slideInterval));
    slideshowContainer.addEventListener('mouseleave', () => {
        slideInterval = setInterval(() => changeSlide(1), 3000);
    });
}

document.addEventListener('DOMContentLoaded', () => showSlide(0));

// Category Carousel
let currentCategoryType = 0;
const categoryTypes = [
    {
        title: 'Shop By Pest',
        subtitle: 'Find the right solution for your pest problem'
    },
    {
        title: 'Shop By Formulation', 
        subtitle: 'Choose the right application method'
    },
    {
        title: 'Shop By Product Type',
        subtitle: 'Browse by product category'
    }
];

function changeCategoryType(direction) {
    const slideElements = document.querySelectorAll('.category-type-slide');
    const title = document.getElementById('carousel-title');
    const subtitle = document.getElementById('carousel-subtitle');
    
    slideElements[currentCategoryType].classList.remove('active');
    
    currentCategoryType += direction;
    
    if (currentCategoryType >= slideElements.length) {
        currentCategoryType = 0;
    } else if (currentCategoryType < 0) {
        currentCategoryType = slideElements.length - 1;
    }
    
    slideElements[currentCategoryType].classList.add('active');
    
    title.textContent = categoryTypes[currentCategoryType].title;
    subtitle.textContent = categoryTypes[currentCategoryType].subtitle;
}

window.changeCategoryType = changeCategoryType;

// Compare Functionality
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
        compareItems.innerHTML = compareProducts.map(product => `
            <div class="compare-item">
                <img src="${product.image || 'default-image.jpg'}" alt="${product.name}" onerror="this.src='default-image.jpg'">
                <span title="${product.name}">${product.name}</span>
                <button onclick="removeFromCompare('${product.id}')" class="remove-compare" title="Remove">×</button>
            </div>
        `).join('');
    } else {
        compareBar.classList.remove('show');
        compareBar.style.display = 'none';
    }
}

function removeFromCompare(productId) {
    compareProducts = compareProducts.filter(p => p.id !== productId);
    const checkbox = document.querySelector(`.compare-checkbox[data-product-id="${productId}"]`);
    if (checkbox) checkbox.checked = false;
    updateCompareBar();
}

function clearCompare() {
    compareProducts = [];
    document.querySelectorAll('.compare-checkbox').forEach(cb => cb.checked = false);
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

document.addEventListener('DOMContentLoaded', function() {
    const compareBtn = document.getElementById('compare-btn');
    const clearCompareBtn = document.getElementById('clear-compare');
    if (compareBtn) compareBtn.addEventListener('click', compareSelected);
    if (clearCompareBtn) clearCompareBtn.addEventListener('click', clearCompare);
});
</script>

<?php require_once 'includes/footer.php'; ?>

