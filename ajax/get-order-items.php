<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Check if user is a customer (not admin or seller)
if (isset($_SESSION['user_type']) && in_array($_SESSION['user_type'], ['admin', 'seller'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$customerId = $_SESSION['user_id'];
$orderId = (int)($_GET['order_id'] ?? 0);

if (!$orderId) {
    echo json_encode(['success' => false, 'message' => 'Order ID required']);
    exit();
}

try {
    // Get order items for the specified order
    $stmt = $pdo->prepare("SELECT oi.product_id, oi.quantity, p.name as product_name, p.price
                          FROM order_items oi
                          JOIN products p ON oi.product_id = p.id
                          JOIN orders o ON oi.order_id = o.id
                          WHERE oi.order_id = ? AND o.customer_id = ?
                          ORDER BY p.name");
    $stmt->execute([$orderId, $customerId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'items' => $items]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error loading order items']);
}
?>
