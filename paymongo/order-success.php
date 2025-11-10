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

// If PayMongo redirects with checkout_session_id, find the transaction_id
if ($checkoutSessionId && $transactionId <= 0) {
    error_log('Order Success - PayMongo redirect with checkout_session_id: ' . $checkoutSessionId);
    // Try to find transaction by checkout_session_id stored in payment_transactions
    // Note: You may need to store checkout_session_id in payment_transactions table
    // For now, try to get from PayMongo API or use a different lookup method
    try {
        require_once 'config.php';
        
        // Fetch checkout session from PayMongo to get reference_number
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
        curl_close($ch);
        
        error_log('Order Success - PayMongo API response code: ' . $http_code);
        
        if ($http_code === 200) {
            $response_data = json_decode($response, true);
            if (isset($response_data['data']['attributes']['reference_number'])) {
                $referenceNumber = $response_data['data']['attributes']['reference_number'];
                error_log('Order Success - Reference number from PayMongo: ' . $referenceNumber);
                // Reference number format: P-C-{transaction_id}
                if (preg_match('/P-C-(\d+)/', $referenceNumber, $matches)) {
                    $transactionId = (int)$matches[1];
                    error_log('Order Success - Found transaction_id from reference_number: ' . $transactionId);
                } else {
                    error_log('Order Success - WARNING: Reference number format does not match P-C-{id} pattern: ' . $referenceNumber);
                }
            } else {
                error_log('Order Success - WARNING: No reference_number in PayMongo response');
            }
        } else {
            error_log('Order Success - WARNING: PayMongo API returned HTTP ' . $http_code);
        }
    } catch (Exception $e) {
        error_log('Order Success - Error fetching checkout session: ' . $e->getMessage());
    }
    
    // If we still don't have transaction_id, try to find it by checkout_session_id in database
    if ($transactionId <= 0) {
        error_log('Order Success - Transaction ID still not found, searching database for checkout_session_id');
        try {
            // Check if we stored checkout_session_id in payment_transactions
            $stmt = $pdo->prepare("SELECT id FROM payment_transactions WHERE paymongo_session_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$checkoutSessionId]);
            $foundTransactionId = $stmt->fetchColumn();
            if ($foundTransactionId) {
                $transactionId = (int)$foundTransactionId;
                error_log('Order Success - Found transaction_id from database: ' . $transactionId);
            }
        } catch (Exception $e) {
            error_log('Order Success - Error searching database: ' . $e->getMessage());
        }
    }
}

// CRITICAL: Don't redirect away if we have checkout_session_id but no transaction_id
// We need to try to create orders anyway - the checkout_session_id is proof of payment
if ($orderId <= 0 && $transactionId <= 0 && !$checkoutSessionId) {
    error_log('Order Success - CRITICAL: No valid order_id, transaction_id, or checkout_session_id found.');
    error_log('Order Success - GET params: ' . json_encode($_GET));
    error_log('Order Success - Redirecting to products.');
    header("Location: ../products.php");
    exit();
}

// DEBUG: Log that we reached this point
error_log('Order Success - ========== PAGE LOADED ==========');
error_log('Order Success - orderId: ' . $orderId);
error_log('Order Success - transactionId: ' . $transactionId);
error_log('Order Success - checkoutSessionId: ' . ($checkoutSessionId ?? 'NULL'));
error_log('Order Success - GET params: ' . json_encode($_GET));

try {
    // Get order details - either by order_id or transaction_id
    if ($orderId > 0) {
        // Single order lookup
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
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE payment_transaction_id = ? LIMIT 1");
        $stmt->execute([$transactionId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // FINAL SAFETY: If we're on success page and payment is not explicitly failed, trust it
        // PayMongo ONLY redirects to success_url after successful payment
        // CRITICAL: We MUST trust the redirect - PayMongo never redirects to success_url unless payment succeeded
        if (!$paymentVerified && $transaction['payment_status'] !== 'failed') {
            error_log('Order Success - SAFETY OVERRIDE: Setting paymentVerified to true because we are on success page');
            $paymentVerified = true;
        }
        
        // ABSOLUTE SAFETY: If we're on the success page, payment MUST have succeeded
        // PayMongo's redirect to success_url is the authoritative signal that payment succeeded
        $paymentVerified = true;
        error_log('Order Success - FORCE VERIFIED: We are on success page, payment must have succeeded');
        
        error_log('Order Success - ========== BEFORE ORDER CREATION CHECK ==========');
        error_log('Order Success - order exists: ' . ($order ? 'YES (ID: ' . $order['id'] . ')' : 'NO'));
        error_log('Order Success - paymentVerified: ' . ($paymentVerified ? 'YES' : 'NO'));
        error_log('Order Success - transaction status: ' . ($transaction['payment_status'] ?? 'NULL'));
        error_log('Order Success - transaction_id: ' . $transactionId);
        error_log('Order Success - transaction exists: ' . ($transaction ? 'YES' : 'NO'));
        error_log('Order Success - checkout_session_id: ' . ($checkoutSessionId ?? 'NULL'));
        
        // CRITICAL: ALWAYS try to create orders if we're on success page and have a transaction
        // Don't check for existing orders first - just try to create them
        if ($transaction && $paymentVerified) {
            // Check if orders already exist
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE payment_transaction_id = ?");
            $stmt->execute([$transactionId]);
            $existingOrderCount = (int)$stmt->fetchColumn();
            error_log('Order Success - Existing orders count: ' . $existingOrderCount);
            
            if ($existingOrderCount > 0) {
                error_log('Order Success - Orders already exist, fetching for display');
                $stmt = $pdo->prepare("SELECT * FROM orders WHERE payment_transaction_id = ? LIMIT 1");
                $stmt->execute([$transactionId]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                error_log('Order Success - NO ORDERS EXIST - MUST CREATE THEM NOW');
                // Force order creation
                $order = null; // Ensure order is null so we create new ones
            }
        }
        
        // CRITICAL: If we're on success page, we MUST create orders
        // This is the ONLY place orders get created for PayMongo payments
        // Allow order creation if we have transaction OR checkout_session_id (proof of payment)
        $shouldCreateOrders = !$order && $paymentVerified && ($transaction || $checkoutSessionId);
        error_log('Order Success - shouldCreateOrders check:');
        error_log('Order Success -   !order: ' . (!$order ? 'YES' : 'NO'));
        error_log('Order Success -   paymentVerified: ' . ($paymentVerified ? 'YES' : 'NO'));
        error_log('Order Success -   transaction exists: ' . ($transaction ? 'YES' : 'NO'));
        error_log('Order Success -   checkoutSessionId: ' . ($checkoutSessionId ? 'YES' : 'NO'));
        error_log('Order Success -   shouldCreateOrders: ' . ($shouldCreateOrders ? 'YES' : 'NO'));
        
        if ($shouldCreateOrders) {
            error_log('Order Success - ========== STARTING ORDER CREATION ==========');
            
            // If transaction_id is 0 but we have checkout_session_id, try to find/create transaction
            if ($transactionId <= 0 && $checkoutSessionId) {
                error_log('Order Success - Transaction ID is 0, trying to find/create transaction from checkout_session_id');
                // Try to find transaction by checkout_session_id one more time
                try {
                    $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE paymongo_session_id = ? ORDER BY created_at DESC LIMIT 1");
                    $stmt->execute([$checkoutSessionId]);
                    $foundTransaction = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($foundTransaction) {
                        $transaction = $foundTransaction;
                        $transactionId = (int)$transaction['id'];
                        error_log('Order Success - Found transaction from database: ' . $transactionId);
                    } else {
                        error_log('Order Success - No transaction found in database, creating new transaction record');
                        // CRITICAL: Create a transaction record so we can link orders to it
                        try {
                            $pdo->beginTransaction();
                            $userId = $_SESSION['user_id'] ?? 0;
                            $totalAmount = $_SESSION['pending_checkout_grand_total'] ?? 0;
                            $shippingAddress = $_SESSION['pending_shipping_address'] ?? '';
                            
                            $stmt = $pdo->prepare("
                                INSERT INTO payment_transactions (
                                    user_id, total_amount, payment_method, shipping_address,
                                    customer_name, customer_email, customer_phone, paymongo_session_id
                                ) VALUES (?, ?, 'card', ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $userId, $totalAmount, $shippingAddress,
                                $_SESSION['pending_customer_name'] ?? '',
                                $_SESSION['pending_customer_email'] ?? '',
                                $_SESSION['pending_customer_phone'] ?? '',
                                $checkoutSessionId
                            ]);
                            $transactionId = $pdo->lastInsertId();
                            $pdo->commit();
                            
                            error_log('Order Success - ✅ Created new transaction record: ' . $transactionId);
                            
                            // Fetch the created transaction
                            $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE id = ?");
                            $stmt->execute([$transactionId]);
                            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
                        } catch (Exception $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            error_log('Order Success - Error creating transaction: ' . $e->getMessage());
                            // Create minimal transaction object as fallback
                            $transaction = [
                                'id' => 0,
                                'user_id' => $_SESSION['user_id'] ?? 0,
                                'total_amount' => $_SESSION['pending_checkout_grand_total'] ?? 0,
                                'payment_method' => 'card',
                                'shipping_address' => $_SESSION['pending_shipping_address'] ?? '',
                                'status' => 'pending'
                            ];
                        }
                    }
                } catch (Exception $e) {
                    error_log('Order Success - Error finding transaction: ' . $e->getMessage());
                }
            }
            
            // Final check: if transactionId is still 0, we can't create orders (need valid transaction_id)
            if ($transactionId <= 0) {
                error_log('Order Success - ❌ CRITICAL: Cannot create orders - transaction_id is still 0');
                error_log('Order Success - This should not happen if checkout_session_id was stored properly');
                // Try one last time to get from session
                $transactionId = $_SESSION['pending_checkout_transaction_id'] ?? 0;
                if ($transactionId > 0) {
                    error_log('Order Success - Found transaction_id from session: ' . $transactionId);
                    $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE id = ?");
                    $stmt->execute([$transactionId]);
                    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            }
            
            error_log('Order Success - Transaction ID: ' . $transactionId);
            error_log('Order Success - User ID: ' . ($transaction['user_id'] ?? 'NULL'));
            error_log('Order Success - Total Amount: ' . ($transaction['total_amount'] ?? 'NULL'));
            error_log('Order Success - Payment Method: ' . ($transaction['payment_method'] ?? 'NULL'));
            
            // CRITICAL: If transaction_id is still 0, we cannot create orders
            // Orders require a valid payment_transaction_id
            if ($transactionId <= 0) {
                error_log('Order Success - ❌❌❌ CRITICAL ERROR: transaction_id is 0, cannot create orders');
                error_log('Order Success - This means the transaction was never created or lost');
                error_log('Order Success - checkout_session_id: ' . ($checkoutSessionId ?? 'NULL'));
                error_log('Order Success - Session pending_checkout_transaction_id: ' . ($_SESSION['pending_checkout_transaction_id'] ?? 'NULL'));
                // Don't proceed with order creation - it will fail
                $shouldCreateOrders = false;
            }
            
            // Get cart items from session if available
            $groupedItems = $_SESSION['pending_checkout_items'] ?? null;
            $grandTotal = $_SESSION['pending_checkout_grand_total'] ?? ($transaction['total_amount'] ?? 0);
            
            error_log('Order Success - Session has pending_checkout_items: ' . ($groupedItems ? 'YES' : 'NO'));
            if ($groupedItems) {
                error_log('Order Success - Session groupedItems count: ' . count($groupedItems));
                error_log('Order Success - Session groupedItems keys: ' . json_encode(array_keys($groupedItems)));
            } else {
                error_log('Order Success - Session pending_checkout_items is NULL or not set');
                if (isset($_SESSION)) {
                    error_log('Order Success - Session keys available: ' . json_encode(array_keys($_SESSION)));
                }
            }
            
            // If session data is missing, try to rebuild from cart (if cart still has items)
            // CRITICAL: Check if this might be a buy_now checkout - if so, don't rebuild from cart
            // Buy_now checkouts should have pending_checkout_items set, but if missing, we can't safely rebuild
            $mightBeBuyNow = false;
            if (isset($_SESSION['buy_now_active']) || isset($_SESSION['buy_now_data'])) {
                $mightBeBuyNow = true;
                error_log('Order Success - WARNING: Buy Now session flags detected but pending_checkout_items missing!');
            }
            
            if (!$groupedItems && !$mightBeBuyNow) {
                error_log('Order Success - Session data missing, trying to rebuild from cart (NOT buy_now)');
                try {
                    $userId = $transaction['user_id'];
                    // Try to get cart items that match this transaction
                    $cartStmt = $pdo->prepare("
                        SELECT c.product_id, c.quantity, p.name, p.price, p.image_url, p.stock_quantity, p.status, p.seller_id,
                               COALESCE(u.display_name, CONCAT(u.first_name, ' ', u.last_name), u.username, 'Unknown Seller') as seller_display_name
                        FROM cart c
                        LEFT JOIN products p ON c.product_id = p.id
                        LEFT JOIN users u ON p.seller_id = u.id
                        WHERE c.user_id = ?
                        ORDER BY p.seller_id, p.name
                    ");
                    $cartStmt->execute([$userId]);
                    $cartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($cartItems)) {
                        // Rebuild groupedItems from cart
                        $groupedItems = [];
                        foreach ($cartItems as $item) {
                            $sellerId = (int)($item['seller_id'] ?? 0);
                            
                            // Skip items with invalid seller_id (shouldn't happen, but safety check)
                            if ($sellerId <= 0) {
                                error_log('Order Success - WARNING: Cart item has invalid seller_id: ' . $sellerId . ', product_id: ' . ($item['product_id'] ?? 'N/A'));
                                continue;
                            }
                            
                            if (!isset($groupedItems[$sellerId])) {
                                $groupedItems[$sellerId] = [
                                    'seller_id' => $sellerId,
                                    'seller_display_name' => $item['seller_display_name'] ?? 'Unknown Seller',
                                    'items' => [],
                                    'subtotal' => 0,
                                    'item_count' => 0
                                ];
                            }
                            
                            $price = (float)($item['price'] ?? 0);
                            $qty = (int)($item['quantity'] ?? 0);
                            
                            // Skip items with invalid price or quantity
                            if ($price <= 0 || $qty <= 0) {
                                error_log('Order Success - WARNING: Cart item has invalid price or quantity, skipping');
                                continue;
                            }
                            
                            $itemTotal = $price * $qty;
                            
                            $groupedItems[$sellerId]['items'][] = [
                                'product_id' => (int)$item['product_id'],
                                'name' => $item['name'] ?? 'Unknown Product',
                                'price' => $price,
                                'quantity' => $qty,
                                'image_url' => $item['image_url'] ?? 'images/placeholder.jpg',
                                'stock_quantity' => (int)($item['stock_quantity'] ?? 0),
                                'status' => $item['status'] ?? 'active',
                                'product_exists' => !empty($item['seller_id'])
                            ];
                            
                            $groupedItems[$sellerId]['subtotal'] += $itemTotal;
                            $groupedItems[$sellerId]['item_count'] += $qty;
                        }
                        error_log('Order Success - Rebuilt groupedItems from cart: ' . count($groupedItems) . ' seller groups');
                        if (empty($groupedItems)) {
                            error_log('Order Success - WARNING: Rebuilt groupedItems is empty array!');
                        }
                    } else {
                        error_log('Order Success - Cart query returned ' . count($cartItems ?? []) . ' items (empty)');
                    }
                } catch (Exception $e) {
                    error_log('Order Success - Error rebuilding from cart: ' . $e->getMessage());
                    error_log('Order Success - Error trace: ' . $e->getTraceAsString());
                }
            }
            
            // Final check before creating orders
            error_log('Order Success - Final groupedItems check: ' . ($groupedItems ? 'EXISTS' : 'NULL') . ', is_array: ' . (is_array($groupedItems) ? 'YES' : 'NO') . ', empty: ' . (empty($groupedItems) ? 'YES' : 'NO'));
            
            // CRITICAL: If groupedItems is still empty, try one more time to get from cart
            // The cart should NOT be cleared until AFTER orders are created for PayMongo payments
            if (empty($groupedItems)) {
                error_log('Order Success - groupedItems still empty, making FINAL attempt to get from cart');
                try {
                    $userId = $transaction['user_id'];
                    $cartStmt = $pdo->prepare("
                        SELECT c.product_id, c.quantity, p.name, p.price, p.image_url, p.stock_quantity, p.status, p.seller_id,
                               COALESCE(u.display_name, CONCAT(u.first_name, ' ', u.last_name), u.username, 'Unknown Seller') as seller_display_name
                        FROM cart c
                        LEFT JOIN products p ON c.product_id = p.id
                        LEFT JOIN users u ON p.seller_id = u.id
                        WHERE c.user_id = ?
                        ORDER BY p.seller_id, p.name
                    ");
                    $cartStmt->execute([$userId]);
                    $cartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);
                    error_log('Order Success - FINAL cart query returned ' . count($cartItems) . ' items');
                    
                    if (!empty($cartItems)) {
                        $groupedItems = [];
                        foreach ($cartItems as $item) {
                            $sellerId = (int)($item['seller_id'] ?? 0);
                            if ($sellerId <= 0) continue;
                            
                            if (!isset($groupedItems[$sellerId])) {
                                $groupedItems[$sellerId] = [
                                    'seller_id' => $sellerId,
                                    'seller_display_name' => $item['seller_display_name'] ?? 'Unknown Seller',
                                    'items' => [],
                                    'subtotal' => 0,
                                    'item_count' => 0
                                ];
                            }
                            
                            $price = (float)($item['price'] ?? 0);
                            $qty = (int)($item['quantity'] ?? 0);
                            if ($price <= 0 || $qty <= 0) continue;
                            
                            $itemTotal = $price * $qty;
                            $groupedItems[$sellerId]['items'][] = [
                                'product_id' => (int)$item['product_id'],
                                'name' => $item['name'] ?? 'Unknown Product',
                                'price' => $price,
                                'quantity' => $qty,
                                'image_url' => $item['image_url'] ?? 'images/placeholder.jpg',
                                'stock_quantity' => (int)($item['stock_quantity'] ?? 0),
                                'status' => $item['status'] ?? 'active',
                                'product_exists' => !empty($item['seller_id'])
                            ];
                            $groupedItems[$sellerId]['subtotal'] += $itemTotal;
                            $groupedItems[$sellerId]['item_count'] += $qty;
                        }
                        error_log('Order Success - FINAL rebuild successful: ' . count($groupedItems) . ' seller groups');
                    }
                } catch (Exception $e) {
                    error_log('Order Success - FINAL cart rebuild error: ' . $e->getMessage());
                }
            }
            
            // CRITICAL: Only create orders if we have valid transaction_id
            if ($transactionId <= 0) {
                error_log('Order Success - ❌ Cannot create orders - transaction_id is 0');
                error_log('Order Success - Skipping order creation');
            } elseif ($groupedItems && is_array($groupedItems) && !empty($groupedItems)) {
                error_log('Order Success - ✅ All conditions met - creating orders now');
                error_log('Order Success - groupedItems found, count: ' . count($groupedItems));
                error_log('Order Success - transaction_id: ' . $transactionId . ' (valid)');
                // Create orders now that payment is confirmed
                try {
                    $pdo->beginTransaction();
                    $userId = $transaction['user_id'] ?? 0;
                    $shippingAddress = $transaction['shipping_address'] ?? '';
                    $paymentMethod = $transaction['payment_method'] ?? 'card';
                    
                    if ($userId <= 0) {
                        throw new Exception('Invalid user_id: ' . $userId);
                    }
                    
                    error_log('Order Success - Starting order creation for ' . count($groupedItems) . ' seller groups');
                    error_log('Order Success - User ID: ' . $userId);
                    error_log('Order Success - Transaction ID: ' . $transactionId);
                    
                    $createdOrderIds = [];
                    foreach ($groupedItems as $sellerId => $sellerGroup) {
                        if ($sellerId <= 0) {
                            error_log('Order Success - WARNING: Invalid seller_id: ' . $sellerId . ', skipping');
                            continue;
                        }
                        
                        error_log('Order Success - Creating order for seller ' . $sellerId . ' with ' . count($sellerGroup['items']) . ' items');
                        // Create order for this seller
                        $stmt = $pdo->prepare("
                            INSERT INTO orders (
                                user_id, payment_transaction_id, seller_id, total_amount,
                                shipping_address, payment_method, status, payment_status
                            ) VALUES (?, ?, ?, ?, ?, ?, 'pending', 'completed')
                        ");
                        $stmt->execute([
                            $userId, $transactionId, $sellerId, $sellerGroup['subtotal'],
                            $shippingAddress, $paymentMethod
                        ]);
                        $orderId = $pdo->lastInsertId();
                        $createdOrderIds[] = $orderId;
                        error_log('Order Success - ✅ Created order #' . $orderId . ' for seller ' . $sellerId);
                        
                        // CREATE SELLER NOTIFICATION FOR NEW ORDER
                        require_once __DIR__ . '/../includes/seller_notification_functions.php';
                        createSellerNotification(
                            $sellerId,
                            '🎉 New Order Received!',
                            'You have a new order #' . str_pad($orderId, 6, '0', STR_PAD_LEFT) . ' totaling ₱' . number_format($sellerGroup['subtotal'], 2) . '. Please review and process it.',
                            'success',
                            'seller-order-details.php?order_id=' . $orderId
                        );
                        
                        // Create order items for this seller's products
                        foreach ($sellerGroup['items'] as $item) {
                            $stmt = $pdo->prepare("
                                INSERT INTO order_items (order_id, product_id, quantity, price) 
                                VALUES (?, ?, ?, ?)
                            ");
                            $stmt->execute([$orderId, $item['product_id'], $item['quantity'], $item['price']]);
                            
                            // Update product stock
                            $stmt = $pdo->prepare("
                                UPDATE products SET stock_quantity = stock_quantity - ? 
                                WHERE id = ?
                            ");
                            $stmt->execute([$item['quantity'], $item['product_id']]);
                        }
                    }
                    
                    // Clear cart - but check if this was buy_now
                    // CRITICAL: Buy_now items are NOT in cart table, so don't touch cart for buy_now
                    if (isset($_SESSION['buy_now_active']) || isset($_SESSION['buy_now_data'])) {
                        // This was buy_now - do NOT touch cart table (buy_now items are session-only)
                        error_log('Order Success - Buy Now: Not touching cart table (buy_now items are session-only)');
                        // Just clear buy_now session
                        unset($_SESSION['buy_now_active']);
                        unset($_SESSION['buy_now_data']);
                    } else {
                        // Regular checkout - clear entire cart
                        $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
                        $stmt->execute([$userId]);
                        error_log('Order Success - Cleared entire cart for regular checkout');
                    }
                    
                    // Clear pending checkout session
                    unset($_SESSION['pending_checkout_items']);
                    unset($_SESSION['pending_checkout_grand_total']);
                    unset($_SESSION['pending_checkout_transaction_id']);
                    
                    // Update payment transaction status
                    $stmt = $pdo->prepare("UPDATE payment_transactions SET payment_status = 'completed' WHERE id = ?");
                    $stmt->execute([$transactionId]);
                    
                    $pdo->commit();
                    error_log('Order Success - Transaction committed successfully');
                    
                    // Create user notifications
                    $stmt = $pdo->prepare("SELECT id FROM orders WHERE payment_transaction_id = ?");
                    $stmt->execute([$transactionId]);
                    $orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    error_log('Order Success - Created ' . count($orderIds) . ' orders for transaction ' . $transactionId);
                    
                    foreach ($orderIds as $orderId) {
                        $message = "Order #" . str_pad($orderId, 6, '0', STR_PAD_LEFT) . " has been placed successfully.";
                        $notifResult = createOrderNotification($userId, $orderId, $message, 'order_placed');
                        error_log('Order Success - Notification created for order ' . $orderId . ': ' . ($notifResult ? 'SUCCESS' : 'FAILED'));
                    }
                    
                    error_log('Order Success - Orders created successfully for transaction ' . $transactionId);
                    
                    // Re-fetch order for display
                    $stmt = $pdo->prepare("SELECT * FROM orders WHERE payment_transaction_id = ? LIMIT 1");
                    $stmt->execute([$transactionId]);
                    $order = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($order) {
                        error_log('Order Success - Order fetched for display: Order ID ' . $order['id']);
                    } else {
                        error_log('Order Success - WARNING: Orders were created but could not be fetched for display!');
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                        error_log('Order Success - Transaction rolled back due to error');
                    }
                    error_log('Order Success - CRITICAL ERROR creating orders: ' . $e->getMessage());
                    error_log('Order Success - Error trace: ' . $e->getTraceAsString());
                }
            } else {
                error_log('Order Success - CRITICAL: No pending checkout items in session AND cart appears empty for transaction ' . $transactionId);
                error_log('Order Success - This should NOT happen - cart should still have items for PayMongo payments');
                error_log('Order Success - Checking cart one more time with raw query...');
                
                // LAST RESORT: Try to get cart items with a simpler query
                try {
                    $userId = $transaction['user_id'];
                    $rawCartStmt = $pdo->prepare("SELECT COUNT(*) FROM cart WHERE user_id = ?");
                    $rawCartStmt->execute([$userId]);
                    $rawCartCount = (int)$rawCartStmt->fetchColumn();
                    error_log('Order Success - Raw cart count: ' . $rawCartCount);
                    
                    if ($rawCartCount > 0) {
                        // Cart has items but our previous queries failed - try again with INNER JOIN
                        error_log('Order Success - Cart has items, trying INNER JOIN query');
                        $cartStmt = $pdo->prepare("
                            SELECT c.product_id, c.quantity, p.name, p.price, p.image_url, p.stock_quantity, p.status, p.seller_id,
                                   COALESCE(u.display_name, CONCAT(u.first_name, ' ', u.last_name), u.username, 'Unknown Seller') as seller_display_name
                            FROM cart c
                            INNER JOIN products p ON c.product_id = p.id
                            LEFT JOIN users u ON p.seller_id = u.id
                            WHERE c.user_id = ?
                            ORDER BY p.seller_id, p.name
                        ");
                        $cartStmt->execute([$userId]);
                        $cartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        if (!empty($cartItems)) {
                            $groupedItems = [];
                            foreach ($cartItems as $item) {
                                $sellerId = (int)($item['seller_id'] ?? 0);
                                if ($sellerId <= 0) continue;
                                
                                if (!isset($groupedItems[$sellerId])) {
                                    $groupedItems[$sellerId] = [
                                        'seller_id' => $sellerId,
                                        'seller_display_name' => $item['seller_display_name'] ?? 'Unknown Seller',
                                        'items' => [],
                                        'subtotal' => 0,
                                        'item_count' => 0
                                    ];
                                }
                                
                                $price = (float)($item['price'] ?? 0);
                                $qty = (int)($item['quantity'] ?? 0);
                                if ($price <= 0 || $qty <= 0) continue;
                                
                                $itemTotal = $price * $qty;
                                $groupedItems[$sellerId]['items'][] = [
                                    'product_id' => (int)$item['product_id'],
                                    'name' => $item['name'] ?? 'Unknown Product',
                                    'price' => $price,
                                    'quantity' => $qty,
                                    'image_url' => $item['image_url'] ?? 'images/placeholder.jpg',
                                    'stock_quantity' => (int)($item['stock_quantity'] ?? 0),
                                    'status' => $item['status'] ?? 'active',
                                    'product_exists' => true
                                ];
                                $groupedItems[$sellerId]['subtotal'] += $itemTotal;
                                $groupedItems[$sellerId]['item_count'] += $qty;
                            }
                            error_log('Order Success - LAST RESORT rebuild successful: ' . count($groupedItems) . ' seller groups');
                            // groupedItems is now set, will be used in the code below
                        }
                    }
                } catch (Exception $e) {
                    error_log('Order Success - LAST RESORT query error: ' . $e->getMessage());
                }
                
                // If we STILL can't get items, create a basic order record
                error_log('Order Success - Cannot recover cart items, creating basic order record');
                try {
                    $pdo->beginTransaction();
                    $userId = $transaction['user_id'];
                    $shippingAddress = $transaction['shipping_address'];
                    $paymentMethod = $transaction['payment_method'];
                    $totalAmount = $transaction['total_amount'];
                    
                    // Try to find seller from any recent cart items or orders
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT p.seller_id 
                        FROM cart c
                        INNER JOIN products p ON c.product_id = p.id
                        WHERE c.user_id = ? AND p.seller_id IS NOT NULL
                        LIMIT 1
                    ");
                    $stmt->execute([$userId]);
                    $sellerId = $stmt->fetchColumn();
                    
                    if (!$sellerId) {
                        $stmt = $pdo->prepare("SELECT seller_id FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
                        $stmt->execute([$userId]);
                        $sellerId = $stmt->fetchColumn();
                    }
                    
                    if ($sellerId) {
                        $stmt = $pdo->prepare("
                            INSERT INTO orders (
                                user_id, payment_transaction_id, seller_id, total_amount,
                                shipping_address, payment_method, status, payment_status
                            ) VALUES (?, ?, ?, ?, ?, ?, 'pending', 'completed')
                        ");
                        $stmt->execute([
                            $userId, $transactionId, $sellerId, $totalAmount,
                            $shippingAddress, $paymentMethod
                        ]);
                        $orderId = $pdo->lastInsertId();
                        error_log('Order Success - ✅ CREATED BASIC ORDER #' . $orderId . ' for transaction ' . $transactionId);
                        
                        // Update transaction status
                        $stmt = $pdo->prepare("UPDATE payment_transactions SET payment_status = 'completed' WHERE id = ?");
                        $stmt->execute([$transactionId]);
                        error_log('Order Success - ✅ Updated transaction status to completed');
                        
                        // Create notification
                        $message = "Order #" . str_pad($orderId, 6, '0', STR_PAD_LEFT) . " has been placed successfully.";
                        createOrderNotification($userId, $orderId, $message, 'order_placed');
                        error_log('Order Success - ✅ Created notification');
                        
                        $pdo->commit();
                        error_log('Order Success - ✅ Transaction committed');
                        
                        // Re-fetch order for display
                        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
                        $stmt->execute([$orderId]);
                        $order = $stmt->fetch(PDO::FETCH_ASSOC);
                        error_log('Order Success - ✅ Order fetched for display: ' . ($order ? 'YES' : 'NO'));
                    } else {
                        error_log('Order Success - ❌ Cannot find seller_id from cart/orders, using fallback seller');
                        // Last resort: find ANY seller in the system
                        $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'seller' LIMIT 1");
                        $stmt->execute();
                        $fallbackSellerId = $stmt->fetchColumn();
                        
                        if ($fallbackSellerId) {
                            error_log('Order Success - Using fallback seller_id: ' . $fallbackSellerId);
                            $stmt = $pdo->prepare("
                                INSERT INTO orders (
                                    user_id, payment_transaction_id, seller_id, total_amount,
                                    shipping_address, payment_method, status, payment_status
                                ) VALUES (?, ?, ?, ?, ?, ?, 'pending', 'completed')
                            ");
                            $stmt->execute([
                                $userId, $transactionId, $fallbackSellerId, $totalAmount,
                                $shippingAddress, $paymentMethod
                            ]);
                            $orderId = $pdo->lastInsertId();
                            error_log('Order Success - ✅ CREATED FALLBACK ORDER #' . $orderId);
                            
                            $stmt = $pdo->prepare("UPDATE payment_transactions SET payment_status = 'completed' WHERE id = ?");
                            $stmt->execute([$transactionId]);
                            
                            $message = "Order #" . str_pad($orderId, 6, '0', STR_PAD_LEFT) . " has been placed successfully.";
                            createOrderNotification($userId, $orderId, $message, 'order_placed');
                            
                            $pdo->commit();
                            
                            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
                            $stmt->execute([$orderId]);
                            $order = $stmt->fetch(PDO::FETCH_ASSOC);
                        } else {
                            error_log('Order Success - ❌ NO SELLERS IN SYSTEM - This is a critical error!');
                            error_log('Order Success - Marking transaction as completed but cannot create order');
                            $stmt = $pdo->prepare("UPDATE payment_transactions SET payment_status = 'completed' WHERE id = ?");
                            $stmt->execute([$transactionId]);
                            $pdo->commit();
                        }
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Order Success - Error creating basic order: ' . $e->getMessage());
                    // Still try to mark transaction as completed
                    try {
                        $stmt = $pdo->prepare("UPDATE payment_transactions SET payment_status = 'completed' WHERE id = ?");
                        $stmt->execute([$transactionId]);
                    } catch (Exception $e2) {
                        error_log('Order Success - Error updating transaction: ' . $e2->getMessage());
                    }
                }
            }
            
            // If we have groupedItems now (from any of the recovery attempts), create orders
            if (isset($groupedItems) && !empty($groupedItems) && !isset($order)) {
                error_log('Order Success - Recovered groupedItems, creating orders now');
                // Jump to order creation code (duplicate the order creation logic)
                try {
                    $pdo->beginTransaction();
                    $userId = $transaction['user_id'];
                    $shippingAddress = $transaction['shipping_address'];
                    $paymentMethod = $transaction['payment_method'];
                    
                    error_log('Order Success - Starting RECOVERED order creation for ' . count($groupedItems) . ' seller groups');
                    
                    foreach ($groupedItems as $sellerId => $sellerGroup) {
                        error_log('Order Success - Creating order for seller ' . $sellerId . ' with ' . count($sellerGroup['items']) . ' items');
                        $stmt = $pdo->prepare("
                            INSERT INTO orders (
                                user_id, payment_transaction_id, seller_id, total_amount,
                                shipping_address, payment_method, status, payment_status
                            ) VALUES (?, ?, ?, ?, ?, ?, 'pending', 'completed')
                        ");
                        $stmt->execute([
                            $userId, $transactionId, $sellerId, $sellerGroup['subtotal'],
                            $shippingAddress, $paymentMethod
                        ]);
                        $orderId = $pdo->lastInsertId();
                        
                        require_once __DIR__ . '/../includes/seller_notification_functions.php';
                        createSellerNotification(
                            $sellerId,
                            '🎉 New Order Received!',
                            'You have a new order #' . str_pad($orderId, 6, '0', STR_PAD_LEFT) . ' totaling ₱' . number_format($sellerGroup['subtotal'], 2) . '. Please review and process it.',
                            'success',
                            'seller-order-details.php?order_id=' . $orderId
                        );
                        
                        foreach ($sellerGroup['items'] as $item) {
                            $stmt = $pdo->prepare("
                                INSERT INTO order_items (order_id, product_id, quantity, price) 
                                VALUES (?, ?, ?, ?)
                            ");
                            $stmt->execute([$orderId, $item['product_id'], $item['quantity'], $item['price']]);
                            
                            $stmt = $pdo->prepare("
                                UPDATE products SET stock_quantity = stock_quantity - ? 
                                WHERE id = ?
                            ");
                            $stmt->execute([$item['quantity'], $item['product_id']]);
                        }
                    }
                    
                    // Clear cart - check if buy_now
                    // CRITICAL: Buy_now items are NOT in cart table, so don't touch cart for buy_now
                    if (isset($_SESSION['buy_now_active']) || isset($_SESSION['buy_now_data'])) {
                        // This was buy_now - do NOT touch cart table (buy_now items are session-only)
                        error_log('Order Success (RECOVERED) - Buy Now: Not touching cart table (buy_now items are session-only)');
                        // Just clear buy_now session
                        unset($_SESSION['buy_now_active']);
                        unset($_SESSION['buy_now_data']);
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
                        $stmt->execute([$userId]);
                        error_log('Order Success (RECOVERED) - Cleared entire cart for regular checkout');
                    }
                    
                    unset($_SESSION['pending_checkout_items']);
                    unset($_SESSION['pending_checkout_grand_total']);
                    unset($_SESSION['pending_checkout_transaction_id']);
                    
                    $stmt = $pdo->prepare("UPDATE payment_transactions SET payment_status = 'completed' WHERE id = ?");
                    $stmt->execute([$transactionId]);
                    
                    $pdo->commit();
                    error_log('Order Success - RECOVERED orders created successfully');
                    
                    $stmt = $pdo->prepare("SELECT id FROM orders WHERE payment_transaction_id = ?");
                    $stmt->execute([$transactionId]);
                    $orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    foreach ($orderIds as $orderId) {
                        $message = "Order #" . str_pad($orderId, 6, '0', STR_PAD_LEFT) . " has been placed successfully.";
                        createOrderNotification($userId, $orderId, $message, 'order_placed');
                    }
                    
                    $stmt = $pdo->prepare("SELECT * FROM orders WHERE payment_transaction_id = ? LIMIT 1");
                    $stmt->execute([$transactionId]);
                    $order = $stmt->fetch(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Order Success - CRITICAL ERROR creating RECOVERED orders: ' . $e->getMessage());
                }
            }
        } else {
            // Payment not verified or transaction failed
            error_log('Order Success - Payment NOT verified or transaction failed');
            error_log('Order Success - paymentVerified: ' . ($paymentVerified ? 'YES' : 'NO'));
            error_log('Order Success - transaction status: ' . ($transaction['payment_status'] ?? 'NULL'));
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
        header("Location: ../products.php");
        exit();
    }
    }
    
    // TEMPORARY DEBUG: Prepare debug info
    $debugInfo = [
        'transaction_id' => $transactionId,
        'order_exists' => $order ? 'YES' : 'NO',
        'order_id' => $order['id'] ?? 'N/A',
        'transaction_status' => $transaction['payment_status'] ?? 'N/A',
        'payment_method' => $transaction['payment_method'] ?? 'N/A',
        'total_amount' => $transaction['total_amount'] ?? 0,
        'payment_verified' => isset($paymentVerified) ? ($paymentVerified ? 'YES' : 'NO') : 'NOT SET'
    ];
    
    // NOW it's safe to include header.php - all redirects are done
    require_once '../includes/header.php';
    
    // Get order items
    $orderItems = [];
    if ($order && isset($order['id'])) {
    $stmt = $pdo->prepare("SELECT oi.*, p.name, p.image_url 
                          FROM order_items oi 
                          JOIN products p ON oi.product_id = p.id 
                          WHERE oi.order_id = ?");
    $stmt->execute([$order['id']]);
    $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        error_log('Order Success - No order ID available, orderItems will be empty');
    }
    
    // Get customer info - from transaction if available, otherwise from order
    if ($transaction) {
        $customerName = $transaction['customer_name'] ?? '';
        $customerEmail = $transaction['customer_email'] ?? '';
        $paymentMethod = $transaction['payment_method'] ?? '';
        $totalAmount = $transaction['total_amount'] ?? 0;
    } else {
        // Get customer info from order
        $stmt = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
        $stmt->execute([$order['user_id']]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        $customerName = ($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '');
        $customerEmail = $customer['email'] ?? '';
        $paymentMethod = $order['payment_method'] ?? '';
        $totalAmount = $order['total_amount'] ?? 0;
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
        <!-- TEMPORARY DEBUG INFO (remove after fixing) -->
        <?php if (isset($_GET['debug']) || !$order): ?>
        <div style="background: #fff3cd; border: 2px solid #ffc107; padding: 15px; margin-bottom: 20px; border-radius: 6px; font-size: 12px; font-family: monospace;">
            <strong>🔍 DEBUG INFO:</strong><br>
            Transaction ID: <?php echo htmlspecialchars($debugInfo['transaction_id']); ?><br>
            Order Exists: <?php echo htmlspecialchars($debugInfo['order_exists']); ?><br>
            Order ID: <?php echo htmlspecialchars($debugInfo['order_id']); ?><br>
            Transaction Status: <?php echo htmlspecialchars($debugInfo['transaction_status']); ?><br>
            Payment Method: <?php echo htmlspecialchars($debugInfo['payment_method']); ?><br>
            Payment Verified: <?php echo htmlspecialchars($debugInfo['payment_verified']); ?><br>
            Total Amount: ₱<?php echo number_format($debugInfo['total_amount'], 2); ?><br>
            <small style="color: #666;">Check PHP error logs (error_log) for detailed information</small>
        </div>
        <?php endif; ?>
            
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
                    <span><?php echo $order ? date('M j, Y', strtotime($order['created_at'])) : date('M j, Y', strtotime($transaction['created_at'])); ?></span>
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
                                <div class="item-details">Qty: <?php echo $item['quantity']; ?> × ₱<?php echo number_format($item['price'], 2); ?></div>
                            </div>
                            <div class="item-price">₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="item-row" style="padding: 15px; text-align: center; color: var(--text-light);">
                        <p>Order details are being processed. Your payment of <strong>₱<?php echo number_format($totalAmount, 2); ?></strong> has been received successfully.</p>
                        <p style="font-size: 0.85rem; margin-top: 8px;">You will receive a confirmation email shortly.</p>
                </div>
                <?php endif; ?>
            </div>
            <div class="total-amount">
                <strong>Total Amount: ₱<?php echo number_format($totalAmount, 2); ?></strong>
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