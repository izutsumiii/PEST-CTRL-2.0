<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_once 'includes/seller_notification_functions.php';

// Check seller login
requireSeller();
$userId = $_SESSION['user_id'];

// Get filter parameters
$filterRating = isset($_GET['rating']) ? (int)$_GET['rating'] : 0;
$filterStatus = isset($_GET['status']) ? $_GET['status'] : 'all';
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get seller's products
try {
    $stmt = $pdo->prepare("
        SELECT id, name 
        FROM products 
        WHERE seller_id = ? 
        ORDER BY name ASC
    ");
    $stmt->execute([$userId]);
    $sellerProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $productIds = array_column($sellerProducts, 'id');
} catch (Exception $e) {
    $sellerProducts = [];
    $productIds = [];
}

// Initialize statistics
$totalReviews = 0;
$averageRating = 0;
$unreadReviews = 0;
$hiddenReviews = 0;
$ratingBreakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

// Get all reviews for seller's products with customer info
$reviews = [];

if (!empty($productIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        
        // Build base query
        $whereConditions = ["r.product_id IN ($placeholders)"];
        $params = $productIds;
        
        // Apply filters
        if ($filterRating > 0) {
            $whereConditions[] = "r.rating = ?";
            $params[] = $filterRating;
        }
        
        if ($filterStatus === 'unread') {
            $whereConditions[] = "r.is_read_by_seller = 0";
        } elseif ($filterStatus === 'hidden') {
            $whereConditions[] = "r.is_hidden = 1";
        } elseif ($filterStatus === 'visible') {
            $whereConditions[] = "r.is_hidden = 0";
        }
        
        if (!empty($searchQuery)) {
            $whereConditions[] = "(p.name LIKE ? OR r.review_text LIKE ? OR u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
            $searchParam = '%' . $searchQuery . '%';
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // Get filtered reviews
        $stmt = $pdo->prepare("
            SELECT 
                r.id,
                r.product_id,
                r.user_id,
                r.rating,
                r.review_text,
                r.admin_reply,
                r.is_read_by_seller,
                r.is_hidden,
                r.created_at,
                r.updated_at,
                p.name as product_name,
                p.image_url as product_image,
                u.username,
                u.first_name,
                u.last_name
            FROM reviews r
            INNER JOIN products p ON r.product_id = p.id
            INNER JOIN users u ON r.user_id = u.id
            WHERE $whereClause
            ORDER BY r.created_at DESC
        ");
        $stmt->execute($params);
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get overall statistics (without filters)
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                AVG(rating) as avg_rating,
                SUM(CASE WHEN is_read_by_seller = 0 THEN 1 ELSE 0 END) as unread,
                SUM(CASE WHEN is_hidden = 1 THEN 1 ELSE 0 END) as hidden,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as rating_5,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as rating_4,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as rating_3,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as rating_2,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as rating_1
            FROM reviews r
            WHERE r.product_id IN ($placeholders)
        ");
        $stmt->execute($productIds);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($stats) {
            $totalReviews = (int)$stats['total'];
            $averageRating = (float)$stats['avg_rating'];
            $unreadReviews = (int)$stats['unread'];
            $hiddenReviews = (int)$stats['hidden'];
            $ratingBreakdown = [
                5 => (int)$stats['rating_5'],
                4 => (int)$stats['rating_4'],
                3 => (int)$stats['rating_3'],
                2 => (int)$stats['rating_2'],
                1 => (int)$stats['rating_1']
            ];
        }
    } catch (Exception $e) {
        $reviews = [];
        error_log("Review fetch error: " . $e->getMessage());
    }
}

// Get review-related notifications
$reviewNotifications = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            sn.id,
            sn.title,
            sn.message,
            sn.action_url,
            sn.is_read,
            sn.created_at
        FROM seller_notifications sn
        WHERE sn.seller_id = ?
        AND (
            sn.title LIKE '%review%' 
            OR sn.title LIKE '%rating%'
            OR sn.action_url LIKE '%seller-reviews.php%'
            OR sn.action_url LIKE '%review%'
        )
        ORDER BY sn.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $reviewNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $reviewNotifications = [];
}

// Include seller header
require_once 'includes/seller_header.php';
?>
<style>

.notification-wrapper {
    display: block;
    margin-bottom: 0;
}

.notification-wrapper a {
    display: block;
    text-decoration: none;
    color: inherit;
}

.notification-card {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 10px;
    padding: 14px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    transition: all 0.2s ease;
    position: relative;
    margin-bottom: 0;
}

.notification-card.unread {
    border-left: 4px solid var(--warning-orange);
    background: linear-gradient(90deg, rgba(245, 158, 11, 0.05) 0%, var(--bg-white) 100%);
}

.notification-card:hover {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    transform: translateY(-1px);
    cursor: pointer;
}

.notification-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(245, 158, 11, 0.05));
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: var(--warning-orange);
    font-size: 18px;
}

