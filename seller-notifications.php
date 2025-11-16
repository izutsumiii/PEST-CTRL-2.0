<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_once 'includes/seller_notification_functions.php';

// Check seller login
requireSeller();
$userId = $_SESSION['user_id'];

// Get all notifications for this seller
$notifications = getSellerNotifications($userId, 50);
$unreadCount = getSellerUnreadCount($userId);

// Include seller header
require_once 'includes/seller_header.php';

// Helper function for status badge
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

// Helper function for notification badge class
function getNotificationBadgeClass($title, $type) {
    $titleLower = strtolower($title);
    
    if (strpos($titleLower, 'new order') !== false || strpos($titleLower, 'order received') !== false) {
        return 'notification-status-order';
    }
    if (strpos($titleLower, 'return request') !== false || strpos($titleLower, 'return/refund') !== false || strpos($titleLower, 'refund') !== false) {
        return 'notification-status-return';
    }
    if (strpos($titleLower, 'low stock') !== false) {
        return 'notification-status-lowstock';
    }
    if (strpos($titleLower, 'processing') !== false || strpos($titleLower, 'order status') !== false) {
        return 'notification-status-processing';
    }
    
    return 'notification-status-order';
}

// Helper function for notification badge text
function getNotificationBadgeText($title, $type) {
    $titleLower = strtolower($title);
    
    if (strpos($titleLower, 'new order') !== false || strpos($titleLower, 'order received') !== false) {
        return 'Order';
    }
    if (strpos($titleLower, 'return request') !== false || strpos($titleLower, 'return/refund') !== false || strpos($titleLower, 'refund') !== false) {
        return 'Return/Refund';
    }
    if (strpos($titleLower, 'low stock') !== false) {
        return 'Low Stocks';
    }
    if (strpos($titleLower, 'processing') !== false || strpos($titleLower, 'order status') !== false) {
        return 'Processing';
    }
    
    return 'Order';
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
    max-width: 1400px;
    margin: 0;
    padding: 12px 20px;
    margin-left: -220px;
    transition: margin-left 0.3s ease;
}

.sidebar.collapsed ~ main .notifications-container {
    margin-left: 80px;
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

/* Responsive */
@media (max-width: 968px) {
    .notifications-container {
        padding: 10px 12px;
        max-width: 100%;
        margin-left: 0 !important;
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
<main style="background: var(--bg-light); min-height: 100vh; padding: 0; margin-top: -30px;">
  <div class="notifications-container">
    <div class="page-header">
      <div class="page-header-left">
        <a href="seller-dashboard.php" class="back-arrow" title="Back to Dashboard">
          <i class="fas fa-arrow-left"></i>
        </a>
        <div class="page-header-title">
          Notifications
        </div>
        <span class="badge badge-total"><?php echo count($notifications); ?> total</span>
        <?php if ($unreadCount > 0): ?>
          <span class="badge badge-unread"><?php echo (int)$unreadCount; ?> unread</span>
        <?php endif; ?>
      </div>
      <?php if (!empty($notifications)): ?>
        <button type="button" onclick="markAllAsRead()" class="btn-clear-all">
          <i class="fas fa-check-double"></i> Mark All as Read
        </button>
      <?php endif; ?>
    </div>

    <?php if (empty($notifications)): ?>
      <div class="empty-state">No notifications yet.</div>
    <?php else: ?>
      <div class="notif-list">
        <?php foreach ($notifications as $notification): ?>
          <div class="notif-item" <?php if (!$notification['is_read']): ?>style="border-left: 4px solid var(--primary-dark);"<?php endif; ?>>
            <a href="#" onclick="handleViewNotification(<?php echo (int)$notification['id']; ?>, '<?php echo htmlspecialchars($notification['action_url'] ?: '', ENT_QUOTES); ?>'); return false;" data-notif-id="<?php echo (int)$notification['id']; ?>" data-notif-url="<?php echo htmlspecialchars($notification['action_url'] ?: '', ENT_QUOTES); ?>">
              <div class="notif-item-content">
                <div class="notif-icon">
                  <i class="fas fa-bell"></i>
                </div>
                <div class="notif-body">
                  <div class="notif-title">
                    <?php echo htmlspecialchars($notification['title']); ?>
                  </div>
                  <div class="notif-details">
                    <span><?php echo htmlspecialchars($notification['message']); ?></span>
                  </div>
                </div>
                <div class="notif-meta">
                  <span class="notif-time" data-timestamp="<?php echo strtotime($notification['created_at']); ?>"><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($notification['created_at']))); ?></span>
                  <button 
                    type="button"
                    onclick="event.stopPropagation(); event.preventDefault(); handleViewNotification(<?php echo (int)$notification['id']; ?>, '<?php echo htmlspecialchars($notification['action_url'] ?: '', ENT_QUOTES); ?>');"
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

<script>
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

function markAllAsRead() {
    openConfirm('Are you sure you want to mark all notifications as read?', function() {
        fetch('ajax/mark-all-seller-notifications-read.php', {
            method: 'POST'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            }
        })
        .catch(error => {
            console.error('Error marking all notifications as read:', error);
        });
    });
}

