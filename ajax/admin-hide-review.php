<?php
// Start output buffering to catch any unwanted output
ob_start();

// Disable error display but keep error logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Set JSON header early
header('Content-Type: application/json');

// Function to send JSON response and clean output
function sendJsonResponse($data) {
    // Clean any output buffer
    if (ob_get_length()) ob_clean();
    echo json_encode($data);
    exit;
}

// Error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    sendJsonResponse(['success' => false, 'message' => 'Server error: ' . $errstr]);
});

try {
    require_once '../config/database.php';
    require_once '../includes/functions.php';
    require_once '../includes/seller_notification_functions.php';
} catch (Exception $e) {
    error_log("Include error: " . $e->getMessage());
    sendJsonResponse(['success' => false, 'message' => 'Failed to load required files: ' . $e->getMessage()]);
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    sendJsonResponse(['success' => false, 'message' => 'Unauthorized access']);
}

// Get JSON input
$rawInput = file_get_contents('php://input');
error_log("Raw input: " . $rawInput);

$input = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("JSON decode error: " . json_last_error_msg());
    sendJsonResponse(['success' => false, 'message' => 'Invalid JSON input: ' . json_last_error_msg()]);
}

if (!isset($input['review_id']) || !isset($input['action'])) {
    error_log("Missing parameters. Input: " . print_r($input, true));
    sendJsonResponse(['success' => false, 'message' => 'Missing required parameters']);
}

$reviewId = intval($input['review_id']);
$action = $input['action']; // 'hide' or 'unhide'
$reason = isset($input['reason']) ? trim($input['reason']) : '';

error_log("Processing action '$action' for review ID $reviewId");