.notification-body {
    flex: 1;
    min-width: 0;
    padding-right: 30px; /* Space for dismiss button */
}

.notification-card .notification-title {
    font-weight: 600;
    font-size: 0.9375rem;
    color: var(--text-dark);
    margin-bottom: 4px;
    line-height: 1.3;
}

.notification-card .notification-message {
    font-size: 0.875rem;
    color: var(--text-light);
    line-height: 1.5;
    margin-bottom: 6px;
}

.notification-card .notification-time {
    font-size: 0.75rem;
    color: var(--text-light);
    opacity: 0.8;
}

.btn-dismiss {
    background: transparent;
    border: none;
    color: var(--text-light);
    padding: 6px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
    flex-shrink: 0;
    opacity: 0.6;
    position: absolute;
    top: 12px;
    right: 12px;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.btn-dismiss:hover {
    background: rgba(239, 68, 68, 0.1);
    color: var(--error-red);
    opacity: 1;
}

.notification-card:hover .btn-dismiss {
    opacity: 1;
}

.notifications-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}


    .notification-card a {
    cursor: pointer;
}

.notifications-list > a {
    display: block;
    text-decoration: none;
    color: inherit;
}

.notifications-list > a:hover .notification-card {
    cursor: pointer;
}
:root {
    --primary-dark: #130325;
    --accent-yellow: #FFD736;
    --text-dark: #1a1a1a;
    --text-light: #6b7280;
    --border-light: #e5e7eb;
    --bg-light: #f9fafb;
    --bg-white: #ffffff;
    --success-green: #10b981;
    --warning-orange: #f59e0b;
    --error-red: #ef4444;
    --info-blue: #3b82f6;
}

body { 
    background: var(--bg-light) !important; 
    color: var(--text-dark); 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}

.reviews-container {
    max-width: 1400px;
    margin: 0;
    padding: 12px 20px;
    margin-left: -220px;
    transition: margin-left 0.3s ease;
}

.sidebar.collapsed ~ main .reviews-container {
    margin-left: 80px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
    padding: 16px 20px;
    background: var(--bg-white);
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.page-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
}

.back-arrow {
    color: var(--bg-white);
    text-decoration: none;
    font-size: 16px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    padding: 6px 8px;
    border-radius: 8px;
    width: 32px;
    height: 32px;
    background: var(--primary-dark);
    border: 1px solid var(--primary-dark);
}

.back-arrow:hover {
    color: var(--bg-white);
    background: #0a0118;
    border-color: #0a0118;
    transform: translateX(-2px);
    box-shadow: 0 2px 6px rgba(19, 3, 37, 0.3);
}

.page-header-title {
    color: var(--text-dark);
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0;
    letter-spacing: -0.5px;
}

/* Notifications Banner */
.notifications-banner {
    background: linear-gradient(135deg, rgba(255, 215, 54, 0.1) 0%, rgba(255, 215, 54, 0.05) 100%);
    border: 1px solid rgba(255, 215, 54, 0.3);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(255, 215, 54, 0.1);
}

.notifications-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    flex-wrap: wrap;
    gap: 12px;
}

