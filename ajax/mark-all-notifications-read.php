<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

require_once '../config/database.php';

$data = json_decode(file_get_contents('php://input'), true);
$orderId = isset($data['order_id']) ? (int)$data['order_id'] : 0;
$isCustom = isset($data['is_custom']) ? (bool)$data['is_custom'] : false;
$userId = $_SESSION['user_id'];

if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit;
}

try {
    $notificationType = $isCustom ? 'custom' : 'order_update';
    
    $stmt = $pdo->prepare("INSERT INTO notification_reads (user_id, order_id, notification_type) 
                           VALUES (?, ?, ?) 
                           ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP");
    $stmt->execute([$userId, $orderId, $notificationType]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
