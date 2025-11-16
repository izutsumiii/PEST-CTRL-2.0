<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

// PHPMailer for notifications
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'PHPMailer/PHPMailer/src/Exception.php';
require 'PHPMailer/PHPMailer/src/PHPMailer.php';
require 'PHPMailer/PHPMailer/src/SMTP.php';

// Check if user is logged in and is a seller
if (!isLoggedIn() || !isSeller()) {
    header('Location: login_seller.php');
    exit();
}

$sellerId = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle return request actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['return_id'])) {
        $returnId = (int)$_POST['return_id'];
        $action = $_POST['action'];
        
        try {
            $pdo->beginTransaction();
            
            if ($action === 'approve') {
                // Get return request details for notifications
                $stmt = $pdo->prepare("
                SELECT rr.*, o.id as order_id, u.first_name, u.last_name, u.email as customer_email,
                    p.name as product_name, s.first_name as seller_first_name, s.last_name as seller_last_name
                FROM return_requests rr
                JOIN orders o ON rr.order_id = o.id
                JOIN users u ON rr.customer_id = u.id
                JOIN products p ON rr.product_id = p.id
                JOIN users s ON p.seller_id = s.id
                WHERE rr.id = ? AND rr.seller_id = ?
            ");
                $stmt->execute([$returnId, $sellerId]);
                $returnDetails = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$returnDetails) {
                    throw new Exception("Return request not found or access denied.");
                }
                
                // Approve return request
                $stmt = $pdo->prepare("UPDATE return_requests SET status = 'approved', processed_at = NOW(), processed_by = ? WHERE id = ? AND seller_id = ?");
                $result = $stmt->execute([$sellerId, $returnId, $sellerId]);
                
                if ($result) {
                    // Update order status to return_approved
                    $stmt = $pdo->prepare("UPDATE orders SET status = 'return_approved', refund_status = 'processing' WHERE id = ?");
                    $stmt->execute([$returnDetails['order_id']]);
                    
                    // Create app notification for customer in order_status_history
                    $stmt = $pdo->prepare("
                        INSERT INTO order_status_history (order_id, status, notes, created_at) 
                        VALUES (?, 'return_approved', ?, NOW())
                    ");
                    $notificationMessage = "Your return request for Order #" . str_pad($returnDetails['order_id'], 6, '0', STR_PAD_LEFT) . " has been approved. Refund is being processed.";
                    $stmt->execute([$returnDetails['order_id'], $notificationMessage]);
                    
                    // Create explicit app notification for customer in notifications table
                    if (!empty($returnDetails['customer_id'])) {
                        try {
                            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, order_id, message, type, created_at) VALUES (?, ?, ?, 'success', NOW())");
                            $stmt->execute([$returnDetails['customer_id'], $returnDetails['order_id'], $notificationMessage]);
                        } catch (Exception $e) {
                            error_log("Failed to create return approval notification: " . $e->getMessage());
                        }
                    }
                    
                
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
                        $mail->addAddress($returnDetails['customer_email'], $returnDetails['first_name'] . ' ' . $returnDetails['last_name']);
                        
                        $mail->isHTML(true);
                        $mail->Subject = '✅ Return Request Approved - Order #' . str_pad($returnDetails['order_id'], 6, '0', STR_PAD_LEFT);
                        
                        $mail->Body = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;'>
                            <div style='text-align: center; border-bottom: 2px solid #28a745; padding-bottom: 20px; margin-bottom: 20px;'>
                                <h1 style='color: #28a745; margin: 0;'>✅ Return Request Approved</h1>
                            </div>
                            
                            <p style='color: #666; font-size: 16px;'>Dear {$returnDetails['first_name']},</p>
                            <p style='color: #666; font-size: 16px;'>Great news! Your return request has been approved by the seller.</p>
                            
                            <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                                <h2 style='color: #333; margin-top: 0;'>Return Details</h2>
                                <p style='margin: 10px 0;'><strong>Order Number:</strong> #" . str_pad($returnDetails['order_id'], 6, '0', STR_PAD_LEFT) . "</p>
                                <p style='margin: 10px 0;'><strong>Product:</strong> " . htmlspecialchars($returnDetails['product_name']) . "</p>
                                <p style='margin: 10px 0;'><strong>Status:</strong> <span style='color: #28a745; font-weight: bold;'>APPROVED</span></p>
                                <p style='margin: 10px 0;'><strong>Processed:</strong> " . date('F j, Y g:i A') . "</p>
                            </div>
                            
                            <div style='background: #d1ecf1; border-left: 4px solid #17a2b8; padding: 15px; margin: 20px 0;'>
                                <p style='color: #0c5460; margin: 0;'>Your refund is now being processed. You should receive your refund within 3-5 business days.</p>
                            </div>
                            
                            <div style='text-align: center; border-top: 1px solid #eee; padding-top: 20px; margin-top: 30px; color: #999; font-size: 14px;'>
                                <p>This is an automated notification from your E-Commerce Store</p>
                            </div>
                        </div>";
                        
                        $mail->send();
                        
                    } catch (Exception $e) {
                        error_log("Return approval email failed: {$mail->ErrorInfo}");
                    }
                    
                    $message = "Return request approved successfully. Customer has been notified.";
                }
            } 
            
            elseif ($action === 'reject') {
                $rejectionReason = $_POST['rejection_reason'] ?? '';
                
                // Get return request details and customer id
                $stmt = $pdo->prepare("SELECT rr.order_id, o.user_id as customer_id FROM return_requests rr JOIN orders o ON rr.order_id = o.id WHERE rr.id = ? AND rr.seller_id = ?");
                $stmt->execute([$returnId, $sellerId]);
                $returnDetails = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$returnDetails) {
                    throw new Exception("Return request not found or access denied.");
                }
                
                // Reject return request
                $stmt = $pdo->prepare("UPDATE return_requests SET status = 'rejected', rejection_reason = ?, processed_at = NOW(), processed_by = ? WHERE id = ? AND seller_id = ?");
                $result = $stmt->execute([$rejectionReason, $sellerId, $returnId, $sellerId]);
                
                if ($result) {
                    // Update order status to return_rejected
                    $stmt = $pdo->prepare("UPDATE orders SET status = 'return_rejected' WHERE id = ?");
                    $stmt->execute([$returnDetails['order_id']]);
                    
                    // Create notification in order_status_history
                    $stmt = $pdo->prepare("
                        INSERT INTO order_status_history (order_id, status, notes, created_at) 
                        VALUES (?, 'return_rejected', ?, NOW())
                    ");
                    $notificationMessage = "Your return request has been rejected. Reason: " . $rejectionReason;
                    $stmt->execute([$returnDetails['order_id'], $notificationMessage]);

                    // Create explicit app notification for customer
                    if (!empty($returnDetails['customer_id'])) {
                        try {
                            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, order_id, message, type, created_at) VALUES (?, ?, ?, 'warning', NOW())");
                            $stmt->execute([$returnDetails['customer_id'], $returnDetails['order_id'], $notificationMessage]);
                        } catch (Exception $e) { /* ignore */ }
                    }
                    
                    $message = "Return request rejected.";
                }
            }
            
            
           elseif ($action === 'complete_refund') {
            // Get return request details
            $stmt = $pdo->prepare("SELECT rr.order_id, o.user_id as customer_id FROM return_requests rr JOIN orders o ON rr.order_id = o.id WHERE rr.id = ? AND rr.seller_id = ?");
            $stmt->execute([$returnId, $sellerId]);  
            $returnDetails = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$returnDetails) {
                throw new Exception("Return request not found or access denied.");
            }
            
            // Complete the refund process
            $stmt = $pdo->prepare("UPDATE return_requests SET status = 'completed', processed_at = NOW(), processed_by = ? WHERE id = ? AND seller_id = ?");
            $result = $stmt->execute([$sellerId, $returnId, $sellerId]);
            
            if ($result) {
                // Update order status to return_completed
                $stmt = $pdo->prepare("UPDATE orders SET status = 'return_completed', refund_status = 'completed' WHERE id = ?");
                $stmt->execute([$returnDetails['order_id']]);
                
                // Restore product stock
                $stmt = $pdo->prepare("
                    SELECT oi.product_id, oi.quantity 
                    FROM order_items oi 
                    JOIN return_requests rr ON oi.order_id = rr.order_id 
                    WHERE rr.id = ?
                ");
                $stmt->execute([$returnId]);
                $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($orderItems as $item) {
                    $stmt = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?");
                    $stmt->execute([$item['quantity'], $item['product_id']]);
                }
                
                // Create notification
                $stmt = $pdo->prepare("
                    INSERT INTO order_status_history (order_id, status, notes, created_at) 
                    VALUES (?, 'return_completed', ?, NOW())
                ");
                $notificationMessage = "Your refund has been completed successfully.";
                $stmt->execute([$returnDetails['order_id'], $notificationMessage]);

                // Explicit app notification for customer
                if (!empty($returnDetails['customer_id'])) {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, order_id, message, type, created_at) VALUES (?, ?, ?, 'success', NOW())");
                        $stmt->execute([$returnDetails['customer_id'], $returnDetails['order_id'], $notificationMessage]);
                    } catch (Exception $e) { /* ignore */ }
                }
                
                $message = "Refund completed and stock restored.";
            }
        }
            
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollback();
            $error = "Error processing return request: " . $e->getMessage();
        }
    }
}
// After return request is successfully created

