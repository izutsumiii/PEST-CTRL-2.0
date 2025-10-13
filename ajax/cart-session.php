<?php
// ajax/cart-session.php - For handling other cart operations with session
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$action = isset($_GET['action']) ? $_GET['action'] : (isset($input['action']) ? $input['action'] : '');

$response = ['success' => false, 'message' => ''];

switch ($action) {
    case 'get_count':
        $count = 0;
        
        // First try session cart
        if (function_exists('getSessionCartCount')) {
            $count = getSessionCartCount();
        }
        
        // If session cart is empty but user is logged in, pull DB cart count
        if ($count === 0 && function_exists('isLoggedIn') && isLoggedIn() && isset($_SESSION['user_id'])) {
            try {
                global $pdo;
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) as count FROM cart WHERE user_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && isset($row['count'])) {
                    $count = (int)$row['count'];
                }
            } catch (Exception $e) {
                error_log('Cart count DB error: ' . $e->getMessage());
            }
        }
        
        echo json_encode(['success' => true, 'count' => $count]);
        break;
        
    case 'get_total':
        $total = getSessionCartTotal();
        echo json_encode(['success' => true, 'total' => $total]);
        break;
        
    case 'get_cart':
        $cart = getSessionCartForDisplay();
        $count = getSessionCartCount();
        $total = getSessionCartTotal();
        
        echo json_encode([
            'success' => true,
            'cart' => $cart,
            'count' => $count,
            'total' => $total
        ]);
        break;
        
    case 'update_quantity':
        $productId = isset($input['product_id']) ? (int)$input['product_id'] : 0;
        $quantity = isset($input['quantity']) ? (int)$input['quantity'] : 1;
        
        if ($productId > 0) {
            $result = updateSessionCartQuantity($productId, $quantity);
            
            if ($result['success']) {
                $result['cartCount'] = getSessionCartCount();
                $result['cartTotal'] = getSessionCartTotal();
            }
            
            echo json_encode($result);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
        }
        break;
        
    case 'remove_item':
        $productId = isset($input['product_id']) ? (int)$input['product_id'] : 0;
        
        if ($productId > 0) {
            if (removeFromSessionCart($productId)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Item removed from cart',
                    'cartCount' => getSessionCartCount(),
                    'cartTotal' => getSessionCartTotal()
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Item not found in cart']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
        }
        break;
        
    case 'clear_cart':
        clearCart();
        echo json_encode([
            'success' => true,
            'message' => 'Cart cleared',
            'cartCount' => 0,
            'cartTotal' => '0.00'
        ]);
        break;
        
    case 'validate_cart':
        $result = validateSessionCart();
        
        if ($result['success'] || (!empty($result['updates']) && empty($result['errors']))) {
            $result['cartCount'] = getSessionCartCount();
            $result['cartTotal'] = getSessionCartTotal();
        }
        
        echo json_encode($result);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}
?>