<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is admin
if (!isLoggedIn() || !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Mark all reviews as viewed by admin
    $stmt = $pdo->prepare("UPDATE product_reviews SET admin_viewed = 1 WHERE admin_viewed = 0");
    $stmt->execute();
    
    echo json_encode([
        'success' => true,
        'message' => 'Reviews marked as viewed'
    ]);
} catch (PDOException $e) {
    error_log("Error marking reviews as viewed: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);
}
