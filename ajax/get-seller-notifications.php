<?php
// ajax/get-seller-notifications.php
// This file fetches seller notifications and returns them as JSON

session_start();
header('Content-Type: application/json');

// Check if user is logged in and is a seller
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'seller') {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access',
        'notifications' => [],
        'unreadCount' => 0
    ]);
    exit;
}

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/seller_notification_functions.php';
    
    $sellerId = $_SESSION['user_id'];
    
    // Get notifications (limit to 20 most recent for dropdown)
    $notifications = getSellerNotifications($sellerId, 20);
    
    // Get unread count
    $unreadCount = getSellerUnreadCount($sellerId);
    
    // Format notifications for JSON response
    $formattedNotifications = [];
    foreach ($notifications as $notification) {
        $formattedNotifications[] = [
            'id' => (int)$notification['id'],
            'title' => htmlspecialchars($notification['title'] ?? 'Notification'),
            'message' => htmlspecialchars($notification['message'] ?? ''),
            'type' => htmlspecialchars($notification['type'] ?? 'info'),
            'action_url' => htmlspecialchars($notification['action_url'] ?? ''),
            'is_read' => (bool)$notification['is_read'],
            'created_at' => $notification['created_at']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'notifications' => $formattedNotifications,
        'unreadCount' => (int)$unreadCount,
        'timestamp' => time()
    ]);
    
} catch (Exception $e) {
    error_log('Error fetching seller notifications: ' . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching notifications',
        'notifications' => [],
        'unreadCount' => 0,
        'error' => $e->getMessage()
    ]);
}
