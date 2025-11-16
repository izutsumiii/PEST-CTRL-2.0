<?php
// Turn off error display for JSON requests to prevent breaking JSON output
if (isset($_GET['as']) && $_GET['as'] === 'json') {
    error_reporting(0);
    ini_set('display_errors', 0);
}

date_default_timezone_set('Asia/Manila');
require_once 'config/database.php';
require_once 'includes/functions.php';

// Ensure product_id and review_id columns exist in notifications table
try {
    $checkColumn = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'product_id'");
    if ($checkColumn->rowCount() == 0) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN product_id INT NULL AFTER order_id");
    }
    
    $checkReviewColumn = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'review_id'");
    if ($checkReviewColumn->rowCount() == 0) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN review_id INT NULL AFTER product_id");
    }
} catch (Exception $e) {
    error_log("Error checking/adding columns: " . $e->getMessage());
}

// Handle delete notifications request
if (isset($_POST['delete_notifications'])) {
    session_start();
    if (isset($_SESSION['user_id'])) {
        try {
            $userId = $_SESSION['user_id'];
            
            // Delete custom notifications from notifications table
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            // Hide ALL order status updates by creating hidden_notifications entries for all user's orders
            // First, create the table if it doesn't exist
            $pdo->exec("CREATE TABLE IF NOT EXISTS hidden_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                order_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_order (user_id, order_id)
            )");
            
            // Get all order IDs for this user
            $stmt = $pdo->prepare("SELECT id FROM orders WHERE user_id = ?");
            $stmt->execute([$userId]);
            $userOrders = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Insert all order IDs into hidden_notifications to hide them
            if (!empty($userOrders)) {
                $placeholders = str_repeat('?,', count($userOrders) - 1) . '?';
                $stmt = $pdo->prepare("INSERT IGNORE INTO hidden_notifications (user_id, order_id) VALUES " . 
                    implode(',', array_fill(0, count($userOrders), "($userId, ?)")));
                $stmt->execute($userOrders);
            }
            
            $successMessage = "All notifications have been deleted successfully.";
            
            // Redirect to refresh the page and show updated notifications
            header("Location: customer-notifications.php?deleted=1");
            exit;
        } catch (Exception $e) {
            $errorMessage = "Error deleting notifications: " . $e->getMessage();
        }
    }
}

