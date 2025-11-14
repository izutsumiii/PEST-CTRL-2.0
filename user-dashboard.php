<?php
date_default_timezone_set('Asia/Manila');
require_once 'includes/header.php';
require_once 'config/database.php';

// spacer below fixed header (add ~8px more)
echo '<div style="height:0px"></div>';

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

// Function to get return request status with detailed info
function getReturnRequestStatus($orderId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT rr.*, 
                   GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') as returned_products
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
        error_log("Error fetching return request status: " . $e->getMessage());
        return null;
    }
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
        // Check if user has reviewed THIS SPECIFIC ORDER
        // Now that we have order_id in product_reviews, we can check precisely
        
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM product_reviews pr
            WHERE pr.order_id = ? 
              AND pr.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$orderId, $userId]);
        
        return (int)$stmt->fetchColumn() > 0;
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
                    $cancelMessage = "<div class=\"floating-toast\" style=\"position:fixed; left:50%; bottom:24px; transform:translateX(-50%); background:#ffffff; color:#130325; border:1px solid #e5e7eb; border-radius:12px; padding:16px 18px; z-index:10000; box-shadow:0 10px 30px rgba(0,0,0,0.2); max-width:90%; width:520px; text-align:center;\">\n"
                      . "<div style=\"font-weight:800; margin-bottom:6px;\">Order Cancelled Successfully!</div>\n"
                      . "<div style=\"font-weight:600;\">Order #" . str_pad($orderId, 6, '0', STR_PAD_LEFT) . " has been cancelled.</div>\n"
                      . "<div style=\"margin-top:6px; font-size:13px; color:#4b5563;\">The seller has been notified. If you made a payment, the refund will be processed within 3-5 business days.</div>\n"
                      . "</div>";
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