.notifications-title {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.notifications-title i {
    color: var(--warning-orange);
}

.btn-dismiss-all {
    background: transparent;
    border: 1px solid var(--text-light);
    color: var(--text-light);
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-dismiss-all:hover {
    background: var(--success-green);
    border-color: var(--success-green);
    color: white;
    transform: translateY(-1px);
}

.notifications-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.notification-card {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 10px;
    padding: 14px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    transition: all 0.2s ease;
    position: relative;
}

.notification-card.unread {
    border-left: 4px solid var(--warning-orange);
    background: linear-gradient(90deg, rgba(245, 158, 11, 0.05) 0%, var(--bg-white) 100%);
}

.notification-card:hover {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    transform: translateY(-1px);
}

.notification-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(245, 158, 11, 0.05));
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: var(--warning-orange);
    font-size: 18px;
}

.notification-body {
    flex: 1;
    min-width: 0;
}

.notification-card .notification-title {
    font-weight: 600;
    font-size: 0.9375rem;
    color: var(--text-dark);
    margin-bottom: 4px;
}

.notification-card .notification-message {
    font-size: 0.875rem;
    color: var(--text-light);
    line-height: 1.5;
    margin-bottom: 6px;
}

.notification-card .notification-time {
    font-size: 0.75rem;
    color: var(--text-light);
}

.btn-dismiss {
    background: transparent;
    border: none;
    color: var(--text-light);
    padding: 6px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
    flex-shrink: 0;
    opacity: 0.6;
}

.btn-dismiss:hover {
    background: rgba(239, 68, 68, 0.1);
    color: var(--error-red);
    opacity: 1;
}

.notification-card:hover .btn-dismiss {
    opacity: 1;
}

/* Stats Cards */
.stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--bg-white);
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid var(--border-light);
    transition: all 0.2s ease;
    cursor: pointer;
}

.stat-card:hover {
    box-shadow: 0 4px 12px rgba(19, 3, 37, 0.12);
    transform: translateY(-2px);
}

.stat-card.active {
    border-color: var(--primary-dark);
    box-shadow: 0 4px 12px rgba(19, 3, 37, 0.15);
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    margin-bottom: 12px;
}

.stat-icon.total {
    background: linear-gradient(135deg, rgba(19, 3, 37, 0.1), rgba(19, 3, 37, 0.05));
    color: var(--primary-dark);
}

.stat-icon.average {
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.05));
    color: var(--warning-orange);
}

.stat-icon.unread {
    background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(239, 68, 68, 0.05));
    color: var(--error-red);
}

.stat-icon.hidden {
    background: linear-gradient(135deg, rgba(107, 114, 128, 0.1), rgba(107, 114, 128, 0.05));
    color: var(--text-light);
}

.stat-icon.visible {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05));
    color: var(--success-green);
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text-dark);
    line-height: 1;
}

.stat-label {
    font-size: 0.875rem;
    color: var(--text-light);
    margin-top: 4px;
}

/* Filters Section */
.filters-section {
    background: var(--bg-white);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid var(--border-light);
}

.filters-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.filters-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-dark);
    display: flex;
    align-items: center;
    gap: 8px;
}

.btn-clear-filters {
    background: transparent;
    border: 1px solid var(--border-light);
    color: var(--text-light);
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-clear-filters:hover {
    background: var(--bg-light);
    border-color: var(--text-light);
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 16px;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.filter-label {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-dark);
}

.filter-select,
.filter-input {
    padding: 10px 12px;
    border: 1px solid var(--border-light);
    border-radius: 8px;
    font-size: 0.875rem;
    color: var(--text-dark);
    background: var(--bg-white);
    transition: all 0.2s ease;
}

.filter-select:focus,
.filter-input:focus {
    outline: none;
    border-color: var(--primary-dark);
    box-shadow: 0 0 0 3px rgba(19, 3, 37, 0.1);
}

.rating-filter-section {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--border-light);
}

.rating-filter-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 8px;
}

