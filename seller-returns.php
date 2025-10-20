<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

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
                // Approve return request
                $stmt = $pdo->prepare("UPDATE return_requests SET status = 'approved', processed_at = NOW(), processed_by = ? WHERE id = ? AND seller_id = ?");
                $result = $stmt->execute([$sellerId, $returnId, $sellerId]);
                
                if ($result) {
                    // Update order refund status
                    $stmt = $pdo->prepare("UPDATE orders SET refund_status = 'processing' WHERE id = (SELECT order_id FROM return_requests WHERE id = ?)");
                    $stmt->execute([$returnId]);
                    
                    $message = "Return request approved successfully.";
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
.modal-dialog {
    max-width: 500px;
}

.modal-content {
    background: #1a0a2e;
    border: 1px solid #2d1b4e;
    border-radius: 10px;
    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.5);
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
                                            <div class="action-buttons">
                                                <?php if ($request['status'] === 'pending'): ?>
                                                    <button class="btn btn-success btn-sm" 
                                                            onclick="approveReturn(<?php echo $request['id']; ?>)">
                                                        <i class="fas fa-check"></i> Approve
                                                    </button>
                                                    <button class="btn btn-danger btn-sm" 
                                                            onclick="rejectReturn(<?php echo $request['id']; ?>)">
                                                        <i class="fas fa-times"></i> Reject
                                                    </button>
                                                <?php elseif ($request['status'] === 'approved'): ?>
                                                    <button class="btn btn-primary btn-sm" 
                                                            onclick="completeRefund(<?php echo $request['id']; ?>)">
                                                        <i class="fas fa-money-bill-wave"></i> Complete
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <button class="btn btn-info btn-sm" 
                                                        onclick="viewDetails(<?php echo $request['id']; ?>)">
                                                    <i class="fas fa-eye"></i> Details
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
<div class="modal fade" id="rejectionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reject Return Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close">×</button>
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
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmRejection()">
                    <i class="fas fa-times-circle"></i> Reject Request
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let currentReturnId = null;

function approveReturn(returnId) {
    if (confirm('Are you sure you want to approve this return request?')) {
        document.getElementById('actionType').value = 'approve';
        document.getElementById('returnId').value = returnId;
        document.getElementById('actionForm').submit();
    }
}

function rejectReturn(returnId) {
    currentReturnId = returnId;
    const modal = new bootstrap.Modal(document.getElementById('rejectionModal'));
    modal.show();
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
    
    document.getElementById('actionType').value = 'reject';
    document.getElementById('returnId').value = currentReturnId;
    document.getElementById('rejectionReason').value = finalReason;
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
    // This could open a modal or redirect to a details page
    alert('View details functionality for return ID: ' + returnId);
}

// Show/hide custom reason field based on dropdown selection
document.addEventListener('DOMContentLoaded', function() {
    const reasonSelect = document.getElementById('rejectionReasonSelect');
    const customReasonDiv = document.getElementById('customReasonDiv');
    
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
