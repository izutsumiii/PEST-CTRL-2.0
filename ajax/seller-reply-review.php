<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/seller_notification_functions.php';

header('Content-Type: application/json');

// Check if user is logged in as seller
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'seller') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$sellerId = $_SESSION['user_id'];

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$reviewId = isset($input['review_id']) ? (int)$input['review_id'] : 0;
$replyText = isset($input['reply_text']) ? trim($input['reply_text']) : '';

// Validate input
if (!isset($input['review_id']) || $reviewId < 0 || empty($replyText)) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

// Check reply length (500 character limit)
if (strlen($replyText) > 500) {
    echo json_encode(['success' => false, 'message' => 'Reply must be 500 characters or less']);
    exit;
}

try {
    // Get review details and verify seller owns the product
    $stmt = $pdo->prepare("
        SELECT pr.*, p.seller_id, p.name as product_name, p.id as product_id, u.username, u.id as customer_id
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
    
    // Verify this seller owns the product
    if ($review['seller_id'] != $sellerId) {
        echo json_encode(['success' => false, 'message' => 'You can only reply to reviews on your own products']);
        exit;
    }
    
    // Check if seller already replied
    if (!empty($review['seller_reply'])) {
        echo json_encode(['success' => false, 'message' => 'You have already replied to this review']);
        exit;
    }
    
    // Check if review is hidden
    if ($review['is_hidden']) {
        echo json_encode(['success' => false, 'message' => 'Cannot reply to a hidden review']);
        exit;
    }
    
    // Add seller reply
    $updateStmt = $pdo->prepare("
        UPDATE product_reviews 
        SET seller_reply = ?, seller_replied_at = NOW() 
        WHERE id = ?
    ");
    
    if ($updateStmt->execute([$replyText, $reviewId])) {
        // Send notification to customer with product_id link
        try {
            $customerMessage = "The seller has responded to your review on '" . $review['product_name'] . "'";
            
            // Create notification with product_id so it links to the product page
            $notifStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, order_id, message, type, product_id, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $notifStmt->execute([
                $review['customer_id'],
                $review['order_id'],
                $customerMessage,
                'seller_reply',
                $review['product_id']
            ]);
            
            // Log for debugging
            error_log("Seller reply notification created for customer ID: " . $review['customer_id'] . " on product ID: " . $review['product_id']);
            
        } catch (Exception $e) {
            error_log("Error creating customer notification for seller reply: " . $e->getMessage());
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Reply posted successfully',
            'reply' => [
                'text' => htmlspecialchars($replyText),
                'replied_at' => date('F j, Y g:i A')
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to post reply']);
    }
    
} catch (Exception $e) {
    error_log("Error in seller-reply-review.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