// Automatic completion: Check for delivered orders that are 1 week old
try {
    $stmt = $pdo->prepare("SELECT id FROM orders 
                           WHERE user_id = ? 
                           AND status = 'delivered' 
                           AND delivery_date IS NOT NULL 
                           AND delivery_date <= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stmt->execute([$userId]);
    $oldDeliveredOrders = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($oldDeliveredOrders)) {
        $pdo->beginTransaction();
        foreach ($oldDeliveredOrders as $orderId) {
            // Update to completed
            $stmt = $pdo->prepare("UPDATE orders SET status = 'completed', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$orderId]);
            
            // Log status change
            try {
                $stmt = $pdo->prepare("INSERT INTO order_status_history (order_id, status, notes, updated_by, created_at) 
                                      VALUES (?, 'completed', 'Automatically completed after 1 week', ?, NOW())");
                $stmt->execute([$orderId, $userId]);
            } catch (Exception $e) {
                error_log("Failed to log auto-completion: " . $e->getMessage());
            }
            
            // Create notification for customer
            try {
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, order_id, message, type, created_at) 
                                      VALUES (?, ?, ?, 'info', NOW())");
                $message = "Order #" . str_pad($orderId, 6, '0', STR_PAD_LEFT) . " has been automatically completed.";
                $stmt->execute([$userId, $orderId, $message]);
            } catch (Exception $e) {
                error_log("Failed to create auto-completion notification: " . $e->getMessage());
            }
        }
        $pdo->commit();
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error in automatic completion: " . $e->getMessage());
}

// Get orders by status - NOW THESE FUNCTIONS WILL WORK
$pendingOrders = getOrdersByStatus('pending');
$processingOrders = getOrdersByStatus('processing');
$shippedOrders = getOrdersByStatus('shipped');
$cancelledOrders = getOrdersByStatus('cancelled');
$orders = getUserOrders();
$deliveredOrders = getDeliveredOrders();

// Get orders with return requests for the return_requested filter
$returnRequestedOrders = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT o.*, 
               GROUP_CONCAT(CONCAT(p.name, ' (x', oi.quantity, ')') SEPARATOR ', ') as items,
               rr.status as return_status,
               rr.id as return_request_id
        FROM orders o
        INNER JOIN return_requests rr ON o.id = rr.order_id
        LEFT JOIN order_items oi ON o.id = oi.order_id
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE o.user_id = ?
        GROUP BY o.id
        ORDER BY rr.created_at DESC
    ");
    $stmt->execute([$userId]);
    $returnRequestedOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get detailed order items with images for each return requested order
    foreach ($returnRequestedOrders as &$order) {
        $stmt = $pdo->prepare("
            SELECT oi.*, p.name as product_name, p.image_url, p.price as product_price
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ?
            ORDER BY oi.id
        ");
        $stmt->execute([$order['id']]);
        $order['order_items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching return requested orders: " . $e->getMessage());
}

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

body {
    background: var(--bg-light) !important;
    min-height: 100vh;
    color: var(--text-dark);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    margin: 0;
    padding: 0;
}

.site-header,
.site-header *,
.navbar,
.navbar *,
.dropdown,
.dropdown * {
    text-align: left !important;
}

.dashboard-layout {
    max-width: 1600px;
    margin: 0 auto;
    padding: 12px 20px;
}

.dashboard-main {
    width: 100%;
}

h1 {
    color: var(--text-dark);
    margin: 0 0 12px 0;
    font-size: 1.5rem;
    font-weight: 700;
    letter-spacing: -0.5px;
}

.page-title,
h1.page-title {
    color: var(--text-dark);
    margin: 0;
    font-size: 1.35rem;
    font-weight: 600;
    letter-spacing: -0.3px;
    line-height: 1.2;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    flex-wrap: wrap;
    gap: 10px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border-light);
}

.filter-container {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    padding: 10px 12px;
    margin-bottom: 14px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.filter-tabs {
    display: flex;
    justify-content: center;
    gap: 6px;
    flex-wrap: wrap;
}

.filter-tab {
    background: var(--bg-light);
    border: 1px solid var(--border-light);
    color: var(--text-dark);
    padding: 6px 14px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    font-size: 12px;
    transition: all 0.2s ease;
    cursor: pointer;
    white-space: nowrap;
}

.filter-tab:hover {
    background: var(--primary-dark);
    color: #ffffff;
    border-color: var(--primary-dark);
    transform: translateY(-1px);
}

.filter-tab.active {
    background: var(--primary-dark);
    color: #ffffff;
    border-color: var(--primary-dark);
}

.order-card {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    padding: 12px 16px;
    margin-bottom: 12px;
    transition: all 0.2s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.order-card:hover {
    border-color: var(--border-light);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    transform: translateY(-1px);
}

.order-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border-light);
    flex-wrap: wrap;
    gap: 8px;
}

.order-left {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
    min-width: 0;
}

.order-number {
    color: var(--text-dark);
    font-size: 14px;
    font-weight: 700;
    letter-spacing: -0.3px;
}

.order-date {
    color: var(--text-light);
    font-size: 11px;
}

.seller-info {
    color: var(--text-light);
    font-size: 11px;
    font-weight: 500;
}

.order-status-wrapper {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
}

.order-status {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
    border: 1px solid transparent;
}

.status-pending { background: rgba(255,193,7,0.2); color: #ff8c00; border-color: #ff8c00; }
.status-processing { background: rgba(0,123,255,0.2); color: #007bff; border-color: #007bff; }
.status-shipped { background: rgba(23,162,184,0.2); color: #17a2b8; border-color: #17a2b8; }
.status-delivered { background: rgba(40,167,69,0.2); color: #28a745; border-color: #28a745; }
.status-completed { background: rgba(40,167,69,0.2); color: #28a745; border-color: #28a745; }
.status-cancelled { background: rgba(220,53,69,0.2); color: #dc3545; border-color: #dc3545; }

.order-items {
    margin: 10px 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.order-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px;
    background: var(--bg-light);
    border-radius: 8px;
    border: 1px solid var(--border-light);
}

.order-item img {
    width: 40px;
    height: 40px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid var(--border-light);
    flex-shrink: 0;
}

.order-item-info {
    flex: 1;
    min-width: 0;
}

.order-item-name {
    color: var(--text-dark);
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 3px;
    line-height: 1.4;
}

.order-item-details {
    color: var(--text-light);
    font-size: 11px;
    line-height: 1.4;
}

.order-bottom {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid var(--border-light);
    flex-wrap: wrap;
    gap: 10px;
}

.order-total {
    text-align: left;
}

.order-total-amount {
    color: var(--text-dark);
    font-size: 15px;
    font-weight: 700;
    letter-spacing: -0.3px;
}

.order-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.btn {
    padding: 8px 14px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 12px;
    text-decoration: none;
    text-align: center;
    transition: all 0.2s ease;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    font-family: inherit;
    white-space: nowrap;
}

.btn-primary {
    background-color: var(--primary-dark);
    color: white;
}

.btn-primary:hover {
    background-color: #0a0118;
    transform: translateY(-1px);
}

.btn-danger {
    background-color: var(--error-red);
    color: white;
}

.btn-danger:hover {
    background-color: #dc2626;
    transform: translateY(-1px);
}

.btn-secondary {
    background-color: var(--accent-yellow);
    color: var(--text-dark);
}

.btn-secondary:hover {
    background-color: #ffd020;
    transform: translateY(-1px);
}

.btn-warning {
    background-color: var(--accent-yellow);
    color: var(--text-dark);
}

.btn-warning:hover {
    background-color: #ffd020;
    transform: translateY(-1px);
}

.no-orders {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    text-align: center;
    padding: 40px 20px;
    color: var(--text-light);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.no-orders p {
    font-size: 14px;
    margin-bottom: 16px;
    color: var(--text-dark);
}

.user-orders {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    border-left: 4px solid;
    font-size: 14px;
}

.alert-success {
    background-color: #d1fae5;
    border-left-color: var(--success-green);
    border: 1px solid #a7f3d0;
    color: #065f46;
}

.alert-error {
    background-color: #fef2f2;
    border-left-color: var(--error-red);
    border: 1px solid #fecaca;
    color: #991b1b;
}

.alert-warning {
    background-color: #fef3c7;
    border-left-color: #f59e0b;
    border: 1px solid #fde68a;
    color: #92400e;
}

/* Responsive design */
@media (max-width: 968px) {
    .dashboard-layout {
        padding: 10px 12px;
        max-width: 100%;
    }
    
    h1,
    .page-title,
    h1.page-title {
        font-size: 1.2rem;
        margin-bottom: 12px;
    }
    
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        padding-bottom: 10px;
        margin-bottom: 14px;
    }
    
    .filter-container {
        padding: 8px 10px;
        margin-bottom: 12px;
    }
    
    .filter-tabs {
        gap: 4px;
        justify-content: center;
    }
    
    .filter-tab {
        padding: 5px 10px;
        font-size: 11px;
    }
    
    .order-card {
        padding: 10px 12px;
        margin-bottom: 10px;
    }
    
    .order-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
        margin-bottom: 8px;
        padding-bottom: 8px;
    }
    
    .order-number {
        font-size: 13px;
    }
    
    .order-status {
        font-size: 0.65rem;
        padding: 3px 8px;
    }
    
    .order-item {
        padding: 6px;
        gap: 8px;
    }
    
    .order-item img {
        width: 36px;
        height: 36px;
    }
    
    .order-item-name {
        font-size: 12px;
    }
    
    .order-item-details {
        font-size: 10px;
    }
    
    .order-bottom {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
        margin-top: 8px;
        padding-top: 8px;
    }
    
    .order-actions {
        width: 100%;
        flex-direction: column;
        gap: 6px;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
        padding: 8px 12px;
        font-size: 11px;
    }
}

.delivered-reviews-wrap { 
    max-width: 1600px; 
    margin: 16px auto; 
    padding: 0 20px;
}

.delivered-card { 
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    overflow: hidden; 
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    transition: all 0.2s ease;
}

.delivered-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.delivered-card .header { 
    display: flex; 
    align-items: center; 
    justify-content: space-between; 
    padding: 12px 16px; 
    border-bottom: 1px solid var(--border-light);
    background: var(--bg-light);
    flex-wrap: wrap;
    gap: 8px;
}

.delivered-card .title { 
    color: var(--text-dark); 
    font-size: 16px; 
    font-weight: 700; 
    letter-spacing: -0.3px; 
    margin: 0;
}

.delivered-card .subtitle { 
    color: var(--text-light); 
    font-size: 13px; 
    margin: 4px 0 0 0;
}

.delivered-list { 
    max-height: 400px; 
    overflow-y: auto; 
    padding: 8px 0;
}

.delivered-item { 
    display: flex; 
    gap: 14px; 
    align-items: flex-start; 
    padding: 14px 20px; 
    border-bottom: 1px solid var(--border-light);
    transition: all 0.2s ease;
}

.delivered-item:hover {
    background: var(--bg-light);
}

.delivered-item:last-child { 
    border-bottom: none; 
}

.delivered-thumb { 
    width: 60px; 
    height: 60px; 
    border-radius: 8px; 
    border: 1px solid var(--border-light); 
    object-fit: cover; 
    background: var(--bg-light); 
    flex-shrink: 0;
    transition: all 0.2s ease;
}

.delivered-thumb:hover {
    border-color: var(--border-light);
}

.delivered-body { 
    flex: 1; 
    min-width: 0; 
}

.delivered-name { 
    color: var(--text-dark); 
    font-weight: 600; 
    margin: 0 0 6px 0; 
    font-size: 14px;
    line-height: 1.4;
}

.delivered-meta { 
    display: flex; 
    gap: 12px; 
    flex-wrap: wrap; 
    color: var(--text-light); 
    font-size: 12px; 
    margin-bottom: 8px; 
}

.delivered-meta .price { 
    color: var(--text-dark); 
    font-weight: 600; 
}

.delivered-meta .date { 
    color: var(--text-light); 
}

.review-cta { 
    display: flex; 
    align-items: center; 
    gap: 10px; 
    margin-top: 6px;
}

.btn-review { 
    background: var(--accent-yellow);
    color: var(--text-dark); 
    border: none; 
    padding: 8px 16px; 
    border-radius: 8px; 
    font-weight: 600; 
    font-size: 13px; 
    text-decoration: none; 
    display: inline-flex; 
    align-items: center; 
    gap: 6px; 
    transition: all 0.2s ease;
    cursor: pointer;
}

.btn-review:hover { 
    background: #ffd020;
    transform: translateY(-1px); 
    color: var(--text-dark); 
    text-decoration: none; 
}

.badge-reviewed { 
    display: inline-block; 
    padding: 4px 10px; 
    border-radius: 6px; 
    border: 1px solid var(--success-green); 
    color: var(--success-green); 
    background: rgba(16, 185, 129, 0.1); 
    font-weight: 600; 
    font-size: 11px;
}

.delivered-list::-webkit-scrollbar { 
    width: 6px; 
}

.delivered-list::-webkit-scrollbar-track { 
    background: var(--bg-light); 
    border-radius: 3px;
}

.delivered-list::-webkit-scrollbar-thumb { 
    background: var(--border-light); 
    border-radius: 3px; 
}

.delivered-list::-webkit-scrollbar-thumb:hover {
    background: var(--text-light);
}

@media (max-width: 968px) {
    .delivered-reviews-wrap {
        padding: 0 10px;
    }
    
    .delivered-card .header {
        padding: 12px 16px;
        flex-direction: column;
        align-items: flex-start;
    }
    
    .delivered-card .title {
        font-size: 16px;
    }
    
    .delivered-item { 
        flex-direction: column; 
        align-items: flex-start; 
        gap: 10px;
        padding: 12px 16px;
    }
    
    .delivered-thumb {
        width: 50px;
        height: 50px;
    }
    
    .delivered-name {
        font-size: 13px;
    }
    
    .review-cta { 
        width: 100%; 
        justify-content: flex-start;
    }
    
    .btn-review {
        padding: 8px 14px;
        font-size: 12px;
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
    border-radius: 8px;
    font-weight: 600;
    transition: all 0.2s ease;
    border: none;
    position: relative;
    z-index: 1;
}

.user-info a:hover {
    background: #ffd020;
    transform: translateY(-1px);
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
    border-bottom: 1px solid var(--border-light);
    padding-bottom: 8px;
}

.user-orders p { color:#d7d1e2; font-size: .98rem; text-align:center; margin-top: 40px; font-style: italic; }


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

.order-status.pending { background: rgba(255,193,7,0.2); color:#ff8c00; border-color:#ff8c00; }

.order-status.processing { background: rgba(0,123,255,0.2); color:#007bff; border-color:#007bff; }

.order-status.shipped { background: rgba(23,162,184,0.2); color:#17a2b8; border-color:#17a2b8; }

.order-status.delivered { background: rgba(40,167,69,0.2); color:#28a745; border-color:#28a745; }

.order-status.completed { background: rgba(40,167,69,0.2); color:#28a745; border-color:#28a745; }

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
    padding: 4px 10px;
    border: none;
    border-radius: 4px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 0.75rem;
    text-align: center;
    display: inline-flex;
    align-items: center;
    gap: 4px;
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
    background: #ffffff;
    margin: 10% auto;
    padding: 16px 20px;
    border-radius: 12px;
    width: 92%;
    max-width: 420px;
    position: relative;
    animation: modalSlideIn 0.25s ease;
    box-shadow: 0 10px 30px rgba(0,0,0,0.25);
    border: 1px solid #e5e7eb;
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
    right: 10px;
    top: 10px;
    background: transparent;
    border: none;
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    color: #6b7280;
    transition: color 0.2s ease;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.close-modal:hover {
    color: #dc3545;
}

.cancel-modal-content h3 {
    font-size: 15px;
    font-weight: 800;
    margin: 0 0 8px 0;
    color: #130325;
}

.cancel-modal-content p {
    margin-bottom: 10px;
    color: #4b5563;
    font-size: 12px;
    font-weight: 600;
}

.cancel-modal-buttons {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    margin-top: 14px;
}

/* Make modal buttons compact */
.cancel-modal-buttons .btn { 
    padding: 8px 14px; 
    font-weight: 600; 
    font-size: 12px;
}
.cancel-modal-buttons .btn.btn-danger { 
    padding: 8px 14px; 
    font-size: 12px;
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
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
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
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
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

.product-selection-popup {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 10001;
    backdrop-filter: blur(4px);
}

.product-selection-content {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    max-width: 500px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
    animation: slideIn 0.2s ease;
}

@keyframes slideIn {
    from { transform: translateY(-20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.product-selection-header {
    background: var(--primary-dark);
    color: #ffffff;
    padding: 16px 20px;
    border-radius: 12px 12px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.product-selection-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
    letter-spacing: -0.3px;
}

.product-selection-header h3 i {
    color: var(--accent-yellow);
}

.close-popup {
    background: none;
    border: none;
    color: #ffffff;
    font-size: 20px;
    cursor: pointer;
    padding: 4px;
    border-radius: 6px;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.close-popup:hover {
    background: rgba(255, 255, 255, 0.15);
}

.product-selection-body {
    padding: 20px;
}

.product-selection-body p {
    color: var(--text-dark);
    font-size: 14px;
    margin-bottom: 16px;
    font-weight: 500;
    line-height: 1.5;
}

.product-checkboxes {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.product-checkbox-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    border: 1px solid var(--border-light);
    border-radius: 8px;
    transition: all 0.2s ease;
    cursor: pointer;
    background: var(--bg-white);
}

.product-checkbox-item:hover {
    border-color: var(--primary-dark);
    background: var(--bg-light);
}

.product-checkbox-item input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--primary-dark);
    cursor: pointer;
}

.product-checkbox-item.selected {
    border-color: var(--primary-dark);
    background: rgba(19, 3, 37, 0.05);
}

.product-checkbox-info {
    flex: 1;
    min-width: 0;
}

.product-checkbox-info h5 {
    margin: 0 0 4px 0;
    color: var(--text-dark);
    font-size: 14px;
    font-weight: 600;
    line-height: 1.4;
}

.product-checkbox-info p {
    margin: 0;
    color: var(--text-light);
    font-size: 12px;
}

.product-checkbox-image {
    width: 50px;
    height: 50px;
    object-fit: cover;
    border-radius: 8px;
    border: 1px solid var(--border-light);
    flex-shrink: 0;
}

.product-selection-footer {
    padding: 16px 20px;
    border-top: 1px solid var(--border-light);
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    background: var(--bg-light);
}

.product-selection-footer .btn {
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 13px;
}

.product-selection-footer .btn-secondary {
    background: var(--text-light);
    color: #ffffff;
}

.product-selection-footer .btn-secondary:hover {
    background: #4b5563;
    transform: translateY(-1px);
}

.product-selection-footer .btn-primary {
    background: var(--primary-dark);
    color: #ffffff;
}

.product-selection-footer .btn-primary:hover {
    background: #0f0220;
    transform: translateY(-1px);
}

.delivered-products {
    animation-delay: 0.4s;
}
.order-status.return_approved { 
    background: rgba(0,123,255,0.2); 
    color:#007bff; 
    border-color:#007bff; 
}

.order-status.return_rejected { 
    background: rgba(220,53,69,0.2); 
    color:#dc3545; 
    border-color:#dc3545; 
}

.order-status.return_completed { 
    background: rgba(40,167,69,0.2); 
    color:#28a745; 
    border-color:#28a745; 
}

/* Return Request Status Badges */
.status-return-pending {
    background: rgba(255, 193, 7, 0.2);
    color: #ffc107;
    border: 1px solid #ffc107;
}

.status-return-approved {
    background: rgba(0, 123, 255, 0.2);
    color: #007bff;
    border: 1px solid #007bff;
}

.status-return-rejected {
    background: rgba(220, 53, 69, 0.2);
    color: #dc3545;
    border: 1px solid #dc3545;
}

.status-return-completed {
    background: rgba(40, 167, 69, 0.2);
    color: #28a745;
    border: 1px solid #28a745;
}
.confirm-dialog {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    backdrop-filter: blur(4px);
}

.confirm-content {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    padding: 24px;
    max-width: 400px;
    width: 90%;
    text-align: center;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    animation: confirmSlideIn 0.2s ease;
}

@keyframes confirmSlideIn {
    from {
        opacity: 0;
        transform: translateY(-20px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.confirm-title {
    color: var(--text-dark);
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 12px;
    letter-spacing: -0.3px;
}

.confirm-message {
    color: var(--text-light);
    font-size: 14px;
    margin-bottom: 20px;
    line-height: 1.5;
}

.confirm-buttons {
    display: flex;
    gap: 10px;
    justify-content: center;
}

.confirm-btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    min-width: 90px;
}

.confirm-btn-yes {
    background: var(--error-red);
    color: white;
    border: 1px solid var(--error-red);
}

.confirm-btn-yes:hover {
    background: #dc2626;
    border-color: #dc2626;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.2);
}

.confirm-btn-no {
    background: var(--text-light);
    color: white;
    border: 1px solid var(--text-light);
}


.return-details-section {
    background: var(--bg-light);
    border: 1px solid var(--border-light);
    border-radius: 10px;
    padding: 14px;
    margin: 12px 0;
}

.return-details-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border-light);
}

.return-details-header i {
    color: var(--accent-yellow);
    font-size: 16px;
}

.return-details-header h4 {
    margin: 0;
    color: var(--text-dark);
    font-size: 14px;
    font-weight: 700;
}

.return-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 10px;
    margin-bottom: 10px;
}

.return-info-item {
    background: var(--bg-white);
    padding: 10px;
    border-radius: 8px;
    border: 1px solid var(--border-light);
}

.return-info-label {
    font-size: 11px;
    color: var(--text-light);
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-bottom: 4px;
    font-weight: 600;
}

.return-info-value {
    font-size: 13px;
    color: var(--text-dark);
    font-weight: 600;
}

.return-action-notice {
    padding: 10px 12px;
    border-radius: 8px;
    margin-top: 10px;
    font-size: 13px;
    border-left: 4px solid;
}

.return-action-notice.approved {
    background: #d1fae5;
    border-left-color: var(--success-green);
    border: 1px solid #a7f3d0;
    color: #065f46;
}

.return-action-notice.rejected {
    background: #fef2f2;
    border-left-color: var(--error-red);
    border: 1px solid #fecaca;
    color: #991b1b;
}

.return-action-notice i {
    margin-right: 6px;
}
.confirm-btn-no:hover {
    background: #5a6268;
    border-color: #5a6268;
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
}

/* Return Status Badge Styles */
.return-status-indicator {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 215, 54, 0.2);
    border-radius: 8px;
    padding: 12px;
    margin: 10px 0;
}

.return-status-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.return-status-header i {
    color: #FFD736;
    font-size: 1.1rem;
}

.return-status-title {
    font-weight: 600;
    color: #F9F9F9;
    font-size: 0.95rem;
}

.return-status-details {
    padding-left: 24px;
    font-size: 0.85rem;
    color: rgba(249, 249, 249, 0.8);
}

.return-status-details div {
    margin: 4px 0;
}

.return-status-details strong {
    color: #FFD736;
    margin-right: 6px;
}

.status-badge-large {
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: inline-block;
    margin: 4px 0;
}

.status-return-pending {
    background: rgba(255, 193, 7, 0.2);
    color: #ffc107;
    border: 1px solid rgba(255, 193, 7, 0.4);
}

.status-return-approved {
    background: rgba(40, 167, 69, 0.2);
    color: #28a745;
    border: 1px solid rgba(40, 167, 69, 0.4);
}

.status-return-rejected {
    background: rgba(220, 53, 69, 0.2);
    color: #dc3545;
    border: 1px solid rgba(220, 53, 69, 0.4);
}

.status-return-completed {
    background: rgba(23, 162, 184, 0.2);
    color: #17a2b8;
    border: 1px solid rgba(23, 162, 184, 0.4);
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
    // Find all alert messages (including floating toasts)
    const alerts = document.querySelectorAll('.alert, .alert-success, .alert-error, .alert-warning, .success-message, .error-message, .floating-toast');
    
    alerts.forEach(function(alert) {
        // Add fade-out animation styles if not already present
        if (!alert.style.transition) {
            alert.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        }
        
        // Set timer to fade out after 4 seconds
        setTimeout(function() {
            // Add fade-out effect
            alert.style.opacity = '0';
            // If floating toast, slide down slightly; else slide up
            const isFloating = alert.classList.contains('floating-toast') || (alert.style.position === 'fixed');
            alert.style.transform = isFloating ? 'translate(-50%, 10px)' : 'translateY(-20px)';
            
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
            const isFloating = this.classList.contains('floating-toast') || (this.style.position === 'fixed');
            this.style.opacity = '0';
            this.style.transform = isFloating ? 'translate(-50%, 10px)' : 'translateY(-20px)';
            
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
            if (e.target.classList.contains('close-modal') || e.target.hasAttribute('data-close-cancel-modal') || e.target === document.getElementById('cancelModal')) {
                this.closeCancelModal();
            }

            // Handle direct cancel form submissions from status grid (no extra confirm)
            if (e.target.type === 'submit' && e.target.textContent.includes('Cancel Order')) {
                // submit immediately
                return;
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
            
            let status = this.getAttribute('data-status');
            
            // Map 'to_receive' back to 'shipped' for URL (or we can keep it as 'to_receive')
            // For now, keep it as is
            
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
function openCancelModal(orderId) {
    const orderElement = document.querySelector(`[data-order-id="${orderId}"]`);
    const orderNumberText = orderElement.querySelector('.order-number').textContent;
    
    document.getElementById('cancelOrderId').value = orderId;
    document.getElementById('cancelOrderNumber').textContent = orderNumberText.replace('Order ', '');
    document.getElementById('cancelModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
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
// Order Received Confirmation with Rating Modal
function confirmOrderReceived(orderId, productId) {
    // Create rating modal first
    const modal = document.createElement('div');
    modal.id = 'orderReceivedModal';
    modal.style.cssText = 'display: flex; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center;';
    
    const modalContent = document.createElement('div');
    modalContent.style.cssText = 'background: #ffffff; border-radius: 8px; padding: 0; max-width: 320px; width: 85%; box-shadow: 0 10px 40px rgba(0,0,0,0.2); animation: slideDown 0.3s ease;';
    
    const modalHeader = document.createElement('div');
    modalHeader.style.cssText = 'background: #130325; color: #ffffff; padding: 10px 14px; border-radius: 8px 8px 0 0; display: flex; align-items: center; gap: 8px;';
    modalHeader.innerHTML = '<i class="fas fa-star" style="font-size: 14px; color: #FFD736;"></i><h3 style="margin: 0; font-size: 13px; font-weight: 600;">Rate Your Product</h3>';
    
    const modalBody = document.createElement('div');
    modalBody.style.cssText = 'padding: 14px; color: #130325;';
    
    // Star rating HTML
    let ratingHTML = '<p style="margin: 0 0 12px; font-size: 12px; line-height: 1.4; color: #130325;">How would you rate your experience with this product?</p>';
    ratingHTML += '<div style="display: flex; justify-content: center; gap: 6px; margin-bottom: 12px; flex-wrap: wrap;">';
    for (let i = 1; i <= 5; i++) {
        ratingHTML += `<input type="radio" id="modalStar${i}" name="modalRating" value="${i}" style="display: none;">
        <label for="modalStar${i}" class="modal-star-label" data-rating="${i}" style="font-size: 24px; color: #ddd; cursor: pointer; transition: all 0.2s ease; user-select: none;">★</label>`;
    }
    ratingHTML += '</div>';
    ratingHTML += '<div id="ratingText" style="text-align: center; font-size: 11px; color: #6b7280; min-height: 16px; margin-bottom: 8px;"></div>';
    
    modalBody.innerHTML = ratingHTML;
    
    const modalFooter = document.createElement('div');
    modalFooter.style.cssText = 'padding: 10px 14px; border-top: 1px solid #e5e7eb; display: flex; gap: 8px; justify-content: flex-end;';
    
    const cancelBtn = document.createElement('button');
    cancelBtn.textContent = 'Skip';
    cancelBtn.style.cssText = 'padding: 6px 14px; background: #f3f4f6; color: #130325; border: 1px solid #e5e7eb; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 12px; transition: all 0.2s ease;';
    cancelBtn.onmouseover = function() { this.style.background = '#e5e7eb'; };
    cancelBtn.onmouseout = function() { this.style.background = '#f3f4f6'; };
    
    const confirmBtn = document.createElement('button');
    confirmBtn.textContent = 'Confirm Review';
    confirmBtn.id = 'confirmReviewBtn';
    confirmBtn.style.cssText = 'padding: 6px 14px; background: #130325; color: #ffffff; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 12px; transition: all 0.2s ease; opacity: 0.6; cursor: not-allowed;';
    confirmBtn.disabled = true;
    
    let selectedRating = 0;
    
    // Star rating interaction
    const starLabels = modalBody.querySelectorAll('.modal-star-label');
    const ratingText = modalBody.querySelector('#ratingText');
    const ratingMessages = {
        1: 'Poor',
        2: 'Fair',
        3: 'Good',
        4: 'Very Good',
        5: 'Excellent'
    };
    
    starLabels.forEach(label => {
        label.addEventListener('mouseenter', function() {
            const rating = parseInt(this.getAttribute('data-rating'));
            highlightStars(rating);
        });
        
        label.addEventListener('click', function() {
            selectedRating = parseInt(this.getAttribute('data-rating'));
            highlightStars(selectedRating);
            ratingText.textContent = ratingMessages[selectedRating];
            confirmBtn.disabled = false;
            confirmBtn.style.opacity = '1';
            confirmBtn.style.cursor = 'pointer';
        });
    });
    
    modalBody.addEventListener('mouseleave', function() {
        if (selectedRating > 0) {
            highlightStars(selectedRating);
        } else {
            highlightStars(0);
        }
    });
    
    function highlightStars(rating) {
        starLabels.forEach((label, index) => {
            const starRating = parseInt(label.getAttribute('data-rating'));
            if (starRating <= rating) {
                label.style.color = '#FFD736';
            } else {
                label.style.color = '#ddd';
            }
        });
    }
    
    cancelBtn.onclick = function() {
        // Skip rating, just confirm order received
        confirmOrderReceivedDirect(orderId);
        document.body.removeChild(modal);
        document.body.style.overflow = '';
    };
    
    confirmBtn.onclick = function() {
        if (selectedRating === 0) {
            alert('Please select a rating before confirming.');
            return;
        }
        
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Processing...';
        
        // First confirm order received
        fetch('ajax/confirm-order-received.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                order_id: orderId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.body.removeChild(modal);
                document.body.style.overflow = '';
                
                // Redirect to rate product page with rating
                if (productId) {
                    window.location.href = 'rate-order.php?id=' + orderId + '&rating=' + selectedRating;
                } else {
                    window.location.href = 'user-dashboard.php?status=completed';
                }
            } else {
                alert('Error: ' + (data.message || 'Failed to confirm order'));
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Confirm Review';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error confirming order. Please try again.');
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Confirm Review';
        });
    };
    
    modalFooter.appendChild(cancelBtn);
    modalFooter.appendChild(confirmBtn);
    
    modalContent.appendChild(modalHeader);
    modalContent.appendChild(modalBody);
    modalContent.appendChild(modalFooter);
    modal.appendChild(modalContent);
    
    document.body.appendChild(modal);
    document.body.style.overflow = 'hidden';
    
    // Close on background click
    modal.onclick = function(e) {
        if (e.target === modal) {
            document.body.removeChild(modal);
            document.body.style.overflow = '';
        }
    };
    
    // Add CSS animation if not exists
    if (!document.getElementById('orderReceivedModalStyles')) {
        const style = document.createElement('style');
        style.id = 'orderReceivedModalStyles';
        style.textContent = `
            @keyframes slideDown { 
                from { opacity: 0; transform: translateY(-20px); } 
                to { opacity: 1; transform: translateY(0); } 
            }
            .modal-star-label:hover {
                transform: scale(1.2);
            }
        `;
        document.head.appendChild(style);
    }
}

// Direct order received confirmation (without rating)
function confirmOrderReceivedDirect(orderId) {
    fetch('ajax/confirm-order-received.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            order_id: orderId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showOrderReceivedSuccess(data.message);
            setTimeout(() => {
                window.location.href = 'user-dashboard.php?status=completed';
            }, 1500);
        } else {
            alert('Error: ' + (data.message || 'Failed to confirm order'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error confirming order. Please try again.');
    });
}

// Show success notification
function showOrderReceivedSuccess(message) {
    const notification = document.createElement('div');
    notification.style.cssText = 'position: fixed; left: 50%; bottom: 24px; transform: translateX(-50%); background: #ffffff; color: #130325; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px 18px; z-index: 10001; box-shadow: 0 10px 30px rgba(0,0,0,0.2); max-width: 90%; width: 520px; text-align: center; animation: slideInUp 0.3s ease;';
    notification.innerHTML = `
        <div style="font-weight: 600;">${message}</div>
    `;
    
    document.body.appendChild(notification);
    
    // Remove after 3 seconds
    setTimeout(() => {
        notification.style.animation = 'slideOutDown 0.3s ease forwards';
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }, 3000);
    
    // Add animations if not exists
    if (!document.getElementById('orderReceivedNotificationStyles')) {
        const style = document.createElement('style');
        style.id = 'orderReceivedNotificationStyles';
        style.textContent = `
            @keyframes slideInUp {
                from { opacity: 0; transform: translate(-50%, 20px); }
                to { opacity: 1; transform: translate(-50%, 0); }
            }
            @keyframes slideOutDown {
                from { opacity: 1; transform: translate(-50%, 0); }
                to { opacity: 0; transform: translate(-50%, 20px); }
            }
        `;
        document.head.appendChild(style);
    }
}

// Scroll to specific order when page loads with hash
document.addEventListener('DOMContentLoaded', function() {
    // Check if there's a hash in the URL (e.g., #order-123)
    if (window.location.hash) {
        const hash = window.location.hash;
        const match = hash.match(/#order-(\d+)/);
        
        if (match) {
            const orderId = match[1];
            
            // Wait for page to fully render
            setTimeout(function() {
                // Find the order card
                const orderCard = document.querySelector(`.order-card[data-order-id="${orderId}"]`);
                
                if (orderCard) {
                    // Scroll to the order with smooth animation
                    orderCard.scrollIntoView({ 
                        behavior: 'smooth', 
                        block: 'center' 
                    });
                    
                    // Add a highlight effect
                    orderCard.style.transition = 'all 0.3s ease';
                    orderCard.style.boxShadow = '0 0 20px rgba(255, 215, 54, 0.6)';
                    orderCard.style.transform = 'scale(1.02)';
                    
                    // Remove highlight after 2 seconds
                    setTimeout(function() {
                        orderCard.style.boxShadow = '';
                        orderCard.style.transform = '';
                    }, 2000);
                }
            }, 500); // Wait 500ms for page to render
        }
    }
});

</script>

<!-- Display cancel message if exists -->
<?php if (isset($cancelMessage)): ?>
    <?php echo $cancelMessage; ?>
<?php endif; ?>

<div class="dashboard-layout">
    <div class="dashboard-main">
        <div class="page-header">
            <h1 class="page-title">My Orders</h1>
        </div>
        
        <!-- Filter Container -->
        <div class="filter-container">
            <div class="filter-tabs">
                <?php 
                $currentFilter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'completed';
                // Map 'shipped' and 'delivered' to 'to_receive' for filter tab
                if ($currentFilter === 'shipped' || $currentFilter === 'delivered') {
                    $currentFilter = 'to_receive';
                }
                ?>
                <a href="#" class="filter-tab <?php echo $currentFilter === 'pending' ? 'active' : ''; ?>" data-status="pending">PENDING</a>
                <a href="#" class="filter-tab <?php echo $currentFilter === 'processing' ? 'active' : ''; ?>" data-status="processing">TO SHIP</a>
                <a href="#" class="filter-tab <?php echo $currentFilter === 'to_receive' ? 'active' : ''; ?>" data-status="to_receive">TO RECEIVE</a>
                <a href="#" class="filter-tab <?php echo $currentFilter === 'completed' ? 'active' : ''; ?>" data-status="completed">COMPLETED</a>
                <a href="#" class="filter-tab <?php echo $currentFilter === 'cancelled' ? 'active' : ''; ?>" data-status="cancelled">CANCELLED</a>
                <a href="#" class="filter-tab <?php echo $currentFilter === 'return_requested' ? 'active' : ''; ?>" data-status="return_requested">REFUNDED/RETURNS</a>
            </div>
        </div>
    
        <div class="user-orders">
        <?php if (empty($orders)): ?>
            <div class="no-orders">
                <p>You haven't placed any orders yet.</p>
                <a href="products.php" class="btn btn-primary">Start Shopping</a>
            </div>
        <?php else: ?>
                <?php 
                $filter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'completed';
                $hasFilteredOrders = false;

                // First pass: check if there are any orders matching the filter
                if ($filter === 'return_requested') {
                    $hasFilteredOrders = !empty($returnRequestedOrders);
                } elseif ($filter === 'to_receive') {
                    // TO RECEIVE shows both 'shipped' and 'delivered' status
                    foreach ($orders as $order) {
                        $orderStatusKey = strtolower($order['status']);
                        if ($orderStatusKey === 'shipped' || $orderStatusKey === 'delivered') {
                            $hasFilteredOrders = true;
                            break;
                        }
                    }
                } else {
                    foreach ($orders as $order) {
                        $orderStatusKey = strtolower($order['status']);
                        if ($filter === $orderStatusKey) {
                            $hasFilteredOrders = true;
                            break;
                        }
                    }
                }
                
        if (!$hasFilteredOrders): ?>
            <div class="no-orders">
                <p>No <?php echo ucfirst($filter); ?> orders found.</p>
                <a href="products.php" class="btn btn-primary">Continue Shopping</a>
            </div>
            <?php else: ?>
                <?php 
                // Use different order source for return_requested filter
                $displayOrders = ($filter === 'return_requested') ? $returnRequestedOrders : $orders;

                foreach ($displayOrders as $order):
                    $orderStatusKey = strtolower($order['status']);
                    
                    // For non-return_requested filters, skip if status doesn't match
                    if ($filter === 'return_requested') {
                        // Already filtered
                    } elseif ($filter === 'to_receive') {
                        // TO RECEIVE shows both 'shipped' and 'delivered'
                        if ($orderStatusKey !== 'shipped' && $orderStatusKey !== 'delivered') {
                            continue;
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
                        <div class="order-date">
                            <?php 
                            $createdAt = $order['created_at'] ?? ($order['order_date'] ?? null);
                            $updatedAt = $order['updated_at'] ?? null;
                            $chosenDate = $createdAt;
                            if (!empty($updatedAt) && strtotime($updatedAt) > strtotime((string)$createdAt)) {
                                $chosenDate = $updatedAt;
                            }
                            if ($chosenDate) {
                                $dateTime = new DateTime($chosenDate);
                                echo $dateTime->format('M d, Y h:i A');
                            }
                            ?>
                        </div>
                    </div>
                    <div class="order-status-wrapper">
                        <span class="order-status status-<?php echo strtolower($order['status']); ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $order['status'])); ?>
                        </span>
                        <?php if ($filter === 'return_requested' && isset($order['return_status'])): ?>
                            <span class="order-status status-return-<?php echo strtolower($order['return_status']); ?>" style="font-size: 10px; margin-top: 4px;">
                                Return: <?php echo ucfirst(str_replace('_', ' ', $order['return_status'])); ?>
                            </span>
                        <?php endif; ?>
                    </div>
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
                                        <span>Qty: <?php echo $item['quantity']; ?></span>
                                        <span>•</span>
                                        <span class="item-price-info">
                                            <?php if (isset($item['original_price']) && $item['original_price'] != $item['price']): ?>
                                                <span style="text-decoration: line-through; color: var(--text-light); font-size: 11px;">₱<?php echo number_format($item['original_price'], 2); ?></span>
                                                <span style="color: var(--success-green); font-weight: 600;">₱<?php echo number_format($item['price'], 2); ?></span>
                                            <?php else: ?>
                                                <span style="color: var(--text-dark); font-weight: 600;">₱<?php echo number_format($item['price'], 2); ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Bottom: PRICE | BUTTONS -->
                <div class="order-bottom">
                    <div class="order-total">
                        <?php 
                        $displayTotal = isset($order['total_amount']) ? (float)$order['total_amount'] : 0.0;
                        if ($displayTotal <= 0 && !empty($order['order_items'])) {
                            $sum = 0.0;
                            foreach ($order['order_items'] as $it) {
                                $price = isset($it['price']) ? (float)$it['price'] : (isset($it['product_price']) ? (float)$it['product_price'] : 0.0);
                                $qty = (int)($it['quantity'] ?? 0);
                                $sum += $price * $qty;
                            }
                            $displayTotal = $sum;
                        }
                        ?>
                        <div class="order-total-amount">₱<?php echo number_format($displayTotal, 2); ?></div>
                    </div>
                    
                    <div class="order-actions">
    <?php if (strtolower($order['status']) === 'completed'): ?>
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
        // Determine whether to show "Rate Product" or "Buy Again" button
        // Logic:
        // 1. If user has already reviewed the product → Show "Buy Again"
        // 2. If 7 days have passed since completed_at → Show "Buy Again"
        // 3. Otherwise → Show "Rate Product"
        
        $hasReviewed = hasUserReviewedProduct($pdo, $userId, $order['id']);
        $showBuyAgain = false;
        
        if ($hasReviewed) {
            // User already reviewed → Show Buy Again
            $showBuyAgain = true;
        } else if (!empty($order['completed_at'])) {
            // Check if 7 days have passed since order was marked completed
            $completedDate = new DateTime($order['completed_at']);
            $currentDate = new DateTime();
            $daysSinceCompleted = $currentDate->diff($completedDate)->days;
            
            if ($daysSinceCompleted >= 7) {
                // 7 days passed → Show Buy Again
                $showBuyAgain = true;
            }
        }
        ?>
        <?php if ($showBuyAgain): ?>
            <a href="product-detail.php?id=<?php echo $order['order_items'][0]['product_id']; ?>" class="btn btn-warning">
                <i class="fas fa-shopping-cart"></i> Buy Again
            </a>
        <?php else: ?>
            <a href="product-detail.php?id=<?php echo $order['order_items'][0]['product_id']; ?>#reviews-tab" class="btn btn-secondary">
                <i class="fas fa-star"></i> Rate Product
            </a>
        <?php endif; ?>
        
    <?php elseif (strtolower($order['status']) === 'return_requested' || 
                  in_array(strtolower($order['status']), ['return_approved', 'return_rejected', 'return_completed'])): ?>
        <!-- RETURN REQUESTED/PROCESSED ORDERS -->
        <?php 
        $returnDetails = getOrderReturnDetails($order['id']);
        if ($returnDetails): 
        ?>
            <div class="return-details-section">
                <div class="return-details-header">
                    <i class="fas fa-info-circle"></i>
                    <h4>Return Request Details</h4>
                </div>
                
                <div class="return-info-grid">
                    <div class="return-info-item">
                        <div class="return-info-label">Status</div>
                        <div class="return-info-value">
                            <span class="status-badge-large status-return-<?php echo htmlspecialchars($returnDetails['status']); ?>">
                                <?php 
                                $statusLabels = [
                                    'pending' => 'Pending Review',
                                    'approved' => 'Approved',
                                    'rejected' => 'Rejected',
                                    'completed' => 'Completed'
                                ];
                                echo $statusLabels[$returnDetails['status']] ?? ucfirst($returnDetails['status']);
                                ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="return-info-item">
                        <div class="return-info-label">Requested Date</div>
                        <div class="return-info-value">
                            <?php echo date('M d, Y', strtotime($returnDetails['created_at'])); ?>
                        </div>
                    </div>
                    
                    <?php if ($returnDetails['processed_at']): ?>
                    <div class="return-info-item">
                        <div class="return-info-label">Processed Date</div>
                        <div class="return-info-value">
                            <?php echo date('M d, Y', strtotime($returnDetails['processed_at'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($returnDetails['items_count']): ?>
                    <div class="return-info-item">
                        <div class="return-info-label">Items Returned</div>
                        <div class="return-info-value">
                            <?php echo $returnDetails['items_count']; ?> item(s)
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($returnDetails['reason']): ?>
                <div class="return-info-item" style="margin-bottom: 10px;">
                    <div class="return-info-label">Return Reason</div>
                    <div class="return-info-value" style="font-weight: normal;">
                        <?php echo nl2br(htmlspecialchars($returnDetails['reason'])); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($returnDetails['status'] === 'approved'): ?>
                <div class="return-action-notice approved">
                    <i class="fas fa-check-circle"></i>
                    <strong>Return Approved!</strong> Your refund is being processed and will be completed within 3-5 business days. 
                    The refund will be issued to your original payment method.
                </div>
                <?php endif; ?>
                
                <?php if ($returnDetails['status'] === 'rejected'): ?>
                <div class="return-action-notice rejected">
                    <i class="fas fa-times-circle"></i>
                    <strong>Return Request Rejected.</strong>
                    <?php if ($returnDetails['rejection_reason']): ?>
                        <br>Reason: <?php echo htmlspecialchars($returnDetails['rejection_reason']); ?>
                    <?php endif; ?>
                    <br><br>
                    <strong>You can submit a new return request within 7 days of delivery if you believe this was an error.</strong>
                </div>
                <?php endif; ?>
                
                <?php if ($returnDetails['status'] === 'completed'): ?>
                <div class="return-action-notice approved">
                    <i class="fas fa-check-circle"></i>
                    <strong>Refund Completed!</strong> Your refund has been successfully processed. 
                    Please allow 3-5 business days for the amount to reflect in your account.
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <a href="order-details.php?id=<?php echo $order['id']; ?>" class="btn btn-primary">
            <i class="fas fa-eye"></i> View Full Details
        </a>
        
        <?php 
        // Allow re-requesting return if rejected and still within 7 days
        if ($returnDetails && $returnDetails['status'] === 'rejected'): 
            $deliveryDate = new DateTime($order['delivery_date'] ?? $order['created_at']);
            $currentDate = new DateTime();
            $daysSinceDelivery = $currentDate->diff($deliveryDate)->days;
            $canReturnAgain = $daysSinceDelivery <= 7;
            
            if ($canReturnAgain):
        ?>
            <?php if (count($order['order_items']) > 1): ?>
                <button onclick="showProductSelectionPopup(<?php echo $order['id']; ?>)" class="btn btn-warning">
                    <i class="fas fa-redo"></i> Submit New Return Request
                </button>
            <?php else: ?>
                <a href="customer-returns.php?order_id=<?php echo $order['id']; ?>" class="btn btn-warning">
                    <i class="fas fa-redo"></i> Submit New Return Request
                </a>
            <?php endif; ?>
        <?php else: ?>
            <button onclick="showReturnExpiredPopup()" class="btn btn-return-expired" disabled>
                <i class="fas fa-redo"></i> Return Period Expired
            </button>
        <?php endif; ?>
        <?php endif; ?>
        
    <?php elseif (strtolower($order['status']) === 'pending'): ?>
        <a href="order-details.php?id=<?php echo $order['id']; ?>" class="btn btn-primary">
            <i class="fas fa-eye"></i> View Details
        </a>
        <button type="button" class="btn btn-danger" onclick="openCancelModal(<?php echo $order['id']; ?>)">
            <i class="fas fa-times"></i> Cancel Order
        </button>
        
    <?php elseif (strtolower($order['status']) === 'delivered'): ?>
        <!-- DELIVERED ORDERS: Order Received button + View Details -->
        <button type="button" class="btn btn-secondary" onclick="confirmOrderReceived(<?php echo $order['id']; ?>, <?php echo !empty($order['order_items']) && isset($order['order_items'][0]['product_id']) ? $order['order_items'][0]['product_id'] : 'null'; ?>)">
            <i class="fas fa-check-circle"></i> Order Received
        </button>
        <a href="order-details.php?id=<?php echo $order['id']; ?>" class="btn btn-primary">
            <i class="fas fa-eye"></i> View Details
        </a>
    <?php else: ?>
        <!-- For processing and shipped orders -->
        <a href="order-details.php?id=<?php echo $order['id']; ?>" class="btn btn-primary">
            <i class="fas fa-eye"></i> View Details
        </a>
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
                <button type="button" class="btn btn-primary" data-close-cancel-modal="1">Keep Order</button>
                <button type="submit" class="btn btn-danger">Cancel Order</button>
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