// Check for JSON request first, before requiring login
if (isset($_GET['as']) && $_GET['as'] === 'json') {
    // For JSON requests, we need to check login status differently
    session_start();
    if (!isset($_SESSION['user_id'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Not logged in', 'items' => []]);
        exit;
    }
    $userId = $_SESSION['user_id'];
} else {
    // For regular page requests, use normal login check
    require_once 'includes/header.php';
    requireLogin();
    $userId = $_SESSION['user_id'];
}
// Simplified query - get ALL notifications directly, then enrich with order/return data
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(n.order_id, 0) as order_id,
        COALESCE(o.status, 'notification') as status,
        COALESCE(n.created_at, o.created_at) as created_at,
        COALESCE(o.updated_at, n.created_at) as updated_at,
        COALESCE(NULLIF(pt.payment_status, ''), NULL) as payment_status,
        IF(nr.read_at IS NULL OR COALESCE(n.created_at, o.updated_at, o.created_at) > nr.read_at, 0, 1) as is_read,
        rr.status as return_status,
        rr.processed_at as return_updated_at,
        n.message,
        n.type,
        n.id as notification_id,
        n.product_id,
        n.review_id,
        GREATEST(
            COALESCE(n.created_at, o.updated_at, o.created_at),
            COALESCE(rr.processed_at, '1970-01-01 00:00:00')
        ) as sort_date
    FROM notifications n
    LEFT JOIN orders o ON n.order_id = o.id AND n.user_id = o.user_id
    LEFT JOIN payment_transactions pt ON pt.id = o.payment_transaction_id
    LEFT JOIN return_requests rr ON rr.order_id = o.id
    LEFT JOIN (
        SELECT 
            COALESCE(notification_id, 0) as notification_id,
            COALESCE(order_id, 0) as order_id,
            MAX(read_at) as read_at
        FROM notification_reads
        WHERE user_id = ?
        GROUP BY notification_id, order_id
    ) nr ON (nr.notification_id = n.id OR (nr.notification_id = 0 AND nr.order_id = n.order_id))
    WHERE n.user_id = ?
    
    UNION ALL
    
    -- Also show orders that don't have notifications yet (for order status tracking)
    SELECT 
        o.id as order_id,
        o.status,
        o.created_at,
        o.updated_at,
        COALESCE(NULLIF(pt.payment_status, ''), NULL) as payment_status,
        IF(nr.read_at IS NULL OR GREATEST(COALESCE(o.updated_at, o.created_at), COALESCE(rr.processed_at, o.created_at)) > nr.read_at, 0, 1) as is_read,
        rr.status as return_status,
        rr.processed_at as return_updated_at,
        NULL as message,
        'order_update' as type,
        NULL as notification_id,
        NULL as product_id,
        NULL as review_id,
        GREATEST(
            COALESCE(o.updated_at, o.created_at),
            COALESCE(rr.processed_at, '1970-01-01 00:00:00')
        ) as sort_date
    FROM orders o
    LEFT JOIN payment_transactions pt ON pt.id = o.payment_transaction_id
    LEFT JOIN return_requests rr ON rr.order_id = o.id
    LEFT JOIN (
        SELECT user_id, order_id, MAX(read_at) as read_at
        FROM notification_reads
        WHERE user_id = ?
        GROUP BY user_id, order_id
    ) nr ON nr.user_id = o.user_id AND nr.order_id = o.id
    WHERE o.user_id = ?
    AND o.id NOT IN (
        SELECT DISTINCT order_id 
        FROM notifications 
        WHERE user_id = ? AND order_id IS NOT NULL
    )
    
    ORDER BY sort_date DESC
");
try {
    // First, let's verify notifications exist in database
    $testStmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ?");
    $testStmt->execute([$userId]);
    $testResult = $testStmt->fetch(PDO::FETCH_ASSOC);
    $totalNotificationsInDB = $testResult['count'] ?? 0;
    error_log('Customer Notifications - Total notifications in DB for user ' . $userId . ': ' . $totalNotificationsInDB);
    
    // Get sample notifications to verify they exist
    $sampleStmt = $pdo->prepare("SELECT id, order_id, type, message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $sampleStmt->execute([$userId]);
    $sampleNotifications = $sampleStmt->fetchAll(PDO::FETCH_ASSOC);
    error_log('Customer Notifications - Sample notifications: ' . json_encode($sampleNotifications));
    
    // Parameters: 1 (notification_reads for notifications), 2 (notifications WHERE), 3 (notification_reads for orders), 4 (orders WHERE), 5 (orders NOT IN)
    $stmt->execute([$userId, $userId, $userId, $userId, $userId]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug: Log notification types found
    $notificationTypes = [];
    foreach ($events as $event) {
        $type = $event['type'] ?? 'unknown';
        $notificationTypes[$type] = ($notificationTypes[$type] ?? 0) + 1;
    }
    error_log('Customer Notifications - Query returned ' . count($events) . ' notifications for user ' . $userId);
    error_log('Customer Notifications - Types found: ' . json_encode($notificationTypes));
    error_log('Customer Notifications - DB has ' . $totalNotificationsInDB . ' notifications, query returned ' . count($events));
} catch (PDOException $e) {
    error_log("Error fetching notifications: " . $e->getMessage());
    // If it's a column error, try to add the column and retry
    if (strpos($e->getMessage(), 'product_id') !== false) {
        try {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN product_id INT NULL AFTER order_id");
            $stmt->execute([$userId, $userId, $userId, $userId, $userId]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Debug: Log notification types found after retry
            $notificationTypes = [];
            foreach ($events as $event) {
                $type = $event['type'] ?? 'unknown';
                $notificationTypes[$type] = ($notificationTypes[$type] ?? 0) + 1;
            }
            error_log('Customer Notifications - After retry: Found ' . count($events) . ' notifications. Types: ' . json_encode($notificationTypes));
        } catch (Exception $retryError) {
            error_log("Error retrying after adding column: " . $retryError->getMessage());
            $events = [];
        }
    } else {
        $events = [];
    }
}

// JSON mode for header popup
if (isset($_GET['as']) && $_GET['as'] === 'json') {
    // Clear any previous output and turn off all error reporting
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Turn off all error reporting for JSON requests
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 0);
    
    header('Content-Type: application/json');
    
    try {
        $items = [];
        $unreadCount = 0;
        
        foreach ($events as $e) {
            $isRead = isset($e['is_read']) ? (bool)$e['is_read'] : false;
            if (!$isRead) {
                $unreadCount++;
            }
            
            // Get the actual order status
            $actualOrderStatus = (string)($e['status'] ?? 'unknown');
            $mostRecentUpdate = $e['updated_at'] ?: $e['created_at'];
            
            if (!empty($e['return_updated_at']) && strtotime($e['return_updated_at']) > strtotime($mostRecentUpdate)) {
                $mostRecentUpdate = $e['return_updated_at'];
            }
            
            // For seller_reply notifications, ensure we have product_id and review_id
            $productId = !empty($e['product_id']) ? (int)$e['product_id'] : null;
            $reviewId = !empty($e['review_id']) ? (int)$e['review_id'] : null;
            
            // If seller_reply but missing product_id or review_id, try to get from database
            if ($e['type'] === 'seller_reply' && (!$productId || !$reviewId)) {
                try {
                    $notifId = !empty($e['notification_id']) ? (int)$e['notification_id'] : null;
                    if ($notifId) {
                        $fixStmt = $pdo->prepare("SELECT product_id, review_id FROM notifications WHERE id = ?");
                        $fixStmt->execute([$notifId]);
                        $fixData = $fixStmt->fetch(PDO::FETCH_ASSOC);
                        if ($fixData) {
                            if (!$productId && !empty($fixData['product_id'])) {
                                $productId = (int)$fixData['product_id'];
                                error_log("Fixed missing product_id for notification $notifId: $productId");
                            }
                            if (!$reviewId && !empty($fixData['review_id'])) {
                                $reviewId = (int)$fixData['review_id'];
                                error_log("Fixed missing review_id for notification $notifId: $reviewId");
                            }
                        }
                        
                        // If still missing, try to get from review_id -> product_id
                        if ($reviewId && !$productId) {
                            $prodStmt = $pdo->prepare("SELECT product_id FROM product_reviews WHERE id = ?");
                            $prodStmt->execute([$reviewId]);
                            $prodData = $prodStmt->fetch(PDO::FETCH_ASSOC);
                            if ($prodData && !empty($prodData['product_id'])) {
                                $productId = (int)$prodData['product_id'];
                                error_log("Fixed missing product_id from review $reviewId: $productId");
                            }
                        }
                    }
                } catch (Exception $fixError) {
                    error_log("Error fixing seller_reply notification data: " . $fixError->getMessage());
                }
            }
            
            // Build notification item
            $items[] = [
                'order_id' => (int)$e['order_id'],
                'status' => $actualOrderStatus,
                'message' => !empty($e['message']) ? (string)$e['message'] : null,
                'type' => !empty($e['type']) ? (string)$e['type'] : 'info',
                'notification_id' => !empty($e['notification_id']) ? (int)$e['notification_id'] : null,
                'product_id' => $productId,
                'review_id' => $reviewId,
                'payment_status' => !empty($e['payment_status']) ? (string)$e['payment_status'] : null,
                'return_status' => !empty($e['return_status']) ? (string)$e['return_status'] : null,
                'updated_at' => $mostRecentUpdate,
                'updated_at_human' => date('M d, Y h:i A', strtotime($mostRecentUpdate)),
                'is_read' => $isRead,
                'is_custom_notification' => !empty($e['message'])
            ];
        }
        
        echo json_encode(['success' => true, 'items' => $items, 'unread_count' => $unreadCount]);
    } catch (Exception $e) {
        error_log("Error in notifications JSON: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error loading notifications', 'items' => []]);
    }
    exit;
       
}

function statusBadge($status) {
    $status = strtolower((string)$status);
    $map = [
        'pending' => ['Pending', '#ffc107', '#130325'],
        'processing' => ['Processing', '#0dcaf0', '#130325'],
        'shipped' => ['Shipped', '#17a2b8', '#ffffff'],
        'delivered' => ['Delivered', '#28a745', '#ffffff'],
        'cancelled' => ['Cancelled', '#dc3545', '#ffffff'],
        'refunded' => ['Refunded', '#6c757d', '#ffffff'],
        'completed' => ['Completed', '#28a745', '#ffffff'],
        'failed' => ['Failed', '#dc3545', '#ffffff'],
    ];
    $label = ucfirst($status);
    $bg = '#6c757d';
    $fg = '#ffffff';
    if (isset($map[$status])) { [$label,$bg,$fg] = $map[$status]; }
    return '<span class="status-badge" style="background:'.$bg.';color:'.$fg.';padding:2px 6px;border-radius:6px;font-weight:500;font-size:11px;opacity:0.9;">'.$label.'</span>';
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
    background: var(--bg-light) !important; 
    color: var(--text-dark); 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}

.notifications-container {
    max-width: 1600px;
    margin: 0 auto;
    padding: 12px 20px;
}

h1 {
    color: var(--text-dark);
    margin: 0 0 12px 0;
    font-size: 1.5rem;
    font-weight: 700;
    letter-spacing: -0.5px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    flex-wrap: wrap;
    gap: 12px;
    padding: 12px 16px;
    background: var(--bg-white);
    border-bottom: 1px solid var(--border-light);
    position: relative;
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

.back-arrow i {
    margin: 0;
}

.page-header-title {
    color: var(--text-dark);
    font-size: 1.35rem;
    font-weight: 600;
    margin: 0;
    letter-spacing: -0.3px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.badge {
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    display: inline-block;
    letter-spacing: 0.3px;
    text-transform: uppercase;
}

.badge-total {
    background: rgba(19, 3, 37, 0.1);
    color: var(--text-dark);
    border: 1px solid rgba(19, 3, 37, 0.2);
    margin-left: 6px;
}

.badge-unread {
    background: var(--error-red);
    color: #ffffff;
    margin-left: 6px;
    animation: pulse 2s ease-in-out infinite;
    border: 1px solid var(--error-red);
}

@keyframes pulse {
    0%, 100% {
        opacity: 1;
    }
    50% {
        opacity: 0.8;
    }
}

.btn-clear-all {
    background: var(--error-red);
    color: #ffffff;
    border: none;
    padding: 8px 14px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    font-size: 12px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.2s ease;
}

.btn-clear-all:hover {
    background: #dc2626;
    transform: translateY(-1px);
}

/* ============================================
   FIX: Notification Item Height & Text Display
   ============================================ */

   .notif-item {
    background: var(--bg-white) !important;
    border: 1px solid var(--border-light);
    border-radius: 12px;
    transition: all 0.2s ease !important; /* Changed from none */
    position: relative;
    overflow: hidden !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    
    /* FIXED: Increased height limits for better content visibility */
    min-height: 80px !important;
    max-height: 180px !important;
    height: auto !important;
    
    display: block !important;
    flex-shrink: 0 !important;
    flex-grow: 0 !important;
    width: 100% !important;
    contain: layout style !important;
}

/* Expand on hover to show full content */
.notif-item:hover {
    border-color: var(--primary-dark);
    box-shadow: 0 4px 12px rgba(19, 3, 37, 0.15), 0 0 0 1px rgba(19, 3, 37, 0.1);
    transform: translateY(-1px);
    max-height: 300px !important; /* Expand on hover */
}

.notif-item a {
    text-decoration: none;
    display: block !important;
    background: transparent !important;
    padding: 14px 16px; /* Increased padding */
    border-radius: 12px;
    height: auto !important;
    max-height: 180px !important;
    min-height: 0 !important;
    overflow: hidden !important;
    position: relative;
    width: 100%;
    box-sizing: border-box;
}

.notif-item:hover a {
    max-height: 300px !important; /* Expand on hover */
}

.notif-title {
    color: var(--text-dark);
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 5px;
    line-height: 1.4;
    
    /* FIXED: Better text truncation with ellipsis */
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 3; /* Show up to 3 lines */
    line-clamp: 3;
    -webkit-box-orient: vertical;
    max-height: 4.2em; /* 3 lines × 1.4 line-height */
    word-wrap: break-word;
    word-break: break-word;
}

/* Show full title on hover */
.notif-item:hover .notif-title {
    -webkit-line-clamp: unset;
    line-clamp: unset;
    max-height: none;
    overflow: visible;
}

.notif-details {
    color: var(--text-light);
    font-size: 11px;
    line-height: 1.5;
    margin-top: 4px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    
    /* Prevent details from being cut off */
    overflow: visible;
    max-height: none;
}

.notif-item-content {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    height: auto;
    min-height: 0;
    max-height: none; /* Changed from fixed height */
    width: 100%;
    position: relative;
    overflow: visible; /* Changed from hidden */
}

/* Mobile responsiveness improvements */
@media (max-width: 968px) {
    .notif-item {
        min-height: 70px !important;
        max-height: 160px !important;
    }
    
    .notif-item:hover {
        max-height: 250px !important;
    }
    
    .notif-item a {
        padding: 12px 14px;
        max-height: 160px !important;
    }
    
    .notif-item:hover a {
        max-height: 250px !important;
    }
    
    .notif-title {
        font-size: 12px;
        -webkit-line-clamp: 2; /* Show 2 lines on mobile */
        line-clamp: 2;
        max-height: 2.8em;
    }
    
    .notif-details {
        font-size: 10px;
        gap: 4px;
    }
}

/* Loading state for notifications */
.notif-item.loading {
    opacity: 0.6;
    pointer-events: none;
}

.notif-item.loading::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(
        90deg,
        transparent 0%,
        rgba(255, 255, 255, 0.6) 50%,
        transparent 100%
    );
    animation: shimmer 1.5s infinite;
}

@keyframes shimmer {
    0% {
        transform: translateX(-100%);
    }
    100% {
        transform: translateX(100%);
    }
}

/* Notification list improvements */
.notif-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-height: none;
    height: auto;
    overflow: visible; /* Changed from hidden */
    min-height: 0;
    will-change: contents;
    contain: layout style;
}

/* Smooth appearance for new notifications */
.notif-item.new-notification {
    animation: slideInNotification 0.4s ease-out;
}

@keyframes slideInNotification {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Missing CSS classes */
.notif-item[style*="border-left"] {
    border-left: 4px solid var(--primary-dark) !important;
    background: linear-gradient(90deg, #f8f7fa 0%, var(--bg-white) 100%) !important;
    box-shadow: 0 2px 8px rgba(19, 3, 37, 0.1);
}

.notif-icon {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, rgba(19, 3, 37, 0.1) 0%, rgba(19, 3, 37, 0.05) 100%);
    color: var(--primary-dark);
    border-radius: 10px;
    flex-shrink: 0;
    font-size: 16px;
    border: 1px solid rgba(19, 3, 37, 0.2);
    position: relative;
    box-shadow: 0 1px 3px rgba(19, 3, 37, 0.1);
}

.notif-item[style*="border-left"] .notif-icon {
    background: linear-gradient(135deg, rgba(19, 3, 37, 0.15) 0%, rgba(19, 3, 37, 0.1) 100%);
    border-color: rgba(19, 3, 37, 0.3);
    box-shadow: 0 2px 6px rgba(19, 3, 37, 0.15);
}

.notif-item[style*="border-left"] .notif-icon::after {
    content: '';
    position: absolute;
    top: -2px;
    right: -2px;
    width: 10px;
    height: 10px;
    background: var(--error-red);
    border-radius: 50%;
    border: 2px solid var(--bg-white);
    box-shadow: 0 0 6px rgba(239, 68, 68, 0.5);
    animation: pulse 2s ease-in-out infinite;
}

.notif-body {
    flex: 1;
    min-width: 0;
    padding-right: 8px;
}

.notif-details .status-badge {
    margin: 0;
    vertical-align: middle;
    display: inline-block;
}

.notif-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 6px;
    flex-shrink: 0;
    min-width: 90px;
}

.notif-time {
    color: var(--text-light);
    font-size: 10px;
    white-space: nowrap;
}

.btn-view {
    background: var(--bg-light);
    color: var(--text-dark);
    border: 1px solid var(--border-light);
    border-radius: 8px;
    padding: 6px 10px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    transition: all 0.2s ease;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}

.btn-view:hover {
    background: var(--primary-dark);
    border-color: var(--primary-dark);
    color: var(--bg-white);
    transform: translateY(-1px);
}

.btn-view i {
    font-size: 12px;
}

.empty-state {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    color: var(--text-light);
    border-radius: 12px;
    padding: 40px 20px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    border-top: 3px solid rgba(19, 3, 37, 0.2);
}

.empty-state::before {
    content: "";
    display: block;
    margin-bottom: 16px;
    opacity: 0.4;
}

.empty-state .empty-icon {
    font-size: 48px;
    color: var(--text-light);
    opacity: 0.4;
    margin-bottom: 16px;
    display: block;
}

.alert {
    padding: 10px 14px;
    border-radius: 8px;
    margin-bottom: 14px;
    border-left: 4px solid;
    font-size: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.alert-success {
    background-color: #d1fae5;
    border-left-color: var(--success-green);
    border: 1px solid #a7f3d0;
    color: #065f46;
}

.alert-error {
    background-color: #fef2f2;
    border-left-color: var(--error-red);
    border: 1px solid #fecaca;
    color: #991b1b;
}

/* Confirmation dialog */
.confirm-dialog {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    backdrop-filter: blur(5px);
}

.confirm-content {
    background: var(--bg-white);
    border-radius: 12px;
    padding: 18px 20px;
    max-width: 400px;
    width: 90%;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15), 0 0 0 1px rgba(19, 3, 37, 0.1);
    text-align: center;
    animation: confirmSlideIn 0.3s ease-out;
    border-top: 3px solid var(--primary-dark);
}

@keyframes confirmSlideIn {
    from {
        opacity: 0;
        transform: scale(0.8) translateY(-20px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}

.confirm-title {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-dark);
    margin-bottom: 10px;
}

.confirm-message {
    font-size: 12px;
    color: var(--text-light);
    margin-bottom: 16px;
    line-height: 1.5;
}

.confirm-buttons {
    display: flex;
    gap: 10px;
    justify-content: center;
}

.confirm-btn {
    padding: 8px 14px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
    min-width: 70px;
}

.confirm-btn-yes {
    background: var(--error-red);
    color: white;
}

.confirm-btn-yes:hover {
    background: #dc2626;
    transform: translateY(-1px);
}

.confirm-btn-no {
    background: var(--text-light);
    color: white;
}

.confirm-btn-no:hover {
    background: #4b5563;
    transform: translateY(-1px);
}

/* Notification grouping by date (optional enhancement) */
.notif-date-group {
    margin-bottom: 24px;
}

.notif-date-header {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-light);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--border-light);
}

/* Responsive */
@media (max-width: 968px) {
    .notifications-container {
        padding: 10px 12px;
        max-width: 100%;
    }
    
    h1 {
        font-size: 1.35rem;
        margin-bottom: 12px;
    }
    
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        padding: 10px 12px;
        padding-bottom: 10px;
        gap: 8px;
    }
    
    .page-header-left {
        width: 100%;
        flex-wrap: wrap;
        gap: 8px;
    }
    
    .back-arrow {
        width: 28px;
        height: 28px;
        font-size: 14px;
    }
    
    .page-header-title {
        font-size: 1.2rem;
        font-weight: 600;
    }
    
    .btn-clear-all {
        width: 100%;
        justify-content: center;
        margin-top: 4px;
    }
    
    .notif-item-content {
        gap: 8px;
    }
    
    .notif-icon {
        width: 36px;
        height: 36px;
        font-size: 14px;
    }
    
    .notif-item a {
        padding: 10px 12px;
    }
    
    .notif-body {
        padding-right: 0;
    }
    
    .notif-title {
        font-size: 12px;
    }
    
    .notif-details {
        font-size: 10px;
        gap: 4px;
    }
    
    .notif-meta {
        min-width: auto;
        align-items: flex-end;
        gap: 4px;
    }
    
    .notif-time {
        font-size: 9px;
    }
    
    .btn-view {
        padding: 5px 8px;
        font-size: 10px;
    }
    
    .badge {
        font-size: 10px;
        padding: 2px 6px;
    }
}
</style>
<main style="background: var(--bg-light); min-height: 100vh; padding: 0;">
  <div class="notifications-container">
    <div class="page-header">
      <div class="page-header-left">
        <a href="user-dashboard.php" class="back-arrow" title="Back to Dashboard">
          <i class="fas fa-arrow-left"></i>
        </a>
        <div class="page-header-title">
          Notifications
        </div>
        <?php 
        $unreadCount = 0;
        foreach ($events as $e) {
            if (empty($e['is_read'])) {
                $unreadCount++;
            }
        }
        ?>
        <span class="badge badge-total"><?php echo count($events); ?> total</span>
        <?php if ($unreadCount > 0): ?>
          <span class="badge badge-unread"><?php echo $unreadCount; ?> unread</span>
        <?php endif; ?>
      </div>
      <?php if (!empty($events)): ?>
        <form method="POST" style="margin:0;" id="deleteAllForm">
            <input type="hidden" name="delete_notifications" value="1">
            <button type="button" onclick="confirmDeleteAll()" class="btn-clear-all">
              <i class="fas fa-trash"></i> Clear All
            </button>
        </form>
      <?php endif; ?>
    </div>

    <?php if (isset($_GET['deleted']) && $_GET['deleted'] == '1'): ?>
      <div id="successMessage" class="alert alert-success">
        All notifications have been deleted successfully.
      </div>
      <script>
        setTimeout(function() {
          const message = document.getElementById('successMessage');
          if (message) {
            message.style.opacity = '0';
            message.style.transform = 'translateY(-10px)';
            setTimeout(() => message.remove(), 300);
          }
        }, 2000);
      </script>
    <?php endif; ?>

    <?php if (isset($successMessage)): ?>
      <div class="alert alert-success">
        <?php echo htmlspecialchars($successMessage); ?>
      </div>
    <?php endif; ?>

    <?php if (isset($errorMessage)): ?>
      <div class="alert alert-error">
        <?php echo htmlspecialchars($errorMessage); ?>
      </div>
    <?php endif; ?>

    <?php if (empty($events)): ?>
      <div class="empty-state">
        <i class="fas fa-bell empty-icon"></i>
        <div>No notifications yet.</div>
      </div>
    <?php else: ?>
      <div class="notif-list">
        <?php foreach ($events as $e): ?>
          
            <div class="notif-item" <?php if (empty($e['is_read'])): ?>style="border-left: 4px solid var(--primary-dark); max-height: 180px !important; overflow: hidden !important; height: auto !important; min-height: 60px !important;"<?php else: ?>style="max-height: 180px !important; overflow: hidden !important; height: auto !important; min-height: 60px !important;"<?php endif; ?>>
    <?php
    $hasOrderId = !empty($e['order_id']);
    $hasProductId = !empty($e['product_id']);
    $hasReviewId = !empty($e['review_id']);
    
    // Determine link URL: product > order > default
    // For seller reply notifications, redirect to reviews tab with review_id
    $isSellerReply = !empty($e['type']) && $e['type'] === 'seller_reply';
    if ($hasProductId) {
        $linkUrl = "product-detail.php?id=" . (int)$e['product_id'];
        if ($isSellerReply && $hasReviewId) {
            // Put review_id in query string, hash at the end
            $linkUrl .= "&review_id=" . (int)$e['review_id'] . "#reviews-tab";
        } elseif ($isSellerReply) {
            $linkUrl .= "#reviews-tab";
        }
    } elseif ($hasOrderId) {
        $linkUrl = "order-details.php?id=" . (int)$e['order_id'];
    } else {
        $linkUrl = "#";
    }
    ?>
    <a href="<?php echo $linkUrl; ?>" 
       <?php if ($hasOrderId): ?>data-order-id="<?php echo (int)$e['order_id']; ?>"<?php endif; ?>
       <?php if ($hasProductId): ?>data-product-id="<?php echo (int)$e['product_id']; ?>"<?php endif; ?>
       data-notification-id="<?php echo (int)$e['notification_id']; ?>"
       data-is-custom="<?php echo isset($e['message']) ? '1' : '0'; ?>"
       <?php if ($hasProductId): ?>
       onclick="event.preventDefault(); 
                const startTime = performance.now();
                const notificationId = <?php echo (int)$e['notification_id']; ?>;
                const productId = <?php echo (int)$e['product_id']; ?>;
                const reviewId = <?php echo $hasReviewId ? (int)$e['review_id'] : 'null'; ?>;
                const isSellerReply = <?php echo $isSellerReply ? 'true' : 'false'; ?>;
                const notificationType = '<?php echo isset($e['type']) ? htmlspecialchars($e['type']) : ''; ?>';
                
                console.log('[REDIRECT DEBUG] ============================================');
                console.log('[REDIRECT DEBUG] Notification Click Event Started');
                console.log('[REDIRECT DEBUG] Timestamp:', new Date().toISOString());
                console.log('[REDIRECT DEBUG] Notification ID:', notificationId);
                console.log('[REDIRECT DEBUG] Product ID:', productId);
                console.log('[REDIRECT DEBUG] Review ID:', reviewId);
                console.log('[REDIRECT DEBUG] Is Seller Reply:', isSellerReply);
                console.log('[REDIRECT DEBUG] Notification Type:', notificationType);
                
                // Set flag for back button handling
                sessionStorage.setItem('fromNotification', 'true');
                console.log('[REDIRECT DEBUG] Set sessionStorage flag: fromNotification = true');
                
                // Build redirect URL correctly: product-detail.php?id=X&review_id=Y#reviews-tab
                let redirectUrl = 'product-detail.php?id=' + productId;
                if (reviewId) {
                    redirectUrl += '&review_id=' + reviewId;
                }
                if (isSellerReply) {
                    redirectUrl += '#reviews-tab';
                }
                
                console.log('[REDIRECT DEBUG] Constructed Redirect URL:', redirectUrl);
                console.log('[REDIRECT DEBUG] Current URL:', window.location.href);
                
                // Mark as read first (don't redirect from function)
                console.log('[REDIRECT DEBUG] Calling markStandaloneNotificationRead...');
                markStandaloneNotificationRead(notificationId, null, ''); // Don't redirect from function
                
                // Redirect immediately
                const redirectStartTime = performance.now();
                console.log('[REDIRECT DEBUG] Starting redirect in 150ms...');
                setTimeout(function() { 
                    const redirectTime = performance.now() - redirectStartTime;
                    const totalTime = performance.now() - startTime;
                    console.log('[REDIRECT DEBUG] Redirect timeout fired');
                    console.log('[REDIRECT DEBUG] Redirect delay time:', redirectTime.toFixed(2), 'ms');
                    console.log('[REDIRECT DEBUG] Total time from click:', totalTime.toFixed(2), 'ms');
                    console.log('[REDIRECT DEBUG] Executing window.location.href =', redirectUrl);
                    window.location.href = redirectUrl;
                    console.log('[REDIRECT DEBUG] Redirect command executed');
                }, 150);"
       <?php elseif (!$hasOrderId && !$hasProductId): ?>
       onclick="event.preventDefault(); markStandaloneNotificationRead(<?php echo (int)$e['notification_id']; ?>);"
       <?php endif; ?>>
      <div class="notif-item-content">
        <div class="notif-icon">
          <i class="fas fa-<?php echo $e['type'] === 'warning' ? 'exclamation-triangle' : 'bell'; ?>"></i>
        </div>
        <div class="notif-body">
        <div class="notif-title">
            <?php 
            $isReviewNotification = false;
            if (!empty($e['message'])) {
                echo htmlspecialchars($e['message']);
                // Check if this is a review-related notification
                $lowerMessage = strtolower($e['message']);
                if (strpos($lowerMessage, 'review') !== false || 
                    strpos($lowerMessage, 'rating') !== false || 
                    strpos($lowerMessage, 'feedback') !== false) {
                    $isReviewNotification = true;
                }
            } else {
                // Generate default message based on status
                $status = $e['status'] ?? 'unknown';
                $orderId = $e['order_id'] ?? 0;
                
                if (!empty($e['return_status'])) {
                    echo "Return request update for Order #" . $orderId;
                } elseif ($status === 'pending') {
                    echo "Your order #" . $orderId . " is pending confirmation";
                } elseif ($status === 'processing') {
                    echo "Your order #" . $orderId . " is now being processed";
                } elseif ($status === 'shipped') {
                    echo "Your order #" . $orderId . " has been shipped!";
                } elseif ($status === 'delivered') {
                    echo "Your order #" . $orderId . " has been delivered";
                } elseif ($status === 'cancelled') {
                    echo "Order #" . $orderId . " has been cancelled";
                } elseif ($status === 'completed') {
                    echo "Order #" . $orderId . " is complete";
                } else {
                    echo "Order #" . $orderId . " update";
                }
            }
            ?>
            </div>
            <?php if (!$isReviewNotification && !empty($e['order_id'])): ?>
            <div class="notif-details">
                <span>Order #<?php echo htmlspecialchars($e['order_id']); ?></span>
                <span>•</span>
                <span>Status: <?php echo statusBadge($e['status']); ?></span>
                <?php if (!empty($e['payment_status'])): ?>
                    <span>Payment: <?php echo statusBadge($e['payment_status']); ?></span>
                <?php endif; ?>
                <?php if (!empty($e['return_status'])): ?>
                    <span>Return: <?php echo statusBadge($e['return_status']); ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <div class="notif-meta">
          <span class="notif-time" data-timestamp="<?php echo strtotime($e['created_at']); ?>">
            <?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($e['created_at']))); ?>
          </span>
          <?php if ($hasOrderId || $hasProductId): ?>
          <button 
            type="button"
            onclick="event.stopPropagation(); 
                     <?php if ($hasProductId): ?>
                     const viewStartTime = performance.now();
                     const viewNotificationId = <?php echo (int)$e['notification_id']; ?>;
                     const viewProductId = <?php echo (int)$e['product_id']; ?>;
                     const viewReviewId = <?php echo $hasReviewId ? (int)$e['review_id'] : 'null'; ?>;
                     const viewIsSellerReply = <?php echo $isSellerReply ? 'true' : 'false'; ?>;
                     
                     console.log('[VIEW BUTTON DEBUG] ============================================');
                     console.log('[VIEW BUTTON DEBUG] View Button Click Event Started');
                     console.log('[VIEW BUTTON DEBUG] Timestamp:', new Date().toISOString());
                     console.log('[VIEW BUTTON DEBUG] Notification ID:', viewNotificationId);
                     console.log('[VIEW BUTTON DEBUG] Product ID:', viewProductId);
                     console.log('[VIEW BUTTON DEBUG] Review ID:', viewReviewId);
                     console.log('[VIEW BUTTON DEBUG] Is Seller Reply:', viewIsSellerReply);
                     
                     // Set flag for back button handling
                     sessionStorage.setItem('fromNotification', 'true');
                     console.log('[VIEW BUTTON DEBUG] Set sessionStorage flag: fromNotification = true');
                     
                     // Build redirect URL correctly
                     let viewRedirectUrl = 'product-detail.php?id=' + viewProductId;
                     if (viewReviewId) {
                         viewRedirectUrl += '&review_id=' + viewReviewId;
                     }
                     if (viewIsSellerReply) {
                         viewRedirectUrl += '#reviews-tab';
                     }
                     
                     console.log('[VIEW BUTTON DEBUG] Constructed Redirect URL:', viewRedirectUrl);
                     console.log('[VIEW BUTTON DEBUG] Current URL:', window.location.href);
                     
                     // Mark as read first (don't redirect from function)
                     console.log('[VIEW BUTTON DEBUG] Calling markStandaloneNotificationRead...');
                     markStandaloneNotificationRead(viewNotificationId, null, ''); // Don't redirect from function
                     
                     // Add to history for back button
                     if (window.history && window.history.pushState) { 
                         window.history.pushState({ page: 'customer-notifications', fromNotification: true }, '', 'customer-notifications.php');
                         console.log('[VIEW BUTTON DEBUG] Added history state');
                     }
                     
                     // Redirect immediately
                     const viewRedirectStartTime = performance.now();
                     console.log('[VIEW BUTTON DEBUG] Starting redirect in 150ms...');
                     setTimeout(function() { 
                         const viewRedirectTime = performance.now() - viewRedirectStartTime;
                         const viewTotalTime = performance.now() - viewStartTime;
                         console.log('[VIEW BUTTON DEBUG] Redirect timeout fired');
                         console.log('[VIEW BUTTON DEBUG] Redirect delay time:', viewRedirectTime.toFixed(2), 'ms');
                         console.log('[VIEW BUTTON DEBUG] Total time from click:', viewTotalTime.toFixed(2), 'ms');
                         console.log('[VIEW BUTTON DEBUG] Executing window.location.href =', viewRedirectUrl);
                         window.location.href = viewRedirectUrl;
                         console.log('[VIEW BUTTON DEBUG] Redirect command executed');
                     }, 150);
                     <?php else: ?>
                     handleViewNotification(<?php echo (int)$e['order_id']; ?>, <?php echo isset($e['message']) ? '1' : '0'; ?>);
                     <?php endif; ?>"
            class="btn-view"
            title="View Details">
            <i class="fas fa-eye"></i> View
          </button>
          <?php endif; ?>
        </div>
      </div>
    </a>
</div>

        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</main>

<?php require_once 'includes/footer.php'; ?>

<script>
// Real-time notifications update - State variables
let lastUpdateTime = null;
let refreshInterval = null;
let connectionStatus = 'connected';
let failedAttempts = 0;

// Custom styled confirmation dialog function
function openConfirm(message, onConfirm) {
    const dialog = document.createElement('div');
    dialog.className = 'confirm-dialog';
    dialog.innerHTML = `
        <div class="confirm-content">
            <div class="confirm-title">Confirm Action</div>
            <div class="confirm-message">${message}</div>
            <div class="confirm-buttons">
                <button class="confirm-btn confirm-btn-yes">Yes</button>
                <button class="confirm-btn confirm-btn-no">No</button>
            </div>
        </div>
    `;
    
    document.body.appendChild(dialog);
    
    const yesBtn = dialog.querySelector('.confirm-btn-yes');
    const noBtn = dialog.querySelector('.confirm-btn-no');
    
    yesBtn.addEventListener('click', () => {
        document.body.removeChild(dialog);
        if (onConfirm) onConfirm();
    });
    
    noBtn.addEventListener('click', () => {
        document.body.removeChild(dialog);
    });
    
    const handleEscape = (e) => {
        if (e.key === 'Escape') {
            document.body.removeChild(dialog);
            document.removeEventListener('keydown', handleEscape);
        }
    };
    document.addEventListener('keydown', handleEscape);
    
    dialog.addEventListener('click', (e) => {
        if (e.target === dialog) {
            document.body.removeChild(dialog);
            document.removeEventListener('keydown', handleEscape);
        }
    });
}

function confirmDeleteAll() {
    openConfirm('Are you sure you want to delete all notifications? This action cannot be undone.', function() {
        document.getElementById('deleteAllForm').submit();
    });
}

// Intercept clicks on server-rendered notification links to mark as read
document.addEventListener('click', function(e) {
    // Don't intercept if clicking the View button (it has its own handler)
    if (e.target.closest('.btn-view')) {
        return;
    }
    
    const link = e.target.closest('a[data-order-id]');
    if (!link) return;
    const orderId = parseInt(link.getAttribute('data-order-id') || '0', 10);
    const isCustom = link.getAttribute('data-is-custom') === '1';
    
    if (orderId > 0) {
        e.preventDefault();
        markNotificationAsRead(orderId, isCustom);
        // Update UI immediately
        updateNotificationItemUI(orderId);
        // Update header badge immediately and after delay
        setTimeout(updateHeaderBadge, 100);
        setTimeout(updateHeaderBadge, 500);
        
        // Add to history for back button functionality
        if (window.history && window.history.pushState) {
            window.history.pushState({ page: 'customer-notifications' }, '', 'customer-notifications.php');
        }
        
        const href = link.getAttribute('href');
        setTimeout(function(){ window.location.href = href; }, 300);
    }
});

// Main notification update function
function updateNotifications() {
    fetch('customer-notifications.php?as=json')
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.items) {
                updateNotificationsDisplay(data.items, data.unread_count);
                
                // Reset failed attempts on success
                failedAttempts = 0;
                connectionStatus = 'connected';
            }
        })
        .catch(error => {
            console.error('Error fetching notifications:', error);
            failedAttempts++;
            
            // If multiple failures, slow down polling
            if (failedAttempts > 3) {
                stopRealTimeUpdates();
                // Restart with slower interval (10 seconds)
                refreshInterval = setInterval(updateNotifications, 10000);
            }
        });
}

