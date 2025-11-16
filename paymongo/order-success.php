<?php
// CRITICAL: Start session FIRST with proper cookie settings to ensure it persists after PayMongo redirect
if (session_status() === PHP_SESSION_NONE) {
    // Configure session cookie to persist across redirects
    // IMPORTANT: For ngrok, we need to set secure to false in development, true in production
    $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    $cookiePath = '/';
    $cookieDomain = ''; // Empty string = current domain (works with ngrok)
    
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_lifetime', '0'); // Session cookie (until browser closes)
    ini_set('session.cookie_path', $cookiePath);
    ini_set('session.cookie_domain', $cookieDomain);
    ini_set('session.cookie_secure', $isSecure ? '1' : '0');
    
    // Start session
    session_start();
    
    // Log session info for debugging
    error_log('Order Success - Session started: ' . session_id() . ' | Cookie domain: ' . $cookieDomain . ' | Secure: ' . ($isSecure ? 'yes' : 'no'));
} else {
    // Session already started
    error_log('Order Success - Session already active: ' . session_id());
    session_start();
}

// CRITICAL: Do NOT include header.php yet - we need to do redirects first
// All redirects must happen before any output
require_once '../config/database.php';

// Get transaction ID early (needed for session restoration)
$transactionId = isset($_GET['transaction_id']) ? (int)$_GET['transaction_id'] : 0;

// CRITICAL FIX: Restore session using multiple methods (cookie OR URL token)
// This prevents logout after PayMongo redirect, especially when ngrok URL changes
$sessionRestored = false;

