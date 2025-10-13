<?php
date_default_timezone_set('Asia/Manila');
require_once 'includes/header.php';
require_once 'config/database.php';

// spacer below fixed header (add ~8px more)
echo '<div style="height:41px"></div>';

requireLogin();
// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'PHPMailer/PHPMailer/src/Exception.php';
require 'PHPMailer/PHPMailer/src/PHPMailer.php';
require 'PHPMailer/PHPMailer/src/SMTP.php';

// Function to send cancellation notification email
function sendCancellationEmail($orderId, $customerName, $customerEmail, $reason, $orderTotal, $orderItems) {
    global $pdo;
    
    // Get seller email from order items
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.email, u.first_name, u.last_name
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            JOIN users u ON p.user_id = u.id
            WHERE oi.order_id = ? AND u.user_type = 'seller'
        ");
        $stmt->execute([$orderId]);
        $sellers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($sellers)) {
            error_log("No seller found for order #$orderId");
            return false;
        }
        
        $mail = new PHPMailer(true);
        $emailsSent = 0;
        
        // Send email to each seller involved in the order
        foreach ($sellers as $seller) {
            try {
                $mail->clearAddresses();
                $mail->clearReplyTos();
                
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'jhongujol1299@gmail.com';
                $mail->Password = 'ljdo ohkv pehx idkv';
                $mail->SMTPSecure = "ssl";
                $mail->Port = 465;
                
                // Recipients
                $mail->setFrom('jhongujol1299@gmail.com', 'E-Commerce Store');
                $mail->addAddress($seller['email'], $seller['first_name'] . ' ' . $seller['last_name']);
                $mail->addReplyTo($customerEmail, $customerName);
                
                // Content
                $mail->isHTML(true);
                $mail->Subject = '🚫 Order Cancellation - Order #' . str_pad($orderId, 6, '0', STR_PAD_LEFT);
                $mail->Body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
                    <div style='text-align: center; border-bottom: 2px solid #dc3545; padding-bottom: 20px;'>
                        <h1 style='color: #dc3545; margin: 0;'>Order Cancellation</h1>
                    </div>
                    <div style='padding: 30px 0;'>
                        <h2 style='color: #333; margin-bottom: 10px;'>Dear " . htmlspecialchars($seller['first_name']) . ",</h2>
                        <p style='color: #666; font-size: 16px;'>A customer has cancelled their order. Please review the details below:</p>
                        
                        <div style='background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                            <p style='margin: 10px 0;'><strong>Order ID:</strong> #" . str_pad($orderId, 6, '0', STR_PAD_LEFT) . "</p>
                            <p style='margin: 10px 0;'><strong>Customer Name:</strong> " . htmlspecialchars($customerName) . "</p>
                            <p style='margin: 10px 0;'><strong>Customer Email:</strong> " . htmlspecialchars($customerEmail) . "</p>
                            <p style='margin: 10px 0;'><strong>Order Total:</strong> $" . number_format($orderTotal, 2) . "</p>
                            <p style='margin: 10px 0;'><strong>Cancellation Date:</strong> " . date('F j, Y g:i A') . "</p>
                        </div>
                        
                        <div style='background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;'>
                            <h3 style='color: #856404; margin-top: 0;'>Cancellation Reason:</h3>
                            <p style='color: #856404; margin: 0;'>" . nl2br(htmlspecialchars($reason)) . "</p>
                        </div>
                        
                        <div style='margin: 20px 0;'>
                            <h3 style='color: #333;'>Order Items:</h3>
                            <p style='color: #666;'>" . htmlspecialchars($orderItems) . "</p>
                        </div>
                        
                        <div style='background-color: #d1ecf1; border: 1px solid #bee5eb; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                            <p style='margin: 0; color: #0c5460; font-size: 14px;'>
                                <strong>Action Required:</strong> Please process the refund if payment was already made. The product stock has been automatically restored.
                            </p>
                        </div>
                    </div>
                    <div style='text-align: center; border-top: 1px solid #eee; padding-top: 20px; color: #999; font-size: 14px;'>
                        <p>This is an automated notification from your E-Commerce Store</p>
                        <p style='margin: 0;'>© 2024 E-Commerce Store</p>
                    </div>
                </div>";
                
                $mail->send();
                $emailsSent++;
            } catch (Exception $e) {
                error_log("Cancellation email could not be sent to seller {$seller['email']}. Mailer Error: {$mail->ErrorInfo}");
            }
        }
        
        return $emailsSent > 0;
        
    } catch (PDOException $e) {
        error_log("Error fetching seller information: " . $e->getMessage());
        return false;
    }
}
$userId = $_SESSION['user_id'];

// Function to check if customer can cancel order (only if status is pending)
function canCustomerCancelOrder($order) {
    return $order['status'] === 'pending';
}

