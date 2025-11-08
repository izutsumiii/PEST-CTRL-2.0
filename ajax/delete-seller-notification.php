<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/seller_notification_functions.php';

// Turn off error display for JSON requests
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 0);

// Clear any previous output
while (ob_get_level()) {
    ob_end_clean();
}

session_start();

// Set JSON header
header('Content-Type: application/json');

// Check if user is logged in and is a seller
if (!isLoggedIn() || !isSeller()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$notificationId = isset($input['notification_id']) ? (int)$input['notification_id'] : 0;
$sellerId = $_SESSION['user_id'];

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit;
}

if ($notificationId <= 0) {
    $response['message'] = 'Invalid notification ID';
    echo json_encode($response);
    exit;
}

try {
    // Verify the notification belongs to this seller before deleting
    $stmt = $pdo->prepare("SELECT id FROM seller_notifications WHERE id = ? AND seller_id = ?");
    $stmt->execute([$notificationId, $sellerId]);
    $notification = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$notification) {
        $response['message'] = 'Notification not found or access denied';
        echo json_encode($response);
        exit;
    }
    
    // Delete the notification
    $stmt = $pdo->prepare("DELETE FROM seller_notifications WHERE id = ? AND seller_id = ?");
    $result = $stmt->execute([$notificationId, $sellerId]);
    
    if ($result) {
        $response['success'] = true;
        $response['message'] = 'Notification deleted successfully';
    } else {
        $response['message'] = 'Failed to delete notification';
    }
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
?>