.rating-btn {
    padding: 8px 12px;
    border: 1px solid var(--border-light);
    background: var(--bg-white);
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: var(--text-dark);
    text-decoration: none;
}

.rating-btn:hover {
    background: var(--bg-light);
}

.rating-btn.active {
    background: var(--warning-orange);
    border-color: var(--warning-orange);
    color: white;
}

.rating-btn i {
    color: var(--warning-orange);
}

.rating-btn.active i {
    color: white;
}

.rating-count {
    background: var(--bg-light);
    padding: 2px 6px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
}

.rating-btn.active .rating-count {
    background: rgba(255, 255, 255, 0.3);
    color: white;
}

.results-info {
    background: var(--bg-light);
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}

.results-count {
    font-size: 0.875rem;
    color: var(--text-dark);
    font-weight: 600;
}

/* Reviews List */
.reviews-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.review-card {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    transition: all 0.2s ease;
    position: relative;
}

.review-card.unread {
    border-left: 4px solid var(--primary-dark);
    background: linear-gradient(90deg, #f8f7fa 0%, var(--bg-white) 100%);
}

.review-card.hidden-review {
    opacity: 0.6;
    border-left: 4px solid var(--text-light);
}

.review-card:hover {
    box-shadow: 0 4px 12px rgba(19, 3, 37, 0.12);
    transform: translateY(-1px);
}

.review-header {
    display: flex;
    gap: 16px;
    margin-bottom: 16px;
    align-items: flex-start;
}

.product-thumbnail {
    width: 80px;
    height: 80px;
    border-radius: 8px;
    object-fit: cover;
    border: 1px solid var(--border-light);
    flex-shrink: 0;
}

.review-info {
    flex: 1;
    min-width: 0;
}

.product-name {
    font-weight: 600;
    font-size: 1rem;
    color: var(--text-dark);
    margin-bottom: 4px;
}

.review-meta {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 8px;
}

.customer-name {
    font-size: 0.875rem;
    color: var(--text-light);
}

.rating-stars {
    display: inline-flex;
    gap: 2px;
}

.star {
    color: var(--warning-orange);
    font-size: 16px;
}

.star.empty {
    color: #d1d5db;
}

.review-date {
    font-size: 0.75rem;
    color: var(--text-light);
}

.review-content {
    margin-bottom: 16px;
}

.review-text {
    color: var(--text-dark);
    line-height: 1.6;
    font-size: 0.9375rem;
    margin-bottom: 12px;
}

.admin-reply-section {
    background: rgba(16, 185, 129, 0.05);
    border-left: 3px solid var(--success-green);
    padding: 12px 16px;
    border-radius: 8px;
    margin-top: 12px;
}

.admin-reply-label {
    font-weight: 600;
    color: var(--success-green);
    font-size: 0.875rem;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.admin-reply-text {
    color: var(--text-dark);
    font-size: 0.875rem;
    line-height: 1.5;
}

.review-actions {
    display: flex;
    gap: 8px;
    align-items: center;
}

.btn {
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
}

.btn-mark-read {
    background: var(--primary-dark);
    color: var(--bg-white);
}

.btn-mark-read:hover {
    background: #0a0118;
    transform: translateY(-1px);
}

.btn-view-product {
    background: var(--bg-light);
    color: var(--text-dark);
    border: 1px solid var(--border-light);
}

.btn-view-product:hover {
    background: var(--border-light);
    transform: translateY(-1px);
}

.badge {
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.badge-unread {
    background: var(--error-red);
    color: white;
    position: absolute;
    top: 16px;
    right: 16px;
}

.badge-hidden {
    background: var(--text-light);
    color: white;
    position: absolute;
    top: 16px;
    right: 16px;
}

.empty-state {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    padding: 60px 20px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.empty-state-icon {
    font-size: 64px;
    margin-bottom: 16px;
    opacity: 0.3;
}

.empty-state-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--text-dark);
    margin-bottom: 8px;
}

.empty-state-text {
    color: var(--text-light);
    font-size: 0.9375rem;
}

/* Responsive */
@media (max-width: 968px) {
    .reviews-container {
        padding: 10px 12px;
        max-width: 100%;
        margin-left: 0 !important;
    }
    
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        padding: 12px 16px;
    }
    
    .notifications-banner {
        padding: 16px;
    }
    
    .notifications-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .btn-dismiss-all {
        width: 100%;
        justify-content: center;
    }
    
    .stats-cards {
        grid-template-columns: 1fr 1fr;
    }
    
    .filters-grid {
        grid-template-columns: 1fr;
    }
    
    .rating-filter-buttons {
        justify-content: flex-start;
    }
    
    .review-header {
        flex-direction: column;
    }
    
    .product-thumbnail {
        width: 100%;
        height: 200px;
    }
    
    .review-actions {
        flex-direction: column;
        width: 100%;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>


      
            
           
        </div>

        
        <button type="submit" style="display: none;"></button>
    </form>
    
    
</div>
        <!-- Reviews List -->
        <?php if (empty($reviews)): ?>
            
        <?php else: ?>
            <div class="reviews-list">
                <?php foreach ($reviews as $review): ?>
                    <div class="review-card <?php echo !$review['is_read_by_seller'] ? 'unread' : ''; ?>" id="review-<?php echo $review['id']; ?>">
                    <?php if (!$review['is_read_by_seller']): ?>
    <span class="badge badge-unread">New</span>
<?php elseif ($review['is_hidden']): ?>
    <span class="badge badge-hidden"><i class="fas fa-eye-slash"></i> Hidden</span>
<?php endif; ?>
                        
                        <div class="review-header">
                            <img 
                                src="<?php echo htmlspecialchars($review['product_image'] ?: 'assets/images/placeholder.png'); ?>" 
                                alt="<?php echo htmlspecialchars($review['product_name']); ?>"
                                class="product-thumbnail"
                                onerror="this.src='assets/images/placeholder.png'"
                            >
                            
                            <div class="review-info">
                                <div class="product-name"><?php echo htmlspecialchars($review['product_name']); ?></div>
                                
                                <div class="review-meta">
                                    <div class="rating-stars">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star star <?php echo $i <= $review['rating'] ? '' : 'empty'; ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                    
                                    <span class="customer-name">
                                        by <?php 
                                            $customerName = trim(($review['first_name'] ?? '') . ' ' . ($review['last_name'] ?? ''));
                                            echo htmlspecialchars($customerName ?: $review['username']); 
                                        ?>
                                    </span>
                                    
                                    <span class="review-date">
                                        <?php echo date('M d, Y h:i A', strtotime($review['created_at'])); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="review-content">
                            <?php if (!empty($review['review_text'])): ?>
                                <div class="review-text">
                                    <?php echo nl2br(htmlspecialchars($review['review_text'])); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($review['admin_reply'])): ?>
                                <div class="admin-reply-section">
                                    <div class="admin-reply-label">
                                        <i class="fas fa-shield-alt"></i>
                                        Admin Response
                                    </div>
                                    <div class="admin-reply-text">
                                        <?php echo nl2br(htmlspecialchars($review['admin_reply'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="review-actions">
                            <?php if (!$review['is_read_by_seller']): ?>
                                <button 
                                    type="button" 
                                    class="btn btn-mark-read" 
                                    onclick="markReviewAsRead(<?php echo $review['id']; ?>)"
                                >
                                    <i class="fas fa-check"></i> Mark as Read
                                </button>
                            <?php endif; ?>
                            
                            <a 
                                href="view-products.php?product_id=<?php echo $review['product_id']; ?>" 
                                class="btn btn-view-product"
                            >
                                <i class="fas fa-external-link-alt"></i> View Product
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>




<main style="background: var(--bg-light); min-height: 100vh; padding: 0; margin-top: -30px;">
    <div class="reviews-container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-left">
                <a href="seller-dashboard.php" class="back-arrow" title="Back to Dashboard">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h1 class="page-header-title">Product Reviews</h1>
            </div>
        </div>

    
 <!-- Review Notifications Section -->
<?php if (!empty($reviewNotifications)): ?>
<div class="notifications-banner">
    


<div class="notifications-header">
    <h3 class="notifications-title">
        <i class="fas fa-bell"></i> Recent Review Updates
    </h3>
    
</div>

    <div class="notifications-list">
        <?php foreach ($reviewNotifications as $notif): ?>
            <div class="notification-wrapper">
                <a href="#" onclick="handleReviewNotificationClick(<?php echo $notif['id']; ?>, '<?php echo htmlspecialchars($notif['action_url'] ?: '', ENT_QUOTES); ?>'); return false;" style="text-decoration: none; color: inherit; display: block;">
                    <div class="notification-card <?php echo !$notif['is_read'] ? 'unread' : ''; ?>" id="notif-<?php echo $notif['id']; ?>">
                        <div class="notification-icon">
                            <i class="fas fa-star"></i>
                        </div>
                        <div class="notification-body">
                            <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                            <div class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                            <div class="notification-time">
                                <?php echo date('M d, Y h:i A', strtotime($notif['created_at'])); ?>
                            </div>
                        </div>
                        <button 
                            type="button" 
                            class="btn-dismiss" 
                            onclick="event.stopPropagation(); event.preventDefault(); dismissReviewNotification(<?php echo $notif['id']; ?>)"
                            title="Dismiss">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

</main>

<script>
// Clear review notifications when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Clear review notifications on page load
    fetch('ajax/clear-review-notifications.php', {
        method: 'POST',
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update badge to 0
            updateReviewBadge(0);
            
            // Also update in header if the function exists
            if (typeof loadReviewNotificationCount === 'function') {
                loadReviewNotificationCount();
            }
        }
    })
    .catch(error => {
        console.error('Error clearing review notifications:', error);
    });
});


function dismissReviewNotification(notificationId) {
    const notifCard = document.getElementById('notif-' + notificationId);
    if (notifCard) {
        notifCard.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
        notifCard.style.opacity = '0';
        notifCard.style.transform = 'translateX(20px)';
        
        setTimeout(() => {
            notifCard.remove();
            
            // Check if notifications list is empty
            const notifsList = document.querySelector('.notifications-list');
            if (notifsList && notifsList.children.length === 0) {
                const banner = document.querySelector('.notifications-banner');
                if (banner) {
                    banner.style.transition = 'opacity 0.3s ease';
                    banner.style.opacity = '0';
                    setTimeout(() => banner.remove(), 300);
                }
            }
        }, 300);
    }
    
    // Mark as read in backend
    fetch('ajax/mark-seller-notification-read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ notification_id: notificationId }),
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && typeof updateReviewBadge === 'function') {
            // Refresh badge count
            if (typeof loadReviewNotificationCount === 'function') {
                loadReviewNotificationCount();
            }
        }
    })
    .catch(error => {
        console.error('Error dismissing notification:', error);
    });
}


