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
        'seller-order-details.php?order_id=' . $orderId
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
html, body { 
    background: #f0f2f5 !important; 
    margin: 0; 
    padding: 0; 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}
main { 
    background: #f0f2f5 !important; 
    margin-left: 120px !important; 
    margin-top: -20px !important;
    margin-bottom: 0 !important;
    padding-top: 5px !important;
    padding-bottom: 40px !important;
    padding-left: 30px !important;
    padding-right: 30px !important;
    min-height: calc(100vh - 60px) !important; 
    transition: margin-left 0.3s ease !important;
}
main.sidebar-collapsed { margin-left: 0px !important; }

h1 { 
    color: #130325 !important; 
    font-size: 20px !important; 
    font-weight: 700 !important; 
    margin: 0 !important;
    margin-bottom: 16px !important;
    padding: 0 !important; 
    text-shadow: none !important;
}

.notification-toast {
    position: fixed;
    top: 100px;
    right: 20px;
    max-width: 450px;
    min-width: 350px;
    background: #ffffff;
    border: 1px solid rgba(0, 0, 0, 0.1);
    border-radius: 16px;
    padding: 20px 24px;
    color: #130325;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
    z-index: 10000;
    animation: slideInRight 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    display: flex;
    align-items: center;
    gap: 16px;
    font-family: var(--font-primary, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif);
}

.notification-toast::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    border-radius: 16px 16px 0 0;
    background: linear-gradient(90deg, #FFD736, #FFA500);
}

.notification-toast.success {
    background: #ffffff;
    border-color: rgba(40, 167, 69, 0.3);
    border-top: 4px solid #28a745;
}

