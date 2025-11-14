<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$productId = isset($input['product_id']) ? (int)$input['product_id'] : 0;

if ($productId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
    exit();
}

try {
    $userId = $_SESSION['user_id'];
    
    // Remove item from cart
    $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$userId, $productId]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Item removed from cart']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Item not found in cart']);
    }
} catch (Exception $e) {
    error_log('Remove from cart error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error removing item']);
}
?>

