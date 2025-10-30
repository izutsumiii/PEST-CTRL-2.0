<?php
// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'PHPMailer/PHPMailer/src/Exception.php';
require 'PHPMailer/PHPMailer/src/PHPMailer.php';
require 'PHPMailer/PHPMailer/src/SMTP.php';

require_once 'config/database.php';
require_once 'includes/functions.php';

requireSeller();

$userId = $_SESSION['user_id'];

// Handle status update - MUST be before any HTML output
if (isset($_POST['update_status'])) {
    $orderId = intval($_POST['order_id']);
    $newStatus = sanitizeInput($_POST['status']);
    $cancellationReason = isset($_POST['cancellation_reason']) ? sanitizeInput($_POST['cancellation_reason']) : '';
    
    $stmt = $pdo->prepare("SELECT o.* FROM orders o JOIN order_items oi ON o.id = oi.order_id JOIN products p ON oi.product_id = p.id WHERE o.id = ? AND p.seller_id = ? GROUP BY o.id");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
$result = $stmt->execute([$newStatus, $orderId]);

if ($result) {
    $_SESSION['order_message'] = ['type' => 'success', 'text' => 'Order #' . str_pad($orderId, 6, '0', STR_PAD_LEFT) . ' updated to ' . ucfirst($newStatus)];
    
    // Create notification for customer
    try {
        // Get the customer user_id from the order
        $stmt = $pdo->prepare("SELECT user_id FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $orderData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($orderData && $orderData['user_id']) {
            $customerId = $orderData['user_id'];
            
            // Create notification message based on status
            $statusMessages = [
                'pending' => 'Your order has been received and is pending processing.',
                'processing' => 'Your order is now being processed.',
                'shipped' => 'Great news! Your order has been shipped.',
                'delivered' => 'Your order has been delivered. Thank you for your purchase!',
                'cancelled' => 'Your order has been cancelled.',
                'refunded' => 'Your order has been refunded.'
            ];
            
            $message = $statusMessages[$newStatus] ?? "Your order status has been updated to: $newStatus";
            
            // Insert notification into notifications table
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, order_id, message, type, created_at) 
                                  VALUES (?, ?, ?, 'info', NOW())");
            $stmt->execute([$customerId, $orderId, $message]);
        }
    } catch (Exception $e) {
        // Log error but don't stop execution
        error_log("Failed to create customer notification: " . $e->getMessage());
    }

if ($result && in_array($newStatus, ['processing', 'shipped', 'delivered'])) {
    require_once 'includes/seller_notification_functions.php';
    
    $statusMessages = [
        'processing' => '✅ Order #' . str_pad($orderId, 6, '0', STR_PAD_LEFT) . ' moved to Processing',
        'shipped' => '🚚 Order #' . str_pad($orderId, 6, '0', STR_PAD_LEFT) . ' has been shipped',
        'delivered' => '📦 Order #' . str_pad($orderId, 6, '0', STR_PAD_LEFT) . ' was delivered'
    ];
    
    createSellerNotification(
        $userId,
        'Order Status Updated',
        $statusMessages[$newStatus] ?? 'Order status changed',
        'info',
        'view-orders.php'
    );
}
    }
    header("Location: view-orders.php");
    exit();
}

function getGracePeriodMinutes($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'order_grace_period'");
        $stmt->execute();
        $gracePeriod = $stmt->fetchColumn();
        
        return $gracePeriod ? intval($gracePeriod) : 5;
    } catch (PDOException $e) {
        error_log("Error fetching grace period setting: " . $e->getMessage());
        return 5;
    }
}

function isWithinGracePeriod($orderCreatedAt, $pdo) {
    $orderTime = strtotime($orderCreatedAt);
    $currentTime = time();
    $timeDifference = $currentTime - $orderTime;
    $gracePeriodMinutes = getGracePeriodMinutes($pdo);
    $gracePeriodSeconds = $gracePeriodMinutes * 60;
    
    return $timeDifference < $gracePeriodSeconds;
}

function getRemainingGracePeriod($orderCreatedAt, $pdo) {
    $orderTime = strtotime($orderCreatedAt);
    $currentTime = time();
    $timeDifference = $currentTime - $orderTime;
    $gracePeriodMinutes = getGracePeriodMinutes($pdo);
    $gracePeriodSeconds = $gracePeriodMinutes * 60;
    $remaining = $gracePeriodSeconds - $timeDifference;
    
    if ($remaining <= 0) return 0;
    
    $minutes = floor($remaining / 60);
    $seconds = $remaining % 60;
    
    return [
        'minutes' => $minutes, 
        'seconds' => $seconds, 
        'total_seconds' => $remaining,
        'grace_period_minutes' => $gracePeriodMinutes
    ];
}

