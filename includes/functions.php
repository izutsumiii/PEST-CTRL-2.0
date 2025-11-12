<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
/**
 * Validate and apply discount code
 * Returns array with success status, message, and discount details
 */
function validateAndApplyDiscount($discountCode, $userId, $cartTotal, $pdo) {
    try {
        // Get discount code details
        $stmt = $pdo->prepare("
            SELECT * FROM discount_codes 
            WHERE code = ? AND is_active = 1
        ");
        $stmt->execute([strtoupper(trim($discountCode))]);
        $discount = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$discount) {
            return ['success' => false, 'message' => 'Invalid or inactive discount code.'];
        }
        
        // Check if code has expired
        $now = date('Y-m-d H:i:s');
        if ($now < $discount['start_date']) {
            return ['success' => false, 'message' => 'This discount code is not yet active.'];
        }
        if ($discount['end_date'] && $now > $discount['end_date']) {
            return ['success' => false, 'message' => 'This discount code has expired.'];
        }
        
        // Check if user has already used this code (ONE TIME PER USER)
        $stmt = $pdo->prepare("
            SELECT id FROM discount_code_usage 
            WHERE user_id = ? AND discount_code_id = ?
        ");
        $stmt->execute([$userId, $discount['id']]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'You have already used this discount code.'];
        }
        
        // Check minimum order amount
        if ($cartTotal < $discount['min_order_amount']) {
            return [
                'success' => false, 
                'message' => 'Minimum order amount of ₱' . number_format($discount['min_order_amount'], 2) . ' required.'
            ];
        }
        
        // Check max uses (global limit)
        if ($discount['max_uses'] && $discount['used_count'] >= $discount['max_uses']) {
            return ['success' => false, 'message' => 'This discount code has reached its usage limit.'];
        }
        
        // Calculate discount amount
        $discountAmount = 0;
        if ($discount['discount_type'] === 'percentage') {
            $discountAmount = ($cartTotal * $discount['discount_value']) / 100;
        } else {
            $discountAmount = $discount['discount_value'];
        }
        
        // Ensure discount doesn't exceed cart total
        $discountAmount = min($discountAmount, $cartTotal);
        
        return [
            'success' => true,
            'message' => 'Discount code applied successfully!',
            'discount_id' => $discount['id'],
            'discount_code' => $discount['code'],
            'discount_amount' => $discountAmount,
            'discount_type' => $discount['discount_type'],
            'discount_value' => $discount['discount_value'],
            'final_total' => $cartTotal - $discountAmount
        ];
        
    } catch (PDOException $e) {
        error_log('Discount validation error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Error validating discount code.'];
    }
}

/**
 * Record discount code usage after successful payment
 */
function recordDiscountUsage($userId, $discountCodeId, $paymentTransactionId, $orderId, $pdo) {
    try {
        // Record usage
        $stmt = $pdo->prepare("
            INSERT INTO discount_code_usage (user_id, discount_code_id, payment_transaction_id, order_id) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $discountCodeId, $paymentTransactionId, $orderId]);
        
        // Increment used_count in discount_codes table
        $stmt = $pdo->prepare("UPDATE discount_codes SET used_count = used_count + 1 WHERE id = ?");
        $stmt->execute([$discountCodeId]);
        
        return true;
    } catch (PDOException $e) {
        error_log('Error recording discount usage: ' . $e->getMessage());
        return false;
    }
}
// Auto-logout after 20 minutes of inactivity
function checkSessionTimeout() {
    $timeout = 20 * 60; // 20 minutes in seconds
    
    // Set last activity time if not set
    if (!isset($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = time();
        return true;
    }
    
    // Check if timeout has been reached
    if (time() - $_SESSION['last_activity'] > $timeout) {
        // Session expired - destroy session and redirect
        session_unset();
        session_destroy();
        
        // Determine redirect URL based on current page
        $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
        $redirectUrl = 'login.php';
        
        if (strpos($currentUrl, '/admin/') !== false) {
            $redirectUrl = '../login_admin.php';
        } elseif (strpos($currentUrl, '/seller') !== false || strpos($currentUrl, 'seller-') !== false) {
            $redirectUrl = '../login_seller.php';
        } elseif (strpos($currentUrl, '/customer') !== false || strpos($currentUrl, 'customer-') !== false) {
            $redirectUrl = '../login_customer.php';
        }
        
        // Set message for expired session
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['session_expired'] = true;
        $_SESSION['expired_message'] = 'Your session has expired due to inactivity. Please log in again.';
        
        header("Location: " . $redirectUrl);
        exit();
    }
    
    // Update last activity time
    $_SESSION['last_activity'] = time();
    return true;
}

// Call session timeout check on every request
if (isset($_SESSION['user_id'])) {
    checkSessionTimeout();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Check if user is admin
function isAdmin() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';
}

// Check if user is seller
function isSeller() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'seller';
}

// Redirect to login if not authenticated
if (!function_exists('requireLogin')) {
    function requireLogin() {
        if (!isLoggedIn()) {
            header("Location: login.php");
            exit();
        }
    }
}

// Ensure a table's primary key `id` is AUTO_INCREMENT to avoid duplicate '0' inserts
if (!function_exists('ensureAutoIncrementPrimary')) {
    function ensureAutoIncrementPrimary($tableName) {
        global $pdo;
        try {
            // Skip if we're in a transaction to avoid conflicts
            if ($pdo->inTransaction()) {
                return;
            }
            
            // Determine current database/schema
            $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
            if (!$dbName) return;

            // Check if the `id` column already has auto_increment
            $stmt = $pdo->prepare("SELECT EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = 'id' LIMIT 1");
            $stmt->execute([$dbName, $tableName]);
            $extra = $stmt->fetchColumn();

            if (is_string($extra) && stripos($extra, 'auto_increment') !== false) {
                return; // already ok
            }

            // Attempt to alter column to AUTO_INCREMENT (id unsigned int)
            $pdo->exec("ALTER TABLE `" . str_replace('`','',$tableName) . "` MODIFY `id` INT UNSIGNED NOT NULL AUTO_INCREMENT");
        } catch (Exception $e) {
            // Silently ignore; will surface as DB error if still misconfigured
        }
    }
}

// Redirect to admin dashboard if not admin
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header("Location: index.php");
        exit();
    }
}

