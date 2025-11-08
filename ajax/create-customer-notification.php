<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Only sellers should be able to create customer notifications
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'seller') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $orderId = $data['order_id'] ?? null;
    $newStatus = $data['status'] ?? null;
    
    if (!$orderId || !$newStatus) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit();
    }
    
    // Get the customer user_id from the order
    $stmt = $pdo->prepare("SELECT user_id FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit();
    }
    
    $customerId = $order['user_id'];
    
    // Create notification message based on status
    $statusMessages = [
        'pending' => 'Your order has been received and is pending processing.',
        'processing' => 'Your order is now being processed.',
        'shipped' => 'Great news! Your order has been shipped.',
        'delivered' => 'Your order has been delivered. Thank you for your purchase!',
        'cancelled' => 'Your order has been cancelled.',
        'refunded' => 'Your order has been refunded.'
    ];
    
    $message = $statusMessages[$newStatus] ?? "Your order status has been updated to: $newStatus";
    
    // Insert notification into notifications table
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, order_id, message, type, created_at) 
                          VALUES (?, ?, ?, 'info', NOW())");
    $stmt->execute([$customerId, $orderId, $message]);
    
    echo json_encode(['success' => true, 'message' => 'Customer notification created']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
