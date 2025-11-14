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
    error_log('Order Failure - Session started: ' . session_id() . ' | Cookie domain: ' . $cookieDomain . ' | Secure: ' . ($isSecure ? 'yes' : 'no'));
} else {
    // Session already started
    error_log('Order Failure - Session already active: ' . session_id());
    session_start();
}

require_once '../config/database.php';

// CRITICAL FIX: Restore session from remember_token cookie BEFORE including functions.php
// This prevents checkSessionTimeout() from logging out the user
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    error_log('Order Failure - Session lost, attempting to restore from remember_token cookie');
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
            error_log('Order Failure - Session restored from remember_token for user: ' . $user['id']);
        } else {
            error_log('Order Failure - Invalid remember_token cookie');
        }
    } catch (Exception $e) {
        error_log('Order Failure - Error restoring session from remember_token: ' . $e->getMessage());
    }
}

// CRITICAL: Update last_activity BEFORE including functions.php to prevent timeout
// AND set flag to skip timeout check since we're handling session restoration manually
if (isset($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
    $_SESSION['skip_timeout_check'] = true;
    error_log('Order Failure - Updated last_activity for user: ' . $_SESSION['user_id'] . ', skip_timeout_check set');
}

// NOW include functions.php - checkSessionTimeout() will be skipped for this request
require_once '../includes/functions.php';

$transactionId = isset($_GET['transaction_id']) ? (int)$_GET['transaction_id'] : 0;
$checkoutSessionId = isset($_GET['checkout_session_id']) ? $_GET['checkout_session_id'] : null;
$errorMessage = $_GET['error'] ?? $_GET['message'] ?? 'Payment was cancelled or failed';

// Check for PayMongo-specific error messages
$paymongoError = null;
if (isset($_GET['paymongo_error'])) {
    $paymongoError = urldecode($_GET['paymongo_error']);
} elseif (isset($_GET['error_description'])) {
    $paymongoError = urldecode($_GET['error_description']);
}

// If PayMongo redirects with checkout_session_id, find the transaction_id and verify payment status
if ($checkoutSessionId) {
    error_log('Order Failure - PayMongo redirect with checkout_session_id: ' . $checkoutSessionId);
    try {
        require_once 'config.php';
        
        // Fetch checkout session from PayMongo to get reference_number and payment status
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
        
        if ($http_code === 200) {
            $response_data = json_decode($response, true);
            
            // Get reference_number to find transaction_id
            if (isset($response_data['data']['attributes']['reference_number'])) {
                $referenceNumber = $response_data['data']['attributes']['reference_number'];
                // Reference number format: P-C-{transaction_id}
                if (preg_match('/P-C-(\d+)/', $referenceNumber, $matches)) {
                    $transactionId = (int)$matches[1];
                    error_log('Order Failure - Found transaction_id from reference_number: ' . $transactionId);
                }
            }
            
            // Check for errors in the checkout session
            if (isset($response_data['errors'])) {
                $errors = $response_data['errors'];
                if (is_array($errors) && !empty($errors)) {
                    $firstError = $errors[0];
                    if (isset($firstError['detail'])) {
                        $paymongoError = $firstError['detail'];
                        error_log('Order Failure - PayMongo error: ' . $paymongoError);
                    } elseif (isset($firstError['message'])) {
                        $paymongoError = $firstError['message'];
                        error_log('Order Failure - PayMongo error: ' . $paymongoError);
                    }
                }
            }
            
            // Check payment status - if it's actually paid, redirect to success page
            if (isset($response_data['data']['attributes']['payment_status'])) {
                $paymongoPaymentStatus = $response_data['data']['attributes']['payment_status'];
                error_log('Order Failure - PayMongo payment status: ' . $paymongoPaymentStatus);
                
                if ($paymongoPaymentStatus === 'paid' && $transactionId > 0) {
                    // Payment was actually successful - redirect to success page
                    error_log('Order Failure - Payment is actually paid, redirecting to success page');
                    header("Location: order-success.php?transaction_id=" . $transactionId . "&checkout_session_id=" . $checkoutSessionId);
                    exit();
                }
            }
            
            // Check for last_payment_error in attributes
            if (isset($response_data['data']['attributes']['last_payment_error'])) {
                $lastError = $response_data['data']['attributes']['last_payment_error'];
                if (isset($lastError['detail'])) {
                    $paymongoError = $lastError['detail'];
                } elseif (isset($lastError['message'])) {
                    $paymongoError = $lastError['message'];
                }
                error_log('Order Failure - Last payment error from PayMongo: ' . $paymongoError);
            }
        } else {
            // If API call failed, log it
            error_log('Order Failure - Failed to fetch checkout session (HTTP ' . $http_code . ')');
            $errorData = json_decode($response, true);
            if (isset($errorData['errors'])) {
                $errors = $errorData['errors'];
                if (is_array($errors) && !empty($errors)) {
                    $firstError = $errors[0];
                    if (isset($firstError['detail'])) {
                        $paymongoError = $firstError['detail'];
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log('Order Failure - Error fetching checkout session: ' . $e->getMessage());
    }
}

// CRITICAL: Clear pending checkout session - payment failed, don't create orders
unset($_SESSION['pending_checkout_items']);
unset($_SESSION['pending_checkout_grand_total']);
unset($_SESSION['pending_checkout_transaction_id']);

// If we have a transaction ID, update the payment status to failed
if ($transactionId > 0) {
    try {
        $stmt = $pdo->prepare("UPDATE payment_transactions SET payment_status = 'failed' WHERE id = ?");
        $stmt->execute([$transactionId]);
        
        // Only update existing orders (shouldn't exist for PayMongo, but just in case)
        $stmt = $pdo->prepare("UPDATE orders SET payment_status = 'failed' WHERE payment_transaction_id = ?");
        $stmt->execute([$transactionId]);
        
        error_log('Order Failure - Updated transaction ' . $transactionId . ' status to failed');
    } catch (Exception $e) {
        error_log("Failed to update payment status: " . $e->getMessage());
    }
}

// Get payment transaction details for display
$paymentTransaction = null;
if ($transactionId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE id = ?");
        $stmt->execute([$transactionId]);
        $paymentTransaction = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Failed to fetch payment transaction: " . $e->getMessage());
    }
}

// Include header
require_once '../includes/header.php';
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
    --error-red: #dc3545;
}

body {
    background: var(--bg-light) !important;
    margin: 0;
    padding: 0;
}

.order-failure-wrapper {
    background: var(--bg-light);
    min-height: calc(100vh - 120px);
    padding: 20px;
    margin-top: 100px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.order-failure-container {
    background: var(--bg-white);
    border-radius: 12px;
    padding: 24px;
    margin: 0 auto;
    max-width: 900px;
    width: 100%;
    box-shadow: 0 2px 12px rgba(19, 3, 37, 0.08);
}

.failure-header {
    text-align: center;
    margin-bottom: 20px;
}

.failure-icon {
    width: 64px;
    height: 64px;
    background: linear-gradient(135deg, var(--error-red), #c82333);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 12px;
    font-size: 32px;
    color: white;
    font-weight: bold;
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
}

.page-title,
.failure-header h1 {
    color: var(--error-red);
    font-size: 1.35rem;
    font-weight: 600;
    letter-spacing: -0.3px;
    line-height: 1.2;
    margin: 0 0 8px 0;
}

.failure-header p {
    color: var(--text-light);
    font-size: 0.9rem;
    margin: 0;
}

.transaction-details {
    background: rgba(220, 53, 69, 0.05);
    border: 1px solid rgba(220, 53, 69, 0.2);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
}

.transaction-details h2 {
    color: var(--primary-dark);
    font-size: 1.1rem;
    font-weight: 600;
    margin: 0 0 12px 0;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--error-red);
}

.transaction-info {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    font-size: 0.875rem;
}

.transaction-info > div {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.transaction-info span:first-child {
    color: var(--text-light);
    font-weight: 500;
}

.transaction-info span:last-child {
    color: var(--primary-dark);
    font-weight: 600;
}

.status-badge {
    color: var(--error-red);
    font-weight: 600;
    background: rgba(220, 53, 69, 0.15);
    padding: 4px 12px;
    border-radius: 20px;
    border: 1px solid var(--error-red);
    font-size: 0.75rem;
}

.help-section {
    background: rgba(19, 3, 37, 0.03);
    border: 1px solid var(--border-light);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
}

.help-section h3 {
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

.btn-info {
    background: var(--primary-dark);
    color: var(--bg-white);
}

.btn-info:hover {
    background: #0d021f;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(19, 3, 37, 0.3);
}

@media (max-width: 768px) {
    .order-failure-wrapper {
        margin-top: 80px;
        padding: 12px;
        min-height: calc(100vh - 80px);
    }
    
    .order-failure-container {
        padding: 16px;
    }
    
    .failure-icon {
        width: 56px;
        height: 56px;
        font-size: 28px;
    }
    
    .page-title,
    .failure-header h1 {
        font-size: 1.2rem;
    }
    
    .transaction-info {
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
    .order-failure-wrapper {
        padding: 8px;
    }
    
    .order-failure-container {
        padding: 12px;
    }
}
</style>

<main class="order-failure-wrapper">
    <div class="order-failure-container">
        <!-- Failure Header -->
        <div class="failure-header">
            <div class="failure-icon">✗</div>
            <h1 class="page-title">Payment Failed</h1>
            <?php if ($paymongoError): ?>
                <p style="color: var(--error-red); font-weight: 600; margin-bottom: 8px;"><?php echo htmlspecialchars($paymongoError); ?></p>
                <?php if ($errorMessage !== 'Payment was cancelled or failed'): ?>
                    <p style="color: var(--text-light); font-size: 0.85rem;"><?php echo htmlspecialchars($errorMessage); ?></p>
                <?php endif; ?>
            <?php else: ?>
                <p><?php echo htmlspecialchars($errorMessage); ?></p>
            <?php endif; ?>
            
            <?php 
            // Handle specific PayMongo error messages
            $displayMessage = $paymongoError ? $paymongoError : $errorMessage;
            if (stripos($displayMessage, 'expired') !== false || stripos($displayMessage, 'expired status') !== false): ?>
                <div style="background: rgba(19, 3, 37, 0.05); border: 1px solid var(--border-light); border-radius: 8px; padding: 12px; margin-top: 12px; font-size: 0.875rem; color: var(--text-dark);">
                    <strong style="color: var(--primary-dark);">💡 What this means:</strong> The payment source expired before the payment could be completed. Please try again with a fresh payment method.
                </div>
            <?php endif; ?>
            
            <?php if (!$checkoutSessionId && !$transactionId): ?>
                <div style="background: rgba(19, 3, 37, 0.05); border: 1px solid var(--border-light); border-radius: 8px; padding: 12px; margin-top: 12px; font-size: 0.875rem; color: var(--text-dark);">
                    <strong style="color: var(--primary-dark);">ℹ️ Note:</strong> If you saw an "expired source" error on PayMongo's page, the payment was cancelled. You can try again from your cart.
                </div>
            <?php endif; ?>
        </div>

        <?php if ($paymentTransaction): ?>
        <!-- Compact Transaction Details -->
        <div class="transaction-details">
            <h2>Transaction Details</h2>
            <div class="transaction-info">
                <div>
                    <span>Transaction ID:</span>
                    <span>#<?php echo str_pad($paymentTransaction['id'], 6, '0', STR_PAD_LEFT); ?></span>
                </div>
                <div>
                    <span>Date:</span>
                    <span><?php echo date('M j, Y g:i A', strtotime($paymentTransaction['created_at'])); ?></span>
                </div>
                <div>
                    <span>Amount:</span>
                    <span>₱<?php echo number_format($paymentTransaction['total_amount'], 2); ?></span>
                </div>
                <div>
                    <span>Status:</span>
                    <span class="status-badge">Failed</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Compact Help Section -->
        <div class="help-section">
            <h3>What can you do?</h3>
            <div class="steps-grid">
                <div class="step-item">
                    <div class="step-number">1</div>
                    <div class="step-title">Try Again</div>
                    <div class="step-desc">Retry your payment</div>
                </div>
                <div class="step-item">
                    <div class="step-number">2</div>
                    <div class="step-title">Check Payment</div>
                    <div class="step-desc">Verify your payment method</div>
                </div>
                <div class="step-item">
                    <div class="step-number">3</div>
                    <div class="step-title">Contact Support</div>
                    <div class="step-desc">Get help if needed</div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons">
            <a href="multi-seller-checkout.php" class="btn btn-primary">Try Again</a>
            <a href="../products.php" class="btn btn-secondary">Continue Shopping</a>
            <a href="../user-dashboard.php" class="btn btn-info">View Orders</a>
        </div>
    </div>
</main>

<?php require_once '../includes/footer.php'; ?>