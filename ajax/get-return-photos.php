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
    // Verify the return request belongs to this seller
    $stmt = $pdo->prepare("SELECT id FROM return_requests WHERE id = ? AND seller_id = ?");
    $stmt->execute([$returnId, $sellerId]);
    
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Return request not found']);
        exit;
    }
    
    // Get photos from the uploads directory
    $uploadDir = '../uploads/returns/' . $returnId . '/';
    $photos = [];
    
    if (is_dir($uploadDir)) {
        $files = scandir($uploadDir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..' && preg_match('/\.(jpg|jpeg|png|gif)$/i', $file)) {
                $photos[] = 'uploads/returns/' . $returnId . '/' . $file;
            }
        }
    }
    
    echo json_encode(['success' => true, 'photos' => $photos]);
    
} catch (Exception $e) {
    error_log("Error fetching return photos: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error fetching photos']);
}
?>
