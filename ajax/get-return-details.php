<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in as seller
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'seller') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$sellerId = $_SESSION['user_id'];
$returnId = isset($_GET['return_id']) ? (int)$_GET['return_id'] : 0;

if (!$returnId) {
    echo json_encode(['success' => false, 'message' => 'Invalid return ID']);
    exit;
}

try {
    // Get return request details
    $stmt = $pdo->prepare("
        SELECT 
            rr.*,
            o.id as order_id,
            o.total_amount,
            o.created_at as order_date,
            u.username as customer_name,
            u.email as customer_email,
            p.name as product_name,
            p.image_url as product_image,
            oi.quantity,
            oi.price as item_price
        FROM return_requests rr
        JOIN orders o ON rr.order_id = o.id
        JOIN users u ON o.user_id = u.id
        JOIN order_items oi ON o.id = oi.order_id AND rr.product_id = oi.product_id
        JOIN products p ON oi.product_id = p.id
        WHERE rr.id = ? AND rr.seller_id = ?
    ");
    $stmt->execute([$returnId, $sellerId]);
    $returnRequest = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$returnRequest) {
        echo json_encode(['success' => false, 'message' => 'Return request not found']);
        exit;
    }
    
    echo json_encode(['success' => true, 'return_request' => $returnRequest]);
    
} catch (Exception $e) {
    error_log("Error fetching return details: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error fetching details']);
}
?>
