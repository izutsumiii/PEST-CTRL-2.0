<?php
// PayMongo Configuration
// Replace these with your actual PayMongo API keys

// Test API Keys (replace with your actual keys)
define('PAYMONGO_SECRET_KEY', 'sk_test_PxE3JBr7SUfUP6NmAdvQsc6e');
define('PAYMONGO_PUBLIC_KEY', 'pk_test_ZEYJppLbVBzLodtW9mK7q4TJ');

// Production API Keys (uncomment and replace when going live)
// define('PAYMONGO_SECRET_KEY', 'sk_live_your_live_secret_key');
// define('PAYMONGO_PUBLIC_KEY', 'pk_live_your_live_public_key');

// Base URLs
define('NGROK_BASE_URL', 'https://nonfragilely-marked-wilfredo.ngrok-free.dev/GITHUB_PEST-CTRL');
define('LOCAL_BASE_URL', 'http://localhost/GITHUB_PEST-CTRL');

// Success and Cancel URLs
define('PAYMENT_SUCCESS_URL', NGROK_BASE_URL . '/paymongo/order-success.php');
define('PAYMENT_CANCEL_URL', NGROK_BASE_URL . '/paymongo/order-failure.php');

// Local URLs for testing
define('LOCAL_SUCCESS_URL', LOCAL_BASE_URL . '/paymongo/order-success.php');
define('LOCAL_CANCEL_URL', LOCAL_BASE_URL . '/paymongo/order-failure.php');

// PayMongo API URLs
define('PAYMONGO_API_URL', 'https://api.paymongo.com/v1');
define('PAYMONGO_CHECKOUT_URL', PAYMONGO_API_URL . '/checkout_sessions');
define('PAYMONGO_PAYMENTS_URL', PAYMONGO_API_URL . '/payments');

// Debug mode
define('PAYMONGO_DEBUG', true);

// Log file path
define('PAYMONGO_LOG_FILE', 'paymongo_debug.log');

// Function to log PayMongo requests
function logPayMongoRequest($message, $data = null) {
    if (PAYMONGO_DEBUG) {
        $logMessage = date('Y-m-d H:i:s') . " - " . $message;
        if ($data) {
            $logMessage .= " - Data: " . json_encode($data);
        }
        $logMessage .= "\n";
        file_put_contents(PAYMONGO_LOG_FILE, $logMessage, FILE_APPEND | LOCK_EX);
    }
}

// Function to get current base URL
function getCurrentBaseUrl() {
    if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'ngrok') !== false) {
        return NGROK_BASE_URL;
    }
    return LOCAL_BASE_URL;
}

// Function to get success URL
function getSuccessUrl($orderId = null) {
    $baseUrl = getCurrentBaseUrl();
    $url = $baseUrl . '/order-success.php';
    if ($orderId) {
        $url .= '?order_id=' . $orderId;
    }
    return $url;
}

// Function to get cancel URL
function getCancelUrl() {
    $baseUrl = getCurrentBaseUrl();
    return $baseUrl . '/cart.php';
}
?>
