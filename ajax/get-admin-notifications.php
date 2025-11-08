<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/admin_notification_functions.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$adminId = $_SESSION['user_id'];

try {
    // Get unread count
    $unreadCount = getAdminUnreadCount($adminId);
    
    // Get recent notifications
    $notifications = getAdminNotifications($adminId, 10);
    
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