function markAllReviewNotificationsAsRead() {
    const notifCards = document.querySelectorAll('.notification-card');
    const notificationIds = [];
    
    notifCards.forEach(card => {
        const id = card.id.replace('notif-', '');
        if (id) {
            notificationIds.push(parseInt(id));
        }
        
        // Remove unread styling
        card.classList.remove('unread');
    });
    
    // Mark all as read in backend
    notificationIds.forEach(notificationId => {
        fetch('ajax/mark-seller-notification-read.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ notification_id: notificationId }),
            credentials: 'same-origin'
        }).catch(error => {
            console.error('Error marking notification as read:', error);
        });
    });
    
    // CRITICAL: Refresh badge count after all are marked
    setTimeout(() => {
        loadReviewNotificationCount();
    }, 500);
}
    
   function markAllSellerNotificationsAsRead() {
    fetch('ajax/mark-all-seller-notifications-read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update badge to 0
            updateSellerNotificationBadge(0);
            
            // Also update the review badge to 0 if this function exists
            if (typeof updateReviewBadge === 'function') {
                updateReviewBadge(0);
            }
            
            // Reload notifications to show them as read
            loadSellerNotifications();
            
            // CRITICAL: Also reload review notification count
            if (typeof loadReviewNotificationCount === 'function') {
                loadReviewNotificationCount();
            }
        }
    })
    .catch(error => {
        console.error('Error marking all notifications as read:', error);
    });
} 
function markReviewAsRead(reviewId) {
    fetch('ajax/mark-review-read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ review_id: reviewId }),
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update UI
            const reviewCard = document.getElementById('review-' + reviewId);
            if (reviewCard) {
                reviewCard.classList.remove('unread');
                const badge = reviewCard.querySelector('.badge-unread');
                if (badge) {
                    badge.remove();
                }
                const markReadBtn = reviewCard.querySelector('.btn-mark-read');
                if (markReadBtn) {
                    markReadBtn.remove();
                }
            }
            
            // Update unread count in stats
            const unreadStat = document.querySelector('.stat-icon.unread').nextElementSibling;
            if (unreadStat) {
                const currentCount = parseInt(unreadStat.textContent) || 0;
                unreadStat.textContent = Math.max(0, currentCount - 1);
            }
            
            // CRITICAL: Update the badge count
            loadReviewNotificationCount();
        }
    })
    .catch(error => {
        console.error('Error marking review as read:', error);
    });
}
   


