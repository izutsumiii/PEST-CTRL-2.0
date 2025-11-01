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

// Pull recent order status updates and notifications for this user
$stmt = $pdo->prepare("SELECT o.id as order_id, o.status, o.created_at, o.updated_at,
                             COALESCE(pt.payment_status, '') as payment_status,
                             IF(nr.id IS NULL, 0, 1) as is_read
                        FROM orders o
                        LEFT JOIN payment_transactions pt ON pt.id = o.payment_transaction_id
                        LEFT JOIN hidden_notifications hn ON hn.user_id = o.user_id AND hn.order_id = o.id
                        LEFT JOIN notification_reads nr ON nr.user_id = o.user_id 
                            AND nr.order_id = o.id 
                            AND nr.notification_type = 'order_update'
                        WHERE o.user_id = ? AND hn.id IS NULL
                        ORDER BY o.updated_at DESC, o.created_at DESC");
$stmt->execute([$userId]);
$orderEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get notifications from notifications table
// Get notifications from notifications table
$stmt = $pdo->prepare("SELECT n.order_id, n.message, n.type, n.created_at as updated_at,
                              IF(nr.id IS NULL, 0, 1) as is_read
                        FROM notifications n
                        LEFT JOIN notification_reads nr ON nr.user_id = n.user_id 
                            AND nr.order_id = n.order_id 
                            AND nr.notification_type = 'custom'
                        WHERE n.user_id = ? 
                        ORDER BY n.created_at DESC");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Combine and sort all events
$allEvents = array_merge($orderEvents, $notifications);
usort($allEvents, function($a, $b) {
    $timeA = strtotime($a['updated_at'] ?: $a['created_at']);
    $timeB = strtotime($b['updated_at'] ?: $b['created_at']);
    return $timeB - $timeA;
});
$events = $allEvents;

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
            
            if (isset($e['message'])) {
                // This is a notification
                $items[] = [
                    'order_id' => (int)$e['order_id'],
                    'status' => 'notification',
                    'message' => $e['message'],
                    'type' => $e['type'],
                    'updated_at' => $e['updated_at'],
                    'updated_at_human' => date('M d, Y h:i A', strtotime($e['updated_at'])),
                    'is_read' => $isRead
                ];
            } else {
                // This is an order event
                $items[] = [
                    'order_id' => (int)$e['order_id'],
                    'status' => (string)$e['status'],
                    'payment_status' => (string)$e['payment_status'],
                    'updated_at' => $e['updated_at'] ?: $e['created_at'],
                    'updated_at_human' => date('M d, Y h:i A', strtotime($e['updated_at'] ?: $e['created_at'])),
                    'is_read' => $isRead
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
    ];
    $label = ucfirst($status);
    $bg = '#6c757d';
    $fg = '#ffffff';
    if (isset($map[$status])) { [$label,$bg,$fg] = $map[$status]; }
    return '<span class="badge" style="background:'.$bg.';color:'.$fg.';padding:4px 8px;border-radius:12px;font-weight:600;font-size:12px;">'.$label.'</span>';
}
?>

<style>
  html, body { background:#f8f9fa !important; }
  main { background:#f8f9fa !important; }
  main h1 { text-shadow: none !important; }
  .notif-item { background:#ffffff !important; }
  .notif-item a { background:#ffffff !important; }
  .notif-item a:hover { background:#ffffff !important; }
  .notif-item a:focus { background:#ffffff !important; }
  .notif-item a:active { background:#ffffff !important; }
  .notif-item a:visited { background:#ffffff !important; }
  .notif-list .notif-item { background:#ffffff !important; }
  .notif-list .notif-item a { background:#ffffff !important; }
  .notif-list .notif-item a:hover { background:#ffffff !important; }
  .notif-list .notif-item a:focus { background:#ffffff !important; }
  .notif-list .notif-item a:active { background:#ffffff !important; }
  .notif-list .notif-item a:visited { background:#ffffff !important; }
  
  .notif-delete-btn-page {
    position: absolute;
    top: 12px;
    right: 8px;
    background: transparent;
    border: none;
    color: #dc3545;
    width: 28px;
    height: 28px;
    border-radius: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 12px;
    transition: all 0.3s ease;
    z-index: 10;
  }
  
  .notif-delete-btn-page:hover {
    background: transparent;
    border: none;
    color: #b02a37;
    transform: scale(1.15);
  }
  
  /* Custom confirmation dialog styles */
  .confirm-dialog {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    backdrop-filter: blur(5px);
  }
  
  .confirm-content {
    background: #ffffff;
    border-radius: 12px;
    padding: 30px;
    max-width: 400px;
    width: 90%;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    text-align: center;
    animation: confirmSlideIn 0.3s ease-out;
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
  /* Add smooth transitions for notification removal */
.notif-item {
  transition: opacity 0.3s ease, transform 0.3s ease;
}

.notif-list {
  transition: all 0.3s ease;
}

/* Ensure delete button is always visible */
.notif-item:hover .notif-delete-btn-page {
  opacity: 1;
}
  .confirm-title {
    font-size: 20px;
    font-weight: 700;
    color: #130325;
    margin-bottom: 15px;
  }
  
  .confirm-message {
    font-size: 16px;
    color: #666;
    margin-bottom: 25px;
    line-height: 1.5;
  }
  
  .confirm-buttons {
    display: flex;
    gap: 12px;
    justify-content: center;
  }
  
  .confirm-btn {
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
    min-width: 80px;
  }
  
  .confirm-btn-yes {
    background: #dc3545;
    color: white;
  }
  
  .confirm-btn-yes:hover {
    background: #c82333;
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4);
  }
  
  .confirm-btn-no {
    background: #6c757d;
    color: white;
  }
  
  .confirm-btn-no:hover {
    background: #5a6268;
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
  }
</style>
<main style="background:#f8f9fa; min-height:100vh; padding: 40px 0 60px 0;">
  <div style="max-width: 1400px; margin: 0 auto; padding: 0 60px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
      <h1 style="color:#130325; margin:0; text-shadow: none;">Notifications (<?php echo count($events); ?> total)</h1>
      <?php if (!empty($events)): ?>
        <form method="POST" style="margin:0;" id="deleteAllForm">
            <input type="hidden" name="delete_notifications" value="1">
            <button type="button" onclick="confirmDeleteAll()" style="background:#dc3545; color:#ffffff; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-weight:600;">
            <i class="fas fa-trash"></i> CLEAR ALL
          </button>
        </form>
      <?php endif; ?>
    </div>

    <?php if (isset($_GET['deleted']) && $_GET['deleted'] == '1'): ?>
      <div id="successMessage" style="position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background: #ffc107; color: #000; border: 1px solid #ffb300; border-radius: 8px; padding: 12px 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); z-index: 10000; font-weight: 600;">
        All notifications have been deleted successfully.
      </div>
      <script>
        setTimeout(function() {
          const message = document.getElementById('successMessage');
          if (message) {
            message.style.opacity = '0';
            message.style.transform = 'translateX(-50%) translateY(20px)';
            setTimeout(() => message.remove(), 300);
          }
        }, 2000);
      </script>
    <?php endif; ?>

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
      <div style="background:#ffffff; border:1px solid rgba(0,0,0,0.1); color:#130325; border-radius:8px; padding:20px; text-align:center;">No notifications yet.</div>
    <?php else: ?>
      <div class="notif-list" style="display:flex; flex-direction:column; gap:12px;">
        <?php foreach ($events as $e): ?>
          <div class="notif-item" style="position: relative;">
            <a href="user-dashboard.php#order-<?php echo (int)$e['order_id']; ?>" style="text-decoration:none; display: block;">
              <div style="display:flex; gap:12px; align-items:center; background:#ffffff; border:1px solid rgba(0,0,0,0.1); padding:14px; border-radius:10px;">
                <div style="width:36px; height:36px; display:flex; align-items:center; justify-content:center; background:rgba(255,215,54,0.15); color:#FFD736; border-radius:8px;">
                  <i class="fas fa-bell"></i>
                </div>
                <div style="flex:1;">
                  <?php if (isset($e['message'])): ?>
                    <!-- Custom notification -->
                    <div style="color:#130325; font-weight:700;"><?php echo htmlspecialchars($e['message']); ?></div>
                    <div style="color:#130325; opacity:0.9; font-size:0.9rem;">
                      Order #<?php echo (int)$e['order_id']; ?>
                    </div>
                  <?php else: ?>
                    <!-- Order status update -->
                    <div style="color:#130325; font-weight:700;">Order #<?php echo (int)$e['order_id']; ?> update</div>
                    <div style="color:#130325; opacity:0.9; font-size:0.9rem;">
                      Status: <?php echo statusBadge($e['status'] ?? 'unknown'); ?>
                      <?php if (!empty($e['payment_status'])): ?>
                        <span style="margin-left:8px; opacity:0.9;">Payment: <?php echo htmlspecialchars($e['payment_status']); ?></span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>
                <div style="color:#130325; opacity:0.8; font-size:0.85rem; white-space:nowrap; margin-right: 40px;">
                  <?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($e['updated_at'] ?: $e['created_at']))); ?>
                </div>
              </div>
            </a>
            <!-- X button for ALL notifications -->
            <button class="notif-delete-btn-page" onclick="deleteNotificationFromPage(<?php echo (int)$e['order_id']; ?>, this, <?php echo isset($e['message']) ? 'true' : 'false'; ?>)" title="Delete notification">
              <i class="fas fa-times"></i>
            </button>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</main>

<?php require_once 'includes/footer.php'; ?>

<script>
// Custom styled confirmation dialog function
function openConfirm(message, onConfirm) {
    // Create dialog overlay
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
    
    // Add to page
    document.body.appendChild(dialog);
    
    // Handle button clicks
    const yesBtn = dialog.querySelector('.confirm-btn-yes');
    const noBtn = dialog.querySelector('.confirm-btn-no');
    
    yesBtn.addEventListener('click', () => {
        document.body.removeChild(dialog);
        if (onConfirm) onConfirm();
    });
    
    noBtn.addEventListener('click', () => {
        document.body.removeChild(dialog);
    });
    
    // Handle escape key
    const handleEscape = (e) => {
        if (e.key === 'Escape') {
            document.body.removeChild(dialog);
            document.removeEventListener('keydown', handleEscape);
        }
    };
    document.addEventListener('keydown', handleEscape);
    
    // Handle click outside
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

// Real-time notifications update
let lastUpdateTime = null;
let refreshInterval = null;

function updateNotifications() {
    fetch('customer-notifications.php?as=json')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.items) {
                updateNotificationsDisplay(data.items, data.unread_count);
            }
        })
        .catch(error => {
            console.error('Error fetching notifications:', error);
        });
}

function updateNotificationsDisplay(items, unreadCount) {
    const notifList = document.querySelector('.notif-list');
    if (!notifList) return;
    
    // Clear existing notifications
    notifList.innerHTML = '';
    
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
    
    // Update page title count
    const header = document.querySelector('h1');
    if (header) {
        header.textContent = `Notifications (${items.length} total${unreadCount > 0 ? ', ' + unreadCount + ' unread' : ''})`;
    }
    
    if (items.length === 0) {
        notifList.innerHTML = '<div style="background:#ffffff; border:1px solid rgba(0,0,0,0.1); color:#130325; border-radius:8px; padding:20px; text-align:center;">No notifications yet.</div>';
        
        // Hide clear all button if no notifications
        const deleteAllForm = document.querySelector('form[method="POST"]');
        if (deleteAllForm) {
            deleteAllForm.style.display = 'none';
        }
        return;
    }
    
    // Add each notification
    items.forEach(item => {
        const notifElement = createNotificationElement(item);
        notifList.appendChild(notifElement);
    });
}

function createNotificationElement(item) {
    const notifContainer = document.createElement('div');
    notifContainer.className = 'notif-item';
    notifContainer.style.position = 'relative';
    
    // Add unread indicator style
    if (!item.is_read) {
        notifContainer.style.borderLeft = '4px solid #FFD736';
    }
    
    const notifDiv = document.createElement('a');
    notifDiv.href = `user-dashboard.php#order-${item.order_id}`;
    notifDiv.style.textDecoration = 'none';
    notifDiv.style.display = 'block';
    
    // Mark as read when clicked
    notifDiv.onclick = function(e) {
        if (!item.is_read) {
            markNotificationAsRead(item.order_id, item.status === 'notification');
        }
    };
    
    let content = '';
    if (item.status === 'notification') {
        content = `
            <div style="display:flex; gap:12px; align-items:center; background:#ffffff; border:1px solid rgba(0,0,0,0.1); padding:14px; border-radius:10px;">
                <div style="width:36px; height:36px; display:flex; align-items:center; justify-content:center; background:rgba(255,215,54,0.15); color:#FFD736; border-radius:8px;">
                    <i class="fas fa-info-circle"></i>
                </div>
                <div style="flex:1;">
                    <div style="color:#130325; font-weight:700;">${escapeHtml(item.message)}</div>
                    <div style="color:#130325; opacity:0.9; font-size:0.9rem;">Order #${item.order_id}</div>
                </div>
                <div style="color:#130325; opacity:0.8; font-size:0.85rem; white-space:nowrap; margin-right: 40px;">${item.updated_at_human}</div>
            </div>
        `;
    } else {
        const statusBadge = getStatusBadge(item.status);
        content = `
            <div style="display:flex; gap:12px; align-items:center; background:#ffffff; border:1px solid rgba(0,0,0,0.1); padding:14px; border-radius:10px;">
                <div style="width:36px; height:36px; display:flex; align-items:center; justify-content:center; background:rgba(255,215,54,0.15); color:#FFD736; border-radius:8px;">
                    <i class="fas fa-bell"></i>
                </div>
                <div style="flex:1;">
                    <div style="color:#130325; font-weight:700;">Order #${item.order_id} update</div>
                    <div style="color:#130325; opacity:0.9; font-size:0.9rem;">
                        Status: ${statusBadge}
                        ${item.payment_status ? `<span style="margin-left:8px; opacity:0.9;">Payment: ${escapeHtml(item.payment_status)}</span>` : ''}
                    </div>
                </div>
                <div style="color:#130325; opacity:0.8; font-size:0.85rem; white-space:nowrap; margin-right: 40px;">${item.updated_at_human}</div>
            </div>
        `;
    }
    
    notifDiv.innerHTML = content;
    notifContainer.appendChild(notifDiv);
    
    // Add X button for ALL notifications
    const deleteBtn = document.createElement('button');
    deleteBtn.className = 'notif-delete-btn-page';
    deleteBtn.onclick = function(e) { 
        e.preventDefault();
        e.stopPropagation();
        deleteNotificationFromPage(item.order_id, this, item.status === 'notification'); 
    };
    deleteBtn.title = 'Delete notification';
    deleteBtn.innerHTML = '<i class="fas fa-times"></i>';
    notifContainer.appendChild(deleteBtn);
    
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
    };
    
    const [label, bg, fg] = statusMap[status.toLowerCase()] || ['Unknown', '#6c757d', '#ffffff'];
    return `<span class="badge" style="background:${bg};color:${fg};padding:4px 8px;border-radius:12px;font-weight:600;font-size:12px;">${label}</span>`;
}

// Function to mark notification as read
function markNotificationAsRead(orderId, isCustomNotification) {
    fetch('ajax/mark-notification-read.php', {
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
            // Update badge count immediately - both on page and in header
            setTimeout(() => {
                updateNotifications();
                // Also update the header badge
                updateHeaderBadge();
            }, 500);
        }
    })
    .catch(error => {
        console.error('Error marking notification as read:', error);
    });
}

// Add this new function to update the header badge
function updateHeaderBadge() {
    fetch('customer-notifications.php?as=json')
        .then(response => response.json())
        .then(data => {
            if (data && data.success) {
                const badge = document.getElementById('notif-badge');
                const unreadCount = data.unread_count || 0;
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
                    
                    // Restart real-time updates after deletion to get fresh count
                    setTimeout(() => {
                        startRealTimeUpdates();
                        updateNotifications();
                    }, 500);
                }, 300);
            }
        } else {
            // Show error and restore button
            button.innerHTML = '<i class="fas fa-times"></i>';
            button.disabled = false;
            alert('Error deleting notification: ' + (data.message || 'Unknown error'));
            
            // Restart real-time updates even on error
            setTimeout(() => {
                startRealTimeUpdates();
            }, 1000);
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
</script>


