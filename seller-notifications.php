<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_once 'includes/seller_notification_functions.php';

// Check seller login
requireSeller();
$userId = $_SESSION['user_id'];

// Get seller info
$stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$userId]);
$seller = $stmt->fetch(PDO::FETCH_ASSOC);

// Get all notifications for this seller
$notifications = getSellerNotifications($userId, 50); // Get more notifications for the full page
$unreadCount = getSellerUnreadCount($userId);

// Include seller header
require_once 'includes/seller_header.php';

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
  
  
  /* Add smooth transitions for notification removal */
  .notif-item {
    transition: opacity 0.3s ease, transform 0.3s ease;
  }

  .notif-list {
    transition: all 0.3s ease;
  }

  .notification-status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
  }

  .notification-status-order {
    background: #3b82f6;
    color: #ffffff;
  }

  .notification-status-return {
    background: #dc3545;
    color: #ffffff;
  }

  .notification-status-lowstock {
    background: #f97316;
    color: #ffffff;
  }

  .notification-status-processing {
    background: #10b981;
    color: #ffffff;
  }

  
  /* Confirmation dialog (match logout modal design) */
  .confirm-dialog {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
  }
  .confirm-content {
    background: #ffffff;
    border-radius: 12px;
    padding: 0;
    max-width: 420px;
    width: 90%;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    animation: confirmSlideIn 0.3s ease-out;
    overflow: hidden;
  }
  @keyframes confirmSlideIn { from { opacity:0; transform: translateY(-20px);} to { opacity:1; transform: translateY(0);} }
  .confirm-header { background:#130325; color:#ffffff; padding:16px 20px; display:flex; align-items:center; gap:10px; }
  .confirm-header h3 { margin:0; font-size:14px; font-weight:700; }
  .confirm-body { padding:20px; color:#130325; font-size:13px; line-height:1.5; }
  .confirm-footer { padding:16px 24px; border-top:1px solid #e5e7eb; display:flex; gap:10px; justify-content:flex-end; }
  .confirm-btn { padding:8px 20px; border-radius:6px; font-size:14px; font-weight:600; border:none; cursor:pointer; }
  .confirm-btn-cancel { background:#f3f4f6; color:#130325; border:1px solid #e5e7eb; }
  .confirm-btn-primary { background:#130325; color:#ffffff; }
</style>

<main style="background:#f8f9fa; min-height:100vh; padding: 0 0 60px 0;">
  <div style="max-width: 1400px; margin-left: -150px; margin-right: auto; margin-top: -15px; padding-left: 0; padding-right: 60px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
      <h1 style="color:#130325; margin:0; font-size: 28px; font-weight: 700;">Notifications <span style="background: #FFD736; color: #130325; padding: 4px 12px; border-radius: 20px; font-size: 18px; font-weight: 600; margin-left: 8px;"><?php echo count($notifications); ?> total</span> <span style="color:#6b7280; font-size:16px; font-weight:700; margin-left:8px;">(<?php echo (int)$unreadCount; ?> unread)</span></h1>
      <?php if (!empty($notifications)): ?>
        <button onclick="markAllAsRead()" style="background:#dc3545; color:#ffffff; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-weight:600;">
          Mark All as Read
        </button>
      <?php endif; ?>
    </div>

    <?php if (empty($notifications)): ?>
      <div style="background:#ffffff; border:1px solid rgba(0,0,0,0.1); color:#130325; border-radius:8px; padding:20px; text-align:center;">No notifications yet.</div>
    <?php else: ?>
      <div class="notif-list" style="display:flex; flex-direction:column; gap:12px;">
        <?php foreach ($notifications as $notification): ?>
              <div class="notif-item" style="position: relative; cursor:pointer;" onclick="markAndGo(<?php echo (int)$notification['id']; ?>, '<?php echo htmlspecialchars($notification['action_url'] ?: '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($notification['title'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($notification['message'], ENT_QUOTES); ?>')">
            <div style="display:flex; gap:12px; align-items:center; background:#ffffff; border:1px solid rgba(0,0,0,0.1); padding:14px; border-radius:10px; width:100%;">
              <span class="notification-status-badge <?php echo getNotificationBadgeClass($notification['title'], $notification['type']); ?>">
                <?php echo getNotificationBadgeText($notification['title'], $notification['type']); ?>
              </span>
              <div style="flex:1;">
                <div style="color:#130325; font-weight:700;"><?php echo htmlspecialchars($notification['title']); ?></div>
                <div style="color:#130325; opacity:0.9; font-size:0.9rem;">
                  <?php echo htmlspecialchars($notification['message']); ?>
                  <?php if ($notification['action_url']): ?>
                    <a href="<?php echo htmlspecialchars($notification['action_url']); ?>" onclick="event.preventDefault(); markAndGo(<?php echo (int)$notification['id']; ?>, '<?php echo htmlspecialchars($notification['action_url'] ?: '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($notification['title'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($notification['message'], ENT_QUOTES); ?>')" style="color:#130325; text-decoration:underline; margin-left:8px;">View Details</a>
                  <?php endif; ?>
                </div>
              </div>
              <div style="color:#6b7280; font-size:0.8rem; white-space:nowrap; margin-right: 5px;">
                <?php echo htmlspecialchars(date('M d, h:i A', strtotime($notification['created_at']))); ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</main>

<script>
function markAndGo(notificationId, actionUrl, title, message){
  // Mark as read
  try {
    navigator.sendBeacon('ajax/mark-seller-notification-read.php', new Blob([JSON.stringify({ notification_id: notificationId })], { type:'application/json' }));
  } catch(_){
    fetch('ajax/mark-seller-notification-read.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ notification_id: notificationId }), credentials:'same-origin', keepalive:true }).catch(()=>{});
  }
  // Infer fallback URL if missing
  let href = actionUrl;
  if (!href) {
    const text = (title||'') + ' ' + (message||'');
    const m = text.match(/Order\s*#?(\d{1,10})/i);
    if (m && m[1]) href = 'seller-order-details.php?order_id=' + m[1];
    else if (/low stock/i.test(text)) href = 'manage-products.php';
    else href = 'view-orders.php';
  }
  // Navigate
  setTimeout(()=>{ window.location.href = href; }, 120);
}
// Custom styled confirmation dialog function
function openConfirm(message, onConfirm) {
    const dialog = document.createElement('div');
    dialog.className = 'confirm-dialog';
    dialog.innerHTML = `
      <div class="confirm-content">
        <div class="confirm-header">
          <i class="fas fa-bell" style="font-size:16px; color:#FFD736;"></i>
          <h3>Confirm Action</h3>
        </div>
        <div class="confirm-body">${message}</div>
        <div class="confirm-footer">
          <button class="confirm-btn confirm-btn-cancel">Cancel</button>
          <button class="confirm-btn confirm-btn-primary">Confirm</button>
        </div>
      </div>`;
    document.body.appendChild(dialog);
    const onClose = () => { if (dialog && dialog.parentNode) dialog.parentNode.removeChild(dialog); };
    dialog.addEventListener('click', (e)=>{ if (e.target === dialog) onClose(); });
    const esc = (e)=>{ if (e.key === 'Escape') { onClose(); document.removeEventListener('keydown', esc); } };
    document.addEventListener('keydown', esc);
    dialog.querySelector('.confirm-btn-cancel').addEventListener('click', onClose);
    dialog.querySelector('.confirm-btn-primary').addEventListener('click', ()=>{ onClose(); if (onConfirm) onConfirm(); });
}

function deleteSellerNotification(notificationId, buttonElement) {
    openConfirm('Are you sure you want to delete this notification? This action cannot be undone.', function() {
        // Hide the notification item immediately with animation
        const notifItem = buttonElement.closest('.notif-item');
        if (notifItem) {
            notifItem.style.opacity = '0';
            notifItem.style.transform = 'translateX(-20px)';
            setTimeout(() => {
                notifItem.style.display = 'none';
            }, 300);
        }
        
        // Call API to delete
        fetch('ajax/delete-seller-notification.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ notification_id: notificationId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Remove the item from DOM
                if (notifItem) {
                    notifItem.remove();
                }
                // Update the count in the header
                const header = document.querySelector('h1');
                if (header) {
                    const notifList = document.querySelector('.notif-list');
                    const remainingCount = notifList ? notifList.querySelectorAll('.notif-item').length : 0;
                    header.textContent = `Seller Notifications (${remainingCount} total)`;
                }
            } else {
                // If deletion failed, show the item again
                if (notifItem) {
                    notifItem.style.opacity = '1';
                    notifItem.style.transform = 'translateX(0)';
                    notifItem.style.display = 'block';
                }
                alert('Failed to delete notification: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error deleting notification:', error);
            // If deletion failed, show the item again
            if (notifItem) {
                notifItem.style.opacity = '1';
                notifItem.style.transform = 'translateX(0)';
                notifItem.style.display = 'block';
            }
            alert('Error deleting notification. Please try again.');
        });
    });
}

function markAsRead(notificationId) {
    fetch('ajax/mark-seller-notification-read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ notification_id: notificationId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Reload page to update the display
            location.reload();
        }
    })
    .catch(error => {
        console.error('Error marking notification as read:', error);
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
                // Reload page to update the display
                location.reload();
            }
        })
        .catch(error => {
            console.error('Error marking all notifications as read:', error);
        });
    });
}

function getNotificationIcon(type) {
    switch(type) {
        case 'warning': return 'exclamation-triangle';
        case 'success': return 'check-circle';
        case 'error': return 'times-circle';
        default: return 'info-circle';
    }
}

function getNotificationColor(type) {
    switch(type) {
        case 'warning': return 'text-yellow-400';
        case 'success': return 'text-green-400';
        case 'error': return 'text-red-400';
        default: return 'text-blue-400';
    }
}

function formatTime(timestamp) {
    const date = new Date(timestamp);
    const now = new Date();
    const diff = now - date;
    
    if (diff < 60000) return 'Just now';
    if (diff < 3600000) return Math.floor(diff / 60000) + 'm ago';
    if (diff < 86400000) return Math.floor(diff / 3600000) + 'h ago';
    return Math.floor(diff / 86400000) + 'd ago';
}
</script>

<?php
// Helper functions
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
    
    return 'notification-status-order'; // Default fallback
}

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
    
    return 'Order'; // Default fallback
}

function getNotificationIcon($type) {
    switch($type) {
        case 'warning': return 'exclamation-triangle';
        case 'success': return 'check-circle';
        case 'error': return 'times-circle';
        default: return 'info-circle';
    }
}

function getNotificationColor($type) {
    switch($type) {
        case 'warning': return 'text-yellow-400';
        case 'success': return 'text-green-400';
        case 'error': return 'text-red-400';
        default: return 'text-blue-400';
    }
}

function formatTime($timestamp) {
    $date = new DateTime($timestamp);
    $now = new DateTime();
    $diff = $now->diff($date);
    
    if ($diff->days > 0) {
        return $diff->days . 'd ago';
    } elseif ($diff->h > 0) {
        return $diff->h . 'h ago';
    } elseif ($diff->i > 0) {
        return $diff->i . 'm ago';
    } else {
        return 'Just now';
    }
}
?>

