<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/seller_notification_functions.php';

// Check if user is logged in and is a seller
if (!isLoggedIn() || !isSeller()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$sellerId = $_SESSION['user_id'];

try {
    $unreadNotifications = getSellerUnreadNotifications($sellerId, 10);
    $unreadCount = count($unreadNotifications);

    // Normalize is_read values to boolean for consistent JavaScript handling
    $normalizeNotification = function($notif) {
        // Convert is_read to boolean (handle string "0"/"1", integer 0/1, boolean false/true)
        $notif['is_read'] = (bool)($notif['is_read'] ?? false);
        // Ensure all required fields exist
        $notif['id'] = $notif['id'] ?? 0;
        $notif['title'] = $notif['title'] ?? '';
        $notif['message'] = $notif['message'] ?? '';
        $notif['type'] = $notif['type'] ?? 'info';
        $notif['action_url'] = $notif['action_url'] ?? null;
        $notif['created_at'] = $notif['created_at'] ?? date('Y-m-d H:i:s');
        return $notif;
    };

    // Normalize unread notifications
    $unreadNotifications = array_map($normalizeNotification, $unreadNotifications);

    // If less than 10 unread, fill with recently read notifications
    if ($unreadCount < 10) {
        $extraNeeded = 10 - $unreadCount;
        $recentRead = [];
        if ($extraNeeded > 0) {
            $recentRead = getSellerNotifications($sellerId, 10 + $extraNeeded);
            // Remove duplicates (skip unread), keep only "read"
            $recentRead = array_filter($recentRead, function($notif) {
                // Handle string "0"/"1", integer 0/1, boolean false/true
                $isRead = $notif['is_read'] ?? false;
                return (bool)$isRead;
            });
            $recentRead = array_slice($recentRead, 0, $extraNeeded);
            // Normalize read notifications
            $recentRead = array_map($normalizeNotification, $recentRead);
        }
        $notifications = array_merge($unreadNotifications, $recentRead);
    } else {
        $notifications = $unreadNotifications;
    }

    echo json_encode([
        'success' => true,
        'unreadCount' => $unreadCount,
        'notifications' => $notifications
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error loading notifications: ' . $e->getMessage()
    ]);
}
?>