function handleReviewNotificationClick(notificationId, actionUrl) {
    // Mark notification as read immediately
    const notifCard = document.getElementById('notif-' + notificationId);
    if (notifCard) {
        notifCard.classList.remove('unread');
    }
    
    // Send mark as read request
    const data = JSON.stringify({
        notification_id: notificationId
    });
    
    // Use sendBeacon for reliability when navigating
    if (navigator.sendBeacon) {
        const formData = new FormData();
        formData.append('notification_id', notificationId);
        navigator.sendBeacon('ajax/mark-seller-notification-read.php', formData);
    }
    
    // Also use fetch for immediate processing
    fetch('ajax/mark-seller-notification-read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: data,
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            // Update badge count
            if (typeof loadReviewNotificationCount === 'function') {
                loadReviewNotificationCount();
            }
        }
    })
    .catch(error => {
        console.error('Error marking notification as read:', error);
    });
    
    // Navigate to the appropriate page
    setTimeout(function() {
        if (actionUrl && actionUrl !== '' && actionUrl !== 'null' && actionUrl !== 'undefined') {
            // If action URL exists, use it
            window.location.href = actionUrl;
        } else {
            // Default to seller-reviews.php for review notifications
            window.location.href = 'seller-reviews.php';
        }
    }, 150);
}


