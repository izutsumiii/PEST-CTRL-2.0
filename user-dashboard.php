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
                            <p style='margin: 10px 0;'><strong>Order Total:</strong> ₱" . number_format($orderTotal, 2) . "</p>
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
    background: #1a0a2e;
    border: 1px solid #2d1b4e;
    padding: 22px;
    border-radius: 14px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.35);
    height: fit-content;
    max-height: 600px;
    overflow-y: auto;
}

.user-orders h2 {
    color: #F9F9F9;
    font-size: 1.4rem;
    font-weight: 800;
    margin-bottom: 14px;
    border-bottom: 2px solid #FFD736;
    padding-bottom: 8px;
}

.user-orders p { color:#d7d1e2; font-size: .98rem; text-align:center; margin-top: 40px; font-style: italic; }

/* Order Card Styles */
.order-card { background:#ffffff; border-radius:12px; padding:10px; margin-bottom:10px; box-shadow: 0 8px 22px rgba(0,0,0,0.35); border:2px solid #FFD736; transition: all 0.3s ease; }

.order-card:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(0,0,0,0.4); }

.order-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; padding-bottom:8px; border-bottom: 1px solid rgba(255,255,255,0.08); }

/* New Clean Order Layout */
.order-summary {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
    padding: 6px 0;
}

.summary-left {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.order-total {
    font-size: 1.3rem;
    font-weight: 700;
    color: #FFD736;
}

.summary-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
}

.delivery-info-small {
    font-size: 0.8rem;
    color: #28a745;
    font-weight: 500;
}

.order-items-compact {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 8px;
    padding: 4px 0;
}

.item-compact {
    display: flex;
    align-items: flex-start;
    gap: 15px;
    background: rgba(255, 255, 255, 0.05);
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid rgba(255, 215, 54, 0.2);
    width: 100%;
}

.item-thumb {
    width: 80px;
    height: 80px;
    object-fit: cover;
    border-radius: 8px;
    border: 2px solid #130325;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    flex-shrink: 0;
}

.item-details {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
}

.item-name {
    color: #130325;
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.3;
    margin-bottom: 4px;
}

.item-qty {
    color: #130325;
    font-size: 0.85rem;
    font-weight: 600;
}

.item-price {
    color: #130325;
    font-size: 1.1rem;
    font-weight: 700;
    margin-left: auto;
    align-self: flex-start;
}

.more-items {
    color: #130325;
    opacity: 0.7;
    font-size: 0.8rem;
    font-style: italic;
    padding: 6px 10px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 8px;
    border: 1px solid rgba(255, 215, 54, 0.2);
}

.order-number { 
    font-size: 1.05rem; 
    font-weight: 800; 
    color: #130325;
}