try {
    // First, ensure all necessary columns exist
    $columnsToAdd = [
        'is_hidden' => "BOOLEAN DEFAULT FALSE",
        'hidden_reason' => "VARCHAR(255) DEFAULT NULL",
        'hidden_at' => "TIMESTAMP NULL DEFAULT NULL",
        'hidden_by_admin_id' => "INT NULL DEFAULT NULL"
    ];
    
    foreach ($columnsToAdd as $columnName => $columnDef) {
        try {
            $checkColumn = $pdo->query("SHOW COLUMNS FROM product_reviews LIKE '$columnName'");
            if ($checkColumn->rowCount() == 0) {
                $alterQuery = "ALTER TABLE product_reviews ADD COLUMN $columnName $columnDef";
                error_log("Adding column: $alterQuery");
                $pdo->exec($alterQuery);
            }
        } catch (Exception $e) {
            error_log("Column '$columnName' check/add error: " . $e->getMessage());
            // Continue anyway
        }
    }
    
    // Get review details (customer and product info)
    error_log("Fetching review details for ID: $reviewId");
    $stmt = $pdo->prepare("
        SELECT pr.*, p.name as product_name, p.seller_id, p.id as product_id, u.username as customer_name, u.email as customer_email
        FROM product_reviews pr
        JOIN products p ON pr.product_id = p.id
        JOIN users u ON pr.user_id = u.id
        WHERE pr.id = ?
    ");
    $stmt->execute([$reviewId]);
    $review = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$review) {
        error_log("Review not found for ID: $reviewId");
        sendJsonResponse(['success' => false, 'message' => 'Review not found']);
    }
    
    error_log("Review found: " . print_r($review, true));
    
    if ($action === 'hide') {
        // Validate reason for hiding
        if (empty($reason)) {
            sendJsonResponse(['success' => false, 'message' => 'Reason is required for hiding reviews']);
        }
        
        error_log("Hiding review $reviewId with reason: $reason");
        
        // Hide the review
        $stmt = $pdo->prepare("
            UPDATE product_reviews 
            SET is_hidden = 1, 
                hidden_reason = ?, 
                hidden_at = NOW(), 
                hidden_by_admin_id = ? 
            WHERE id = ?
        ");
        $result = $stmt->execute([$reason, $_SESSION['user_id'], $reviewId]);
        
        if (!$result) {
            error_log("Failed to update review: " . print_r($stmt->errorInfo(), true));
            sendJsonResponse(['success' => false, 'message' => 'Failed to hide review']);
        }
        
        error_log("Review hidden successfully");
        
        // Notify customer with product_id
        try {
            $customerMessage = "Your review for '{$review['product_name']}' has been hidden by admin.\n\nReason: {$reason}";
            
            // Insert notification with product_id
            $customerNotificationStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, product_id, message, type, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $customerNotificationStmt->execute([
                $review['user_id'],
                $review['product_id'],
                $customerMessage,
                'review_hidden'
            ]);
            
            error_log("Customer notification sent successfully to user ID: " . $review['user_id']);
        } catch (Exception $e) {
            error_log("Error sending customer notification: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
        }

        // Notify seller
        try {
            $sellerMessage = "A review for your product '{$review['product_name']}' by {$review['customer_name']} has been hidden by admin.\n\nReason: {$reason}";
            $sellerLink = 'view-products.php?product_id=' . $review['product_id'];
            
            createSellerNotification(
                $review['seller_id'],
                "Customer Review Hidden",
                $sellerMessage,
                'warning',
                $sellerLink
            );
            
            error_log("Seller notification sent successfully to seller ID: " . $review['seller_id']);
        } catch (Exception $e) {
            error_log("Error creating seller notification: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
        }
        
        sendJsonResponse([
            'success' => true, 
            'message' => 'Review hidden successfully. Customer and seller have been notified.',
            'action' => 'hide'
        ]);
        
    } elseif ($action === 'unhide') {
        error_log("Unhiding review $reviewId");
        
        // Unhide the review
        $stmt = $pdo->prepare("
            UPDATE product_reviews 
            SET is_hidden = 0, 
                hidden_reason = NULL, 
                hidden_at = NULL, 
                hidden_by_admin_id = NULL 
            WHERE id = ?
        ");
        $result = $stmt->execute([$reviewId]);
        
        if (!$result) {
            error_log("Failed to update review: " . print_r($stmt->errorInfo(), true));
            sendJsonResponse(['success' => false, 'message' => 'Failed to unhide review']);
        }
        
        error_log("Review unhidden successfully");
        
        // Notify customer with product_id
        try {
            $customerMessage = "Great news! Your review for '{$review['product_name']}' has been unhidden by admin and is now visible again.";
            
            // Insert notification with product_id
            $customerNotificationStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, product_id, message, type, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $customerNotificationStmt->execute([
                $review['user_id'],
                $review['product_id'],
                $customerMessage,
                'review_unhidden'
            ]);
            
            error_log("Customer notification sent successfully to user ID: " . $review['user_id']);
        } catch (Exception $e) {
            error_log("Error sending customer notification: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
        }

        // Notify seller
        try {
            $sellerMessage = "A review for your product '{$review['product_name']}' by {$review['customer_name']} has been unhidden by admin and is now visible again.";
            $sellerLink = 'view-products.php?product_id=' . $review['product_id'];
            
            createSellerNotification(
                $review['seller_id'],
                "Customer Review Unhidden",
                $sellerMessage,
                'success',
                $sellerLink
            );
            
            error_log("Seller notification sent successfully to seller ID: " . $review['seller_id']);
        } catch (Exception $e) {
            error_log("Error creating seller notification: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
        }
        
        sendJsonResponse([
            'success' => true, 
            'message' => 'Review unhidden successfully. Customer and seller have been notified.',
            'action' => 'unhide'
        ]);
        
    } else {
        error_log("Invalid action: $action");
        sendJsonResponse(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (PDOException $e) {
    error_log("PDO Error in admin-hide-review.php: " . $e->getMessage());
    error_log("SQL State: " . $e->getCode());
    error_log("Stack trace: " . $e->getTraceAsString());
    sendJsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error in admin-hide-review.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    sendJsonResponse(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}
?>