// Function to update the review notification badge in the sidebar
function updateReviewBadge(count) {
    // Update header badge if it exists
    const headerBadge = document.getElementById('review-badge');
    if (headerBadge) {
        if (count > 0) {
            headerBadge.textContent = count;
            headerBadge.style.display = 'flex';
            headerBadge.classList.add('show');
        } else {
            headerBadge.style.display = 'none';
            headerBadge.classList.remove('show');
        }
    }
    
    // Update sidebar badge if it exists (from seller_header.php)
    const sidebarBadge = document.querySelector('.sidebar a[href="seller-reviews.php"] .notification-badge');
    if (sidebarBadge) {
        if (count > 0) {
            sidebarBadge.textContent = count;
            sidebarBadge.style.display = 'inline-block';
        } else {
            sidebarBadge.style.display = 'none';
        }
    }
}

// Load review notification count on page load
function loadReviewNotificationCount() {
    fetch('ajax/get-review-notification-count.php', {
        method: 'GET',
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateReviewBadge(data.count);
        }
    })
    .catch(error => {
        console.error('Error loading review notification count:', error);
    });
}

// Call on page load
document.addEventListener('DOMContentLoaded', function() {
    loadReviewNotificationCount();
});

</script>

<?php require_once 'includes/footer.php'; ?>