// Function to sanitize input
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// ADD THIS MISSING FUNCTION
function getOrdersByStatus($status) {
    global $pdo, $userId;
    
    try {
        $stmt = $pdo->prepare("SELECT o.*, 
                              GROUP_CONCAT(CONCAT(p.name, ' (x', oi.quantity, ')') SEPARATOR ', ') as items
                              FROM orders o
                              LEFT JOIN order_items oi ON o.id = oi.order_id
                              LEFT JOIN products p ON oi.product_id = p.id
                              WHERE o.user_id = ? AND o.status = ?
                              GROUP BY o.id
                              ORDER BY o.created_at DESC");
        $stmt->execute([$userId, $status]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get detailed order items with images for each order
        foreach ($orders as &$order) {
            $stmt = $pdo->prepare("SELECT oi.*, p.name as product_name, p.image_url, p.price as product_price
                                  FROM order_items oi
                                  JOIN products p ON oi.product_id = p.id
                                  WHERE oi.order_id = ?
                                  ORDER BY oi.id");
            $stmt->execute([$order['id']]);
            $order['order_items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return $orders;
    } catch (PDOException $e) {
        error_log("Error fetching orders by status: " . $e->getMessage());
        return [];
    }
}

// Function to get user orders with delivery date
function getUserOrders() {
    global $pdo, $userId;
    
    $stmt = $pdo->prepare("SELECT o.*, 
                          GROUP_CONCAT(CONCAT(p.name, ' (x', oi.quantity, ')') SEPARATOR ', ') as items
                          FROM orders o
                          LEFT JOIN order_items oi ON o.id = oi.order_id
                          LEFT JOIN products p ON oi.product_id = p.id
                          WHERE o.user_id = ?
                          GROUP BY o.id
                          ORDER BY o.created_at DESC");
    $stmt->execute([$userId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get detailed order items with images for each order
    foreach ($orders as &$order) {
        $stmt = $pdo->prepare("SELECT oi.*, p.name as product_name, p.image_url, p.price as product_price
                              FROM order_items oi
                              JOIN products p ON oi.product_id = p.id
                              WHERE oi.order_id = ?
                              ORDER BY oi.id");
        $stmt->execute([$order['id']]);
        $order['order_items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return $orders;
}

// Updated function to get delivered orders with delivery date
function getDeliveredOrders() {
    global $pdo, $userId;
    
    $stmt = $pdo->prepare("SELECT o.id as order_id, 
                          o.created_at as order_date,
                          o.delivery_date,
                          oi.product_id, 
                          oi.quantity, 
                          p.name as product_name, 
                          p.image_url,
                          p.price,
                          (oi.quantity * oi.price) as item_total
                          FROM orders o
                          JOIN order_items oi ON o.id = oi.order_id
                          JOIN products p ON oi.product_id = p.id
                          WHERE o.user_id = ? AND o.status = 'delivered'
                          ORDER BY o.delivery_date DESC, o.created_at DESC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to update order status and set delivery date
function updateOrderStatus($orderId, $newStatus, $updatedBy = null, $notes = '') {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // Update order status and set delivery_date if status is 'delivered'
        if ($newStatus === 'delivered') {
            $stmt = $pdo->prepare("UPDATE orders SET status = ?, delivery_date = NOW() WHERE id = ?");
        } else {
            $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
        }
        
        $result = $stmt->execute([$newStatus, $orderId]);
        
        if ($result) {
            // Log status change in history
            $stmt = $pdo->prepare("INSERT INTO order_status_history (order_id, status, notes, updated_by) 
                                  VALUES (?, ?, ?, ?)");
            $stmt->execute([$orderId, $newStatus, $notes, $updatedBy]);
            
            $pdo->commit();
            return true;
        } else {
            $pdo->rollback();
            return false;
        }
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Order status update error: " . $e->getMessage());
        return false;
    }
}

// Function to get order status history
function getOrderStatusHistory($orderId) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT osh.*, u.username as updated_by_name
                          FROM order_status_history osh
                          LEFT JOIN users u ON osh.updated_by = u.id
                          WHERE osh.order_id = ?
                          ORDER BY osh.status_date ASC");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle order cancellation (FIXED VERSION)
if (isset($_POST['cancel_order'])) {
    $orderId = intval($_POST['order_id']);
    $cancellationReason = trim($_POST['cancellation_reason'] ?? '');
    
    if (empty($cancellationReason)) {
        $cancelMessage = "<div class='alert alert-error'>Please provide a reason for cancellation.</div>";
    } else {
        // Verify that this order belongs to the logged-in user
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($order && $order['status'] === 'pending') {
            try {
                $pdo->beginTransaction();
                // Ensure status history table has proper AUTO_INCREMENT primary key
                if (function_exists('ensureAutoIncrementPrimary')) {
                    ensureAutoIncrementPrimary('order_status_history');
                }
                
                // Get order items for email
                $stmt = $pdo->prepare("SELECT GROUP_CONCAT(CONCAT(p.name, ' (x', oi.quantity, ')') SEPARATOR ', ') as items
                                      FROM order_items oi 
                                      JOIN products p ON oi.product_id = p.id 
                                      WHERE oi.order_id = ?");
                $stmt->execute([$orderId]);
                $orderItemsText = $stmt->fetchColumn();
                
                // Update order status and save cancellation reason
                $stmt = $pdo->prepare("UPDATE orders SET status = 'cancelled', cancellation_reason = ?, cancelled_at = NOW() WHERE id = ?");
                $result = $stmt->execute([$cancellationReason, $orderId]);
                
                if ($result && $stmt->rowCount() > 0) {
                    // Restore product stock
                    $stmt = $pdo->prepare("SELECT oi.product_id, oi.quantity FROM order_items oi WHERE oi.order_id = ?");
                    $stmt->execute([$orderId]);
                    $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($orderItems as $item) {
                        $stmt = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?");
                        $stmt->execute([$item['quantity'], $item['product_id']]);
                    }
                    
                    // Log the cancellation
                    try {
                        $stmt = $pdo->prepare("INSERT INTO order_status_history (order_id, status, notes, updated_by) 
                                              VALUES (?, 'cancelled', ?, ?)");
                        $stmt->execute([$orderId, 'Cancelled by customer. Reason: ' . $cancellationReason, $userId]);
                    } catch (PDOException $e) {
                        error_log("Order status history insert failed: " . $e->getMessage());
                    }
                    
                   $pdo->commit();
                    
                    // Get user info for email
                    $stmt = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Send cancellation email to admin
                    if ($userInfo) {
                        $customerName = $userInfo['first_name'] . ' ' . $userInfo['last_name'];
                        $customerEmail = $userInfo['email'];
                        sendCancellationEmail($orderId, $customerName, $customerEmail, $cancellationReason, $order['total_amount'], $orderItemsText);
                    }
                    $cancelMessage = "<div class='alert alert-success'>
                    <strong>Order Cancelled Successfully!</strong><br>
                    Order #" . str_pad($orderId, 6, '0', STR_PAD_LEFT) . " has been cancelled.<br>
                    The seller has been notified. If you made a payment, the refund will be processed within 3-5 business days.
                  </div>";
                } else {
                    $pdo->rollback();
                    $cancelMessage = "<div class='alert alert-error'>Error cancelling order. Please try again.</div>";
                }
            } catch (Exception $e) {
                $pdo->rollback();
                error_log("Order cancellation error: " . $e->getMessage());
                $cancelMessage = "<div class='alert alert-error'>Database error occurred: " . $e->getMessage() . "</div>";
            }
        } else {
            $cancelMessage = "<div class='alert alert-error'>Order cannot be cancelled at this time.</div>";
        }
    }
}


function checkDatabaseStructure($pdo) {
    try {
        // Check if order_status_history table exists
        $stmt = $pdo->prepare("DESCRIBE order_status_history");
        $stmt->execute();
        echo "<!-- order_status_history table exists -->";
    } catch (PDOException $e) {
        echo "<!-- order_status_history table missing: " . $e->getMessage() . " -->";
    }
    
    try {
        // Check orders table structure
        $stmt = $pdo->prepare("DESCRIBE orders");
        $result = $stmt->execute();
        if ($result) {
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo "<!-- Orders table columns: " . implode(', ', array_column($columns, 'Field')) . " -->";
        }
    } catch (PDOException $e) {
        echo "<!-- Error checking orders table: " . $e->getMessage() . " -->";
    }
}


// Get orders by status - NOW THESE FUNCTIONS WILL WORK
$pendingOrders = getOrdersByStatus('pending');
$processingOrders = getOrdersByStatus('processing');
$shippedOrders = getOrdersByStatus('shipped');
$cancelledOrders = getOrdersByStatus('cancelled');
$orders = getUserOrders();
$deliveredOrders = getDeliveredOrders();

// Get user info
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if user exists
if (!$user) {
    // User doesn't exist, redirect to login
    header('Location: login.php?error=user_not_found');
    exit;
}
?>

<style>
/* Dashboard Styles */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

/* Hover for filter tabs */
.filter-tab:hover {
    background: #FFD736 !important;
    color: #130325 !important;
    border-color: #FFD736 !important;
}

/* Dark background for user dashboard page */
body {
    background-color: #130325 !important;
}

/* Removed body background override to match header styling */

/* Alert Styles */
.alert {
    padding: 15px 20px;
    margin: 20px auto;
    max-width: 1200px;
    border-radius: 10px;
    font-size: 1rem;
    border: none;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.alert-success {
    background: linear-gradient(135deg, #d4edda, #c3e6cb);
    color: #155724;
    border-left: 5px solid #28a745;
}

.alert-error {
    background: linear-gradient(135deg, #f8d7da, #f1b0b7);
    color: #721c24;
    border-left: 5px solid #dc3545;
}

.alert-warning {
    background: linear-gradient(135deg, #fff3cd, #ffeaa7);
    color: #856404;
    border-left: 5px solid #ffc107;
}

/* Main heading */
h1 {
    color: var(--primary-dark);
    text-align: center;
    margin: 30px 0;
    font-size: 2.5rem;
    font-weight: 600;
    text-shadow: 0 2px 4px var(--shadow-light);
}

/* My Orders title should be white on dark background */
.page-title {
    color: #ffffff !important;
}

/* Container for the entire dashboard */
.dashboard-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
    display: grid;
    grid-template-columns: 1fr;
    gap: 30px;
    align-items: start;
}

/* Full width container for delivered products */
.dashboard-full-width {
    max-width: 1400px;
    margin: 30px auto 0;
    padding: 0 20px;
}

/* User info section */
.user-info {
    background: var(--gradient-primary);
    position: relative;
    overflow: hidden;
    padding: 25px;
    border-radius: 15px;
    box-shadow: var(--shadow-soft);
    height: fit-content;
    max-height: 400px;
}

.user-info::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255,255,255,0.1);
    border-radius: 15px;
    pointer-events: none;
}

.user-info h2 {
    font-size: 1.6rem;
    font-weight: 600;
    margin-bottom: 20px;
}

.user-info p {
    font-size: 1rem;
    margin: 12px 0;
    padding: 6px 10px;
}

.user-info p strong {
    display: inline-block;
    width: 80px;
    font-weight: 600;
}

.user-info a {
    display: inline-block;
    margin-top: 20px;
    padding: 12px 25px;
    background: var(--accent-yellow);
    color: var(--primary-dark);
    text-decoration: none;
    border-radius: var(--radius-full);
    font-weight: 600;
    transition: var(--transition-normal);
    border: 2px solid var(--accent-yellow);
    position: relative;
    z-index: 1;
}

.user-info a:hover {
    background: #e6c230;
    border-color: #e6c230;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px var(--shadow-medium);
}

/* Orders section */
.user-orders {
    background: rgba(255,255,255,0.9);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255,255,255,0.2);
    padding: 25px;
    border-radius: 15px;
    box-shadow: var(--shadow-soft);
    height: fit-content;
    max-height: 600px;
    overflow-y: auto;
}

.user-orders h2 {
    font-size: 1.6rem;
    margin-bottom: 20px;
    text-transform: uppercase;
    color: #130325;
}

.user-orders p {
    color: var(--text-muted);
    font-size: 1.1rem;
    text-align: center;
    margin-top: 40px;
    font-style: italic;
}

/* Order Card Styles */
.order-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border: 1px solid #e9ecef;
    transition: all 0.3s ease;
}

.order-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.15);
}

.order-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f0f0f0;
}

.order-number {
    font-size: 1.2rem;
    font-weight: 700;
    color: #2c3e50;
}

.order-date {
    color: #6c757d;
    font-size: 0.9rem;
}

.order-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 15px;
    margin-bottom: 15px;
}

.detail-item {
    text-align: center;
    padding: 12px;
    background: #f8f9fa;
    border-radius: 8px;
    transition: all 0.2s ease;
}

.detail-item:hover {
    background: #e9ecef;
}

.detail-label {
    font-size: 0.8rem;
    color: #6c757d;
    margin-bottom: 5px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 500;
}

.detail-value {
    font-size: 1rem;
    font-weight: 600;
    color: #2c3e50;
}

.order-status {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.order-status.pending {
    background: #fff3cd;
    color: #856404;
}

.order-status.processing {
    background: #d1ecf1;
    color: #0c5460;
}

.order-status.shipped {
    background: #d4edda;
    color: #155724;
}

.order-status.delivered {
    background: #d4edda;
    color: #155724;
}

.order-status.cancelled {
    background: #f8d7da;
    color: #721c24;
}

.order-items {
    background: #f8f9fa;
    padding: 12px;
    border-radius: 8px;
    margin: 15px 0;
    font-size: 0.95rem;
    color: #495057;
}

.delivery-info {
    background: linear-gradient(135deg, #d4edda, #c3e6cb);
    padding: 12px;
    border-radius: 8px;
    margin: 15px 0;
    color: #155724;
    font-weight: 500;
}

.order-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 15px;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 0.9rem;
    text-align: center;
    display: inline-block;
}

.btn-primary {
    background: #007bff;
    color: white;
}

.btn-primary:hover {
    background: #0056b3;
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0, 123, 255, 0.3);
}

.btn-danger {
    background: #dc3545;
    color: white;
}

.btn-danger:hover {
    background: #c82333;
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(220, 53, 69, 0.3);
}

/* Status Grid Styles */
.order-status-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    margin-bottom: 40px;
}

.status-container {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    overflow: hidden;
}

.status-container h3 {
    padding: 15px 20px;
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    border-bottom: 2px solid #f0f0f0;
}

.status-container.pending h3 {
    background: #fff3cd;
    color: #856404;
    border-bottom-color: #ffeaa7;
}

.status-container.processing h3 {
    background: #d1ecf1;
    color: #0c5460;
    border-bottom-color: #bee5eb;
}

.status-container.shipped h3 {
    background: #d4edda;
    color: #155724;
    border-bottom-color: #c3e6cb;
}

.status-container.cancelled h3 {
    background: #f8d7da;
    color: #721c24;
    border-bottom-color: #f1b0b7;
}

.status-items {
    max-height: 400px;
    overflow-y: auto;
    padding: 0;
}

.status-item {
    padding: 15px 20px;
    border-bottom: 1px solid #f0f0f0;
    transition: background-color 0.2s ease, color 0.2s ease;
}

.status-item:hover {
    background-color: #2d1b4e;
    color: #FFD736;
}

.status-item:last-child {
    border-bottom: none;
}

.status-item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}
#cancellation_reason:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
}

#cancellation_reason::placeholder {
    color: #999;
}
.order-id {
    font-weight: 600;
    color: #2c3e50;
}

.status-item-details {
    margin-bottom: 10px;
}

.order-items {
    font-size: 0.9rem;
    color: #444;
    margin-bottom: 8px;
}

.order-meta {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.order-meta span {
    font-size: 0.8rem;
    color: #666;
}

.status-item-total {
    font-weight: 600;
    color: #27ae60;
    font-size: 0.95rem;
}

.empty-status {
    padding: 30px 20px;
    text-align: center;
    color: #999;
    font-style: italic;
}

/* Delivered Products Section */
.delivered-products {
    background: #ffffff;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    overflow: hidden;
    margin: 20px 0; /* balanced spacing */
}

.delivered-products h2 {
    padding: 16px 20px;
    margin: 0;
    background: #130325;
    color: #ffffff;
    border-bottom: 1px solid #2d1b4e; /* remove yellow border */
    font-size: 1.3rem;
    text-transform: uppercase;
}

.delivered-products-scroll {
    max-height: 500px;
    overflow-y: auto;
    padding: 0;
}

.delivered-product-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 25px;
    border-bottom: 1px solid #f0f0f0;
    transition: background-color 0.2s ease;
}

.delivered-product-item:hover {
    background-color: #f8f9fa;
}

.delivered-product-item:last-child {
    border-bottom: none;
}

.product-info {
    flex: 1;
}

.product-name {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 8px;
}

.product-details {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 8px;
}

.product-details span {
    font-size: 0.9rem;
    color: #666;
}

.delivery-date {
    color: #27ae60 !important;
    font-weight: 500;
}

.delivery-info {
    color: #27ae60;
    font-size: 0.9rem;
    margin-top: 5px;
}

.review-action {
    margin-left: 20px;
}

.btn-review {
    background: #130325;
    color: #FFD736;
    padding: 10px 20px;
    border: none;
    border-radius: 5px;
    text-decoration: none;
    font-size: 0.9rem;
    font-weight: 500;
    transition: background-color 0.2s ease;
}

.btn-review:hover {
    background: #2d1b4e;
    text-decoration: none;
    color: #FFD736;
}

.no-delivered-products {
    padding: 40px 20px;
    text-align: center;
    color: #666;
    font-style: italic;
}

.no-orders {
    text-align: center;
    padding: 40px 20px;
    color: #666;
}

.no-orders p {
    font-size: 1.1rem;
    margin-bottom: 20px;
    font-style: italic;
}

/* Cancel Modal */
.cancel-modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(5px);
}

.cancel-modal-content {
    background: white;
    margin: 10% auto;
    padding: 30px;
    border-radius: 15px;
    width: 90%;
    max-width: 500px;
    position: relative;
    animation: modalSlideIn 0.3s ease;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-50px) scale(0.9);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.close-modal {
    position: absolute;
    right: 15px;
    top: 15px;
    background: none;
    border: none;
    font-size: 2rem;
    cursor: pointer;
    color: #ccc;
    transition: all 0.2s ease;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}

.close-modal:hover {
    color: #e74c3c;
    background: #f8f9fa;
}

.cancel-modal-content h3 {
    font-size: 1.5rem;
    margin-bottom: 15px;
    color: #2c3e50;
}

.cancel-modal-content p {
    margin-bottom: 15px;
    color: #495057;
}

.cancel-modal-buttons {
    display: flex;
    gap: 15px;
    justify-content: center;
    margin-top: 25px;
}

/* Responsive Design */
@media (max-width: 768px) {
    .dashboard-container {
        grid-template-columns: 1fr;
        gap: 20px;
        padding: 15px;
    }
    
    .dashboard-full-width {
        padding: 0 15px;
    }
    
    .order-status-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .delivered-product-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .review-action {
        margin-left: 0;
        width: 100%;
    }
    
    .btn-review {
        width: 100%;
        text-align: center;
    }
    
    .product-details {
        flex-direction: column;
        gap: 5px;
    }
    
    .order-details {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
    
    .order-actions {
        flex-direction: column;
    }
    
    .cancel-modal-content {
        margin: 20% auto;
        padding: 25px;
    }
    
    .cancel-modal-buttons {
        flex-direction: column;
    }
}

/* Custom Scrollbar Styling */
.status-items::-webkit-scrollbar,
.delivered-products-scroll::-webkit-scrollbar,
.user-orders::-webkit-scrollbar {
    width: 6px;
}

.status-items::-webkit-scrollbar-track,
.delivered-products-scroll::-webkit-scrollbar-track,
.user-orders::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.status-items::-webkit-scrollbar-thumb,
.delivered-products-scroll::-webkit-scrollbar-thumb,
.user-orders::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.status-items::-webkit-scrollbar-thumb:hover,
.delivered-products-scroll::-webkit-scrollbar-thumb:hover,
.user-orders::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

/* CSS Variables */
:root {
    --gradient-primary: linear-gradient(135deg, rgba(19, 3, 37, 0.9) 0%, #130325  100%);
    --primary-dark: #130325;
    --primary-light: #ffffff;
    --accent-yellow: #ffd700;
    --radius-full: 25px;
    --shadow-soft: 0 10px 40px rgba(0,0,0,0.1);
    --shadow-medium: 0 5px 15px rgba(0,0,0,0.2);
    --transition-normal: all 0.3s ease;
    --text-muted: #6c757d;
}

/* Animation for fade in */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.user-info,
.user-orders,
.delivered-products {
    animation: fadeInUp 0.6s ease-out;
}

.user-orders {
    animation-delay: 0.2s;
}

.delivered-products {
    animation-delay: 0.4s;
}
</style>

<script>
// Auto-dismiss alert notifications after 4 seconds
document.addEventListener('DOMContentLoaded', function() {
    // Find all alert messages
    const alerts = document.querySelectorAll('.alert, .alert-success, .alert-error, .alert-warning, .success-message, .error-message');
    
    alerts.forEach(function(alert) {
        // Add fade-out animation styles if not already present
        if (!alert.style.transition) {
            alert.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        }
        
        // Set timer to fade out after 4 seconds
        setTimeout(function() {
            // Add fade-out effect
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-20px)';
            
            // Remove element from DOM after animation completes
            setTimeout(function() {
                alert.remove();
            }, 500); // Wait for fade animation to complete
        }, 4000); // 4 seconds delay
    });
    
    // Optional: Add click-to-dismiss functionality
    alerts.forEach(function(alert) {
        alert.style.cursor = 'pointer';
        alert.title = 'Click to dismiss';
        
        alert.addEventListener('click', function() {
            this.style.opacity = '0';
            this.style.transform = 'translateY(-20px)';
            
            setTimeout(() => {
                this.remove();
            }, 500);
        });
    });
});
    function confirmCancelOrder(orderId) {
    // Minimal confirmation for production
    openConfirm('Are you sure you want to cancel order #' + orderId + '?', function(){
        const form = document.querySelector('form[action*="cancel_order"][data-order-id="' + orderId + '"]');
        if (form) form.submit();
    });
    return false;
}
// Order Management JavaScript (FIXED VERSION)
class OrderManager {
    constructor() {
        this.init();
    }

    init() {
        this.setupEventListeners();
    }
    setupEventListeners() {
        // Cancel order modal events - FIXED
        document.addEventListener('click', (e) => {
            // Handle cancel button clicks from both order history and status grid
            if (e.target.classList.contains('cancel-order-btn') || e.target.classList.contains('btn-cancel-modal')) {
                e.preventDefault();
                const orderId = e.target.dataset.orderId || e.target.getAttribute('data-order-id');
                const orderNumber = e.target.dataset.orderNumber || e.target.getAttribute('data-order-number');
                this.showCancelModal(orderId, orderNumber);
            }

            // Handle close modal clicks
            if (e.target.classList.contains('close-modal') || e.target === document.getElementById('cancelModal')) {
                this.closeCancelModal();
            }

            // Handle direct cancel form submissions from status grid
            if (e.target.type === 'submit' && e.target.textContent.includes('Cancel Order')) {
                openConfirm('Are you sure you want to cancel this order? This action cannot be undone.', function(){
                    // proceed
                    e.target.closest('form')?.submit();
                });
                e.preventDefault();
            }
        });

        // Keyboard events
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeCancelModal();
            }
        });
    }

    showCancelModal(orderId, orderNumber) {
        const cancelOrderId = document.getElementById('cancelOrderId');
        const cancelOrderNumber = document.getElementById('cancelOrderNumber');
        const cancelModal = document.getElementById('cancelModal');
        
        if (cancelOrderId && cancelOrderNumber && cancelModal) {
            cancelOrderId.value = orderId;
            cancelOrderNumber.textContent = orderNumber;
            cancelModal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
    }

    closeCancelModal() {
        const cancelModal = document.getElementById('cancelModal');
        if (cancelModal) {
            cancelModal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    new OrderManager();
});
</script>

<h1 class="page-title">My Orders</h1>

<!-- Display cancel message if exists -->
<?php if (isset($cancelMessage)): ?>
    <?php echo $cancelMessage; ?>
<?php endif; ?>

<div class="dashboard-container">
   <!-- Profile panel temporarily hidden; moved to Edit Profile page -->
   <div class="user-info" style="display:none"></div>

   <div class="user-orders">
    <h2>Order History</h2>

    <!-- Status filter tabs -->
    <div class="order-filters" style="margin: 10px 0 20px 0; display:flex; gap:10px; flex-wrap:wrap;">
        <?php
        $statuses = ['all' => 'All', 'pending' => 'Pending', 'processing' => 'Processing', 'shipped' => 'Shipped', 'to receive' => 'To Receive', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'];
        $activeStatus = isset($_GET['status']) ? strtolower($_GET['status']) : 'all';
        foreach ($statuses as $key => $label) {
            $isActive = ($activeStatus === $key) ? ' style="background:#FFD736;color:#130325;border:1px solid #FFD736;"' : '';
            echo '<a href="user-dashboard.php?status=' . urlencode($key) . '" class="btn filter-tab" style="padding:6px 12px;border:1px solid #ddd;border-radius:16px;color:#130325;background:#fff;text-decoration:none;font-weight:800;text-transform:uppercase;"' . $isActive . '>' . htmlspecialchars($label) . '</a>';
        }
        ?>
    </div>
    
    <?php if (empty($orders)): ?>
        <div class="no-orders">
            <p>You haven't placed any orders yet.</p>
            <a href="products.php" class="btn btn-primary">Start Shopping</a>
        </div>
    <?php else: ?>
        <?php
        $filter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';
        foreach ($orders as $order):
            $orderStatusKey = strtolower($order['status']);
            if ($filter !== 'all' && $filter !== $orderStatusKey) {
                continue;
            }
        ?>
            <div class="order-card" data-order-id="<?php echo $order['id']; ?>">
                <div class="order-header">
                    <div class="order-number">Order #<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?></div>
                    <div class="order-date"><?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?></div>
                </div>
                
                <div class="order-body">
                    <div class="order-details">
                        <div class="detail-item">
                            <div class="detail-label">Total Amount</div>
                            <div class="detail-value">$<?php echo number_format((float)$order['total_amount'], 2); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Status</div>
                            <div class="detail-value">
                                <span class="order-status <?php echo strtolower($order['status']); ?>">
                                    <?php echo ucfirst($order['status']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Order Date</div>
                            <div class="detail-value"><?php echo date('M j, Y', strtotime($order['created_at'])); ?></div>
                        </div>
                        <?php if ($order['status'] === 'delivered' && !empty($order['delivery_date'])): ?>
                            <div class="detail-item">
                                <div class="detail-label">Delivery Date</div>
                                <div class="detail-value delivery-date">
                                    <?php echo date('M j, Y g:i A', strtotime($order['delivery_date'])); ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="detail-item">
                                <div class="detail-label">Expected Delivery</div>
                                <div class="detail-value">
                                    <?php 
                                    $expectedDelivery = date('M j', strtotime($order['created_at'] . ' +5 days')) . '-' . 
                                                       date('j, Y', strtotime($order['created_at'] . ' +7 days'));
                                    echo $expectedDelivery; 
                                    ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="order-items">
                        <strong>Items:</strong>
                        <div class="order-items-list" style="margin-top: 10px;">
                            <?php if (!empty($order['order_items'])): ?>
                                <?php foreach ($order['order_items'] as $item): ?>
                                    <div class="order-item-row" style="display: flex; align-items: center; margin-bottom: 8px; padding: 8px; background: rgba(255, 255, 255, 0.1); border-radius: 6px;">
                                        <img src="<?php echo htmlspecialchars($item['image_url']); ?>" 
                                             alt="<?php echo htmlspecialchars($item['product_name']); ?>" 
                                             style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px; margin-right: 12px; border: 1px solid rgba(255, 215, 54, 0.3);">
                                        <div style="flex: 1;">
                                            <div style="color: #F9F9F9; font-weight: 600; font-size: 14px;"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                            <div style="color: #F9F9F9; opacity: 0.8; font-size: 12px;">Qty: <?php echo $item['quantity']; ?> × ₱<?php echo number_format($item['price'], 2); ?></div>
                                        </div>
                                        <div style="color: #FFD736; font-weight: 600; font-size: 14px;">₱<?php echo number_format($item['quantity'] * $item['price'], 2); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="color: #F9F9F9; opacity: 0.8; font-style: italic;">No items found</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if ($order['status'] === 'delivered' && !empty($order['delivery_date'])): ?>
                        <div class="delivery-info">
                            <strong>Delivered on <?php echo date('F j, Y \a\t g:i A', strtotime($order['delivery_date'])); ?></strong><br>
                            <small>Your order has been successfully delivered. You can now leave reviews for the products.</small>
                        </div>
                    <?php endif; ?>
                    
                    <div class="order-actions">
                        <a href="order-confirmation.php?id=<?php echo $order['id']; ?>" class="btn btn-primary">View Details</a>
                        
                        <?php if ($order['status'] === 'delivered'): ?>
                            <span class="btn" style="background: #28a745; color: white; cursor: default;">Order Delivered</span>
                        <?php elseif (canCustomerCancelOrder($order)): ?>
                            <button type="button" 
                                    class="btn btn-danger btn-cancel-modal" 
                                    data-order-id="<?php echo $order['id']; ?>"
                                    data-order-number="#<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?>">
                                Cancel Order
                            </button>
                        <?php elseif ($order['status'] === 'cancelled'): ?>
                            <span class="btn" style="background: #6c757d; color: white; cursor: default;">Order Cancelled</span>
                        <?php else: ?>
                            <span class="btn" style="background: #17a2b8; color: white; cursor: default;">
                                <?php echo ucfirst($order['status']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</div>

<!-- Delivered Products - Add Reviews Section -->
    <div class="delivered-products">
        <h2>Delivered Products - Add Reviews</h2>
        <div class="delivered-products-scroll">
            <?php if (empty($deliveredOrders)): ?>
                <div class="no-delivered-products">
                    <p>No delivered products yet. Complete an order to see products here for review.</p>
                </div>
            <?php else: ?>
                <?php foreach ($deliveredOrders as $deliveredItem): ?>
                    <div class="delivered-product-item">
<<<<<<< HEAD
                        <div class="product-info" style="display: flex; align-items: flex-start; gap: 15px;">
                            <img src="<?php echo htmlspecialchars($deliveredItem['image_url']); ?>" 
                                 alt="<?php echo htmlspecialchars($deliveredItem['product_name']); ?>" 
                                 style="width: 60px; height: 60px; object-fit: cover; border-radius: 6px; border: 1px solid rgba(255, 215, 54, 0.3); flex-shrink: 0;">
                            <div style="flex: 1;">
                                <div class="product-name" style="font-size: 16px; font-weight: 600; color: #F9F9F9; margin-bottom: 8px;"><?php echo htmlspecialchars($deliveredItem['product_name']); ?></div>
                                <div class="product-details" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; font-size: 14px;">
                                    <span style="color: #F9F9F9;"><strong>Quantity:</strong> <?php echo (int)$deliveredItem['quantity']; ?></span>
                                    <span style="color: #F9F9F9;"><strong>Price:</strong> ₱<?php echo number_format((float)$deliveredItem['price'], 2); ?></span>
                                    <span style="color: #FFD736;"><strong>Total:</strong> ₱<?php echo number_format((float)$deliveredItem['item_total'], 2); ?></span>
                                    <span style="color: #F9F9F9;"><strong>Order Date:</strong> <?php echo date('M j, Y g:i A', strtotime($deliveredItem['order_date'])); ?></span>
                                    <?php if (!empty($deliveredItem['delivery_date'])): ?>
                                        <span style="color: #F9F9F9;"><strong>Delivered:</strong> 
                                            <span class="delivery-date" style="color: #28a745;">
                                                <?php echo date('M j, Y g:i A', strtotime($deliveredItem['delivery_date'])); ?>
                                            </span>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
=======
                        <div class="product-info">
                            <div class="product-name"><?php echo htmlspecialchars($deliveredItem['product_name']); ?></div>
                            <div class="product-details">
                                <span><strong>Quantity:</strong> <?php echo (int)$deliveredItem['quantity']; ?></span>
                                <span><strong>Price:</strong> $<?php echo number_format((float)$deliveredItem['price'], 2); ?></span>
                                <span><strong>Total:</strong> $<?php echo number_format((float)$deliveredItem['item_total'], 2); ?></span>
                                <span><strong>Order Date:</strong> <?php echo date('M j, Y g:i A', strtotime($deliveredItem['order_date'])); ?></span>
                                <?php if (!empty($deliveredItem['delivery_date'])): ?>
                                    <span><strong>Delivered:</strong> 
                                        <span class="delivery-date">
                                            <?php echo date('M j, Y g:i A', strtotime($deliveredItem['delivery_date'])); ?>
                                        </span>
                                    </span>
                                <?php endif; ?>
                            </div>
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
                            <?php if (!empty($deliveredItem['delivery_date'])): ?>
                                <?php
                                $deliveryTime = strtotime($deliveredItem['delivery_date']);
                                $currentTime = time();
                                $daysDiff = floor(($currentTime - $deliveryTime) / (60 * 60 * 24));
                                ?>
                                <div class="delivery-info">
                                    <strong>Delivered <?php echo $daysDiff > 0 ? $daysDiff . ' day' . ($daysDiff > 1 ? 's' : '') . ' ago' : 'today'; ?></strong> - Ready for review
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="review-action">
                            <a href="product-detail.php?id=<?php echo $deliveredItem['product_id']; ?>" class="btn btn-review">Add Review</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Cancel Order Modal -->
<div id="cancelModal" class="cancel-modal">
    <div class="cancel-modal-content">
        <button class="close-modal" type="button">&times;</button>
        <h3>Cancel Order</h3>
        <p>Please tell us why you're cancelling order <strong id="cancelOrderNumber">#000000</strong></p>
        
        <form method="POST" action="" style="margin: 20px 0;">
            <input type="hidden" name="order_id" id="cancelOrderId" value="">
            <input type="hidden" name="cancel_order" value="1">
            
            <div style="margin: 20px 0;">
                <label for="cancellation_reason" style="display: block; margin-bottom: 10px; font-weight: 600; color: #333;">
                    Reason for Cancellation: <span style="color: #dc3545;">*</span>
                </label>
                <textarea 
                    name="cancellation_reason" 
                    id="cancellation_reason" 
                    rows="4" 
                    required
                    placeholder="Please provide a detailed reason for cancelling your order..."
                    style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; font-family: inherit; resize: vertical;"
                ></textarea>
                <small style="color: #666; display: block; margin-top: 5px;">
                    This will help us improve our service
                </small>
            </div>
            
            <p style="color: #dc3545; font-weight: 600; margin: 20px 0; padding: 15px; background: #f8d7da; border-radius: 6px; border-left: 4px solid #dc3545;">
                ⚠️ This action cannot be undone. Once cancelled, you'll need to place a new order.
            </p>
            
            <div class="cancel-modal-buttons">
                <button type="button" class="btn btn-primary close-modal">Keep Order</button>
                <button type="submit" class="btn btn-danger">Submit & Cancel Order</button>
            </div>
        </form>
    </div>
</div>