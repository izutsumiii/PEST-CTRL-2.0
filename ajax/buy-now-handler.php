<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$productId = (int)($input['product_id'] ?? 0);
$quantity = (int)($input['quantity'] ?? 1);

if ($productId <= 0 || $quantity <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product or quantity']);
    exit();
}

try {
    // Get product details
    $stmt = $pdo->prepare("SELECT id, name, price, image_url, stock_quantity FROM products WHERE id = ? AND status = 'active'");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit();
    }
    
    // Check stock
    if ($product['stock_quantity'] < $quantity) {
        echo json_encode(['success' => false, 'message' => 'Not enough stock available']);
        exit();
    }
    
    // IMPORTANT: Add product to cart database first
    $userId = $_SESSION['user_id'];
    error_log('Buy Now Handler - Attempting to add product ' . $productId . ' with quantity ' . $quantity . ' to cart for user ' . $userId);
    
    $addToCartResult = addToCart($productId, $quantity);
    
    error_log('Buy Now Handler - addToCart result: ' . json_encode($addToCartResult));
    
    if (!$addToCartResult['success']) {
        error_log('Buy Now Handler - Failed to add to cart: ' . ($addToCartResult['message'] ?? 'Unknown error'));
        echo json_encode([
            'success' => false, 
            'message' => $addToCartResult['message'] ?? 'Failed to add product to cart',
            'debug' => [
                'product_id' => $productId,
                'quantity' => $quantity,
                'user_id' => $userId,
                'addToCartResult' => $addToCartResult
            ]
        ]);
        exit();
    }
    
    // Verify the item was actually added to cart
    $verifyStmt = $pdo->prepare("SELECT quantity FROM cart WHERE user_id = ? AND product_id = ?");
    $verifyStmt->execute([$userId, $productId]);
    $cartItem = $verifyStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cartItem) {
        error_log('Buy Now Handler - WARNING: Product was not found in cart after addToCart returned success');
        echo json_encode([
            'success' => false,
            'message' => 'Product was not added to cart. Please try again.',
            'debug' => [
                'addToCartResult' => $addToCartResult,
                'cart_verification' => 'failed'
            ]
        ]);
        exit();
    }
    
    error_log('Buy Now Handler - Successfully verified product in cart with quantity: ' . $cartItem['quantity']);
    
    // Get total cart count for response
    $cartCountStmt = $pdo->prepare("SELECT SUM(quantity) as count FROM cart WHERE user_id = ?");
    $cartCountStmt->execute([$userId]);
    $cartCountResult = $cartCountStmt->fetch(PDO::FETCH_ASSOC);
    $totalCartItems = $cartCountResult['count'] ? (int)$cartCountResult['count'] : 0;
    
    // Store buy now item in session for checkout
    $_SESSION['buy_now_item'] = [
        'id' => $product['id'],
        'name' => $product['name'],
        'price' => $product['price'],
        'quantity' => $quantity,
        'image_url' => $product['image_url'],
        'total' => $product['price'] * $quantity
    ];
    
    echo json_encode([
        'success' => true,
        'message' => 'Product added to cart successfully',
        'cart_count' => $totalCartItems,
        'debug' => [
            'cart_items_count' => $totalCartItems,
            'product_in_cart' => true,
            'cart_quantity' => $cartItem['quantity']
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Buy now error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error processing request']);
}
?>
