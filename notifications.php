<?php
date_default_timezone_set('Asia/Manila');
require_once 'config/database.php';
require_once 'includes/functions.php';

// Handle delete notifications request
if (isset($_POST['delete_notifications'])) {
    session_start();
    if (isset($_SESSION['user_id'])) {
        try {
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $successMessage = "All notifications have been deleted successfully.";
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

// Pull recent order status updates and notifications for this user
$stmt = $pdo->prepare("SELECT o.id as order_id, o.status, o.created_at, o.updated_at,
                             COALESCE(pt.payment_status, '') as payment_status
                        FROM orders o
                        LEFT JOIN payment_transactions pt ON pt.id = o.payment_transaction_id
                        WHERE o.user_id = ?
                        ORDER BY o.updated_at DESC, o.created_at DESC
                        LIMIT 50");
$stmt->execute([$userId]);
$orderEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get notifications from notifications table
$stmt = $pdo->prepare("SELECT order_id, message, type, created_at as updated_at
                        FROM notifications 
                        WHERE user_id = ? 
                        ORDER BY created_at DESC 
                        LIMIT 50");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Combine and sort all events
$allEvents = array_merge($orderEvents, $notifications);
usort($allEvents, function($a, $b) {
    $timeA = strtotime($a['updated_at'] ?: $a['created_at']);
    $timeB = strtotime($b['updated_at'] ?: $b['created_at']);
    return $timeB - $timeA;
});
$events = array_slice($allEvents, 0, 100);

// JSON mode for header popup
if (isset($_GET['as']) && $_GET['as'] === 'json') {
    header('Content-Type: application/json');
    $items = [];
    foreach ($events as $e) {
        if (isset($e['message'])) {
            // This is a notification
            $items[] = [
                'order_id' => (int)$e['order_id'],
                'status' => 'notification',
                'message' => $e['message'],
                'type' => $e['type'],
                'updated_at' => $e['updated_at'],
                'updated_at_human' => date('M d, Y h:i A', strtotime($e['updated_at']))
            ];
        } else {
            // This is an order event
            $items[] = [
                'order_id' => (int)$e['order_id'],
                'status' => (string)$e['status'],
                'payment_status' => (string)$e['payment_status'],
                'updated_at' => $e['updated_at'] ?: $e['created_at'],
                'updated_at_human' => date('M d, Y h:i A', strtotime($e['updated_at'] ?: $e['created_at']))
            ];
        }
    }
    echo json_encode(['success' => true, 'items' => $items]);
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
    ];
    $label = ucfirst($status);
    $bg = '#6c757d';
    $fg = '#ffffff';
    if (isset($map[$status])) { [$label,$bg,$fg] = $map[$status]; }
    return '<span class="badge" style="background:'.$bg.';color:'.$fg.';padding:4px 8px;border-radius:12px;font-weight:600;font-size:12px;">'.$label.'</span>';
}
?>

<style>
  html, body { background:#130325 !important; }
</style>
<main style="background:#130325; min-height:100vh; padding: 80px 0 60px 0;">
  <div style="max-width: 1000px; margin: 0 auto; padding: 0 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
      <h1 style="color:#ffffff; margin:0;">Notifications</h1>
      <?php if (!empty($events)): ?>
        <form method="POST" style="margin:0;" onsubmit="return confirm('Are you sure you want to delete all notifications? This action cannot be undone.');">
          <button type="submit" name="delete_notifications" style="background:#dc3545; color:#ffffff; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-weight:600;">
            <i class="fas fa-trash"></i> Delete All
          </button>
        </form>
      <?php endif; ?>
    </div>

    <?php if (isset($successMessage)): ?>
      <div style="background:#d4edda; color:#155724; border:1px solid #c3e6cb; border-radius:6px; padding:12px; margin-bottom:20px;">
        <?php echo htmlspecialchars($successMessage); ?>
      </div>
    <?php endif; ?>

    <?php if (isset($errorMessage)): ?>
      <div style="background:#f8d7da; color:#721c24; border:1px solid #f1b0b7; border-radius:6px; padding:12px; margin-bottom:20px;">
        <?php echo htmlspecialchars($errorMessage); ?>
      </div>
    <?php endif; ?>

    <?php if (empty($events)): ?>
      <div style="background:#1a0a2e; border:1px solid #2d1b4e; color:#F9F9F9; border-radius:8px; padding:20px; text-align:center;">No notifications yet.</div>
    <?php else: ?>
      <div class="notif-list" style="display:flex; flex-direction:column; gap:12px;">
        <?php foreach ($events as $e): ?>
          <a href="user-dashboard.php#order-<?php echo (int)$e['order_id']; ?>" class="notif-item" style="text-decoration:none;">
            <div style="display:flex; gap:12px; align-items:center; background:#1a0a2e; border:1px solid rgba(255,215,54,0.25); padding:14px; border-radius:10px;">
              <div style="width:36px; height:36px; display:flex; align-items:center; justify-content:center; background:rgba(255,215,54,0.15); color:#FFD736; border-radius:8px;">
                <i class="fas fa-bell"></i>
              </div>
              <div style="flex:1;">
                <?php if (isset($e['message'])): ?>
                  <!-- Custom notification -->
                  <div style="color:#F9F9F9; font-weight:700;"><?php echo htmlspecialchars($e['message']); ?></div>
                  <div style="color:#F9F9F9; opacity:0.9; font-size:0.9rem;">
                    Order #<?php echo (int)$e['order_id']; ?>
                  </div>
                <?php else: ?>
                  <!-- Order status update -->
                  <div style="color:#F9F9F9; font-weight:700;">Order #<?php echo (int)$e['order_id']; ?> update</div>
                  <div style="color:#F9F9F9; opacity:0.9; font-size:0.9rem;">
                    Status: <?php echo statusBadge($e['status'] ?? 'unknown'); ?>
                    <?php if (!empty($e['payment_status'])): ?>
                      <span style="margin-left:8px; opacity:0.9;">Payment: <?php echo htmlspecialchars($e['payment_status']); ?></span>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
              <div style="color:#F9F9F9; opacity:0.8; font-size:0.85rem; white-space:nowrap;">
                <?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($e['updated_at'] ?: $e['created_at']))); ?>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</main>

<?php require_once 'includes/footer.php'; ?>

<script>
// Real-time notifications update
let lastUpdateTime = null;
let refreshInterval = null;

function updateNotifications() {
    fetch('notifications.php?as=json')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.items) {
                updateNotificationsDisplay(data.items);
            }
        })
        .catch(error => {
            console.error('Error fetching notifications:', error);
        });
}