$stmt = $pdo->prepare("
    SELECT 
        rr.*,
        o.id as order_id,
        o.total_amount,
        o.created_at as order_date,
        CONCAT(u.first_name, ' ', u.last_name) as customer_name,
        u.email as customer_email,
        COALESCE(p.name, 'Product Deleted') as product_name,
        COALESCE(p.image_url, '') as product_image,
        COALESCE(rr.quantity, COALESCE(oi.quantity, 1)) as quantity,
        COALESCE(oi.price, 0) as item_price
    FROM return_requests rr
    JOIN orders o ON rr.order_id = o.id
    JOIN users u ON o.user_id = u.id
    LEFT JOIN order_items oi ON o.id = oi.order_id AND rr.product_id = oi.product_id
    LEFT JOIN products p ON rr.product_id = p.id
    WHERE rr.seller_id = ?
    ORDER BY rr.created_at DESC
");
$stmt->execute([$sellerId]);
$returnRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_requests,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_requests,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_requests,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_requests,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_requests
    FROM return_requests 
    WHERE seller_id = ?
");
$statsStmt->execute([$sellerId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Set default numbers if no data exists
$stats['total_requests'] = $stats['total_requests'] ?? 0;
$stats['pending_requests'] = $stats['pending_requests'] ?? 0;
$stats['approved_requests'] = $stats['approved_requests'] ?? 0;
$stats['completed_requests'] = $stats['completed_requests'] ?? 0;

include 'includes/seller_header.php';
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

/* Body and Main Container */
body {
    background: var(--bg-light) !important;
    min-height: 100vh;
    color: var(--text-dark);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}

.main-content {
    background: var(--bg-light) !important;
    min-height: 100vh;
    padding: 12px;
    margin-top: 5px;
    margin-left: 0;
    transition: margin-left 0.3s ease !important;
}

.main-content.sidebar-collapsed {
    margin-left: 0;
}

.content-wrapper {
    max-width: 1400px;
    margin: 20px auto 0 auto;
    padding: 0 16px;
}


/* Page Header - Aligned with content container */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    width: 100%;
}

/* Page Title */
.page-title {
    color: var(--primary-dark) !important;
    font-size: 1.35rem;
    font-weight: 700 !important;
    margin: 0;
    margin-bottom: 16px !important;
    letter-spacing: -0.3px;
    line-height: 1.2;
    text-shadow: none !important;
}

/* Export Button */
.export-btn {
    padding: 8px 16px;
    background: var(--primary-dark);
    color: var(--bg-white);
    border: 1px solid var(--primary-dark);
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 0.875rem;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}

.export-btn:hover {
    background: #0a0118;
    border-color: #0a0118;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(19, 3, 37, 0.3);
}

/* Alert Messages */
.alert {
    border-radius: 4px;
    padding: 10px 14px;
    margin-bottom: 16px;
    font-weight: 500;
    font-size: 12px;
    border-left: 4px solid;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from { transform: translateY(-20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.alert-success {
    background: #d1fae5 !important;
    color: #065f46 !important;
    border-left-color: var(--success-green) !important;
    border: 1px solid #a7f3d0 !important;
}

.alert-danger {
    background: #fef2f2 !important;
    color: #991b1b !important;
    border-left-color: var(--error-red) !important;
    border: 1px solid #fecaca !important;
}

.alert i {
    font-size: 16px;
    margin-top: 1px;
    flex-shrink: 0;
}

.btn-close {
    background: transparent;
    border: none;
    color: inherit;
    font-size: 1.2rem;
    cursor: pointer;
    opacity: 0.7;
    margin-left: auto;
    padding: 0;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.btn-close:hover {
    opacity: 1;
}

/* Statistics Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}

.stats-card {
    background: var(--bg-white) !important;
    border: 1px solid rgba(19, 3, 37, 0.08) !important;
    border-left: 4px solid var(--primary-dark);
    border-radius: 6px !important;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    min-height: 80px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05), 0 1px 2px rgba(0, 0, 0, 0.1) !important;
    position: relative;
    overflow: hidden;
}

.stats-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: linear-gradient(180deg, var(--primary-dark), var(--accent-yellow));
    transform: scaleY(0);
    transition: transform 0.3s ease;
    transform-origin: bottom;
}

.stats-card:hover {
    transform: translateY(-3px);
    border-color: rgba(19, 3, 37, 0.15) !important;
    box-shadow: 0 4px 16px rgba(19, 3, 37, 0.12), 0 2px 4px rgba(19, 3, 37, 0.08) !important;
}

.stats-card:hover::before {
    transform: scaleY(1);
    transform-origin: top;
}

.stats-content h3 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0 0 4px 0;
    color: var(--text-dark) !important;
    text-align: center;
}

.stats-content p {
    margin: 0;
    color: var(--text-light) !important;
    font-weight: 500;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Card Container - Modern Design */
.card {
    background: var(--bg-white) !important;
    border: 1px solid rgba(19, 3, 37, 0.08) !important;
    border-radius: 6px !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05), 0 1px 2px rgba(0, 0, 0, 0.1) !important;
    margin-bottom: 16px;
    overflow: hidden !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
}

.card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--primary-dark), var(--accent-yellow));
    opacity: 0;
    transition: opacity 0.3s ease;
}

.card:hover {
    box-shadow: 0 4px 16px rgba(19, 3, 37, 0.12), 0 2px 4px rgba(19, 3, 37, 0.08) !important;
    transform: translateY(-2px);
    border-color: rgba(19, 3, 37, 0.15) !important;
}

.card:hover::before {
    opacity: 1;
}

.card-header {
    background: linear-gradient(135deg, var(--primary-dark) 0%, rgba(19, 3, 37, 0.95) 100%) !important;
    padding: 14px 18px;
    border-bottom: none !important;
    border-radius: 6px 6px 0 0;
    position: relative;
}

.card-header::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--accent-yellow), transparent);
    opacity: 0.6;
}

.card-title {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--bg-white) !important;
    display: flex;
    align-items: center;
    gap: 8px;
}

.card-title i {
    color: var(--accent-yellow);
    font-size: 1rem;
}

.card-body {
    padding: 18px 20px;
    background: var(--bg-white) !important;
    overflow: visible !important;
    max-height: none !important;
    height: auto !important;
    position: relative;
}

/* Filter Section - Modern Design */
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
    border-width: 1.5px;
    transform: translateY(-1px);
}

.filter-dropdown select:hover {
    border-color: rgba(19, 3, 37, 0.25);
}

.filter-dropdown select option {
    background: var(--bg-white);
    color: var(--text-dark);
    padding: 6px;
}

/* Table Container */
.table-responsive {
    overflow-x: auto;
    border: 1px solid #dee2e6;
    border-radius: 0.375rem;
}

.table {
    width: 100%;
    margin-bottom: 0;
    background-color: #fff;
    border-collapse: collapse;
}

.table thead th {
    vertical-align: bottom;
    border-bottom: 2px solid var(--accent-yellow);
    background: linear-gradient(135deg, var(--primary-dark) 0%, rgba(19, 3, 37, 0.95) 100%);
    background-image: linear-gradient(135deg, var(--primary-dark) 0%, rgba(19, 3, 37, 0.95) 100%);
    border-top: none;
    padding: 0.75rem;
    font-weight: 600;
    color: var(--bg-white);
    text-align: left;
    border-left: none;
    border-right: none;
    font-size: 0.75rem;
}

.table thead th.sortable {
    position: relative;
    cursor: pointer;
    user-select: none;
}

.table thead th .sort-indicator {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 10px;
    opacity: 0.7;
    color: var(--accent-yellow);
}

.table thead th:hover .sort-indicator {
    opacity: 1;
}

.table thead th.sort-asc .sort-indicator::before {
    content: '↑';
    color: var(--accent-yellow);
}

.table thead th.sort-desc .sort-indicator::before {
    content: '↓';
    color: var(--accent-yellow);
}

.table tbody td {
    padding: 0.75rem;
    vertical-align: middle;
    border-top: 1px solid #dee2e6;
    border-left: none;
    border-right: none;
}

.table tbody tr:hover {
    background-color: rgba(0, 0, 0, 0.075);
}

/* Customer Info */
.customer-info strong {
    color: var(--text-dark) !important;
    font-weight: 600;
    font-size: 0.9rem;
}

.customer-info small {
    color: var(--text-light) !important;
    font-size: 0.75rem;
}

/* Product Info */
.product-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.product-thumb {
    width: 45px;
    height: 45px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid var(--border-light);
}

.product-details strong {
    color: var(--text-dark) !important;
    font-weight: 600;
    font-size: 0.85rem;
    display: block;
    margin-bottom: 3px;
}

