<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$userId = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$orderId = isset($data['order_id']) ? intval($data['order_id']) : 0;

if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit();
}

try {
    // Automatically update database ENUM to include 'completed' if needed
    try {
        $pdo->exec("ALTER TABLE `orders` MODIFY COLUMN `status` ENUM('pending','processing','shipped','delivered','completed','cancelled') DEFAULT 'pending'");
        error_log("Successfully updated orders.status ENUM to include 'completed'");
    } catch (Exception $e) {
        // ENUM might already be updated or error occurred, continue anyway
        error_log("Note: orders.status ENUM update attempt: " . $e->getMessage());
    }
    
    $pdo->beginTransaction();
    
    // Verify order belongs to user and is in 'delivered' status
    $stmt = $pdo->prepare("SELECT o.*, oi.product_id, p.seller_id 
                           FROM orders o 
                           JOIN order_items oi ON o.id = oi.order_id 
                           JOIN products p ON oi.product_id = p.id 
                           WHERE o.id = ? AND o.user_id = ? AND o.status = 'delivered'
                           GROUP BY o.id");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Order not found or cannot be confirmed']);
        exit();
    }
    
    // Update order status to 'completed' and set delivery_date if not set
    $stmt = $pdo->prepare("UPDATE orders SET status = 'completed', delivery_date = COALESCE(delivery_date, NOW()), updated_at = NOW() WHERE id = ?");
    $stmt->execute([$orderId]);
    
    // Verify the update actually worked
    $verifyStmt = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
    $verifyStmt->execute([$orderId]);
    $actualStatus = $verifyStmt->fetchColumn();
    
    if ($actualStatus !== 'completed') {
        $pdo->rollBack();
        error_log("ERROR: Order status update to 'completed' failed. Database ENUM may not include 'completed'. Actual status: " . $actualStatus);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update order status. Please ensure the database ENUM includes "completed" status. Run the SQL: ALTER TABLE `orders` MODIFY COLUMN `status` ENUM(\'pending\',\'processing\',\'shipped\',\'delivered\',\'completed\',\'cancelled\') DEFAULT \'pending\';'
        ]);
        exit();
    }
    
    // Log status change
    try {
        $stmt = $pdo->prepare("INSERT INTO order_status_history (order_id, status, notes, updated_by, created_at) 
                              VALUES (?, 'completed', 'Order confirmed received by customer', ?, NOW())");
        $stmt->execute([$orderId, $userId]);
    } catch (Exception $e) {
        error_log("Failed to log status history: " . $e->getMessage());
    }
    
    // Create customer notification
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, order_id, message, type, created_at) 
                              VALUES (?, ?, ?, 'info', NOW())");
        $message = "✅ Order #" . str_pad($orderId, 6, '0', STR_PAD_LEFT) . " has been completed! Thank you for your purchase.";
        $stmt->execute([$userId, $orderId, $message]);
    } catch (Exception $e) {
        error_log("Failed to create customer notification: " . $e->getMessage());
    }
    
    // Get seller ID from order items
    $stmt = $pdo->prepare("SELECT DISTINCT p.seller_id 
                           FROM order_items oi 
                           JOIN products p ON oi.product_id = p.id 
                           WHERE oi.order_id = ?");
    $stmt->execute([$orderId]);
    $sellers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Create seller notification for each seller
    if (file_exists('../includes/seller_notification_functions.php')) {
        require_once '../includes/seller_notification_functions.php';
        
        foreach ($sellers as $sellerId) {
            if ($sellerId) {
                createSellerNotification(
                    $sellerId,
                    'Order Completed',
                    '✅ Order #' . str_pad($orderId, 6, '0', STR_PAD_LEFT) . ' has been completed by customer',
                    'COMPLETE',
                    'seller-order-details.php?order_id=' . $orderId
                );
            }
        }
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Order confirmed as received!',
        'order_id' => $orderId
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Error confirming order received: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error processing request']);
}

