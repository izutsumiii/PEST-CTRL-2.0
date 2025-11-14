<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireAdmin();

$message = '';
$error = '';

// Display stored messages
if (isset($_SESSION['admin_message'])) {
    $message = $_SESSION['admin_message'];
    unset($_SESSION['admin_message']);
}
if (isset($_SESSION['admin_error'])) {
    $error = $_SESSION['admin_error'];
    unset($_SESSION['admin_error']);
}

// Get filter parameters
$statusFilter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : 'all';
$searchTerm = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

$query = "SELECT r.*, 
          u.username as customer_name, 
          u.email as customer_email,
          p.name as product_name,
          p.id as product_id,
          s.username as seller_name,
          s.id as seller_id
          FROM product_reviews r
          JOIN users u ON r.user_id = u.id
          JOIN products p ON r.product_id = p.id
          JOIN users s ON p.seller_id = s.id
          WHERE 1=1";

$params = [];

// Status filter
if ($statusFilter !== 'all') {
    if ($statusFilter === 'hidden') {
        $query .= " AND r.is_hidden = 1";
    } else if ($statusFilter === 'visible') {
        $query .= " AND r.is_hidden = 0";
    } else if ($statusFilter === 'with_reply') {
        $query .= " AND r.seller_reply IS NOT NULL AND r.seller_reply != ''";
    } else if ($statusFilter === 'no_reply') {
        $query .= " AND (r.seller_reply IS NULL OR r.seller_reply = '')";
    }
}

// Search filter
if (!empty($searchTerm)) {
    $query .= " AND (r.review_text LIKE ? OR u.username LIKE ? OR p.name LIKE ? OR s.username LIKE ?)";
    $searchParam = "%$searchTerm%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$query .= " ORDER BY r.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

// Get reviews
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
    $stmt->bindValue($key + 1, $value, $paramType);
}
$stmt->execute();
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$countQuery = "SELECT COUNT(*) as total FROM product_reviews r
               JOIN users u ON r.user_id = u.id
               JOIN products p ON r.product_id = p.id
               JOIN users s ON p.seller_id = s.id
               WHERE 1=1";
$countParams = [];

if ($statusFilter !== 'all') {
    if ($statusFilter === 'hidden') {
        $countQuery .= " AND r.is_hidden = 1";
    } else if ($statusFilter === 'visible') {
        $countQuery .= " AND r.is_hidden = 0";
    } else if ($statusFilter === 'with_reply') {
        $countQuery .= " AND r.seller_reply IS NOT NULL AND r.seller_reply != ''";
    } else if ($statusFilter === 'no_reply') {
        $countQuery .= " AND (r.seller_reply IS NULL OR r.seller_reply = '')";
    }
}

if (!empty($searchTerm)) {
    $countQuery .= " AND (r.comment LIKE ? OR u.username LIKE ? OR p.name LIKE ? OR s.username LIKE ?)";
    $searchParam = "%$searchTerm%";
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
}

$stmt = $pdo->prepare($countQuery);
$stmt->execute($countParams);
$totalReviews = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalReviews / $limit);

// Get statistics
$stats = [];