// Function to handle View button click - marks as read and navigates
function handleViewNotification(notificationId, actionUrl) {
    // Update UI immediately for better UX
    updateNotificationItemUI(notificationId);
    
    // Mark as read - use sendBeacon for reliability when navigating
    const data = JSON.stringify({
        notification_id: notificationId
    });
    
    // Use sendBeacon for reliability when navigating away
    if (navigator.sendBeacon) {
        const formData = new FormData();
        formData.append('notification_id', notificationId);
        navigator.sendBeacon('ajax/mark-seller-notification-read.php', formData);
    }
    
    // Also use fetch for immediate badge update (if page doesn't navigate)
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
            // Update header badge if function exists
            if (typeof updateSellerNotificationBadge === 'function') {
                fetch('ajax/get-seller-notifications.php', { credentials: 'same-origin' })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            updateSellerNotificationBadge(data.unreadCount);
                        }
                    });
            }
        }
    })
    .catch(error => {
        console.error('Error marking notification as read:', error);
    });
    
    // Navigate after a short delay to ensure the mark request is sent
    setTimeout(function() {
        if (actionUrl && actionUrl !== '' && actionUrl !== 'null' && actionUrl !== 'undefined') {
            window.location.href = actionUrl;
        } else {
            // Extract order ID or return ID from notification title/message if possible
            const notifItem = document.querySelector(`[data-notif-id="${notificationId}"]`);
            if (notifItem) {
                const title = notifItem.closest('.notif-item').querySelector('.notif-title').textContent;
                const message = notifItem.closest('.notif-item').querySelector('.notif-details span').textContent;
                const text = title + ' ' + message;
                
                // Try to extract order ID
                const orderMatch = text.match(/(?:Order\s*#?0*|order_id[=:])\s*(\d{1,10})/i);
                if (orderMatch && orderMatch[1]) {
                    window.location.href = 'seller-order-details.php?order_id=' + orderMatch[1];
                    return;
                }
                
                // Try to extract return ID
                const returnMatch = text.match(/(?:return_id[=:]|return\s*request\s*#?)\s*(\d{1,10})/i);
                if (returnMatch && returnMatch[1]) {
                    window.location.href = 'seller-returns.php?return_id=' + returnMatch[1];
                    return;
                }
            }
            
            // Default fallback
            window.location.href = 'view-orders.php';
        }
    }, 150);
}

// Function to update notification item UI (remove unread styling)
function updateNotificationItemUI(notificationId) {
    // Find the notification item
    const notifLink = document.querySelector(`[data-notif-id="${notificationId}"]`);
    if (notifLink) {
        const notifItem = notifLink.closest('.notif-item');
        if (notifItem) {
            // Remove unread styling
            notifItem.style.borderLeft = 'none';
            notifItem.style.background = 'var(--bg-white)';
        }
    }
}

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

// Update relative times on page load
document.addEventListener('DOMContentLoaded', function() {
    updateRelativeTimes();
    
    // Update relative times every minute
    setInterval(updateRelativeTimes, 60000);
});
</script>