// Store last update data to prevent unnecessary re-renders
let lastUpdateHash = '';

function updateNotificationsDisplay(items, unreadCount) {
    requestAnimationFrame(() => {
        const updateStartTime = performance.now();
        
        const notifList = document.querySelector('.notif-list');
        if (!notifList) {
            return;
        }
        
        // Store current scroll position
        const scrollTop = notifList.scrollTop || 0;
        
        // OPTIMIZED: Create hash more efficiently (only essential fields)
        const hashData = items.length + '|' + items.map(i => 
            `${i.order_id || ''}:${i.is_read ? '1' : '0'}:${i.updated_at || ''}`
        ).join(',');
        
        // FIXED: Only skip if hash matches AND we have content already rendered
        if (hashData === lastUpdateHash && notifList.children.length === items.length) {
            updateHeaderCounts(items.length, unreadCount);
            return;
        }
        
        lastUpdateHash = hashData;
        
        // Update header counts first (lightweight operation)
        updateHeaderCounts(items.length, unreadCount);
        
        // OPTIMIZED: Batch style updates
        if (items.length === 0) {
            notifList.innerHTML = '<div class="empty-state"><i class="fas fa-bell empty-icon"></i><div>No notifications yet.</div></div>';
            const deleteAllForm = document.getElementById('deleteAllForm');
            if (deleteAllForm) deleteAllForm.style.display = 'none';
            return;
        }
        
        // Show/hide clear all button
        const deleteAllForm = document.getElementById('deleteAllForm');
        if (deleteAllForm) {
            deleteAllForm.style.display = 'block';
        }
        
        // OPTIMIZED: Build all notification items in fragment (off-DOM, single pass)
        const fragment = document.createDocumentFragment();
        for (let i = 0; i < items.length; i++) {
            try {
                const notifElement = createNotificationElement(items[i]);
                fragment.appendChild(notifElement);
            } catch (error) {
                console.error('[ERROR] Failed to create notification element:', error);
            }
        }
        
        // Clear and append in one operation
        notifList.innerHTML = '';
        notifList.appendChild(fragment);
        
        // Restore scroll position
        notifList.scrollTop = scrollTop;
        
        // Update relative times (deferred to avoid blocking)
        setTimeout(updateRelativeTimes, 50);
        
        const totalTime = performance.now() - updateStartTime;
        if (totalTime > 50) {
            console.warn('[PERF] updateNotificationsDisplay took', totalTime.toFixed(2), 'ms');
        }
    });
}