.order-status-header {
    display: flex;
    align-items: center;
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

.order-status { padding:6px 12px; border-radius:999px; font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.5px; border:1px solid transparent; }

.order-status.pending { background: rgba(255,193,7,0.2); color:#ffc107; border-color:#ffc107; }

.order-status.processing { background: rgba(0,123,255,0.2); color:#007bff; border-color:#007bff; }

.order-status.shipped { background: rgba(23,162,184,0.2); color:#17a2b8; border-color:#17a2b8; }

.order-status.delivered { background: rgba(40,167,69,0.2); color:#28a745; border-color:#28a745; }

.order-status.cancelled { background: rgba(220,53,69,0.2); color:#dc3545; border-color:#dc3545; }

.order-items {
    background: #120722;
    padding: 12px;
    border-radius: 8px;
    margin: 15px 0;
    font-size: 0.95rem;
    color: #e6e1ee;
    border: 1px solid #2d1b4e;
}
.order-items strong { color: #F9F9F9; }

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
                    <div class="order-status-header">
                        <span class="order-status <?php echo strtolower($order['status']); ?>">
                            <?php echo ucfirst($order['status']); ?>
                        </span>
                    </div>
                </div>
                
                <div class="order-body">
                    
                    <div class="order-items-compact">
                        <?php if (!empty($order['order_items'])): ?>
                            <?php 
                            $itemCount = count($order['order_items']);
                            $displayItems = array_slice($order['order_items'], 0, 2);
                            ?>
                            <?php foreach ($displayItems as $item): ?>
                                <div class="item-compact">
                                    <img src="<?php echo htmlspecialchars($item['image_url']); ?>" 
                                         alt="<?php echo htmlspecialchars($item['product_name']); ?>" 
                                         class="item-thumb">
                                    <div class="item-details">
                                        <div class="item-name"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                        <div class="item-qty">Qty: <?php echo $item['quantity']; ?></div>
                                    </div>
                                    <div class="item-price">₱<?php echo number_format($item['quantity'] * $item['price'], 2); ?></div>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($itemCount > 2): ?>
                                <div class="more-items">+<?php echo ($itemCount - 2); ?> more items</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($order['status'] === 'delivered' && !empty($order['delivery_date'])): ?>
                        <div class="delivery-info">
                            <strong>Delivered on <?php echo date('F j, Y \a\t g:i A', strtotime($order['delivery_date'])); ?></strong><br>
                            <small>Your order has been successfully delivered. You can now leave reviews for the products.</small>
                        </div>
                    <?php endif; ?>
                    
                    <div class="order-actions">
                        <a href="order-confirmation.php?id=<?php echo $order['id']; ?>" class="btn btn-primary">View Details</a>
                        
                        <?php if ($order['status'] === 'cancelled'): ?>
                            <!-- No action button for cancelled orders -->
                        <?php elseif (canCustomerCancelOrder($order)): ?>
                            <button type="button" 
                                    class="btn btn-danger btn-cancel-modal" 
                                    data-order-id="<?php echo $order['id']; ?>"
                                    data-order-number="#<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?>">
                                Cancel Order
                            </button>
                        <?php else: ?>
                            <a href="customer-returns.php" class="btn" style="background: #6c757d; color: white; text-decoration: none;">
                                Return/Refund
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Delivered Products - Add Reviews Section (Restored & Restyled) -->
<style>
.delivered-reviews-wrap { max-width: 1600px; margin: 20px auto 10px auto; padding: 0 20px; }
.delivered-card { background:#1a0a2e; border:1px solid #2d1b4e; border-radius:14px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.35); }
.delivered-card .header { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid rgba(255,215,54,0.25); }
.delivered-card .title { color:#F9F9F9; font-size:18px; font-weight:800; letter-spacing:.3px; }
.delivered-card .subtitle { color:#d7d1e2; font-size:13px; opacity:.9; }
.delivered-list { max-height: 460px; overflow-y:auto; }
.delivered-item { display:flex; gap:16px; align-items:flex-start; padding:14px 20px; border-bottom:1px solid rgba(255,255,255,0.08); }
.delivered-item:last-child { border-bottom:none; }
.delivered-thumb { width:60px; height:60px; border-radius:8px; border:1px solid rgba(255,215,54,0.35); object-fit:cover; background:#0e0620; flex-shrink:0; }
.delivered-body { flex:1; min-width:0; }
.delivered-name { color:#F9F9F9; font-weight:700; margin:0 0 6px 0; font-size:15px; }
.delivered-meta { display:flex; gap:14px; flex-wrap:wrap; color:#d7d1e2; font-size:12.5px; margin-bottom:6px; }
.delivered-meta .price { color:#FFD736; font-weight:800; }
.delivered-meta .date { color:#bfb8cb; }
.review-cta { display:flex; align-items:center; gap:10px; }
.btn-review { background:linear-gradient(135deg,#FFD736,#f0c419); color:#130325; border:2px solid #FFD736; padding:10px 16px; border-radius:10px; font-weight:800; font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:8px; transition: all 0.3s ease; }
.btn-review:hover { background:linear-gradient(135deg,#e6c230,#d4b017); border-color:#e6c230; transform:translateY(-2px); box-shadow:0 8px 20px rgba(255,215,54,0.5); color:#130325; text-decoration:none; }
.badge-reviewed { display:inline-block; padding:6px 10px; border-radius:999px; border:1px solid #28a745; color:#28a745; background:rgba(40,167,69,0.15); font-weight:800; font-size:12px; }

.delivered-list::-webkit-scrollbar { width:8px; }
.delivered-list::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
.delivered-list::-webkit-scrollbar-thumb { background: linear-gradient(135deg,#FFD736,#f0c419); border-radius:6px; }

@media (max-width: 640px){
  .delivered-item { flex-direction:column; align-items:flex-start; }
  .review-cta { width:100%; }
}
</style>

<div class="delivered-reviews-wrap">
    <div class="delivered-card">
        <div class="header">
            <div>
                <div class="title">Delivered Products - Add Reviews</div>
                <div class="subtitle">Review your recently delivered items</div>
            </div>
        </div>
        <div class="delivered-list">
            <?php if (empty($deliveredOrders)): ?>
                <div class="delivered-item" style="justify-content:center;">
                    <div class="delivered-body" style="text-align:center; color:#d7d1e2;">No delivered products yet. Complete an order to see products here for review.</div>
                </div>
            <?php else: ?>
                <?php foreach ($deliveredOrders as $deliveredItem): ?>
                    <div class="delivered-item">
                        <img class="delivered-thumb" src="<?php echo htmlspecialchars($deliveredItem['image_url']); ?>" alt="<?php echo htmlspecialchars($deliveredItem['product_name']); ?>" onerror="this.src='assets/uploads/tempo_image.jpg'">
                        <div class="delivered-body">
                            <div class="delivered-name"><?php echo htmlspecialchars($deliveredItem['product_name']); ?></div>
                            <div class="delivered-meta">
                                <span>Qty: <?php echo (int)$deliveredItem['quantity']; ?></span>
                                <span class="price">₱<?php echo number_format((float)$deliveredItem['price'], 2); ?></span>
                                <span>Total: ₱<?php echo number_format((float)$deliveredItem['item_total'], 2); ?></span>
                                <?php if (!empty($deliveredItem['delivery_date'])): ?>
                                    <span class="date">Delivered: <?php echo date('M j, Y g:i A', strtotime($deliveredItem['delivery_date'])); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="review-cta">
                                <?php
                                $alreadyReviewed = false;
                                try {
                                    if (isset($_SESSION['user_id'])) {
                                        $rv = $pdo->prepare("SELECT 1 FROM product_reviews WHERE user_id = ? AND product_id = ? LIMIT 1");
                                        $rv->execute([$_SESSION['user_id'], $deliveredItem['product_id']]);
                                        $alreadyReviewed = (bool)$rv->fetchColumn();
                                    }
                                } catch (Exception $e) { $alreadyReviewed = false; }
                                ?>
                                <?php if ($alreadyReviewed): ?>
                                    <span class="badge-reviewed">Reviewed</span>
                                <?php else: ?>
                                    <a href="product-detail.php?id=<?php echo $deliveredItem['product_id']; ?>#review" class="btn-review">
                                        <i class="fas fa-star"></i> Add Review
                                    </a>
                                <?php endif; ?>
                            </div>
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