function updateNotificationsDisplay(items) {
    const notifList = document.querySelector('.notif-list');
    if (!notifList) return;
    
    // Clear existing notifications
    notifList.innerHTML = '';
    
    if (items.length === 0) {
        notifList.innerHTML = '<div style="background:#1a0a2e; border:1px solid #2d1b4e; color:#F9F9F9; border-radius:8px; padding:20px; text-align:center;">No notifications yet.</div>';
        return;
    }
    
    // Add each notification
    items.forEach(item => {
        const notifElement = createNotificationElement(item);
        notifList.appendChild(notifElement);
    });
}

function createNotificationElement(item) {
    const notifDiv = document.createElement('a');
    notifDiv.href = `user-dashboard.php#order-${item.order_id}`;
    notifDiv.style.textDecoration = 'none';
    
    let content = '';
    if (item.status === 'notification') {
        content = `
            <div style="display:flex; gap:12px; align-items:center; background:#1a0a2e; border:1px solid rgba(255,215,54,0.25); padding:14px; border-radius:10px;">
                <div style="width:36px; height:36px; display:flex; align-items:center; justify-content:center; background:rgba(255,215,54,0.15); color:#FFD736; border-radius:8px;">
                    <i class="fas fa-info-circle"></i>
                </div>
                <div style="flex:1;">
                    <div style="color:#F9F9F9; font-weight:700;">${item.message}</div>
                    <div style="color:#F9F9F9; opacity:0.9; font-size:0.9rem;">Order #${item.order_id}</div>
                </div>
                <div style="color:#F9F9F9; opacity:0.8; font-size:0.85rem; white-space:nowrap;">${item.updated_at_human}</div>
            </div>
        `;
    } else {
        const statusBadge = getStatusBadge(item.status);
        content = `
            <div style="display:flex; gap:12px; align-items:center; background:#1a0a2e; border:1px solid rgba(255,215,54,0.25); padding:14px; border-radius:10px;">
                <div style="width:36px; height:36px; display:flex; align-items:center; justify-content:center; background:rgba(255,215,54,0.15); color:#FFD736; border-radius:8px;">
                    <i class="fas fa-bell"></i>
                </div>
                <div style="flex:1;">
                    <div style="color:#F9F9F9; font-weight:700;">Order #${item.order_id} update</div>
                    <div style="color:#F9F9F9; opacity:0.9; font-size:0.9rem;">
                        Status: ${statusBadge}
                        ${item.payment_status ? `<span style="margin-left:8px; opacity:0.9;">Payment: ${item.payment_status}</span>` : ''}
                    </div>
                </div>
                <div style="color:#F9F9F9; opacity:0.8; font-size:0.85rem; white-space:nowrap;">${item.updated_at_human}</div>
            </div>
        `;
    }
    
    notifDiv.innerHTML = content;
    return notifDiv;
}