function getAllowedStatusTransitions($currentStatus) {
    $transitions = [
        'pending' => ['processing', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped' => ['delivered'],
        'delivered' => [],
        'cancelled' => []
    ];
    
    return $transitions[$currentStatus] ?? [];
}

function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function sendOrderStatusUpdateEmail($customerEmail, $customerName, $orderId, $newStatus, $oldStatus, $orderItems, $totalAmount, $pdo, $cancellationReason = '') {
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'jhongujol1299@gmail.com';
        $mail->Password = 'ljdo ohkv pehx idkv';
        $mail->SMTPSecure = "ssl";
        $mail->Port = 465;
        
        $mail->setFrom('jhongujol1299@gmail.com', 'E-Commerce Store');
        $mail->addAddress($customerEmail, $customerName);
        
        $statusConfig = [
            'pending' => ['emoji' => '⏳', 'title' => 'Order Received', 'color' => '#ffc107', 'message' => 'Your order has been received and is awaiting processing.', 'next_step' => 'We\'ll start preparing your order soon.'],
            'processing' => ['emoji' => '🔄', 'title' => 'Order Confirmed & Processing', 'color' => '#007bff', 'message' => 'Great news! Your order has been confirmed and is now being prepared.', 'next_step' => 'Your items are being carefully prepared for shipment.'],
            'shipped' => ['emoji' => '🚚', 'title' => 'Order Shipped', 'color' => '#17a2b8', 'message' => 'Your order is on its way!', 'next_step' => 'You\'ll receive a tracking number shortly. Expected delivery: 3-5 business days.'],
            'delivered' => ['emoji' => '✅', 'title' => 'Order Delivered', 'color' => '#28a745', 'message' => 'Your order has been successfully delivered!', 'next_step' => 'We hope you enjoy your purchase. Please consider leaving a review.'],
            'cancelled' => ['emoji' => '❌', 'title' => 'Order Cancelled', 'color' => '#dc3545', 'message' => 'Your order has been cancelled by the seller.', 'next_step' => 'If you have any questions, please contact our support team.']
        ];

        $config = $statusConfig[$newStatus] ?? ['emoji' => '📋', 'title' => 'Order Status Updated', 'color' => '#6c757d', 'message' => 'Your order status has been updated.', 'next_step' => 'We\'ll keep you informed of any further updates.'];

        $itemsList = '';
        foreach ($orderItems as $item) {
            $itemTotal = $item['quantity'] * $item['item_price'];
            $itemsList .= "<tr><td style='padding: 10px; border-bottom: 1px solid #eee;'>" . htmlspecialchars($item['product_name']) . "</td><td style='padding: 10px; border-bottom: 1px solid #eee; text-align: center;'>" . (int)$item['quantity'] . "</td><td style='padding: 10px; border-bottom: 1px solid #eee; text-align: right;'>₱" . number_format((float)$item['item_price'], 2) . "</td><td style='padding: 10px; border-bottom: 1px solid #eee; text-align: right;'>₱" . number_format($itemTotal, 2) . "</td></tr>";
        }
        
        $mail->isHTML(true);
        $mail->Subject = $config['emoji'] . ' Order Update - Order #' . str_pad($orderId, 6, '0', STR_PAD_LEFT);
        $mail->Body = "<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'><div style='text-align: center; border-bottom: 2px solid " . $config['color'] . "; padding-bottom: 20px;'><h1 style='color: " . $config['color'] . "; margin: 0;'>" . $config['title'] . "</h1></div><div style='padding: 30px 0;'><h2 style='color: #333; margin-bottom: 20px;'>Hello " . htmlspecialchars($customerName) . ",</h2><p style='color: #666; font-size: 16px; line-height: 1.6;'>" . $config['message'] . "</p><div style='background: linear-gradient(135deg, " . $config['color'] . ", " . $config['color'] . "dd); color: white; padding: 20px; border-radius: 10px; margin: 20px 0; text-align: center;'><h3 style='margin: 0; font-size: 18px;'>Order #" . str_pad($orderId, 6, '0', STR_PAD_LEFT) . "</h3><p style='margin: 10px 0 0 0; opacity: 0.9;'>Status: " . ucfirst($newStatus) . "</p></div></div></div>";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Order status update email could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}


$stmt = $pdo->prepare("SELECT o.*, oi.quantity, oi.price as item_price, p.name as product_name, p.id as product_id, COALESCE(u.username, 'Guest Customer') as customer_name FROM orders o JOIN order_items oi ON o.id = oi.order_id JOIN products p ON oi.product_id = p.id LEFT JOIN users u ON o.user_id = u.id WHERE p.seller_id = ? ORDER BY o.created_at DESC");
$stmt->execute([$userId]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$groupedOrders = [];
foreach ($orders as $order) {
    $orderId = $order['id'];
    if (!isset($groupedOrders[$orderId])) {
        $groupedOrders[$orderId] = [
            'order_id' => $orderId,
            'customer_name' => $order['customer_name'],
            'total_amount' => $order['total_amount'],
            'status' => $order['status'],
            'created_at' => $order['created_at'],
            'items' => []
        ];
    }
    
    $groupedOrders[$orderId]['items'][] = [
        'product_id' => $order['product_id'],
        'product_name' => $order['product_name'],
        'quantity' => $order['quantity'],
        'item_price' => $order['item_price']
    ];
}

// Include header after form processing is complete
require_once 'includes/seller_header.php';
?>

<style>
html, body { background:#130325 !important; margin:0; padding:0; }
main { background:transparent !important; margin-left: 120px !important; padding: 20px 30px 60px 30px !important; min-height: calc(100vh - 60px) !important; transition: margin-left 0.3s ease; margin-top: -20px !important; }
main.sidebar-collapsed { margin-left: 0px !important; }
h1 { color:#F9F9F9 !important; font-family:var(--font-primary) !important; font-size:24px !important; font-weight:700 !important; text-align:left !important; margin:0 0 15px 0 !important; padding-left:20px !important; background:none !important; text-shadow:none !important; }

.notification-toast {
    position: fixed;
    top: 100px;
    right: 20px;
    max-width: 400px;
    background: #1a0a2e;
    border: 1px solid rgba(255,215,54,0.5);
    border-left: 4px solid #FFD736;
    border-radius: 10px;
    padding: 16px 20px;
    color: #F9F9F9;
    box-shadow: 0 10px 30px rgba(0,0,0,0.4);
    z-index: 10000;
    animation: slideInRight 0.3s ease;
}

.notification-toast.success { border-left-color: #28a745; }
.notification-toast.error { border-left-color: #dc3545; }

@keyframes slideInRight {
    from { transform: translateX(400px); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

.orders-container {
    max-width: 1600px;
    margin: 0 auto;
    margin-top: -20px !important;
}

.orders-container h1 {
    color: #F9F9F9 !important;
    font-family: var(--font-primary) !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    text-align: left !important;
    margin: 0 0 15px 0 !important;
    padding-left: 20px !important;
    background: none !important;
    text-shadow: none !important;
}

.orders-container > p {
    color: #ffffff;
    text-align: center;
    opacity: 0.95;
    margin: 0 0 30px 0;
}

.table-wrapper {
    background: #1a0a2e;
    border: 1px solid #2d1b4e;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}

.orders-table {
    width: 100%;
    border-collapse: collapse;
}

.orders-table thead {
    background: rgba(255,215,54,0.1);
    border-bottom: 2px solid #FFD736;
}

.orders-table th {
    padding: 16px 12px;
    text-align: left;
    color: #FFD736;
    font-weight: 700;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.orders-table td {
    padding: 16px 12px;
    color: #F9F9F9;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    vertical-align: top;
}

.orders-table tbody tr {
    transition: all 0.2s ease;
}

.orders-table tbody tr:hover {
    background: rgba(255,215,54,0.05);
}

.order-id {
    color: #FFD736;
    font-weight: 700;
    font-size: 16px;
}

.customer-name {
    color: #F9F9F9;
    font-weight: 600;
}

.product-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.product-item {
    padding: 4px 0;
    color: #F9F9F9;
    opacity: 0.9;
}

.product-item strong {
    color: #FFD736;
}

.total-amount {
    color: #FFD736;
    font-weight: 700;
    font-size: 18px;
}

.order-status {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 12px;
    text-transform: uppercase;
}

.status-pending { background: rgba(255,193,7,0.2); color: #ffc107; border: 1px solid #ffc107; }
.status-processing { background: rgba(0,123,255,0.2); color: #007bff; border: 1px solid #007bff; }
.status-shipped { background: rgba(23,162,184,0.2); color: #17a2b8; border: 1px solid #17a2b8; }
.status-delivered { background: rgba(40,167,69,0.2); color: #28a745; border: 1px solid #28a745; }
.status-cancelled { background: rgba(220,53,69,0.2); color: #dc3545; border: 1px solid #dc3545; }

.order-date {
    color: #F9F9F9;
    opacity: 0.8;
    font-size: 14px;
}

.actions-cell {
    min-width: 200px;
    text-align: center;
}

.grace-period-timer {
    background: rgba(255,193,7,0.15);
    border: 1px solid #ffc107;
    padding: 10px 12px;
    border-radius: 8px;
    text-align: center;
    color: #ffc107;
    font-weight: 600;
    font-size: 12px;
    margin-bottom: 10px;
}

.grace-period-ready {
    background: rgba(0,123,255,0.15);
    border: 1px solid #007bff;
    color: #007bff;
    padding: 6px 14px;
    border-radius: 20px;
    font-weight: 700;
    text-transform: uppercase;
    display: inline-block;
}

.action-buttons {
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.action-btn {
    width: 36px;
    height: 36px;
    padding: 0;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
    text-align: center;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.btn-process {
    background: #007bff;
    color: white;
}

.btn-process:hover {
    background: #0056b3;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,123,255,0.4);
}

.btn-cancel {
    background: #dc3545;
    color: white;
}

.btn-cancel:hover {
    background: #c82333;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(220,53,69,0.4);
}

/* Custom Confirmation Modal */
.custom-confirm-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    opacity: 0;
    visibility: hidden;
    transition: all 0.25s ease;
}

.custom-confirm-overlay.show { opacity: 1; visibility: visible; }

.custom-confirm-dialog {
    background: linear-gradient(135deg, #1a0a2e 0%, #130325 100%);
    border: 2px solid #FFD736;
    border-radius: 12px;
    padding: 22px;
    width: 92%;
    max-width: 420px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.5);
}

.custom-confirm-title { color: #FFD736; font-weight: 800; font-size: 18px; margin: 0 0 10px 0; }
.custom-confirm-message { color: #F9F9F9; opacity: 0.9; font-size: 14px; margin-bottom: 16px; }
.custom-confirm-buttons { display: flex; gap: 10px; justify-content: flex-end; }
.custom-confirm-btn { padding: 10px 14px; border-radius: 8px; font-weight: 800; border: 2px solid transparent; cursor: pointer; }
.custom-confirm-btn.cancel { background: rgba(108,117,125,0.15); color: #adb5bd; border-color: #6c757d; }
.custom-confirm-btn.confirm { background: linear-gradient(135deg, #dc3545, #c82333); color: #fff; border-color: #dc3545; }
.custom-confirm-btn.primary { background: linear-gradient(135deg, #FFD736, #FFD736); color: #130325; border-color: #FFD736; }

.status-form {
    margin-top: 8px;
}

.status-form label {
    display: block;
    color: #FFD736;
    font-weight: 600;
    font-size: 12px;
    margin-bottom: 6px;
}

.status-select {
    width: 100%;
    padding: 8px;
    background: #ffffff;
    color: #130325;
    border: 1px solid #FFD736;
    border-radius: 6px;
    font-weight: 600;
    font-size: 12px;
    cursor: pointer;
}

.status-select:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(255,215,54,0.3);
}

.no-orders {
    background: #1a0a2e;
    border: 1px solid #2d1b4e;
    color: #F9F9F9;
    border-radius: 12px;
    padding: 60px 20px;
    text-align: center;
}

.no-orders i {
    font-size: 64px;
    color: #FFD736;
    margin-bottom: 20px;
    display: block;
}

@media (max-width: 1200px) {
    .orders-table { font-size: 12px; }
    .orders-table th, .orders-table td { padding: 12px 8px; }
}

@media (max-width: 768px) {
    main { padding: 70px 10px 60px 10px !important; }
    .table-wrapper { overflow-x: auto; }
    .orders-table { min-width: 1000px; }
}
</style>

<main>
<div class="orders-container">
    <?php if (isset($_SESSION['order_message'])): ?>
        <div class="notification-toast <?php echo $_SESSION['order_message']['type']; ?>">
            <?php echo htmlspecialchars($_SESSION['order_message']['text']); ?>
        </div>
        <?php unset($_SESSION['order_message']); ?>
    <?php endif; ?>

    <h1>Order Management</h1>

<?php if (empty($groupedOrders)): ?>
        <div class="no-orders">
            <i class="fas fa-inbox"></i>
            <p style="font-size: 18px; margin: 0;">No orders found.</p>
        </div>
<?php else: ?>
        <div class="table-wrapper">
<table class="orders-table">
    <thead>
        <tr>
            <th>Order ID</th>
            <th>Customer</th>
            <th>Products</th>
            <th>Total</th>
            <th>Status</th>
            <th>Date</th>
                        <th>Actions</th>
        </tr>
    </thead>
    <tbody>
                    <?php foreach ($groupedOrders as $order): 
            $withinGracePeriod = isWithinGracePeriod($order['created_at'], $pdo);
            $remainingTime = $withinGracePeriod ? getRemainingGracePeriod($order['created_at'], $pdo) : null;
                        $statusClass = 'status-' . $order['status'];
            ?>
            <tr>
                            <td>
                                <span class="order-id">#<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></span>
                            </td>
                            <td>
                                <span class="customer-name"><?php echo htmlspecialchars($order['customer_name']); ?></span>
                            </td>
                            <td>
                                <ul class="product-list">
                        <?php foreach ($order['items'] as $item): ?>
                                        <li class="product-item">
                                <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                            (x<?php echo (int)$item['quantity']; ?>) - ₱<?php echo number_format((float)$item['item_price'], 2); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </td>
                            <td>
                                <span class="total-amount">₱<?php echo number_format((float)$order['total_amount'], 2); ?></span>
                            </td>
                            <td>
                                <span class="order-status <?php echo $statusClass; ?>">
                        <?php echo ucfirst($order['status']); ?>
                    </span>
                </td>
                            <td>
                                <div class="order-date">
                                    <?php echo date('M j, Y', strtotime($order['created_at'])); ?><br>
                                    <?php echo date('g:i A', strtotime($order['created_at'])); ?>
                                </div>
                </td>
                            <td class="actions-cell">
    <?php if ($order['status'] === 'pending'): ?>
        <?php if ($withinGracePeriod): ?>
                                        <div class="grace-period-timer" id="timer-<?php echo $order['order_id']; ?>">
                                            🔒 <?php echo $remainingTime['minutes']; ?>m <?php echo str_pad($remainingTime['seconds'], 2, '0', STR_PAD_LEFT); ?>s
            </div>
            <script>
                                            (function() {
                                                let remaining = <?php echo $remainingTime['total_seconds']; ?>;
                                                const timer = document.getElementById('timer-<?php echo $order['order_id']; ?>');
                                                const interval = setInterval(function() {
                                                    if (remaining <= 0) {
                                                        clearInterval(interval);
                                                        location.reload();
                                                        return;
                                                    }
                                                    const m = Math.floor(remaining / 60);
                                                    const s = remaining % 60;
                                                    timer.innerHTML = `🔒 ${m}m ${s.toString().padStart(2, '0')}s`;
                                                    remaining--;
                                                }, 1000);
                                            })();
            </script>
        <?php else: ?>
                                        <div class="grace-period-timer grace-period-ready">
                                            Ready
            </div>
                                        <div class="action-buttons">
                                            <form method="POST" onsubmit="return confirmStatusChange('processing');">
                                                <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                                <input type="hidden" name="status" value="processing">
                                                <input type="hidden" name="update_status" value="1">
                                                <button type="submit" class="action-btn btn-process" title="Process">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            </form>
                                            <form method="POST" onsubmit="return confirmStatusChange('cancelled');">
                <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                                <input type="hidden" name="status" value="cancelled">
                <input type="hidden" name="update_status" value="1">
                                                <button type="submit" class="action-btn btn-cancel" title="Cancel">
                                                    <i class="fas fa-times"></i>
                                                </button>
            </form>
                                        </div>
        <?php endif; ?>
                                <?php elseif (in_array($order['status'], ['processing', 'shipped'])): ?>
                                    <form method="POST" class="status-form" onsubmit="return confirmStatusChange(this.querySelector('select').value);">
            <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                        <input type="hidden" name="update_status" value="1">
                                        <label>Update Status:</label>
                                        <select name="status" class="status-select" onchange="this.form.submit()">
                                            <option value="">Select...</option>
                                            <?php if ($order['status'] === 'processing'): ?>
                <option value="shipped">Ship</option>
                <option value="cancelled">Cancel</option>
    <?php elseif ($order['status'] === 'shipped'): ?>
                <option value="delivered">Delivered</option>
                                            <?php endif; ?>
            </select>
        </form>
                                <?php else: ?>
                                    <span style="color: #999; font-style: italic; font-size: 12px;">No actions</span>
    <?php endif; ?>
</td>
                        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
        </div>
<?php endif; ?>
</div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const toast = document.querySelector('.notification-toast');
    if (toast) {
        setTimeout(function() {
            toast.style.transition = 'opacity 0.5s ease';
            toast.style.opacity = '0';
            setTimeout(function() { toast.remove(); }, 500);
        }, 4000);
    }

    const main = document.querySelector('main');
    const sidebar = document.getElementById('sellerSidebar');
    
    function updateMainMargin() {
        if (sidebar && sidebar.classList.contains('collapsed')) {
            main.classList.add('sidebar-collapsed');
        } else {
            main.classList.remove('sidebar-collapsed');
        }
    }
    
    updateMainMargin();
    
    const observer = new MutationObserver(updateMainMargin);
    if (sidebar) {
        observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
    }

    // Remove inline onsubmit from status update forms to avoid double prompts
    document.querySelectorAll('form[onsubmit]').forEach(function(form){
        const hasStatus = form.querySelector('input[name="status"], select[name="status"]');
        const hasFlag = form.querySelector('input[name="update_status"]');
        if (hasStatus && hasFlag) {
            form.removeAttribute('onsubmit');
        }
    });

    // Intercept forms that update order status so we can show the custom confirm modal first
    document.addEventListener('submit', function(e) {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        const statusField = form.querySelector('input[name="status"], select[name="status"]');
        const updateFlag = form.querySelector('input[name="update_status"]');
        if (!statusField || !updateFlag) return; // only intercept order status updates

        const nextStatus = statusField.value;
        if (!nextStatus) return; // allow native validation/no-op

        e.preventDefault();
        Promise.resolve(confirmStatusChange(nextStatus)).then(function(ok){
            if (ok) form.submit();
        });
    }, true);

    // Intercept status dropdown direct submit behavior; replace with modal confirmation first
    document.querySelectorAll('select[name="status"]').forEach(function(sel){
        // Remove inline onchange submit if present
        try { sel.onchange = null; } catch (err) {}
        sel.addEventListener('change', function(ev){
            ev.preventDefault();
            const form = sel.form;
            if (!form) return;
            const val = sel.value;
            if (!val) return;
            Promise.resolve(confirmStatusChange(val)).then(function(ok){
                if (ok) form.submit();
            });
        });
    });
});
</script>
<script>
function confirmStatusChange(nextStatus) {
    if (!nextStatus) return false;
    const overlay = document.createElement('div');
    overlay.className = 'custom-confirm-overlay';
    overlay.innerHTML = `
      <div class="custom-confirm-dialog">
        <div class="custom-confirm-title">Confirm Action</div>
        <div class="custom-confirm-message">${
            nextStatus==='processing' ? 'Move order to Processing?' :
            nextStatus==='shipped' ? 'Mark order as Shipped?' :
            nextStatus==='delivered' ? 'Mark order as Delivered?' :
            nextStatus==='cancelled' ? 'Cancel this order?' : 'Apply this status change?'
        }</div>
        <div class="custom-confirm-buttons">
          <button type="button" class="custom-confirm-btn cancel">Cancel</button>
          <button type="button" class="custom-confirm-btn primary">Confirm</button>
        </div>
      </div>`;
    document.body.appendChild(overlay);
    requestAnimationFrame(()=>overlay.classList.add('show'));
    return new Promise((resolve)=>{
      const close = ()=>{ overlay.classList.remove('show'); setTimeout(()=>overlay.remove(), 200); };
      overlay.querySelector('.cancel').addEventListener('click', ()=>{ close(); resolve(false); });
      overlay.addEventListener('click', (e)=>{ if(e.target===overlay){ close(); resolve(false);} });
      overlay.querySelector('.primary').addEventListener('click', ()=>{ close(); resolve(true); });
    }).then(ok=> ok);
}
</script>
