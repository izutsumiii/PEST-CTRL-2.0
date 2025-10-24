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

// Function to get return request details for an order
function getOrderReturnDetails($orderId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT rr.*, 
                   GROUP_CONCAT(DISTINCT CONCAT(p.name, ' (Qty: ', rri.quantity, ')') SEPARATOR ', ') as returned_items,
                   COUNT(DISTINCT rri.product_id) as items_count
            FROM return_requests rr
            LEFT JOIN return_request_items rri ON rr.id = rri.return_request_id
            LEFT JOIN products p ON rri.product_id = p.id
            WHERE rr.order_id = ?
            GROUP BY rr.id
            ORDER BY rr.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$orderId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching return details: " . $e->getMessage());
        return null;
    }
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

// Function to check if user has reviewed a product from an order
function hasUserReviewedProduct($pdo, $userId, $orderId) {
    try {
        // Get all products from the order
        $stmt = $pdo->prepare("
            SELECT oi.product_id 
            FROM order_items oi 
            JOIN orders o ON oi.order_id = o.id 
            WHERE o.id = ? AND o.user_id = ?
        ");
        $stmt->execute([$orderId, $userId]);
        $products = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($products)) {
            return false;
        }
        
        // Check if user has reviewed any of these products
        $placeholders = str_repeat('?,', count($products) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT 1 FROM product_reviews 
            WHERE user_id = ? AND product_id IN ($placeholders) 
            LIMIT 1
        ");
        $stmt->execute(array_merge([$userId], $products));
        
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error checking if user reviewed product: " . $e->getMessage());
        return false;
    }
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
/* Modern Dashboard Styles - Matching Products.php Design */

/* Body background - Lighter white like products.php */
body {
    background: #f8f9fa !important;
    min-height: 100vh;
    color: #130325;
    margin: 0;
    padding: 0;
}

/* Override text-align for header and navigation */
.site-header,
.site-header *,
.navbar,
.navbar *,
.dropdown,
.dropdown * {
    text-align: left !important;
}

/* Dashboard Layout - Matching delivered products width */
.dashboard-layout {
    display: grid;
    grid-template-columns: 1fr;
    align-items: start;
    gap: 30px;
    max-width: 1600px;
    margin: 0 auto;
    padding: 0 20px;
    margin-top: 40px;
}

/* Main dashboard content */
.dashboard-main {
    min-width: 0;
    grid-column: 1;
    grid-row: 1;
}

/* Page title styling */
.page-title {
    color: #130325 !important;
    text-align: left;
    margin: 0 0 30px 60px;
    font-size: 1.8rem;
    font-weight: 700;
    text-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

/* Filter container */
.filter-container {
    background: #ffffff;
    border: 1px solid rgba(0, 0, 0, 0.1);
    border-radius: 8px;
    padding: 20px;
    margin: 0 60px 30px 60px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

/* Filter tabs styling */
.filter-tabs {
    display: flex;
    justify-content: center;
    gap: 10px;
    flex-wrap: wrap;
}

.filter-tab {
    background: #f8f9fa !important;
    border: 1px solid rgba(0, 0, 0, 0.1) !important;
    color: #130325 !important;
    padding: 10px 20px;
    border-radius: 4px;
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.3s ease;
    cursor: pointer;
}

.filter-tab:hover {
    background: #130325 !important;
    color: #ffffff !important;
    border-color: #130325 !important;
}

.filter-tab.active {
    background: #130325 !important;
    color: #ffffff !important;
    border-color: #130325 !important;
}

/* Order cards styling - compact cards */
.order-card {
    background: #ffffff !important;
    border: 1px solid rgba(0, 0, 0, 0.1) !important;
    border-radius: 6px !important;
    padding: 15px !important;
    margin: 0 60px 10px 60px !important;
    transition: all 0.3s ease !important;
    box-shadow: 0 1px 4px rgba(0,0,0,0.05);
    min-height: 80px;
    max-width: 100%;
}

.order-card:hover {
    border-color: rgba(0, 0, 0, 0.2) !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1) !important;
}

/* Order header - compact layout */
.order-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
    padding-bottom: 8px;
    border-bottom: 1px solid rgba(0, 0, 0, 0.1);
}

.order-number {
    color: #130325 !important;
    font-size: 1.1rem;
    font-weight: 700;
}

.order-date {
    color: #666 !important;
    font-size: 0.8rem;
}

.order-left {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.seller-info {
    color: #666 !important;
    font-size: 0.8rem;
    font-weight: 500;
}

/* Order status */
.order-status {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 0.85rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-pending { background: #fff3cd; color: #856404; }
.status-processing { background: #d1ecf1; color: #0c5460; }
.status-shipped { background: #d4edda; color: #155724; }
.status-delivered { background: #d1ecf1; color: #0c5460; }
.status-cancelled { background: #f8d7da; color: #721c24; }

/* Order items - compact */
.order-items {
    margin: 8px 0;
}

.order-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px;
    background: #ffffff;
    border-radius: 4px;
    margin-bottom: 6px;
    border: 1px solid rgba(0, 0, 0, 0.1);
}

.order-item img {
    width: 40px;
    height: 40px;
    object-fit: cover;
    border-radius: 4px;
    border: 1px solid rgba(0, 0, 0, 0.1);
}

.order-item-info {
    flex: 1;
}

.order-item-name {
    color: #130325 !important;
    font-weight: 600;
    font-size: 0.9rem;
    margin-bottom: 2px;
}

.order-item-details {
    color: #666 !important;
    font-size: 0.8rem;
}

/* Order bottom section */
.order-bottom {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 10px;
    padding-top: 8px;
    border-top: 1px solid rgba(0, 0, 0, 0.1);
}

/* Order total */
.order-total {
    text-align: left;
}

.order-total-amount {
    color: #130325 !important;
    font-size: 1.1rem;
    font-weight: 700;
}

/* Order actions */
.order-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.btn {
    padding: 6px 12px;
    border: none;
    border-radius: 4px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.3s ease;
    cursor: pointer;
    font-size: 0.8rem;
}

.btn-primary {
    background: #130325;
    color: #ffffff;
}

.btn-primary:hover {
    background: #0f0220;
    box-shadow: 0 4px 12px rgba(19, 3, 37, 0.3);
}

.btn-danger {
    background: #dc3545;
    color: white;
}

.btn-danger:hover {
    background: #c82333;
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
}

.btn-secondary {
    background: #FFD736;
    color: #130325;
}

.btn-secondary:hover {
    background: #e6c230;
    box-shadow: 0 4px 12px rgba(255, 215, 54, 0.3);
}

.btn-warning {
    background: #FFD736;
    color: #130325;
}

.btn-warning:hover {
    background: #e6c230;
    box-shadow: 0 4px 12px rgba(255, 215, 54, 0.3);
}

/* No orders message */
.no-orders {
    text-align: center;
    padding: 60px 20px;
    color: #666;
}

.no-orders p {
    font-size: 1.1rem;
    margin-bottom: 20px;
}

/* Alert Styles */
.alert {
    padding: 15px 20px;
    margin: 20px auto;
    max-width: 1200px;
    border-radius: 12px;
    font-size: 1rem;
    border: none;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
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

/* Responsive design */
@media (max-width: 768px) {
    .dashboard-layout {
        padding: 0 15px;
        margin-top: 60px;
    }
    
    .order-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .order-actions {
        flex-direction: column;
    }
    
    .btn {
        justify-content: center;
    }
}

/* Reviews Section - Wider and Modern Design */
.delivered-reviews-wrap { 
    max-width: 1600px; 
    margin: 40px auto 20px auto; 
    padding: 0 20px;
}

.delivered-card { 
    background: #ffffff !important;
    border: 1px solid rgba(0, 0, 0, 0.1) !important;
    border-radius: 8px !important;
    overflow: hidden; 
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    transition: all 0.3s ease;
}

.delivered-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 15px 35px rgba(255, 215, 54, 0.2);
}

.delivered-card .header { 
    display: flex; 
    align-items: center; 
    justify-content: space-between; 
    padding: 20px 25px; 
    border-bottom: 1px solid rgba(0, 0, 0, 0.1);
    background: rgba(248, 249, 250, 0.8);
}

.delivered-card .title { 
    color: #130325 !important; 
    font-size: 20px; 
    font-weight: 700; 
    letter-spacing: 0.3px; 
    margin: 0;
}

.delivered-card .subtitle { 
    color: #666 !important; 
    font-size: 14px; 
    opacity: 0.9; 
    margin: 5px 0 0 0;
}

.delivered-list { 
    max-height: 500px; 
    overflow-y: auto; 
    padding: 10px 0;
}

.delivered-item { 
    display: flex; 
    gap: 20px; 
    align-items: flex-start; 
    padding: 18px 25px; 
    border-bottom: 1px solid rgba(255, 215, 54, 0.1);
    transition: all 0.3s ease;
}

.delivered-item:hover {
    background: rgba(255, 255, 255, 0.05);
}

.delivered-item:last-child { 
    border-bottom: none; 
}

.delivered-thumb { 
    width: 80px; 
    height: 80px; 
    border-radius: 12px; 
    border: 2px solid #130325; 
    object-fit: cover; 
    background: #f8f9fa; 
    flex-shrink: 0;
    transition: all 0.3s ease;
}

.delivered-thumb:hover {
    transform: scale(1.05);
    border-color: #FFD736;
}

.delivered-body { 
    flex: 1; 
    min-width: 0; 
}

.delivered-name { 
    color: #130325 !important; 
    font-weight: 700; 
    margin: 0 0 8px 0; 
    font-size: 16px;
    line-height: 1.3;
}

.delivered-meta { 
    display: flex; 
    gap: 16px; 
    flex-wrap: wrap; 
    color: #666 !important; 
    font-size: 13px; 
    margin-bottom: 8px; 
}

.delivered-meta .price { 
    color: #FFD736 !important; 
    font-weight: 700; 
}

.delivered-meta .date { 
    color: #999 !important; 
}

.review-cta { 
    display: flex; 
    align-items: center; 
    gap: 12px; 
    margin-top: 8px;
}

.btn-review { 
    background: #FFD736 !important;
    color: #130325 !important; 
    border: 2px solid #FFD736 !important; 
    padding: 12px 20px; 
    border-radius: 10px; 
    font-weight: 700; 
    font-size: 14px; 
    text-decoration: none; 
    display: inline-flex; 
    align-items: center; 
    gap: 8px; 
    transition: all 0.3s ease;
    cursor: pointer;
}

.btn-review:hover { 
    background: #e6c230 !important;
    border-color: #e6c230 !important; 
    transform: translateY(-2px); 
    box-shadow: 0 8px 20px rgba(255, 215, 54, 0.4); 
    color: #130325 !important; 
    text-decoration: none; 
}

.badge-reviewed { 
    display: inline-block; 
    padding: 8px 14px; 
    border-radius: 20px; 
    border: 1px solid #28a745; 
    color: #28a745; 
    background: rgba(40, 167, 69, 0.15); 
    font-weight: 700; 
    font-size: 13px;
}

.delivered-list::-webkit-scrollbar { 
    width: 8px; 
}

.delivered-list::-webkit-scrollbar-track { 
    background: rgba(255, 255, 255, 0.1); 
    border-radius: 4px;
}

.delivered-list::-webkit-scrollbar-thumb { 
    background: linear-gradient(135deg, #FFD736, #e6c230); 
    border-radius: 4px; 
}

.delivered-list::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #e6c230, #d4b017);
}

@media (max-width: 768px) {
    .delivered-reviews-wrap {
        padding: 0 15px;
    }
    
    .delivered-card .header {
        padding: 15px 20px;
    }
    
    .delivered-item { 
        flex-direction: column; 
        align-items: flex-start; 
        gap: 15px;
        padding: 15px 20px;
    }
    
    .delivered-thumb {
        width: 70px;
        height: 70px;
    }
    
    .review-cta { 
        width: 100%; 
        justify-content: flex-start;
    }
    
    .btn-review {
        padding: 10px 16px;
        font-size: 13px;
    }
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
    background: #ffffff;
    border: 1px solid rgba(0, 0, 0, 0.1);
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
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

.order-status.return_requested { background: rgba(255,193,7,0.2); color:#ffc107; border-color:#ffc107; }


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

/* Removed duplicate btn-primary definition - using the dark purple one above */

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
    width: 8px;
}

.status-items::-webkit-scrollbar-track,
.delivered-products-scroll::-webkit-scrollbar-track,
.user-orders::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 4px;
}

.status-items::-webkit-scrollbar-thumb,
.delivered-products-scroll::-webkit-scrollbar-thumb,
.user-orders::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #FFD736, #e6c230);
    border-radius: 4px;
}

.status-items::-webkit-scrollbar-thumb:hover,
.delivered-products-scroll::-webkit-scrollbar-thumb:hover,
.user-orders::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #e6c230, #d4b017);
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

/* Return/Refund Button Styles */
.btn-return-active {
    background: #495057;
    color: #ffffff;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 0.9rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    transition: all 0.3s ease;
}

.btn-return-active:hover {
    background: #343a40;
    transform: translateY(-1px);
    color: #ffffff;
    text-decoration: none;
}

.btn-return-expired {
    background: #adb5bd;
    color: #ffffff;
    opacity: 0.7;
    cursor: not-allowed;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 0.9rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-return-expired:hover {
    background: #adb5bd;
    transform: none;
    cursor: not-allowed;
}

/* Return Expired Popup Styles */
.return-expired-popup {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 10000;
    backdrop-filter: blur(5px);
}

.return-expired-content {
    background: #ffffff;
    padding: 40px;
    border-radius: 15px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    max-width: 500px;
    width: 90%;
    text-align: center;
    border: 3px solid #dc3545;
    animation: popupSlideIn 0.3s ease-out;
}

@keyframes popupSlideIn {
    from {
        opacity: 0;
        transform: scale(0.8) translateY(-50px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}

.return-expired-icon {
    font-size: 4rem;
    color: #dc3545;
    margin-bottom: 20px;
    animation: shake 0.5s ease-in-out;
}

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-5px); }
    75% { transform: translateX(5px); }
}

.return-expired-title {
    color: #dc3545;
    font-size: 1.8rem;
    font-weight: 700;
    margin-bottom: 15px;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.return-expired-message {
    color: #130325;
    font-size: 1.1rem;
    line-height: 1.6;
    margin-bottom: 25px;
}

.return-expired-button {
    background: #dc3545;
    color: white;
    border: none;
    padding: 12px 30px;
    border-radius: 8px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.return-expired-button:hover {
    background: #c82333;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(220, 53, 69, 0.4);
}

/* Product Selection Popup */
.product-selection-popup {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 10001;
    backdrop-filter: blur(5px);
}

.product-selection-content {
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
    max-width: 500px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from { transform: translateY(-50px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.product-selection-header {
    background: #130325;
    color: #ffffff;
    padding: 20px;
    border-radius: 12px 12px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.product-selection-header h3 {
    margin: 0;
    font-size: 1.3rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
}

.product-selection-header h3 i {
    color: #FFD736;
}

.close-popup {
    background: none;
    border: none;
    color: #ffffff;
    font-size: 1.5rem;
    cursor: pointer;
    padding: 5px;
    border-radius: 50%;
    width: 35px;
    height: 35px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
}

.close-popup:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: scale(1.1);
}

.product-selection-body {
    padding: 25px;
}

.product-selection-body p {
    color: #495057;
    font-size: 1rem;
    margin-bottom: 20px;
    font-weight: 500;
}

.product-checkboxes {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.product-checkbox-item {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    transition: all 0.3s ease;
    cursor: pointer;
}

.product-checkbox-item:hover {
    border-color: #130325;
    background: #f8f9fa;
}

.product-checkbox-item input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: #130325;
    cursor: pointer;
}

.product-checkbox-item.selected {
    border-color: #130325;
    background: rgba(19, 3, 37, 0.05);
}

.product-checkbox-info {
    flex: 1;
}

.product-checkbox-info h5 {
    margin: 0 0 5px 0;
    color: #130325;
    font-size: 1rem;
    font-weight: 600;
}

.product-checkbox-info p {
    margin: 0;
    color: #6c757d;
    font-size: 0.9rem;
}

.product-checkbox-image {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border-radius: 8px;
    border: 2px solid #e9ecef;
}

.product-selection-footer {
    padding: 20px 25px;
    border-top: 1px solid #e9ecef;
    display: flex;
    gap: 15px;
    justify-content: flex-end;
}

.product-selection-footer .btn {
    padding: 10px 20px;
    border-radius: 6px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
}

.product-selection-footer .btn-secondary {
    background: #6c757d;
    color: #ffffff;
}

.product-selection-footer .btn-secondary:hover {
    background: #5a6268;
    transform: translateY(-1px);
}

.product-selection-footer .btn-primary {
    background: #130325;
    color: #ffffff;
}

.product-selection-footer .btn-primary:hover {
    background: #0f0220;
    transform: translateY(-1px);
}

.delivered-products {
    animation-delay: 0.4s;
}

/* Custom confirmation dialog styling */
.confirm-dialog {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    backdrop-filter: blur(5px);
}

.confirm-content {
    background: linear-gradient(135deg, #130325 0%, #2a0a4a 100%);
    border: 2px solid #FFD736;
    border-radius: 15px;
    padding: 30px;
    max-width: 400px;
    width: 90%;
    text-align: center;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
    animation: confirmSlideIn 0.3s ease;
}

@keyframes confirmSlideIn {
    from {
        opacity: 0;
        transform: translateY(-30px) scale(0.9);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.confirm-title {
    color: #FFD736;
    font-size: 1.3rem;
    font-weight: 700;
    margin-bottom: 15px;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.confirm-message {
    color: #F9F9F9;
    font-size: 1rem;
    margin-bottom: 25px;
    line-height: 1.5;
}

.confirm-buttons {
    display: flex;
    gap: 15px;
    justify-content: center;
}

.confirm-btn {
    padding: 12px 25px;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    cursor: pointer;
    transition: all 0.3s ease;
    min-width: 100px;
}

.confirm-btn-yes {
    background: #dc3545;
    color: white;
    border: 2px solid #dc3545;
}

.confirm-btn-yes:hover {
    background: #c82333;
    border-color: #c82333;
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4);
}

.confirm-btn-no {
    background: #6c757d;
    color: white;
    border: 2px solid #6c757d;
}

.confirm-btn-no:hover {
    background: #5a6268;
    border-color: #5a6268;
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
}
</style>

<script>
// Custom styled confirmation dialog function
function openConfirm(message, onConfirm) {
    // Create dialog overlay
    const dialog = document.createElement('div');
    dialog.className = 'confirm-dialog';
    dialog.innerHTML = `
        <div class="confirm-content">
            <div class="confirm-title">Confirm Action</div>
            <div class="confirm-message">${message}</div>
            <div class="confirm-buttons">
                <button class="confirm-btn confirm-btn-yes">Yes</button>
                <button class="confirm-btn confirm-btn-no">No</button>
            </div>
        </div>
    `;
    
    // Add to page
    document.body.appendChild(dialog);
    
    // Handle button clicks
    const yesBtn = dialog.querySelector('.confirm-btn-yes');
    const noBtn = dialog.querySelector('.confirm-btn-no');
    
    yesBtn.addEventListener('click', () => {
        document.body.removeChild(dialog);
        onConfirm();
    });
    
    noBtn.addEventListener('click', () => {
        document.body.removeChild(dialog);
    });
    
    // Handle escape key
    const handleEscape = (e) => {
        if (e.key === 'Escape') {
            document.body.removeChild(dialog);
            document.removeEventListener('keydown', handleEscape);
        }
    };
    document.addEventListener('keydown', handleEscape);
    
    // Handle click outside
    dialog.addEventListener('click', (e) => {
        if (e.target === dialog) {
            document.body.removeChild(dialog);
            document.removeEventListener('keydown', handleEscape);
        }
    });
}

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
    // Clean confirmation message
    openConfirm('Cancel order #' + orderId + '?', function(){
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
                openConfirm('Cancel this order?', function(){
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
    
    // Filter tabs functionality
    const filterTabs = document.querySelectorAll('.filter-tab');
    const orderCards = document.querySelectorAll('.order-card');
    
    filterTabs.forEach(tab => {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Remove active class from all tabs
            filterTabs.forEach(t => t.classList.remove('active'));
            
            // Add active class to clicked tab
            this.classList.add('active');
            
            const status = this.getAttribute('data-status');
            
            // Update URL to reflect the selected filter
            const url = new URL(window.location);
            url.searchParams.set('status', status);
            window.history.pushState({}, '', url);
            
            // Reload the page to apply the filter on the server side
            window.location.reload();
        });
    });
});

function showReturnExpiredPopup() {
    // Create popup HTML
    const popupHTML = `
        <div class="return-expired-popup" id="returnExpiredPopup">
            <div class="return-expired-content">
                <div class="return-expired-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h2 class="return-expired-title">Return Period Expired</h2>
                <p class="return-expired-message">
                    Sorry, the return/refund period for this order has expired. 
                    Returns and refunds are only allowed within 7 days of delivery.
                </p>
                <button class="return-expired-button" onclick="closeReturnExpiredPopup()">
                    I Understand
                </button>
            </div>
        </div>
    `;
    
    // Add popup to body
    document.body.insertAdjacentHTML('beforeend', popupHTML);
    
    // Prevent body scroll
    document.body.style.overflow = 'hidden';
    
    // Close popup when clicking outside
    document.getElementById('returnExpiredPopup').addEventListener('click', function(e) {
        if (e.target === this) {
            closeReturnExpiredPopup();
        }
    });
    
    // Close popup with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeReturnExpiredPopup();
        }
    });
}

function closeReturnExpiredPopup() {
    const popup = document.getElementById('returnExpiredPopup');
    if (popup) {
        popup.remove();
        document.body.style.overflow = '';
    }
}

// Product Selection Popup Functions
let currentOrderId = null;
let currentOrderItems = null;

function showProductSelectionPopup(orderId) {
    currentOrderId = orderId;
    
    // Find the order data from the page
    const orderElement = document.querySelector(`[data-order-id="${orderId}"]`);
    if (!orderElement) {
        console.error('Order element not found');
        return;
    }
    
    // Get order items from the order element
    const orderItems = [];
    const itemElements = orderElement.querySelectorAll('.order-item');
    
    console.log('DEBUG - Found order item elements:', itemElements.length);
    
    itemElements.forEach((item, index) => {
        const img = item.querySelector('img');
        const nameElement = item.querySelector('.item-name');
        const qtyElement = item.querySelector('.item-qty');
        
        console.log('DEBUG - Item', index, ':', {
            hasImg: !!img,
            hasName: !!nameElement,
            hasQty: !!qtyElement,
            productId: item.dataset.productId,
            name: nameElement ? nameElement.textContent.trim() : 'no name'
        });
        
        if (nameElement && qtyElement) {
            // Get the actual product_id from the data attribute
            const productId = item.dataset.productId;
            if (productId) {
                orderItems.push({
                    product_id: productId,
                    product_name: nameElement.textContent.trim(),
                    quantity: qtyElement.textContent.replace('Qty: ', '').trim(),
                    image_url: img ? img.src : ''
                });
            }
        }
    });
    
    console.log('DEBUG - Final order items:', orderItems);
    currentOrderItems = orderItems;
    
    // Populate the popup
    const checkboxesContainer = document.getElementById('productCheckboxes');
    checkboxesContainer.innerHTML = '';
    
    orderItems.forEach((item, index) => {
        const checkboxItem = document.createElement('div');
        checkboxItem.className = 'product-checkbox-item';
        checkboxItem.innerHTML = `
            <input type="checkbox" id="product_${item.product_id}" value="${item.product_id}" checked>
            <img src="${item.image_url}" alt="${item.product_name}" class="product-checkbox-image" onerror="this.style.display='none'">
            <div class="product-checkbox-info">
                <h5>${item.product_name}</h5>
                <p>Quantity: ${item.quantity}</p>
            </div>
        `;
        
        // Add click handler for the entire item
        checkboxItem.addEventListener('click', function(e) {
            if (e.target.type !== 'checkbox') {
                const checkbox = this.querySelector('input[type="checkbox"]');
                checkbox.checked = !checkbox.checked;
                updateItemSelection(this);
            }
        });
        
        // Add change handler for checkbox
        const checkbox = checkboxItem.querySelector('input[type="checkbox"]');
        checkbox.addEventListener('change', function() {
            updateItemSelection(checkboxItem);
        });
        
        checkboxesContainer.appendChild(checkboxItem);
        updateItemSelection(checkboxItem);
    });
    
    // Show the popup
    const popup = document.getElementById('productSelectionPopup');
    popup.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    // Close on background click
    popup.addEventListener('click', function(e) {
        if (e.target === popup) {
            closeProductSelectionPopup();
        }
    });
}

function updateItemSelection(item) {
    const checkbox = item.querySelector('input[type="checkbox"]');
    if (checkbox.checked) {
        item.classList.add('selected');
    } else {
        item.classList.remove('selected');
    }
}

function closeProductSelectionPopup() {
    const popup = document.getElementById('productSelectionPopup');
    popup.style.display = 'none';
    document.body.style.overflow = '';
    currentOrderId = null;
    currentOrderItems = null;
}

function confirmProductSelection() {
    const checkboxes = document.querySelectorAll('#productCheckboxes input[type="checkbox"]:checked');
    
    console.log('DEBUG - Found checkboxes:', checkboxes.length);
    console.log('DEBUG - Checkboxes:', checkboxes);
    
    if (checkboxes.length === 0) {
        alert('Please select at least one product to return.');
        return;
    }
    
    // Get selected product IDs
    const selectedProductIds = Array.from(checkboxes).map(cb => cb.value);
    console.log('DEBUG - Selected Product IDs:', selectedProductIds);
    console.log('DEBUG - Current Order ID:', currentOrderId);
    
    // Redirect to customer-returns.php with order_id and selected product IDs
    const productIdsParam = selectedProductIds.join(',');
    const redirectUrl = `customer-returns.php?order_id=${currentOrderId}&product_ids=${productIdsParam}`;
    console.log('DEBUG - Redirect URL:', redirectUrl);
    
    window.location.href = redirectUrl;
}
</script>

<!-- Display cancel message if exists -->
<?php if (isset($cancelMessage)): ?>
    <?php echo $cancelMessage; ?>
<?php endif; ?>

<div class="dashboard-layout">
    <div class="dashboard-main">
        <h1 class="page-title">My Orders</h1>
        
        <!-- Filter Container -->
        <div class="filter-container">
            <div class="filter-tabs">
                <?php 
                $currentFilter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'delivered';
                ?>
                <a href="#" class="filter-tab <?php echo $currentFilter === 'pending' ? 'active' : ''; ?>" data-status="pending">PENDING</a>
                <a href="#" class="filter-tab <?php echo $currentFilter === 'processing' ? 'active' : ''; ?>" data-status="processing">TO SHIP</a>
                <a href="#" class="filter-tab <?php echo $currentFilter === 'shipped' ? 'active' : ''; ?>" data-status="shipped">TO RECEIVE</a>
                <a href="#" class="filter-tab <?php echo $currentFilter === 'delivered' ? 'active' : ''; ?>" data-status="delivered">COMPLETED</a>
                <a href="#" class="filter-tab <?php echo $currentFilter === 'cancelled' ? 'active' : ''; ?>" data-status="cancelled">CANCELLED</a>
                <a href="#" class="filter-tab <?php echo $currentFilter === 'return_requested' ? 'active' : ''; ?>" data-status="return_requested">REFUNDED/RETURNS</a>
            </div>
        </div>
    
        <div class="user-orders" style="margin: 0 60px; border-radius: 8px; max-height: 600px; overflow-y: auto;">
        <?php if (empty($orders)): ?>
            <div class="no-orders">
                <p>You haven't placed any orders yet.</p>
                <a href="products.php" class="btn btn-primary">Start Shopping</a>
            </div>
        <?php else: ?>
            <?php
            $filter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'delivered';
            $hasFilteredOrders = false;
            
            // First pass: check if there are any orders matching the filter
            foreach ($orders as $order) {
                $orderStatusKey = strtolower($order['status']);
                
                // Special handling for return_requested - check both order status and if return request exists
                if ($filter === 'return_requested') {
                    // Check if this order has a return request
                    $returnCheck = getOrderReturnDetails($order['id']);
                    if ($returnCheck !== null) {
                        $hasFilteredOrders = true;
                        break;
                    }
                } elseif ($filter === $orderStatusKey) {
                    $hasFilteredOrders = true;
                    break;
                }
            }
        
        if (!$hasFilteredOrders): ?>
            <div class="no-orders">
                <p>No <?php echo ucfirst($filter); ?> orders found.</p>
                <a href="products.php" class="btn btn-primary">Continue Shopping</a>
            </div>
        <?php else: ?>
            <?php foreach ($orders as $order):
                $orderStatusKey = strtolower($order['status']);
                
                // Special handling for return_requested filter
                if ($filter === 'return_requested') {
                    $returnCheck = getOrderReturnDetails($order['id']);
                    if ($returnCheck === null) {
                        continue; // Skip orders without return requests
                    }
                } elseif ($filter !== $orderStatusKey) {
                    continue;
                }
            ?>
            
            <div class="order-card" data-order-id="<?php echo $order['id']; ?>">
                <!-- Header: Seller | Order Number | Status -->
                <div class="order-header">
                    <div class="order-left">
                        <?php 
                        // Get seller info from first order item
                        $sellerName = 'Unknown Shop';
                        if (!empty($order['order_items'])) {
                            try {
                                $stmt = $pdo->prepare("SELECT u.first_name, u.last_name FROM products p JOIN users u ON p.seller_id = u.id WHERE p.id = ?");
                                $stmt->execute([$order['order_items'][0]['product_id']]);
                                $seller = $stmt->fetch(PDO::FETCH_ASSOC);
                                if ($seller) {
                                    $sellerName = $seller['first_name'] . ' ' . $seller['last_name'];
                                }
                            } catch (Exception $e) {
                                $sellerName = 'Shop';
                            }
                        }
                        ?>
                        <div class="seller-info"><?php echo htmlspecialchars($sellerName); ?></div>
                        <div class="order-number">Order #<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?></div>
                    </div>
                    <span class="order-status status-<?php echo strtolower($order['status']); ?>">
                        <?php echo ucfirst($order['status']); ?>
                    </span>
                </div>
                
                <!-- Items: PIC | NAME | QTY -->
                <div class="order-items">
                    <?php if (!empty($order['order_items'])): ?>
                        <?php foreach ($order['order_items'] as $item): ?>
                            <div class="order-item" data-product-id="<?php echo $item['product_id']; ?>">
                                <img src="<?php echo htmlspecialchars($item['image_url']); ?>" 
                                     alt="<?php echo htmlspecialchars($item['product_name']); ?>">
                                <div class="order-item-info">
                                    <div class="order-item-name item-name"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                    <div class="order-item-details">
                                        <span class="item-qty">Qty: <?php echo $item['quantity']; ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Bottom: PRICE | BUTTONS -->
                <div class="order-bottom">
                    <div class="order-total">
                        <div class="order-total-amount">₱<?php echo number_format($order['total_amount'], 2); ?></div>
                    </div>
                    
                    <div class="order-actions">
                        <?php if (strtolower($order['status']) === 'delivered'): ?>
                            <!-- COMPLETED ORDERS: View Details, Return/Refund and Rate buttons -->
                            <a href="order-details.php?id=<?php echo $order['id']; ?>" class="btn btn-primary">
                                <i class="fas fa-eye"></i> View Details
                            </a>
                            <?php 
                            // Check if 1 week has passed since delivery for return/refund
                            $deliveryDate = new DateTime($order['delivery_date'] ?? $order['created_at']);
                            $currentDate = new DateTime();
                            $daysSinceDelivery = $currentDate->diff($deliveryDate)->days;
                            $canReturn = $daysSinceDelivery <= 7;
                            ?>
                <?php if ($canReturn): ?>
                    <?php if (count($order['order_items']) > 1): ?>
                        <button onclick="showProductSelectionPopup(<?php echo $order['id']; ?>)" class="btn btn-return-active">
                            <i class="fas fa-undo"></i> Return/Refund
                        </button>
                    <?php else: ?>
                        <a href="customer-returns.php?order_id=<?php echo $order['id']; ?>" class="btn btn-return-active">
                            <i class="fas fa-undo"></i> Return/Refund
                        </a>
                    <?php endif; ?>
                            <?php else: ?>
                                <button onclick="showReturnExpiredPopup()" class="btn btn-return-expired" disabled>
                                    <i class="fas fa-undo"></i> Return/Refund
                                </button>
                            <?php endif; ?>
                            <?php 
                            // Check if user has already reviewed this product
                            $hasReviewed = hasUserReviewedProduct($pdo, $userId, $order['id']);
                            ?>
                            <?php if ($hasReviewed): ?>
                                <a href="product-detail.php?id=<?php echo $order['order_items'][0]['product_id']; ?>" class="btn btn-warning">
                                    <i class="fas fa-shopping-cart"></i> Buy Again
                                </a>
                            <?php else: ?>
                                <a href="product-detail.php?id=<?php echo $order['order_items'][0]['product_id']; ?>#reviews-tab" class="btn btn-secondary">
                                    <i class="fas fa-star"></i> Rate
                                </a>
                            <?php endif; ?>
                            <?php elseif (strtolower($order['status']) === 'return_requested'): ?>
                            <!-- RETURN REQUESTED ORDERS: Show return details -->
                            <?php 
                            $returnDetails = getOrderReturnDetails($order['id']);
                            if ($returnDetails): 
                            ?>
                                <div style="background: #fff3cd; padding: 12px; border-radius: 6px; margin: 10px 0; border-left: 4px solid #ffc107;">
                                    <div style="font-weight: 600; color: #856404; margin-bottom: 5px;">
                                        <i class="fas fa-undo"></i> Return Request Status: 
                                        <span style="text-transform: uppercase;"><?php echo htmlspecialchars($returnDetails['status']); ?></span>
                                    </div>
                                    <div style="color: #856404; font-size: 0.9rem; margin-bottom: 5px;">
                                        <strong>Returned Items:</strong> <?php echo htmlspecialchars($returnDetails['returned_items']); ?>
                                    </div>
                                    <div style="color: #856404; font-size: 0.9rem; margin-bottom: 5px;">
                                        <strong>Reason:</strong> <?php echo htmlspecialchars($returnDetails['reason']); ?>
                                    </div>
                                    <div style="color: #856404; font-size: 0.85rem;">
                                        <strong>Requested on:</strong> <?php echo date('M d, Y g:i A', strtotime($returnDetails['created_at'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <a href="order-details.php?id=<?php echo $order['id']; ?>" class="btn btn-primary">
                                <i class="fas fa-eye"></i> View Details
                            </a>
                            <a href="customer-returns.php" class="btn btn-warning">
                                <i class="fas fa-search"></i> Track Return
                            </a>
                        <?php else: ?>
                            <!-- OTHER STATUSES: View Details and Cancel (if pending) -->
                            <a href="order-details.php?id=<?php echo $order['id']; ?>" class="btn btn-primary">
                                <i class="fas fa-eye"></i> View Details
                            </a>
                            
                            <?php if (strtolower($order['status']) === 'pending'): ?>
                                <button type="button" class="btn btn-danger" onclick="openCancelModal(<?php echo $order['id']; ?>)">
                                    <i class="fas fa-times"></i> Cancel Order
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
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

<!-- Product Selection Popup for Multiple Items -->
<div class="product-selection-popup" id="productSelectionPopup">
    <div class="product-selection-content">
        <div class="product-selection-header">
            <h3><i class="fas fa-shopping-bag"></i> Select Products to Return</h3>
            <button type="button" class="close-popup" onclick="closeProductSelectionPopup()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="product-selection-body">
            <p>Which products would you like to request a refund/return for?</p>
            <div class="product-checkboxes" id="productCheckboxes">
                <!-- Products will be populated here by JavaScript -->
            </div>
        </div>
        
        <div class="product-selection-footer">
            <button type="button" class="btn btn-secondary" onclick="closeProductSelectionPopup()">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="confirmProductSelection()">Confirm</button>
        </div>
    </div>
</div>

</script>
