<?php
// ajax/add-to-cart.php
// Turn off error display for JSON requests
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 0);

// Clear any previous output
while (ob_get_level()) {
    ob_end_clean();
}

session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Set JSON header
header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit;
}

// Check if user is logged in
if (!isLoggedIn()) {
    $response['message'] = 'Please log in to add items to cart';
    echo json_encode($response);
    exit;
}

// Validate input
$productId = isset($input['product_id']) ? (int)$input['product_id'] : 0;
$quantity = isset($input['quantity']) ? (int)$input['quantity'] : 1;

if ($productId <= 0) {
    $response['message'] = 'Invalid product ID';
    echo json_encode($response);
    exit;
}

if ($quantity <= 0) {
    $quantity = 1;
}

// Use the database-based addToCart function
$result = addToCart($productId, $quantity);

if ($result['success']) {
    $response['success'] = true;
    $response['message'] = $result['message'];
    
    // Get cart count for response
    $stmt = $pdo->prepare("SELECT SUM(quantity) as count FROM cart WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $countResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $response['cartCount'] = $countResult['count'] ? $countResult['count'] : 0;
} else {
    $response['message'] = $result['message'];
}

echo json_encode($response);
?>