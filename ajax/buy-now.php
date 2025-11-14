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
    
    // CRITICAL: Buy Now does NOT add to cart table - it's a separate session-only checkout
    // We only validate product exists and has stock, then store in session
    error_log('Buy Now - Validating product ' . $productId . ' with quantity ' . $quantity . ' for user ' . $userId);
    error_log('Buy Now - Product stock: ' . $product['stock_quantity'] . ', Required: ' . $quantity);
    
    // Stock validation is already done above, so we can proceed
    // NO addToCart() call - buy_now items stay in session only, not in cart table
    
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
    
    // Check if user wants to include cart items
    $includeCartItems = isset($data['include_cart']) && $data['include_cart'] === true;
    
    // If including cart items, add buy_now item to cart temporarily for checkout
    if ($includeCartItems) {
        // Add buy_now product to cart so it appears in checkout with other items
        try {
            // Check if product already in cart
            $checkCart = $pdo->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ?");
            $checkCart->execute([$userId, $productId]);
            $existingCartItem = $checkCart->fetch(PDO::FETCH_ASSOC);
            
            if ($existingCartItem) {
                // Update quantity
                $newQty = (int)$existingCartItem['quantity'] + $quantity;
                $updateCart = $pdo->prepare("UPDATE cart SET quantity = ? WHERE id = ?");
                $updateCart->execute([$newQty, $existingCartItem['id']]);
                error_log('Buy Now - Updated existing cart item quantity to ' . $newQty);
            } else {
                // Add new cart item
                $addCart = $pdo->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)");
                $addCart->execute([$userId, $productId, $quantity]);
                error_log('Buy Now - Added product to cart for checkout');
            }
            
            // Set flag to indicate buy_now was used but items are in cart
            $_SESSION['buy_now_include_cart'] = true;
            $_SESSION['buy_now_product_id'] = $productId;
        } catch (Exception $e) {
            error_log('Buy Now - Error adding to cart: ' . $e->getMessage());
        }
    } else {
        // Store buy_now in session only (original behavior)
        $_SESSION['buy_now_include_cart'] = false;
    }
    
    // Prepare response - always redirect to checkout when buy now is used
    $redirectTo = 'paymongo/multi-seller-checkout.php' . ($includeCartItems ? '' : '?buy_now=1');
    
    // Prepare response - note: buy_now items are NOT in cart, so cart_count is from regular cart only
    // CRITICAL: Cart count should NOT include buy_now items - only count from cart table
    $cartCountStmt = $pdo->prepare("SELECT SUM(quantity) as count FROM cart WHERE user_id = ?");
    $cartCountStmt->execute([$userId]);
    $cartCountResult = $cartCountStmt->fetch(PDO::FETCH_ASSOC);
    $totalCartItems = $cartCountResult['count'] ? (int)$cartCountResult['count'] : 0;
    error_log('Buy Now - Cart count (excluding buy_now): ' . $totalCartItems);
    
    $response = [
        'success' => true,
        'message' => 'Product ready for Buy Now checkout.',
        'redirect_url' => $redirectTo,
        'item_name' => $product['name'],
        'total' => number_format($total, 2),
        'cart_count' => $totalCartItems, // Regular cart count (buy_now items not included)
        'debug' => [
            'cart_items_count' => $totalCartItems,
            'product_in_cart' => false, // Buy_now items are NOT in cart table
            'buy_now_quantity' => $quantity,
            'product_id' => $productId,
            'user_id' => $userId,
            'note' => 'Buy Now items stored in session only, not in cart table'
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
