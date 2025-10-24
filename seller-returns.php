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
                    // Update order refund status
                    $stmt = $pdo->prepare("UPDATE orders SET refund_status = 'processing' WHERE id = (SELECT order_id FROM return_requests WHERE id = ?)");
                    $stmt->execute([$returnId]);
                    
                    // Create app notification for customer
                    // Create app notification for customer
                    // Create app notification for customer in order_status_history
                    $stmt = $pdo->prepare("
                        INSERT INTO order_status_history (order_id, status, notes, created_at) 
                        VALUES (?, 'return_approved', ?, NOW())
                    ");
                    $notificationMessage = "Your return request for Order #" . str_pad($returnDetails['order_id'], 6, '0', STR_PAD_LEFT) . " has been approved. Refund is being processed.";
                    $stmt->execute([$returnDetails['order_id'], $notificationMessage]);
                    // Send email notification to customer
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
            } elseif ($action === 'reject') {
                $rejectionReason = $_POST['rejection_reason'] ?? '';
                
                // Reject return request
                $stmt = $pdo->prepare("UPDATE return_requests SET status = 'rejected', rejection_reason = ?, processed_at = NOW(), processed_by = ? WHERE id = ? AND seller_id = ?");
                $result = $stmt->execute([$rejectionReason, $sellerId, $returnId, $sellerId]);
                
                if ($result) {
                    $message = "Return request rejected.";
                }
            } elseif ($action === 'complete_refund') {
                // Complete the refund process
                $stmt = $pdo->prepare("UPDATE return_requests SET status = 'completed', processed_at = NOW(), processed_by = ? WHERE id = ? AND seller_id = ?");
                $result = $stmt->execute([$sellerId, $returnId, $sellerId]);
                
                if ($result) {
                    // Update order refund status
                    $stmt = $pdo->prepare("UPDATE orders SET refund_status = 'completed' WHERE id = (SELECT order_id FROM return_requests WHERE id = ?)");
                    $stmt->execute([$returnId]);
                    
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

// Get return requests for this seller
$stmt = $pdo->prepare("
    SELECT 
        rr.*,
        o.id as order_id,
        o.total_amount,
        o.created_at as order_date,
        u.username as customer_name,
        u.email as customer_email,
        p.name as product_name,
        p.image_url as product_image,
        oi.quantity,
        oi.price as item_price
    FROM return_requests rr
    JOIN orders o ON rr.order_id = o.id
    JOIN users u ON o.user_id = u.id
    JOIN order_items oi ON o.id = oi.order_id
    JOIN products p ON oi.product_id = p.id
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
/* Body and Main Container */
body {
    background: #130325;
    min-height: 100vh;
    color: #F9F9F9;
}

.main-content {
    background: #130325;
    min-height: 100vh;
    padding: 20px 0 40px 0;
    margin-left: 70px;
    width: calc(100% - 140px);
}

.content-wrapper {
    max-width: 1400px;
    margin: 0 0 0 0;
    padding: 0 20px 0 5px;
    margin-top: -40px;
}

/* Page Header */
.page-header {
    margin-bottom: 30px;
    padding-bottom: 15px;
    border-bottom: 2px solid rgba(255, 215, 54, 0.3);
}

.page-title {
    color: #F9F9F9;
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.page-title i {
    color: #FFD736;
    font-size: 1.8rem;
}

/* Alert Messages */
.alert {
    border-radius: 8px;
    border: none;
    padding: 12px 16px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 500;
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from { transform: translateY(-20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.alert-success {
    background: rgba(40, 167, 69, 0.15);
    color: #28a745;
    border: 1px solid rgba(40, 167, 69, 0.3);
}

.alert-danger {
    background: rgba(220, 53, 69, 0.15);
    color: #dc3545;
    border: 1px solid rgba(220, 53, 69, 0.3);
}

.alert i {
    font-size: 1.2rem;
}

.btn-close {
    background: transparent;
    border: none;
    color: inherit;
    font-size: 1.5rem;
    cursor: pointer;
    opacity: 0.7;
    margin-left: auto;
}

.btn-close:hover {
    opacity: 1;
}

/* Statistics Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 15px;
    margin-bottom: 25px;
}

.stats-card {
    background: #1a0a2e;
    border: 1px solid #2d1b4e;
    border-left: 4px solid #007bff;
    border-radius: 8px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    min-height: 60px;
}

.stats-card:hover {
    transform: translateY(-5px);
    border-color: #FFD736;
    box-shadow: 0 10px 30px rgba(255, 215, 54, 0.2);
}


.stats-content h3 {
    font-size: 1.8rem;
    font-weight: 700;
    margin: 0 0 2px 0;
    color: #F9F9F9;
    text-align: center;
}

.stats-content p {
    margin: 0;
    color: rgba(249, 249, 249, 0.7);
    font-weight: 500;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Card Container */
.card {
    background: #1a0a2e;
    border: 1px solid #2d1b4e;
    border-radius: 10px;
    box-shadow: 0 3px 15px rgba(0, 0, 0, 0.3);
    margin-bottom: 20px;
    overflow: hidden;
}

.card-header {
    background: #130325;
    padding: 15px 20px;
    border-bottom: 1px solid rgba(255, 215, 54, 0.3);
}

.card-title {
    margin: 0;
    font-size: 1.2rem;
    font-weight: 600;
    color: #F9F9F9;
    display: flex;
    align-items: center;
    gap: 8px;
}

.card-title i {
    color: #FFD736;
}

.card-body {
    padding: 20px;
}

/* Table Styling */
.table-responsive {
    overflow-x: auto;
    border-radius: 8px;
}

.table {
    width: 100%;
    margin-bottom: 0;
    border-collapse: separate;
    border-spacing: 0;
}

.table thead th {
    background: rgba(19, 3, 37, 0.8);
    color: #FFD736;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.8rem;
    padding: 12px 10px;
    border: none;
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.table tbody td {
    padding: 12px 10px;
    vertical-align: middle;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    color: #F9F9F9;
    background: #1a0a2e;
}

.table tbody tr {
    transition: all 0.3s ease;
}

.table tbody tr:hover {
    background: rgba(255, 215, 54, 0.05);
    transform: translateX(5px);
}

/* Customer Info */
.customer-info strong {
    color: #F9F9F9;
    font-weight: 600;
    font-size: 0.95rem;
}

.customer-info small {
    color: rgba(249, 249, 249, 0.6);
    font-size: 0.8rem;
}

/* Product Info */
.product-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.product-thumb {
    width: 50px;
    height: 50px;
    object-fit: cover;
    border-radius: 8px;
    border: 2px solid rgba(255, 215, 54, 0.3);
}

.product-details strong {
    color: #F9F9F9;
    font-weight: 600;
    font-size: 0.95rem;
    display: block;
    margin-bottom: 4px;
}

.product-details small {
    color: rgba(249, 249, 249, 0.6);
    font-size: 0.8rem;
}

/* Status Badges */
.status-badge {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: inline-block;
}

.status-pending {
    background: rgba(255, 193, 7, 0.2);
    color: #ffc107;
    border: 1px solid rgba(255, 193, 7, 0.4);
}

.status-approved {
    background: rgba(40, 167, 69, 0.2);
    color: #28a745;
    border: 1px solid rgba(40, 167, 69, 0.4);
}

.status-rejected {
    background: rgba(220, 53, 69, 0.2);
    color: #dc3545;
    border: 1px solid rgba(220, 53, 69, 0.4);
}

.status-completed {
    background: rgba(23, 162, 184, 0.2);
    color: #17a2b8;
    border: 1px solid rgba(23, 162, 184, 0.4);
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
    background: #28a745;
    color: #F9F9F9;
}

.btn-success:hover {
    background: #218838;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
}

.btn-danger {
    background: #dc3545;
    color: #F9F9F9;
}

.btn-danger:hover {
    background: #c82333;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
}

.btn-primary {
    background: #007bff;
    color: #F9F9F9;
}

.btn-primary:hover {
    background: #0056b3;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0, 123, 255, 0.3);
}

.btn-info {
    background: #17a2b8;
    color: #F9F9F9;
}

.btn-info:hover {
    background: #138496;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(23, 162, 184, 0.3);
}

.btn-sm {
    padding: 6px 12px;
    font-size: 0.8rem;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: rgba(249, 249, 249, 0.6);
}

.empty-state i {
    font-size: 5rem;
    margin-bottom: 20px;
    color: rgba(255, 215, 54, 0.3);
}

.empty-state h4 {
    color: #F9F9F9;
    margin-bottom: 10px;
    font-size: 1.5rem;
    font-weight: 700;
}

.empty-state p {
    color: rgba(249, 249, 249, 0.6);
    font-size: 1rem;
}

/* Return Reason */
.return-reason {
    max-width: 200px;
    display: block;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: rgba(249, 249, 249, 0.8);
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
    background: transparent;
    color: #F9F9F9;
}

.return-details .table td {
    border-color: rgba(255, 255, 255, 0.1);
    padding: 8px 12px;
}

.return-details .table td:first-child {
    color: #FFD736;
    font-weight: 500;
}

.product-info {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.product-details {
    flex: 1;
}

.issue-details {
    padding: 15px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.description-box {
    background: rgba(0, 0, 0, 0.3);
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
    background: rgba(255, 255, 255, 0.05);
    border-radius: 6px;
    border: 1px solid rgba(255, 255, 255, 0.1);
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
}

.issue-details-compact {
    padding: 10px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 6px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    font-size: 0.9rem;
}

.description-box-compact {
    background: rgba(0, 0, 0, 0.3);
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
    background: rgba(255, 255, 255, 0.05);
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.1);
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
}

/* Force modal to be horizontal/wide */
#detailsModal .modal-dialog {
    max-width: 80vw !important;
    width: 80vw !important;
    height: 80vh !important;
    margin: 10vh auto !important;
}

#detailsModal .modal-content {
    height: 100% !important;
    display: flex !important;
    flex-direction: column !important;
}

#detailsModal .modal-body {
    flex: 1 !important;
    overflow-y: auto !important;
    padding: 15px !important;
}

.info-grid {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 8px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 6px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.info-grid div {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 2px 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.info-grid div:last-child {
    border-bottom: none;
}

.info-grid strong {
    color: #FFD736;
    font-size: 0.8rem;
    min-width: 40px;
}

.product-info-ultra-compact {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 6px;
    border: 1px solid rgba(255, 255, 255, 0.1);
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
}

.issue-details-ultra-compact {
    padding: 8px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 6px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    font-size: 0.8rem;
}

.issue-details-ultra-compact div {
    margin-bottom: 4px;
}

.issue-details-ultra-compact div:last-child {
    margin-bottom: 0;
}

.description-box-ultra-compact {
    background: rgba(0, 0, 0, 0.3);
    padding: 6px;
    border-radius: 4px;
    border-left: 2px solid #FFD736;
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
    background: rgba(255, 255, 255, 0.05);
    border-radius: 6px;
    border: 1px solid rgba(255, 255, 255, 0.1);
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
    border: 1px solid rgba(255, 255, 255, 0.1);
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
    border: none;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 14px;
}

.btn-success-icon {
    background: #007bff;
    color: white;
}

.btn-success-icon:hover {
    background: #0056b3;
    transform: scale(1.1);
}

.btn-danger-icon {
    background: #dc3545;
    color: white;
}

.btn-danger-icon:hover {
    background: #c82333;
    transform: scale(1.1);
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
    background: #1a0a2e;
    border: 1px solid #2d1b4e;
    border-radius: 10px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.8);
    position: relative;
    z-index: 10001;
}

.modal-header {
    background: #130325;
    color: #F9F9F9;
    border-bottom: 1px solid rgba(255, 215, 54, 0.3);
    padding: 15px 50px 15px 20px;
    border-radius: 10px 10px 0 0;
    position: relative;
}

.modal-title {
    font-weight: 600;
    font-size: 1.1rem;
    color: #F9F9F9;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    border-top: 1px solid rgba(255, 215, 54, 0.2);
    padding: 15px 20px;
    background: #130325;
}

/* Form Styling */
.form-label {
    font-weight: 500;
    color: #F9F9F9;
    margin-bottom: 6px;
    font-size: 0.9rem;
}

.form-select,
.form-control {
    background: #1a0a2e;
    border: 1px solid rgba(255, 215, 54, 0.3);
    border-radius: 6px;
    padding: 10px 12px;
    font-size: 0.9rem;
    color: #F9F9F9;
    transition: all 0.3s ease;
}

.form-select:focus,
.form-control:focus {
    background: rgba(19, 3, 37, 0.9);
    border-color: #FFD736;
    box-shadow: 0 0 0 0.2rem rgba(255, 215, 54, 0.25);
    outline: none;
    color: #F9F9F9;
}

.form-select option {
    background: #130325;
    color: #F9F9F9;
    padding: 8px;
}

/* Modal Close Button Override */
.modal-header .btn-close {
    background: #dc3545;
    border: none;
    border-radius: 4px;
    width: 30px;
    height: 30px;
    opacity: 1;
    color: #fff;
    font-size: 1rem;
    font-weight: bold;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    position: absolute;
    top: 10px;
    right: 10px;
}

.modal-header .btn-close:hover {
    background: #c82333;
    color: #fff;
    transform: scale(1.1);
}

/* Responsive Design */
@media (max-width: 992px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    
    .page-title {
        font-size: 1.8rem;
    }
}

@media (max-width: 768px) {
    .main-content {
        padding: 30px 0;
        width: 100%;
        margin-left: 0;
    }
    
    .content-wrapper {
        margin-left: 0 0 0 0;
        padding: 0 15px 0 5px;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
        gap: 12px;
        margin-bottom: 20px;
    }
    
    .stats-card {
        padding: 15px;
    }
    
    .stats-icon {
        width: 50px;
        height: 50px;
        font-size: 1.3rem;
    }
    
    .stats-content h3 {
        font-size: 1.8rem;
    }
    
    .page-title {
        font-size: 1.5rem;
    }
    
    .page-header {
        margin-bottom: 20px;
    }
    
    .action-buttons {
        flex-direction: column;
        gap: 6px;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
        padding: 8px 12px;
    }
    
    .table-responsive {
        font-size: 0.8rem;
    }
    
    .product-info {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    
    .card-body {
        padding: 15px;
    }
    
    .table thead th {
        padding: 8px 6px;
        font-size: 0.75rem;
    }
    
    .table tbody td {
        padding: 10px 6px;
    }
}

@media (max-width: 576px) {
    .page-title {
        font-size: 1.5rem;
    }
    
    .page-title i {
        font-size: 1.5rem;
    }
    
    .stats-content h3 {
        font-size: 1.75rem;
    }
    
    .table thead th {
        font-size: 0.75rem;
        padding: 10px 8px;
    }
    
    .table tbody td {
        padding: 12px 8px;
        font-size: 0.85rem;
    }
}
</style>

<main class="main-content">
    <div class="content-wrapper">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                Return & Refund Requests
            </h1>
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

        <!-- Return Requests Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">
                    <i class="fas fa-list"></i>
                    Return Requests
                </h5>
            </div>
            <div class="card-body">
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
                                    <th>Request ID</th>
                                    <th>Customer</th>
                                    <th>Product</th>
                                    <th>Order Date</th>
                                    <th>Reason</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($returnRequests as $request): ?>
                                    <tr>
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
                                            <span class="return-reason" title="<?php echo htmlspecialchars($request['reason']); ?>">
                                                <?php echo htmlspecialchars($request['reason']); ?>
                                            </span>
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
                                                <?php if ($request['status'] === 'pending'): ?>
                                                    <div class="action-icons">
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
                                                    </div>
                                                <?php elseif ($request['status'] === 'approved'): ?>
                                                    <div class="action-icons">
                                                        <button class="btn-icon btn-success-icon" 
                                                                onclick="completeRefund(<?php echo $request['id']; ?>)"
                                                                title="Complete Refund">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <button class="btn-details" 
                                                        onclick="viewDetails(<?php echo $request['id']; ?>)">
                                                    VIEW DETAILS
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
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
            <div class="modal-header">
                <h5 class="modal-title">Return Request Details</h5>
                <button type="button" class="btn-close" onclick="closeDetailsModal()" aria-label="Close">×</button>
            </div>
            <div class="modal-body" id="detailsModalBody">
                <!-- Content will be loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDetailsModal()">Close</button>
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
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePhotoModal()">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
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
    if (confirm('Are you sure you want to complete this refund? This will restore product stock.')) {
        document.getElementById('actionType').value = 'complete_refund';
        document.getElementById('returnId').value = returnId;
        document.getElementById('actionForm').submit();
    }
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
    
    modalBody.innerHTML = `
        <div class="return-details-wide">
            <div class="row">
                <div class="col-md-2">
                    <h6><i class="fas fa-info-circle"></i> Info</h6>
                    <div class="info-grid">
                        <div><strong>ID:</strong> #${returnRequest.id}</div>
                        <div><strong>Order:</strong> #${returnRequest.order_id}</div>
                        <div><strong>Status:</strong> <span class="badge badge-${getStatusBadgeClass(returnRequest.status)}">${returnRequest.status}</span></div>
                        <div><strong>Date:</strong> ${new Date(returnRequest.created_at).toLocaleDateString()}</div>
                        <div><strong>Qty:</strong> ${returnRequest.quantity}</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <h6><i class="fas fa-user"></i> Customer</h6>
                    <div class="info-grid">
                        <div><strong>Name:</strong> ${returnRequest.customer_name}</div>
                        <div><strong>Email:</strong> ${returnRequest.customer_email}</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <h6><i class="fas fa-box"></i> Product</h6>
                    <div class="product-info-ultra-compact">
                        <img src="${returnRequest.product_image}" alt="Product" class="product-image-ultra-compact">
                        <div class="product-details-ultra-compact">
                            <strong>${returnRequest.product_name}</strong><br>
                            <small>₱${parseFloat(returnRequest.item_price).toFixed(2)}</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <h6><i class="fas fa-exclamation-triangle"></i> Issue</h6>
                    <div class="issue-details-ultra-compact">
                        <div><strong>Type:</strong> ${issueType}</div>
                        <div><strong>Details:</strong></div>
                        <div class="description-box-ultra-compact">${details}</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <h6><i class="fas fa-images"></i> Photos</h6>
                    <div class="photos-container-wide" id="photosContainer">
                        <div class="text-center p-2">
                            <i class="fas fa-spinner fa-spin"></i> Loading...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Load photos
    loadReturnPhotos(returnRequest.id);
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
                    `<div class="photo-item">
                        <img src="${photo}" alt="Return photo" class="return-photo" onclick="openPhotoModal('${photo}')">
                    </div>`
                ).join('');
            } else {
                photosContainer.innerHTML = '<div class="text-center p-3 text-muted">No photos uploaded</div>';
            }
        })
        .catch(error => {
            document.getElementById('photosContainer').innerHTML = '<div class="text-center p-3 text-danger">Error loading photos</div>';
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
});
</script>
