<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isSeller()) {
    echo json_encode(['success' => false, 'count' => 0]);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    // Count unread reviews for seller's products
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM reviews r
        INNER JOIN products p ON r.product_id = p.id
        WHERE p.seller_id = ?
        AND r.is_read_by_seller = 0
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $count = (int)($result['count'] ?? 0);
    
    echo json_encode([
        'success' => true,
        'count' => $count
    ]);
} catch (Exception $e) {
    error_log("Get review notification count error: " . $e->getMessage());
    echo json_encode(['success' => false, 'count' => 0]);
}