// Total reviews
$stmt = $pdo->query("SELECT COUNT(*) as count FROM product_reviews");
$stats['total'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Visible reviews
$stmt = $pdo->query("SELECT COUNT(*) as count FROM product_reviews WHERE is_hidden = 0");
$stats['visible'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Hidden reviews
$stmt = $pdo->query("SELECT COUNT(*) as count FROM product_reviews WHERE is_hidden = 1");
$stats['hidden'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Reviews with seller reply
$stmt = $pdo->query("SELECT COUNT(*) as count FROM product_reviews WHERE seller_reply IS NOT NULL AND seller_reply != ''");
$stats['with_reply'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Reviews without seller reply
$stmt = $pdo->query("SELECT COUNT(*) as count FROM product_reviews WHERE seller_reply IS NULL OR seller_reply = ''");
$stats['no_reply'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

require_once 'includes/admin_header.php';
?>

<style>
    body {
        background: #f0f2f5 !important;
        color: #130325 !important;
        min-height: 100vh;
        margin: 0;
        font-family: 'Inter', 'Segoe UI', sans-serif;
    }

    /* Toast Notification */
    .toast-notification {
        position: fixed;
        top: 80px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 10000;
        min-width: 300px;
        max-width: 500px;
        padding: 16px 20px;
        border-radius: 12px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        animation: toastSlideIn 0.3s ease-out;
        opacity: 0;
        pointer-events: none;
    }
    .toast-notification.show {
        opacity: 1;
        pointer-events: auto;
    }
    .toast-notification.hide {
        animation: toastSlideOut 0.3s ease-out forwards;
    }
    @keyframes toastSlideIn {
        from {
            opacity: 0;
            transform: translateX(-50%) translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
    }
    @keyframes toastSlideOut {
        from {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
        to {
            opacity: 0;
            transform: translateX(-50%) translateY(-20px);
        }
    }
    .toast-success {
        background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
        color: #065f46;
        border: 2px solid #10b981;
    }
    .toast-error {
        background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
        color: #991b1b;
        border: 2px solid #ef4444;
    }
    .toast-notification i {
        font-size: 20px;
        flex-shrink: 0;
    }
    .toast-notification .toast-message {
        flex: 1;
        font-size: 14px;
        line-height: 1.4;
    }

    /* Page Header */
    .page-header {
        font-size: 18px !important;
        font-weight: 800 !important;
        color: #130325 !important;
        margin: -60px auto 12px auto !important;
        padding: 0 20px !important;
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
        flex-wrap: wrap !important;
        max-width: 1400px !important;
        text-shadow: none !important;
        position: relative !important;
        z-index: 1 !important;
    }
    .page-header h1 {
        font-size: 20px !important;
        font-weight: 800 !important;
        color: #130325 !important;
        margin: 0 !important;
        text-shadow: none !important;
    }

    /* Container */
    .container {
        max-width: 1400px;
        margin: -20px auto 30px auto;
        padding: 0 20px;
    }

    /* Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 16px;
        margin-bottom: 20px;
    }
    .stat-card {
        background: #ffffff;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 8px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .stat-number {
        font-size: 28px;
        font-weight: 700;
        color: #130325;
        margin-bottom: 8px;
    }
    .stat-label {
        font-size: 12px;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
    }
    .stat-card.total { border-left: 4px solid #130325; }
    .stat-card.visible { border-left: 4px solid #22c55e; }
    .stat-card.hidden { border-left: 4px solid #ef4444; }
    .stat-card.with-reply { border-left: 4px solid #3b82f6; }
    .stat-card.no-reply { border-left: 4px solid #f59e0b; }

    /* Filter Section */
    .filter-section {
        background: #ffffff;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 8px;
        padding: 24px;
        margin-bottom: 16px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .filter-row {
        display: flex;
        align-items: center;
        gap: 20px;
        flex-wrap: wrap;
        justify-content: center;
    }
    .status-filters {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        align-items: center;
    }
    .status-filters a {
        padding: 8px 16px;
        background: #f8f9fa;
        color: #130325;
        text-decoration: none;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        transition: all 0.2s ease;
        border: 1px solid #e5e7eb;
    }
    .status-filters a:hover {
        background: #e9ecef;
        border-color: #dee2e6;
    }
    .status-filters a.active {
        background: #FFD736;
        color: #130325;
        border-color: #FFD736;
    }

    /* Search Form */
    .search-form {
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: nowrap;
    }
    .search-input {
        flex: 1;
        min-width: 260px;
        max-width: 320px;
        padding: 10px 14px;
        background: #ffffff;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        color: #130325;
        font-size: 14px;
        height: 40px;
        transition: all 0.2s ease;
    }
    .search-input:focus {
        outline: none;
        border-color: #FFD736;
        box-shadow: 0 0 0 3px rgba(255,215,54,0.12);
    }
    .search-input::placeholder {
        color: #9ca3af;
    }
    .btn-search {
        background: linear-gradient(135deg, #FFD736, #FFC107);
        color: #130325;
        border: none;
        height: 40px;
        width: 40px;
        min-width: 40px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        cursor: pointer;
        flex-shrink: 0;
    }
    .btn-clear {
        background: #ffffff;
        color: #6b7280;
        border: 1px solid #e5e7eb;
        padding: 8px 14px;
        border-radius: 8px;
        text-decoration: none;
        font-size: 13px;
        font-weight: 600;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .btn-clear:hover {
        background: #f3f4f6;
        border-color: #d1d5db;
    }

    /* Reviews Container */
    .reviews-container {
        background: #ffffff;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 8px;
        padding: 24px;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }

    /* Review Item */
    .review-item {
        padding: 20px;
        border-bottom: 1px solid rgba(19, 3, 37, 0.1);
        transition: background 0.2s ease;
    }
    .review-item:last-child {
        border-bottom: none;
    }
    .review-item:hover {
        background: rgba(255, 215, 54, 0.03);
    }
    .review-item.review-hidden {
        opacity: 0.6;
        background: rgba(108, 117, 125, 0.05);
    }
    
    .review-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 12px;
        gap: 16px;
    }
    .review-user-info {
        flex: 1;
    }
    .review-username {
        font-size: 15px;
        font-weight: 700;
        color: #130325;
        margin-bottom: 4px;
    }
    .review-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        align-items: center;
        font-size: 12px;
        color: rgba(19, 3, 37, 0.5);
    }
    .review-meta-item {
        display: flex;
        align-items: center;
        gap: 4px;
    }
    .review-meta-item i {
        font-size: 11px;
    }
    .review-rating {
        color: #FFD736;
        font-size: 16px;
    }
    
    .review-text {
        font-size: 14px;
        color: #130325;
        line-height: 1.6;
        margin-bottom: 12px;
        white-space: pre-wrap;
    }
    
    .review-hidden-message {
        padding: 12px 16px;
        background: rgba(108, 117, 125, 0.1);
        border-left: 3px solid #6c757d;
        border-radius: 4px;
        display: flex;
        align-items: center;
        gap: 10px;
        color: rgba(19, 3, 37, 0.5);
        margin-bottom: 12px;
    }
    .review-hidden-message i {
        font-size: 16px;
        color: #6c757d;
    }
    .review-hidden-message em {
        font-size: 13px;
        font-style: italic;
    }
    .hidden-reason {
        font-size: 12px;
        color: rgba(19, 3, 37, 0.4);
        margin-top: 4px;
    }
    
    .seller-reply {
        margin-top: 12px;
        padding: 14px 16px;
        background: rgba(255, 215, 54, 0.08);
        border-left: 3px solid #FFD736;
        border-radius: 4px;
        margin-bottom: 12px;
    }
    .reply-header {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
    }
    .reply-header i {
        color: #FFD736;
        font-size: 14px;
    }
    .reply-label {
        font-weight: 700;
        color: #130325;
        font-size: 13px;
    }
    .reply-date {
        font-size: 11px;
        color: rgba(19, 3, 37, 0.4);
        margin-left: auto;
    }
    .reply-text {
        color: #130325;
        font-size: 13px;
        line-height: 1.5;
        white-space: pre-wrap;
    }
    
    .review-actions {
        margin-top: 12px;
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }
    .btn-hide-review, .btn-unhide-review, .btn-view-product {
        background: transparent;
        border: none;
        padding: 8px 14px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s ease;
        border-radius: 6px;
        text-decoration: none;
    }
    .btn-hide-review {
        color: #dc3545;
    }
    .btn-hide-review:hover {
        background: rgba(220, 53, 69, 0.1);
    }
    .btn-unhide-review {
        color: #28a745;
    }
    .btn-unhide-review:hover {
        background: rgba(40, 167, 69, 0.1);
    }
    .btn-view-product {
        color: #130325;
        background: rgba(255, 215, 54, 0.15);
    }
    .btn-view-product:hover {
        background: rgba(255, 215, 54, 0.25);
    }
    .btn-hide-review i, .btn-unhide-review i, .btn-view-product i {
        font-size: 13px;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #6b7280;
    }
    .empty-state i {
        font-size: 48px;
        color: #d1d5db;
        margin-bottom: 16px;
        display: block;
    }
    .empty-state h3 {
        color: #6b7280;
        font-size: 18px;
        margin-bottom: 8px;
    }
    .empty-state p {
        color: #9ca3af;
        font-size: 14px;
    }

    /* Pagination */
    .pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 20px;
    }
    .page-link {
        padding: 8px 14px;
        background: #ffffff !important;
        color: #130325 !important;
        text-decoration: none;
        border-radius: 6px;
        border: 1px solid #e5e7eb !important;
        font-weight: 600;
        font-size: 13px;
        transition: all 0.2s ease;
    }
    .page-link i {
        color: #130325 !important;
    }
    .page-link:hover {
        background: #f8f9fa !important;
        color: #130325 !important;
        border-color: #130325 !important;
        transform: translateY(-1px);
    }
    .page-link:hover i {
        color: #130325 !important;
    }
    .page-link.active {
        background: #130325 !important;
        color: #ffffff !important;
        border-color: #130325 !important;
        box-shadow: 0 2px 8px rgba(19, 3, 37, 0.3);
    }
    .page-link.active i {
        color: #ffffff !important;
    }

    /* Confirmation Modal */
    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.35);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 9999;
    }
    .modal-dialog {
        width: 360px;
        max-width: 90vw;
        background: #ffffff;
        border: none;
        border-radius: 12px;
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
        letter-spacing: .3px;
    }
    .modal-close {
        background: transparent;
        border: none;
        color: #F9F9F9;
        font-size: 16px;
        line-height: 1;
        cursor: pointer;
    }
    .modal-body {
        padding: 12px;
        color: #130325;
        font-size: 13px;
    }
    .modal-actions {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
        padding: 0 12px 12px 12px;
    }
    .btn-outline {
        background: #ffffff;
        color: #130325;
        border: none;
        border-radius: 8px;
        padding: 6px 10px;
        font-weight: 700;
        font-size: 12px;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .btn-primary-y {
        background: linear-gradient(135deg, #FFD736 0%, #FFC107 100%);
        color: #130325;
        border: none;
        border-radius: 8px;
        padding: 6px 10px;
        font-weight: 700;
        font-size: 12px;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    @media (max-width: 1200px) {
        .stats-grid {
            grid-template-columns: repeat(3, 1fr);
        }
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .filter-row {
            flex-direction: column;
            align-items: stretch;
        }
        .search-form {
            flex-direction: column;
        }
        .search-input {
            max-width: 100%;
        }
    }
</style>

<?php if ($message): ?>
<div class="toast-notification toast-success" id="successToast">
    <i class="fas fa-check-circle"></i>
    <span class="toast-message"><?php echo htmlspecialchars($message); ?></span>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="toast-notification toast-error" id="errorToast">
    <i class="fas fa-exclamation-triangle"></i>
    <span class="toast-message"><?php echo htmlspecialchars($error); ?></span>
</div>
<?php endif; ?>

<div class="page-header">
    <h1><i class="fas fa-star"></i> Review Management</h1>
</div>

<div class="container">
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card total">
            <div class="stat-number"><?php echo $stats['total']; ?></div>
            <div class="stat-label">Total Reviews</div>
        </div>
        <div class="stat-card visible">
            <div class="stat-number"><?php echo $stats['visible']; ?></div>
            <div class="stat-label">Visible</div>
        </div>
        <div class="stat-card hidden">
            <div class="stat-number"><?php echo $stats['hidden']; ?></div>
            <div class="stat-label">Hidden</div>
        </div>
        <div class="stat-card with-reply">
            <div class="stat-number"><?php echo $stats['with_reply']; ?></div>
            <div class="stat-label">With Reply</div>
        </div>
        <div class="stat-card no-reply">
            <div class="stat-number"><?php echo $stats['no_reply']; ?></div>
            <div class="stat-label">No Reply</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-section">
        <div class="filter-row">
            <div class="status-filters">
                <a href="admin-reviews.php?<?php echo !empty($searchTerm) ? "search=" . urlencode($searchTerm) . "&" : ""; ?>status=all" 
                   class="<?php echo $statusFilter === 'all' ? 'active' : ''; ?>">
                    All (<?php echo $stats['total']; ?>)
                </a>
                <a href="admin-reviews.php?<?php echo !empty($searchTerm) ? "search=" . urlencode($searchTerm) . "&" : ""; ?>status=visible" 
                   class="<?php echo $statusFilter === 'visible' ? 'active' : ''; ?>">
                    Visible (<?php echo $stats['visible']; ?>)
                </a>
                <a href="admin-reviews.php?<?php echo !empty($searchTerm) ? "search=" . urlencode($searchTerm) . "&" : ""; ?>status=hidden" 
                   class="<?php echo $statusFilter === 'hidden' ? 'active' : ''; ?>">
                    Hidden (<?php echo $stats['hidden']; ?>)
                </a>
                <a href="admin-reviews.php?<?php echo !empty($searchTerm) ? "search=" . urlencode($searchTerm) . "&" : ""; ?>status=with_reply" 
                   class="<?php echo $statusFilter === 'with_reply' ? 'active' : ''; ?>">
                    With Reply (<?php echo $stats['with_reply']; ?>)
                </a>
                <a href="admin-reviews.php?<?php echo !empty($searchTerm) ? "search=" . urlencode($searchTerm) . "&" : ""; ?>status=no_reply" 
                   class="<?php echo $statusFilter === 'no_reply' ? 'active' : ''; ?>">
                    No Reply (<?php echo $stats['no_reply']; ?>)
                </a>
            </div>
            
            <form method="GET" class="search-form">
                <?php if ($statusFilter !== 'all'): ?>
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                <?php endif; ?>
                <input type="text" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" 
                       placeholder="Search reviews, customers, products..." class="search-input">
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i>
                </button>
                <?php if (!empty($searchTerm)): ?>
                    <a href="admin-reviews.php?<?php echo $statusFilter !== 'all' ? "status=" . urlencode($statusFilter) : ""; ?>" class="btn-clear">
                        <i class="fas fa-times"></i> Clear
                    </a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Reviews List -->
    <div class="reviews-container">
        <?php if (empty($reviews)): ?>
            <div class="empty-state">
                <i class="fas fa-comments"></i>
                <h3>No reviews found</h3>
                <p>
                    <?php if (!empty($searchTerm)): ?>
                        Try adjusting your search terms or filters.
                    <?php else: ?>
                        Reviews will appear here once customers start reviewing products.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <?php foreach ($reviews as $review): ?>
                <?php
                $stars = str_repeat('★', $review['rating']) . str_repeat('☆', 5 - $review['rating']);
                $isHidden = $review['is_hidden'];
                $hasReply = $review['seller_reply'] && trim($review['seller_reply']) !== '';
                ?>
                <div class="review-item <?php echo $isHidden ? 'review-hidden' : ''; ?>" data-review-id="<?php echo $review['id']; ?>">
                    <div class="review-header">
                        <div class="review-user-info">
                            <div class="review-username"><?php echo htmlspecialchars($review['customer_name']); ?></div>
                            <div class="review-meta">
                                <span class="review-meta-item">
                                    <i class="fas fa-calendar"></i>
                                    <?php echo date('M j, Y', strtotime($review['created_at'])); ?>
                                </span>
                                <span class="review-meta-item">
                                    <i class="fas fa-box"></i>
                                    <?php echo htmlspecialchars($review['product_name']); ?>
                                </span>
                                <span class="review-meta-item">
                                    <i class="fas fa-store"></i>
                                    <?php echo htmlspecialchars($review['seller_name']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="review-rating"><?php echo $stars; ?></div>
                    </div>
                    
                    <?php if ($isHidden): ?>
                        <div class="review-hidden-message">
                            <i class="fas fa-eye-slash"></i>
                            <div>
                                <em>This review is hidden</em>
                                <?php if ($review['hidden_reason']): ?>
                                    <div class="hidden-reason">
                                        <strong>Reason:</strong> <?php echo htmlspecialchars($review['hidden_reason']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="review-text"><?php echo nl2br(htmlspecialchars($review['review_text'])); ?></div>
                    <?php endif; ?>
                    
                    <?php if ($hasReply): ?>
                        <div class="seller-reply">
                            <div class="reply-header">
                                <i class="fas fa-store"></i>
                                <span class="reply-label">Seller Reply</span>
                                <?php if ($review['seller_replied_at']): ?>
                                    <span class="reply-date"><?php echo date('M j, Y', strtotime($review['seller_replied_at'])); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="reply-text"><?php echo nl2br(htmlspecialchars($review['seller_reply'])); ?></div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="review-actions">
                        <a href="admin-products.php?product_id=<?php echo $review['product_id']; ?>" class="btn-view-product">
                            <i class="fas fa-box"></i> View Product
                        </a>
                        <?php if ($isHidden): ?>
                            <button class="btn-unhide-review" onclick="openHideModal(<?php echo $review['id']; ?>, 'unhide', '<?php echo htmlspecialchars($review['customer_name'], ENT_QUOTES); ?>'); return false;">
                                <i class="fas fa-eye"></i> Unhide Review
                            </button>
                        <?php else: ?>
                            <button class="btn-hide-review" onclick="openHideModal(<?php echo $review['id']; ?>, 'hide', '<?php echo htmlspecialchars($review['customer_name'], ENT_QUOTES); ?>'); return false;">
                                <i class="fas fa-eye-slash"></i> Hide Review
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                    $baseUrl = "admin-reviews.php?" . http_build_query(['status' => $statusFilter, 'search' => $searchTerm]);
                    ?>
                    
                    <?php if ($page > 1): ?>
                        <a href="<?php echo $baseUrl; ?>&page=<?php echo $page - 1; ?>" class="page-link">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php 
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <a href="<?php echo $baseUrl; ?>&page=<?php echo $i; ?>" 
                           class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo $baseUrl; ?>&page=<?php echo $page + 1; ?>" class="page-link">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// Auto-dismiss toast notifications
document.addEventListener('DOMContentLoaded', function() {
    const successToast = document.getElementById('successToast');
    const errorToast = document.getElementById('errorToast');
    
    function showAndDismissToast(toast) {
        if (toast) {
            setTimeout(() => {
                toast.classList.add('show');
            }, 10);
            
            setTimeout(() => {
                toast.classList.remove('show');
                toast.classList.add('hide');
                
                setTimeout(() => {
                    toast.remove();
                }, 300);
            }, 1500);
        }
    }
    
    showAndDismissToast(successToast);
    showAndDismissToast(errorToast);
});

// Hide/Unhide Review Functions
let currentReviewId = null;
let currentReviewAction = null;

function openHideModal(reviewId, action, customerName) {
    console.log('openHideModal called with reviewId:', reviewId, 'action:', action, 'customerName:', customerName);
    currentReviewId = reviewId;
    currentReviewAction = action;
    
    const modal = document.getElementById('hideReviewModal');
    const titleEl = document.getElementById('hideModalTitle');
    const messageEl = document.getElementById('hideModalMessage');
    const reasonGroup = document.getElementById('hideReasonGroup');
    const reasonInput = document.getElementById('hideReasonInput');
    const confirmBtn = document.getElementById('hideConfirmBtn');
    
    if (action === 'hide') {
        titleEl.textContent = 'Hide Review';
        messageEl.innerHTML = `Hide review from <strong>${customerName}</strong>?<br><small style="color: rgba(19, 3, 37, 0.6);">The customer and seller will be notified.</small>`;
        reasonGroup.style.display = 'block';
        reasonInput.value = '';
        confirmBtn.innerHTML = '<i class="fas fa-eye-slash"></i> Hide Review';
        confirmBtn.className = 'btn-primary-y';
    } else {
        titleEl.textContent = 'Unhide Review';
        messageEl.innerHTML = `Unhide review from <strong>${customerName}</strong>?<br><small style="color: rgba(19, 3, 37, 0.6);">This review will be visible again.</small>`;
        reasonGroup.style.display = 'none';
        confirmBtn.innerHTML = '<i class="fas fa-eye"></i> Unhide Review';
        confirmBtn.className = 'btn-primary-y';
    }
    
    if (modal) {
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        if (action === 'hide') {
            setTimeout(() => reasonInput && reasonInput.focus(), 100);
        }
    }
}

function closeHideModal() {
    const modal = document.getElementById('hideReviewModal');
    const reasonInput = document.getElementById('hideReasonInput');
    
    if (modal) {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
    if (reasonInput) {
        reasonInput.value = '';
    }
    currentReviewId = null;
    currentReviewAction = null;
}

function submitHideAction() {
    console.log('submitHideAction called, currentReviewId:', currentReviewId, 'currentReviewAction:', currentReviewAction);
    const reasonInput = document.getElementById('hideReasonInput');
    const reason = reasonInput ? reasonInput.value.trim() : '';
    
    if (currentReviewAction === 'hide' && !reason) {
        alert('Please provide a reason for hiding this review');
        return;
    }
    
    if (currentReviewId === null || currentReviewId === undefined || !currentReviewAction) {
        console.error('currentReviewId or currentReviewAction is null/undefined');
        alert('Error: Review ID or action not found');
        return;
    }
    
    console.log('Sending hide/unhide request with:', { review_id: currentReviewId, action: currentReviewAction, reason: reason });
    
    const confirmBtn = document.getElementById('hideConfirmBtn');
    if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    }
    
    fetch('../ajax/admin-hide-review.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            review_id: currentReviewId,
            action: currentReviewAction,
            reason: reason
        })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            return response.text().then(text => {
                throw new Error('Invalid JSON response: ' + text.substring(0, 100));
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            closeHideModal();
            showHideSuccessModal(currentReviewAction);
            
            // Reload page after 1.5 seconds to show updated review list
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showHideErrorModal(data.message || 'Failed to process request');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        let errorMessage = 'Error processing request. Please try again.';
        if (error.message) {
            errorMessage = error.message;
        }
        showHideErrorModal(errorMessage);
    })
    .finally(() => {
        if (confirmBtn) {
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = currentReviewAction === 'hide' 
                ? '<i class="fas fa-eye-slash"></i> Hide Review' 
                : '<i class="fas fa-eye"></i> Unhide Review';
        }
    });
}

function showHideSuccessModal(action) {
    const modal = document.getElementById('hideSuccessModal');
    const message = document.getElementById('hideSuccessMessage');
    if (message) {
        message.textContent = action === 'hide' 
            ? 'Review hidden successfully! The customer and seller have been notified.' 
            : 'Review unhidden successfully! This review is now visible again.';
    }
    if (modal) {
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
}

function closeHideSuccessModal() {
    const modal = document.getElementById('hideSuccessModal');
    if (modal) {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
}

function showHideErrorModal(errorMessage) {
    const modal = document.getElementById('hideErrorModal');
    const message = document.getElementById('hideErrorMessage');
    if (message) {
        message.textContent = errorMessage;
    }
    if (modal) {
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
}

function closeHideErrorModal() {
    const modal = document.getElementById('hideErrorModal');
    if (modal) {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.style.display = 'none';
            this.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }
    });
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        });
    }
});
</script>

<!-- Hide/Unhide Review Modal -->
<div id="hideReviewModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="modal-dialog" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div class="modal-title" id="hideModalTitle">Hide Review</div>
            <button type="button" class="modal-close" onclick="closeHideModal()">×</button>
        </div>
        <div class="modal-body">
            <p id="hideModalMessage" style="margin: 0 0 12px 0; color: #130325; font-size: 12px; line-height: 1.5; font-weight: 500;"></p>
            <div class="form-group" id="hideReasonGroup" style="margin-bottom: 8px;">
                <label for="hideReasonInput" style="font-size: 11px; color: #6b7280; margin-bottom: 6px; display: block; font-weight: 600;">Reason for hiding:</label>
                <textarea 
                    id="hideReasonInput" 
                    maxlength="255" 
                    rows="3"
                    placeholder="Explain why this review is being hidden..."
                    style="padding: 8px 10px; border: 1px solid rgba(0,0,0,0.15); border-radius: 6px; font-size: 13px; width: 100%; font-family: inherit; resize: vertical;"></textarea>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-outline" onclick="closeHideModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="button" class="btn-primary-y" id="hideConfirmBtn" onclick="submitHideAction()">
                <i class="fas fa-eye-slash"></i> Hide Review
            </button>
        </div>
    </div>
</div>

<!-- Hide/Unhide Success Modal -->
<div id="hideSuccessModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" style="display: none;">
    <div class="modal-dialog" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div class="modal-title">Success</div>
            <button type="button" class="modal-close" onclick="closeHideSuccessModal()">×</button>
        </div>
        <div class="modal-body">
            <div style="text-align: center; padding: 10px 0;">
                <i class="fas fa-check-circle" style="font-size: 48px; color: #28a745; margin-bottom: 12px;"></i>
                <p id="hideSuccessMessage" style="margin: 0; color: #130325; font-size: 14px; line-height: 1.5; font-weight: 500;"></p>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-primary-y" onclick="closeHideSuccessModal()">
                <i class="fas fa-check"></i> Got it!
            </button>
        </div>
    </div>
</div>

<!-- Hide/Unhide Error Modal -->
<div id="hideErrorModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" style="display: none;">
    <div class="modal-dialog" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div class="modal-title">Error</div>
            <button type="button" class="modal-close" onclick="closeHideErrorModal()">×</button>
        </div>
        <div class="modal-body">
            <div style="text-align: center; padding: 10px 0;">
                <i class="fas fa-exclamation-circle" style="font-size: 48px; color: #dc3545; margin-bottom: 12px;"></i>
                <p id="hideErrorMessage" style="margin: 0; color: #130325; font-size: 14px; line-height: 1.5; font-weight: 500;"></p>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-primary-y" onclick="closeHideErrorModal()">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>

</body>
</html>