.product-details small {
    color: var(--text-light) !important;
    font-size: 0.75rem;
}

/* Status Badges */
.status-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: inline-block;
}

.status-pending {
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fde68a;
}

.status-approved {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}

.status-rejected {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.status-completed {
    background: #dbeafe;
    color: #1e40af;
    border: 1px solid #bfdbfe;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.btn {
    border-radius: 6px;
    font-weight: 600;
    padding: 8px 16px;
    font-size: 0.85rem;
    border: none;
    transition: all 0.3s ease;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-success {
    background: var(--success-green);
    color: var(--bg-white);
}

.btn-success:hover {
    background: #059669;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.btn-danger {
    background: var(--error-red);
    color: var(--bg-white);
}

.btn-danger:hover {
    background: #dc2626;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

.btn-primary {
    background: var(--primary-dark);
    color: var(--bg-white);
}

.btn-primary:hover {
    background: #0a0118;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(19, 3, 37, 0.3);
}

.btn-info {
    background: var(--accent-yellow);
    color: var(--primary-dark);
}

.btn-info:hover {
    background: #FFC107;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(255, 215, 54, 0.3);
}

.btn-sm {
    padding: 6px 12px;
    font-size: 0.8rem;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-light) !important;
    background: var(--bg-white) !important;
    border: 1px solid rgba(19, 3, 37, 0.08);
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    margin-top: 16px;
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: 12px;
    color: var(--border-light) !important;
}

.empty-state h4 {
    color: var(--text-dark) !important;
    margin-bottom: 8px;
    font-size: 1.1rem;
    font-weight: 600;
}

.empty-state p {
    color: var(--text-light) !important;
    font-size: 0.9rem;
}

/* Return Reason */
.return-reason {
    max-width: 200px;
    display: block;
    white-space: normal; /* allow reason to wrap on small screens */
    overflow: visible;
    text-overflow: clip;
    color: #130325 !important;
}

/* Modal Styling */
.modal {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 100% !important;
    height: 100% !important;
    z-index: 9999 !important;
    background-color: rgba(0, 0, 0, 0.7) !important;
    display: none !important;
    align-items: center !important;
    justify-content: center !important;
}

/* Details Modal Specific Styling */
.return-details .table {
    background: #ffffff !important;
    color: #130325 !important;
}

.return-details .table td {
    border-color: #e5e7eb !important;
    padding: 8px 12px;
    color: #130325 !important;
}

.return-details .table td:first-child {
    color: #130325 !important;
    font-weight: 600;
}

.product-info {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px;
    background: #f9fafb !important;
    border-radius: 8px;
    border: 1px solid #e5e7eb !important;
}

.product-details {
    flex: 1;
}

.issue-details {
    padding: 15px;
    background: #f9fafb !important;
    border-radius: 8px;
    border: 1px solid #e5e7eb !important;
}

.description-box {
    background: #f9fafb !important;
    color: #130325 !important;
    padding: 12px;
    border-radius: 6px;
    border-left: 3px solid #FFD736;
    margin-top: 8px;
    white-space: pre-wrap;
    font-style: italic;
}

/* Compact Layout Styles */
.product-info-compact {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px;
    background: #f9fafb !important;
    border-radius: 6px;
    border: 1px solid #e5e7eb !important;
}

.product-image-compact {
    width: 50px;
    height: 50px;
    object-fit: cover;
    border-radius: 6px;
}

.product-details-compact {
    flex: 1;
    font-size: 0.9rem;
    color: #130325 !important;
}

.issue-details-compact {
    padding: 10px;
    background: #f9fafb !important;
    border-radius: 6px;
    border: 1px solid #e5e7eb !important;
    font-size: 0.9rem;
    color: #130325 !important;
}

.description-box-compact {
    background: #f9fafb !important;
    color: #130325 !important;
    padding: 8px;
    border-radius: 4px;
    border-left: 2px solid #FFD736;
    margin-top: 6px;
    white-space: pre-wrap;
    font-style: italic;
    font-size: 0.85rem;
    max-height: 80px;
    overflow-y: auto;
}

.photos-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 15px;
    padding: 15px;
    background: #f9fafb !important;
    border-radius: 8px;
    border: 1px solid #e5e7eb !important;
}

.photo-item {
    position: relative;
    border-radius: 8px;
    overflow: hidden;
    cursor: pointer;
    transition: transform 0.3s ease;
}

.photo-item:hover {
    transform: scale(1.05);
}

.return-photo {
    width: 100%;
    height: 120px;
    object-fit: cover;
    border-radius: 8px;
    border: 2px solid rgba(255, 255, 255, 0.1);
}

.return-photo:hover {
    border-color: #FFD736;
}

/* Badge Styling */
.badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-warning {
    background: #FFD736;
    color: #130325;
}

.badge-success {
    background: #28a745;
    color: white;
}

.badge-danger {
    background: #dc3545;
    color: white;
}

.badge-info {
    background: #17a2b8;
    color: white;
}

.badge-secondary {
    background: #6c757d;
    color: white;
}

/* Approval Notes Wide Textarea */
.approval-notes-wide {
    width: 200% !important;
    max-width: 100% !important;
    resize: vertical;
}

/* Ultra Wide Details Layout */
.return-details-wide {
    font-size: 0.85rem;
    color: #130325 !important;
}

.return-details-wide h6 {
    color: #130325 !important;
    font-weight: 700;
    margin-bottom: 8px;
    border-bottom: 2px solid #FFD736;
    padding-bottom: 4px;
}

.return-details-wide h6 i {
    color: #FFD736 !important;
    margin-right: 6px;
}

/* Compact Modal Styling */
#detailsModal .modal-dialog {
    max-width: 700px !important;
    width: 90vw !important;
    height: auto !important;
    margin: 8vh auto !important;
}

#detailsModal .modal-content {
    border: none !important;
    border-radius: 12px !important;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2) !important;
    overflow: hidden !important;
}

