<?php
require_once 'includes/header.php';
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'paymongo/config.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$paymentTransactionId = $_GET['transaction_id'] ?? null;

if (!$paymentTransactionId) {
    header("Location: cart.php");
    exit();
}

// Get payment transaction details
$paymentTransaction = getPaymentTransaction($paymentTransactionId);
if (!$paymentTransaction || $paymentTransaction['user_id'] != $userId) {
    header("Location: cart.php");
    exit();
}

// Get orders for this payment transaction
$orders = getOrdersByPaymentTransaction($paymentTransactionId);
$orderItems = getOrderItemsByPaymentTransaction($paymentTransactionId);

// Handle payment processing
$paymentStatus = $paymentTransaction['payment_status'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    if ($paymentTransaction['payment_method'] === 'paymongo') {
        // Process PayMongo payment
        try {
            // Create PayMongo checkout session
            $checkoutData = [
                'data' => [
                    'attributes' => [
                        'line_items' => [],
                        'payment_method_types' => ['card'],
                        'success_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/multi-seller-payment-success.php?transaction_id=' . $paymentTransactionId,
                        'cancel_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/multi-seller-checkout.php',
                        'description' => 'Multi-seller order payment',
                        'billing' => [
                            'name' => $paymentTransaction['customer_name'] ?: 'Customer',
                            'email' => $paymentTransaction['customer_email'] ?: '',
                            'phone' => $paymentTransaction['customer_phone'] ?: ''
                        ]
                    ]
                ]
            ];

            // Add line items for each seller
            foreach ($orders as $order) {
                $sellerItems = array_filter($orderItems, function($item) use ($order) {
                    return $item['seller_id'] == $order['seller_id'];
                });

                foreach ($sellerItems as $item) {
                    $checkoutData['data']['attributes']['line_items'][] = [
                        'currency' => 'PHP',
                        'amount' => (int)($item['price'] * 100), // Convert to centavos
                        'quantity' => $item['quantity'],
                        'name' => $item['product_name'],
                        'description' => 'From seller: ' . $item['seller_name']
                    ];
                }
            }

            // Make API call to PayMongo
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.paymongo.com/v1/checkout_sessions');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($checkoutData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
                'Accept: application/json'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $responseData = json_decode($response, true);
                $checkoutUrl = $responseData['data']['attributes']['checkout_url'];
                
                // Update payment transaction with PayMongo session ID
                updatePaymentTransactionStatus(
                    $paymentTransactionId, 
                    'pending', 
                    null, 
                    $responseData['data']['id']
                );
                
                // Redirect to PayMongo checkout
                header("Location: " . $checkoutUrl);
                exit();
            } else {
                $errors[] = 'Failed to create payment session. Please try again.';
            }
        } catch (Exception $e) {
            $errors[] = 'Payment processing error: ' . $e->getMessage();
        }
    } else {
        // For other payment methods, mark as completed (simulate)
        updatePaymentTransactionStatus($paymentTransactionId, 'completed', 'SIMULATED_PAYMENT');
        header("Location: multi-seller-payment-success.php?transaction_id=" . $paymentTransactionId);
        exit();
    }
}
?>

<style>
.payment-page {
    max-width: 800px;
    margin: 0 auto;
    padding: 20px;
}

.payment-header {
    text-align: center;
    margin-bottom: 30px;
}

.payment-header h1 {
    color: #F9F9F9;
    font-size: 2.5rem;
    margin-bottom: 10px;
}

.payment-summary {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 30px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    padding: 5px 0;
}

.summary-row:last-child {
    border-top: 2px solid #130325;
    padding-top: 15px;
    margin-top: 15px;
    font-weight: 700;
    font-size: 1.1rem;
}

.summary-label {
    color: #333;
}

.summary-value {
    color: #130325;
    font-weight: 600;
}

.payment-methods {
    background: white;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 30px;
}

.payment-method {
    border: 2px solid #dee2e6;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 15px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.payment-method:hover {
    border-color: #FFD736;
    background: #fffbf0;
}

.payment-method.selected {
    border-color: #FFD736;
    background: #fffbf0;
}

.payment-method-header {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
}

.payment-method-icon {
    width: 40px;
    height: 40px;
    margin-right: 15px;
    background: #130325;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #F9F9F9;
    font-weight: bold;
}

