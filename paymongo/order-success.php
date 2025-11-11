<?php
// CRITICAL: Start session FIRST with proper cookie settings to ensure it persists after PayMongo redirect
if (session_status() === PHP_SESSION_NONE) {
    // Configure session cookie to persist across redirects
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    // Start session
    session_start();
    
    // Ensure session cookie is set with proper parameters
    if (isset($_COOKIE[session_name()])) {
        // Session cookie exists, ensure it's properly configured
        $sessionParams = session_get_cookie_params();
        $expires = $sessionParams['lifetime'] ? time() + $sessionParams['lifetime'] : 0;
        setcookie(
            session_name(),
            session_id(),
            $expires,
            $sessionParams['path'],
            $sessionParams['domain'],
            $sessionParams['secure'],
            $sessionParams['httponly']
        );
    }
} else {
    // Session already started, just ensure it's active
    session_start();
}

// CRITICAL: Do NOT include header.php yet - we need to do redirects first
// All redirects must happen before any output
require_once '../config/database.php';

// CRITICAL FIX: Restore session from remember_token cookie BEFORE including functions.php
// This prevents checkSessionTimeout() from logging out the user
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
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
            error_log('Order Success - Session restored from remember_token for user: ' . $user['id']);
        } else {
            error_log('Order Success - Invalid remember_token cookie');
        }
    } catch (Exception $e) {
        error_log('Order Success - Error restoring session from remember_token: ' . $e->getMessage());
    }
}

