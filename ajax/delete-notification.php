<?php
// ajax/delete-notification.php
// Turn off error display for JSON requests
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 0);

// Clear any previous output
while (ob_get_level()) {
    ob_end_clean();
}

session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Set JSON header
header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit;
}

// Check if user is logged in
if (!isLoggedIn()) {
    $response['message'] = 'Please log in to delete notifications';
    echo json_encode($response);
    exit;
}

// Validate input
$orderId = isset($input['order_id']) ? (int)$input['order_id'] : 0;
$isCustom = isset($input['is_custom']) ? (bool)$input['is_custom'] : false;
$userId = $_SESSION['user_id'];

if ($orderId <= 0) {
    $response['message'] = 'Invalid order ID';
    echo json_encode($response);
    exit;
}

try {
    if ($isCustom) {
        // Delete custom notification from the notifications table
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND order_id = ?");
        $result = $stmt->execute([$userId, $orderId]);
        
        if ($result) {
            $response['success'] = true;
            $response['message'] = 'Notification deleted successfully';
        } else {
            $response['message'] = 'Failed to delete notification';
        }
    } else {
        // For order status updates, create a "hidden_notifications" table to track which to hide
        $pdo->exec("CREATE TABLE IF NOT EXISTS hidden_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            order_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_order (user_id, order_id)
        )");
        
        // Insert into hidden_notifications to mark this order update as hidden
        $stmt = $pdo->prepare("INSERT IGNORE INTO hidden_notifications (user_id, order_id) VALUES (?, ?)");
        $result = $stmt->execute([$userId, $orderId]);
        
        if ($result) {
            $response['success'] = true;
            $response['message'] = 'Notification deleted successfully';
        } else {
            $response['message'] = 'Failed to delete notification';
        }
    }
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
?>