function getStatusBadge(status) {
    const statusMap = {
        'pending': ['Pending', '#ffc107', '#130325'],
        'processing': ['Processing', '#0dcaf0', '#130325'],
        'shipped': ['Shipped', '#17a2b8', '#ffffff'],
        'delivered': ['Delivered', '#28a745', '#ffffff'],
        'cancelled': ['Cancelled', '#dc3545', '#ffffff'],
        'refunded': ['Refunded', '#6c757d', '#ffffff'],
    };
    
    const [label, bg, fg] = statusMap[status] || ['Unknown', '#6c757d', '#ffffff'];
    return `<span class="badge" style="background:${bg};color:${fg};padding:4px 8px;border-radius:12px;font-weight:600;font-size:12px;">${label}</span>`;
}

function startRealTimeUpdates() {
    // Update every 5 seconds
    refreshInterval = setInterval(updateNotifications, 5000);
    
    // Also update when page becomes visible again
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            updateNotifications();
        }
    });
}

function stopRealTimeUpdates() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
        refreshInterval = null;
    }
}

// Start real-time updates when page loads
document.addEventListener('DOMContentLoaded', function() {
    startRealTimeUpdates();
    
    // Stop updates when page is unloaded
    window.addEventListener('beforeunload', stopRealTimeUpdates);
});

// Update time display to show relative time (e.g., "2 minutes ago")
function updateRelativeTimes() {
    const timeElements = document.querySelectorAll('[data-time]');
    timeElements.forEach(element => {
        const timestamp = element.getAttribute('data-time');
        const relativeTime = getRelativeTime(timestamp);
        element.textContent = relativeTime;
    });
}

function getRelativeTime(timestamp) {
    const now = new Date();
    const time = new Date(timestamp);
    const diffInSeconds = Math.floor((now - time) / 1000);
    
    if (diffInSeconds < 60) {
        return 'Just now';
    } else if (diffInSeconds < 3600) {
        const minutes = Math.floor(diffInSeconds / 60);
        return `${minutes} minute${minutes > 1 ? 's' : ''} ago`;
    } else if (diffInSeconds < 86400) {
        const hours = Math.floor(diffInSeconds / 3600);
        return `${hours} hour${hours > 1 ? 's' : ''} ago`;
    } else {
        const days = Math.floor(diffInSeconds / 86400);
        return `${days} day${days > 1 ? 's' : ''} ago`;
    }
}

// Update relative times every minute
setInterval(updateRelativeTimes, 60000);
</script>


