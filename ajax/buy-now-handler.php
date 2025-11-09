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
    
    // CRITICAL: Buy Now does NOT add to cart table - it's a separate session-only checkout
    // We only validate product exists and has stock, then store in session
    $userId = $_SESSION['user_id'];
    error_log('Buy Now Handler - Validating product ' . $productId . ' with quantity ' . $quantity . ' for user ' . $userId);
    error_log('Buy Now Handler - Product stock: ' . $product['stock_quantity'] . ', Required: ' . $quantity);
    
    // Stock validation is already done above, so we can proceed
    // NO addToCart() call - buy_now items stay in session only, not in cart table
    
    // Get seller info for buy_now_data
    $sellerStmt = $pdo->prepare("
        SELECT u.id as seller_id, u.username as seller_username, u.first_name, u.last_name, u.display_name
        FROM products p
        LEFT JOIN users u ON p.seller_id = u.id
        WHERE p.id = ?
    ");
    $sellerStmt->execute([$productId]);
    $sellerInfo = $sellerStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$sellerInfo || !$sellerInfo['seller_id']) {
        echo json_encode(['success' => false, 'message' => 'Product seller not found']);
        exit();
    }
    
    $total = (float)$product['price'] * $quantity;
    $sellerDisplayName = $sellerInfo['display_name'] 
        ?? trim(($sellerInfo['first_name'] ?? '') . ' ' . ($sellerInfo['last_name'] ?? ''))
        ?: ($sellerInfo['seller_username'] ?? 'Unknown Seller');
    
    // Store buy_now data in session (same structure as buy-now.php)
    $_SESSION['buy_now_active'] = true;
    $_SESSION['buy_now_data'] = [
        'product' => [
            'id' => $product['id'],
            'product_id' => $product['id'],
            'name' => $product['name'],
            'price' => (float)$product['price'],
            'quantity' => $quantity,
            'image_url' => $product['image_url'] ?? 'images/placeholder.jpg',
            'stock_quantity' => (int)$product['stock_quantity'],
            'status' => 'active'
        ],
        'seller' => [
            'seller_id' => (int)$sellerInfo['seller_id'],
            'seller_name' => $sellerInfo['seller_username'] ?? 'Unknown',
            'seller_display_name' => $sellerDisplayName,
            'seller_first_name' => $sellerInfo['first_name'] ?? '',
            'seller_last_name' => $sellerInfo['last_name'] ?? ''
        ],
        'total' => $total,
        'quantity' => $quantity,
        'timestamp' => time()
    ];
    
    error_log('Buy Now Handler - Session data stored: ' . json_encode($_SESSION['buy_now_data']));
    
    // Get regular cart count (buy_now items NOT included)
    $cartCountStmt = $pdo->prepare("SELECT SUM(quantity) as count FROM cart WHERE user_id = ?");
    $cartCountStmt->execute([$userId]);
    $cartCountResult = $cartCountStmt->fetch(PDO::FETCH_ASSOC);
    $totalCartItems = $cartCountResult['count'] ? (int)$cartCountResult['count'] : 0;
    
    echo json_encode([
        'success' => true,
        'message' => 'Product ready for Buy Now checkout.',
        'cart_count' => $totalCartItems, // Regular cart count only
        'debug' => [
            'cart_items_count' => $totalCartItems,
            'product_in_cart' => false, // Buy_now items are NOT in cart table
            'buy_now_quantity' => $quantity,
            'note' => 'Buy Now items stored in session only, not in cart table'
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Buy now error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error processing request']);
}
?>
