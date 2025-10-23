<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/header.php';

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$transactionId = isset($_GET['transaction_id']) ? (int)$_GET['transaction_id'] : 0;

if ($orderId <= 0 && $transactionId <= 0) {
    header("Location: ../products.php");
    exit();
}

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
        // Multi-seller order lookup by transaction_id
        $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE id = ?");
        $stmt->execute([$transactionId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$transaction) {
            header("Location: ../products.php");
            exit();
        }
        
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE payment_transaction_id = ? LIMIT 1");
        $stmt->execute([$transactionId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$order) {
        header("Location: ../products.php");
        exit();
    }
    
    // Get order items
    $stmt = $pdo->prepare("SELECT oi.*, p.name, p.image_url 
                          FROM order_items oi 
                          JOIN products p ON oi.product_id = p.id 
                          WHERE oi.order_id = ?");
    $stmt->execute([$order['id']]);
    $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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

// Include header
require_once '../includes/header.php';
?>

<style>
body {
    background: #f8f9fa !important;
    margin: 0;
    padding: 0;
}
</style>

<main style="background: #f8f9fa; min-height: 100vh; padding: 20px; margin-top: 180px;">
    <div class="container" style="max-width: 1200px; margin: 0 auto;">
        <div class="order-success-container" style="background: #ffffff; border-radius: 12px; padding: 30px; margin: 20px 0; box-shadow: 0 2px 10px rgba(0,0,0,0.15);">
            
            <!-- Success Header -->
            <div class="success-header" style="text-align: center; margin-bottom: 30px;">
                <div class="success-icon" style="width: 60px; height: 60px; background: linear-gradient(135deg, #28a745, #20c997); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; font-size: 30px; color: white; font-weight: bold;">✓</div>
                <h1 style="color: #130325; font-size: 2rem; margin-bottom: 10px; font-weight: 700;">Order Placed Successfully!</h1>
                <p style="color: #6c757d; font-size: 1rem; opacity: 0.9;">Thank you for your purchase. Your order has been received and is being processed.</p>
            </div>

            <!-- Compact Order Details -->
            <div class="order-details" style="background: rgba(255, 215, 54, 0.1); border: 1px solid rgba(255, 215, 54, 0.3); border-radius: 8px; padding: 20px; margin-bottom: 25px;">
                <h2 style="color: #130325; font-size: 1.4rem; margin-bottom: 15px; border-bottom: 2px solid #FFD736; padding-bottom: 8px;">Order Details</h2>
                <div class="order-info" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 14px;">
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #6c757d; font-weight: 600;">Order ID:</span>
                        <span style="color: #130325; font-weight: 700;">#<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #6c757d; font-weight: 600;">Date:</span>
                        <span style="color: #130325;"><?php echo date('M j, Y', strtotime($order['created_at'])); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #6c757d; font-weight: 600;">Customer:</span>
                        <span style="color: #130325;"><?php echo htmlspecialchars($customerName); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #6c757d; font-weight: 600;">Payment:</span>
                        <span style="color: #FFD736; font-weight: 600;"><?php echo htmlspecialchars($paymentMethodDisplay); ?></span>
                    </div>
                </div>
            </div>

            <!-- Compact Items List -->
            <div class="items-section" style="margin-bottom: 25px;">
                <h3 style="color: #130325; font-size: 1.2rem; margin-bottom: 15px;">Items Ordered</h3>
                <div class="items-list" style="max-height: 200px; overflow-y: auto;">
                    <?php foreach ($orderItems as $item): ?>
                        <div style="display: flex; align-items: center; padding: 10px 0; border-bottom: 1px solid rgba(255, 215, 54, 0.2);">
                            <img src="../<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" 
                                 style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px; margin-right: 12px;">
                            <div style="flex: 1;">
                                <div style="color: #130325; font-weight: 600; font-size: 14px;"><?php echo htmlspecialchars($item['name']); ?></div>
                                <div style="color: #6c757d; font-size: 12px; opacity: 0.8;">Qty: <?php echo $item['quantity']; ?> × ₱<?php echo number_format($item['price'], 2); ?></div>
                            </div>
                            <div style="color: #FFD736; font-weight: 600; font-size: 14px;">₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="text-align: right; margin-top: 15px; padding-top: 15px; border-top: 2px solid #FFD736;">
                    <strong style="color: #FFD736; font-size: 1.2rem;">Total Amount: ₱<?php echo number_format($totalAmount, 2); ?></strong>
                </div>
            </div>

            <!-- Compact Next Steps -->
            <div class="next-steps" style="background: rgba(255, 215, 54, 0.1); border: 1px solid rgba(255, 215, 54, 0.3); border-radius: 8px; padding: 20px; margin-bottom: 25px;">
                <h3 style="color: #130325; font-size: 1.2rem; margin-bottom: 15px;">What's Next?</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; font-size: 14px;">
                    <div style="text-align: center;">
                        <div style="width: 40px; height: 40px; background: #FFD736; color: #130325; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; font-weight: bold;">1</div>
                        <div style="color: #130325; font-weight: 600;">Order Confirmation</div>
                        <div style="color: #6c757d; opacity: 0.8; font-size: 12px;">Email sent to <?php echo htmlspecialchars($customerEmail); ?></div>
                    </div>
                    <div style="text-align: center;">
                        <div style="width: 40px; height: 40px; background: #FFD736; color: #130325; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; font-weight: bold;">2</div>
                        <div style="color: #130325; font-weight: 600;">Processing</div>
                        <div style="color: #6c757d; opacity: 0.8; font-size: 12px;">Order being prepared</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="width: 40px; height: 40px; background: #FFD736; color: #130325; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; font-weight: bold;">3</div>
                        <div style="color: #130325; font-weight: 600;">Delivery</div>
                        <div style="color: #6c757d; opacity: 0.8; font-size: 12px;">5-7 business days</div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons" style="display: flex; gap: 15px; justify-content: center;">
                <a href="../products.php" class="btn" style="background: #6c757d; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: 600; transition: all 0.3s;">Continue Shopping</a>
                <a href="../user-dashboard.php" class="btn" style="background: #FFD736; color: #130325; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: 600; transition: all 0.3s;">Dashboard</a>
            </div>
        </div>
    </div>
</main>

<?php require_once '../includes/footer.php'; ?>