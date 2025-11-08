<?php
/**
 * PayMongo Configuration File
 * Updated for GITHUB_PEST-CTRL project structure
 */

// PayMongo API Configuration
define('PAYMONGO_SECRET_KEY', 'sk_test_PxE3JBr7SUfUP6NmAdvQsc6e'); // Replace with your actual secret key
define('PAYMONGO_PUBLIC_KEY', 'pk_test_ZEYJppLbVBzLodtW9mK7q4TJ'); // Replace with your actual public key
define('PAYMONGO_BASE_URL', 'https://api.paymongo.com/v1');

// Base URLs - Updated for current project
// IMPORTANT: Include the project subdirectory in the ngrok URL since ngrok forwards to localhost:80 root
define('NGROK_BASE_URL', 'https://nonfragilely-marked-wilfredo.ngrok-free.dev/GITHUB_PEST-CTRL'); // Include subdirectory
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
    // PayMongo requires HTTPS URLs for redirects
    // Always use ngrok URL for PayMongo redirects (even in local development)
    // Check if we're accessing via ngrok
    if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'ngrok') !== false) {
        return NGROK_BASE_URL;
    }
    // For local development, always use ngrok (PayMongo requires HTTPS)
    // This ensures PayMongo can redirect properly
    return NGROK_BASE_URL;
}

// Function to get success URL
function getSuccessUrl($transactionId = null) {
    $baseUrl = getCurrentBaseUrl();
    // Always use the PayMongo order success page
    // Note: PayMongo will append checkout_session_id automatically to the URL as a query parameter
    $url = $baseUrl . '/paymongo/order-success.php';
    if ($transactionId) {
        // Pass payment transaction id so the success page can load details
        $url .= '?transaction_id=' . $transactionId;
    }
    // Ensure URL is properly encoded and uses HTTPS (required by PayMongo)
    error_log('PayMongo Config - Success URL: ' . $url);
    return $url;
}

// Function to get cancel URL
function getCancelUrl($transactionId = null) {
    $baseUrl = getCurrentBaseUrl();
    // Redirect to order-failure.php when user cancels or goes back
    $url = $baseUrl . '/paymongo/order-failure.php';
    if ($transactionId) {
        // Pass payment transaction id so the failure page can update status
        $url .= '?transaction_id=' . $transactionId;
    }
    return $url;
}

?>
