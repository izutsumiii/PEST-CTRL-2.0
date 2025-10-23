<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$orderId) {
    header('Location: user-dashboard.php?error=invalid_order');
    exit();
}

try {
    // Get the first product from the order to redirect to its product page
    $stmt = $pdo->prepare("
        SELECT oi.product_id 
        FROM order_items oi 
        JOIN orders o ON oi.order_id = o.id 
        WHERE o.id = ? AND o.user_id = ? 
        LIMIT 1
    ");
    $stmt->execute([$orderId, $_SESSION['user_id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        // Redirect to product page with review anchor to scroll to reviews section
        header("Location: product-detail.php?id=" . $result['product_id'] . "#reviews-tab");
        exit();
    } else {
        header('Location: user-dashboard.php?error=order_not_found');
        exit();
    }
    
} catch (PDOException $e) {
    error_log("Database error in rate-order.php: " . $e->getMessage());
    header('Location: user-dashboard.php?error=database_error');
    exit();
}
?>
