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
/* Body background */
body {
    background: #f8f9fa;
    min-height: 100vh;
    color: #130325;
    margin: 0;
    padding: 0;
    text-align: center;
}

/* Dark purple container spanning full width - using header color */
.dark-purple-container {
    background: #130325;
    width: 100%;
    margin: 0;
    padding: 60px 0;
    position: relative;
    overflow: hidden;
}

.dark-purple-container::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.05)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.03)"/><circle cx="50" cy="10" r="0.5" fill="rgba(255,255,255,0.04)"/><circle cx="10" cy="60" r="0.8" fill="rgba(255,255,255,0.02)"/><circle cx="90" cy="40" r="0.6" fill="rgba(255,255,255,0.03)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
    opacity: 0.3;
    pointer-events: none;
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
    margin-bottom: 30px;
}

.hero-layout {
    display: flex;
    gap: 20px;
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
}

.promo-banners {
    display: flex;
    flex-direction: column;
    gap: 15px;
    width: 300px;
    flex-shrink: 0;
}

.promo-banner {
    background: linear-gradient(135deg, #FFD736, #FFA500);
    padding: 15px;
    display: flex;
    align-items: center;
    gap: 15px;
    height: 140px;
    box-shadow: 0 15px 40px rgba(0,0,0,0.4);
}

.promo-content {
    flex: 1;
}

.promo-content h3 {
    color: #130325;
    font-size: 16px;
    font-weight: 700;
    margin: 0 0 5px 0;
}

.promo-content p {
    color: #130325;
    font-size: 12px;
    margin: 0 0 10px 0;
    line-height: 1.4;
}

.promo-btn {
    background: #130325;
    color: white;
    padding: 6px 12px;
    font-size: 11px;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
    transition: all 0.3s ease;
}

.promo-btn:hover {
    background: #FFD736;
    color: #130325;
}

.promo-image {
    width: 80px;
    height: 80px;
    overflow: hidden;
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
    box-shadow: 0 15px 40px rgba(0,0,0,0.4);
}

.hero-prev, .hero-next {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 20px;
    color: #130325;
    background: rgba(255, 255, 255, 0.8);
    border: 2px solid #130325;
    transition: all 0.3s ease;
    z-index: 100;
    opacity: 0;
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
    height: 300px;
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
    background: #FFD736;
    color: #130325;
    padding: 18px 40px;
    text-decoration: none;
    font-weight: 800;
    font-size: 1.2rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    transition: all 0.3s ease;
    display: inline-block;
    box-shadow: 0 8px 20px rgba(255, 215, 54, 0.4);
}

.shop-now-btn:hover {
    background: #130325;
    color: #FFD736;
    transform: translateY(-3px);
    box-shadow: 0 12px 30px rgba(255, 215, 54, 0.6);
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
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: rgba(19, 3, 37, 0.5);
    cursor: pointer;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.dot.active {
    background: #130325;
    border-color: #fff;
    transform: scale(1.3);
}

/* Quick Features */
.quick-features {
    max-width: 1400px;
    margin: 0 auto 60px;
    padding: 0 20px;
}

.features-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 25px;
}

.feature-item {
    text-align: center;
    padding: 20px 15px;
    transition: all 0.3s ease;
    position: relative;
}

.feature-item:hover {
    transform: translateY(-5px);
}

.feature-icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #FFD736, #e6c230);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 15px auto;
    font-size: 1.3rem;
    color: #130325;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(255, 215, 54, 0.3);
}

.feature-item:hover .feature-icon {
    transform: scale(1.1);
    box-shadow: 0 6px 20px rgba(255, 215, 54, 0.4);
}

.feature-item h3 {
    color: #130325;
    font-size: 1.1rem;
    font-weight: 700;
    margin: 0 0 8px 0;
    letter-spacing: 0.3px;
}

.feature-item p {
    color: #6c757d;
    font-size: 0.9rem;
    margin: 0;
    line-height: 1.5;
    max-width: 200px;
    margin: 0 auto;
}

/* Featured Products Section */
.featured-products {
    margin: 0 auto 80px auto;
    text-align: center;
}

.featured-products .hero-layout {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0;
}

.section-header {
    text-align: center;
    margin-bottom: 50px;
}

.section-title {
    color: #F9F9F9;
    font-size: 2.5rem;
    font-weight: 800;
    text-transform: uppercase;
    margin: 0 0 10px 0;
    letter-spacing: 2px;
}

.section-subtitle {
    color: rgba(249, 249, 249, 0.8);
    font-size: 1.1rem;
    margin: 0;
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
     background: rgba(26, 10, 46, 0.6);
     border: 1px solid rgba(255, 215, 54, 0.2);
     border-radius: 16px;
     padding: 15px;
     text-align: center;
     transition: all 0.3s ease;
     display: flex;
     flex-direction: column;
     flex: 0 0 19%;
     min-width: 250px;
 }

