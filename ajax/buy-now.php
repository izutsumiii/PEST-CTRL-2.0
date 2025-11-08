<?php
// Turn off ALL error display to prevent breaking JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Clear ALL output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Start output buffering to catch any accidental output
ob_start();

try {
    session_start();
} catch (Exception $e) {
    // Session already started, ignore
}

require_once '../config/database.php';
require_once '../includes/functions.php';

// Clear any output that might have been generated
ob_clean();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit();
}

if (!isset($data['product_id']) || !isset($data['quantity'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing product ID or quantity']);
    exit();
}

$productId = intval($data['product_id']);
$quantity = intval($data['quantity']);

if ($productId <= 0 || $quantity <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product ID or quantity']);
    exit();
}

try {
    // Get product details with seller info
    $stmt = $pdo->prepare("
        SELECT p.*, u.username as seller_username, u.first_name as seller_first_name, 
               u.last_name as seller_last_name, u.display_name as seller_display_name
        FROM products p
        LEFT JOIN users u ON p.seller_id = u.id
        WHERE p.id = ?
    ");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        error_log('Buy Now - Product not found: ' . $productId);
        echo json_encode(['success' => false, 'message' => 'Product not found.']);
        exit();
    }
    
    if ($product['status'] !== 'active') {
        echo json_encode(['success' => false, 'message' => 'This product is not available.']);
        exit();
    }

    if ($product['stock_quantity'] < $quantity) {
        $msg = $product['stock_quantity'] == 0 ? "Product is out of stock" : "Only {$product['stock_quantity']} items available";
        echo json_encode(['success' => false, 'message' => $msg]);
        exit();
    }

    if (!isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Please log in to continue']);
        exit();
    }
    
    $userId = $_SESSION['user_id'];
    $sellerId = $product['seller_id'];
    
    if (!$sellerId) {
        echo json_encode(['success' => false, 'message' => 'Product seller not found']);
        exit();
    }
    
    // IMPORTANT: Add product to cart database first
    error_log('Buy Now - Attempting to add product ' . $productId . ' with quantity ' . $quantity . ' to cart for user ' . $userId);
    
    $addToCartResult = addToCart($productId, $quantity);
    
    error_log('Buy Now - addToCart result: ' . json_encode($addToCartResult));
    
    if (!$addToCartResult['success']) {
        error_log('Buy Now - Failed to add to cart: ' . ($addToCartResult['message'] ?? 'Unknown error'));
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
        error_log('Buy Now - WARNING: Product was not found in cart after addToCart returned success');
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
    
    error_log('Buy Now - Successfully verified product in cart with quantity: ' . $cartItem['quantity']);
    
    // Get total cart count for response
    $cartCountStmt = $pdo->prepare("SELECT SUM(quantity) as count FROM cart WHERE user_id = ?");
    $cartCountStmt->execute([$userId]);
    $cartCountResult = $cartCountStmt->fetch(PDO::FETCH_ASSOC);
    $totalCartItems = $cartCountResult['count'] ? (int)$cartCountResult['count'] : 0;
    
    $total = (float)$product['price'] * $quantity;
    
    // Get seller display name
    $sellerDisplayName = $product['seller_display_name'] 
        ?? trim(($product['seller_first_name'] ?? '') . ' ' . ($product['seller_last_name'] ?? ''))
        ?: ($product['seller_username'] ?? 'Unknown Seller');
    
    // Store EVERYTHING in session for checkout
    $_SESSION['buy_now_active'] = true;
    $_SESSION['buy_now_data'] = [
        'product' => [
            'id' => $product['id'],
            'product_id' => $product['id'],
            'name' => $product['name'],
            'price' => (float)$product['price'],
            'quantity' => $quantity,
            'image_url' => $product['image_url'] ?? 'images/placeholder.jpg',
            'stock_quantity' => $product['stock_quantity'],
            'status' => $product['status']
        ],
        'seller' => [
            'seller_id' => $sellerId,
            'seller_name' => $product['seller_username'] ?? 'Unknown',
            'seller_display_name' => $sellerDisplayName,
            'seller_first_name' => $product['seller_first_name'] ?? '',
            'seller_last_name' => $product['seller_last_name'] ?? ''
        ],
        'total' => $total,
        'quantity' => $quantity,
        'timestamp' => time()
    ];
    
    error_log('Buy Now - Session data stored: ' . json_encode($_SESSION['buy_now_data']));
    
    // Prepare response - default to cart.php so user can see items, then proceed to checkout
    $redirectTo = isset($data['redirect_to']) && $data['redirect_to'] === 'checkout'
        ? 'paymongo/multi-seller-checkout.php?buy_now=1'
        : 'cart.php';
    
    // Prepare response
    $response = [
        'success' => true,
        'message' => 'Product added to cart successfully. Ready for checkout.',
        'redirect_url' => $redirectTo,
        'item_name' => $product['name'],
        'total' => number_format($total, 2),
        'cart_count' => $totalCartItems,
        'debug' => [
            'cart_items_count' => $totalCartItems,
            'product_in_cart' => true,
            'cart_quantity' => $cartItem['quantity'],
            'product_id' => $productId,
            'user_id' => $userId
        ]
    ];
    
    // Write session before sending response
    session_write_close();
    
    // Clear any output and send JSON
    ob_clean();
    echo json_encode($response);
    exit();

} catch (Exception $e) {
    error_log('Buy Now Error: ' . $e->getMessage());
    error_log('Buy Now Error Trace: ' . $e->getTraceAsString());
    
    // Clear any output
    ob_clean();
    
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Error: ' . $e->getMessage(),
        'error' => $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
    exit();
} catch (Error $e) {
    error_log('Buy Now Fatal Error: ' . $e->getMessage());
    error_log('Buy Now Fatal Error Trace: ' . $e->getTraceAsString());
    
    // Clear any output
    ob_clean();
    
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Fatal Error: ' . $e->getMessage(),
        'error' => $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
    exit();
}
?>
