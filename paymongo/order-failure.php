<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

$transactionId = $_GET['transaction_id'] ?? null;
$errorMessage = $_GET['error'] ?? 'Payment was cancelled or failed';

// If we have a transaction ID, update the payment status
if ($transactionId) {
    try {
        $stmt = $pdo->prepare("UPDATE payment_transactions SET status = 'failed' WHERE id = ?");
        $stmt->execute([$transactionId]);
        
        $stmt = $pdo->prepare("UPDATE orders SET payment_status = 'failed' WHERE payment_transaction_id = ?");
        $stmt->execute([$transactionId]);
    } catch (Exception $e) {
        error_log("Failed to update payment status: " . $e->getMessage());
    }
}

// Get payment transaction details for display
$paymentTransaction = null;
if ($transactionId) {
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
body {
    background: #130325 !important;
    margin: 0;
    padding: 0;
}
</style>

<main style="background: #130325; min-height: 100vh; padding: 20px; margin-top: 180px;">
    <div class="container" style="max-width: 1200px; margin: 0 auto;">
        <div class="order-failure-container" style="background: #1a0a2e; border-radius: 12px; padding: 30px; margin: 20px 0;">
            
            <!-- Failure Header -->
            <div class="failure-header" style="text-align: center; margin-bottom: 30px;">
                <div class="failure-icon" style="width: 60px; height: 60px; background: linear-gradient(135deg, #dc3545, #c82333); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; font-size: 30px; color: white; font-weight: bold;">✗</div>
                <h1 style="color: #dc3545; font-size: 2rem; margin-bottom: 10px; font-weight: 700;">Payment Failed</h1>
                <p style="color: #F9F9F9; font-size: 1rem; opacity: 0.9;"><?php echo htmlspecialchars($errorMessage); ?></p>
            </div>

            <?php if ($paymentTransaction): ?>
            <!-- Compact Transaction Details -->
            <div class="transaction-details" style="background: rgba(220, 53, 69, 0.05); border: 1px solid rgba(220, 53, 69, 0.3); border-radius: 8px; padding: 20px; margin-bottom: 25px;">
                <h2 style="color: #dc3545; font-size: 1.4rem; margin-bottom: 15px; border-bottom: 2px solid #dc3545; padding-bottom: 8px;">Transaction Details</h2>
                <div class="transaction-info" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 14px;">
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #F9F9F9; font-weight: 600;">Transaction ID:</span>
                        <span style="color: #dc3545; font-weight: 700;">#<?php echo str_pad($paymentTransaction['id'], 6, '0', STR_PAD_LEFT); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #F9F9F9; font-weight: 600;">Date:</span>
                        <span style="color: #F9F9F9;"><?php echo date('M j, Y g:i A', strtotime($paymentTransaction['created_at'])); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #F9F9F9; font-weight: 600;">Amount:</span>
                        <span style="color: #F9F9F9; font-weight: 600;">₱<?php echo number_format($paymentTransaction['total_amount'], 2); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #F9F9F9; font-weight: 600;">Status:</span>
                        <span style="color: #dc3545; font-weight: 600; background: rgba(220, 53, 69, 0.2); padding: 4px 12px; border-radius: 20px; border: 1px solid #dc3545;">Failed</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Compact Help Section -->
            <div class="help-section" style="background: rgba(255, 215, 54, 0.05); border: 1px solid rgba(255, 215, 54, 0.3); border-radius: 8px; padding: 20px; margin-bottom: 25px;">
                <h3 style="color: #FFD736; font-size: 1.2rem; margin-bottom: 15px;">What can you do?</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; font-size: 14px;">
                    <div style="text-align: center;">
                        <div style="width: 40px; height: 40px; background: #FFD736; color: #130325; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; font-weight: bold;">1</div>
                        <div style="color: #F9F9F9; font-weight: 600;">Try Again</div>
                        <div style="color: #F9F9F9; opacity: 0.8; font-size: 12px;">Retry your payment</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="width: 40px; height: 40px; background: #FFD736; color: #130325; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; font-weight: bold;">2</div>
                        <div style="color: #F9F9F9; font-weight: 600;">Check Payment</div>
                        <div style="color: #F9F9F9; opacity: 0.8; font-size: 12px;">Verify your payment method</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="width: 40px; height: 40px; background: #FFD736; color: #130325; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; font-weight: bold;">3</div>
                        <div style="color: #F9F9F9; font-weight: 600;">Contact Support</div>
                        <div style="color: #F9F9F9; opacity: 0.8; font-size: 12px;">Get help if needed</div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons" style="display: flex; gap: 15px; justify-content: center;">
                <a href="../paymongo/multi-seller-checkout.php" class="btn" style="background: #FFD736; color: #130325; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: 600; transition: all 0.3s;">Try Again</a>
                <a href="../products.php" class="btn" style="background: #6c757d; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: 600; transition: all 0.3s;">Continue Shopping</a>
                <a href="../user-dashboard.php" class="btn" style="background: #007bff; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: 600; transition: all 0.3s;">View Orders</a>
            </div>
        </div>
    </div>
</main>

<?php require_once '../includes/footer.php'; ?>