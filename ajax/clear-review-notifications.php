<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in and is a seller
if (!isLoggedIn() || !isSeller()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    // Mark all review-related notifications as read for this seller
    $stmt = $pdo->prepare("
        UPDATE seller_notifications 
        SET is_read = 1 
        WHERE seller_id = ? 
        AND (
            title LIKE '%review%' 
            OR title LIKE '%rating%'
            OR action_url LIKE '%seller-reviews.php%'
            OR action_url LIKE '%review%'
        )
    ");
    $stmt->execute([$userId]);
    
    // Also mark all reviews as read by seller
    $stmt = $pdo->prepare("
        UPDATE reviews r
        INNER JOIN products p ON r.product_id = p.id
        SET r.is_read_by_seller = 1
        WHERE p.seller_id = ?
    ");
    $stmt->execute([$userId]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log("Clear review notifications error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
