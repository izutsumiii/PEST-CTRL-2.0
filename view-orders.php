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

// Automatically update database ENUM to include 'completed' if needed
try {
    $pdo->exec("ALTER TABLE `orders` MODIFY COLUMN `status` ENUM('pending','processing','shipped','delivered','completed','cancelled') DEFAULT 'pending'");
    error_log("Successfully updated orders.status ENUM to include 'completed'");
} catch (Exception $e) {
    // ENUM might already be updated or error occurred, continue anyway
    error_log("Note: orders.status ENUM update attempt: " . $e->getMessage());
}

// Handle status update - MUST be before any HTML output
if (isset($_POST['update_status'])) {
    $orderId = intval($_POST['order_id']);
    $newStatus = sanitizeInput($_POST['status']);
    $cancellationReason = isset($_POST['cancellation_reason']) ? trim(sanitizeInput($_POST['cancellation_reason'])) : '';
    
    // Validate cancellation reason is required when canceling
    if ($newStatus === 'cancelled' && empty($cancellationReason)) {
        $_SESSION['order_message'] = ['type' => 'error', 'text' => 'Please provide a reason for cancellation.'];
        header("Location: view-orders.php");
        exit();
    }
    
    // CRITICAL FIX: Check BOTH o.seller_id (new multi-seller orders) AND p.seller_id (legacy compatibility)
    $stmt = $pdo->prepare("SELECT o.* FROM orders o LEFT JOIN order_items oi ON o.id = oi.order_id LEFT JOIN products p ON oi.product_id = p.id WHERE o.id = ? AND (o.seller_id = ? OR p.seller_id = ?) GROUP BY o.id");
    $stmt->execute([$orderId, $userId, $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Update order status and set delivery_date if status is 'delivered'
    if ($newStatus === 'delivered') {
        $stmt = $pdo->prepare("UPDATE orders SET status = ?, delivery_date = NOW(), updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$newStatus, $orderId]);
    } elseif ($newStatus === 'completed') {
        // For completed status, ensure delivery_date is set if not already
        $stmt = $pdo->prepare("UPDATE orders SET status = ?, delivery_date = COALESCE(delivery_date, NOW()), updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$newStatus, $orderId]);
    } elseif ($newStatus === 'cancelled') {
        // For cancelled status, save cancellation reason and cancelled_at
        $stmt = $pdo->prepare("UPDATE orders SET status = ?, cancellation_reason = ?, cancelled_at = NOW(), updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$newStatus, $cancellationReason, $orderId]);
    } else {
        $stmt = $pdo->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$newStatus, $orderId]);
    }
    
    // Verify the update actually worked by checking the status
    if ($result && $newStatus === 'completed') {
        $verifyStmt = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
        $verifyStmt->execute([$orderId]);
        $actualStatus = $verifyStmt->fetchColumn();
        if ($actualStatus !== 'completed') {
            error_log("WARNING: Order status update to 'completed' failed. Database ENUM may not include 'completed'. Actual status: " . $actualStatus);
            $_SESSION['order_message'] = ['type' => 'error', 'text' => 'Failed to update order status. Please ensure the database ENUM includes "completed" status.'];
        }
    }

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
                'delivered' => 'Your order has been delivered by the courier. Please confirm when you receive it.',
                'completed' => '✅ Order #' . str_pad($orderId, 6, '0', STR_PAD_LEFT) . ' has been completed! Thank you for your purchase.',
                'cancelled' => 'Your order has been cancelled.' . (!empty($cancellationReason) ? ' Reason: ' . htmlspecialchars($cancellationReason) : ''),
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

    // Send email notification for cancelled orders
    if ($result && $newStatus === 'cancelled') {
        try {
            // Get customer email and order details
            $stmt = $pdo->prepare("SELECT o.*, u.email, u.first_name, u.last_name, COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.username, 'Customer') as customer_name 
                                  FROM orders o 
                                  LEFT JOIN users u ON o.user_id = u.id 
                                  WHERE o.id = ?");
            $stmt->execute([$orderId]);
            $orderData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($orderData && !empty($orderData['email'])) {
                // Get order items
                $stmt = $pdo->prepare("SELECT oi.quantity, oi.price as item_price, p.name as product_name 
                                      FROM order_items oi 
                                      JOIN products p ON oi.product_id = p.id 
                                      WHERE oi.order_id = ?");
                $stmt->execute([$orderId]);
                $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Send email
                sendOrderStatusUpdateEmail(
                    $orderData['email'],
                    $orderData['customer_name'],
                    $orderId,
                    $newStatus,
                    $order['status'] ?? 'pending',
                    $orderItems,
                    $orderData['total_amount'],
                    $pdo,
                    $cancellationReason
                );
            }
        } catch (Exception $e) {
            // Log error but don't stop execution
            error_log("Failed to send cancellation email: " . $e->getMessage());
        }
    }

if ($result && in_array($newStatus, ['processing', 'shipped', 'delivered', 'completed'])) {
    require_once 'includes/seller_notification_functions.php';
    
    $statusMessages = [
        'processing' => '✅ Order #' . str_pad($orderId, 6, '0', STR_PAD_LEFT) . ' moved to Processing',
        'shipped' => '🚚 Order #' . str_pad($orderId, 6, '0', STR_PAD_LEFT) . ' has been shipped',
        'delivered' => '📦 Order #' . str_pad($orderId, 6, '0', STR_PAD_LEFT) . ' was delivered by courier',
        'completed' => '✅ Order #' . str_pad($orderId, 6, '0', STR_PAD_LEFT) . ' has been completed'
    ];
    
    $notificationType = ($newStatus === 'completed') ? 'COMPLETE' : 'info';
    
    createSellerNotification(
        $userId,
        ($newStatus === 'completed') ? 'Order Completed' : 'Order Status Updated',
        $statusMessages[$newStatus] ?? 'Order status changed',
        $notificationType,
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

        // Add cancellation reason to message if cancelled
        if ($newStatus === 'cancelled' && !empty($cancellationReason)) {
            $config['message'] .= '<br><br><strong>Reason for cancellation:</strong><br><em style="color: #666; font-size: 14px;">' . htmlspecialchars($cancellationReason) . '</em>';
        }

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


// Pagination setup
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Get total count of unique orders for this seller
$countStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT o.id) as total 
    FROM orders o 
    LEFT JOIN order_items oi ON o.id = oi.order_id 
    LEFT JOIN products p ON oi.product_id = p.id 
    WHERE (o.seller_id = ? OR p.seller_id = ?)
");
$countStmt->execute([$userId, $userId]);
$totalOrders = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalOrders / $limit);

// CRITICAL FIX: Check BOTH o.seller_id (new multi-seller orders) AND p.seller_id (legacy compatibility)
// Get unique order IDs first with pagination
$orderIdsStmt = $pdo->prepare("
    SELECT DISTINCT o.id 
    FROM orders o 
    LEFT JOIN order_items oi ON o.id = oi.order_id 
    LEFT JOIN products p ON oi.product_id = p.id 
    WHERE (o.seller_id = ? OR p.seller_id = ?) 
    ORDER BY o.created_at DESC 
    LIMIT ? OFFSET ?
");
$orderIdsStmt->bindValue(1, $userId, PDO::PARAM_INT);
$orderIdsStmt->bindValue(2, $userId, PDO::PARAM_INT);
$orderIdsStmt->bindValue(3, $limit, PDO::PARAM_INT);
$orderIdsStmt->bindValue(4, $offset, PDO::PARAM_INT);
$orderIdsStmt->execute();
$orderIds = $orderIdsStmt->fetchAll(PDO::FETCH_COLUMN);

// Get full order details for the paginated order IDs
if (!empty($orderIds)) {
    $placeholders = str_repeat('?,', count($orderIds) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT o.*, oi.quantity, oi.price as item_price, p.name as product_name, p.id as product_id,
               COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'Guest Customer') as customer_name,
               COALESCE(u.email, 'No email') as customer_email
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN products p ON oi.product_id = p.id
        LEFT JOIN users u ON o.user_id = u.id
        WHERE o.id IN ($placeholders)
        ORDER BY o.created_at DESC
    ");
    $stmt->execute($orderIds);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $orders = [];
}

$groupedOrders = [];
foreach ($orders as $order) {
    $orderId = $order['id'];
    if (!isset($groupedOrders[$orderId])) {
    $groupedOrders[$orderId] = [
        'order_id' => $orderId,
        'customer_name' => $order['customer_name'],
        'customer_email' => $order['customer_email'],
        'total_amount' => $order['total_amount'],
        'status' => $order['status'],
        'created_at' => $order['created_at'],
        'delivery_date' => $order['delivery_date'] ?? null,
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
:root {
    --primary-dark: #130325;
    --accent-yellow: #FFD736;
    --text-dark: #1a1a1a;
    --text-light: #6b7280;
    --border-light: #e5e7eb;
    --bg-light: #f9fafb;
    --bg-white: #ffffff;
    --success-green: #10b981;
    --error-red: #ef4444;
}

html, body { 
    background: var(--bg-light) !important; 
    margin: 0; 
    padding: 0; 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}

main {
    background: var(--bg-light) !important;
    margin: 0;
    padding: 12px;
    margin-top: -100px;
    margin-left: 240px;
    transition: margin-left 0.3s ease !important;
    min-height: calc(100vh - 5px) !important;
}

.sidebar.collapsed ~ main {
    margin-left: 70px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
}

.page-header-title {
    color: var(--text-dark);
    font-size: 1.35rem;
    font-weight: 600;
    margin: 0;
    letter-spacing: -0.3px;
    line-height: 1.2;
    text-shadow: none !important;
}

h1.page-header-title {
    color: var(--primary-dark) !important;
    font-size: 1.35rem;
    font-weight: 700 !important;
    margin: 0;
    margin-bottom: 16px !important;
    letter-spacing: -0.3px;
    line-height: 1.2;
    text-shadow: none !important;
}

.notification-toast {
    position: fixed;
    top: 80px;
    right: 20px;
    max-width: 400px;
    min-width: 320px;
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 10px;
    padding: 16px 18px;
    color: var(--text-dark);
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
    z-index: 10000;
    animation: slideInRight 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    display: flex;
    align-items: center;
    gap: 12px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.notification-toast::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    border-radius: 10px 10px 0 0;
    background: var(--accent-yellow);
}

.notification-toast.success {
    background: var(--bg-white);
    border-color: rgba(40, 167, 69, 0.2);
}

.notification-toast.success::before {
    background: var(--success-green);
}

.notification-toast.error {
    background: var(--bg-white);
    border-color: rgba(220, 53, 69, 0.2);
}

.notification-toast.error::before {
    background: var(--error-red);
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
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    background: rgba(255, 215, 54, 0.15);
    color: var(--accent-yellow);
}

.notification-toast.success .toast-icon {
    background: rgba(40, 167, 69, 0.15);
    color: var(--success-green);
}

.notification-toast.error .toast-icon {
    background: rgba(220, 53, 69, 0.15);
    color: var(--error-red);
}

.notification-toast .toast-content {
    flex: 1;
    min-width: 0;
}

.notification-toast .toast-title {
    font-size: 14px;
    font-weight: 600;
    margin: 0 0 4px 0;
    color: var(--text-dark);
    line-height: 1.3;
}

.notification-toast .toast-message {
    font-size: 13px;
    margin: 0;
    color: var(--text-light);
    line-height: 1.4;
}

.notification-toast .toast-close {
    position: absolute;
    top: 10px;
    right: 10px;
    background: none;
    border: none;
    color: var(--text-light);
    opacity: 0.6;
    font-size: 16px;
    cursor: pointer;
    padding: 4px;
    border-radius: 6px;
    transition: all 0.2s ease;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.notification-toast .toast-close:hover {
    background: rgba(19, 3, 37, 0.05);
    color: var(--text-dark);
    opacity: 1;
    transform: scale(1.1);
}

.orders-container {
    max-width: 1400px;
    margin: 0;
    margin-left: -220px;
    transition: margin-left 0.3s ease;
}

.sidebar.collapsed ~ main .orders-container {
    margin-left: -150px;
}

/* ============================================
   FILTER SECTION - MODERN DESIGN
   ============================================ */

.filter-section {
    margin-bottom: 16px;
    padding: 14px 16px;
    background: var(--bg-white);
    border-radius: 6px;
    border: 1px solid rgba(19, 3, 37, 0.08);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    transition: all 0.2s ease;
}

.filter-section:hover {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border-color: rgba(19, 3, 37, 0.12);
}

.filter-wrapper {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

.filter-search {
    flex: 1;
    min-width: 150px;
    position: relative;
    display: flex;
    align-items: center;
}

.filter-search i {
    position: absolute;
    left: 10px;
    color: var(--text-light);
    font-size: 12px;
    pointer-events: none;
}

.filter-search input {
    width: 100%;
    padding: 8px 12px 8px 32px;
    border: 1.5px solid rgba(19, 3, 37, 0.15);
    border-radius: 6px;
    font-size: 13px;
    background: var(--bg-white);
    color: var(--text-dark);
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.filter-search input:focus {
    outline: none;
    border-color: var(--primary-dark);
    box-shadow: 0 0 0 3px rgba(19, 3, 37, 0.1), 0 2px 4px rgba(19, 3, 37, 0.08);
    border-width: 1.5px;
    transform: translateY(-1px);
}

.filter-search input::placeholder {
    color: var(--text-light);
    opacity: 0.7;
}

.filter-dropdown {
    min-width: 140px;
}

.filter-dropdown select {
    width: 100%;
    padding: 8px 32px 8px 12px;
    border: 1.5px solid rgba(19, 3, 37, 0.15);
    border-radius: 6px;
    font-size: 13px;
    background: var(--bg-white);
    color: var(--text-dark);
    cursor: pointer;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23130325' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.filter-dropdown select:focus {
    outline: none;
    border-color: var(--primary-dark);
    box-shadow: 0 0 0 3px rgba(19, 3, 37, 0.1), 0 2px 4px rgba(19, 3, 37, 0.08);
    transform: translateY(-1px);
}

.orders-table-container {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 10px;
    padding: 20px;
    margin-top: 0;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.table-wrapper {
    overflow-x: auto;
}

.orders-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.orders-table thead {
    background: var(--primary-dark);
    border-bottom: 2px solid var(--accent-yellow);
}

.orders-table th {
    padding: 12px 14px;
    text-align: left;
    font-weight: 600;
    color: var(--bg-white);
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    position: relative;
    user-select: none;
    cursor: pointer;
    transition: background 0.2s ease;
    padding-right: 28px;
}

.orders-table th.sortable {
    cursor: pointer;
}

.orders-table th.sortable:hover {
    background: rgba(255, 215, 54, 0.2);
}

.sort-indicator {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 10px;
    color: var(--accent-yellow);
    transition: all 0.2s ease;
}

.sort-indicator::before {
    content: '↕';
    display: block;
}

.sort-indicator.asc::before {
    content: '↑';
    color: var(--accent-yellow);
}

.sort-indicator.desc::before {
    content: '↓';
    color: var(--accent-yellow);
}

.orders-table td {
    padding: 12px 14px;
    color: var(--text-dark);
    border-bottom: 1px solid var(--border-light);
    vertical-align: top;
    font-size: 0.875rem;
}

.orders-table tbody tr {
    border-bottom: 1px solid var(--border-light);
    transition: background 0.2s ease;
}

.orders-table tbody tr:hover {
    background: rgba(19, 3, 37, 0.03);
}

.order-id {
    color: var(--primary-dark);
    font-weight: 600;
    font-size: 0.9rem;
    text-decoration: none;
    transition: color 0.2s ease;
}

.order-id:hover {
    color: var(--accent-yellow);
}

.customer-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.customer-name {
    color: var(--text-dark);
    font-weight: 600;
    font-size: 0.875rem;
}

.customer-email {
    color: var(--text-light);
    font-size: 0.75rem;
    font-weight: 500;
}

.product-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.product-item {
    padding: 3px 0;
    color: var(--text-dark);
    opacity: 0.9;
    font-size: 0.813rem;
    line-height: 1.4;
}

.product-item strong {
    color: var(--text-dark);
    font-weight: 600;
}

.total-amount {
    color: var(--primary-dark);
    font-weight: 700;
    font-size: 0.95rem;
}

.status-container {
    display: flex;
    align-items: center;
    gap: 6px;
}

.order-status {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.status-pending { background: rgba(255,193,7,0.15); color: #ffc107; }
.status-processing { background: rgba(0,123,255,0.15); color: #007bff; }
.status-shipped { background: rgba(23,162,184,0.15); color: #17a2b8; }
.status-delivered { background: rgba(40,167,69,0.15); color: #28a745; }
.status-completed { background: rgba(40,167,69,0.15); color: #28a745; }
.status-cancelled { background: rgba(220,53,69,0.15); color: #dc3545; }

.ready-indicator {
    color: #28a745;
    font-size: 0.6rem;
    opacity: 0.8;
    animation: pulse 2s infinite;
    display: inline-flex;
    align-items: center;
}

.ready-indicator i {
    filter: drop-shadow(0 0 2px rgba(40, 167, 69, 0.5));
}

@keyframes pulse {
    0%, 100% { opacity: 0.8; }
    50% { opacity: 1; }
}

.order-date {
    color: var(--text-light);
    font-size: 0.813rem;
    line-height: 1.4;
}

.actions-cell {
    min-width: 150px;
    text-align: center;
}

.grace-period-timer {
    background: rgba(255,193,7,0.15);
    border: 1px solid #ffc107;
    padding: 6px 10px;
    border-radius: 6px;
    text-align: center;
    color: #ffc107;
    font-weight: 600;
    font-size: 0.75rem;
    margin-bottom: 8px;
}

.grace-period-ready {
    background: rgba(40, 167, 69, 0.15);
    border: none;
    color: #28a745;
    padding: 6px 10px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 0.75rem;
    text-transform: uppercase;
    display: block;
    margin-bottom: 8px;
    text-align: center;
    width: 100%;
}

.action-buttons {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.action-btn {
    width: auto;
    min-width: 110px;
    padding: 8px 12px;
    border: 2px solid;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.813rem;
    cursor: pointer;
    transition: all 0.2s ease;
    text-align: center;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    white-space: nowrap;
    background: transparent;
}

.btn-process {
    background: transparent;
    color: #28a745;
    border: 2px solid #28a745;
}

.btn-process:hover {
    background: rgba(40, 167, 69, 0.1);
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(40, 167, 69, 0.2);
}

.btn-ship {
    background: transparent;
    color: #17a2b8;
    border: 2px solid #17a2b8;
}

.btn-ship:hover {
    background: rgba(23, 162, 184, 0.1);
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(23, 162, 184, 0.2);
}

.btn-delivered {
    background: transparent;
    color: #28a745;
    border: 2px solid #28a745;
}

.btn-delivered:hover {
    background: rgba(40, 167, 69, 0.1);
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(40, 167, 69, 0.2);
}

.btn-completed {
    background: transparent;
    color: var(--primary-dark);
    border: 2px solid var(--primary-dark);
}

.btn-completed:hover:not(:disabled) {
    background: rgba(19, 3, 37, 0.1);
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(19, 3, 37, 0.2);
}

.btn-cancel {
    background: #130325;
    color: white;
    border: 2px solid #130325;
}

.btn-cancel:hover {
    background: #0a0118;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(19, 3, 37, 0.3);
}

.btn-view {
    background: transparent;
    color: #130325;
    border: 2px solid #130325;
    text-decoration: none;
}

.btn-view:hover {
    background: rgba(19, 3, 37, 0.1);
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(19, 3, 37, 0.2);
}

/* Custom Confirmation Modal - Matching Logout Modal */
.custom-confirm-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    opacity: 0;
    visibility: hidden;
    transition: all 0.25s ease;
}

.custom-confirm-overlay.show {
    opacity: 1;
    visibility: visible;
}

.custom-confirm-dialog {
    background: #ffffff;
    border-radius: 12px;
    padding: 0;
    max-width: 400px;
    width: 90%;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    animation: slideDown 0.3s ease;
}

.custom-confirm-header {
    background: #130325;
    color: #ffffff;
    padding: 16px 20px;
    border-radius: 12px 12px 0 0;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    font-size: 16px;
}

.custom-confirm-body {
    padding: 20px;
    color: #130325;
    font-size: 14px;
    line-height: 1.5;
}

.custom-confirm-footer {
    padding: 16px 24px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.custom-confirm-btn {
    padding: 8px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.2s ease;
}

.custom-confirm-btn.cancel {
    background: #f3f4f6;
    color: #130325;
    border: 1px solid #e5e7eb;
}

.custom-confirm-btn.cancel:hover {
    background: #e5e7eb;
}

.custom-confirm-btn.confirm {
    background: #130325;
    color: #ffffff;
}

.custom-confirm-btn.confirm:hover {
    background: #0a0218;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Cancellation Reason Modal - Matching Logout Modal */
.cancel-reason-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10001;
    opacity: 0;
    visibility: hidden;
    transition: all 0.25s ease;
}

.cancel-reason-overlay.show {
    opacity: 1;
    visibility: visible;
}

.cancel-reason-dialog {
    background: #ffffff;
    border-radius: 12px;
    padding: 0;
    max-width: 500px;
    width: 90%;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    animation: slideDown 0.3s ease;
}

.cancel-reason-header {
    background: #130325;
    color: #ffffff;
    padding: 16px 20px;
    border-radius: 12px 12px 0 0;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    font-size: 16px;
}

.cancel-reason-body {
    padding: 20px;
    color: #130325;
    font-size: 14px;
    line-height: 1.5;
}

.cancel-reason-footer {
    padding: 16px 24px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.cancel-reason-btn {
    padding: 8px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.2s ease;
}

.cancel-reason-btn.cancel {
    background: #f3f4f6;
    color: #130325;
    border: 1px solid #e5e7eb;
}

.cancel-reason-btn.cancel:hover {
    background: #e5e7eb;
}

.cancel-reason-btn.submit {
    background: #130325;
    color: #ffffff;
}

.cancel-reason-btn.submit:hover {
    background: #0a0218;
}



.no-orders-message {
    text-align: center;
    padding: 40px;
    color: var(--text-light);
    font-size: 0.9rem;
}

/* Pagination */
.pagination { 
    display: flex; 
    justify-content: center; 
    gap: 8px; 
    margin-top: 24px; 
    flex-wrap: wrap;
}

.page-link { 
    padding: 8px 14px; 
    background: var(--bg-white) !important; 
    color: var(--primary-dark) !important; 
    text-decoration: none; 
    border-radius: 8px; 
    border: 1px solid var(--border-light) !important; 
    font-weight: 600; 
    font-size: 0.813rem; 
    transition: all 0.2s ease; 
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.page-link i { 
    color: var(--primary-dark) !important; 
    font-size: 0.75rem;
}

.page-link:hover { 
    background: rgba(19, 3, 37, 0.05) !important; 
    color: var(--primary-dark) !important; 
    border-color: var(--primary-dark) !important; 
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(19, 3, 37, 0.15);
}

.page-link:hover i { 
    color: var(--primary-dark) !important; 
}

.page-link.active { 
    background: var(--primary-dark) !important; 
    color: var(--bg-white) !important; 
    border-color: var(--primary-dark) !important; 
    box-shadow: 0 2px 8px rgba(19, 3, 37, 0.3); 
}

.page-link.active i { 
    color: var(--bg-white) !important; 
}

.page-ellipsis { 
    padding: 8px 8px; 
    color: var(--text-light); 
    font-weight: 700; 
    display: inline-flex;
    align-items: center;
}

.pagination-info { 
    text-align: center; 
    margin-top: 12px; 
    color: var(--text-light); 
    font-size: 0.813rem; 
    font-weight: 500; 
}

/* Responsive Design */
@media (max-width: 968px) {
    main {
        margin-left: 0 !important;
        padding: 10px 8px;
        margin-top: -50px;
    }

    .orders-container {
        margin-left: 0 !important;
        max-width: 100%;
    }

    .filter-section {
        padding: 8px 10px;
        margin-bottom: 10px;
    }

    .filter-wrapper {
        gap: 6px;
    }

    .filter-search {
        min-width: 120px;
    }

    .filter-search input {
        padding: 5px 8px 5px 24px;
        font-size: 11px;
    }

    .filter-search i {
        left: 8px;
        font-size: 11px;
    }

    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }

    .page-header-title {
        font-size: 1.2rem;
    }


    .orders-table-container { 
        padding: 16px; 
    }

    .orders-table { 
        font-size: 0.75rem; 
    }

    .orders-table th, .orders-table td { 
        padding: 10px 12px; 
    }

    .table-wrapper { 
        overflow-x: auto; 
    }

    .action-btn {
        min-width: 100px;
        padding: 6px 10px;
        font-size: 0.75rem;
    }
}

@media (max-width: 768px) {
    .filter-section {
        padding: 6px 8px;
    }

    .filter-wrapper {
        flex-direction: column;
        gap: 6px;
    }

    .filter-search,
    .filter-dropdown {
        width: 100%;
        min-width: 100%;
    }

    .filter-search input,
    .filter-dropdown select {
        padding: 5px 8px 5px 24px;
        font-size: 11px;
    }
}

@media (max-width: 480px) {
    main {
        padding: 8px 4px;
        margin-top: 12px;
    }

    .page-header {
        padding: 16px 0;
    }

    .page-header-title {
        font-size: 1.1rem;
    }

    .filter-section {
        padding: 8px 6px;
        margin-bottom: 12px;
    }

    .filter-wrapper {
        gap: 4px;
    }

    .filter-search,
    .filter-dropdown {
        min-width: 100%;
        width: 100%;
    }

    .filter-search input,
    .filter-dropdown select {
        padding: 6px 8px 6px 28px;
        font-size: 11px;
    }

    .filter-search i {
        left: 8px;
        font-size: 10px;
    }

    .orders-table-container {
        padding: 12px 6px;
    }

    .orders-table {
        font-size: 0.75rem;
    }

    .orders-table th,
    .orders-table td {
        padding: 6px 4px;
    }

    .customer-name {
        font-size: 0.8rem;
    }

    .customer-email {
        font-size: 0.65rem;
    }

    .action-btn {
        min-width: 80px;
        padding: 4px 6px;
        font-size: 0.7rem;
    }

    .pagination {
        gap: 2px;
    }

    .page-link {
        padding: 6px 8px;
        font-size: 0.7rem;
    }

    .pagination-info {
        font-size: 0.75rem;
    }
}

@media (max-width: 360px) {
    main {
        padding: 6px 3px;
        margin-top: 8px;
    }

    .page-header {
        padding: 12px 0;
    }

    .page-header-title {
        font-size: 1rem;
    }

    .filter-section {
        padding: 6px 4px;
        margin-bottom: 8px;
    }

    .orders-table-container {
        padding: 8px 4px;
    }

    .orders-table {
        font-size: 0.7rem;
    }

    .orders-table th,
    .orders-table td {
        padding: 4px 3px;
    }

    .customer-name {
        font-size: 0.75rem;
    }

    .customer-email {
        font-size: 0.6rem;
    }

    .action-btn {
        min-width: 70px;
        padding: 3px 4px;
        font-size: 0.65rem;
    }

    .page-link {
        padding: 4px 6px;
        font-size: 0.65rem;
    }
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

    <div class="page-header">
        <h1 class="page-header-title">Order Management</h1>
    </div>

    <div class="filter-section">
        <div class="filter-wrapper">
            <div class="filter-search">
                <i class="fas fa-search"></i>
                <input type="text" id="orderSearch" placeholder="Search by Order ID, Customer, Status, or Product..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>" onkeyup="filterOrders()">
            </div>
            <div class="filter-dropdown">
                <select id="statusFilter" onchange="filterOrders()">
                    <option value="all">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="processing">Processing</option>
                    <option value="shipped">Shipped</option>
                    <option value="delivered">Delivered</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
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
                            <td colspan="7" class="no-orders-message" style="text-align:center; padding:40px 20px;">
                                <i class="fas fa-inbox" style="font-size: 48px; color: var(--text-light); opacity: 0.5; margin-bottom: 16px; display: block;"></i>
                                <p style="margin: 0; color: var(--text-light); font-size: 0.9rem;">No orders found.</p>
                            </td>
                        </tr>
                        <?php else: foreach ($groupedOrders as $order):
            $withinGracePeriod = isWithinGracePeriod($order['created_at'], $pdo);
            $remainingTime = $withinGracePeriod ? getRemainingGracePeriod($order['created_at'], $pdo) : null;
            $statusClass = 'status-' . strtolower($order['status']);
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
                                <div class="customer-info">
                                    <div class="customer-name"><?php echo htmlspecialchars($order['customer_name']); ?></div>
                                    <div class="customer-email"><?php echo htmlspecialchars($order['customer_email']); ?></div>
                                </div>
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
                                <div class="status-container">
                                    <span class="order-status <?php echo $statusClass; ?>">
                                        <?php echo ucfirst($order['status']); ?>
                                    </span>
                                    <?php if ($order['status'] === 'pending' && !$withinGracePeriod): ?>
                                        <span class="ready-indicator" title="Ready to process">
                                            <i class="fas fa-circle"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
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
                                        <div class="action-buttons">
                                            <a href="seller-order-details.php?order_id=<?php echo $order['order_id']; ?>" class="action-btn btn-view">
                                                <i class="fas fa-eye"></i>
                                                <span>View</span>
                                            </a>
                                            <form method="POST" onsubmit="return confirmStatusChange('processing');">
                                                <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                                <input type="hidden" name="status" value="processing">
                                                <input type="hidden" name="update_status" value="1">
                                                <button type="submit" class="action-btn btn-process">
                                                    <i class="fas fa-check"></i>
                                                    <span>Process</span>
                                                </button>
                                            </form>
                                            <button type="button" class="action-btn btn-cancel" onclick="showCancelReasonModal(<?php echo $order['order_id']; ?>)">
                                                <i class="fas fa-times"></i>
                                                <span>Cancel</span>
                                            </button>
                                        </div>
        <?php endif; ?>
                                <?php elseif ($order['status'] === 'processing'): ?>
                                    <div class="action-buttons">
                                        <a href="seller-order-details.php?order_id=<?php echo $order['order_id']; ?>" class="action-btn btn-view">
                                            <i class="fas fa-eye"></i>
                                            <span>View</span>
                                        </a>
                                        <form method="POST" onsubmit="return confirmStatusChange('shipped');">
                                            <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                            <input type="hidden" name="status" value="shipped">
                                            <input type="hidden" name="update_status" value="1">
                                            <button type="submit" class="action-btn btn-ship">
                                                <i class="fas fa-truck"></i>
                                                <span>Ship</span>
                                            </button>
                                        </form>
                                        <button type="button" class="action-btn btn-cancel" onclick="showCancelReasonModal(<?php echo $order['order_id']; ?>)">
                                            <i class="fas fa-times"></i>
                                            <span>Cancel</span>
                                        </button>
                                    </div>
                                <?php elseif ($order['status'] === 'shipped'): ?>
                                    <div class="action-buttons">
                                        <a href="seller-order-details.php?order_id=<?php echo $order['order_id']; ?>" class="action-btn btn-view">
                                            <i class="fas fa-eye"></i>
                                            <span>View</span>
                                        </a>
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
                                <?php elseif ($order['status'] === 'delivered'): ?>
                                    <div class="action-buttons">
                                        <a href="seller-order-details.php?order_id=<?php echo $order['order_id']; ?>" class="action-btn btn-view">
                                            <i class="fas fa-eye"></i>
                                            <span>View</span>
                                        </a>
                                        <?php
                                        // Check if 1 week has passed since delivery_date
                                        $deliveryDate = $order['delivery_date'] ?? null;
                                        $canComplete = false;
                                        $daysSinceDelivery = 0; // Initialize variable

                                        if ($deliveryDate) {
                                            $deliveryDateTime = new DateTime($deliveryDate);
                                            $currentDateTime = new DateTime();
                                            $daysSinceDelivery = $currentDateTime->diff($deliveryDateTime)->days;
                                            $canComplete = $daysSinceDelivery >= 7;
                                        }

                                        // Check if order is already completed (customer might have confirmed)
                                        // Use the order status from the fetched data
                                        if (strtolower($order['status']) === 'completed') {
                                            echo '<span style="color: #28a745; font-weight: 600; font-size: 12px;">✓ Completed</span>';
                                        } elseif ($canComplete) {
                                            // Button enabled after 1 week
                                            ?>
                                            <form method="POST" onsubmit="return confirmStatusChange('completed');">
                                                <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                                <input type="hidden" name="status" value="completed">
                                                <input type="hidden" name="update_status" value="1">
                                                <button type="submit" class="action-btn btn-completed">
                                                    <i class="fas fa-check-double"></i>
                                                    <span>Order Completed</span>
                                                </button>
                                            </form>
                                            <?php
                                        } else {
                                            // Button disabled - show countdown
                                            if ($deliveryDate) {
                                                $daysRemaining = max(0, 7 - $daysSinceDelivery);
                                            } else {
                                                $daysRemaining = 7; // If no delivery date, show 7 days
                                            }
                                            ?>
                                            <button type="button" class="action-btn btn-completed" disabled style="opacity: 0.6; cursor: not-allowed;" title="Available after <?php echo $daysRemaining; ?> day(s)">
                                                <i class="fas fa-clock"></i>
                                                <span>Order Completed (<?php echo $daysRemaining; ?>d)</span>
                                            </button>
                                            <?php
                                        }
                                        ?>
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

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="view-orders.php?page=<?php echo $page - 1; ?><?php echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?>" class="page-link">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                <?php endif; ?>
                
                <?php 
                // Show page numbers with ellipsis for large page counts
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                if ($startPage > 1): ?>
                    <a href="view-orders.php?page=1<?php echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?>" class="page-link">1</a>
                    <?php if ($startPage > 2): ?>
                        <span class="page-ellipsis">...</span>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <a href="view-orders.php?page=<?php echo $i; ?><?php echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?>" 
                       class="page-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <span class="page-ellipsis">...</span>
                    <?php endif; ?>
                    <a href="view-orders.php?page=<?php echo $totalPages; ?><?php echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?>" class="page-link"><?php echo $totalPages; ?></a>
                <?php endif; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="view-orders.php?page=<?php echo $page + 1; ?><?php echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?>" class="page-link">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            
            <div class="pagination-info">
                Showing <?php echo (($page - 1) * $limit + 1); ?>-<?php echo min($page * $limit, $totalOrders); ?> 
                of <?php echo $totalOrders; ?> orders
            </div>
        <?php endif; ?>
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

        // CRITICAL FIX: Skip confirmation for cancel status - it has its own modal
        if (nextStatus === 'cancelled') {
            return true; // Allow form to submit normally (from cancel reason modal)
        }

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
            const customerEmail = row.querySelector('.customer-email')?.textContent.toLowerCase() || '';
            const statusBadge = row.querySelector('.order-status')?.textContent.toLowerCase() || '';
            const productItems = row.querySelectorAll('.product-item strong');
            const productNames = Array.from(productItems).map(item => item.textContent.toLowerCase()).join(' ');

            const matchesSearch = orderId.includes(searchTerm) ||
                                 customerName.includes(searchTerm) ||
                                 customerEmail.includes(searchTerm) ||
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

    const actionText = nextStatus==='processing' ? 'Move order to Processing?' :
                      nextStatus==='shipped' ? 'Mark order as Shipped?' :
                      nextStatus==='delivered' ? 'Mark order as Delivered?' :
                      nextStatus==='cancelled' ? 'Cancel this order?' : 'Apply this status change?';

    overlay.innerHTML = `
      <div class="custom-confirm-dialog">
        <div class="custom-confirm-header">
            <i class="fas fa-exclamation-triangle"></i>
            Confirm Action
        </div>
        <div class="custom-confirm-body">
            <p style="margin: 0; font-size: 14px; line-height: 1.5; color: #130325;">${actionText}</p>
        </div>
        <div class="custom-confirm-footer">
          <button type="button" class="custom-confirm-btn cancel">Cancel</button>
          <button type="button" class="custom-confirm-btn confirm">Confirm</button>
        </div>
      </div>`;
    document.body.appendChild(overlay);
    requestAnimationFrame(()=>overlay.classList.add('show'));
    return new Promise((resolve)=>{
      const close = ()=>{ overlay.classList.remove('show'); setTimeout(()=>overlay.remove(), 200); };
      overlay.querySelector('.cancel').addEventListener('click', ()=>{ close(); resolve(false); });
      overlay.querySelector('.confirm').addEventListener('click', ()=>{ close(); resolve(true); });
      overlay.addEventListener('click', (e)=>{ if(e.target===overlay){ close(); resolve(false);} });
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

// Cancellation Reason Modal - Matching Logout Modal
function showCancelReasonModal(orderId) {
    const overlay = document.createElement('div');
    overlay.className = 'cancel-reason-overlay';
    overlay.innerHTML = `
        <div class="cancel-reason-dialog">
            <div class="cancel-reason-header">
                <i class="fas fa-exclamation-triangle"></i>
                Cancel Order #${String(orderId).padStart(6, '0')}
            </div>
            <div class="cancel-reason-body">
                <p style="margin: 0 0 16px 0; font-size: 14px; line-height: 1.5; color: #130325;">
                    Please provide a reason for cancellation. This will be sent to the customer.
                </p>
                <form id="cancelOrderForm" method="POST">
                    <input type="hidden" name="order_id" value="${orderId}">
                    <input type="hidden" name="status" value="cancelled">
                    <input type="hidden" name="update_status" value="1">
                    <label for="cancellation_reason" style="display: block; margin-bottom: 8px; font-weight: 600; color: #130325;">
                        Cancellation Reason / Remarks <span style="color: #dc3545;">*</span>
                    </label>
                    <textarea
                        name="cancellation_reason"
                        id="cancellation_reason"
                        style="width: 100%; padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px; font-family: inherit; font-size: 14px; line-height: 1.5; resize: vertical; min-height: 80px;"
                        placeholder="Please explain why this order is being cancelled (e.g., Out of stock, Customer request, Payment issue, etc.)"
                        required
                        minlength="10"
                    ></textarea>
                </form>
            </div>
            <div class="cancel-reason-footer">
                <button type="button" class="cancel-reason-btn cancel" onclick="closeCancelReasonModal()">Cancel</button>
                <button type="button" class="cancel-reason-btn submit" onclick="document.getElementById('cancelOrderForm').submit()">Confirm Cancellation</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);
    requestAnimationFrame(() => overlay.classList.add('show'));
    
    // Focus on textarea
    const textarea = overlay.querySelector('#cancellation_reason');
    if (textarea) {
        setTimeout(() => textarea.focus(), 100);
    }
    
    // Handle form submission
    const form = overlay.querySelector('#cancelOrderForm');
    form.addEventListener('submit', function(e) {
        const reason = textarea.value.trim();
        if (reason.length < 10) {
            e.preventDefault();
            alert('Please provide a detailed reason (at least 10 characters).');
            textarea.focus();
            return false;
        }
    });
    
    // Close on overlay click
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) {
            closeCancelReasonModal();
        }
    });
}

function closeCancelReasonModal() {
    const overlay = document.querySelector('.cancel-reason-overlay');
    if (overlay) {
        overlay.classList.remove('show');
        setTimeout(() => overlay.remove(), 250);
    }
}
</script>
