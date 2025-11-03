<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
// Offline payment processing only

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: ../login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$paymentTransactionId = $_GET['transaction_id'] ?? ($_POST['transaction_id'] ?? null);

if (!$paymentTransactionId) {
    header("Location: ../cart.php");
    exit();
}

// Get payment transaction details
$paymentTransaction = getPaymentTransaction($paymentTransactionId);
if (!$paymentTransaction || $paymentTransaction['user_id'] != $userId) {
    header("Location: ../cart.php");
    exit();
}

// Get orders for this payment transaction
$orders = getOrdersByPaymentTransaction($paymentTransactionId);
$orderItems = getOrderItemsByPaymentTransaction($paymentTransactionId);

// Handle payment processing
$paymentStatus = $paymentTransaction['payment_status'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if this is an AJAX request (JSON)
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        // Handle AJAX request from JavaScript
        $input = json_decode(file_get_contents('php://input'), true);
        
        if ($input) {
            // Create a new payment transaction for this AJAX request
            $stmt = $pdo->prepare("INSERT INTO payment_transactions (user_id, total_amount, payment_method, payment_status, shipping_address, customer_name, customer_email, customer_phone, created_at) VALUES (?, ?, 'cod', 'completed', ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $userId,
                $input['amount'] ?? 0,
                $input['shipping_address'] ?? 'N/A',
                $input['customer_name'] ?? 'Customer',
                $input['customer_email'] ?? '',
                $input['customer_phone'] ?? ''
            ]);
            
            $newTransactionId = $pdo->lastInsertId();
            
            // Return offline success response
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'checkout_session_id' => 'offline_' . $newTransactionId,
                'checkout_url' => 'paymongo/order-success.php?transaction_id=' . $newTransactionId,
                'message' => 'Offline payment processed successfully'
            ]);
            exit();
        }
    }
    
    // Handle form submission
    if (isset($_POST['process_payment'])) {
        // Process offline payment (simulate success)
        updatePaymentTransactionStatus($paymentTransactionId, 'completed', 'OFFLINE_PAYMENT');
        
        // Create seller notifications for successful payment
        try {
            require_once '../includes/seller_notification_functions.php';
            $stmt = $pdo->prepare("SELECT o.id, p.seller_id FROM orders o JOIN order_items oi ON o.id = oi.order_id JOIN products p ON oi.product_id = p.id WHERE o.payment_transaction_id = ?");
            $stmt->execute([$paymentTransactionId]);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($orders as $order) {
                $sellerMessage = "New order #" . str_pad($order['id'], 6, '0', STR_PAD_LEFT) . " has been placed!";
                createSellerNotification($order['seller_id'], "New Order", $sellerMessage, "info");
            }
        } catch (Exception $e) {
            error_log('Error creating seller notifications: ' . $e->getMessage());
        }
        
        header("Location: order-success.php?transaction_id=" . $paymentTransactionId);
        exit();
    }
}

// Include header after all redirects are handled
require_once '../includes/header.php';
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
    color:rgb(39, 37, 37);
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
        <p style="color:rgb(255, 255, 255);">Transaction ID: <?php echo $paymentTransactionId; ?></p>
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
            <div class="payment-method selected" data-method="cod">
                <div class="payment-method-header">
                    <div class="payment-method-icon">💰</div>
                    <div class="payment-method-name">Cash on Delivery (COD)</div>
                </div>
                <div class="payment-method-description">
                    Pay when your order arrives - No online payment required
                </div>
            </div>
            
            <button type="submit" name="process_payment" class="payment-button">
                Complete Order - ₱<?php echo number_format($paymentTransaction['total_amount'], 2); ?> (COD)
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

<?php require_once '../includes/footer.php'; ?>