.payment-method-name {
    font-size: 1.2rem;
    font-weight: 600;
    color: #130325;
}

.payment-method-description {
    color: #666;
    font-size: 0.9rem;
}

.payment-button {
    width: 100%;
    background: linear-gradient(135deg, #FFD736, #e6c230);
    color: #130325;
    border: none;
    padding: 15px;
    border-radius: 8px;
    font-size: 1.1rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
}

.payment-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(255, 215, 54, 0.3);
}

.payment-button:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.error-message {
    background: #f8d7da;
    color: #721c24;
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 20px;
    border-left: 4px solid #dc3545;
}

.order-details {
    background: white;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 30px;
}

.order-details h3 {
    color: #130325;
    margin-bottom: 15px;
}

.seller-section {
    margin-bottom: 20px;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 4px;
    border-left: 4px solid #FFD736;
}

.seller-section:last-child {
    margin-bottom: 0;
}

.seller-name {
    font-weight: 600;
    color: #130325;
    margin-bottom: 10px;
}

.order-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #e9ecef;
}

.order-item:last-child {
    border-bottom: none;
}

.item-info {
    flex: 1;
}

.item-name {
    font-weight: 500;
    color: #333;
}

.item-details {
    color: #666;
    font-size: 0.9rem;
}

.item-total {
    font-weight: 600;
    color: #130325;
}
</style>

<div class="payment-page">
    <div class="payment-header">
        <h1>Complete Your Payment</h1>
        <p style="color: #F9F9F9;">Transaction ID: <?php echo $paymentTransactionId; ?></p>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="error-message">
            <strong>Payment Error:</strong>
            <ul style="margin: 10px 0 0 20px;">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Order Details -->
    <div class="order-details">
        <h3>Order Details</h3>
        
        <?php
        $groupedBySeller = [];
        foreach ($orderItems as $item) {
            $sellerId = $item['seller_id'];
            if (!isset($groupedBySeller[$sellerId])) {
                $groupedBySeller[$sellerId] = [
                    'seller_name' => $item['seller_name'],
                    'items' => []
                ];
            }
            $groupedBySeller[$sellerId]['items'][] = $item;
        }
        ?>
        
        <?php foreach ($groupedBySeller as $sellerId => $sellerData): ?>
            <div class="seller-section">
                <div class="seller-name"><?php echo htmlspecialchars($sellerData['seller_name']); ?></div>
                <?php foreach ($sellerData['items'] as $item): ?>
                    <div class="order-item">
                        <div class="item-info">
                            <div class="item-name"><?php echo htmlspecialchars($item['product_name']); ?></div>
                            <div class="item-details">₱<?php echo number_format($item['price'], 2); ?> × <?php echo $item['quantity']; ?></div>
                        </div>
                        <div class="item-total">₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Payment Summary -->
    <div class="payment-summary">
        <div class="summary-row">
            <span class="summary-label">Subtotal:</span>
            <span class="summary-value">₱<?php echo number_format($paymentTransaction['total_amount'], 2); ?></span>
        </div>
        <div class="summary-row">
            <span class="summary-label">Shipping:</span>
            <span class="summary-value">₱0.00</span>
        </div>
        <div class="summary-row">
            <span class="summary-label">Total Amount:</span>
            <span class="summary-value">₱<?php echo number_format($paymentTransaction['total_amount'], 2); ?></span>
        </div>
    </div>

    <!-- Payment Methods -->
    <div class="payment-methods">
        <h3 style="color: #130325; margin-bottom: 20px;">Choose Payment Method</h3>
        
        <form method="POST">
            <div class="payment-method selected" data-method="paymongo">
                <div class="payment-method-header">
                    <div class="payment-method-icon">💳</div>
                    <div class="payment-method-name">PayMongo (Credit/Debit Card)</div>
                </div>
                <div class="payment-method-description">
                    Secure payment processing with credit or debit card
                </div>
            </div>
            
            <button type="submit" name="process_payment" class="payment-button">
                Pay ₱<?php echo number_format($paymentTransaction['total_amount'], 2); ?> with PayMongo
            </button>
        </form>
    </div>
</div>

<script>
// Payment method selection
document.querySelectorAll('.payment-method').forEach(method => {
    method.addEventListener('click', function() {
        document.querySelectorAll('.payment-method').forEach(m => m.classList.remove('selected'));
        this.classList.add('selected');
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