function updateHeaderCounts(total, unreadCount) {
    // Update header badge
    const badge = document.getElementById('notif-badge');
    if (badge) {
        if (unreadCount > 0) {
            badge.textContent = unreadCount;
            badge.style.display = 'flex';
            badge.classList.add('show');
        } else {
            badge.style.display = 'none';
            badge.classList.remove('show');
        }
    }
    
    // Update page header badges
    const totalBadge = document.querySelector('.page-header-left .badge-total');
    const unreadBadge = document.querySelector('.page-header-left .badge-unread');
    
    if (totalBadge) {
        totalBadge.textContent = `${total} total`;
    }
    
    if (unreadCount > 0) {
        if (unreadBadge) {
            unreadBadge.textContent = `${unreadCount} unread`;
        } else {
            const pageHeaderLeft = document.querySelector('.page-header-left');
            if (pageHeaderLeft) {
                const newUnreadBadge = document.createElement('span');
                newUnreadBadge.className = 'badge badge-unread';
                newUnreadBadge.textContent = `${unreadCount} unread`;
                pageHeaderLeft.appendChild(newUnreadBadge);
            }
        }
    } else {
        if (unreadBadge) {
            unreadBadge.remove();
        }
    }
}

function createNotificationElement(item) {
    try {
        const notifContainer = document.createElement('div');
        notifContainer.className = 'notif-item';
        
        // FIXED: Increase max-height and add dynamic height
        const containerStyles = `
            position: relative;
            max-height: 180px !important;
            min-height: 80px !important;
            height: auto !important;
            overflow: hidden !important;
            display: block !important;
            flex-shrink: 0 !important;
            flex-grow: 0 !important;
            width: 100% !important;
            contain: layout style !important;
            transition: max-height 0.2s ease !important;
        `;
        notifContainer.style.cssText = containerStyles;
        
        // Add unread indicator style
        if (!item.is_read) {
            notifContainer.style.borderLeft = '4px solid #130325';
        }
        
        // OPTIMIZED: Use CSS class for hover instead of JS event listeners
        // Hover is handled by CSS .notif-item:hover { max-height: 300px !important; }
        
        const notifDiv = document.createElement('a');
        notifDiv.setAttribute('data-order-id', item.order_id);
        if (item.product_id) {
            notifDiv.setAttribute('data-product-id', item.product_id);
        }
        notifDiv.setAttribute('data-is-custom', item.is_custom_notification ? '1' : '0');
        
        // Determine URL
        let filterStatus = item.status.toLowerCase();

// Map order status to dashboard filter
switch(filterStatus) {
    case 'pending':
        filterStatus = 'pending';
        break;
    case 'processing':
        filterStatus = 'processing';
        break;
    case 'shipped':
        filterStatus = 'shipped';
        break;
    case 'delivered':
        filterStatus = 'delivered';
        break;
    case 'cancelled':
        filterStatus = 'cancelled';
        break;
    case 'notification':
        // For custom notifications, determine based on message or default
        filterStatus = 'delivered';
        break;
}

// If there's a return status, always redirect to return_requested
if (item.return_status) {
    filterStatus = 'return_requested';
}

// Link directly: product > order > default
// For seller reply notifications, add #reviews-tab hash with review_id in query string
const isSellerReply = item.type === 'seller_reply';
const reviewId = item.review_id || null;
const productId = item.product_id || null;

let targetUrl = '';
if (productId) {
    targetUrl = `product-detail.php?id=${productId}`;
    if (reviewId) {
        targetUrl += `&review_id=${reviewId}`;
    }
    if (isSellerReply) {
        targetUrl += '#reviews-tab';
    }
} else if (item.order_id) {
    targetUrl = `order-details.php?id=${item.order_id}`;
} else {
    targetUrl = 'customer-notifications.php';
}

notifDiv.href = targetUrl;
notifDiv.style.textDecoration = 'none';
notifDiv.style.display = 'block';

// Click handler
notifDiv.addEventListener('click', function(e) {
    if (e.target.closest('.btn-view')) {
        return;
    }
    
    e.preventDefault();
    const isCustom = item.is_custom_notification || false;
    
    if (item.product_id) {
        const isSellerReply = item.type === 'seller_reply';
        const reviewId = item.review_id || null;
        
        sessionStorage.setItem('fromNotification', 'true');
        
        let redirectUrl = 'product-detail.php?id=' + item.product_id;
        if (reviewId) {
            redirectUrl += '&review_id=' + reviewId;
        }
        if (isSellerReply) {
            redirectUrl += '#reviews-tab';
        }
        
        markStandaloneNotificationRead(item.notification_id || 0, null, '');
        
        setTimeout(function() { 
            window.location.href = redirectUrl;
        }, 150);
        return;
    }
    
    if (!item.is_read && item.order_id) {
        markNotificationAsRead(item.order_id, isCustom);
        updateNotificationItemUI(item.order_id);
        setTimeout(updateHeaderBadge, 100);
        setTimeout(updateHeaderBadge, 500);
    } else {
        setTimeout(updateHeaderBadge, 100);
    }
    
    if (window.history && window.history.pushState) {
        window.history.pushState({ page: 'customer-notifications' }, '', 'customer-notifications.php');
    }
    
    setTimeout(function() { window.location.href = targetUrl; }, 300);
});
    
    // Always use the same design with bell icon and status badges
    let content = '';
    const timestamp = Math.floor(new Date(item.updated_at).getTime() / 1000);
    
    // CRITICAL FIX: Use actual order status from database, not 'notification' string
    // The status is now always the actual order status (pending, completed, etc.)
    let orderStatus = item.status || 'unknown';
    
    const statusBadge = getStatusBadge(orderStatus);
    
    // Build payment status badge
    let paymentBadge = '';
    if (item.payment_status && item.payment_status !== 'null' && item.payment_status !== '' && item.payment_status !== null) {
        paymentBadge = `<span>Payment: ${getStatusBadge(item.payment_status)}</span>`;
    }
    
    // Build return status badge
    let returnBadge = '';
    if (item.return_status && item.return_status !== 'null' && item.return_status !== '' && item.return_status !== null) {
        returnBadge = `<span>Return: ${getStatusBadge(item.return_status)}</span>`;
    }
    
   // Build title - show message if custom notification, otherwise generate status-based message
let titleText = '';
let isReviewNotification = false;

if (item.is_custom_notification && item.message) {
    titleText = escapeHtml(item.message);
    // Check if this is a review-related notification
    const lowerMessage = item.message.toLowerCase();
    if (lowerMessage.includes('review') || lowerMessage.includes('rating') || lowerMessage.includes('feedback')) {
        isReviewNotification = true;
    }
} else {
    // Generate message based on status
    const orderId = item.order_id || 0;
    const status = (item.status || 'unknown').toLowerCase();
    
    if (item.return_status) {
        titleText = `Return request update for Order #${orderId}`;
    } else if (status === 'pending') {
        titleText = `Your order #${orderId} is pending confirmation`;
    } else if (status === 'processing') {
        titleText = `Your order #${orderId} is now being processed`;
    } else if (status === 'shipped') {
        titleText = `Your order #${orderId} has been shipped!`;
    } else if (status === 'delivered') {
        titleText = `Your order #${orderId} has been delivered`;
    } else if (status === 'cancelled') {
        titleText = `Order #${orderId} has been cancelled`;
    } else if (status === 'completed') {
        titleText = `Order #${orderId} is complete`;
    } else {
        titleText = `Order #${orderId} update`;
    }
}

// Build details section - hide order info for review notifications
let detailsContent = '';
if (!isReviewNotification && item.order_id) {
    detailsContent = `
        <div class="notif-details">
            <span>Order #${item.order_id}</span>
            <span>•</span>
            <span>Status: ${statusBadge}</span>
            ${paymentBadge}
            ${returnBadge}
        </div>
    `;
}
    
    content = `
        <div class="notif-item-content">
            <div class="notif-icon">
                <i class="fas fa-bell"></i>
            </div>
            <div class="notif-body">
                <div class="notif-title" style="
                    overflow: hidden;
                    text-overflow: ellipsis;
                    display: -webkit-box;
                    -webkit-line-clamp: 3;
                    -webkit-box-orient: vertical;
                    line-height: 1.4;
                    max-height: 4.2em;
                ">${titleText}</div>
                ${detailsContent}
            </div>
            <div class="notif-meta">
                <span class="notif-time" data-timestamp="${timestamp}">${item.updated_at_human}</span>
                <button 
                    type="button"
                    onclick="event.stopPropagation(); handleViewNotification(${item.order_id}, ${item.is_custom_notification ? 1 : 0});" 
                    class="btn-view"
                    title="View Details">
                    <i class="fas fa-eye"></i> View
                </button>
            </div>
        </div>
    `;
    
    // Apply anchor styles
    const anchorStyles = `
        text-decoration: none !important;
        display: block !important;
        background: transparent !important;
        padding: 14px 16px;
        border-radius: 12px;
        max-height: 180px !important;
        overflow: hidden !important;
        height: auto !important;
        position: relative;
        width: 100%;
        box-sizing: border-box;
    `;
    notifDiv.style.cssText = anchorStyles;
    
    notifDiv.innerHTML = content;
    
    notifContainer.appendChild(notifDiv);
    
    // OPTIMIZED: Removed forced reflow per item - will be done once after all items are appended
    
    return notifContainer;
    } catch (error) {
        console.error('[ERROR] createNotificationElement failed:', error, item);
        // Return a minimal error element
        const errorDiv = document.createElement('div');
        errorDiv.className = 'notif-item';
        errorDiv.style.maxHeight = '180px';
        errorDiv.style.overflow = 'hidden';
        errorDiv.textContent = 'Error loading notification';
        return errorDiv;
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function getStatusBadge(status) {
    const statusMap = {
        'pending': ['Pending', '#ffc107', '#130325'],
        'processing': ['Processing', '#0dcaf0', '#130325'],
        'shipped': ['Shipped', '#17a2b8', '#ffffff'],
        'delivered': ['Delivered', '#28a745', '#ffffff'],
        'cancelled': ['Cancelled', '#dc3545', '#ffffff'],
        'refunded': ['Refunded', '#6c757d', '#ffffff'],
        'completed': ['Completed', '#28a745', '#ffffff'],
        'failed': ['Failed', '#dc3545', '#ffffff'],
    };
    
    const [label, bg, fg] = statusMap[status.toLowerCase()] || ['Unknown', '#6c757d', '#ffffff'];
    return `<span class="status-badge" style="background:${bg};color:${fg};padding:2px 6px;border-radius:6px;font-weight:500;font-size:11px;opacity:0.9;">${label}</span>`;
}

// Function to handle View button click - marks as read and navigates
function handleViewNotification(orderId, isCustomNotification) {
    // Update UI immediately for better UX
    updateNotificationItemUI(orderId);
    
    // Mark as read - use sendBeacon for reliability when navigating
    const data = JSON.stringify({
        order_id: orderId,
        is_custom: isCustomNotification
    });
    
    // Use sendBeacon for reliability when navigating away
    if (navigator.sendBeacon) {
        const formData = new FormData();
        formData.append('order_id', orderId);
        formData.append('is_custom', isCustomNotification ? '1' : '0');
        navigator.sendBeacon('ajax/mark-notification-read.php', formData);
    }
    
    // Also use fetch for immediate badge update (if page doesn't navigate)
    fetch('ajax/mark-notification-read.php', {
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
            updateHeaderBadge();
        }
    })
    .catch(error => {
        console.error('Error marking notification as read:', error);
    });
    
    // Navigate after a short delay to ensure the mark request is sent
    setTimeout(function() {
        window.location.href = 'order-details.php?id=' + orderId;
    }, 150);
}

// Function to update notification item UI (remove unread styling)
function updateNotificationItemUI(orderId) {
    // Find the notification item
    const notifLink = document.querySelector(`a[data-order-id="${orderId}"]`);
    if (notifLink) {
        const notifItem = notifLink.closest('.notif-item');
        if (notifItem) {
            // Remove unread styling
            notifItem.classList.remove('unread');
            notifItem.style.borderLeft = 'none';
            
            // Remove unread badge if exists
            const unreadBadge = notifItem.querySelector('.badge-unread');
            if (unreadBadge) {
                unreadBadge.remove();
            }
        }
    }
}

// Function to mark notification as read
function markNotificationAsRead(orderId, isCustomNotification) {
    const data = JSON.stringify({
        order_id: orderId,
        is_custom: isCustomNotification
    });
    
    // Use fetch for response handling
    fetch('ajax/mark-notification-read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: data,
        credentials: 'same-origin'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(result => {
        if (result.success) {
            // Update header badge after successful mark
            setTimeout(updateHeaderBadge, 200);
            setTimeout(updateHeaderBadge, 600);
            // Also refresh notifications list to update unread status
            setTimeout(updateNotifications, 300);
        } else {
            console.error('Failed to mark notification as read:', result.message);
            // Still try to update badge
            setTimeout(updateHeaderBadge, 200);
        }
    })
    .catch(error => {
        console.error('Error marking notification as read:', error);
        // Still try to update badge even on error
        setTimeout(updateHeaderBadge, 200);
    });
    
    // Also update UI immediately without waiting for response
    setTimeout(updateHeaderBadge, 100);
    
    // Use sendBeacon as backup for reliability when navigating away
    if (navigator.sendBeacon) {
        const formData = new FormData();
        formData.append('order_id', orderId);
        formData.append('is_custom', isCustomNotification ? '1' : '0');
        // Note: sendBeacon with FormData - the PHP script needs to handle both JSON and FormData
        navigator.sendBeacon('ajax/mark-notification-read.php', formData);
    }
}

// Update the header badge - use the same method as header.php
function updateHeaderBadge() {
    fetch('customer-notifications.php?as=json')
        .then(response => {
            if (!response.ok) {
                throw new Error('Response not OK: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data && data.success) {
                const unreadCount = data.unread_count || 0;
                
                // Use the global updateNotificationBadge function if it exists, otherwise use our own
                if (typeof updateNotificationBadge === 'function') {
                    updateNotificationBadge(unreadCount);
                } else {
                    // Fallback: update badge directly with all required styles
                    const badge = document.getElementById('notif-badge');
                    
                    if (badge) {
                        if (unreadCount > 0) {
                            badge.textContent = unreadCount;
                            badge.style.display = 'flex';
                            badge.style.visibility = 'visible';
                            badge.style.opacity = '1';
                            badge.classList.add('show');
                        } else {
                            badge.textContent = '';
                            badge.style.display = 'none';
                            badge.style.visibility = 'hidden';
                            badge.style.opacity = '0';
                            badge.classList.remove('show');
                        }
                    }
                }
            }
        })
        .catch(error => {
            console.error('Error updating header badge:', error);
        });
}

// Function to delete individual notifications from the page
function deleteNotificationFromPage(orderId, button, isCustomNotification) {
    // Stop real-time updates temporarily to prevent notifications from coming back
    stopRealTimeUpdates();
    
    // Show loading state
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    button.disabled = true;
    
    // Make AJAX request to delete notification
    fetch('ajax/delete-notification.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            order_id: orderId,
            is_custom: isCustomNotification
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove the notification item from the page
            const notificationItem = button.closest('.notif-item');
            if (notificationItem) {
                notificationItem.style.opacity = '0';
                notificationItem.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    notificationItem.remove();
                    
                    // Check if there are no more notifications
                    const remainingNotifs = document.querySelectorAll('.notif-item');
                    if (remainingNotifs.length === 0) {
                        const notifList = document.querySelector('.notif-list');
                        if (notifList) {
                            notifList.innerHTML = '<div class="empty-state"><i class="fas fa-bell empty-icon"></i><div>No notifications yet.</div></div>';
                        }
                        
                        // Hide clear all button
                        const deleteAllForm = document.getElementById('deleteAllForm');
                        if (deleteAllForm) {
                            deleteAllForm.style.display = 'none';
                        }
                    }
                    
                    // Force immediate update
                    updateNotifications();
                    updateHeaderBadge();
                    
                    // Restart real-time updates
                    setTimeout(() => {
                        startRealTimeUpdates();
                    }, 500);
                }, 300);
            }
        } else {
            // Show error and restore button
            button.innerHTML = '<i class="fas fa-times"></i>';
            button.disabled = false;
            alert('Error deleting notification: ' + (data.message || 'Unknown error'));
            
            // Restart real-time updates even on error
            startRealTimeUpdates();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        button.innerHTML = '<i class="fas fa-times"></i>';
        button.disabled = false;
        alert('Error deleting notification');
        
        // Restart real-time updates even on error
        setTimeout(() => {
            startRealTimeUpdates();
        }, 1000);
    });
}
// Mark standalone notification as read (no order_id)
function markStandaloneNotificationRead(notificationId, productId = null, hash = '') {
    const markStartTime = performance.now();
    console.log('[MARK READ DEBUG] ============================================');
    console.log('[MARK READ DEBUG] markStandaloneNotificationRead called');
    console.log('[MARK READ DEBUG] Timestamp:', new Date().toISOString());
    console.log('[MARK READ DEBUG] Notification ID:', notificationId);
    console.log('[MARK READ DEBUG] Product ID:', productId);
    console.log('[MARK READ DEBUG] Hash:', hash);
    console.log('[MARK READ DEBUG] Will redirect from function:', (productId && hash) ? 'YES' : 'NO');
    
    // Mark as read via beacon (fire and forget)
    if (navigator.sendBeacon) {
        const beaconStartTime = performance.now();
        const formData = new FormData();
        formData.append('notification_id', notificationId);
        const sent = navigator.sendBeacon('ajax/mark-notification-read.php', formData);
        const beaconTime = performance.now() - beaconStartTime;
        console.log('[MARK READ DEBUG] sendBeacon result:', sent, 'Time:', beaconTime.toFixed(2), 'ms');
    }
    
    // Also try fetch for immediate feedback
    const fetchStartTime = performance.now();
    fetch('ajax/mark-notification-read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            notification_id: notificationId
        }),
        credentials: 'same-origin'
    })
    .then(response => {
        const fetchTime = performance.now() - fetchStartTime;
        console.log('[MARK READ DEBUG] Fetch response received in', fetchTime.toFixed(2), 'ms');
        return response.json();
    })
    .then(result => {
        const totalMarkTime = performance.now() - markStartTime;
        console.log('[MARK READ DEBUG] markStandaloneNotificationRead response:', result);
        console.log('[MARK READ DEBUG] Total mark-as-read time:', totalMarkTime.toFixed(2), 'ms');
        if (result.success) {
            // Update UI immediately
            const notifItem = document.querySelector(`a[data-notification-id="${notificationId}"]`);
            if (notifItem) {
                const parentItem = notifItem.closest('.notif-item');
                if (parentItem) {
                    parentItem.style.borderLeft = 'none';
                    console.log('[MARK READ DEBUG] Updated UI - removed border-left');
                }
            }
            // Update header badge
            updateHeaderBadge();
            console.log('[MARK READ DEBUG] Updated header badge');
        } else {
            console.error('[MARK READ DEBUG] Failed to mark notification as read:', result.message);
        }
    })
    .catch(error => {
        const errorTime = performance.now() - markStartTime;
        console.error('[MARK READ DEBUG] Error marking notification as read:', error, 'Time:', errorTime.toFixed(2), 'ms');
    });
    
    // Only redirect if productId is provided AND hash is provided (to avoid double redirect)
    // If hash is empty string, caller will handle redirect
    if (productId && hash) {
        console.log('[MARK READ DEBUG] Redirecting from function to: product-detail.php?id=' + productId + hash);
        setTimeout(() => {
            console.log('[MARK READ DEBUG] Executing redirect from markStandaloneNotificationRead');
            window.location.href = 'product-detail.php?id=' + productId + hash;
        }, 150);
    } else {
        console.log('[MARK READ DEBUG] No redirect from function - caller will handle redirect');
    }
}
let updateTimeout;