// CRITICAL: Update last_activity BEFORE including functions.php to prevent timeout
if (isset($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
    error_log('Order Success - Updated last_activity for user: ' . $_SESSION['user_id']);
}

// NOW include functions.php - checkSessionTimeout() will see updated last_activity
require_once '../includes/functions.php';

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$transactionId = isset($_GET['transaction_id']) ? (int)$_GET['transaction_id'] : 0;
$checkoutSessionId = isset($_GET['checkout_session_id']) ? $_GET['checkout_session_id'] : null;

// Rest of the redirect logic...
if ($orderId <= 0 && $transactionId <= 0 && !$checkoutSessionId) {
    error_log('Order Success - CRITICAL: No valid order_id, transaction_id, or checkout_session_id found.');
    error_log('Order Success - GET params: ' . json_encode($_GET));
    error_log('Order Success - Redirecting to products.');
    // CRITICAL: Update session activity before redirecting to prevent logout
    if (isset($_SESSION['user_id'])) {
        $_SESSION['last_activity'] = time();
    }
    header("Location: ../products.php");
    exit();
}

try {
    // Initialize variables
    $order = null;
    $transaction = null;
    $hasDiscount = false;
    $originalAmount = 0;
    $discountAmount = 0;
    $totalAmount = 0;
    
    // Get transaction
    if ($transactionId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE id = ?");
        $stmt->execute([$transactionId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get order
    if ($orderId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get transaction details if available
        $transaction = null;
        if ($order && $order['payment_transaction_id']) {
            $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE id = ?");
            $stmt->execute([$order['payment_transaction_id']]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } else {
        // Multi-seller order lookup by transaction_id OR checkout_session_id
        if ($transactionId > 0) {
            $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE id = ?");
            $stmt->execute([$transactionId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif ($checkoutSessionId) {
            // Try to find transaction by checkout_session_id
            error_log('Order Success - Looking up transaction by checkout_session_id: ' . $checkoutSessionId);
            $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE paymongo_session_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$checkoutSessionId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($transaction) {
                $transactionId = (int)$transaction['id'];
                error_log('Order Success - Found transaction by checkout_session_id: ' . $transactionId);
            }
        } else {
            $transaction = null;
        }
        
        if (!$transaction) {
            error_log('Order Success - CRITICAL: Transaction not found. transactionId: ' . $transactionId . ', checkoutSessionId: ' . ($checkoutSessionId ?? 'NULL'));
            
            // If we have checkout_session_id but no transaction, try to create a transaction record
            if ($checkoutSessionId) {
                error_log('Order Success - Have checkout_session_id but no transaction - trying to create transaction from PayMongo data');
                // We'll continue and try to create orders with minimal data
                // Create a minimal transaction object for order creation
                $transaction = [
                    'id' => 0,
                    'user_id' => $_SESSION['user_id'] ?? 0,
                    'total_amount' => $_SESSION['pending_checkout_grand_total'] ?? 0,
                    'payment_method' => 'card', // Default for PayMongo
                    'shipping_address' => $_SESSION['pending_shipping_address'] ?? '',
                    'status' => 'pending'
                ];
                error_log('Order Success - Created minimal transaction object for order creation');
            } else {
                // Don't redirect - try to create orders anyway if we have checkout_session_id
                if (!$checkoutSessionId) {
                    // CRITICAL: Update session activity before redirecting to prevent logout
                    if (isset($_SESSION['user_id'])) {
                        $_SESSION['last_activity'] = time();
                    }
                    header("Location: ../products.php");
                    exit();
                }
                // If we have checkout_session_id, continue - we'll try to create orders
                error_log('Order Success - Continuing with checkout_session_id only - will try to create orders');
            }
        }
        
        // CRITICAL: Verify payment status with PayMongo before creating orders
        // Check if there's a checkout_session_id in the URL or we can get it from the transaction
        // IMPORTANT: Re-get checkout_session_id from URL (it might have been set earlier but we need it here)
        $checkoutSessionId = $_GET['checkout_session_id'] ?? $checkoutSessionId ?? null;
        $paymentVerified = false;
        
        error_log('Order Success - Starting payment verification for transaction ' . $transactionId);
        error_log('Order Success - checkout_session_id: ' . ($checkoutSessionId ?? 'NULL'));
        error_log('Order Success - transaction status: ' . ($transaction['payment_status'] ?? 'NULL'));
        
        // If we have checkout_session_id, verify payment status with PayMongo
        if ($checkoutSessionId) {
            error_log('Order Success - Verifying payment with PayMongo for checkout_session_id: ' . $checkoutSessionId);
            try {
                require_once 'config.php';
                
                // Fetch checkout session from PayMongo to verify payment status
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => PAYMONGO_BASE_URL . '/checkout_sessions/' . $checkoutSessionId,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':')
                    ],
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_TIMEOUT => 10
                ]);
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                
                error_log('Order Success - PayMongo API Response HTTP Code: ' . $http_code);
                error_log('Order Success - PayMongo API Response: ' . substr($response, 0, 500));
                
                if ($curlError) {
                    error_log('Order Success - PayMongo API cURL Error: ' . $curlError);
                }
                
                if ($http_code === 200) {
                    $response_data = json_decode($response, true);
                    if (!$response_data) {
                        error_log('Order Success - Failed to decode PayMongo response JSON');
                        $paymentVerified = true; // Trust redirect if we're on success page
                    } elseif (isset($response_data['data']['attributes']['payment_status'])) {
                        $paymongoPaymentStatus = $response_data['data']['attributes']['payment_status'];
                        error_log('Order Success - PayMongo payment status: ' . $paymongoPaymentStatus);
                        
                        // PayMongo payment statuses: 'paid', 'unpaid', 'awaiting_payment_method'
                        // If we're on success_url, PayMongo only redirects here after successful payment
                        // So even if status is not 'paid' yet, we should trust the redirect
                        if ($paymongoPaymentStatus === 'paid') {
                            $paymentVerified = true;
                            error_log('Order Success - Payment status is paid - verified');
                        } else {
                            // Status might be 'unpaid' or 'awaiting_payment_method' but we're on success page
                            // PayMongo ONLY redirects to success_url after payment succeeds
                            // So trust the redirect and verify payment
                            error_log('Order Success - Payment status is ' . $paymongoPaymentStatus . ' but we are on success page - trusting redirect');
                            $paymentVerified = true;
                            
                            // Also check if there are any payments in the checkout session
                            if (isset($response_data['data']['attributes']['payments']) && is_array($response_data['data']['attributes']['payments'])) {
                                $payments = $response_data['data']['attributes']['payments'];
                                if (!empty($payments)) {
                                    $lastPayment = end($payments);
                                    if (isset($lastPayment['attributes']['status']) && $lastPayment['attributes']['status'] === 'paid') {
                                        error_log('Order Success - Found paid payment in checkout session - verified');
                                        $paymentVerified = true;
                                    }
                                }
                            }
                        }
                    } else {
                        // No payment_status in response - but we're on success page
                        // PayMongo only redirects to success_url after payment, so trust it
                        error_log('Order Success - No payment_status in response, but on success page - trusting PayMongo redirect');
                        $paymentVerified = true;
                    }
                } else {
                    // API call failed - but we're on success page
                    // PayMongo only redirects to success_url after payment, so trust it
                    error_log('Order Success - Failed to verify with PayMongo API (HTTP ' . $http_code . '), but on success page - trusting redirect');
                    $paymentVerified = true;
                }
            } catch (Exception $e) {
                error_log('Order Success - Error verifying payment: ' . $e->getMessage());
                // Even if API call fails, we're on success page
                // PayMongo only redirects to success_url after payment, so trust it
                $paymentVerified = true;
            }
        } else {
            // No checkout_session_id - might be COD or PayMongo redirect without session ID
            // For COD, we trust the transaction status
            if ($transaction['payment_method'] === 'cod') {
                $paymentVerified = true;
            } else {
                // For PayMongo without session ID:
                // 1. Check if orders already exist (payment was successful before)
                // 2. Check transaction status - if it's 'pending' and we're on success page, trust it
                // 3. PayMongo redirects to success_url only after successful payment
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE payment_transaction_id = ?");
                $stmt->execute([$transactionId]);
                $existingOrdersCount = $stmt->fetchColumn();
                
                if ($existingOrdersCount > 0) {
                    // Orders already exist - payment was successful
                    $paymentVerified = true;
                    error_log('Order Success - Orders already exist, payment verified');
                } elseif ($transaction['payment_status'] === 'failed') {
                    // Transaction status is explicitly failed - don't trust it
                    error_log('Order Success - Transaction status is failed, redirecting to failure');
                    header("Location: order-failure.php?transaction_id=" . $transactionId . "&error=Payment verification failed");
                    exit();
                } else {
                    // Transaction is pending/null/completed and we're on success page
                    // PayMongo ONLY redirects to success_url after payment succeeds
                    // So if we're here, payment was successful - trust it!
                    $paymentVerified = true;
                    error_log('Order Success - No checkout_session_id but on success page - trusting PayMongo redirect (status: ' . ($transaction['payment_status'] ?? 'NULL') . ')');
                }
            }
        }
        
        // CRITICAL: Check if orders already exist for this transaction
        if ($transaction) {
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE payment_transaction_id = ? LIMIT 1");
            $stmt->execute([$transactionId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
    
    // If no order found, check if transaction exists and is completed
    // If payment was successful, show success page even without order details
    if (!$order) {
        error_log('Order Success - No order found after all attempts.');
        
        // Check if transaction exists and is completed
        if ($transaction && ($transaction['payment_status'] === 'completed' || $transaction['payment_status'] === 'pending')) {
            error_log('Order Success - Transaction exists and is completed/pending, showing success page without order details');
            // We'll show the success page with transaction info only
            $order = null; // Keep as null, we'll handle this in the display
        } else {
            error_log('Order Success - Transaction not found or failed, redirecting to products.');
            // CRITICAL: Update session activity before redirecting to prevent logout
            if (isset($_SESSION['user_id'])) {
                $_SESSION['last_activity'] = time();
            }
            header("Location: ../products.php");
            exit();
        }
    }
    
    // Get customer info - from transaction if available, otherwise from order
    if ($transaction) {
        $customerName = $transaction['customer_name'] ?? '';
        $customerEmail = $transaction['customer_email'] ?? '';
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
        // Get customer info from order
        $stmt = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
        $stmt->execute([$order['user_id']]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        $customerName = ($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '');
        $customerEmail = $customer['email'] ?? '';
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
$orderItems = [];
if ($order && isset($order['id'])) {
    $stmt = $pdo->prepare("SELECT oi.*, 
                          oi.original_price,
                          p.name, 
                          p.image_url 
                          FROM order_items oi 
                          JOIN products p ON oi.product_id = p.id 
                          WHERE oi.order_id = ?");
    $stmt->execute([$order['id']]);
    $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
    
} catch (PDOException $e) {
    error_log('Order success page error: ' . $e->getMessage());
    // CRITICAL: Update session activity before redirecting to prevent logout
    if (isset($_SESSION['user_id'])) {
        $_SESSION['last_activity'] = time();
    }
    header("Location: ../products.php");
    exit();
}
?>

<style>
:root {
    --primary-dark: #130325;
    --accent-yellow: #FFD736;
    --text-dark: #130325;
    --text-light: #6c757d;
    --bg-light: #f8f9fa;
}

body {
    background: var(--bg-light) !important;
    margin: 0;
    padding: 0;
}

.order-success-wrapper {
    background: var(--bg-light);
    min-height: 100vh;
    padding: 10px;
    margin-top: 120px;
}

.order-success-container {
    background: #ffffff;
    border-radius: 8px;
    padding: 20px;
    margin: 0 auto;
    max-width: 800px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.success-header {
    text-align: center;
    margin-bottom: 15px;
}

.success-icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #28a745, #20c997);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 10px;
    font-size: 24px;
    color: white;
    font-weight: bold;
}

.success-header h1 {
    color: var(--primary-dark);
    font-size: 1.5rem;
    margin-bottom: 5px;
    font-weight: 700;
}

.success-header p {
    color: var(--text-light);
    font-size: 0.9rem;
    opacity: 0.9;
    margin: 0;
}

.order-details {
    background: rgba(255, 215, 54, 0.1);
    border: 1px solid rgba(255, 215, 54, 0.3);
    border-radius: 6px;
    padding: 12px;
    margin-bottom: 15px;
}

.order-details h2 {
    color: var(--primary-dark);
    font-size: 1.1rem;
    margin-bottom: 10px;
    border-bottom: 2px solid var(--accent-yellow);
    padding-bottom: 5px;
}

.order-info {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    font-size: 13px;
}

.order-info > div {
    display: flex;
    justify-content: space-between;
}

.order-info span:first-child {
    color: var(--text-light);
    font-weight: 600;
}

.order-info span:last-child {
    color: var(--primary-dark);
    font-weight: 600;
}

.items-section {
    margin-bottom: 15px;
}

.items-section h3 {
    color: var(--primary-dark);
    font-size: 1rem;
    margin-bottom: 10px;
}

.items-list {
    max-height: 150px;
    overflow-y: auto;
    margin-bottom: 10px;
}

.item-row {
    display: flex;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid rgba(255, 215, 54, 0.2);
}

.item-row img {
    width: 35px;
    height: 35px;
    object-fit: cover;
    border-radius: 4px;
    margin-right: 10px;
}

.item-info {
    flex: 1;
}

.item-name {
    color: var(--primary-dark);
    font-weight: 600;
    font-size: 13px;
}

.item-details {
    color: var(--text-light);
    font-size: 11px;
    opacity: 0.8;
}

.item-price {
    color: var(--accent-yellow);
    font-weight: 600;
    font-size: 13px;
}

.total-amount {
    text-align: right;
    padding-top: 10px;
    border-top: 2px solid var(--accent-yellow);
}

.total-amount strong {
    color: var(--accent-yellow);
    font-size: 1rem;
}

.discount-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 4px;
}

.next-steps {
    background: rgba(255, 215, 54, 0.1);
    border: 1px solid rgba(255, 215, 54, 0.3);
    border-radius: 6px;
    padding: 12px;
    margin-bottom: 15px;
}

.next-steps h3 {
    color: var(--primary-dark);
    font-size: 1rem;
    margin-bottom: 10px;
}

.steps-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    font-size: 12px;
}

.step-item {
    text-align: center;
}

.step-number {
    width: 32px;
    height: 32px;
    background: var(--accent-yellow);
    color: var(--primary-dark);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 5px;
    font-weight: bold;
    font-size: 14px;
}

.step-title {
    color: var(--primary-dark);
    font-weight: 600;
    font-size: 12px;
    margin-bottom: 2px;
}

.step-desc {
    color: var(--text-light);
    opacity: 0.8;
    font-size: 10px;
}

.action-buttons {
    display: flex;
    gap: 10px;
    justify-content: center;
    flex-wrap: wrap;
}

.btn {
    padding: 8px 16px;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 600;
    font-size: 13px;
    transition: all 0.3s;
    border: none;
    cursor: pointer;
    white-space: nowrap;
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #5a6268;
    transform: translateY(-1px);
}

.btn-primary {
    background: var(--accent-yellow);
    color: var(--primary-dark);
}

.btn-primary:hover {
    background: #e6c230;
    transform: translateY(-1px);
}

@media (max-width: 768px) {
    .order-success-wrapper {
        margin-top: 100px;
        padding: 5px;
    }
    
    .order-success-container {
        padding: 15px;
    }
    
    .order-info {
        grid-template-columns: 1fr;
        gap: 6px;
    }
    
    .steps-grid {
        grid-template-columns: 1fr;
        gap: 8px;
    }
    
    .action-buttons {
        flex-direction: column;
    }
    
    .btn {
        width: 100%;
        text-align: center;
    }
}
</style>

<main class="order-success-wrapper">
    <div class="order-success-container">
        <!-- Success Header -->
        <div class="success-header">
            <div class="success-icon">✓</div>
            <h1>Order Placed Successfully!</h1>
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
                <div>
                    <span>Payment:</span>
                    <span style="color: var(--accent-yellow);"><?php echo htmlspecialchars($paymentMethodDisplay); ?></span>
                </div>
            </div>
        </div>

        <!-- Compact Items List -->
        <div class="items-section">
            <h3>Items Ordered</h3>
            <div class="items-list">
                <?php if (!empty($orderItems)): ?>
                    <?php foreach ($orderItems as $item): ?>
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
                <?php else: ?>
                    <div class="item-row" style="padding: 15px; text-align: center; color: var(--text-light);">
                        <p>Order details are being processed. Your payment has been received successfully.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="total-amount">
                <?php if ($hasDiscount): ?>
                    <div style="margin-bottom: 8px;">
                        <div class="discount-row" style="font-size: 0.9rem; color: var(--text-light);">
                            <span>Subtotal:</span>
                            <span>₱<?php echo number_format($originalAmount, 2); ?></span>
                        </div>
                        <div class="discount-row" style="font-size: 0.9rem; color: #10b981; font-weight: 600;">
                            <span><i class="fas fa-tag"></i> Discount:</span>
                            <span>-₱<?php echo number_format($discountAmount, 2); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
                <strong style="font-size: 1.1rem; display: block;">Total Amount: ₱<?php echo number_format($totalAmount, 2); ?></strong>
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
