<?php
/**
 * PayMongo Configuration File
 * Updated for GITHUB_PEST-CTRL project structure
 */

// PayMongo API Configuration
define('PAYMONGO_SECRET_KEY', 'REPLACE_WITH_YOUR_SECRET_KEY'); // Replace with your actual secret key
define('PAYMONGO_PUBLIC_KEY', 'REPLACE_WITH_YOUR_PUBLIC_KEY'); // Replace with your actual public key
define('PAYMONGO_BASE_URL', 'https://api.paymongo.com/v1');

// Base URLs - Updated for current project
define('NGROK_BASE_URL', 'https://YOUR_NGROK_URL_HERE.ngrok-free.dev/GITHUB_PEST-CTRL'); // Replace with your actual ngrok URL
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