function startRealTimeUpdates() {
    // Clear any existing interval first
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }
    
    // Clear any pending timeout
    if (updateTimeout) {
        clearTimeout(updateTimeout);
    }
    
    // Update every 10 seconds (reduced frequency to prevent lag)
    refreshInterval = setInterval(() => {
        clearTimeout(updateTimeout);
        updateTimeout = setTimeout(() => {
            updateNotifications();
        }, 500);
    }, 10000);
    
    // NO initial update - let server-rendered content stay
    // Only update if user explicitly requests or on visibility change
}

function stopRealTimeUpdates() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
        refreshInterval = null;
    }
    if (updateTimeout) {
        clearTimeout(updateTimeout);
        updateTimeout = null;
    }
}

// Add visibility change handler - only update when tab becomes visible
let isUpdating = false;
document.addEventListener('visibilitychange', function() {
    if (!document.hidden && !isUpdating) {
        isUpdating = true;
        updateNotifications();
        setTimeout(() => { isUpdating = false; }, 2000);
    }
});

// Format relative time (like "2 hours ago")
function formatRelativeTime(timestamp) {
    const now = Math.floor(Date.now() / 1000);
    const diff = now - timestamp;
    
    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
    if (diff < 86400) return Math.floor(diff / 3600) + ' hour' + (Math.floor(diff / 3600) > 1 ? 's' : '') + ' ago';
    if (diff < 604800) return Math.floor(diff / 86400) + ' day' + (Math.floor(diff / 86400) > 1 ? 's' : '') + ' ago';
    
    // For older dates, show actual date
    const date = new Date(timestamp * 1000);
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: date.getFullYear() !== new Date().getFullYear() ? 'numeric' : undefined });
}

