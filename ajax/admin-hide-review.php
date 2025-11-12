<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/seller_notification_functions.php';

header('Content-Type: application/json');

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$adminId = $_SESSION['user_id'];

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$reviewId = isset($input['review_id']) ? (int)$input['review_id'] : 0;
$action = isset($input['action']) ? $input['action'] : ''; // 'hide' or 'unhide'
$reason = isset($input['reason']) ? trim($input['reason']) : '';

// Validate input
if (!isset($input['review_id']) || $reviewId < 0 || !in_array($action, ['hide', 'unhide'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

// If hiding, reason is required
if ($action === 'hide' && empty($reason)) {
    echo json_encode(['success' => false, 'message' => 'Reason is required when hiding a review']);
    exit;
}

try {
    // Get review details
    $stmt = $pdo->prepare("
        SELECT pr.*, p.seller_id, p.name as product_name, p.id as product_id, 
               u.username as customer_name, u.id as customer_id
        FROM product_reviews pr
        JOIN products p ON pr.product_id = p.id
        JOIN users u ON pr.user_id = u.id
        WHERE pr.id = ?
    ");
    $stmt->execute([$reviewId]);
    $review = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$review) {
        echo json_encode(['success' => false, 'message' => 'Review not found']);
        exit;
    }
    
    if ($action === 'hide') {
        // Hide the review
        $updateStmt = $pdo->prepare("
            UPDATE product_reviews 
            SET is_hidden = TRUE, hidden_by = ?, hidden_at = NOW(), hidden_reason = ?
            WHERE id = ?
        ");
        
        if ($updateStmt->execute([$adminId, $reason, $reviewId])) {
            // Send notification to customer ONLY (not seller)
            try {
                $customerMessage = "Your review on '" . $review['product_name'] . "' has been hidden by admin. Reason: " . $reason;
                createCustomerNotification(
                    $review['customer_id'],
                    $customerMessage,
                    'review_hidden'
                );
            } catch (Exception $e) {
                error_log("Error creating customer notification: " . $e->getMessage());
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Review hidden successfully',
                'action' => 'hidden'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to hide review']);
        }
        
    } else if ($action === 'unhide') {
        // Unhide the review
        $updateStmt = $pdo->prepare("
            UPDATE product_reviews 
            SET is_hidden = FALSE, hidden_by = NULL, hidden_at = NULL, hidden_reason = NULL
            WHERE id = ?
        ");
        
        if ($updateStmt->execute([$reviewId])) {
            // Send notification to customer ONLY (not seller)
            try {
                $customerMessage = "Your review on '" . $review['product_name'] . "' has been unhidden by admin and is now visible again";
                createCustomerNotification(
                    $review['customer_id'],
                    $customerMessage,
                    'review_unhidden'
                );
            } catch (Exception $e) {
                error_log("Error creating customer notification: " . $e->getMessage());
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Review unhidden successfully',
                'action' => 'unhidden'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to unhide review']);
        }
    }
    
} catch (Exception $e) {
    error_log("Error in admin-hide-review.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>

