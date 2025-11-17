<?php
require_once '../includes/functions.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!isLoggedIn() || !isSeller()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$reviewId = isset($input['review_id']) ? (int)$input['review_id'] : 0;

if ($reviewId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid review ID']);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    // Verify the review belongs to seller's product
    $stmt = $pdo->prepare("
        SELECT r.id 
        FROM reviews r
        INNER JOIN products p ON r.product_id = p.id
        WHERE r.id = ? AND p.seller_id = ?
        LIMIT 1
    ");
    $stmt->execute([$reviewId, $userId]);
    
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Review not found']);
        exit;
    }
    
    // Mark as read
    $stmt = $pdo->prepare("
        UPDATE reviews 
        SET is_read_by_seller = 1 
        WHERE id = ?
    ");
    $stmt->execute([$reviewId]);
    
    // Get new unread count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_count
        FROM reviews r
        INNER JOIN products p ON r.product_id = p.id
        WHERE p.seller_id = ? 
        AND r.is_read_by_seller = 0
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $newCount = (int)($result['unread_count'] ?? 0);
    
    echo json_encode([
        'success' => true,
        'newCount' => $newCount
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