.product-card:hover {
    transform: translateY(-8px);
    border-color: #FFD736;
    box-shadow: 0 15px 35px rgba(255, 215, 54, 0.2);
}

.product-checkbox {
    position: absolute;
    top: 15px;
    right: 15px;
    background: rgba(255, 215, 54, 0.15);
    padding: 8px 12px;
    border: 1px solid rgba(255, 215, 54, 0.4);
    display: flex;
    align-items: center;
    gap: 8px;
    z-index: 2;
    transition: all 0.3s ease;
}

.product-checkbox:hover {
    background: rgba(255, 215, 54, 0.25);
}

.product-checkbox input[type="checkbox"] {
    accent-color: #FFD736;
    transform: scale(1.3);
}

.product-checkbox label {
    margin: 0;
    color: #F9F9F9;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
}

 .product-card img {
     width: 100%;
     height: 160px;
     object-fit: cover;
     border-radius: 12px;
     margin-bottom: 10px;
 }

section.featured-products .product-card .product-name {
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

section.featured-products .product-card .price {
     color: #212529 !important;
     font-weight: 800 !important;
     font-size: 1.5rem !important;
     margin: 5px 0 !important;
 }

section.featured-products .product-card .rating {
     color: #FFD736 !important;
     font-size: 1.3rem !important;
     margin: 5px 0 !important;
     display: flex !important;
     align-items: center !important;
     justify-content: center !important;
     gap: 8px !important;
 }

section.featured-products .product-card .rating-text {
    color: #212529 !important;
    font-size: 0.9rem !important;
    display: inline !important;
    margin: 0 !important;
    font-weight: 700 !important;
}

.stock {
    color: #28a745;
    font-size: 0.9rem;
    margin: 12px 0;
    font-weight: 600;
}

.product-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: auto;
    margin-bottom: 0;
    padding-top: 8px;
}

.cart-actions {
    display: flex;
    gap: 8px;
    align-items: center;
}

.btn-cart-icon {
    background: #FFD736;
    color: #130325;
    border: 2px solid #FFD736; /* match yellow */
    border-radius: 4px; /* slight rounding */
    padding: 4px;
    cursor: pointer;
    font-size: 10px;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    flex-shrink: 0;
}

.btn-cart-icon:hover {
    background: #ffde62; /* lighter yellow hover */
    border-color: #FFD736;
    color: #130325;
    transform: translateY(-2px);
}

.btn-buy-now {
    background: #130325;
    color: white;
    border: 2px solid #FFD736;
    padding: 4px 10px;
    cursor: pointer;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    flex: 1;
    height: 24px;
}

.btn-buy-now:hover {
    background: #FFD736;
    color: #130325;
    transform: translateY(-2px);
}

.btn {
    padding: 4px 10px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
}

.btn-details {
    background: #130325 !important;
    color: #F9F9F9 !important;
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
    background: #FFD736;
    color: #130325;
    transform: translateY(-2px);
    text-decoration: none;
}

/* ============================================
   CATEGORY CAROUSEL - AMAZON STYLE REDESIGN
   ============================================ */

.category-carousel-section {
    background: #f8f9fa;
    padding: 80px 0;
    margin: 60px 0;
    position: relative;
}

.category-carousel-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 20px;
}

.category-carousel-header {
    text-align: center;
    margin-bottom: 50px;
}

.category-carousel-title {
    font-size: 2.8rem;
    font-weight: 800;
    color: #130325;
    margin: 0 0 15px 0;
    text-transform: uppercase;
    letter-spacing: 1px;
    position: relative;
    display: inline-block;
}