// Redirect to seller dashboard if not seller
function requireSeller() {
    requireLogin();
    if (!isSeller()) {
        header("Location: index.php");
        exit();
    }
}
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($data) {
        return htmlspecialchars(stripslashes(trim($data)));
    }
}
// Unified currency formatting for Philippine Peso
if (!function_exists('formatCurrency')) {
    function formatCurrency($amount) {
        $num = is_numeric($amount) ? (float)$amount : 0.0;
        return '₱' . number_format($num, 2);
    }
}
// Get featured products
function getFeaturedProducts($limit = 8) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM products WHERE status = 'active' AND stock_quantity > 0 ORDER BY rating DESC LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get product by ID
function getProductById($id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT p.*, u.username as seller_name 
                          FROM products p 
                          JOIN users u ON p.seller_id = u.id 
                          WHERE p.id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get product images
function getProductImages($productId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ?");
    $stmt->execute([$productId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get product reviews
function getProductReviews($productId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT pr.*, u.username 
                          FROM product_reviews pr 
                          JOIN users u ON pr.user_id = u.id 
                          WHERE product_id = ? 
                          ORDER BY created_at DESC");
    $stmt->execute([$productId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* -----------------------------
   CART FUNCTIONS WITH VALIDATION
------------------------------ */

// Add to cart with stock validation
function addToCart($productId, $quantity = 1) {
    if (!isLoggedIn()) {
        return ["success" => false, "message" => "Not logged in."];
    }
    
    global $pdo;
    $userId = $_SESSION['user_id'];
    
    // Check product stock
    $stmt = $pdo->prepare("SELECT stock_quantity FROM products WHERE id = ? AND status = 'active'");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        return ["success" => false, "message" => "Product not found or not available."];
    }
    
    // Check if product already in cart
    $stmt = $pdo->prepare("SELECT * FROM cart WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$userId, $productId]);
    $existingItem = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingItem) {
        $newQuantity = $existingItem['quantity'] + $quantity;
        
        if ($newQuantity > $product['stock_quantity']) {
            $available = $product['stock_quantity'] - $existingItem['quantity'];
            return [
                "success" => false, 
                "message" => "Only $available more of this product available. You already have {$existingItem['quantity']} in your cart."
            ];
        }
        
        $stmt = $pdo->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?");
        $result = $stmt->execute([$newQuantity, $userId, $productId]);
        
        return ["success" => $result, "message" => $result ? "Product added to cart!" : "Error updating cart."];
    } else {
        if ($quantity > $product['stock_quantity']) {
            return [
                "success" => false, 
                "message" => "Only {$product['stock_quantity']} of this product available."
            ];
        }
        
        try {
            $stmt = $pdo->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)");
            $result = $stmt->execute([$userId, $productId, $quantity]);
            
            
            return ["success" => $result, "message" => $result ? "Product added to cart!" : "Error adding to cart."];
        } catch (PDOException $e) {
            // Handle unique constraint violation - item was added by another process
            if ($e->getCode() == 23000) { // Duplicate entry error
                // Try to update the existing entry instead
                $stmt = $pdo->prepare("UPDATE cart SET quantity = quantity + ? WHERE user_id = ? AND product_id = ?");
                $result = $stmt->execute([$quantity, $userId, $productId]);
                
                
                if ($result && $stmt->rowCount() > 0) {
                    return ["success" => true, "message" => "Product added to cart!"];
                } else {
                    return ["success" => false, "message" => "Error adding to cart."];
                }
            } else {
                return ["success" => false, "message" => "Database error occurred."];
            }
        }
    }
}

// Update cart quantity with stock validation
function updateCartQuantity($productId, $quantity) {
    if (!isLoggedIn()) {
        return ["success" => false, "message" => "Not logged in."];
    }
    
    global $pdo;
    $userId = $_SESSION['user_id'];
    
    $stmt = $pdo->prepare("SELECT stock_quantity FROM products WHERE id = ? AND status = 'active'");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        return ["success" => false, "message" => "Product not found or not available."];
    }
    
    if ($quantity > $product['stock_quantity']) {
        return [
            "success" => false, 
            "message" => "Only {$product['stock_quantity']} of this product available."
        ];
    }
    
    if ($quantity <= 0) {
        $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
        $result = $stmt->execute([$userId, $productId]);
        return ["success" => $result, "message" => $result ? "Item removed from cart." : "Error removing item."];
    } else {
        $stmt = $pdo->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?");
        $result = $stmt->execute([$quantity, $userId, $productId]);
        return ["success" => $result, "message" => $result ? "Cart updated!" : "Error updating cart."];
    }
}

// Get cart items with stock info
// CRITICAL: This function ONLY reads from cart table
// Buy_now items are in session ONLY and are NOT included here
function getCartItems() {
    if (!isLoggedIn()) {
        return [];
    }
    
    global $pdo;
    $userId = $_SESSION['user_id'];
    
    // Only read from cart table - buy_now items are separate and NOT included
    $stmt = $pdo->prepare("SELECT c.*, p.name, p.price, p.image_url, p.stock_quantity 
                          FROM cart c 
                          JOIN products p ON c.product_id = p.id 
                          WHERE c.user_id = ? AND p.status = 'active'");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Validate cart before checkout
function validateCartForCheckout() {
    if (!isLoggedIn()) {
        return ["success" => false, "message" => "Not logged in."];
    }
    
    $cartItems = getCartItems();
    $errors = [];
    
    foreach ($cartItems as $item) {
        if ($item['quantity'] > $item['stock_quantity']) {
            $errors[] = "Only {$item['stock_quantity']} of '{$item['name']}' available. You have {$item['quantity']} in your cart.";
        }
    }
    
    if (!empty($errors)) {
        return ["success" => false, "message" => implode("<br>", $errors)];
    }
    
    return ["success" => true, "message" => "Cart is valid for checkout."];
}

/* -----------------------------
   PRODUCT MANAGEMENT FUNCTIONS
------------------------------ */
function addProduct($name, $description, $price, $categoryId, $sellerId, $stockQuantity, $imageUrl, $status = 'pending', $categoryIds = []) {
    global $pdo;
    
    // Ensure AUTO_INCREMENT is set on products table
    ensureAutoIncrementPrimary('products');
    
    // Update products.status column to support new status values if it's still an old ENUM
    try {
        $pdo->exec("ALTER TABLE products MODIFY COLUMN status ENUM('pending', 'active', 'inactive', 'suspended', 'rejected') DEFAULT 'pending'");
    } catch (Exception $e) {
        // Column might already be updated or error occurred, continue anyway
        error_log("Error updating products.status column: " . $e->getMessage());
    }
    
    // Create product_categories table if it doesn't exist (do this BEFORE inserting product)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS product_categories (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            product_id INT(11) NOT NULL,
            category_id INT(11) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_product_category (product_id, category_id),
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        // If table creation fails, log error but continue (table might already exist)
        error_log("Error creating product_categories table: " . $e->getMessage());
    }
    
    // Ensure status is valid - default to 'pending' if empty or invalid
    if (empty($status) || !in_array($status, ['pending', 'active', 'inactive', 'suspended', 'rejected'])) {
        $status = 'pending';
    }
    
    // Use first category as primary category_id for backward compatibility
    $primaryCategoryId = !empty($categoryIds) ? intval($categoryIds[0]) : ($categoryId ? intval($categoryId) : null);
    
    $stmt = $pdo->prepare("INSERT INTO products (name, description, price, category_id, seller_id, stock_quantity, image_url, status) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    if ($stmt->execute([$name, $description, $price, $primaryCategoryId, $sellerId, $stockQuantity, $imageUrl, $status])) {
        $productId = $pdo->lastInsertId();
        
        // Create admin notification for new product
        try {
            require_once __DIR__ . '/admin_notification_functions.php';
            // Get seller info for notification
            $sellerStmt = $pdo->prepare("SELECT username, first_name, last_name FROM users WHERE id = ?");
            $sellerStmt->execute([$sellerId]);
            $seller = $sellerStmt->fetch(PDO::FETCH_ASSOC);
            $sellerName = $seller ? ($seller['first_name'] . ' ' . $seller['last_name'] ?: $seller['username']) : 'Unknown Seller';
            
            // Get all admin users
            $adminStmt = $pdo->query("SELECT id FROM users WHERE user_type = 'admin'");
            $admins = $adminStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Create notification for each admin
            foreach ($admins as $admin) {
                createAdminNotification(
                    $admin['id'],
                    "New Product Pending Approval",
                    "Seller '{$sellerName}' has added a new product '{$name}' (ID: #{$productId}) that requires your approval.",
                    'info',
                    'admin/admin-products.php?status=pending&product_id=' . $productId
                );
            }
        } catch (Exception $e) {
            // If notification creation fails, log but don't fail the whole operation
            error_log("Error creating admin notification for new product: " . $e->getMessage());
        }
        
        // Add multiple categories
        if (!empty($categoryIds)) {
            try {
                $insertStmt = $pdo->prepare("INSERT IGNORE INTO product_categories (product_id, category_id) VALUES (?, ?)");
                foreach ($categoryIds as $catId) {
                    $catId = intval($catId);
                    if ($catId > 0) {
                        $insertStmt->execute([$productId, $catId]);
                    }
                }
            } catch (Exception $e) {
                // If insertion fails, log but don't fail the whole operation
                error_log("Error inserting into product_categories: " . $e->getMessage());
            }
        } elseif ($primaryCategoryId) {
            // If no array but single category provided, add it
            try {
                $insertStmt = $pdo->prepare("INSERT IGNORE INTO product_categories (product_id, category_id) VALUES (?, ?)");
                $insertStmt->execute([$productId, $primaryCategoryId]);
            } catch (Exception $e) {
                // If insertion fails, log but don't fail the whole operation
                error_log("Error inserting into product_categories: " . $e->getMessage());
            }
        }
        
        return true;
    }
    
    return false;
}
// Update product
function updateProduct($productId, $name, $description, $price, $categoryId, $stockQuantity, $status) {
    global $pdo;
    
    $stmt = $pdo->prepare("UPDATE products SET name = ?, description = ?, price = ?, category_id = ?, stock_quantity = ?, status = ? WHERE id = ?");
    return $stmt->execute([$name, $description, $price, $categoryId, $stockQuantity, $status, $productId]);
}

// Seller active products
function getSellerActiveProducts($sellerId) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT p.*, c.name as category_name 
                          FROM products p 
                          LEFT JOIN categories c ON p.category_id = c.id 
                          WHERE p.seller_id = ? AND p.status = 'active'
                          ORDER BY p.created_at DESC");
    $stmt->execute([$sellerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Seller inactive products
function getSellerInactiveProducts($sellerId) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT p.*, c.name as category_name 
                          FROM products p 
                          LEFT JOIN categories c ON p.category_id = c.id 
                          WHERE p.seller_id = ? AND p.status = 'inactive'
                          ORDER BY p.created_at DESC");
    $stmt->execute([$sellerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Cart total
function getCartTotal() {
    $cartItems = getCartItems();
    $total = 0;
    
    foreach ($cartItems as $item) {
        $total += $item['price'] * $item['quantity'];
    }
    
    return $total;
}

// Remove from cart
function removeFromCart($productId) {
    if (!isLoggedIn()) {
        return false;
    }
    
    global $pdo;
    $userId = $_SESSION['user_id'];
    
    $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
    return $stmt->execute([$userId, $productId]);
}

// Process checkout
function processCheckout($shippingAddress, $paymentMethod) {
    if (!isLoggedIn()) {
        return false;
    }
    
    global $pdo;
    $userId = $_SESSION['user_id'];
    
    try {
        $pdo->beginTransaction();
        
        $validation = validateCartForCheckout();
        if (!$validation['success']) {
            throw new Exception($validation['message']);
        }
        
        $cartItems = getCartItems();
        if (empty($cartItems)) {
            throw new Exception("Cart is empty");
        }
        
        $total = getCartTotal();
        
        $stmt = $pdo->prepare("INSERT INTO orders (user_id, total_amount, shipping_address, payment_method) 
                              VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $total, $shippingAddress, $paymentMethod]);
        $orderId = $pdo->lastInsertId();
        
        foreach ($cartItems as $item) {
            $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) 
                                  VALUES (?, ?, ?, ?)");
            $stmt->execute([$orderId, $item['product_id'], $item['quantity'], $item['price']]);
            
            $stmt = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?");
            $stmt->execute([$item['quantity'], $item['product_id']]);
        }
        
        $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        $pdo->commit();
        return $orderId;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

// User orders
if (!function_exists('getUserOrders')) {
    function getUserOrders() {
        if (!isLoggedIn()) {
            return [];
        }
        
        global $pdo;
        $userId = $_SESSION['user_id'];
        
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Order details
function getOrderDetails($orderId) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT o.*, oi.*, p.name, p.image_url 
                          FROM orders o 
                          JOIN order_items oi ON o.id = oi.order_id 
                          JOIN products p ON oi.product_id = p.id 
                          WHERE o.id = ?");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Search products
function searchProducts($query, $filters = []) {
    global $pdo;
    
    $sql = "SELECT * FROM products WHERE status = 'active' AND (name LIKE ? OR description LIKE ?)";
    $params = ["%$query%", "%$query%"];
    
    if (!empty($filters['category'])) {
        $sql .= " AND category_id = ?";
        $params[] = $filters['category'];
    }
    
    if (!empty($filters['min_price'])) {
        $sql .= " AND price >= ?";
        $params[] = $filters['min_price'];
    }
    
    if (!empty($filters['max_price'])) {
        $sql .= " AND price <= ?";
        $params[] = $filters['max_price'];
    }
    
    // Default: hide out-of-stock unless explicitly overridden
    if (!isset($filters['include_out_of_stock']) || !$filters['include_out_of_stock']) {
        $sql .= " AND stock_quantity > 0";
    }
    
    $sql .= " ORDER BY created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* -----------------------------
   RATING & REVIEW FILTER FUNCTIONS
------------------------------ */

// Get products filtered by rating
function getProductsByRating($minRating = 0, $maxRating = 5) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM products 
                          WHERE status = 'active' 
                          AND rating >= ? AND rating <= ?
                          ORDER BY rating DESC, review_count DESC");
    $stmt->execute([$minRating, $maxRating]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get products with minimum number of reviews
function getProductsByMinReviews($minReviews = 1) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM products 
                          WHERE status = 'active' 
                          AND review_count >= ?
                          ORDER BY rating DESC, review_count DESC");
    $stmt->execute([$minReviews]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function getProducts() {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM products WHERE status = 'active' AND stock_quantity > 0 ORDER BY id DESC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Enhanced search function with rating filters
function searchProductsWithRating($query, $filters = []) {
    global $pdo;
    
    $sql = "SELECT * FROM products WHERE status = 'active'";
    $params = [];
    
    // Add search query
    if (!empty($query)) {
        $sql .= " AND (name LIKE ? OR description LIKE ?)";
        $params[] = "%$query%";
        $params[] = "%$query%";
    }
    
    // Add filters
    if (!empty($filters['category'])) {
        $sql .= " AND category_id = ?";
        $params[] = $filters['category'];
    }
    
    if (!empty($filters['min_price'])) {
        $sql .= " AND price >= ?";
        $params[] = $filters['min_price'];
    }
    
    if (!empty($filters['max_price'])) {
        $sql .= " AND price <= ?";
        $params[] = $filters['max_price'];
    }
    
    if (isset($filters['in_stock']) && $filters['in_stock']) {
        $sql .= " AND stock_quantity > 0";
    }
    
    // Add rating filters
    if (!empty($filters['min_rating'])) {
        $sql .= " AND rating >= ?";
        $params[] = $filters['min_rating'];
    }
    
    if (!empty($filters['max_rating'])) {
        $sql .= " AND rating <= ?";
        $params[] = $filters['max_rating'];
    }
    
    if (!empty($filters['min_reviews'])) {
        $sql .= " AND review_count >= ?";
        $params[] = $filters['min_reviews'];
    }
    
    // Add sorting
    $sort = isset($filters['sort']) ? $filters['sort'] : 'created_at';
    $order = isset($filters['order']) ? $filters['order'] : 'DESC';
    
    $validSorts = ['name', 'price', 'rating', 'review_count', 'created_at'];
    $validOrders = ['ASC', 'DESC'];
    
    if (in_array($sort, $validSorts) && in_array($order, $validOrders)) {
        $sql .= " ORDER BY $sort $order";
    } else {
        $sql .= " ORDER BY created_at DESC";
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Enhanced search with categories array and pagination
function searchProductsWithRatingEnhanced($query, $filters = []) {
    global $pdo;

    $sql = "SELECT * FROM products WHERE status = 'active'";
    $params = [];

    if (!empty($query)) {
        $sql .= " AND (name LIKE ? OR description LIKE ?)";
        $params[] = "%$query%";
        $params[] = "%$query%";
    }

    // Support single category or multiple categories via IN()
    if (!empty($filters['categories']) && is_array($filters['categories'])) {
        $categoryIds = array_values(array_filter(array_map('intval', $filters['categories'])));
        if (!empty($categoryIds)) {
            $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
            $sql .= " AND category_id IN ($placeholders)";
            $params = array_merge($params, $categoryIds);
        }
    } elseif (!empty($filters['category'])) {
        $sql .= " AND category_id = ?";
        $params[] = $filters['category'];
    }

    if (!empty($filters['min_price'])) {
        $sql .= " AND price >= ?";
        $params[] = $filters['min_price'];
    }

    if (!empty($filters['max_price'])) {
        $sql .= " AND price <= ?";
        $params[] = $filters['max_price'];
    }

    if (!empty($filters['in_stock'])) {
        $sql .= " AND stock_quantity > 0";
    }

    if (!empty($filters['min_rating'])) {
        $sql .= " AND rating >= ?";
        $params[] = $filters['min_rating'];
    }

    if (!empty($filters['max_rating'])) {
        $sql .= " AND rating <= ?";
        $params[] = $filters['max_rating'];
    }

    if (!empty($filters['min_reviews'])) {
        $sql .= " AND review_count >= ?";
        $params[] = $filters['min_reviews'];
    }

    $sort = isset($filters['sort']) ? $filters['sort'] : 'created_at';
    $order = isset($filters['order']) ? $filters['order'] : 'DESC';

    $validSorts = ['name', 'price', 'rating', 'review_count', 'created_at'];
    $validOrders = ['ASC', 'DESC'];
    if (!in_array($sort, $validSorts)) { $sort = 'created_at'; }
    if (!in_array($order, $validOrders)) { $order = 'DESC'; }

    $sql .= " ORDER BY $sort $order";

    // Pagination (inline validated integers for MySQL/MariaDB compatibility)
    $page = isset($filters['page']) ? max(1, (int)$filters['page']) : 1;
    $perPage = isset($filters['per_page']) ? max(1, (int)$filters['per_page']) : 24;
    $offset = ($page - 1) * $perPage;
    $sql .= " LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Count total products for pagination with same filters
function countProductsWithRatingEnhanced($query, $filters = []) {
    global $pdo;

    $sql = "SELECT COUNT(*) as cnt FROM products WHERE status = 'active'";
    $params = [];

    if (!empty($query)) {
        $sql .= " AND (name LIKE ? OR description LIKE ?)";
        $params[] = "%$query%";
        $params[] = "%$query%";
    }

    if (!empty($filters['categories']) && is_array($filters['categories'])) {
        $categoryIds = array_values(array_filter(array_map('intval', $filters['categories'])));
        if (!empty($categoryIds)) {
            $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
            $sql .= " AND category_id IN ($placeholders)";
            $params = array_merge($params, $categoryIds);
        }
    } elseif (!empty($filters['category'])) {
        $sql .= " AND category_id = ?";
        $params[] = $filters['category'];
    }

    if (!empty($filters['min_price'])) {
        $sql .= " AND price >= ?";
        $params[] = $filters['min_price'];
    }

    if (!empty($filters['max_price'])) {
        $sql .= " AND price <= ?";
        $params[] = $filters['max_price'];
    }

    if (!empty($filters['in_stock'])) {
        $sql .= " AND stock_quantity > 0";
    }

    if (!empty($filters['min_rating'])) {
        $sql .= " AND rating >= ?";
        $params[] = $filters['min_rating'];
    }

    if (!empty($filters['max_rating'])) {
        $sql .= " AND rating <= ?";
        $params[] = $filters['max_rating'];
    }

    if (!empty($filters['min_reviews'])) {
        $sql .= " AND review_count >= ?";
        $params[] = $filters['min_reviews'];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int)($row ? $row['cnt'] : 0);
}

// Get products by seller with rating filters
function getSellerProductsWithRating($sellerId, $filters = []) {
    global $pdo;
    
    $sql = "SELECT * FROM products WHERE seller_id = ? AND status = 'active'";
    $params = [$sellerId];
    
    // Add rating filters
    if (!empty($filters['min_rating'])) {
        $sql .= " AND rating >= ?";
        $params[] = $filters['min_rating'];
    }
    
    if (!empty($filters['max_rating'])) {
        $sql .= " AND rating <= ?";
        $params[] = $filters['max_rating'];
    }
    
    if (!empty($filters['min_reviews'])) {
        $sql .= " AND review_count >= ?";
        $params[] = $filters['min_reviews'];
    }
    
    // Add sorting
    $sort = isset($filters['sort']) ? $filters['sort'] : 'created_at';
    $order = isset($filters['order']) ? $filters['order'] : 'DESC';
    
    $validSorts = ['name', 'price', 'rating', 'review_count', 'created_at'];
    $validOrders = ['ASC', 'DESC'];
    
    if (in_array($sort, $validSorts) && in_array($order, $validOrders)) {
        $sql .= " ORDER BY $sort $order";
    } else {
        $sql .= " ORDER BY created_at DESC";
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get average rating distribution for a seller (for analytics)
function getSellerRatingDistribution($sellerId) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT 
        COUNT(*) as total_products,
        AVG(rating) as avg_rating,
        SUM(review_count) as total_reviews,
        COUNT(CASE WHEN rating >= 4 THEN 1 END) as highly_rated,
        COUNT(CASE WHEN rating >= 3 AND rating < 4 THEN 1 END) as medium_rated,
        COUNT(CASE WHEN rating < 3 THEN 1 END) as low_rated
        FROM products 
        WHERE seller_id = ? AND status = 'active'");
    $stmt->execute([$sellerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/* -----------------------------
   PRODUCT REVIEWS
------------------------------ */

// Add product review
function addProductReview($productId, $rating, $reviewText, $orderId = null) {
    if (!isLoggedIn()) {
        return false;
    }
    
    global $pdo;
    $userId = $_SESSION['user_id'];
    
    // Verify user purchased this product in a completed order
    $stmt = $pdo->prepare("SELECT oi.order_id 
                          FROM order_items oi 
                          JOIN orders o ON oi.order_id = o.id 
                          WHERE o.user_id = ? AND oi.product_id = ? AND o.status IN ('delivered', 'completed')
                          " . ($orderId ? "AND o.id = ?" : "") . "
                          ORDER BY o.completed_at DESC
                          LIMIT 1");
    if ($orderId) {
        $stmt->execute([$userId, $productId, $orderId]);
    } else {
        $stmt->execute([$userId, $productId]);
    }
    $orderData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$orderData) {
        return false;
    }
    
    $reviewOrderId = $orderData['order_id'];
    
    // Check if already reviewed THIS specific order
    $stmt = $pdo->prepare("SELECT * FROM product_reviews WHERE user_id = ? AND product_id = ? AND order_id = ?");
    $stmt->execute([$userId, $productId, $reviewOrderId]);
    $existingReview = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingReview) {
        return false;
    }
    
    $stmt = $pdo->prepare("INSERT INTO product_reviews (product_id, user_id, rating, review_text, order_id) 
                          VALUES (?, ?, ?, ?, ?)");
    $result = $stmt->execute([$productId, $userId, $rating, $reviewText, $reviewOrderId]);
    
    if ($result) {
        updateProductRating($productId);
        
        // Send notification to seller
        require_once __DIR__ . '/seller_notification_functions.php';
        
        // Get product details and seller ID
        $productStmt = $pdo->prepare("SELECT p.name, p.seller_id, u.first_name, u.last_name, u.username 
                                       FROM products p 
                                       JOIN users u ON p.seller_id = u.id 
                                       WHERE p.id = ?");
        $productStmt->execute([$productId]);
        $productInfo = $productStmt->fetch(PDO::FETCH_ASSOC);
        
        // Get customer name
        $customerStmt = $pdo->prepare("SELECT first_name, last_name, username FROM users WHERE id = ?");
        $customerStmt->execute([$userId]);
        $customerInfo = $customerStmt->fetch(PDO::FETCH_ASSOC);
        $customerName = trim($customerInfo['first_name'] . ' ' . $customerInfo['last_name']) ?: $customerInfo['username'];
        
        if ($productInfo) {
            $stars = str_repeat('⭐', $rating);
            createSellerNotification(
                $productInfo['seller_id'],
                'New Product Review',
                $customerName . ' left a ' . $rating . '-star review on "' . $productInfo['name'] . '"',
                'info',
                'view-products.php?product_id=' . $productId
            );
        }
        
        return true;
    }
    
    return false;
}

// Update product rating
function updateProductRating($productId) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as review_count 
                          FROM product_reviews 
                          WHERE product_id = ?");
    $stmt->execute([$productId]);
    $ratingData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("UPDATE products SET rating = ?, review_count = ? WHERE id = ?");
    $stmt->execute([$ratingData['avg_rating'], $ratingData['review_count'], $productId]);
}

// Add to includes/functions.php

// Get sales data by time period
function getSalesDataByPeriod($sellerId, $period = 'monthly') {
    global $pdo;
    
    switch ($period) {
        case 'weekly':
            $stmt = $pdo->prepare("SELECT 
                                YEARWEEK(o.created_at) as week,
                                CONCAT('Week ', WEEK(o.created_at), ' ', YEAR(o.created_at)) as label,
                                SUM(oi.quantity * oi.price) as revenue
                                FROM orders o
                                JOIN order_items oi ON o.id = oi.order_id
                                JOIN products p ON oi.product_id = p.id
                                WHERE p.seller_id = ? AND o.status = 'delivered'
                                AND o.created_at >= DATE_SUB(NOW(), INTERVAL 8 WEEK)
                                GROUP BY YEARWEEK(o.created_at)
                                ORDER BY week DESC
                                LIMIT 8");
            break;
            
        case '6months':
            $stmt = $pdo->prepare("SELECT 
                                YEAR(o.created_at) as year,
                                MONTH(o.created_at) as month,
                                CONCAT(MONTHNAME(o.created_at), ' ', YEAR(o.created_at)) as label,
                                SUM(oi.quantity * oi.price) as revenue
                                FROM orders o
                                JOIN order_items oi ON o.id = oi.order_id
                                JOIN products p ON oi.product_id = p.id
                                WHERE p.seller_id = ? AND o.status = 'delivered'
                                AND o.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                                GROUP BY YEAR(o.created_at), MONTH(o.created_at)
                                ORDER BY year DESC, month DESC
                                LIMIT 6");
            break;
            
        case 'yearly':
            $stmt = $pdo->prepare("SELECT 
                                YEAR(o.created_at) as year,
                                CONCAT('Year ', YEAR(o.created_at)) as label,
                                SUM(oi.quantity * oi.price) as revenue
                                FROM orders o
                                JOIN order_items oi ON o.id = oi.order_id
                                JOIN products p ON oi.product_id = p.id
                                WHERE p.seller_id = ? AND o.status = 'delivered'
                                AND o.created_at >= DATE_SUB(NOW(), INTERVAL 3 YEAR)
                                GROUP BY YEAR(o.created_at)
                                ORDER BY year DESC
                                LIMIT 3");
            break;
            
        case 'monthly':
        default:
            $stmt = $pdo->prepare("SELECT 
                                YEAR(o.created_at) as year,
                                MONTH(o.created_at) as month,
                                CONCAT(MONTHNAME(o.created_at), ' ', YEAR(o.created_at)) as label,
                                SUM(oi.quantity * oi.price) as revenue
                                FROM orders o
                                JOIN order_items oi ON o.id = oi.order_id
                                JOIN products p ON oi.product_id = p.id
                                WHERE p.seller_id = ? AND o.status = 'delivered'
                                AND o.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                                GROUP BY YEAR(o.created_at), MONTH(o.created_at)
                                ORDER BY year DESC, month DESC
                                LIMIT 6");
            break;
    }
    
    $stmt->execute([$sellerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get total sales statistics
function getTotalSalesStats($sellerId) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT 
                          COUNT(DISTINCT o.id) as total_orders,
                          SUM(oi.quantity * oi.price) as total_revenue,
                          AVG(oi.quantity * oi.price) as avg_order_value,
                          COUNT(DISTINCT o.user_id) as total_customers
                          FROM orders o
                          JOIN order_items oi ON o.id = oi.order_id
                          JOIN products p ON oi.product_id = p.id
                          WHERE p.seller_id = ? AND o.status = 'delivered'");
    $stmt->execute([$sellerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getSessionCart() {
    return isset($_SESSION['cart']) ? $_SESSION['cart'] : [];
}

// Get cart count from session
function getSessionCartCount() {
    $cart = getSessionCart();
    $count = 0;
    foreach ($cart as $item) {
        $count += $item['quantity'];
    }
    return $count;
}

// Get cart total from session
function getSessionCartTotal() {
    $cart = getSessionCart();
    $total = 0;
    foreach ($cart as $item) {
        $total += $item['price'] * $item['quantity'];
    }
    return number_format($total, 2);
}

// Update cart item quantity in session
function updateSessionCartQuantity($productId, $quantity) {
    if (!isset($_SESSION['cart'])) {
        return ['success' => false, 'message' => 'Cart is empty'];
    }
    
    if ($quantity <= 0) {
        // Remove item from cart
        unset($_SESSION['cart'][$productId]);
        return ['success' => true, 'message' => 'Item removed from cart'];
    }
    
    if (!isset($_SESSION['cart'][$productId])) {
        return ['success' => false, 'message' => 'Item not found in cart'];
    }
    
    // Check stock availability
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT stock_quantity FROM products WHERE id = ? AND status = 'active'");
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            return ['success' => false, 'message' => 'Product not found'];
        }
        
        if ($quantity > $product['stock_quantity']) {
            return ['success' => false, 'message' => 'Insufficient stock available'];
        }
        
        $_SESSION['cart'][$productId]['quantity'] = $quantity;
        return ['success' => true, 'message' => 'Cart updated successfully'];
        
    } catch (PDOException $e) {
        error_log('Update session cart error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Database error occurred'];
    }
}

// Remove item from session cart
function removeFromSessionCart($productId) {
    if (isset($_SESSION['cart'][$productId])) {
        unset($_SESSION['cart'][$productId]);
        return true;
    }
    return false;
}

// Clear entire cart
function clearCart() {
    $_SESSION['cart'] = [];
    return true;
}

// Validate session cart against current stock and prices
function validateSessionCart() {
    if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
        return ['success' => true, 'message' => 'Cart is empty'];
    }
    
    global $pdo;
    $errors = [];
    $updates = [];
    
    try {
        foreach ($_SESSION['cart'] as $productId => $item) {
            $stmt = $pdo->prepare("SELECT id, name, price, stock_quantity, status FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                $errors[] = "Product '{$item['name']}' is no longer available";
                unset($_SESSION['cart'][$productId]);
                continue;
            }
            
            if ($product['status'] !== 'active') {
                $errors[] = "Product '{$item['name']}' is no longer available";
                unset($_SESSION['cart'][$productId]);
                continue;
            }
            
            // Check if price changed
            if ($item['price'] != $product['price']) {
                $_SESSION['cart'][$productId]['price'] = $product['price'];
                $updates[] = "Price updated for '{$item['name']}'";
            }
            
            // Check stock availability
            if ($item['quantity'] > $product['stock_quantity']) {
                if ($product['stock_quantity'] > 0) {
                    $_SESSION['cart'][$productId]['quantity'] = $product['stock_quantity'];
                    $updates[] = "Quantity adjusted for '{$item['name']}' - only {$product['stock_quantity']} available";
                } else {
                    $errors[] = "Product '{$item['name']}' is out of stock";
                    unset($_SESSION['cart'][$productId]);
                }
            }
        }
        
        $messages = array_merge($errors, $updates);
        $hasErrors = !empty($errors);
        
        return [
            'success' => !$hasErrors,
            'message' => empty($messages) ? 'Cart is valid' : implode('; ', $messages),
            'errors' => $errors,
            'updates' => $updates
        ];
        
    } catch (PDOException $e) {
        error_log('Cart validation error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Error validating cart'];
    }
}

// Convert session cart to display format (similar to database cart)
function getSessionCartForDisplay() {
    if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
        return [];
    }
    
    $displayCart = [];
    foreach ($_SESSION['cart'] as $productId => $item) {
        $displayCart[] = [
            'product_id' => $productId,
            'name' => $item['name'],
            'price' => $item['price'],
            'quantity' => $item['quantity'],
            'image_url' => isset($item['image_url']) ? $item['image_url'] : '',
            'total' => $item['price'] * $item['quantity']
        ];
    }
    
    return $displayCart;
}

// Merge session cart with database cart (useful for login)
function mergeSessionCartWithDatabase() {
    if (!isLoggedIn() || !isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
        return;
    }
    
    global $pdo;
    $userId = $_SESSION['user_id'];
    
    try {
        $pdo->beginTransaction();
        
        foreach ($_SESSION['cart'] as $productId => $sessionItem) {
            // Check if item already exists in database cart
            $stmt = $pdo->prepare("SELECT quantity FROM cart WHERE user_id = ? AND product_id = ?");
            $stmt->execute([$userId, $productId]);
            $dbItem = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($dbItem) {
                // Update quantity (combine session and database quantities)
                $newQuantity = $dbItem['quantity'] + $sessionItem['quantity'];
                
                // Check stock limit
                $stmt = $pdo->prepare("SELECT stock_quantity FROM products WHERE id = ? AND status = 'active'");
                $stmt->execute([$productId]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($product && $newQuantity > $product['stock_quantity']) {
                    $newQuantity = $product['stock_quantity'];
                }
                
                $stmt = $pdo->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?");
                $stmt->execute([$newQuantity, $userId, $productId]);
            } else {
                // Insert new item - added_at has DEFAULT current_timestamp(), so we don't need to specify it
                $stmt = $pdo->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)");
                $stmt->execute([$userId, $productId, $sessionItem['quantity']]);
            }
        }
        
        $pdo->commit();
        
        // Clear session cart after merging
        $_SESSION['cart'] = [];
        
    } catch (PDOException $e) {
        $pdo->rollback();
        error_log('Cart merge error: ' . $e->getMessage());
    }
}

/* -----------------------------
   MULTI-SELLER CHECKOUT FUNCTIONS
------------------------------ */

// Get cart items grouped by seller
function getCartItemsGroupedBySeller() {
    if (!isLoggedIn()) {
        error_log('getCartItemsGroupedBySeller - User not logged in');
        return [];
    }
    
    global $pdo;
    $userId = $_SESSION['user_id'];
    
    try {
        // First, let's check if there are any cart items at all
        $checkStmt = $pdo->prepare("SELECT COUNT(*) as count FROM cart WHERE user_id = ?");
        $checkStmt->execute([$userId]);
        $cartCount = $checkStmt->fetch(PDO::FETCH_ASSOC);
        error_log('getCartItemsGroupedBySeller - Cart has ' . ($cartCount['count'] ?? 0) . ' items in database for user ' . $userId);
        
        // Get all cart items with product and seller info in one query
        // Use LEFT JOIN for products to see if products are missing
        $stmt = $pdo->prepare("
            SELECT 
                c.id as cart_id,
                c.product_id,
                c.quantity,
                c.created_at as cart_created_at,
                p.id as product_exists,
                p.name,
                p.price,
                p.image_url,
                p.stock_quantity,
                p.status,
                p.seller_id,
                u.username as seller_username,
                u.first_name as seller_first_name,
                u.last_name as seller_last_name,
                u.display_name as seller_display_name
            FROM cart c
            LEFT JOIN products p ON c.product_id = p.id
            LEFT JOIN users u ON p.seller_id = u.id
            WHERE c.user_id = ?
            ORDER BY p.seller_id, p.name
        ");
        $stmt->execute([$userId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log('getCartItemsGroupedBySeller - Query returned ' . count($results) . ' items for user ' . $userId);
        
        if (empty($results)) {
            error_log('getCartItemsGroupedBySeller - No cart items found in query result');
            return [];
        }
        
        // Filter out items where product doesn't exist or is inactive
        $validResults = [];
        foreach ($results as $row) {
            // Check if product exists (product_exists will be NULL if LEFT JOIN found no product)
            // product_exists is actually p.id, so if it's NULL, the product doesn't exist
            if ($row['product_exists'] === null || $row['product_exists'] === '') {
                error_log('getCartItemsGroupedBySeller - Product ' . $row['product_id'] . ' does not exist (product_exists is NULL/empty), skipping');
                continue;
            }
            
            // If product exists but has no name, try to get it from database or use placeholder
            if (empty($row['name'])) {
                error_log('getCartItemsGroupedBySeller - Product ' . $row['product_id'] . ' has no name, attempting to fetch from database');
                // Try to fetch product name directly
                $nameStmt = $pdo->prepare("SELECT name, price, image_url, stock_quantity, status, seller_id FROM products WHERE id = ?");
                $nameStmt->execute([$row['product_id']]);
                $productData = $nameStmt->fetch(PDO::FETCH_ASSOC);
                if ($productData) {
                    // Update row with fetched data
                    $row['name'] = $productData['name'] ?? 'Unknown Product';
                    $row['price'] = $productData['price'] ?? 0;
                    $row['image_url'] = $productData['image_url'] ?? 'images/placeholder.jpg';
                    $row['stock_quantity'] = $productData['stock_quantity'] ?? 0;
                    $row['status'] = $productData['status'] ?? 'inactive';
                    $row['seller_id'] = $productData['seller_id'] ?? null;
                } else {
                    error_log('getCartItemsGroupedBySeller - Could not fetch product ' . $row['product_id'] . ' from database, skipping');
                    continue;
                }
            }
            
            // Check if product is active (warn but don't skip - let user see inactive products)
            if (isset($row['status']) && $row['status'] !== 'active') {
                error_log('getCartItemsGroupedBySeller - Product ' . $row['product_id'] . ' is not active (status: ' . ($row['status'] ?? 'NULL') . '), but including anyway');
                // Don't skip - let user see it and handle in UI
            }
            
            $validResults[] = $row;
        }
        
        if (empty($validResults)) {
            error_log('getCartItemsGroupedBySeller - No valid cart items after filtering. Total items checked: ' . count($results));
            // Log first few items for debugging
            if (!empty($results)) {
                error_log('getCartItemsGroupedBySeller - First item sample: ' . json_encode($results[0]));
            }
            return [];
        }
        
        error_log('getCartItemsGroupedBySeller - After filtering: ' . count($validResults) . ' valid items out of ' . count($results) . ' total');
        $results = $validResults;
        
        // Group by seller
        $groupedItems = [];
        
        foreach ($results as $row) {
            $sellerId = $row['seller_id'];
            
            // Handle missing seller - but don't skip, use a default seller ID
            if (!$sellerId) {
                error_log('getCartItemsGroupedBySeller - Product ' . $row['product_id'] . ' has no seller_id, using default');
                $sellerId = 0; // Use 0 as default seller ID for orphaned products
            }
            
            // Initialize seller group if not exists
            if (!isset($groupedItems[$sellerId])) {
                $displayName = $row['seller_display_name'] 
                    ?? trim(($row['seller_first_name'] ?? '') . ' ' . ($row['seller_last_name'] ?? ''))
                    ?: ($row['seller_username'] ?? 'Unknown Seller');
                
                // If seller_id is 0 or NULL, use a default display name
                if ($sellerId == 0 || !$sellerId) {
                    $displayName = 'Unknown Seller';
                }
                
                $groupedItems[$sellerId] = [
                    'seller_id' => $sellerId,
                    'seller_name' => $row['seller_username'] ?? 'Unknown Seller',
                    'seller_display_name' => $displayName,
                    'items' => [],
                    'subtotal' => 0,
                    'item_count' => 0
                ];
            }
            
            // Add item to seller group
            $itemTotal = (float)$row['price'] * (int)$row['quantity'];
            
            $groupedItems[$sellerId]['items'][] = [
                'product_id' => $row['product_id'],
                'name' => $row['name'],
                'price' => (float)$row['price'],
                'quantity' => (int)$row['quantity'],
                'image_url' => $row['image_url'] ?? 'images/placeholder.jpg',
                'stock_quantity' => (int)$row['stock_quantity'],
                'status' => $row['status']
            ];
            
            $groupedItems[$sellerId]['subtotal'] += $itemTotal;
            $groupedItems[$sellerId]['item_count'] += (int)$row['quantity'];
        }
        
        error_log('getCartItemsGroupedBySeller - Grouped into ' . count($groupedItems) . ' seller groups');
        
        return $groupedItems;
        
    } catch (Exception $e) {
        error_log('getCartItemsGroupedBySeller - Exception: ' . $e->getMessage());
        return [];
    }
}

// Get cart total across all sellers
function getMultiSellerCartTotal() {
    $groupedItems = getCartItemsGroupedBySeller();
    $total = 0;
    
    foreach ($groupedItems as $sellerGroup) {
        $total += $sellerGroup['subtotal'];
    }
    
    return $total;
}

// Validate cart for multi-seller checkout
function validateMultiSellerCart() {
    if (!isLoggedIn()) {
        return ['success' => false, 'message' => 'Not logged in'];
    }
    
    global $pdo;
    $userId = $_SESSION['user_id'];
    
    // CRITICAL FIX: Use same robust LEFT JOIN query as checkout page
    // Check database directly first - this is the authoritative source
    $rawCartCountStmt = $pdo->prepare("SELECT COUNT(*) as count FROM cart WHERE user_id = ?");
    $rawCartCountStmt->execute([$userId]);
    $rawCartCount = (int)($rawCartCountStmt->fetchColumn() ?? 0);
    
    // If database has items, cart is NOT empty - period
    if ($rawCartCount === 0) {
        return ['success' => false, 'message' => 'Cart is empty'];
    }
    
    // Now get grouped items using LEFT JOIN (same as checkout page)
    $cartItemsStmt = $pdo->prepare("
        SELECT 
            c.id as cart_id,
            c.product_id,
            c.quantity,
            p.id AS product_exists,
            p.name,
            p.price,
            p.image_url,
            p.stock_quantity,
            p.status,
            p.seller_id,
            COALESCE(u.display_name, CONCAT(u.first_name, ' ', u.last_name), u.username, 'Unknown Seller') as seller_display_name
        FROM cart c
        LEFT JOIN products p ON c.product_id = p.id
        LEFT JOIN users u ON p.seller_id = u.id
        WHERE c.user_id = ?
        ORDER BY p.seller_id IS NULL, p.seller_id, p.name
    ");
    $cartItemsStmt->execute([$userId]);
    $allCartItems = $cartItemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build groupedItems from cart items
    $groupedItems = [];
    foreach ($allCartItems as $row) {
        $productId = (int)($row['product_id'] ?? 0);
        $exists = !empty($row['product_exists']);
        $name = $row['name'] ?? ($exists ? 'Unnamed product' : 'Product no longer available');
        $price = isset($row['price']) && $row['price'] !== null ? (float)$row['price'] : 0.0;
        $image = $row['image_url'] ?? 'images/placeholder.jpg';
        $stock = isset($row['stock_quantity']) ? (int)$row['stock_quantity'] : 0;
        $status = $row['status'] ?? ($exists ? 'active' : 'missing');
        $sellerId = isset($row['seller_id']) ? (int)$row['seller_id'] : 0;
        $sellerName = $row['seller_display_name'] ?? 'Unknown Seller';
        $qty = max(0, (int)$row['quantity']);
        
        if (!isset($groupedItems[$sellerId])) {
            $groupedItems[$sellerId] = [
                'seller_id' => $sellerId,
                'seller_display_name' => $sellerName,
                'items' => [],
                'subtotal' => 0,
                'item_count' => 0
            ];
        }
        
        $itemTotal = $price * $qty;
        $groupedItems[$sellerId]['items'][] = [
            'product_id' => $productId,
            'name' => $name,
            'price' => $price,
            'quantity' => $qty,
            'image_url' => $image,
            'stock_quantity' => $stock,
            'status' => $status,
            'product_exists' => $exists
        ];
        
        $groupedItems[$sellerId]['subtotal'] += $itemTotal;
        $groupedItems[$sellerId]['item_count'] += $qty;
    }
    
    // Final check: if database has items but groupedItems is empty, something is wrong
    if (empty($groupedItems) && $rawCartCount > 0) {
        error_log('VALIDATE CART - ERROR: Database has ' . $rawCartCount . ' items but groupedItems is empty!');
        // Don't fail validation - database says cart has items, so it's valid
        // Return success but with a warning
        return ['success' => true, 'message' => 'Cart validation passed (items may need review)'];
    }
    
    if (empty($groupedItems)) {
        return ['success' => false, 'message' => 'Cart is empty'];
    }
    
    $errors = [];
    
    foreach ($groupedItems as $sellerId => $sellerGroup) {
        foreach ($sellerGroup['items'] as $item) {
            if ($item['stock_quantity'] < $item['quantity']) {
                $errors[] = "Insufficient stock for {$item['name']} from {$sellerGroup['seller_display_name']}. Available: {$item['stock_quantity']}, Requested: {$item['quantity']}";
            }
        }
    }
    
    if (!empty($errors)) {
        return ['success' => false, 'message' => implode('; ', $errors)];
    }
    
    return ['success' => true, 'message' => 'Cart is valid'];
}

// Process multi-seller checkout
// $createOrdersImmediately: true for COD (create orders now), false for PayMongo (create orders after payment)
// Process multi-seller checkout
// $createOrdersImmediately: true for COD (create orders now), false for PayMongo (create orders after payment)
function processMultiSellerCheckout($shippingAddress, $paymentMethod, $customerName = '', $customerEmail = '', $customerPhone = '', $isBuyNow = false, $createOrdersImmediately = true) {
    if (!isLoggedIn()) {
        return ['success' => false, 'message' => 'Not logged in'];
    }
    
    global $pdo;
    $userId = $_SESSION['user_id'];
    
    // CRITICAL: Check if we have pre-calculated grouped items from checkout page (includes discount)
    if (isset($_SESSION['checkout_grouped_items'])) {
        $groupedItems = $_SESSION['checkout_grouped_items'];
        $grandTotal = isset($_SESSION['checkout_grand_total']) ? $_SESSION['checkout_grand_total'] : 0;
        
        // Recalculate grand total from grouped items as safety check
        $calculatedTotal = 0;
        foreach ($groupedItems as $sg) {
            $calculatedTotal += $sg['subtotal'];
        }
        
        // Use the calculated total if session total seems wrong
        if ($grandTotal == 0 || abs($calculatedTotal - $grandTotal) > 0.01) {
            $grandTotal = $calculatedTotal;
        }
        
        error_log('CHECKOUT - Using pre-calculated items from session (with discount applied)');
        error_log('CHECKOUT - Grand total: ₱' . number_format($grandTotal, 2));
        
        // Clear the session data after using it
        unset($_SESSION['checkout_grouped_items']);
        unset($_SESSION['checkout_grand_total']);
        
        // Skip to checkout processing
        goto process_checkout;
    }
    
    // HANDLE BUY NOW - Get items from session instead of cart
    // CRITICAL: Check BOTH isBuyNow parameter AND session data to ensure we're really in buy_now mode
    $isReallyBuyNow = $isBuyNow && isset($_SESSION['buy_now_data']) && isset($_SESSION['buy_now_active']);
    
    if ($isReallyBuyNow) {
        error_log('Checkout - Processing Buy Now from session (STRICT MODE)');
        error_log('Checkout - isBuyNow param: ' . ($isBuyNow ? 'YES' : 'NO'));
        error_log('Checkout - buy_now_data exists: ' . (isset($_SESSION['buy_now_data']) ? 'YES' : 'NO'));
        error_log('Checkout - buy_now_active exists: ' . (isset($_SESSION['buy_now_active']) ? 'YES' : 'NO'));
        
        $buyNowData = $_SESSION['buy_now_data'];
        $sellerId = $buyNowData['seller']['seller_id'];
        
        // CRITICAL: Build proper item structure matching what checkout expects
        $buyNowProduct = $buyNowData['product'];
        $buyNowItem = [
            'product_id' => $buyNowProduct['product_id'] ?? $buyNowProduct['id'],
            'name' => $buyNowProduct['name'],
            'price' => (float)$buyNowProduct['price'],
            'quantity' => (int)$buyNowProduct['quantity'],
            'image_url' => $buyNowProduct['image_url'] ?? 'images/placeholder.jpg',
            'stock_quantity' => (int)$buyNowProduct['stock_quantity'],
            'status' => $buyNowProduct['status'] ?? 'active',
            'product_exists' => true
        ];
        
        $groupedItems = [
            $sellerId => [
                'seller_id' => $sellerId,
                'seller_name' => $buyNowData['seller']['seller_name'],
                'seller_display_name' => $buyNowData['seller']['seller_display_name'],
                'items' => [
                    $buyNowItem
                ],
                'subtotal' => (float)$buyNowData['total'],
                'item_count' => (int)$buyNowData['quantity']
            ]
        ];
        
        $grandTotal = (float)$buyNowData['total'];
        
        error_log('Checkout - Buy Now groupedItems created: ' . json_encode($groupedItems));
        error_log('Checkout - Buy Now grandTotal: ' . $grandTotal);
        error_log('Checkout - IMPORTANT: Cart table is COMPLETELY IGNORED for Buy Now');
        
    } else {
        // Regular checkout - validate cart
        if ($isBuyNow) {
            error_log('Checkout - WARNING: isBuyNow=true but session data missing! Falling back to cart.');
        }
        $validation = validateMultiSellerCart();
        if (!$validation['success']) {
            return $validation;
        }
        
        // CRITICAL FIX: Use same robust LEFT JOIN logic as checkout page
        // Don't use getCartItemsGroupedBySeller() - it might use INNER JOIN
        $cartItemsStmt = $pdo->prepare("
            SELECT 
                c.id as cart_id,
                c.product_id,
                c.quantity,
                p.id AS product_exists,
                p.name,
                p.price,
                p.image_url,
                p.stock_quantity,
                p.status,
                p.seller_id,
                COALESCE(u.display_name, CONCAT(u.first_name, ' ', u.last_name), u.username, 'Unknown Seller') as seller_display_name
            FROM cart c
            LEFT JOIN products p ON c.product_id = p.id
            LEFT JOIN users u ON p.seller_id = u.id
            WHERE c.user_id = ?
            ORDER BY p.seller_id IS NULL, p.seller_id, p.name
        ");
        $cartItemsStmt->execute([$userId]);
        $allCartItems = $cartItemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log('PROCESS CHECKOUT - LEFT JOIN query returned ' . count($allCartItems) . ' rows for user ' . $userId);
        
        // Check raw cart count as authoritative source
        $rawCartCountStmt = $pdo->prepare("SELECT COUNT(*) as count FROM cart WHERE user_id = ?");
        $rawCartCountStmt->execute([$userId]);
        $rawCartCount = (int)($rawCartCountStmt->fetchColumn() ?? 0);
        error_log('PROCESS CHECKOUT - Raw cart count: ' . $rawCartCount);
        
        // If database has items, build groupedItems
        if ($rawCartCount > 0) {
            $groupedItems = [];
            $grandTotal = 0;
            
            foreach ($allCartItems as $row) {
                $productId = (int)($row['product_id'] ?? 0);
                $exists = !empty($row['product_exists']);
                $name = $row['name'] ?? ($exists ? 'Unnamed product' : 'Product no longer available');
                $price = isset($row['price']) && $row['price'] !== null ? (float)$row['price'] : 0.0;
                $image = $row['image_url'] ?? 'images/placeholder.jpg';
                $stock = isset($row['stock_quantity']) ? (int)$row['stock_quantity'] : 0;
                $status = $row['status'] ?? ($exists ? 'active' : 'missing');
                $sellerId = isset($row['seller_id']) ? (int)$row['seller_id'] : 0;
                $sellerName = $row['seller_display_name'] ?? 'Unknown Seller';
                $qty = max(0, (int)$row['quantity']);
                
                if (!isset($groupedItems[$sellerId])) {
                    $groupedItems[$sellerId] = [
                        'seller_id' => $sellerId,
                        'seller_display_name' => $sellerName,
                        'items' => [],
                        'subtotal' => 0,
                        'item_count' => 0
                    ];
                }
                
                $itemTotal = $price * $qty;
                $groupedItems[$sellerId]['items'][] = [
                    'product_id' => $productId,
                    'name' => $name,
                    'price' => $price,
                    'quantity' => $qty,
                    'image_url' => $image,
                    'stock_quantity' => $stock,
                    'status' => $status,
                    'product_exists' => $exists
                ];
                
                $groupedItems[$sellerId]['subtotal'] += $itemTotal;
                $groupedItems[$sellerId]['item_count'] += $qty;
                $grandTotal += $itemTotal;
            }
            
            error_log('PROCESS CHECKOUT - After grouping: ' . count($groupedItems) . ' seller groups, grandTotal: ' . $grandTotal);
        } else {
            $groupedItems = [];
            $grandTotal = 0;
        }
    }
    
    // FINAL CHECK: If database has items but groupedItems is empty, that's a bug - but don't fail
    // Check raw cart count as final authority
    if (empty($groupedItems) && !$isBuyNow) {
        // Double-check database
        $finalCheck = $pdo->prepare("SELECT COUNT(*) as count FROM cart WHERE user_id = ?");
        $finalCheck->execute([$userId]);
        $finalCartCount = (int)($finalCheck->fetchColumn() ?? 0);
        
        if ($finalCartCount > 0) {
            error_log('PROCESS CHECKOUT - ERROR: Database has ' . $finalCartCount . ' items but groupedItems is empty! This is a bug.');
            // Don't fail - try to recover by using emergency query
            $emergencyStmt = $pdo->prepare("
                SELECT c.product_id, c.quantity, p.name, p.price, p.image_url, p.stock_quantity, p.status, p.seller_id,
                       COALESCE(u.display_name, CONCAT(u.first_name, ' ', u.last_name), u.username, 'Unknown Seller') as seller_display_name
                FROM cart c
                INNER JOIN products p ON c.product_id = p.id
                LEFT JOIN users u ON p.seller_id = u.id
                WHERE c.user_id = ?
            ");
            $emergencyStmt->execute([$userId]);
            $emergencyItems = $emergencyStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $groupedItems = [];
            $grandTotal = 0;
            foreach ($emergencyItems as $item) {
                $sId = (int)($item['seller_id'] ?? 0);
                if (!isset($groupedItems[$sId])) {
                    $groupedItems[$sId] = [
                        'seller_id' => $sId,
                        'seller_display_name' => $item['seller_display_name'] ?? 'Unknown Seller',
                        'items' => [],
                        'subtotal' => 0,
                        'item_count' => 0
                    ];
                }
                $qty = max(0, (int)$item['quantity']);
                $price = isset($item['price']) ? (float)$item['price'] : 0.0;
                $itTotal = $price * $qty;
                $groupedItems[$sId]['items'][] = [
                    'product_id' => (int)$item['product_id'],
                    'name' => $item['name'] ?? 'Unknown Product',
                    'price' => $price,
                    'quantity' => $qty,
                    'image_url' => $item['image_url'] ?? 'images/placeholder.jpg',
                    'stock_quantity' => (int)$item['stock_quantity'],
                    'status' => $item['status'] ?? 'active',
                    'product_exists' => 1
                ];
                $groupedItems[$sId]['subtotal'] += $itTotal;
                $groupedItems[$sId]['item_count'] += $qty;
                $grandTotal += $itTotal;
            }
            error_log('PROCESS CHECKOUT - Emergency recovery: ' . count($groupedItems) . ' seller groups');
        }
    }
    
    // Only fail if database truly has no items
    if (empty($groupedItems)) {
        // Final check - if database has items, don't fail
        $finalCheck = $pdo->prepare("SELECT COUNT(*) as count FROM cart WHERE user_id = ?");
        $finalCheck->execute([$userId]);
        $finalCartCount = (int)($finalCheck->fetchColumn() ?? 0);
        
        if ($finalCartCount > 0) {
            error_log('PROCESS CHECKOUT - CRITICAL: Database has items but groupedItems empty - returning error but this should not happen');
            return ['success' => false, 'message' => 'Unable to process checkout. Please try again or contact support.'];
        }
        
        return ['success' => false, 'message' => 'No items to checkout'];
    }
    
    // Label for goto statement
    process_checkout:
    
    try {
        // Ensure primary keys are properly auto-incremented (before transaction)
        if (method_exists($pdo, 'query')) {
            ensureAutoIncrementPrimary('payment_transactions');
            ensureAutoIncrementPrimary('orders');
            ensureAutoIncrementPrimary('order_items');
        }
        
        // Start transaction after all setup
        $pdo->beginTransaction();
        error_log('Transaction started successfully for checkout');
        
        // 1. Create payment transaction record
        $stmt = $pdo->prepare("
            INSERT INTO payment_transactions (
                user_id, total_amount, payment_method, shipping_address,
                customer_name, customer_email, customer_phone
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId, $grandTotal, $paymentMethod, $shippingAddress,
            $customerName, $customerEmail, $customerPhone
        ]);
        $paymentTransactionId = $pdo->lastInsertId();
        
        $createdOrders = [];
        
        // 2. Create orders ONLY if createOrdersImmediately is true (COD payments)
        // For PayMongo payments, orders will be created after payment confirmation
        if ($createOrdersImmediately) {
            error_log('PROCESS CHECKOUT - Creating orders immediately (COD payment)');
            
            foreach ($groupedItems as $sellerId => $sellerGroup) {
                // Create order for this seller
                $stmt = $pdo->prepare("
                    INSERT INTO orders (
                        user_id, payment_transaction_id, seller_id, total_amount,
                        shipping_address, payment_method, status, payment_status
                    ) VALUES (?, ?, ?, ?, ?, ?, 'pending', 'pending')
                ");
                $stmt->execute([
                    $userId, $paymentTransactionId, $sellerId, $sellerGroup['subtotal'],
                    $shippingAddress, $paymentMethod
                ]);
                $orderId = $pdo->lastInsertId();
                
                // CREATE SELLER NOTIFICATION FOR NEW ORDER
                if (file_exists(__DIR__ . '/seller_notification_functions.php')) {
                    require_once __DIR__ . '/seller_notification_functions.php';
                    createSellerNotification(
                        $sellerId,
                        'New Order Received',
                        'You have a new order #' . str_pad($orderId, 6, '0', STR_PAD_LEFT) . ' totaling ₱' . number_format($sellerGroup['subtotal'], 2) . '. Please review and process it.',
                        'success',
                        'seller-order-details.php?order_id=' . $orderId
                    );
                }
                
                $createdOrders[] = [
                    'order_id' => $orderId,
                    'seller_id' => $sellerId,
                    'seller_name' => $sellerGroup['seller_display_name'],
                    'subtotal' => $sellerGroup['subtotal'],
                    'item_count' => $sellerGroup['item_count']
                ];
                
                // 3. Create order items for this seller's products
                foreach ($sellerGroup['items'] as $item) {
                    // CRITICAL: Store both original and discounted price
                    $itemPrice = $item['price']; // This is the final price (after discount if applied)
                    $originalPrice = isset($item['original_price']) ? $item['original_price'] : $item['price'];
                    
                    error_log('PROCESS CHECKOUT - Saving item: ' . $item['name'] . ' | Original: ₱' . number_format($originalPrice, 2) . ' | Final: ₱' . number_format($itemPrice, 2));
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO order_items (order_id, product_id, quantity, price, original_price) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$orderId, $item['product_id'], $item['quantity'], $itemPrice, $originalPrice]);
                    
                    // 4. Update product stock
                    $stmt = $pdo->prepare("
                        UPDATE products SET stock_quantity = stock_quantity - ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$item['quantity'], $item['product_id']]);
                }
            }
            
            // CRITICAL: For buy_now, do NOT touch cart table at all (buy_now items are NOT in cart)
            // For regular checkout, clear entire cart
            if ($isBuyNow && isset($_SESSION['buy_now_data'])) {
                // Buy Now: Do NOT remove anything from cart (buy_now items are session-only, not in cart table)
                // Just clear the buy_now session data
                error_log('Checkout - Buy Now: Not touching cart table (buy_now items are session-only)');
                
                // Clear buy now session data
                unset($_SESSION['buy_now_active']);
                unset($_SESSION['buy_now_data']);
                error_log('Checkout - Buy Now: Cleared buy_now session data, cart table untouched');
            } else {
                // Regular checkout: Clear entire cart
                $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
                $stmt->execute([$userId]);
                error_log('Checkout - Cleared entire cart for regular checkout');
            }
            
            $pdo->commit();
            
            // 6. Create notifications for each order (after transaction is committed)
            foreach ($createdOrders as $order) {
                $message = "Order #" . str_pad($order['order_id'], 6, '0', STR_PAD_LEFT) . " has been placed successfully with " . $order['seller_name'] . ".";
                $notificationResult = createOrderNotification($userId, $order['order_id'], $message, 'order_placed');
                if (!$notificationResult) {
                    error_log('Failed to create notification for order: ' . $order['order_id']);
                }
            }
        } else {
            // For PayMongo payments - just commit the payment transaction, don't create orders yet
            error_log('PROCESS CHECKOUT - Payment transaction created, orders will be created after payment confirmation');
            $pdo->commit();
        }

        return [
            'success' => true,
            'message' => $createOrdersImmediately ? 'Multi-seller checkout completed successfully' : 'Payment transaction created. Awaiting payment confirmation.',
            'payment_transaction_id' => $paymentTransactionId,
            'orders' => $createdOrders,
            'total_amount' => $grandTotal,
            'orders_created' => $createOrdersImmediately
        ];
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Multi-seller checkout error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Checkout failed: ' . $e->getMessage()];
    }
}

// Get payment transaction details
function getPaymentTransaction($paymentTransactionId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT pt.*, u.username, u.email as user_email
        FROM payment_transactions pt
        JOIN users u ON pt.user_id = u.id
        WHERE pt.id = ?
    ");
    $stmt->execute([$paymentTransactionId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get orders by payment transaction
function getOrdersByPaymentTransaction($paymentTransactionId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT o.*, u.username as seller_name, u.first_name as seller_first_name, u.last_name as seller_last_name
        FROM orders o
        JOIN users u ON o.seller_id = u.id
        WHERE o.payment_transaction_id = ?
        ORDER BY o.created_at
    ");
    $stmt->execute([$paymentTransactionId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get order items with product details for a payment transaction
function getOrderItemsByPaymentTransaction($paymentTransactionId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT 
            oi.*, 
            o.seller_id, 
            o.payment_transaction_id,
            p.name as product_name, 
            p.image_url,
            u.username as seller_name,
            u.first_name as seller_first_name,
            u.last_name as seller_last_name
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN products p ON oi.product_id = p.id
        JOIN users u ON o.seller_id = u.id
        WHERE o.payment_transaction_id = ?
        ORDER BY o.seller_id, p.name
    ");
    $stmt->execute([$paymentTransactionId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Update payment transaction status
function updatePaymentTransactionStatus($paymentTransactionId, $status, $paymentReference = null) {

    global $pdo;
    
    $stmt = $pdo->prepare("
        UPDATE payment_transactions 
        SET payment_status = ?, 
            payment_reference = ?,
            completed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE completed_at END
        WHERE id = ?
    ");
    return $stmt->execute([$status, $paymentReference, $status, $paymentTransactionId]);
}

// Get seller orders (for seller dashboard)
function getSellerOrders($sellerId, $limit = 50, $offset = 0) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT 
            o.*, 
            pt.total_amount as payment_total,
            pt.payment_status,
            pt.payment_method,
            u.username as customer_name,
            u.email as customer_email
        FROM orders o
        JOIN payment_transactions pt ON o.payment_transaction_id = pt.id
        JOIN users u ON o.user_id = u.id
        WHERE o.seller_id = ?
        ORDER BY o.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$sellerId, $limit, $offset]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get customer's multi-seller order history
function getCustomerMultiSellerOrders($userId, $limit = 50, $offset = 0) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT 
            pt.*,
            COUNT(o.id) as order_count,
            GROUP_CONCAT(DISTINCT CONCAT(u.first_name, ' ', u.last_name) SEPARATOR ', ') as seller_names
        FROM payment_transactions pt
        LEFT JOIN orders o ON pt.id = o.payment_transaction_id
        LEFT JOIN users u ON o.seller_id = u.id
        WHERE pt.user_id = ?
        GROUP BY pt.id
        ORDER BY pt.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$userId, $limit, $offset]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Create notification for order status updates
function createOrderNotification($userId, $orderId, $message, $type = 'order_update') {
    global $pdo;
    
    try {
        // Check if notifications table exists, if not create it
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                order_id INT,
                message TEXT NOT NULL,
                type VARCHAR(50) DEFAULT 'order_update',
                is_read BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_order_id (order_id),
                INDEX idx_created_at (created_at)
            )
        ");
        
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, order_id, message, type) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $orderId, $message, $type]);
        
        return true;
    } catch (Exception $e) {
        error_log('Failed to create notification: ' . $e->getMessage());
        return false;
    }
}

// Create customer notification (for non-order related notifications like reviews)
function createCustomerNotification($userId, $message, $type = 'general') {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, order_id, message, type) 
            VALUES (?, NULL, ?, ?)
        ");
        $stmt->execute([$userId, $message, $type]);
        
        return true;
    } catch (Exception $e) {
        error_log('Failed to create customer notification: ' . $e->getMessage());
        return false;
    }
}
?>