.modal-header-modern {
    background: #130325 !important;
    color: #ffffff !important;
    padding: 16px 20px !important;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.modal-title-modern {
    color: #ffffff !important;
    font-size: 1.1rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.modal-title-modern::before {
    content: "\f05a";
    font-family: "Font Awesome 5 Free";
    font-weight: 900;
    color: #FFD736;
}

.btn-close-modern {
    background: transparent;
    border: none;
    color: #ffffff;
    font-size: 24px;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: all 0.2s ease;
}

.btn-close-modern:hover {
    background: rgba(255,255,255,0.1);
    color: #FFD736;
}

.modal-body-modern {
    padding: 0 !important;
    background: #f9fafb !important;
}

/* Compact Return Details Layout */
.compact-return-details {
    font-size: 0.875rem;
}

.details-header-bar {
    background: #f9fafb;
    padding: 12px 15px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}

.header-info {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
}

.info-item {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 0.8rem;
    color: #6b7280;
}

.info-item i {
    color: #FFD736;
    font-size: 0.75rem;
}

.status-badge-compact {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.status-badge-compact i {
    font-size: 0.7rem;
}

.status-pending {
    background: #fef3c7;
    color: #92400e;
}

.status-approved {
    background: #d1fae5;
    color: #065f46;
}

.status-rejected {
    background: #fee2e2;
    color: #991b1b;
}

.status-completed {
    background: #dbeafe;
    color: #1e40af;
}

.compact-grid {
    padding: 15px;
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.grid-section {
    background: #ffffff;
    padding: 12px;
    border-radius: 6px;
    border: 1px solid #e5e7eb;
}

.grid-section.full-width-section {
    grid-column: 1 / -1;
}

.section-label {
    color: #130325;
    font-weight: 700;
    font-size: 0.8rem;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.section-label i {
    color: #FFD736;
}

.product-compact {
    display: flex;
    gap: 10px;
    align-items: flex-start;
}

.product-thumb {
    width: 50px;
    height: 50px;
    object-fit: cover;
    border-radius: 4px;
    border: 1px solid #e5e7eb;
}

.product-info-compact {
    flex: 1;
}

.product-name-compact {
    font-weight: 600;
    color: #130325;
    font-size: 0.85rem;
    margin-bottom: 4px;
    line-height: 1.3;
}

.product-price-compact {
    color: #10b981;
    font-weight: 600;
    font-size: 0.8rem;
}

.customer-info-compact {
    font-size: 0.85rem;
    color: #130325;
}

.text-muted-compact {
    color: #6b7280;
    font-size: 0.8rem;
    margin-top: 2px;
}

.issue-compact {
    font-size: 0.85rem;
}

.issue-type-compact {
    margin-bottom: 8px;
    color: #130325;
}

.issue-desc-compact {
    background: #f9fafb;
    border-left: 3px solid #FFD736;
    padding: 8px;
    border-radius: 4px;
    color: #130325;
    line-height: 1.5;
    font-size: 0.8rem;
}

.photos-compact {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(70px, 1fr));
    gap: 8px;
}

.loading-compact {
    text-align: center;
    padding: 15px;
    color: #6b7280;
    font-size: 0.85rem;
}

.photo-card-modern {
    position: relative;
    aspect-ratio: 1;
    border-radius: 4px;
    overflow: hidden;
    cursor: pointer;
    border: 1px solid #e5e7eb;
    transition: all 0.2s ease;
}

.photo-card-modern:hover {
    border-color: #FFD736;
    transform: scale(1.05);
}

.photo-img-modern {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.photo-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(19, 3, 37, 0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.2s ease;
}

.photo-card-modern:hover .photo-overlay {
    opacity: 1;
}

.photo-overlay i {
    color: #FFD736;
    font-size: 1.2rem;
}

.no-photos-message,
.error-photos-message {
    text-align: center;
    padding: 20px;
    color: #6b7280;
    font-size: 0.85rem;
}

.no-photos-message i,
.error-photos-message i {
    display: block;
    margin-bottom: 8px;
    font-size: 1.5rem;
}

.error-photos-message {
    color: #ef4444;
}

@media (max-width: 768px) {
    .compact-grid {
        grid-template-columns: 1fr;
    }
    
    .header-info {
        flex-direction: column;
        gap: 6px;
    }
}

.info-grid {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 8px;
    background: #f9fafb !important;
    border-radius: 6px;
    border: 1px solid #e5e7eb !important;
}

.info-grid div {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 2px 0;
    border-bottom: 1px solid #e5e7eb;
    color: #130325 !important;
}

.info-grid div:last-child {
    border-bottom: none;
}

.info-grid strong {
    color: #130325 !important;
    font-size: 0.8rem;
    min-width: 40px;
}

.product-info-ultra-compact {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px;
    background: #f9fafb !important;
    border-radius: 6px;
    border: 1px solid #e5e7eb !important;
}

.product-image-ultra-compact {
    width: 40px;
    height: 40px;
    object-fit: cover;
    border-radius: 4px;
}

.product-details-ultra-compact {
    flex: 1;
    font-size: 0.8rem;
    line-height: 1.2;
    color: #130325 !important;
}

.product-details-ultra-compact strong {
    color: #130325 !important;
}

.product-details-ultra-compact small {
    color: #6b7280 !important;
}

.issue-details-ultra-compact {
    padding: 8px;
    background: #f9fafb !important;
    border-radius: 6px;
    border: 1px solid #e5e7eb !important;
    font-size: 0.8rem;
    color: #130325 !important;
}

.issue-details-ultra-compact div {
    margin-bottom: 4px;
    color: #130325 !important;
}

.issue-details-ultra-compact div:last-child {
    margin-bottom: 0;
}

.issue-details-ultra-compact strong {
    color: #130325 !important;
}

.description-box-ultra-compact {
    background: #f9fafb !important;
    color: #130325 !important;
    padding: 6px;
    border-radius: 4px;
    border-left: 3px solid #FFD736 !important;
    margin-top: 4px;
    white-space: pre-wrap;
    font-style: italic;
    font-size: 0.75rem;
    max-height: 60px;
    overflow-y: auto;
}

.photos-container-wide {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
    gap: 8px;
    padding: 8px;
    background: #f9fafb !important;
    border-radius: 4px;
    border: 1px solid #e5e7eb !important;
    max-height: 120px;
    overflow-y: auto;
}

.photos-container-wide .photo-item {
    border-radius: 4px;
    overflow: hidden;
}

.photos-container-wide .return-photo {
    width: 100%;
    height: 60px;
    object-fit: cover;
    border-radius: 4px;
    border: 2px solid #e5e7eb !important;
    transition: border-color 0.2s ease;
}

.photos-container-wide .return-photo:hover {
    border-color: #FFD736 !important;
}

/* New Action Buttons Layout */
.action-buttons-new {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}

.action-icons {
    display: flex;
    gap: 8px;
    justify-content: center;
}

.btn-icon {
    width: 32px;
    height: 32px;
    background: var(--bg-light);
    color: var(--text-dark);
    border: 1px solid var(--border-light);
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 14px;
}

.btn-success-icon {
    background: var(--bg-light);
    color: var(--success-green);
    border-color: var(--border-light);
}

.btn-success-icon:hover {
    background: var(--success-green);
    border-color: var(--success-green);
    color: var(--bg-white);
    transform: translateY(-1px);
}

.btn-danger-icon {
    background: var(--bg-light);
    color: var(--error-red);
    border-color: var(--border-light);
}

.btn-danger-icon:hover {
    background: var(--error-red);
    border-color: var(--error-red);
    color: var(--bg-white);
    transform: translateY(-1px);
}

.btn-info-icon {
    background: var(--bg-light);
    color: var(--primary-dark);
    border-color: var(--border-light);
}

.btn-info-icon:hover {
    background: var(--primary-dark);
    border-color: var(--primary-dark);
    color: var(--bg-white);
    transform: translateY(-1px);
}

.btn-details {
    background: #FFD736;
    color: #130325;
    border: none;
    border-radius: 4px;
    padding: 4px 8px;
    font-size: 0.65rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.btn-details:hover {
    background: #FFA500;
    color: #130325;
    transform: translateY(-1px);
}

.modal.show {
    display: flex !important;
}

.modal-dialog {
    max-width: 500px;
    width: 90%;
    margin: 0 !important;
    position: relative;
    z-index: 10000;
}

.modal-content {
    background: #ffffff !important;
    border: none !important;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2) !important;
    position: relative;
    z-index: 10001;
}

.modal-header {
    background: var(--primary-dark) !important;
    color: var(--bg-white) !important;
    border-bottom: none !important;
    padding: 12px 16px;
    border-radius: 12px 12px 0 0;
    position: relative;
    display: flex;
    align-items: center;
    gap: 10px;
}

.modal-title {
    font-weight: 700;
    font-size: 14px;
    color: var(--bg-white) !important;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.modal-title i {
    color: var(--accent-yellow);
    font-size: 16px;
}

.modal-body {
    padding: 16px;
    background: var(--bg-white) !important;
    color: var(--text-dark) !important;
}

.modal-footer {
    border-top: none !important;
    padding: 12px 16px;
    background: var(--bg-white) !important;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

/* Form Styling */
.form-label {
    font-weight: 600;
    color: #130325;
    margin-bottom: 6px;
    font-size: 0.9rem;
}

.form-select,
.form-control {
    background: var(--bg-white);
    border: 1.5px solid var(--primary-dark);
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 13px;
    color: var(--text-dark);
    transition: all 0.2s ease;
}

.form-select:focus,
.form-control:focus {
    background: var(--bg-white);
    border-color: var(--primary-dark);
    box-shadow: 0 0 0 3px rgba(19, 3, 37, 0.1);
    outline: none;
    border-width: 2px;
    color: var(--text-dark);
}

.form-select option {
    background: var(--bg-white);
    color: var(--text-dark);
    padding: 8px;
}

/* Modal Close Button Override */
.modal-header .btn-close {
    background: transparent !important;
    border: none !important;
    border-radius: 0;
    width: 30px;
    height: 30px;
    opacity: 1;
    color: #dc3545 !important;
    font-size: 1.2rem;
    font-weight: bold;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    position: absolute;
    top: 10px;
    right: 10px;
    padding: 0;
}

.modal-header .btn-close:hover {
    background: transparent !important;
    color: #c82333 !important;
    transform: scale(1.1);
}

/* Responsive Design */
@media (max-width: 992px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    
    .page-title { font-size: 20px; }
    
    /* Adjust product name max-width on tablets */
    .table tbody td:nth-child(3) {
        max-width: 150px;
        min-width: 120px;
    }
    
    .table tbody td:nth-child(3) .product-details strong {
        font-size: 0.8rem;
        line-height: 1.3;
        white-space: normal;
        word-break: break-word;
        overflow-wrap: break-word;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        text-overflow: ellipsis;
    }
}

@media (max-width: 768px) {
    .main-content {
        margin-left: 0;
        padding: 10px 6px;
        margin-top: 15px;
    }

    .content-wrapper {
        margin-left: 0;
        padding: 0 6px;
    }

    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
        width: 100%;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
        margin-bottom: 16px;
    }
    
    .stats-card {
        padding: 10px 12px;
        min-height: 60px;
    }
    
    .stats-content h3 {
        font-size: 1.3rem;
    }
    
    .stats-content p {
        font-size: 10px;
    }
    
    .page-title,
    h1.page-title {
        font-size: 1.2rem;
    }
    
    .page-header {
        margin-bottom: 16px;
        flex-direction: column;
        align-items: flex-start;
    }
    
    .export-btn {
        width: 100%;
        justify-content: center;
        margin-top: 8px;
    }
    
    .action-buttons {
        flex-direction: column;
        gap: 6px;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
        padding: 8px 12px;
        font-size: 0.8rem;
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
    
    .filter-dropdown {
        min-width: 120px;
    }
    
    .filter-dropdown select {
        padding: 5px 8px 5px 8px;
        padding-right: 24px;
        font-size: 11px;
    }
    
    .table-responsive {
        font-size: 0.75rem;
        overflow-x: auto;
    }
    
    .product-info {
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
    }
    
    .card-body {
        padding: 12px;
    }
    
    .card-header {
        padding: 10px 12px;
    }
    
    .card-title {
        font-size: 1rem;
    }
    
    .table thead th {
        padding: 6px 8px;
        font-size: 0.65rem;
    }
    
    .table tbody td {
        padding: 8px;
        font-size: 0.75rem;
    }
    
    /* Further reduce product name width on mobile */
    .table tbody td:nth-child(3) {
        max-width: 120px;
        min-width: 100px;
    }
    
    .table tbody td:nth-child(3) .product-info {
        flex-wrap: wrap;
        gap: 4px;
    }
    
    .table tbody td:nth-child(3) .product-thumb {
        width: 25px;
        height: 25px;
        flex-shrink: 0;
    }
    
    .table tbody td:nth-child(3) .product-details {
        min-width: 0;
        flex: 1 1 60%;
        max-width: 100%;
    }
    
    .table tbody td:nth-child(3) .product-details strong {
        font-size: 0.75rem;
        line-height: 1.2;
        white-space: normal;
        word-break: break-word;
        overflow-wrap: break-word;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Hide less critical columns on small screens: Order Date (4), Amount (5) */
    .table thead th:nth-child(4),
    .table thead th:nth-child(5),
    .table tbody td:nth-child(4),
    .table tbody td:nth-child(5) {
        display: none;
    }

    /* Ensure action buttons are reachable and compact */
    .action-buttons-new { align-items: flex-start; }
    .action-buttons-new .action-icons {
        gap: 6px;
        flex-wrap: wrap;
        justify-content: flex-start;
    }
    .action-buttons-new .btn-icon {
        width: 28px;
        height: 28px;
        font-size: 12px;
    }
    
    .modal-dialog {
        width: 95%;
        margin: 5vh auto;
    }
    
    .modal-header {
        padding: 10px 12px;
    }
    
    .modal-body {
        padding: 12px;
    }
    
    .modal-footer {
        padding: 10px 12px;
    }
}

@media (max-width: 480px) {
    .main-content {
        padding: 8px 4px;
        margin-top: 12px;
    }

    .content-wrapper {
        padding: 0 4px;
    }

    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
        width: 100%;
    }

    .page-title {
        font-size: 1.1rem;
    }
    
    .page-title i {
        font-size: 1rem;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
        gap: 8px;
    }
    
    .stats-content h3 {
        font-size: 1.2rem;
    }
    
    .stats-content p {
        font-size: 9px;
    }
    
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
    
    .table thead th {
        font-size: 0.6rem;
        padding: 6px 4px;
    }
    
    .table tbody td {
        padding: 6px 4px;
        font-size: 0.7rem;
    }

    /* Keep product visuals compact */
    .product-thumb { 
        width: 30px; 
        height: 30px; 
    }
    
    .product-info { 
        gap: 4px; 
    }
    
    .card-body {
        padding: 8px;
    }
    
    .alert {
        padding: 8px 10px;
        font-size: 10px;
    }
}

@media (max-width: 360px) {
    .main-content {
        padding: 4px 2px;
        margin-top: 8px;
    }

    .content-wrapper {
        padding: 0 2px;
    }

    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
        width: 100%;
    }

    .page-title {
        font-size: 0.9rem;
    }
}
</style>

<main class="main-content">
    <div class="content-wrapper">
        <!-- Page Header - Aligned with content -->
        <div class="page-header">
            <h1 class="page-title">
                Return & Refund Requests
            </h1>
            <button onclick="showExportModal()" class="export-btn" title="Export Returns Data">
                <i class="fas fa-download"></i> EXPORT
            </button>
        </div>

        <!-- Alert Messages -->
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($message); ?></span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">×</button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">×</button>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stats-card default">
                <div class="stats-content">
                    <h3><?php echo $stats['total_requests']; ?></h3>
                    <p>Total Requests</p>
                </div>
            </div>
            
            <div class="stats-card pending">
                <div class="stats-content">
                    <h3><?php echo $stats['pending_requests']; ?></h3>
                    <p>Pending</p>
                </div>
            </div>
            
            <div class="stats-card approved">
                <div class="stats-content">
                    <h3><?php echo $stats['approved_requests']; ?></h3>
                    <p>Approved</p>
                </div>
            </div>
            
            <div class="stats-card completed">
                <div class="stats-content">
                    <h3><?php echo $stats['completed_requests']; ?></h3>
                    <p>Completed</p>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <?php if (!empty($returnRequests)): ?>
        <div class="filter-section">
            <div class="filter-wrapper">
                <div class="filter-search">
                    <i class="fas fa-search"></i>
                    <input type="text" id="returnSearch" placeholder="Search..." 
                           onkeyup="filterReturns()">
                </div>
                <div class="filter-dropdown">
                    <select id="statusFilter" onchange="filterReturns()">
                        <option value="all">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Return Requests Table -->
        <?php if (empty($returnRequests)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h4>No Return Requests</h4>
                <p>You don't have any return requests at the moment.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                            <thead>
                                <tr>
                                    <th class="sortable" data-sort="id">ORDER ID <span class="sort-indicator"></span></th>
                                    <th class="sortable" data-sort="customer">CUSTOMER <span class="sort-indicator"></span></th>
                                    <th class="sortable" data-sort="product">PRODUCT <span class="sort-indicator"></span></th>
                                    <th class="sortable" data-sort="date">DATE <span class="sort-indicator"></span></th>
                                    <th class="sortable" data-sort="amount">TOTAL <span class="sort-indicator"></span></th>
                                    <th class="sortable" data-sort="status">STATUS <span class="sort-indicator"></span></th>
                                    <th>ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($returnRequests as $request): ?>
                                    <tr data-status="<?php echo htmlspecialchars($request['status']); ?>" 
                                        data-request-id="<?php echo $request['id']; ?>"
                                        data-customer="<?php echo htmlspecialchars(strtolower($request['customer_name'] . ' ' . $request['customer_email'])); ?>"
                                        data-product="<?php echo htmlspecialchars(strtolower($request['product_name'])); ?>">
                                        <td>
                                            <strong>#<?php echo $request['id']; ?></strong>
                                        </td>
                                        <td>
                                            <div class="customer-info">
                                                <strong><?php echo htmlspecialchars($request['customer_name']); ?></strong>
                                                <small class="d-block"><?php echo htmlspecialchars($request['customer_email']); ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="product-info">
                                                <?php if ($request['product_image']): ?>
                                                    <img src="<?php echo htmlspecialchars($request['product_image']); ?>" 
                                                         alt="Product" class="product-thumb">
                                                <?php endif; ?>
                                                <div class="product-details">
                                                    <strong><?php echo htmlspecialchars($request['product_name']); ?></strong>
                                                    <small class="d-block">Qty: <?php echo $request['quantity']; ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($request['order_date'])); ?>
                                        </td>
                                        <td>
                                            <strong>₱<?php echo number_format($request['item_price'] * $request['quantity'], 2); ?></strong>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $request['status']; ?>">
                                                <?php echo ucfirst($request['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons-new">
                                                <div class="action-icons">
                                                    <?php if ($request['status'] === 'pending'): ?>
                                                        <button class="btn-icon btn-success-icon" 
                                                                onclick="approveReturn(<?php echo $request['id']; ?>)"
                                                                title="Approve">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                        <button class="btn-icon btn-danger-icon" 
                                                                onclick="rejectReturn(<?php echo $request['id']; ?>)"
                                                                title="Reject">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    <?php elseif ($request['status'] === 'approved'): ?>
                                                        <button class="btn-icon btn-success-icon" 
                                                                onclick="completeRefund(<?php echo $request['id']; ?>)"
                                                                title="Complete Refund">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <button class="btn-icon btn-info-icon" 
                                                            onclick="viewDetails(<?php echo $request['id']; ?>)"
                                                            title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
        <?php endif; ?>
    </div>
</main>

<!-- Action Forms (Hidden) -->
<form id="actionForm" method="POST" style="display: none;">
    <input type="hidden" name="action" id="actionType">
    <input type="hidden" name="return_id" id="returnId">
    <input type="hidden" name="rejection_reason" id="rejectionReason">
</form>

<!-- Rejection Modal -->
<div class="modal fade" id="rejectionModal" tabindex="-1" aria-hidden="true" style="display: none;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reject Return Request</h5>
                <button type="button" class="btn-close" onclick="closeModal()" aria-label="Close">×</button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="rejectionReasonSelect" class="form-label">Rejection Reason</label>
                    <select class="form-select" id="rejectionReasonSelect" required>
                        <option value="">Select a reason...</option>
                        <option value="Product opened or used">Product opened or used</option>
                        <option value="Return period expired">Return period expired (30 days)</option>
                        <option value="Product not in original packaging">Product not in original packaging</option>
                        <option value="Product damaged by customer">Product damaged by customer</option>
                        <option value="Custom or special order item">Custom or special order item (non-returnable)</option>
                        <option value="Hazardous material restrictions">Hazardous material restrictions (safety policy)</option>
                        <option value="Product as described">Product matches description and images</option>
                        <option value="No valid reason provided">No valid reason provided by customer</option>
                        <option value="Product purchased from different seller">Product purchased from different seller</option>
                        <option value="Customer changed mind">Customer changed mind (not eligible for return)</option>
                        <option value="Other">Other (specify below)</option>
                    </select>
                </div>
                <div class="mb-3" id="customReasonDiv" style="display: none;">
                    <label for="customReasonText" class="form-label">Custom Reason</label>
                    <textarea class="form-control" id="customReasonText" rows="3" 
                              placeholder="Please specify the custom reason..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmRejection()">
                    <i class="fas fa-times-circle"></i> Reject Request
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Approval Modal -->
<div class="modal fade" id="approvalModal" tabindex="-1" aria-hidden="true" style="display: none;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Approve Return Request</h5>
                <button type="button" class="btn-close" onclick="closeApprovalModal()" aria-label="Close">×</button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-3">
                    <i class="fas fa-info-circle"></i>
                    <strong>Note:</strong> Approving this return will allow the customer to proceed with the refund process.
                </div>
                <div class="mb-3">
                    <label for="approvalNotes" class="form-label d-block">Approval Notes (Optional)</label>
                    <textarea class="form-control approval-notes-wide" id="approvalNotes" rows="3" 
                              placeholder="Add any notes about this approval (e.g., refund amount, processing instructions)..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeApprovalModal()">Cancel</button>
                <button type="button" class="btn btn-success" onclick="confirmApproval()">
                    <i class="fas fa-check-circle"></i> Approve Request
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true" style="display: none;">
    <div class="modal-dialog modal-xl" style="max-width: 80vw; width: 80vw;">
        <div class="modal-content">
            <div class="modal-header-modern">
                <h5 class="modal-title-modern">Return Request Details</h5>
                <button type="button" class="btn-close-modern" onclick="closeDetailsModal()" aria-label="Close">×</button>
            </div>
            <div class="modal-body-modern" id="detailsModalBody">
                <!-- Content will be loaded here -->
            </div>
        </div>
    </div>
</div>

<!-- Photo Modal -->
<div class="modal fade" id="photoModal" tabindex="-1" aria-hidden="true" style="display: none;">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Return Photo</h5>
                <button type="button" class="btn-close" onclick="closePhotoModal()" aria-label="Close">×</button>
            </div>
            <div class="modal-body text-center">
                <img id="photoModalImg" src="" alt="Return photo" class="img-fluid" style="max-height: 70vh;">
            </div>
        </div>
    </div>
</div>

<script>
// Filter return requests
function filterReturns() {
    const searchInput = document.getElementById('returnSearch');
    const statusFilter = document.getElementById('statusFilter');
    
    if (!searchInput || !statusFilter) return;
    
    const searchTerm = searchInput.value.toLowerCase();
    const statusValue = statusFilter.value;
    const rows = document.querySelectorAll('.table tbody tr');
    
    rows.forEach(row => {
        const requestId = (row.getAttribute('data-request-id') || '').toString();
        const customer = row.getAttribute('data-customer') || '';
        const product = row.getAttribute('data-product') || '';
        const status = row.getAttribute('data-status') || '';
        
        const matchesSearch = requestId.includes(searchTerm) || 
                             customer.includes(searchTerm) || 
                             product.includes(searchTerm);
        
        let matchesStatus = true;
        if (statusValue !== 'all') {
            matchesStatus = status === statusValue;
        }
        
        if (matchesSearch && matchesStatus) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

let currentReturnId = null;

function approveReturn(returnId) {
    currentReturnId = returnId;
    
    // Reset form fields
    document.getElementById('approvalNotes').value = '';
    
    // Show modal with fallback
    const modalElement = document.getElementById('approvalModal');
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modal = new bootstrap.Modal(modalElement);
        modal.show();
    } else {
        // Fallback: show modal manually
        modalElement.style.display = 'flex';
        modalElement.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
}

function rejectReturn(returnId) {
    currentReturnId = returnId;
    
    // Reset form fields
    document.getElementById('rejectionReasonSelect').value = '';
    document.getElementById('customReasonText').value = '';
    document.getElementById('customReasonDiv').style.display = 'none';
    
    // Show modal with fallback
    const modalElement = document.getElementById('rejectionModal');
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modal = new bootstrap.Modal(modalElement);
        modal.show();
    } else {
        // Fallback: show modal manually
        modalElement.style.display = 'flex';
        modalElement.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
}

function confirmRejection() {
    const selectedReason = document.getElementById('rejectionReasonSelect').value;
    const customReason = document.getElementById('customReasonText').value.trim();
    
    if (!selectedReason) {
        alert('Please select a rejection reason.');
        return;
    }
    
    let finalReason = selectedReason;
    
    // If "Other" is selected, use the custom reason
    if (selectedReason === 'Other') {
        if (!customReason) {
            alert('Please provide a custom reason.');
            return;
        }
        finalReason = customReason;
    }
    
    // Close modal
    closeModal();
    
    document.getElementById('actionType').value = 'reject';
    document.getElementById('returnId').value = currentReturnId;
    document.getElementById('rejectionReason').value = finalReason;
    document.getElementById('actionForm').submit();
}

function closeModal() {
    const modalElement = document.getElementById('rejectionModal');
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modal = bootstrap.Modal.getInstance(modalElement);
        if (modal) {
            modal.hide();
        }
    } else {
        // Fallback: hide modal manually
        modalElement.style.display = 'none';
        modalElement.classList.remove('show');
        document.body.style.overflow = '';
    }
}

function closeApprovalModal() {
    const modalElement = document.getElementById('approvalModal');
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modal = bootstrap.Modal.getInstance(modalElement);
        if (modal) {
            modal.hide();
        }
    } else {
        // Fallback: hide modal manually
        modalElement.style.display = 'none';
        modalElement.classList.remove('show');
        document.body.style.overflow = '';
    }
}

function confirmApproval() {
    const approvalNotes = document.getElementById('approvalNotes').value;
    
    // Close modal
    closeApprovalModal();
    
    document.getElementById('actionType').value = 'approve';
    document.getElementById('returnId').value = currentReturnId;
    // Store approval notes in a hidden field if needed for backend processing
    if (approvalNotes.trim()) {
        // You can add a hidden field for approval notes if your backend needs it
        // For now, we'll just submit the approval
    }
    document.getElementById('actionForm').submit();
}

function completeRefund(returnId) {
    showConfirmModal(
        'Complete Refund',
        'Are you sure you want to complete this refund? This will restore product stock.',
        'fas fa-check-circle',
        function() {
            document.getElementById('actionType').value = 'complete_refund';
            document.getElementById('returnId').value = returnId;
            document.getElementById('actionForm').submit();
        }
    );
}

// Logout-style confirmation modal
function showConfirmModal(title, message, iconClass, onConfirm) {
    const modal = document.createElement('div');
    modal.id = 'confirmModal';
    modal.style.cssText = 'display: flex; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center;';
    
    const modalContent = document.createElement('div');
    modalContent.style.cssText = 'background: #ffffff; border-radius: 12px; padding: 0; max-width: 400px; width: 90%; box-shadow: 0 10px 40px rgba(0,0,0,0.2); animation: slideDown 0.3s ease;';
    
    const modalHeader = document.createElement('div');
    modalHeader.style.cssText = 'background: #130325; color: #ffffff; padding: 16px 20px; border-radius: 12px 12px 0 0; display: flex; align-items: center; gap: 10px;';
    modalHeader.innerHTML = `<i class="${iconClass}" style="font-size: 16px; color: #FFD736;"></i><h3 style="margin: 0; font-size: 14px; font-weight: 700;">${title}</h3>`;
    
    const modalBody = document.createElement('div');
    modalBody.style.cssText = 'padding: 20px; color: #130325;';
    modalBody.innerHTML = `<p style="margin: 0; font-size: 13px; line-height: 1.5; color: #130325;">${message}</p>`;
    
    const modalFooter = document.createElement('div');
    modalFooter.style.cssText = 'padding: 16px 24px; border-top: none; display: flex; gap: 10px; justify-content: flex-end;';
    
    const cancelBtn = document.createElement('button');
    cancelBtn.textContent = 'Cancel';
    cancelBtn.style.cssText = 'padding: 8px 20px; background: #f3f4f6; color: #130325; border: 1px solid #e5e7eb; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.2s ease;';
    cancelBtn.onmouseover = function() { this.style.background = '#e5e7eb'; };
    cancelBtn.onmouseout = function() { this.style.background = '#f3f4f6'; };
    
    const confirmBtn = document.createElement('button');
    confirmBtn.textContent = 'Confirm';
    confirmBtn.style.cssText = 'padding: 8px 20px; background: #130325; color: #ffffff; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.2s ease;';
    confirmBtn.onmouseover = function() { this.style.background = '#0a0218'; };
    confirmBtn.onmouseout = function() { this.style.background = '#130325'; };
    
    cancelBtn.onclick = function() {
        document.body.removeChild(modal);
    };
    
    confirmBtn.onclick = function() {
        if (onConfirm) onConfirm();
        document.body.removeChild(modal);
    };
    
    modalFooter.appendChild(cancelBtn);
    modalFooter.appendChild(confirmBtn);
    
    modalContent.appendChild(modalHeader);
    modalContent.appendChild(modalBody);
    modalContent.appendChild(modalFooter);
    modal.appendChild(modalContent);
    
    modal.onclick = function(e) {
        if (e.target === modal) {
            document.body.removeChild(modal);
        }
    };
    
    document.body.appendChild(modal);
}

function viewDetails(returnId) {
    // Show loading state
    const modalElement = document.getElementById('detailsModal');
    const modalBody = document.getElementById('detailsModalBody');
    modalBody.innerHTML = '<div class="text-center p-4"><i class="fas fa-spinner fa-spin"></i> Loading details...</div>';
    
    // Show modal
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modal = new bootstrap.Modal(modalElement);
        modal.show();
    } else {
        modalElement.style.display = 'flex';
        modalElement.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    
    // Fetch details via AJAX
    fetch(`ajax/get-return-details.php?return_id=${returnId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayReturnDetails(data.return_request);
            } else {
                modalBody.innerHTML = '<div class="alert alert-danger">Error loading details: ' + data.message + '</div>';
            }
        })
        .catch(error => {
            modalBody.innerHTML = '<div class="alert alert-danger">Error loading details: ' + error.message + '</div>';
        });
}

function displayReturnDetails(returnRequest) {
    const modalBody = document.getElementById('detailsModalBody');
    
    // Parse the reason to extract issue type and details
    const reasonParts = returnRequest.reason.split(' | ');
    const issueType = reasonParts[0] || 'Not specified';
    const details = reasonParts[1] || 'No additional details provided';
    
    const statusBadgeClass = getStatusBadgeClass(returnRequest.status);
    
    modalBody.innerHTML = `
        <div class="compact-return-details">
            <!-- Header with Status Badge -->
            <div class="details-header-bar">
                <div class="header-info">
                    <div class="info-item">
                        <i class="fas fa-hashtag"></i> Request #${returnRequest.id}
                    </div>
                    <div class="info-item">
                        <i class="fas fa-shopping-bag"></i> Order #${String(returnRequest.order_id).padStart(6, '0')}
                    </div>
                    <div class="info-item">
                        <i class="fas fa-calendar-alt"></i> ${new Date(returnRequest.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                    </div>
                </div>
                <span class="status-badge-compact status-${returnRequest.status}">
                    <i class="fas ${getStatusIcon(returnRequest.status)}"></i> ${returnRequest.status.toUpperCase()}
                </span>
            </div>

            <!-- Compact Grid Layout -->
            <div class="compact-grid">
                <div class="grid-section">
                    <div class="section-label"><i class="fas fa-box"></i> Product</div>
                    <div class="product-compact">
                        <img src="${returnRequest.product_image}" alt="Product" class="product-thumb">
                        <div class="product-info-compact">
                            <div class="product-name-compact">${returnRequest.product_name}</div>
                            <div class="product-price-compact">₱${parseFloat(returnRequest.item_price).toFixed(2)} × ${returnRequest.quantity}</div>
                        </div>
                    </div>
                </div>

                <div class="grid-section">
                    <div class="section-label"><i class="fas fa-user"></i> Customer</div>
                    <div class="customer-info-compact">
                        <div>${returnRequest.customer_name}</div>
                        <div class="text-muted-compact">${returnRequest.customer_email}</div>
                    </div>
                </div>

                <div class="grid-section full-width-section">
                    <div class="section-label"><i class="fas fa-exclamation-circle"></i> Issue</div>
                    <div class="issue-compact">
                        <div class="issue-type-compact"><strong>Type:</strong> ${issueType}</div>
                        <div class="issue-desc-compact">${details}</div>
                    </div>
                </div>

                <div class="grid-section full-width-section">
                    <div class="section-label"><i class="fas fa-images"></i> Photos</div>
                    <div class="photos-compact" id="photosContainer">
                        <div class="loading-compact"><i class="fas fa-spinner fa-spin"></i></div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Load photos
    loadReturnPhotos(returnRequest.id);
}

function getStatusColor(status) {
    switch(status) {
        case 'pending': return 'linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%)';
        case 'approved': return 'linear-gradient(135deg, #10b981 0%, #059669 100%)';
        case 'rejected': return 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)';
        case 'completed': return 'linear-gradient(135deg, #3b82f6 0%, #2563eb 100%)';
        default: return 'linear-gradient(135deg, #6b7280 0%, #4b5563 100%)';
    }
}

function getStatusIcon(status) {
    switch(status) {
        case 'pending': return 'fa-clock';
        case 'approved': return 'fa-check-circle';
        case 'rejected': return 'fa-times-circle';
        case 'completed': return 'fa-flag-checkered';
        default: return 'fa-info-circle';
    }
}

function getStatusBadgeClass(status) {
    switch(status) {
        case 'pending': return 'warning';
        case 'approved': return 'success';
        case 'rejected': return 'danger';
        case 'completed': return 'info';
        default: return 'secondary';
    }
}

function loadReturnPhotos(returnId) {
    fetch(`ajax/get-return-photos.php?return_id=${returnId}`)
        .then(response => response.json())
        .then(data => {
            const photosContainer = document.getElementById('photosContainer');
            if (data.success && data.photos.length > 0) {
                photosContainer.innerHTML = data.photos.map(photo => 
                    `<div class="photo-card-modern" onclick="openPhotoModal('${photo}')">
                        <img src="${photo}" alt="Return photo" class="photo-img-modern">
                        <div class="photo-overlay">
                            <i class="fas fa-search-plus"></i>
                        </div>
                    </div>`
                ).join('');
            } else {
                photosContainer.innerHTML = '<div class="no-photos-message"><i class="fas fa-image"></i> No photos uploaded</div>';
            }
        })
        .catch(error => {
            document.getElementById('photosContainer').innerHTML = '<div class="error-photos-message"><i class="fas fa-exclamation-triangle"></i> Error loading photos</div>';
        });
}

function openPhotoModal(photoSrc) {
    const photoModal = document.getElementById('photoModal');
    const photoImg = document.getElementById('photoModalImg');
    photoImg.src = photoSrc;
    
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modal = new bootstrap.Modal(photoModal);
        modal.show();
    } else {
        photoModal.style.display = 'flex';
        photoModal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
}

function closeDetailsModal() {
    const modalElement = document.getElementById('detailsModal');
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modal = bootstrap.Modal.getInstance(modalElement);
        if (modal) {
            modal.hide();
        }
    } else {
        modalElement.style.display = 'none';
        modalElement.classList.remove('show');
        document.body.style.overflow = '';
    }
}

function closePhotoModal() {
    const modalElement = document.getElementById('photoModal');
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modal = bootstrap.Modal.getInstance(modalElement);
        if (modal) {
            modal.hide();
        }
    } else {
        modalElement.style.display = 'none';
        modalElement.classList.remove('show');
        document.body.style.overflow = '';
    }
}

// Show/hide custom reason field based on dropdown selection
document.addEventListener('DOMContentLoaded', function() {
    const reasonSelect = document.getElementById('rejectionReasonSelect');
    const customReasonDiv = document.getElementById('customReasonDiv');
    const rejectionModal = document.getElementById('rejectionModal');
    const approvalModal = document.getElementById('approvalModal');
    const detailsModal = document.getElementById('detailsModal');
    const photoModal = document.getElementById('photoModal');
    
    // Ensure modals are hidden on page load
    if (rejectionModal) {
        rejectionModal.style.display = 'none';
        rejectionModal.classList.remove('show');
        
        // Add click outside to close functionality
        rejectionModal.addEventListener('click', function(e) {
            if (e.target === rejectionModal) {
                closeModal();
            }
        });
    }
    
    if (approvalModal) {
        approvalModal.style.display = 'none';
        approvalModal.classList.remove('show');
        
        // Add click outside to close functionality
        approvalModal.addEventListener('click', function(e) {
            if (e.target === approvalModal) {
                closeApprovalModal();
            }
        });
    }
    
    if (detailsModal) {
        detailsModal.style.display = 'none';
        detailsModal.classList.remove('show');
        
        // Add click outside to close functionality
        detailsModal.addEventListener('click', function(e) {
            if (e.target === detailsModal) {
                closeDetailsModal();
            }
        });
    }
    
    if (photoModal) {
        photoModal.style.display = 'none';
        photoModal.classList.remove('show');
        
        // Add click outside to close functionality
        photoModal.addEventListener('click', function(e) {
            if (e.target === photoModal) {
                closePhotoModal();
            }
        });
    }
    
    if (reasonSelect) {
        reasonSelect.addEventListener('change', function() {
            if (this.value === 'Other') {
                customReasonDiv.style.display = 'block';
            } else {
                customReasonDiv.style.display = 'none';
            }
        });
    }
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            } else {
                alert.style.display = 'none';
            }
        });
    }, 5000);
    
    // Table sorting functionality
    const sortableHeaders = document.querySelectorAll('.table thead th.sortable');
    let currentSort = { column: null, direction: 'asc' };
    
    sortableHeaders.forEach(header => {
        header.addEventListener('click', function() {
            const sortColumn = this.getAttribute('data-sort');
            const tbody = this.closest('table').querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            // Remove sort classes from all headers
            sortableHeaders.forEach(h => {
                h.classList.remove('sort-asc', 'sort-desc');
            });
            
            // Determine sort direction
            if (currentSort.column === sortColumn && currentSort.direction === 'asc') {
                currentSort.direction = 'desc';
                this.classList.add('sort-desc');
            } else {
                currentSort.direction = 'asc';
                this.classList.add('sort-asc');
            }
            currentSort.column = sortColumn;
            
            // Sort rows
            rows.sort((a, b) => {
                let aValue, bValue;
                
                switch(sortColumn) {
                    case 'id':
                        aValue = parseInt(a.querySelector('td:first-child strong')?.textContent.replace('#', '') || 0);
                        bValue = parseInt(b.querySelector('td:first-child strong')?.textContent.replace('#', '') || 0);
                        break;
                    case 'customer':
                        aValue = (a.querySelector('td:nth-child(2) strong')?.textContent || '').toLowerCase();
                        bValue = (b.querySelector('td:nth-child(2) strong')?.textContent || '').toLowerCase();
                        break;
                    case 'product':
                        aValue = (a.querySelector('td:nth-child(3) .product-details strong')?.textContent || '').toLowerCase();
                        bValue = (b.querySelector('td:nth-child(3) .product-details strong')?.textContent || '').toLowerCase();
                        break;
                    case 'date':
                        aValue = new Date(a.querySelector('td:nth-child(4)')?.textContent || 0);
                        bValue = new Date(b.querySelector('td:nth-child(4)')?.textContent || 0);
                        break;
                    case 'amount':
                        aValue = parseFloat(a.querySelector('td:nth-child(5) strong')?.textContent.replace('₱', '').replace(/,/g, '') || 0);
                        bValue = parseFloat(b.querySelector('td:nth-child(5) strong')?.textContent.replace('₱', '').replace(/,/g, '') || 0);
                        break;
                    case 'status':
                        aValue = (a.querySelector('td:nth-child(6) .status-badge')?.textContent || '').toLowerCase();
                        bValue = (b.querySelector('td:nth-child(6) .status-badge')?.textContent || '').toLowerCase();
                        break;
                    default:
                        return 0;
                }
                
                if (currentSort.direction === 'asc') {
                    return aValue > bValue ? 1 : aValue < bValue ? -1 : 0;
                } else {
                    return aValue < bValue ? 1 : aValue > bValue ? -1 : 0;
                }
            });
            
            // Re-append sorted rows
            rows.forEach(row => tbody.appendChild(row));
        });
    });
});

// Export Modal Functions
function showExportModal() {
    const modalElement = document.getElementById('exportModal');
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modal = new bootstrap.Modal(modalElement);
        modal.show();
    } else {
        modalElement.style.display = 'flex';
        modalElement.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
}

function closeExportModal() {
    const modalElement = document.getElementById('exportModal');
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modal = bootstrap.Modal.getInstance(modalElement);
        if (modal) {
            modal.hide();
        }
    } else {
        modalElement.style.display = 'none';
        modalElement.classList.remove('show');
        document.body.style.overflow = '';
    }
}

function confirmExport() {
    const format = document.getElementById('exportFormat').value;
    const errorDiv = document.getElementById('exportError');
    
    if (!format) {
        errorDiv.style.display = 'flex';
        return;
    }
    
    errorDiv.style.display = 'none';
    closeExportModal();
    
    if (format === 'csv') {
        exportReturnsToCSV();
    } else if (format === 'pdf') {
        exportReturnsToPDF();
    }
}

function exportReturnsToCSV() {
    const table = document.querySelector('.table');
    if (!table) return;
    
    const csvData = [];
    
    // Header row - only text, no images
    const headerRow = [];
    table.querySelectorAll('thead th').forEach(th => {
        const text = th.textContent.trim();
        if (text && !text.includes('Actions')) {
            headerRow.push(text);
        }
    });
    csvData.push(headerRow);
    
    // Data rows - extract only text content, no images
    table.querySelectorAll('tbody tr').forEach(row => {
        const rowData = [];
        row.querySelectorAll('td').forEach((td, index) => {
            // Skip action column (last column)
            if (index < row.querySelectorAll('td').length - 1) {
                // Get text content only, excluding images
                let text = '';
                
                // Special handling for product column (3rd column)
                if (index === 2) {
                    // Extract product name from strong tag and quantity
                    const productName = td.querySelector('.product-details strong')?.textContent.trim() || '';
                    const quantity = td.querySelector('.product-details small')?.textContent.trim() || '';
                    text = `${productName} ${quantity}`.trim();
                } else {
                    // For other columns, get all text content
                    text = td.textContent.trim().replace(/\s+/g, ' ');
                }
                
                rowData.push(text);
            }
        });
        if (rowData.length > 0) {
            csvData.push(rowData);
        }
    });
    
    // Convert to CSV string
    const csvContent = csvData.map(row => 
        row.map(cell => `"${cell.replace(/"/g, '""')}"`).join(',')
    ).join('\n');
    
    // Download
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', `returns_data_${new Date().toISOString().split('T')[0]}.csv`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function exportReturnsToPDF() {
    // Simple PDF export using window.print() or you can use a library like jsPDF
    window.print();
}
</script>

<!-- Export Modal -->
<div class="modal fade" id="exportModal" tabindex="-1" aria-hidden="true" style="display: none;">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:12px; overflow:hidden; border:none; box-shadow:0 10px 40px rgba(0,0,0,0.2);">
            <div class="modal-header" style="background:#130325; color:#ffffff; display:flex; align-items:center; justify-content:space-between;">
                <h5 class="modal-title" style="margin:0; font-weight:700; color:#ffffff;">Export Returns Data</h5>
                <button type="button" onclick="closeExportModal()" aria-label="Close" style="background:transparent; border:none; color:#ffffff; font-size:20px; cursor:pointer; line-height:1;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" style="padding:16px;">
                <div class="mb-3">
                    <label for="exportFormat" class="form-label" style="color:#130325; font-weight:700;">Format</label>
                    <select class="form-select" id="exportFormat" required style="border:none; box-shadow: inset 0 0 0 1px rgba(0,0,0,0.08); width:100%; padding:8px 12px; border-radius:6px;">
                        <option value="">Select format...</option>
                        <option value="csv">CSV</option>
                        <option value="pdf">PDF</option>
                    </select>
                    <div id="exportError" class="error-message" style="display: none; color:#dc3545; margin-top:8px; padding:8px 12px; background:rgba(220, 53, 69, 0.1); border:1px solid rgba(220, 53, 69, 0.3); border-radius:6px; display:flex; align-items:center; gap:8px; font-size:0.85rem;">
                        <i class="fas fa-exclamation-circle"></i>
                        <span>Please select an export format.</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="padding: 12px 16px; border-top:none; display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" onclick="closeExportModal()" style="padding:8px 16px; background:#f3f4f6; color:#130325; border:none; border-radius:6px; font-weight:600; cursor:pointer;">Cancel</button>
                <button type="button" onclick="confirmExport()" style="padding:8px 16px; background:#130325; color:#ffffff; border:none; border-radius:6px; font-weight:600; cursor:pointer;">Export</button>
            </div>
        </div>
    </div>
</div>

<style>
/* Export Modal Styling */
.modal {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 100% !important;
    height: 100% !important;
    z-index: 9999 !important;
    background-color: rgba(0, 0, 0, 0.5) !important;
    display: none !important;
    align-items: center !important;
    justify-content: center !important;
}

.modal.show {
    display: flex !important;
}

.modal-dialog {
    max-width: 400px;
    width: 90%;
    margin: 0 !important;
    position: relative;
    z-index: 10000;
}

.modal-content {
    background: #ffffff;
    border-radius: 12px;
    overflow: hidden;
    border: none;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
}

.form-select {
    width: 100%;
    padding: 8px 12px;
    border: none;
    border-radius: 6px;
    box-shadow: inset 0 0 0 1px rgba(0, 0, 0, 0.08);
    font-size: 14px;
    color: #130325;
    background: #ffffff;
    cursor: pointer;
}

.form-select:focus {
    outline: none;
    box-shadow: inset 0 0 0 2px rgba(19, 3, 37, 0.2);
}

.error-message {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #dc3545;
    font-size: 0.85rem;
    margin-top: 8px;
    padding: 8px 12px;
    background: rgba(220, 53, 69, 0.1);
    border: 1px solid rgba(220, 53, 69, 0.3);
    border-radius: 6px;
}

.error-message i {
    font-size: 0.9rem;
}
</style>
