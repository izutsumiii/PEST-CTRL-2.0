<?php
require_once 'includes/header.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

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

// Update payment status to completed if it's still pending
if ($paymentTransaction['payment_status'] === 'pending') {
    updatePaymentTransactionStatus($paymentTransactionId, 'completed');
    $paymentTransaction['payment_status'] = 'completed';
}
?>

<style>
.success-page {
    max-width: 800px;
    margin: 0 auto;
    padding: 20px;
    text-align: center;
}

.success-header {
    margin-bottom: 40px;
}

.success-icon {
    font-size: 4rem;
    color: #28a745;
    margin-bottom: 20px;
}

.success-title {
    color: #F9F9F9;
    font-size: 2.5rem;
    margin-bottom: 10px;
}

.success-subtitle {
    color: #F9F9F9;
    font-size: 1.2rem;
    margin-bottom: 30px;
}

.order-summary {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 30px;
    margin-bottom: 30px;
    text-align: left;
}

.summary-header {
    color: #130325;
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 20px;
    text-align: center;
}

.transaction-info {
    background: white;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.info-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    padding: 5px 0;
}

.info-label {
    color: #666;
    font-weight: 500;
}

.info-value {
    color: #130325;
    font-weight: 600;
}

.seller-orders {
    margin-top: 20px;
}

.seller-order {
    background: white;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 15px;
    border-left: 4px solid #FFD736;
}

.seller-order:last-child {
    margin-bottom: 0;
}

.seller-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #e9ecef;
}

.seller-name {
    font-size: 1.2rem;
    font-weight: 600;
    color: #130325;
}

.order-id {
    color: #666;
    font-size: 0.9rem;
}

.order-items {
    margin-bottom: 15px;
}

.order-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #f1f3f4;
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

.order-total {
    text-align: right;
    padding-top: 10px;
    border-top: 2px solid #130325;
}

.order-total-label {
    color: #666;
    font-size: 0.9rem;
}

.order-total-amount {
    color: #130325;
    font-size: 1.2rem;
    font-weight: 700;
}

.action-buttons {
    display: flex;
    gap: 15px;
    justify-content: center;
    margin-top: 30px;
}

.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-size: 1rem;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-block;
}

.btn-primary {
    background: linear-gradient(135deg, #FFD736, #e6c230);
    color: #130325;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(255, 215, 54, 0.3);
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #5a6268;
    transform: translateY(-2px);
}

@media (max-width: 768px) {
    .action-buttons {
        flex-direction: column;
        align-items: center;
    }
    
    .btn {
        width: 100%;
        max-width: 300px;
    }
}
</style>

<div class="success-page">
    <div class="success-header">
        <div class="success-icon">✅</div>
        <h1 class="success-title">Payment Successful!</h1>
        <p class="success-subtitle">Your multi-seller order has been placed successfully</p>
    </div>

    <div class="order-summary">
        <h2 class="summary-header">Order Summary</h2>
        
        <div class="transaction-info">
            <div class="info-row">
                <span class="info-label">Transaction ID:</span>
                <span class="info-value">#<?php echo $paymentTransactionId; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Payment Method:</span>
                <span class="info-value"><?php echo ucfirst($paymentTransaction['payment_method']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Payment Status:</span>
                <span class="info-value" style="color: #28a745; font-weight: 700;"><?php echo ucfirst($paymentTransaction['payment_status']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Order Date:</span>
                <span class="info-value"><?php echo date('F j, Y g:i A', strtotime($paymentTransaction['created_at'])); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Total Amount:</span>
                <span class="info-value" style="font-size: 1.2rem; color: #FFD736; font-weight: 700;">₱<?php echo number_format($paymentTransaction['total_amount'], 2); ?></span>
            </div>
        </div>

        <div class="seller-orders">
            <h3 style="color: #130325; margin-bottom: 20px; text-align: center;">Orders by Seller</h3>
            
            <?php
            $groupedBySeller = [];
            foreach ($orderItems as $item) {
                $sellerId = $item['seller_id'];
                if (!isset($groupedBySeller[$sellerId])) {
                    $groupedBySeller[$sellerId] = [
                        'seller_name' => $item['seller_name'],
                        'order_id' => null,
                        'items' => [],
                        'subtotal' => 0
                    ];
                }
                $groupedBySeller[$sellerId]['items'][] = $item;
                $groupedBySeller[$sellerId]['subtotal'] += $item['price'] * $item['quantity'];
                
                // Get order ID for this seller
                if (!$groupedBySeller[$sellerId]['order_id']) {
                    foreach ($orders as $order) {
                        if ($order['seller_id'] == $sellerId) {
                            $groupedBySeller[$sellerId]['order_id'] = $order['id'];
                            break;
                        }
                    }
                }
            }
            ?>
            
            <?php foreach ($groupedBySeller as $sellerId => $sellerData): ?>
                <div class="seller-order">
                    <div class="seller-header">
                        <div class="seller-name"><?php echo htmlspecialchars($sellerData['seller_name']); ?></div>
                        <div class="order-id">Order #<?php echo $sellerData['order_id']; ?></div>
                    </div>
                    
                    <div class="order-items">
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
                    
                    <div class="order-total">
                        <div class="order-total-label">Subtotal:</div>
                        <div class="order-total-amount">₱<?php echo number_format($sellerData['subtotal'], 2); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="action-buttons">
        <a href="user-dashboard.php" class="btn btn-primary">View My Orders</a>
        <a href="index.php" class="btn btn-secondary">Continue Shopping</a>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