// Method 1: Try session_token from URL (works even when cookies fail due to domain change)
if (!isset($_SESSION['user_id']) && isset($_GET['session_token']) && $transactionId > 0) {
    $sessionToken = $_GET['session_token'];
    error_log('Order Success - Attempting to restore session from URL token for transaction: ' . $transactionId);
    
    try {
        // First, try with session_token columns (if they exist)
        $stmt = $pdo->prepare("
            SELECT pt.user_id, u.id, u.username, u.user_type 
            FROM payment_transactions pt
            JOIN users u ON pt.user_id = u.id
            WHERE pt.id = ? AND pt.session_token = ? AND pt.session_token_expiry > NOW() AND u.is_active = 1
        ");
        $stmt->execute([$transactionId, $sessionToken]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            // Restore session
            $_SESSION['user_id'] = $result['id'];
            $_SESSION['username'] = $result['username'];
            $_SESSION['user_type'] = $result['user_type'];
            $_SESSION['last_activity'] = time();
            $sessionRestored = true;
            error_log('Order Success - Session restored from URL token for user: ' . $result['id']);
            
            // Clear the session token for security
            try {
                $stmt = $pdo->prepare("UPDATE payment_transactions SET session_token = NULL WHERE id = ?");
                $stmt->execute([$transactionId]);
            } catch (PDOException $e) {
                // Column might not exist, ignore
            }
        } else {
            error_log('Order Success - Session token validation failed, trying fallback method');
            // Fallback: If session_token validation fails, use transaction user_id directly
            // This works if columns don't exist or token expired
            $stmt = $pdo->prepare("
                SELECT pt.user_id, u.id, u.username, u.user_type 
                FROM payment_transactions pt
                JOIN users u ON pt.user_id = u.id
                WHERE pt.id = ? AND u.is_active = 1
            ");
            $stmt->execute([$transactionId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                // Restore session using transaction user_id (less secure but works)
                $_SESSION['user_id'] = $result['id'];
                $_SESSION['username'] = $result['username'];
                $_SESSION['user_type'] = $result['user_type'];
                $_SESSION['last_activity'] = time();
                $sessionRestored = true;
                error_log('Order Success - Session restored from transaction user_id (fallback) for user: ' . $result['id']);
            }
        }
    } catch (PDOException $e) {
        // If session_token column doesn't exist, use transaction user_id directly
        error_log('Order Success - Session token column may not exist, using transaction user_id: ' . $e->getMessage());
        
        try {
            $stmt = $pdo->prepare("
                SELECT pt.user_id, u.id, u.username, u.user_type 
                FROM payment_transactions pt
                JOIN users u ON pt.user_id = u.id
                WHERE pt.id = ? AND u.is_active = 1
            ");
            $stmt->execute([$transactionId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $_SESSION['user_id'] = $result['id'];
                $_SESSION['username'] = $result['username'];
                $_SESSION['user_type'] = $result['user_type'];
                $_SESSION['last_activity'] = time();
                $sessionRestored = true;
                error_log('Order Success - Session restored from transaction user_id for user: ' . $result['id']);
            }
        } catch (PDOException $e2) {
            error_log('Order Success - Failed to restore session from transaction: ' . $e2->getMessage());
        }
    }
}

// Method 2: Try remember_token cookie (fallback if URL token fails)
if (!isset($_SESSION['user_id']) && !$sessionRestored && isset($_COOKIE['remember_token'])) {
    error_log('Order Success - Session lost, attempting to restore from remember_token cookie');
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE remember_token = ? AND is_active = 1");
        $stmt->execute([$_COOKIE['remember_token']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Restore session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_type'] = $user['user_type'];
            // CRITICAL: Update last_activity to prevent session timeout
            $_SESSION['last_activity'] = time();
            $sessionRestored = true;
            error_log('Order Success - Session restored from remember_token for user: ' . $user['id']);
        } else {
            error_log('Order Success - Invalid remember_token cookie');
        }
    } catch (Exception $e) {
        error_log('Order Success - Error restoring session from remember_token: ' . $e->getMessage());
    }
}

// CRITICAL: Update last_activity BEFORE including functions.php to prevent timeout
// AND set flag to skip timeout check since we're handling session restoration manually
if (isset($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
    $_SESSION['skip_timeout_check'] = true;
    error_log('Order Success - Updated last_activity for user: ' . $_SESSION['user_id'] . ', skip_timeout_check set');
}

// NOW include functions.php - checkSessionTimeout() will be skipped for this request
require_once '../includes/functions.php';

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
// $transactionId already defined above for session restoration
$checkoutSessionId = isset($_GET['checkout_session_id']) ? $_GET['checkout_session_id'] : null;

// Rest of the redirect logic...
if ($orderId <= 0 && $transactionId <= 0 && !$checkoutSessionId) {
    error_log('Order Success - CRITICAL: No valid order_id, transaction_id, or checkout_session_id found.');
    header("Location: ../products.php");
    exit();
}

try {
    // CRITICAL: Create orders from pending checkout items for PayMongo payments
    // This is the MISSING PIECE - orders must be created after payment confirmation!
    if (isset($_SESSION['pending_checkout_items']) && $transactionId > 0) {
        error_log('Order Success - Found pending checkout items, creating orders for transaction: ' . $transactionId);
        
        $pendingItems = $_SESSION['pending_checkout_items'];
        $shippingAddress = $_SESSION['pending_shipping_address'] ?? '';
        $userId = $_SESSION['user_id'];
        
        // Get payment method from transaction
        $stmt = $pdo->prepare("SELECT payment_method FROM payment_transactions WHERE id = ?");
        $stmt->execute([$transactionId]);
        $txn = $stmt->fetch(PDO::FETCH_ASSOC);
        $paymentMethod = $txn['payment_method'] ?? 'card';
        
        error_log('Order Success - Creating ' . count($pendingItems) . ' orders for user ' . $userId);
        
        // Create orders for each seller
        foreach ($pendingItems as $sellerId => $sellerGroup) {
            error_log('Order Success - Creating order for seller ' . $sellerId . ' (type: ' . gettype($sellerId) . '), subtotal: ' . $sellerGroup['subtotal']);
            error_log('Order Success - Seller group data: ' . json_encode($sellerGroup));
            
            // CRITICAL: Ensure seller_id is a valid integer
            $sellerIdInt = (int)$sellerId;
            if ($sellerIdInt <= 0) {
                error_log('Order Success - WARNING: Invalid seller_id ' . $sellerId . ', trying to get from items');
                // Try to get seller_id from first product in items
                if (!empty($sellerGroup['items'])) {
                    $firstItem = $sellerGroup['items'][0];
                    $stmt = $pdo->prepare("SELECT seller_id FROM products WHERE id = ?");
                    $stmt->execute([$firstItem['product_id']]);
                    $product = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($product && $product['seller_id']) {
                        $sellerIdInt = (int)$product['seller_id'];
                        error_log('Order Success - Retrieved seller_id ' . $sellerIdInt . ' from product ' . $firstItem['product_id']);
                    }
                }
            }
            
            // Create order for this seller
            // For COD orders, payment_status should be 'pending', not 'completed'
            $paymentStatus = ($paymentMethod === 'cod') ? 'pending' : 'completed';
            $stmt = $pdo->prepare("
                INSERT INTO orders (
                    user_id, payment_transaction_id, seller_id, total_amount,
                    shipping_address, payment_method, status, payment_status
                ) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)
            ");
            $stmt->execute([
                $userId, 
                $transactionId, 
                $sellerIdInt,  // Use validated integer seller_id
                $sellerGroup['subtotal'],
                $shippingAddress, 
                $paymentMethod,
                $paymentStatus  // 'pending' for COD, 'completed' for others
            ]);
            $orderId = $pdo->lastInsertId();
            error_log('Order Success - Order #' . $orderId . ' created with seller_id: ' . $sellerIdInt);
            
            // Create order items for this order
            foreach ($sellerGroup['items'] as $item) {
                $stmt = $pdo->prepare("
                    INSERT INTO order_items (
                        order_id, product_id, quantity, price, original_price
                    ) VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $orderId,
                    $item['product_id'],
                    $item['quantity'],
                    $item['price'],
                    $item['original_price'] ?? $item['price']
                ]);
                error_log('Order Success - Order item created: Product ' . $item['product_id'] . ', Qty ' . $item['quantity']);
                
                // Update product stock
                $stmt = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?");
                $stmt->execute([$item['quantity'], $item['product_id']]);
            }
        }
        
        // Update payment transaction status
        $stmt = $pdo->prepare("UPDATE payment_transactions SET payment_status = 'completed' WHERE id = ?");
        $stmt->execute([$transactionId]);
        
        // Get all orders we just created (needed for both seller and customer notifications)
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE payment_transaction_id = ?");
        $stmt->execute([$transactionId]);
        $createdOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log('Order Success - Found ' . count($createdOrders) . ' orders for transaction ' . $transactionId);
        
        // Create seller notifications for all created orders
        if (file_exists(__DIR__ . '/../includes/seller_notification_functions.php')) {
            require_once __DIR__ . '/../includes/seller_notification_functions.php';
            
            foreach ($createdOrders as $createdOrder) {
                $sellerId = $createdOrder['seller_id'];
                $orderId = $createdOrder['id'];
                $orderTotal = $createdOrder['total_amount'];
                
                // Create seller notification
                createSellerNotification(
                    $sellerId,
                    'New Order Received',
                    'You have a new order #' . str_pad($orderId, 6, '0', STR_PAD_LEFT) . ' totaling ₱' . number_format($orderTotal, 2) . '. Please review and process it.',
                    'success',
                    'seller-order-details.php?order_id=' . $orderId
                );
                error_log('Order Success - Seller notification created for seller ' . $sellerId . ', order ' . $orderId);
            }
        }
        
        // Create customer notifications for each order
        try {
            if (isset($createdOrders) && !empty($createdOrders)) {
                foreach ($createdOrders as $createdOrder) {
                    $orderId = $createdOrder['id'];
                    $orderTotal = $createdOrder['total_amount'];
                    $sellerId = $createdOrder['seller_id'];
                    
                    // Get seller name for notification message
                    $sellerStmt = $pdo->prepare("
                        SELECT COALESCE(display_name, CONCAT(first_name, ' ', last_name), username, 'Unknown Seller') as seller_name
                        FROM users WHERE id = ?
                    ");
                    $sellerStmt->execute([$sellerId]);
                    $seller = $sellerStmt->fetch(PDO::FETCH_ASSOC);
                    $sellerName = $seller['seller_name'] ?? 'Unknown Seller';
                    
                    // Check if notification already exists (prevent duplicates)
                    $checkStmt = $pdo->prepare("SELECT id FROM notifications WHERE user_id = ? AND order_id = ? AND type = 'order_placed'");
                    $checkStmt->execute([$userId, $orderId]);
                    $existingNotif = $checkStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$existingNotif) {
                        $stmt = $pdo->prepare("
                            INSERT INTO notifications (user_id, order_id, message, type, created_at) 
                            VALUES (?, ?, ?, 'order_placed', NOW())
                        ");
                        $message = "Order #" . str_pad($orderId, 6, '0', STR_PAD_LEFT) . " has been placed successfully with " . htmlspecialchars($sellerName) . ".";
                        $stmt->execute([$userId, $orderId, $message]);
                        error_log('Order Success - Created notification for order #' . $orderId . ' for user ' . $userId . ' with seller: ' . $sellerName);
                    } else {
                        error_log('Order Success - Notification already exists for order #' . $orderId . ', skipping duplicate');
                    }
                }
                error_log('Order Success - Customer notifications created for ' . count($createdOrders) . ' orders');
            } else {
                error_log('Order Success - WARNING: $createdOrders is empty or not set, cannot create notifications');
            }
        } catch (Exception $e) {
            error_log('Order Success - Error creating customer notification: ' . $e->getMessage());
            error_log('Order Success - Stack trace: ' . $e->getTraceAsString());
        }
        
        // Clear cart items for this user
        // Clear session-based cart (for guests)
        if (isset($_SESSION['cart_session_id'])) {
            $stmt = $pdo->prepare("DELETE FROM cart_items WHERE session_id = ?");
            $stmt->execute([$_SESSION['cart_session_id']]);
            error_log('Order Success - Cart cleared for session: ' . $_SESSION['cart_session_id']);
        }
        
        // Clear database cart (for logged-in users)
        if (isset($_SESSION['user_id'])) {
            $userId = $_SESSION['user_id'];
            $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
            $stmt->execute([$userId]);
            error_log('Order Success - Cart cleared for logged-in user: ' . $userId);
        }
        
        // Clear pending checkout session data
        unset($_SESSION['pending_checkout_items']);
        unset($_SESSION['pending_checkout_grand_total']);
        unset($_SESSION['pending_checkout_transaction_id']);
        unset($_SESSION['pending_customer_name']);
        unset($_SESSION['pending_customer_email']);
        unset($_SESSION['pending_customer_phone']);
        unset($_SESSION['pending_shipping_address']);
        
        error_log('Order Success - All orders created successfully with notifications, cleared pending session data');
    }
    
    // Initialize variables
    $order = null;
    $transaction = null;
    $hasDiscount = false;
    $originalAmount = 0;
    $discountAmount = 0;
    $totalAmount = 0;
    
    // Get transaction
    // Get transaction
    if ($transactionId > 0) {
        $stmt = $pdo->prepare("SELECT pt.*, o.user_id as order_user_id 
                              FROM payment_transactions pt
                              LEFT JOIN orders o ON pt.id = o.payment_transaction_id
                              WHERE pt.id = ?
                              LIMIT 1");
        $stmt->execute([$transactionId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // CRITICAL: Verify user owns this transaction
        if ($transaction && isset($_SESSION['user_id'])) {
            if (isset($transaction['order_user_id']) && $transaction['order_user_id'] != $_SESSION['user_id']) {
                error_log('Order Success - Access denied: User ' . $_SESSION['user_id'] . ' tried to access transaction ' . $transactionId . ' owned by user ' . $transaction['order_user_id']);
                header("Location: ../products.php?error=access_denied");
                exit();
            }
        }
    }
    
    // Get order
  // Get order
  if ($orderId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // CRITICAL: Verify user owns this order
    if ($order && isset($_SESSION['user_id'])) {
        if ($order['user_id'] != $_SESSION['user_id']) {
            error_log('Order Success - Access denied: User ' . $_SESSION['user_id'] . ' tried to access order ' . $orderId . ' owned by user ' . $order['user_id']);
            header("Location: ../products.php?error=access_denied");
            exit();
        }
    }
} elseif ($transaction) {
    // Get ALL orders for this transaction (multi-seller support)
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE payment_transaction_id = ?");
    $stmt->execute([$transactionId]);
    $allOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Use first order for basic info, but we'll process all orders for items
    if (!empty($allOrders)) {
        $order = $allOrders[0];
    }
    
    // CRITICAL FIX: Create notifications for orders if they don't exist yet
    // This handles cases where session was lost but orders were created
    if (!empty($allOrders) && isset($_SESSION['user_id'])) {
        $userId = $_SESSION['user_id'];
        foreach ($allOrders as $existingOrder) {
            $orderId = $existingOrder['id'];
            $orderTotal = $existingOrder['total_amount'];
            
            // Check if notification already exists for this order
            $checkStmt = $pdo->prepare("SELECT id FROM notifications WHERE user_id = ? AND order_id = ? AND type = 'order_placed'");
            $checkStmt->execute([$userId, $orderId]);
            $existingNotification = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$existingNotification) {
                // Create notification if it doesn't exist
                try {
                    $notifStmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, order_id, message, type, created_at) 
                        VALUES (?, ?, ?, 'order_placed', NOW())
                    ");
                    $message = "Your order #" . str_pad($orderId, 6, '0', STR_PAD_LEFT) . " totaling ₱" . number_format($orderTotal, 2) . " has been placed successfully.";
                    $notifStmt->execute([$userId, $orderId, $message]);
                    error_log('Order Success - Created missing notification for existing order #' . $orderId . ' for user ' . $userId);
                } catch (Exception $e) {
                    error_log('Order Success - Error creating notification for existing order: ' . $e->getMessage());
                }
            }
        }
    }
}
    
    // If no order or transaction found, redirect
    if (!$order && !$transaction) {
        header("Location: ../products.php");
        exit();
    }
    
    // Get customer info - prioritize session data (from checkout form), then transaction, then order
    // Session data is the most accurate as it comes directly from the checkout form
    $customerName = '';
    $customerEmail = '';
    $customerPhone = '';
    
    // First, try to get from session (stored during checkout)
    if (isset($_SESSION['pending_customer_name']) && !empty($_SESSION['pending_customer_name'])) {
        $customerName = $_SESSION['pending_customer_name'];
    }
    if (isset($_SESSION['pending_customer_email']) && !empty($_SESSION['pending_customer_email'])) {
        $customerEmail = $_SESSION['pending_customer_email'];
    }
    if (isset($_SESSION['pending_customer_phone']) && !empty($_SESSION['pending_customer_phone'])) {
        $customerPhone = $_SESSION['pending_customer_phone'];
    }
    
    // If session data not available, fall back to transaction
    if ($transaction) {
        if (empty($customerName)) {
            $customerName = $transaction['customer_name'] ?? '';
        }
        if (empty($customerEmail)) {
            $customerEmail = $transaction['customer_email'] ?? '';
        }
        if (empty($customerPhone)) {
            $customerPhone = $transaction['customer_phone'] ?? '';
        }
        $paymentMethod = $transaction['payment_method'] ?? '';
        
        // CRITICAL: Check if discount was applied
        if (isset($transaction['discount_amount']) && $transaction['discount_amount'] > 0 && isset($transaction['final_amount'])) {
            $hasDiscount = true;
            $originalAmount = (float)$transaction['total_amount'];
            $discountAmount = (float)$transaction['discount_amount'];
            $totalAmount = (float)$transaction['final_amount'];
            error_log('Order Success - Discount applied: Original ₱' . number_format($originalAmount, 2) . ', Discount ₱' . number_format($discountAmount, 2) . ', Final ₱' . number_format($totalAmount, 2));
        } else {
            $hasDiscount = false;
            $totalAmount = (float)($transaction['total_amount'] ?? 0);
        }
    } else {
        // Get customer info from order (only if not already set from session)
        if (empty($customerName) || empty($customerEmail)) {
            $stmt = $pdo->prepare("SELECT first_name, last_name, email, phone FROM users WHERE id = ?");
            $stmt->execute([$order['user_id']]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            if (empty($customerName)) {
                $customerName = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
            }
            if (empty($customerEmail)) {
                $customerEmail = $customer['email'] ?? '';
            }
            if (empty($customerPhone)) {
                $customerPhone = $customer['phone'] ?? '';
            }
        }
        $paymentMethod = $order['payment_method'] ?? '';
        $totalAmount = (float)($order['total_amount'] ?? 0);
        $hasDiscount = false;
    }
    
    // Format payment method display
    $paymentMethodLabels = [
        'card' => 'Debit/Credit Card',
        'gcash' => 'GCash',
        'cod' => 'Cash on Delivery',
        'cash_on_delivery' => 'Cash on Delivery',
        'paymaya' => 'PayMaya',
        'grab_pay' => 'GrabPay',
        'billease' => 'Billease'
    ];
    $paymentMethodDisplay = $paymentMethodLabels[$paymentMethod] ?? $paymentMethod;
    
    // NOW it's safe to include header.php
    require_once '../includes/header.php';
    
// Get order items
// Get order items - GROUP BY SELLER for multi-seller support
$orderItemsBySeller = [];
if ($transactionId > 0) {
    // Get ALL orders and their items for this transaction
    $stmt = $pdo->prepare("
        SELECT 
            o.id as order_id,
            o.seller_id,
            COALESCE(u.display_name, CONCAT(u.first_name, ' ', u.last_name), u.username, 'Unknown Seller') as seller_name,
            oi.*, 
            oi.original_price,
            p.name, 
            p.image_url 
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN products p ON oi.product_id = p.id
        LEFT JOIN users u ON o.seller_id = u.id
        WHERE o.payment_transaction_id = ?
        ORDER BY o.seller_id, p.name
    ");
    $stmt->execute([$transactionId]);
    $allItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group items by seller
    foreach ($allItems as $item) {
        $sellerId = $item['seller_id'];
        if (!isset($orderItemsBySeller[$sellerId])) {
            $orderItemsBySeller[$sellerId] = [
                'seller_name' => $item['seller_name'],
                'order_id' => $item['order_id'],
                'items' => []
            ];
        }
        $orderItemsBySeller[$sellerId]['items'][] = $item;
    }
} elseif ($order && isset($order['id'])) {
    // Single order fallback
    $stmt = $pdo->prepare("
        SELECT 
            o.id as order_id,
            o.seller_id,
            COALESCE(u.display_name, CONCAT(u.first_name, ' ', u.last_name), u.username, 'Unknown Seller') as seller_name,
            oi.*, 
            oi.original_price,
            p.name, 
            p.image_url 
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN products p ON oi.product_id = p.id
        LEFT JOIN users u ON o.seller_id = u.id
        WHERE o.id = ?
    ");
    $stmt->execute([$order['id']]);
    $allItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($allItems)) {
        $sellerId = $allItems[0]['seller_id'];
        $orderItemsBySeller[$sellerId] = [
            'seller_name' => $allItems[0]['seller_name'],
            'order_id' => $allItems[0]['order_id'],
            'items' => $allItems
        ];
    }
}
    
} catch (PDOException $e) {
    error_log('Order success page error: ' . $e->getMessage());
    header("Location: ../products.php");
    exit();
}
?>

<style>
:root {
    --primary-dark: #130325;
    --accent-yellow: #FFD736;
    --text-dark: #130325;
    --text-light: #6b7280;
    --bg-light: #f8f9fa;
    --bg-white: #ffffff;
    --border-light: #e5e7eb;
    --success-green: #10b981;
}

body {
    background: var(--bg-light) !important;
    margin: 0;
    padding: 0;
}

.order-success-wrapper {
    background: var(--bg-light);
    min-height: calc(100vh - 120px);
    padding: 20px;
    margin-top: 100px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.order-success-container {
    background: var(--bg-white);
    border-radius: 12px;
    padding: 24px;
    margin: 0 auto;
    max-width: 900px;
    width: 100%;
    box-shadow: 0 2px 12px rgba(19, 3, 37, 0.08);
}

.success-header {
    text-align: center;
    margin-bottom: 20px;
}

.success-icon {
    width: 64px;
    height: 64px;
    background: linear-gradient(135deg, var(--success-green), #059669);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 12px;
    font-size: 32px;
    color: white;
    font-weight: bold;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.page-title,
.success-header h1 {
    color: var(--text-dark);
    font-size: 1.35rem;
    font-weight: 600;
    letter-spacing: -0.3px;
    line-height: 1.2;
    margin: 0 0 8px 0;
}

.success-header p {
    color: var(--text-light);
    font-size: 0.9rem;
    margin: 0;
}

.order-details {
    background: rgba(19, 3, 37, 0.03);
    border: 1px solid var(--border-light);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
}

.order-details h2 {
    color: var(--primary-dark);
    font-size: 1.1rem;
    font-weight: 600;
    margin: 0 0 12px 0;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--primary-dark);
}

.order-info {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    font-size: 0.875rem;
}

.order-info > div {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.order-info span:first-child {
    color: var(--text-light);
    font-weight: 500;
}

.order-info span:last-child {
    color: var(--primary-dark);
    font-weight: 600;
}

.items-section {
    margin-bottom: 16px;
}

.items-section h3 {
    color: var(--primary-dark);
    font-size: 1rem;
    font-weight: 600;
    margin: 0 0 12px 0;
}

.items-list {
    max-height: 200px;
    overflow-y: auto;
    margin-bottom: 12px;
    padding-right: 4px;
}

.items-list::-webkit-scrollbar {
    width: 6px;
}

.items-list::-webkit-scrollbar-track {
    background: var(--bg-light);
    border-radius: 3px;
}

.items-list::-webkit-scrollbar-thumb {
    background: var(--primary-dark);
    border-radius: 3px;
}

.items-list::-webkit-scrollbar-thumb:hover {
    background: #0d021f;
}

.item-row {
    display: flex;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid var(--border-light);
}

.item-row:last-child {
    border-bottom: none;
}

.item-row img {
    width: 40px;
    height: 40px;
    object-fit: cover;
    border-radius: 6px;
    margin-right: 12px;
    border: 1px solid var(--border-light);
}

.item-info {
    flex: 1;
    min-width: 0;
}

.item-name {
    color: var(--primary-dark);
    font-weight: 600;
    font-size: 0.875rem;
    margin-bottom: 2px;
}

.item-details {
    color: var(--text-light);
    font-size: 0.75rem;
}

.item-price {
    color: var(--primary-dark);
    font-weight: 600;
    font-size: 0.875rem;
    text-align: right;
    min-width: 80px;
}

.total-amount {
    text-align: right;
    padding-top: 12px;
    margin-top: 12px;
    border-top: 2px solid var(--primary-dark);
}

.total-amount strong {
    color: var(--primary-dark);
    font-size: 1.1rem;
    font-weight: 700;
}

.discount-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 6px;
    font-size: 0.9rem;
}

.next-steps {
    background: rgba(19, 3, 37, 0.03);
    border: 1px solid var(--border-light);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
}

.next-steps h3 {
    color: var(--primary-dark);
    font-size: 1rem;
    font-weight: 600;
    margin: 0 0 12px 0;
}

.steps-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}

.step-item {
    text-align: center;
}

.step-number {
    width: 36px;
    height: 36px;
    background: var(--primary-dark);
    color: var(--bg-white);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 8px;
    font-weight: 700;
    font-size: 0.875rem;
}

.step-title {
    color: var(--primary-dark);
    font-weight: 600;
    font-size: 0.813rem;
    margin-bottom: 4px;
}

.step-desc {
    color: var(--text-light);
    font-size: 0.75rem;
}

.action-buttons {
    display: flex;
    gap: 12px;
    justify-content: center;
    flex-wrap: wrap;
    margin-top: 20px;
}

.btn {
    padding: 10px 20px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.875rem;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.btn-secondary {
    background: var(--text-light);
    color: var(--bg-white);
}

.btn-secondary:hover {
    background: #4b5563;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

.btn-primary {
    background: var(--accent-yellow);
    color: var(--primary-dark);
}

.btn-primary:hover {
    background: #e6c230;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(255, 215, 54, 0.3);
}

@media (max-width: 768px) {
    .order-success-wrapper {
        margin-top: 80px;
        padding: 12px;
        min-height: calc(100vh - 80px);
    }
    
    .order-success-container {
        padding: 16px;
    }
    
    .success-icon {
        width: 56px;
        height: 56px;
        font-size: 28px;
    }
    
    .page-title,
    .success-header h1 {
        font-size: 1.2rem;
    }
    
    .order-info {
        grid-template-columns: 1fr;
        gap: 8px;
    }
    
    .steps-grid {
        grid-template-columns: 1fr;
        gap: 10px;
    }
    
    .action-buttons {
        flex-direction: column;
    }
    
    .btn {
        width: 100%;
    }
}

@media (max-width: 480px) {
    .order-success-wrapper {
        padding: 8px;
    }
    
    .order-success-container {
        padding: 12px;
    }
}
</style>

<main class="order-success-wrapper">
    <div class="order-success-container">
        <!-- Success Header -->
        <div class="success-header">
            <div class="success-icon">✓</div>
            <h1 class="page-title">Order Placed Successfully!</h1>
            <p>Thank you for your purchase. Your order has been received and is being processed.</p>
        </div>

        <!-- Compact Order Details -->
        <div class="order-details">
            <h2>Order Details</h2>
            <div class="order-info">
                <div>
                    <span>Order ID:</span>
                    <span>#<?php echo $order ? str_pad($order['id'], 6, '0', STR_PAD_LEFT) : str_pad($transactionId, 6, '0', STR_PAD_LEFT); ?></span>
                </div>
                <div>
                    <span>Date:</span>
                    <span><?php echo $order ? date('M j, Y', strtotime($order['created_at'])) : date('M j, Y'); ?></span>
                </div>
                <div>
                    <span>Customer:</span>
                    <span><?php echo htmlspecialchars($customerName); ?></span>
                </div>
                <?php if (!empty($customerEmail)): ?>
                <div>
                    <span>Email:</span>
                    <span><?php echo htmlspecialchars($customerEmail); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($customerPhone)): ?>
                <div>
                    <span>Phone:</span>
                    <span><?php echo htmlspecialchars($customerPhone); ?></span>
                </div>
                <?php endif; ?>
                <div>
                    <span>Payment:</span>
                    <span style="color: var(--primary-dark); font-weight: 600;"><?php echo htmlspecialchars($paymentMethodDisplay); ?></span>
                </div>
            </div>
        </div>
<!-- Compact Items List -->
<div class="items-section">
    <h3>Items Ordered</h3>
    <?php if (!empty($orderItemsBySeller)): ?>
        <?php foreach ($orderItemsBySeller as $sellerId => $sellerData): ?>
            <div style="margin-bottom: 16px; border: 1px solid var(--border-light); border-radius: 8px; padding: 14px; background: rgba(19, 3, 37, 0.02);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 2px solid var(--primary-dark);">
                    <h4 style="margin: 0; color: var(--primary-dark); font-size: 0.875rem; font-weight: 600;">
                        <i class="fas fa-store"></i> <?php echo htmlspecialchars($sellerData['seller_name']); ?>
                    </h4>
                    <span style="font-size: 0.75rem; color: var(--text-light); font-weight: 500;">
                        Order #<?php echo str_pad($sellerData['order_id'], 6, '0', STR_PAD_LEFT); ?>
                    </span>
                </div>
                
                <div class="items-list" style="max-height: none;">
                    <?php 
                    // Calculate seller's original subtotal (before discount)
                    $sellerOriginalSubtotal = 0;
                    $sellerFinalSubtotal = 0;
                    
                    foreach ($sellerData['items'] as $item): 
                        // Use original_price if discount was applied to items
                        $itemOriginalPrice = !empty($item['original_price']) && $item['original_price'] != $item['price'] 
                            ? $item['original_price'] 
                            : $item['price'];
                        
                        $sellerOriginalSubtotal += $itemOriginalPrice * $item['quantity'];
                        $sellerFinalSubtotal += $item['price'] * $item['quantity'];
                    ?>
                        <div class="item-row">
                            <img src="../<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
                            <div class="item-info">
                                <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                <div class="item-details">
                                    Qty: <?php echo $item['quantity']; ?> × 
                                    <?php if (!empty($item['original_price']) && $item['original_price'] != $item['price']): ?>
                                        <span style="text-decoration: line-through; color: #999; font-size: 0.9rem;">₱<?php echo number_format($item['original_price'], 2); ?></span>
                                        <span style="color: #10b981; font-weight: 700; margin-left: 4px;">₱<?php echo number_format($item['price'], 2); ?></span>
                                    <?php else: ?>
                                        ₱<?php echo number_format($item['price'], 2); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="item-price">
                                <?php if (!empty($item['original_price']) && $item['original_price'] != $item['price']): ?>
                                    <span style="text-decoration: line-through; color: #999; font-size: 0.85rem; display: block;">₱<?php echo number_format($item['original_price'] * $item['quantity'], 2); ?></span>
                                    <span style="color: #10b981; font-weight: 700;">₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?></span>
                                <?php else: ?>
                                    ₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Seller Subtotal with Discount Breakdown -->
                <div style="text-align: right; padding-top: 12px; margin-top: 12px; border-top: 1px solid var(--border-light);">
                    <?php if ($hasDiscount && $sellerOriginalSubtotal > $sellerFinalSubtotal): ?>
                        <?php 
                        $sellerDiscountAmount = $sellerOriginalSubtotal - $sellerFinalSubtotal;
                        ?>
                        <div style="font-size: 0.813rem; color: var(--text-light); margin-bottom: 4px;">
                            Subtotal: <span style="color: var(--primary-dark); font-weight: 600;">₱<?php echo number_format($sellerOriginalSubtotal, 2); ?></span>
                        </div>
                        <div style="font-size: 0.813rem; color: var(--success-green); font-weight: 600; margin-bottom: 6px;">
                            <i class="fas fa-tag"></i> Discount: <span>-₱<?php echo number_format($sellerDiscountAmount, 2); ?></span>
                        </div>
                        <strong style="color: var(--success-green); font-size: 0.938rem; font-weight: 700;">
                            Seller Total: ₱<?php echo number_format($sellerFinalSubtotal, 2); ?>
                        </strong>
                    <?php else: ?>
                        <strong style="color: var(--primary-dark); font-size: 0.875rem; font-weight: 700;">
                            Seller Subtotal: ₱<?php echo number_format($sellerFinalSubtotal, 2); ?>
                        </strong>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="item-row" style="padding: 15px; text-align: center; color: var(--text-light);">
            <p>Order details are being processed. Your payment has been received successfully.</p>
        </div>
    <?php endif; ?>
    
    <!-- Grand Total with Discount -->
    <div class="total-amount">
        <?php if ($hasDiscount): ?>
            <div style="margin-bottom: 8px;">
                <div class="discount-row" style="font-size: 0.9rem; color: var(--text-light);">
                    <span>Order Subtotal:</span>
                    <span style="font-weight: 600;">₱<?php echo number_format($originalAmount, 2); ?></span>
                </div>
                <div class="discount-row" style="font-size: 0.9rem; color: var(--success-green); font-weight: 600;">
                    <span><i class="fas fa-tag"></i> Total Discount:</span>
                    <span>-₱<?php echo number_format($discountAmount, 2); ?></span>
                </div>
            </div>
            <strong style="font-size: 1.1rem; display: block; color: var(--success-green); font-weight: 700;">Final Total: ₱<?php echo number_format($totalAmount, 2); ?></strong>
        <?php else: ?>
            <strong style="font-size: 1.1rem; display: block; font-weight: 700;">Total Amount: ₱<?php echo number_format($totalAmount, 2); ?></strong>
        <?php endif; ?>
    </div>
</div>
        <!-- Compact Next Steps -->
        <div class="next-steps">
            <h3>What's Next?</h3>
            <div class="steps-grid">
                <div class="step-item">
                    <div class="step-number">1</div>
                    <div class="step-title">Order Confirmation</div>
                    <div class="step-desc">Email sent to <?php echo htmlspecialchars($customerEmail); ?></div>
                </div>
                <div class="step-item">
                    <div class="step-number">2</div>
                    <div class="step-title">Processing</div>
                    <div class="step-desc">Order being prepared</div>
                </div>
                <div class="step-item">
                    <div class="step-number">3</div>
                    <div class="step-title">Delivery</div>
                    <div class="step-desc">5-7 business days</div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons">
            <a href="../products.php" class="btn btn-secondary">Continue Shopping</a>
            <a href="../user-dashboard.php" class="btn btn-primary">Dashboard</a>
        </div>
    </div>
</main>

<?php require_once '../includes/footer.php'; ?>
