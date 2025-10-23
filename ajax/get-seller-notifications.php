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
    // Get unread count
    $unreadCount = getSellerUnreadCount($sellerId);
    
    // Get recent notifications
    $notifications = getSellerNotifications($sellerId, 10);
    
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