.notification-toast.success::before {
    background: linear-gradient(90deg, #28a745, #20c997);
}

.notification-toast.error {
    background: #ffffff;
    border-color: rgba(220, 53, 69, 0.3);
    border-top: 4px solid #dc3545;
}

.notification-toast.error::before {
    background: linear-gradient(90deg, #dc3545, #fd7e14);
}

@keyframes slideInRight {
    0% { 
        transform: translateX(100%) scale(0.8); 
        opacity: 0; 
    }
    50% {
        transform: translateX(-10px) scale(1.02);
        opacity: 0.8;
    }
    100% { 
        transform: translateX(0) scale(1); 
        opacity: 1; 
    }
}

@keyframes slideOutRight {
    0% { 
        transform: translateX(0) scale(1); 
        opacity: 1; 
    }
    100% { 
        transform: translateX(100%) scale(0.8); 
        opacity: 0; 
    }
}

.notification-toast.slide-out {
    animation: slideOutRight 0.3s ease forwards;
}

.notification-toast .toast-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    background: rgba(255, 215, 54, 0.15);
}

.notification-toast.success .toast-icon {
    background: rgba(40, 167, 69, 0.15);
    color: #28a745;
}

.notification-toast.error .toast-icon {
    background: rgba(220, 53, 69, 0.15);
    color: #dc3545;
}

.notification-toast .toast-content {
    flex: 1;
    min-width: 0;
}

.notification-toast .toast-title {
    font-size: 16px;
    font-weight: 700;
    margin: 0 0 4px 0;
    color: #130325;
    line-height: 1.3;
}

.notification-toast .toast-message {
    font-size: 14px;
    margin: 0;
    color: #130325;
    opacity: 0.8;
    line-height: 1.4;
}

.notification-toast .toast-close {
    position: absolute;
    top: 12px;
    right: 12px;
    background: none;
    border: none;
    color: #130325;
    opacity: 0.6;
    font-size: 18px;
    cursor: pointer;
    padding: 4px;
    border-radius: 6px;
    transition: all 0.2s ease;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.notification-toast .toast-close:hover {
    background: rgba(255, 215, 54, 0.15);
    color: #130325;
    opacity: 1;
    transform: scale(1.1);
}

.orders-container {
    max-width: 1600px;
    margin: 0 auto;
}

.search-card {
    background: #ffffff;
    border: 1px solid rgba(0,0,0,0.1);
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 30px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.search-wrapper {
    display: flex;
    gap: 12px;
    align-items: center;
}

.search-input-group {
    display: flex;
    gap: 8px;
    flex: 1;
}

.search-bar {
    flex: 1;
    padding: 12px 14px;
    background: #ffffff;
    border: 1px solid #ddd;
    border-radius: 4px;
    color: #130325;
    font-size: 14px;
    transition: all 0.2s ease;
}

.search-bar:focus {
    outline: none;
    border-color: #FFD736;
    box-shadow: 0 0 0 2px rgba(255,215,54,0.2);
}

.search-bar::placeholder {
    color: #999;
}

.search-btn {
    padding: 12px 16px;
    background: #FFD736;
    color: #130325;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.search-btn:hover {
    background: #f5d026;
}

.orders-table-container {
    background: #ffffff;
    border: 1px solid rgba(0,0,0,0.1);
    border-radius: 8px;
    padding: 24px;
    margin-top: 0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.table-wrapper {
    overflow-x: auto;
}

.orders-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.orders-table thead {
    background: #130325;
    border-bottom: 2px solid #FFD736;
}

.orders-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 700;
    color: #ffffff;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    position: relative;
    user-select: none;
    cursor: pointer;
    transition: background 0.2s ease;
    padding-right: 32px;
}

.orders-table th.sortable {
    cursor: pointer;
}

.orders-table th.sortable:hover {
    background: rgba(255, 215, 54, 0.2);
}

.sort-indicator {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 10px;
    color: #FFD736;
    transition: all 0.2s ease;
}

.sort-indicator::before {
    content: '↕';
    display: block;
}

.sort-indicator.asc::before {
    content: '↑';
    color: #FFD736;
}

.sort-indicator.desc::before {
    content: '↓';
    color: #FFD736;
}

.orders-table td {
    padding: 14px 16px;
    color: #130325;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: top;
}

.orders-table tbody tr {
    border-bottom: 1px solid #f0f0f0;
    transition: background 0.2s ease;
}

.orders-table tbody tr:hover {
    background: rgba(255, 215, 54, 0.05);
}

.order-id {
    color: #130325;
    font-weight: 700;
    font-size: 15px;
}

.customer-name {
    color: #130325;
    font-weight: 600;
}

.product-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.product-item {
    padding: 4px 0;
    color: #130325;
    opacity: 0.9;
    font-size: 13px;
}

.product-item strong {
    color: #130325;
    font-weight: 600;
}

.total-amount {
    color: #130325;
    font-weight: 700;
    font-size: 16px;
}

.order-status {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 4px;
    font-weight: 600;
    font-size: 12px;
    text-transform: uppercase;
}

.status-pending { background: rgba(255,193,7,0.15); color: #ffc107; }
.status-processing { background: rgba(0,123,255,0.15); color: #007bff; }
.status-shipped { background: rgba(23,162,184,0.15); color: #17a2b8; }
.status-delivered { background: rgba(40,167,69,0.15); color: #28a745; }
.status-cancelled { background: rgba(220,53,69,0.15); color: #dc3545; }

.order-date {
    color: #130325;
    opacity: 0.8;
    font-size: 13px;
}

.actions-cell {
    min-width: 150px;
    text-align: center;
}

.grace-period-timer {
    background: rgba(255,193,7,0.15);
    border: 1px solid #ffc107;
    padding: 8px 12px;
    border-radius: 4px;
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
    padding: 4px 10px;
    border-radius: 4px;
    font-weight: 600;
    font-size: 11px;
    text-transform: uppercase;
    display: inline-block;
    margin-bottom: 10px;
}

.action-buttons {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.action-btn {
    width: auto;
    min-width: 120px;
    padding: 8px 14px;
    border: none;
    border-radius: 4px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s ease;
    text-align: center;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    white-space: nowrap;
}

.btn-process {
    background: #007bff;
    color: white;
}

.btn-process:hover {
    background: #0056b3;
    transform: scale(1.1);
}

.btn-ship {
    background: #17a2b8;
    color: white;
}

.btn-ship:hover {
    background: #138496;
    transform: scale(1.1);
}

.btn-delivered {
    background: #28a745;
    color: white;
}

.btn-delivered:hover {
    background: #218838;
    transform: scale(1.1);
}

.btn-cancel {
    background: #dc3545;
    color: white;
}

.btn-cancel:hover {
    background: #c82333;
    transform: scale(1.1);
}

/* Custom Confirmation Modal */
.custom-confirm-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.6);
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
    background: #ffffff;
    border-radius: 10px;
    padding: 18px 20px;
    width: 92%;
    max-width: 420px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.18);
}

.custom-confirm-title { color: #111827; font-weight: 600; font-size: 1.1rem; margin: 0 0 10px 0; text-transform: none; letter-spacing: normal; }
.custom-confirm-message { color: #374151; font-size: 0.92rem; margin-bottom: 20px; line-height: 1.5; }
.custom-confirm-buttons { display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px; }
.custom-confirm-btn { padding: 6px 12px; border-radius: 6px; font-size: 0.85rem; font-weight: 600; text-transform: none; letter-spacing: normal; border: none; cursor: pointer; }
.custom-confirm-btn.cancel { background: #6c757d; color: white; }
.custom-confirm-btn.confirm { background: #dc3545; color: white; }
.custom-confirm-btn.primary { background: #FFD736; color: #130325; }
.custom-confirm-btn.primary:hover { background: #f5d026; }
.custom-confirm-btn.cancel:hover { background: #5a6268; }
.custom-confirm-btn.confirm:hover { background: #c82333; }


.no-orders-message {
    text-align: center;
    padding: 40px;
    color: #6b7280;
    font-size: 14px;
}

@media (max-width: 768px) {
    main { padding: 30px 24px 60px 24px !important; }
    .orders-table-container { padding: 20px; }
    .orders-table { font-size: 12px; }
    .orders-table th, .orders-table td { padding: 10px 12px; }
    .table-wrapper { overflow-x: auto; }
}
</style>

<main>
<div class="orders-container">
    <?php if (isset($_SESSION['order_message'])): ?>
        <div class="notification-toast <?php echo $_SESSION['order_message']['type']; ?>" id="notificationToast">
            <div class="toast-icon">
                <?php if ($_SESSION['order_message']['type'] === 'success'): ?>
                    <i class="fas fa-check-circle"></i>
                <?php else: ?>
                    <i class="fas fa-exclamation-circle"></i>
                <?php endif; ?>
            </div>
            <div class="toast-content">
                <div class="toast-title">
                    <?php echo $_SESSION['order_message']['type'] === 'success' ? 'Success!' : 'Error!'; ?>
                </div>
                <div class="toast-message">
                    <?php echo htmlspecialchars($_SESSION['order_message']['text']); ?>
                </div>
            </div>
            <button class="toast-close" onclick="closeNotification()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['order_message']); ?>
    <?php endif; ?>

    <h1>Order Management</h1>

    <div class="search-card">
        <div class="search-wrapper">
            <div class="search-input-group">
                <input type="text" class="search-bar" id="orderSearch" placeholder="Search by Order ID, Customer, Status, or Product..." onkeyup="filterOrders()">
                <button class="search-btn" onclick="filterOrders()" title="Search">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </div>
    </div>

        <div class="orders-table-container">
            <div class="table-wrapper">
                <table class="orders-table" id="orders-table">
                    <thead>
                        <tr>
                            <th class="sortable" data-column="id">Order ID<span class="sort-indicator"></span></th>
                            <th class="sortable" data-column="customer">Customer<span class="sort-indicator"></span></th>
                            <th>Products</th>
                            <th class="sortable" data-column="total">Total<span class="sort-indicator"></span></th>
                            <th class="sortable" data-column="status">Status<span class="sort-indicator"></span></th>
                            <th class="sortable" data-column="date">Date<span class="sort-indicator"></span></th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($groupedOrders)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; color:#6b7280; padding:20px;">No orders found.</td>
                        </tr>
                        <?php else: foreach ($groupedOrders as $order):
            $withinGracePeriod = isWithinGracePeriod($order['created_at'], $pdo);
            $remainingTime = $withinGracePeriod ? getRemainingGracePeriod($order['created_at'], $pdo) : null;
            $statusClass = 'status-' . $order['status'];
            ?>
            <tr data-id="<?php echo $order['order_id']; ?>" 
                data-customer="<?php echo htmlspecialchars(strtolower($order['customer_name'])); ?>" 
                data-total="<?php echo (float)$order['total_amount']; ?>" 
                data-status="<?php echo strtolower($order['status']); ?>" 
                data-date="<?php echo strtotime($order['created_at']); ?>">
                            <td>
                                <a href="seller-order-details.php?order_id=<?php echo (int)$order['order_id']; ?>" class="order-id">#<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></a>
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
                                                <button type="submit" class="action-btn btn-process">
                                                    <i class="fas fa-check"></i>
                                                    <span>Process</span>
                                                </button>
                                            </form>
                                            <form method="POST" onsubmit="return confirmStatusChange('cancelled');">
                                                <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                                <input type="hidden" name="status" value="cancelled">
                                                <input type="hidden" name="update_status" value="1">
                                                <button type="submit" class="action-btn btn-cancel">
                                                    <i class="fas fa-times"></i>
                                                    <span>Cancel</span>
                                                </button>
                                            </form>
                                        </div>
        <?php endif; ?>
                                <?php elseif ($order['status'] === 'processing'): ?>
                                    <div class="action-buttons">
                                        <form method="POST" onsubmit="return confirmStatusChange('shipped');">
                                            <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                            <input type="hidden" name="status" value="shipped">
                                            <input type="hidden" name="update_status" value="1">
                                            <button type="submit" class="action-btn btn-ship">
                                                <i class="fas fa-truck"></i>
                                                <span>Ship</span>
                                            </button>
                                        </form>
                                        <form method="POST" onsubmit="return confirmStatusChange('cancelled');">
                                            <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                            <input type="hidden" name="status" value="cancelled">
                                            <input type="hidden" name="update_status" value="1">
                                            <button type="submit" class="action-btn btn-cancel">
                                                <i class="fas fa-times"></i>
                                                <span>Cancel</span>
                                            </button>
                                        </form>
                                    </div>
                                <?php elseif ($order['status'] === 'shipped'): ?>
                                    <div class="action-buttons">
                                        <form method="POST" onsubmit="return confirmStatusChange('delivered');">
                                            <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                            <input type="hidden" name="status" value="delivered">
                                            <input type="hidden" name="update_status" value="1">
                                            <button type="submit" class="action-btn btn-delivered">
                                                <i class="fas fa-check-circle"></i>
                                                <span>Delivered</span>
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #999; font-style: italic; font-size: 12px;">No actions</span>
    <?php endif; ?>
</td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
</div>
</main>

<script>
// Close notification function
function closeNotification() {
    const toast = document.getElementById('notificationToast');
    if (toast) {
        toast.classList.add('slide-out');
        setTimeout(function() {
            toast.remove();
        }, 300);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const toast = document.querySelector('.notification-toast');
    if (toast) {
        setTimeout(function() {
            closeNotification();
        }, 5000);
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

    // Search functionality
    window.filterOrders = function() {
        const searchTerm = document.getElementById('orderSearch').value.toLowerCase();
        const orderRows = document.querySelectorAll('.orders-table tbody tr');
        
        orderRows.forEach(row => {
            const orderId = row.querySelector('.order-id')?.textContent.toLowerCase() || '';
            const customerName = row.querySelector('.customer-name')?.textContent.toLowerCase() || '';
            const statusBadge = row.querySelector('.order-status')?.textContent.toLowerCase() || '';
            const productItems = row.querySelectorAll('.product-item strong');
            const productNames = Array.from(productItems).map(item => item.textContent.toLowerCase()).join(' ');
            
            const matchesSearch = orderId.includes(searchTerm) || 
                                 customerName.includes(searchTerm) || 
                                 statusBadge.includes(searchTerm) ||
                                 productNames.includes(searchTerm);
            
            if (matchesSearch) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    };
    
    // Table sorting functionality
    const table = document.getElementById('orders-table');
    if (table) {
        const tbody = table.querySelector('tbody');
        const sortableHeaders = document.querySelectorAll('.orders-table th.sortable');
        let currentSort = null;
        let currentOrder = 'desc';
        
        // Store original rows data
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const rowsData = rows.map(row => {
            return {
                element: row,
                id: parseInt(row.getAttribute('data-id')) || 0,
                customer: row.getAttribute('data-customer') || '',
                total: parseFloat(row.getAttribute('data-total')) || 0,
                status: row.getAttribute('data-status') || '',
                date: parseInt(row.getAttribute('data-date')) || 0
            };
        });
        
        function updateSortIndicators(activeColumn, order) {
            sortableHeaders.forEach(header => {
                const indicator = header.querySelector('.sort-indicator');
                const column = header.getAttribute('data-column');
                
                // Remove all sort classes
                indicator.classList.remove('asc', 'desc');
                
                // Add active sort class
                if (column === activeColumn) {
                    indicator.classList.add(order);
                }
            });
        }
        
        function sortTable(column, order) {
            const sortedData = [...rowsData].sort((a, b) => {
                let aVal, bVal;
                
                switch(column) {
                    case 'id':
                        aVal = a.id;
                        bVal = b.id;
                        break;
                    case 'customer':
                        aVal = a.customer;
                        bVal = b.customer;
                        break;
                    case 'total':
                        aVal = a.total;
                        bVal = b.total;
                        break;
                    case 'status':
                        aVal = a.status;
                        bVal = b.status;
                        break;
                    case 'date':
                        aVal = a.date;
                        bVal = b.date;
                        break;
                    default:
                        return 0;
                }
                
                if (aVal < bVal) return order === 'asc' ? -1 : 1;
                if (aVal > bVal) return order === 'asc' ? 1 : -1;
                return 0;
            });
            
            // Clear tbody
            tbody.innerHTML = '';
            
            // Append sorted rows
            sortedData.forEach(data => {
                tbody.appendChild(data.element);
            });
            
            // Update indicators
            updateSortIndicators(column, order);
            
            currentSort = column;
            currentOrder = order;
        }
        
        // Add click handlers
        sortableHeaders.forEach(header => {
            header.addEventListener('click', function() {
                const column = this.getAttribute('data-column');
                let newOrder = 'asc';
                
                // If clicking the same column, toggle order
                if (column === currentSort) {
                    newOrder = currentOrder === 'asc' ? 'desc' : 'asc';
                }
                
                // Sort table without reload
                sortTable(column, newOrder);
            });
        });
    }
    // expose data for modal
    window.sellerOrdersData = <?php echo json_encode($groupedOrders, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
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
<script>
function showSellerOrderDetails(orderId) {
    const order = (window.sellerOrdersData || {})[orderId];
    if (!order) return;
    let modal = document.getElementById('sellerOrderDetailsModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'sellerOrderDetailsModal';
        modal.style.cssText = 'position:fixed; inset:0; background:rgba(0, 0, 0, 0.6); display:flex; align-items:center; justify-content:center; z-index:10001;';
        modal.innerHTML = '<div id="sellerOrderDetailsDialog" style="background:#ffffff; border-radius:12px; width:92%; max-width:720px; box-shadow:0 20px 40px rgba(0,0,0,0.25); overflow:hidden;">\
            <div style="background:#130325; color:#fff; padding:14px 18px; display:flex; align-items:center; justify-content:space-between;">\
                <div style="font-weight:800; font-size:14px;">Order Details</div>\
                <button id="sellerOrderDetailsClose" style="background:transparent; border:none; color:#fff; font-size:18px; cursor:pointer;">&times;</button>\
            </div>\
            <div id="sellerOrderDetailsBody" style="padding:16px; color:#130325;"></div>\
        </div>';
        document.body.appendChild(modal);
        modal.addEventListener('click', (e)=>{ if (e.target.id === 'sellerOrderDetailsModal' || e.target.id === 'sellerOrderDetailsClose') modal.remove(); });
    }
    const body = modal.querySelector('#sellerOrderDetailsBody');
    const itemsHtml = (order.items || []).map(it => `\
        <tr>\
          <td style="padding:8px; border-bottom:1px solid #eef2f7;">${escapeHtml(it.product_name)}</td>\
          <td style="padding:8px; border-bottom:1px solid #eef2f7; text-align:center;">x${parseInt(it.quantity||0,10)}</td>\
          <td style="padding:8px; border-bottom:1px solid #eef2f7; text-align:right;">₱${Number(it.item_price||0).toFixed(2)}</td>\
          <td style="padding:8px; border-bottom:1px solid #eef2f7; text-align:right;">₱${(Number(it.item_price||0)*Number(it.quantity||0)).toFixed(2)}</td>\
        </tr>`).join('');
    const total = Number(order.total_amount||0).toFixed(2);
    body.innerHTML = `\
      <div style=\"display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;\">\
        <div style=\"font-weight:800;\">Order #${String(order.order_id).padStart(6,'0')}</div>\
        <span class=\"order-status status-${escapeHtml(String(order.status).toLowerCase())}\" style=\"margin-left:12px;\">${escapeHtml(String(order.status))}</span>\
      </div>\
      <div style=\"margin-bottom:12px; color:#6b7280; font-size:13px;\">Customer: ${escapeHtml(order.customer_name||'')}</div>\
      <table style=\"width:100%; border-collapse:collapse;\">\
        <thead>\
          <tr style=\"background:#f9fafb;\">\
            <th style=\"text-align:left; padding:8px;\">Product</th>\
            <th style=\"text-align:center; padding:8px;\">Qty</th>\
            <th style=\"text-align:right; padding:8px;\">Price</th>\
            <th style=\"text-align:right; padding:8px;\">Total</th>\
          </tr>\
        </thead>\
        <tbody>${itemsHtml}</tbody>\
        <tfoot>\
          <tr>\
            <td colspan=\"3\" style=\"text-align:right; padding:10px; font-weight:800;\">Grand Total</td>\
            <td style=\"text-align:right; padding:10px; font-weight:800;\">₱${total}</td>\
          </tr>\
        </tfoot>\
      </table>`;
    modal.style.display = 'flex';
}
function escapeHtml(t){ const d=document.createElement('div'); d.textContent=t==null?'':String(t); return d.innerHTML; }
</script>
