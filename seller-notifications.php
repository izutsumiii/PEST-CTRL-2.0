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

// Apply dark purple theme
echo '<style>
body { background: #130325 !important; color: #F9F9F9 !important; }
.container { background: transparent !important; }
.max-w-4xl { max-width: 56rem !important; }
.mx-auto { margin-left: auto !important; margin-right: auto !important; }
.px-4 { padding-left: 1rem !important; padding-right: 1rem !important; }
.py-8 { padding-top: 2rem !important; padding-bottom: 2rem !important; }
.flex { display: flex !important; }
.justify-between { justify-content: space-between !important; }
.items-center { align-items: center !important; }
.mb-8 { margin-bottom: 2rem !important; }
.text-3xl { font-size: 1.875rem !important; line-height: 2.25rem !important; }
.font-bold { font-weight: 700 !important; }
.text-white { color: #F9F9F9 !important; }
.mb-2 { margin-bottom: 0.5rem !important; }
.text-gray-300 { color: rgba(249, 249, 249, 0.7) !important; }
.text-right { text-align: right !important; }
.text-2xl { font-size: 1.5rem !important; line-height: 2rem !important; }
.text-yellow-400 { color: #FFD736 !important; }
.text-sm { font-size: 0.875rem !important; line-height: 1.25rem !important; }
.bg-white { background-color: rgba(255, 255, 255, 0.1) !important; }
.bg-opacity-10 { background-color: rgba(255, 255, 255, 0.1) !important; }
.rounded-lg { border-radius: 0.5rem !important; }
.shadow-lg { box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3) !important; }
.backdrop-filter { backdrop-filter: blur(10px) !important; }
.backdrop-blur-lg { backdrop-filter: blur(16px) !important; }
.p-6 { padding: 1.5rem !important; }
.text-center { text-align: center !important; }
.py-12 { padding-top: 3rem !important; padding-bottom: 3rem !important; }
.fas { font-family: "Font Awesome 5 Free" !important; font-weight: 900 !important; }
.text-6xl { font-size: 3.75rem !important; line-height: 1 !important; }
.text-gray-400 { color: rgba(249, 249, 249, 0.5) !important; }
.mb-4 { margin-bottom: 1rem !important; }
.text-xl { font-size: 1.25rem !important; line-height: 1.75rem !important; }
.font-semibold { font-weight: 600 !important; }
.space-y-4 > * + * { margin-top: 1rem !important; }
.bg-opacity-5 { background-color: rgba(255, 255, 255, 0.05) !important; }
.p-4 { padding: 1rem !important; }
.border-l-4 { border-left-width: 4px !important; }
.border-yellow-400 { border-color: #FFD736 !important; }
.bg-yellow-400 { background-color: rgba(255, 215, 54, 0.1) !important; }
.bg-opacity-10 { background-color: rgba(255, 255, 255, 0.1) !important; }
.border-gray-600 { border-color: rgba(249, 249, 249, 0.3) !important; }
.hover\\:bg-opacity-10:hover { background-color: rgba(255, 255, 255, 0.1) !important; }
.transition-all { transition-property: all !important; transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1) !important; transition-duration: 150ms !important; }
.duration-200 { transition-duration: 200ms !important; }
.cursor-pointer { cursor: pointer !important; }
.items-start { align-items: flex-start !important; }
.space-x-4 > * + * { margin-left: 1rem !important; }
.flex-shrink-0 { flex-shrink: 0 !important; }
.text-lg { font-size: 1.125rem !important; line-height: 1.75rem !important; }
.flex-1 { flex: 1 1 0% !important; }
.min-w-0 { min-width: 0px !important; }
.justify-between { justify-content: space-between !important; }
.inline-flex { display: inline-flex !important; }
.items-center { align-items: center !important; }
.px-2 { padding-left: 0.5rem !important; padding-right: 0.5rem !important; }
.py-1 { padding-top: 0.25rem !important; padding-bottom: 0.25rem !important; }
.rounded-full { border-radius: 9999px !important; }
.text-xs { font-size: 0.75rem !important; line-height: 1rem !important; }
.font-medium { font-weight: 500 !important; }
.bg-yellow-400 { background-color: #FFD736 !important; }
.text-yellow-900 { color: #130325 !important; }
.mt-1 { margin-top: 0.25rem !important; }
.mt-2 { margin-top: 0.5rem !important; }
.hover\\:text-yellow-300:hover { color: #e6c230 !important; }
.ml-1 { margin-left: 0.25rem !important; }
.mt-8 { margin-top: 2rem !important; }
.justify-center { justify-content: center !important; }
.space-x-4 > * + * { margin-left: 1rem !important; }
.bg-yellow-400 { background-color: #FFD736 !important; }
.hover\\:bg-yellow-500:hover { background-color: #e6c230 !important; }
.text-yellow-900 { color: #130325 !important; }
.py-2 { padding-top: 0.5rem !important; padding-bottom: 0.5rem !important; }
.px-6 { padding-left: 1.5rem !important; padding-right: 1.5rem !important; }
.transition-colors { transition-property: color, background-color, border-color, text-decoration-color, fill, stroke !important; transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1) !important; transition-duration: 150ms !important; }
.duration-200 { transition-duration: 200ms !important; }
.bg-gray-600 { background-color: rgba(249, 249, 249, 0.2) !important; }
.hover\\:bg-gray-700:hover { background-color: rgba(249, 249, 249, 0.3) !important; }
.mr-2 { margin-right: 0.5rem !important; }
</style>';
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold text-white mb-2">Seller Notifications</h1>
            </div>
            <div class="text-right">
                <div class="text-2xl font-bold text-yellow-400"><?php echo $unreadCount; ?></div>
                <div class="text-sm text-gray-300">Unread</div>
            </div>
        </div>

        <!-- Notifications List -->
        <div class="bg-white bg-opacity-10 rounded-lg shadow-lg backdrop-filter backdrop-blur-lg">
            <div class="p-6">
                <?php if (empty($notifications)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-bell-slash text-6xl text-gray-400 mb-4"></i>
                        <h3 class="text-xl font-semibold text-white mb-2">No Notifications</h3>
                        <p class="text-gray-300">You don't have any notifications yet.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($notifications as $notification): ?>
                            <div class="notification-item bg-white bg-opacity-5 rounded-lg p-4 border-l-4 <?php echo !$notification['is_read'] ? 'border-yellow-400 bg-yellow-400 bg-opacity-10' : 'border-gray-600'; ?> hover:bg-opacity-10 transition-all duration-200 cursor-pointer" 
                                 onclick="markAsRead(<?php echo $notification['id']; ?>)">
                                <div class="flex items-start space-x-4">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-<?php echo getNotificationIcon($notification['type']); ?> text-lg <?php echo getNotificationColor($notification['type']); ?>"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <h4 class="text-lg font-semibold text-white"><?php echo htmlspecialchars($notification['title']); ?></h4>
                                            <div class="flex items-center space-x-2">
                                                <?php if (!$notification['is_read']): ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-400 text-yellow-900">New</span>
                                                <?php endif; ?>
                                                <span class="text-sm text-gray-400"><?php echo formatTime($notification['created_at']); ?></span>
                                            </div>
                                        </div>
                                        <p class="text-gray-300 mt-1"><?php echo htmlspecialchars($notification['message']); ?></p>
                                        <?php if ($notification['action_url']): ?>
                                            <a href="<?php echo htmlspecialchars($notification['action_url']); ?>" 
                                               class="inline-flex items-center mt-2 text-yellow-400 hover:text-yellow-300 text-sm font-medium">
                                                View Details <i class="fas fa-arrow-right ml-1"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Actions -->
        <div class="mt-8 flex justify-center space-x-4">
            <button onclick="markAllAsRead()" class="bg-yellow-400 hover:bg-yellow-500 text-yellow-900 font-semibold py-2 px-6 rounded-lg transition-colors duration-200">
                <i class="fas fa-check-double mr-2"></i>Mark All as Read
            </button>
            <a href="seller-dashboard.php" class="bg-gray-600 hover:bg-gray-700 text-white font-semibold py-2 px-6 rounded-lg transition-colors duration-200">
                <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
            </a>
        </div>
    </div>
</div>

<script>
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
