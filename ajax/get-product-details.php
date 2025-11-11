<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in as admin or seller
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'seller'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$productId) {
    echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
    exit;
}

try {
    // Check if product_categories table exists
    $tableExists = false;
    try {
        $checkTable = $pdo->query("SHOW TABLES LIKE 'product_categories'");
        $tableExists = $checkTable->rowCount() > 0;
    } catch (Exception $e) {
        $tableExists = false;
    }
    
    // Get product details with seller and multiple categories
    if ($tableExists) {
        $stmt = $pdo->prepare("
            SELECT 
                p.*,
                u.username as seller_name,
                u.email as seller_email,
                u.first_name as seller_first_name,
                u.last_name as seller_last_name,
                GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') as category_names,
                GROUP_CONCAT(DISTINCT c.id ORDER BY c.id SEPARATOR ',') as category_ids
            FROM products p 
            JOIN users u ON p.seller_id = u.id 
            LEFT JOIN product_categories pc ON p.id = pc.product_id
            LEFT JOIN categories c ON (pc.category_id = c.id OR p.category_id = c.id)
            WHERE p.id = ?
            GROUP BY p.id
        ");
    } else {
        // Fallback to single category
        $stmt = $pdo->prepare("
            SELECT 
                p.*,
                u.username as seller_name,
                u.email as seller_email,
                u.first_name as seller_first_name,
                u.last_name as seller_last_name,
                c.name as category_name,
                c.id as category_id
            FROM products p 
            JOIN users u ON p.seller_id = u.id 
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.id = ?
        ");
    }
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit;
    }
    
    // If user is a seller, ensure they can only view their own products
    if ($_SESSION['user_type'] === 'seller' && $product['seller_id'] != $_SESSION['user_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied. You can only view your own products.']);
        exit;
    }
    
    // Get product images
    $imagesStmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY id ASC");
    $imagesStmt->execute([$productId]);
    $images = $imagesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format dates
    $product['created_at_formatted'] = date('M j, Y g:i A', strtotime($product['created_at']));
    $product['updated_at_formatted'] = !empty($product['updated_at']) && $product['updated_at'] !== '0000-00-00 00:00:00' 
        ? date('M j, Y g:i A', strtotime($product['updated_at'])) 
        : 'Not updated';
    
    // Add images to product data
    $product['images'] = $images;
    
    echo json_encode(['success' => true, 'product' => $product]);
    
} catch (Exception $e) {
    error_log("Error fetching product details: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error fetching details: ' . $e->getMessage()]);
}
?>