// Update relative times on page load and periodically
function updateRelativeTimes() {
    document.querySelectorAll('.notif-time[data-timestamp]').forEach(el => {
        const timestamp = parseInt(el.getAttribute('data-timestamp'));
        if (timestamp) {
            el.textContent = formatRelativeTime(timestamp);
        }
    });
}

// Handle back button - redirect to customer-notifications if coming from notification click
window.addEventListener('popstate', function(event) {
    if (event.state && event.state.fromNotification) {
        window.location.href = 'customer-notifications.php';
        return;
    }
    if (event.state && event.state.page === 'customer-notifications') {
        return;
    }
});

// Fix server-rendered items IMMEDIATELY (before DOMContentLoaded)
(function() {
    'use strict';
    try {
        // Inject critical CSS immediately to prevent expansion
        const style = document.createElement('style');
        style.textContent = `
            .notif-list {
                will-change: contents !important;
                contain: layout style !important;
                transition: none !important;
            }
            .notif-item {
                max-height: 180px !important;
                overflow: hidden !important;
                height: auto !important;
                contain: layout style !important;
                flex-shrink: 0 !important;
                flex-grow: 0 !important;
                transition: none !important;
            }
            .notif-item a {
                max-height: 180px !important;
                overflow: hidden !important;
                display: block !important;
            }
            .notif-item-content {
                max-height: 160px !important;
                overflow: hidden !important;
            }
            .notif-title {
                display: -webkit-box !important;
                -webkit-line-clamp: 3 !important;
                -webkit-box-orient: vertical !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
            }
        `;
        document.head.appendChild(style);
        
        // Fix items as soon as they exist
        const fixItems = () => {
            const notifList = document.querySelector('.notif-list');
            if (!notifList) {
                // Retry if not ready yet
                if (document.readyState === 'loading') {
                    setTimeout(fixItems, 10);
                }
                return;
            }
            
            Array.from(notifList.children).forEach((item, index) => {
                try {
                    const itemHeight = item.offsetHeight;
                    if (itemHeight > 200) {
                        console.warn(`[FIX] Item ${index} height: ${itemHeight}px - Applying constraints`);
                        
                        // Apply constraints immediately
                        item.style.setProperty('max-height', '180px', 'important');
                        item.style.setProperty('overflow', 'hidden', 'important');
                        item.style.setProperty('height', 'auto', 'important');
                        
                        const anchor = item.querySelector('a');
                        if (anchor) {
                            anchor.style.setProperty('max-height', '180px', 'important');
                            anchor.style.setProperty('overflow', 'hidden', 'important');
                        }
                        
                        const content = item.querySelector('.notif-item-content');
                        if (content) {
                            content.style.setProperty('max-height', '160px', 'important');
                            content.style.setProperty('overflow', 'hidden', 'important');
                        }
                        
                        const title = item.querySelector('.notif-title');
                        if (title) {
                            title.style.setProperty('display', '-webkit-box', 'important');
                            title.style.setProperty('-webkit-line-clamp', '3', 'important');
                            title.style.setProperty('-webkit-box-orient', 'vertical', 'important');
                            title.style.setProperty('overflow', 'hidden', 'important');
                            title.style.setProperty('text-overflow', 'ellipsis', 'important');
                        }
                        
                        // Force reflow
                        void item.offsetHeight;
                        
                        const newHeight = item.offsetHeight;
                        if (newHeight > 200) {
                            console.error(`[ERROR] Item ${index} still too tall: ${newHeight}px`);
                        }
                    }
                } catch (error) {
                    console.error(`[ERROR] Failed to fix item ${index}:`, error);
                }
            });
        };
        
        // Run immediately and on DOM ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fixItems);
        } else {
            fixItems();
        }
        
        // Also run after a short delay to catch any late-rendered items
        setTimeout(fixItems, 100);
    } catch (error) {
        console.error('[ERROR] Failed to initialize item fix:', error);
    }
})();

