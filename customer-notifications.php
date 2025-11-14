<?php
// Turn off error display for JSON requests to prevent breaking JSON output
if (isset($_GET['as']) && $_GET['as'] === 'json') {
    error_reporting(0);
    ini_set('display_errors', 0);
}

date_default_timezone_set('Asia/Manila');
require_once 'config/database.php';
require_once 'includes/functions.php';

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
// Combined query - get order updates WITH custom messages joined
// Group by order_id to show only ONE notification per order (the most recent)
$stmt = $pdo->prepare("
    SELECT 
        o.id as order_id, 
        o.status, 
        o.created_at, 
        o.updated_at,
        COALESCE(NULLIF(pt.payment_status, ''), NULL) as payment_status,
        IF(nr.read_at IS NULL OR GREATEST(COALESCE(o.updated_at, o.created_at), COALESCE(rr.processed_at, o.created_at), COALESCE(n.created_at, o.created_at)) > nr.read_at, 0, 1) as is_read,
        rr.status as return_status,
        rr.processed_at as return_updated_at,
        n.message,
        n.type,
        GREATEST(
            COALESCE(o.updated_at, o.created_at), 
            COALESCE(rr.processed_at, o.created_at),
            COALESCE(n.created_at, o.created_at)
        ) as sort_date
    FROM orders o
    LEFT JOIN payment_transactions pt ON pt.id = o.payment_transaction_id
    LEFT JOIN hidden_notifications hn ON hn.user_id = o.user_id AND hn.order_id = o.id
    LEFT JOIN (
        SELECT user_id, order_id, MAX(read_at) as read_at
        FROM notification_reads
        GROUP BY user_id, order_id
    ) nr ON nr.user_id = o.user_id AND nr.order_id = o.id
    LEFT JOIN return_requests rr ON rr.order_id = o.id
    LEFT JOIN (
        SELECT order_id, user_id, message, type, created_at
        FROM notifications
        WHERE (order_id, created_at) IN (
            SELECT order_id, MAX(created_at)
            FROM notifications
            GROUP BY order_id
        )
    ) n ON n.order_id = o.id AND n.user_id = o.user_id
    WHERE o.user_id = ? AND hn.id IS NULL
    GROUP BY o.id
    ORDER BY sort_date DESC
");
$stmt->execute([$userId]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            
            // CRITICAL FIX: Always include actual order status, even for custom notifications
            // This ensures status badges match the actual order status
            $actualOrderStatus = (string)($e['status'] ?? 'unknown');
            $mostRecentUpdate = $e['updated_at'] ?: $e['created_at'];
            if (!empty($e['return_updated_at']) && strtotime($e['return_updated_at']) > strtotime($mostRecentUpdate)) {
                $mostRecentUpdate = $e['return_updated_at'];
            }
            
            if (isset($e['message']) && !empty($e['message'])) {
                // This is a custom notification - but still include actual order status
                $items[] = [
                    'order_id' => (int)$e['order_id'],
                    'status' => $actualOrderStatus, // Use actual order status, not 'notification'
                    'message' => $e['message'],
                    'type' => $e['type'],
                    'payment_status' => !empty($e['payment_status']) ? (string)$e['payment_status'] : null,
                    'return_status' => !empty($e['return_status']) ? (string)$e['return_status'] : null,
                    'updated_at' => $mostRecentUpdate,
                    'updated_at_human' => date('M d, Y h:i A', strtotime($mostRecentUpdate)),
                    'is_read' => $isRead,
                    'is_custom_notification' => true // Flag to identify custom notifications
                ];
            } else {
                // This is an order event
                $items[] = [
                    'order_id' => (int)$e['order_id'],
                    'status' => $actualOrderStatus,
                    'payment_status' => !empty($e['payment_status']) ? (string)$e['payment_status'] : null,
                    'return_status' => !empty($e['return_status']) ? (string)$e['return_status'] : null,
                    'updated_at' => $mostRecentUpdate,
                    'updated_at_human' => date('M d, Y h:i A', strtotime($mostRecentUpdate)),
                    'is_read' => $isRead,
                    'is_custom_notification' => false
                ];
            }
            
        }
        echo json_encode(['success' => true, 'items' => $items, 'unread_count' => $unreadCount]);
    } catch (Exception $e) {
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

.notif-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.notif-item {
    background: var(--bg-white) !important;
    border: 1px solid var(--border-light);
    border-radius: 12px;
    transition: all 0.2s ease;
    position: relative;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.notif-item:hover {
    border-color: var(--primary-dark);
    box-shadow: 0 4px 12px rgba(19, 3, 37, 0.15), 0 0 0 1px rgba(19, 3, 37, 0.1);
    transform: translateY(-1px);
}

.notif-item[style*="border-left"] {
    border-left: 4px solid var(--primary-dark) !important;
    background: linear-gradient(90deg, #f8f7fa 0%, var(--bg-white) 100%) !important;
    box-shadow: 0 2px 8px rgba(19, 3, 37, 0.1);
}

.notif-item a {
    text-decoration: none;
    display: block;
    background: transparent !important;
    padding: 12px 14px;
    border-radius: 12px;
}

.notif-item-content {
    display: flex;
    gap: 10px;
    align-items: flex-start;
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

.notif-title {
    color: var(--text-dark);
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 5px;
    line-height: 1.4;
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
    content: "🔔";
    font-size: 48px;
    display: block;
    margin-bottom: 16px;
    opacity: 0.5;
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

/* Notification animations */
@keyframes slideInNotification {
    from {
        opacity: 0;
        transform: translateX(-20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.notif-item.new-notification {
    animation: slideInNotification 0.3s ease-out;
}

@keyframes subtlePulse {
    0%, 100% {
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }
    50% {
        box-shadow: 0 2px 12px rgba(19, 3, 37, 0.2);
    }
}

.notif-item[style*="border-left"] {
    animation: subtlePulse 2s ease-in-out infinite;
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
      <div class="empty-state">No notifications yet.</div>
    <?php else: ?>
      <div class="notif-list">
        <?php foreach ($events as $e): ?>
          <div class="notif-item" <?php if (empty($e['is_read'])): ?>style="border-left: 4px solid var(--primary-dark);"<?php endif; ?>>
            <?php
            $status = strtolower($e['status']);
            $filterStatus = $status;
            
            switch($status) {
                case 'pending': $filterStatus = 'pending'; break;
                case 'processing': $filterStatus = 'processing'; break;
                case 'shipped': $filterStatus = 'shipped'; break;
                case 'delivered': $filterStatus = 'delivered'; break;
                case 'cancelled': $filterStatus = 'cancelled'; break;
            }
            
            if (!empty($e['return_status'])) {
                $filterStatus = 'return_requested';
            }
            ?>
            <a href="order-details.php?id=<?php echo (int)$e['order_id']; ?>" data-order-id="<?php echo (int)$e['order_id']; ?>" data-is-custom="<?php echo isset($e['message']) ? '1' : '0'; ?>">
              <div class="notif-item-content">
                <div class="notif-icon">
                  <i class="fas fa-bell"></i>
                </div>
                <div class="notif-body">
                  <div class="notif-title">
                    <?php if (isset($e['message']) && !empty($e['message'])): ?>
                      <?php echo htmlspecialchars($e['message']); ?>
                    <?php else: ?>
                      Order #<?php echo (int)$e['order_id']; ?> update
                    <?php endif; ?>
                  </div>
                  <div class="notif-details">
                    <span>Order #<?php echo (int)$e['order_id']; ?></span>
                    <span>•</span>
                    <span>Status: <?php echo statusBadge($e['status'] ?? 'unknown'); ?></span>
                    <?php if (!empty($e['payment_status']) && $e['payment_status'] !== ''): ?>
                      <span>Payment: <?php echo statusBadge($e['payment_status']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($e['return_status']) && $e['return_status'] !== ''): ?>
                      <span>Return: <?php echo statusBadge($e['return_status']); ?></span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="notif-meta">
                  <span class="notif-time" data-timestamp="<?php echo strtotime($e['updated_at'] ?: $e['created_at']); ?>"><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($e['updated_at'] ?: $e['created_at']))); ?></span>
                  <button 
                    type="button"
                    onclick="event.stopPropagation(); handleViewNotification(<?php echo (int)$e['order_id']; ?>, <?php echo isset($e['message']) ? '1' : '0'; ?>);"
                    class="btn-view"
                    title="View Details">
                    <i class="fas fa-eye"></i> View
                  </button>
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
    const notifList = document.querySelector('.notif-list');
    if (!notifList) return;
    
    // Create hash of current data to check if update is needed
    // Include all relevant fields to detect any changes
    const currentHash = JSON.stringify(items.map(i => ({
        id: i.order_id,
        status: i.status || 'unknown',
        payment: i.payment_status || null,
        return: i.return_status || null,
        read: i.is_read || false,
        message: i.message || null,
        updated_at: i.updated_at || null
    })));
    
    // Only update if data actually changed
    if (currentHash === lastUpdateHash && notifList.children.length > 0) {
        // Just update header counts, don't rebuild list
        updateHeaderCounts(items.length, unreadCount);
        return;
    }
    
    lastUpdateHash = currentHash;
    
    // Clear existing notifications
    notifList.innerHTML = '';
    
    // Update header counts
    updateHeaderCounts(items.length, unreadCount);

    // Show/hide clear all button based on notifications
    const deleteAllForm = document.getElementById('deleteAllForm');
    if (deleteAllForm) {
        deleteAllForm.style.display = items.length > 0 ? 'block' : 'none';
    }
    
    if (items.length === 0) {
        notifList.innerHTML = '<div class="empty-state">No notifications yet.</div>';
        return;
    }
    
    // Add each notification
    items.forEach(item => {
        const notifElement = createNotificationElement(item);
        notifList.appendChild(notifElement);
    });
    
    // Update relative times after adding notifications
    setTimeout(updateRelativeTimes, 100);
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
    const notifContainer = document.createElement('div');
    notifContainer.className = 'notif-item';
    notifContainer.style.position = 'relative';
    
    // Add unread indicator style
    if (!item.is_read) {
        notifContainer.style.borderLeft = '4px solid #130325';
    }
    
    const notifDiv = document.createElement('a');
    notifDiv.setAttribute('data-order-id', item.order_id);
    notifDiv.setAttribute('data-is-custom', item.is_custom_notification ? '1' : '0');
    
// Determine correct filter based on status
// Determine correct filter based on status
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

// Link directly to order details for precision
const targetUrl = item.order_id ? `order-details.php?id=${item.order_id}` : 'customer-notifications.php';
notifDiv.href = targetUrl;
notifDiv.style.textDecoration = 'none';
notifDiv.style.display = 'block';

// Mark as read when clicked
notifDiv.addEventListener('click', function(e) {
    // Don't intercept if clicking the View button (it has its own handler)
    if (e.target.closest('.btn-view')) {
        return;
    }
    
    e.preventDefault();
    const isCustom = item.is_custom_notification || false;
    
    if (!item.is_read) {
        markNotificationAsRead(item.order_id, isCustom);
        // Update UI immediately
        updateNotificationItemUI(item.order_id);
        // Update header badge immediately and after a delay
        setTimeout(updateHeaderBadge, 100);
        setTimeout(updateHeaderBadge, 500);
    } else {
        // Even if already read, update badge to ensure it's current
        setTimeout(updateHeaderBadge, 100);
    }
    
    // Add to history for back button functionality
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
    
    // Build title - show message if custom notification, otherwise show order update
    let titleText = '';
    if (item.is_custom_notification && item.message) {
        titleText = escapeHtml(item.message);
    } else {
        titleText = `Order #${item.order_id} update`;
    }
    
    content = `
        <div class="notif-item-content">
            <div class="notif-icon">
                <i class="fas fa-bell"></i>
            </div>
            <div class="notif-body">
                <div class="notif-title">${titleText}</div>
                <div class="notif-details">
                    <span>Order #${item.order_id}</span>
                    <span>•</span>
                    <span>Status: ${statusBadge}</span>
                    ${paymentBadge}
                    ${returnBadge}
                </div>
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
    
    
    notifDiv.innerHTML = content;
    notifContainer.appendChild(notifDiv);
    
    // No per-item delete in page list
    
    return notifContainer;
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
                            notifList.innerHTML = '<div class="empty-state">No notifications yet.</div>';
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

function startRealTimeUpdates() {
    // Clear any existing interval first
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }
    
    // Update every 5 seconds (reduced frequency to prevent flicker)
    refreshInterval = setInterval(updateNotifications, 5000);
    
    // Initial update with delay to let page fully load
    setTimeout(() => {
        updateNotifications();
    }, 1000);
}

function stopRealTimeUpdates() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
        refreshInterval = null;
    }
}

// Add visibility change handler
document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
        updateNotifications();
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

// Handle back button - redirect to customer-notifications if coming from order details
window.addEventListener('popstate', function(event) {
    if (event.state && event.state.page === 'customer-notifications') {
        return;
    }
    if (window.location.pathname.indexOf('customer-notifications') === -1) {
        window.location.href = 'customer-notifications.php';
    }
});

// Start real-time updates when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Add initial history state
    if (window.history && window.history.pushState) {
        window.history.replaceState({ page: 'customer-notifications' }, '', 'customer-notifications.php');
    }
    
    startRealTimeUpdates();
    updateRelativeTimes();
    
    // Update relative times every minute
    setInterval(updateRelativeTimes, 60000);
    
    // Stop updates when page is unloaded
    window.addEventListener('beforeunload', stopRealTimeUpdates);
});

</script>
