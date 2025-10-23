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

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$notificationId = $input['notification_id'] ?? null;

if (!$notificationId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Notification ID required']);
    exit();
}

$sellerId = $_SESSION['user_id'];

try {
    $success = markSellerNotificationAsRead($notificationId, $sellerId);
    
    if ($success) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to mark notification as read']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