.category-carousel-title::after {
    content: '';
    position: absolute;
    bottom: -10px;
    left: 50%;
    transform: translateX(-50%);
    width: 80px;
    height: 4px;
    background: linear-gradient(90deg, #FFD736, #130325);
    border-radius: 2px;
}

.category-carousel-subtitle {
    font-size: 1.15rem;
    color: #6c757d;
    margin: 20px 0 0 0;
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
    gap: 25px;
    margin-top: 30px;
}

.category-card {
    background: white;
    border: 2px solid #e6e6e6;
    border-radius: 12px;
    padding: 30px 20px;
    text-align: center;
    text-decoration: none;
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 15px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    position: relative;
    overflow: hidden;
}


.category-card:hover {
    transform: translateY(-5px);
    text-decoration: none;
    border-color: #130325;
    box-shadow: 0 8px 20px rgba(19, 3, 37, 0.2);
}


.category-icon {
    width: 90px;
    height: 90px;
    background: linear-gradient(135deg, #130325 0%, #2a0a4a 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    color: white;
    transition: all 0.4s ease;
    box-shadow: 0 4px 15px rgba(19, 3, 37, 0.2);
    position: relative;
}

.category-icon::after {
    content: '';
    position: absolute;
    inset: -3px;
    border-radius: 50%;
    background: linear-gradient(135deg, #FFD736, #130325);
    z-index: -1;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.category-card:hover .category-icon {
    background: #130325;
    color: white;
    transform: none;
    box-shadow: 0 4px 15px rgba(19, 3, 37, 0.2);
}

.category-card:hover .category-icon::after {
    opacity: 1;
}

.category-name {
    color: #130325;
    font-size: 1rem;
    font-weight: 700;
    text-transform: capitalize;
    margin: 0;
    line-height: 1.4;
    letter-spacing: 0.3px;
    transition: color 0.3s ease;
}

.category-card:hover .category-name {
    color: #FFD736;
}

.carousel-nav {
    background: white;
    color: #130325;
    border: 2px solid #130325;
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 20px;
    transition: all 0.3s ease;
    z-index: 10;
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
}

.carousel-prev {
    left: -30px;
}

.carousel-next {
    right: -30px;
}

.carousel-nav:hover {
    background: linear-gradient(135deg, #FFD736, #f0c419);
    color: #130325;
    border-color: #FFD736;
    transform: translateY(-50%) scale(1.1);
    box-shadow: 0 6px 20px rgba(255, 215, 54, 0.3);
}

.carousel-nav:active {
    transform: translateY(-50%) scale(0.95);
}

.carousel-nav:disabled {
    background: #e9ecef;
    border-color: #dee2e6;
    color: #adb5bd;
    cursor: not-allowed;
    opacity: 0.5;
}

.carousel-nav:disabled:hover {
    transform: translateY(-50%) scale(1);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
}

/* Compare Bar */
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
    .categories-grid {
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
    }
    
    .carousel-nav {
        width: 50px;
        height: 50px;
        font-size: 18px;
    }
    
    .carousel-prev {
        left: -25px;
    }
    
    .carousel-next {
        right: -25px;
    }
}

@media (max-width: 768px) {
    .hero-layout {
        flex-direction: column;
        gap: 15px;
    }
    
    .promo-banners {
        width: 100%;
        flex-direction: row;
        gap: 10px;
    }
    
    .promo-banner {
        flex: 1;
        height: 120px;
    }
    
    .promo-image {
        width: 60px;
        height: 60px;
    }
    
    .slideshow-container {
        order: -1;
    }
    
    .slide {
        height: 400px;
    }
    
    .section-title {
        font-size: 2rem;
    }
    
    .products-grid {
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 20px;
    }
    
    .category-carousel-section {
        padding: 60px 0;
    }
    
    .category-carousel-title {
        font-size: 2.2rem;
    }
    
    .category-carousel-wrapper {
        padding: 0 50px;
    }
    
    .categories-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
    }
    
    .carousel-prev {
        left: -20px;
    }
    
    .carousel-next {
        right: -20px;
    }
}

@media (max-width: 576px) {
    .hero-slideshow {
        margin-top: 70px;
    }
    
    .slide {
        height: 300px;
    }
    
    .shop-now-btn {
        padding: 14px 30px;
        font-size: 1rem;
    }
    
    .section-title {
        font-size: 1.75rem;
    }
    
    .products-grid {
        grid-template-columns: 1fr;
    }
    
    .category-carousel-section {
        padding: 40px 0;
    }
    
    .category-carousel-title {
        font-size: 1.8rem;
    }
    
    .category-carousel-subtitle {
        font-size: 1rem;
    }
    
    .category-carousel-wrapper {
        padding: 0 40px;
    }
    
    .carousel-prev {
        left: -15px;
    }
    
    .carousel-next {
        right: -15px;
    }
    
    .carousel-nav {
        width: 40px;
        height: 40px;
        font-size: 16px;
    }
    
    .categories-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }
    
    .category-card {
        padding: 20px 15px;
    }
    
    .category-icon {
        width: 70px;
        height: 70px;
        font-size: 1.5rem;
    }
    
    .category-name {
        font-size: 0.9rem;
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
                                <a href="product-detail.php?id=<?php echo $product['id']; ?>" 
                                   class="btn btn-details">
                                    <i class="fas fa-eye"></i> View Details
                                </a>
                                <div class="cart-actions">
                                    <button onclick="handleAddToCart(<?php echo $product['id']; ?>, 1)" 
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

function handleBuyNow(productId, quantity = 1) {
    if (!isLoggedIn) {
        window.location.href = 'login.php';
        return;
    }
    
    // Send buy now request to backend
    fetch('ajax/buy-now-handler.php', {
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
            // Redirect directly to checkout with buy_now flag
            window.location.href = 'paymongo/multi-seller-checkout.php?buy_now=1';
        } else {
            alert(data.message || 'Error processing buy now request');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error processing buy now request');
    });
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
