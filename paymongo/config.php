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
=======
/**
 * PayMongo Configuration File
 * Update these settings with your actual PayMongo credentials and ngrok URL
 */

// PayMongo API Configuration
define('PAYMONGO_SECRET_KEY', 'sk_test_PZ1WNKqqTe4drEswNQhq7j3V'); // Replace with your actual secret key
define('PAYMONGO_PUBLIC_KEY', 'pk_test_Jg347cHmxmHKD2PTe9MKXpBT'); // Replace with your actual public key
define('PAYMONGO_BASE_URL', 'https://api.paymongo.com/v1');

// IMPORTANT: Replace this with your actual ngrok URL
// Example: https://abc123.ngrok-free.app
// Get your ngrok URL by running: ngrok http 80
define('NGROK_BASE_URL', 'https://nonfragilely-marked-wilfredo.ngrok-free.dev'); // Replace with your actual ngrok URL

// Payment URLs - Updated for current project structure
define('PAYMENT_SUCCESS_URL', NGROK_BASE_URL . '/PEST-CTRL-VER_1.6/PEST-CTRL-main/multi-seller-paymongo-success.php');
define('PAYMENT_CANCEL_URL', NGROK_BASE_URL . '/PEST-CTRL-VER_1.6/PEST-CTRL-main/multi-seller-checkout.php');

// Local URLs for testing (use these when testing locally)
define('LOCAL_SUCCESS_URL', 'http://localhost/PEST-CTRL_VER.1.3/paymongo/payment-success.php');
define('LOCAL_CANCEL_URL', 'http://localhost/PEST-CTRL_VER.1.3/paymongo/payment-cancel.php');

// Webhook Configuration (optional)
define('WEBHOOK_SECRET', 'whsk_bBCHLBWDHmTP1SRzsE2AeJ8e'); // Replace with your webhook secret

// Currency Settings
define('DEFAULT_CURRENCY', 'PHP');

// Payment Method Types
define('PAYMENT_METHOD_TYPES', ['card', 'gcash', 'grab_pay']);

// Email Settings
define('SEND_EMAIL_RECEIPT', true);
define('SHOW_LINE_ITEMS', true);
define('SHOW_DESCRIPTION', false);

/**
 * Get PayMongo Configuration Array
 */
function getPayMongoConfig() {
    return [
        'secret_key' => PAYMONGO_SECRET_KEY,
        'public_key' => PAYMONGO_PUBLIC_KEY,
        'base_url' => PAYMONGO_BASE_URL,
        'success_url' => PAYMENT_SUCCESS_URL,
        'cancel_url' => PAYMENT_CANCEL_URL,
        'webhook_secret' => WEBHOOK_SECRET,
        'currency' => DEFAULT_CURRENCY,
        'payment_method_types' => PAYMENT_METHOD_TYPES,
        'send_email_receipt' => SEND_EMAIL_RECEIPT,
        'show_line_items' => SHOW_LINE_ITEMS,
        'show_description' => SHOW_DESCRIPTION
    ];
}

/**
 * Instructions for Setup:
 * 
 * 1. Get your PayMongo API keys from https://dashboard.paymongo.com/
 * 2. Replace the placeholder keys above with your actual keys
 * 3. Start ngrok: ngrok http 80 (or your local port)
 * 4. Copy your ngrok URL and replace NGROK_BASE_URL above
 * 5. Test with sandbox keys first before going live
 * 
 * Example ngrok URL: https://abc123def456.ngrok-free.app
 */
?>
