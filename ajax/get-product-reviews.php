<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in as seller or admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['seller', 'admin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;

if (!$productId) {
    echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
    exit;
}

try {
    // If seller, verify they own this product
    if ($_SESSION['user_type'] === 'seller') {
        $checkStmt = $pdo->prepare("SELECT id FROM products WHERE id = ? AND seller_id = ?");
        $checkStmt->execute([$productId, $_SESSION['user_id']]);
        if (!$checkStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'You can only view reviews for your own products']);
            exit;
        }
    }
    
    // Get reviews for this product
    $stmt = $pdo->prepare("
        SELECT 
            pr.id as review_id,
            pr.product_id,
            pr.user_id,
            pr.rating,
            pr.review_text,
            pr.seller_reply,
            pr.seller_replied_at,
            pr.is_hidden,
            pr.hidden_reason,
            pr.created_at,
            u.username,
            u.first_name,
            u.last_name
        FROM product_reviews pr
        JOIN users u ON pr.user_id = u.id
        WHERE pr.product_id = ?
        ORDER BY pr.created_at DESC
    ");
    $stmt->execute([$productId]);
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format reviews
    $formattedReviews = array_map(function($review) use ($productId) {
        return [
            'id' => (int)$review['review_id'],
            'product_id' => $productId,
            'user_id' => $review['user_id'],
            'username' => $review['username'],
            'customer_name' => trim($review['first_name'] . ' ' . $review['last_name']) ?: $review['username'],
            'rating' => (int)$review['rating'],
            'review_text' => $review['review_text'],
            'seller_reply' => $review['seller_reply'],
            'seller_replied_at' => $review['seller_replied_at'],
            'is_hidden' => (bool)$review['is_hidden'],
            'hidden_reason' => $review['hidden_reason'],
            'created_at' => $review['created_at'],
            'created_at_formatted' => date('F j, Y', strtotime($review['created_at'])),
            'created_at_time' => date('g:i A', strtotime($review['created_at']))
        ];
    }, $reviews);
    
    echo json_encode([
        'success' => true,
        'reviews' => $formattedReviews,
        'total' => count($formattedReviews)
    ]);
    
} catch (Exception $e) {
    error_log("Error in get-product-reviews.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
?>

