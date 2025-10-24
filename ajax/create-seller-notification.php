<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/seller_notification_functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Handle new order notifications
    if (isset($data['seller_id']) && isset($data['order_id']) && isset($data['customer_name']) && isset($data['product_name'])) {
        createNewOrderNotification(
            $data['seller_id'],
            $data['order_id'],
            $data['customer_name'],
            $data['product_name']
        );
        
        echo json_encode(['success' => true]);
    } 
    // Handle return request notifications
    elseif (isset($data['seller_id']) && isset($data['return_id']) && isset($data['order_id']) && isset($data['product_name'])) {
        createReturnRequestNotification(
            $data['seller_id'],
            $data['return_id'],
            $data['order_id'],
            $data['product_name']
        );
        
        echo json_encode(['success' => true]);
    } 
    else {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
