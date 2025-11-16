<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

require_once '../config/database.php';

// Handle both JSON (from fetch) and FormData (from sendBeacon)
$orderId = 0;
$notificationId = 0;
$isCustom = false;
$notificationType = null;

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    // JSON request
    $data = json_decode(file_get_contents('php://input'), true);
    $orderId = isset($data['order_id']) ? (int)$data['order_id'] : 0;
    $notificationId = isset($data['notification_id']) ? (int)$data['notification_id'] : 0;
    $isCustom = isset($data['is_custom']) ? (bool)$data['is_custom'] : false;
    $notificationType = isset($data['notification_type']) ? $data['notification_type'] : null;
} else {
    // FormData request (from sendBeacon)
    $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
    $notificationId = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
    $isCustom = isset($_POST['is_custom']) && $_POST['is_custom'] === '1';
    $notificationType = isset($_POST['notification_type']) ? $_POST['notification_type'] : null;
}

$userId = $_SESSION['user_id'];

// Need either order_id or notification_id
if ($orderId <= 0 && $notificationId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID or notification ID']);
    exit;
}

try {
    // First, ensure the notification_reads table has the correct structure
    $pdo->exec("CREATE TABLE IF NOT EXISTS notification_reads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        order_id INT NULL,
        notification_id INT NULL,
        notification_type VARCHAR(50) NULL,
        read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_order (user_id, order_id),
        INDEX idx_user_notification (user_id, notification_id)
    )");
    
    if ($notificationId > 0) {
        // Mark notification as read (product notifications, seller_reply, general notifications)
        // Use the provided notification_type or default to 'standalone'
        $typeToUse = $notificationType ?: 'standalone';
        
        $stmt = $pdo->prepare("INSERT INTO notification_reads (user_id, notification_id, notification_type, read_at) 
                               VALUES (?, ?, ?, CURRENT_TIMESTAMP) 
                               ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP");
        $stmt->execute([$userId, $notificationId, $typeToUse]);
    } elseif ($orderId > 0) {
        // Mark order notification as read
        $notificationType = $isCustom ? 'custom' : 'order_update';
        
        $stmt = $pdo->prepare("INSERT INTO notification_reads (user_id, order_id, notification_type, read_at) 
                               VALUES (?, ?, ?, CURRENT_TIMESTAMP) 
                               ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP");
        $stmt->execute([$userId, $orderId, $notificationType]);
    }
    
    echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
} catch (Exception $e) {
    error_log("Mark notification read error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