// Start real-time updates when page loads
document.addEventListener('DOMContentLoaded', function() {
    const pageLoadStartTime = performance.now();
    console.log('[PAGE LOAD] Initializing notifications...');
    
    try {
        // Add initial history state
        if (window.history && window.history.pushState) {
            window.history.replaceState({ page: 'customer-notifications' }, '', 'customer-notifications.php');
        }
        
        // Force an immediate update to show all notifications
        // This ensures AJAX loads all notifications even if server-rendered content exists
        setTimeout(() => {
            console.log('[PAGE LOAD] Forcing initial notification fetch...');
            updateNotifications();
        }, 500);
        
        startRealTimeUpdates();
        updateRelativeTimes();
        
        // Update relative times every minute
        setInterval(updateRelativeTimes, 60000);
        
        // Stop updates when page is unloaded
        window.addEventListener('beforeunload', stopRealTimeUpdates);
        
        const pageLoadTime = performance.now() - pageLoadStartTime;
        console.log('[PAGE LOAD] Initialization completed in', pageLoadTime.toFixed(2), 'ms');
    } catch (error) {
        console.error('[PAGE LOAD] ERROR:', error);
    }
});

// Log page reload
window.addEventListener('load', function() {
    console.log('[PAGE RELOAD DEBUG] ============================================');
    console.log('[PAGE RELOAD DEBUG] Window load event fired');
    console.log('[PAGE RELOAD DEBUG] Timestamp:', new Date().toISOString());
    console.log('[PAGE RELOAD DEBUG] Current URL:', window.location.href);
    console.log('[PAGE RELOAD DEBUG] Document ready state:', document.readyState);
    if (performance.timing) {
        console.log('[PAGE RELOAD DEBUG] Performance timing:', {
            domContentLoaded: performance.timing.domContentLoadedEventEnd - performance.timing.domContentLoadedEventStart,
            loadComplete: performance.timing.loadEventEnd - performance.timing.loadEventStart
        });
    }
    console.log('[PAGE RELOAD DEBUG] ============================================');
});

</script>
