<?php
require_once '../config/database.php';
require_once 'includes/admin_header.php';

requireAdmin();

// Get user ID from URL
$userId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$userId) {
    header('Location: admin-customers.php?msg=' . urlencode('Invalid user ID') . '&type=error');
    exit();
}

// Initialize variables
$message = '';
$messageType = '';

// Handle actions
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    try {
        switch ($action) {
            case 'activate':
                $stmt = $pdo->prepare("UPDATE users SET is_active = TRUE WHERE id = ?");
                $stmt->execute([$userId]);
                if ($stmt->rowCount() > 0) {
                    $message = "User activated successfully!";
                    $messageType = 'success';
                }
                break;
                
            case 'deactivate':
                $stmt = $pdo->prepare("UPDATE users SET is_active = FALSE WHERE id = ?");
                $stmt->execute([$userId]);
                if ($stmt->rowCount() > 0) {
                    $message = "User deactivated successfully!";
                    $messageType = 'warning';
                }
                break;
                
            case 'delete':
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM orders WHERE user_id = ?");
                $stmt->execute([$userId]);
                $orderCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                
                if ($orderCount > 0) {
                    $message = "Cannot delete user. User has {$orderCount} order(s).";
                    $messageType = 'error';
                } else {
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    if ($stmt->rowCount() > 0) {
                        header('Location: admin-customers.php?msg=' . urlencode('User deleted successfully') . '&type=success');
                        exit();
                    }
                }
                break;
        }
    } catch (PDOException $e) {
        $message = "Database error: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Get user details
try {
    $stmt = $pdo->prepare("
        SELECT *, 
               CASE WHEN is_active IS NULL THEN TRUE ELSE is_active END as active_status
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        header('Location: admin-customers.php?msg=' . urlencode('User not found') . '&type=error');
        exit();
    }
    
} catch (PDOException $e) {
    header('Location: admin-customers.php?msg=' . urlencode('Error retrieving user details') . '&type=error');
    exit();
}

// Get user's orders with pagination
$orderPage = isset($_GET['order_page']) ? max(1, intval($_GET['order_page'])) : 1;
$orderLimit = 10;
$orderOffset = ($orderPage - 1) * $orderLimit;

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM orders WHERE user_id = ?");
    $stmt->execute([$userId]);
    $totalOrders = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalOrderPages = ceil($totalOrders / $orderLimit);
    
    $stmt = $pdo->prepare("
        SELECT o.*, 
               COALESCE(o.total_amount, 0) as total_amount,
               COALESCE(o.status, 'pending') as status
        FROM orders o 
        WHERE o.user_id = ? 
        ORDER BY o.created_at DESC 
        LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, (int)$userId, PDO::PARAM_INT);
    $stmt->bindValue(2, (int)$orderLimit, PDO::PARAM_INT);
    $stmt->bindValue(3, (int)$orderOffset, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $orders = [];
    $totalOrders = 0;
    $totalOrderPages = 0;
}

// Get user statistics
try {
    $stats = [
        'total_orders' => $totalOrders,
        'total_spent' => 0,
        'last_order_date' => null,
        'account_age_days' => 0,
        'completed_orders' => 0,
        'pending_orders' => 0,
        'cancelled_orders' => 0
    ];
    
    if ($totalOrders > 0) {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(total_amount), 0) as total_spent,
                MAX(created_at) as last_order,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders
            FROM orders 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $orderStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stats['total_spent'] = $orderStats['total_spent'];
        $stats['last_order_date'] = $orderStats['last_order'];
        $stats['completed_orders'] = $orderStats['completed_orders'];
        $stats['pending_orders'] = $orderStats['pending_orders'];
        $stats['cancelled_orders'] = $orderStats['cancelled_orders'];
    }
    
    if ($user['created_at']) {
        $stats['account_age_days'] = floor((time() - strtotime($user['created_at'])) / (60 * 60 * 24));
    }
    
} catch (PDOException $e) {
    $stats = [
        'total_orders' => 0,
        'total_spent' => 0,
        'last_order_date' => null,
        'account_age_days' => 0,
        'completed_orders' => 0,
        'pending_orders' => 0,
        'cancelled_orders' => 0
    ];
}

// Check if is_active column exists
$isActiveExists = false;
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_active'");
    $isActiveExists = $stmt->rowCount() > 0;
} catch (PDOException $e) {
    $isActiveExists = false;
}
?>

<style>
    body {
        background: #f0f2f5 !important;
        color: #130325 !important;
        min-height: 100vh;
        margin: 0;
        font-family: 'Inter', 'Segoe UI', sans-serif;
    }

    .alert {
        max-width: 1400px;
        margin: 0 auto 10px;
        padding: 14px 18px;
        border-radius: 8px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #86efac; }
    .alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
    .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }

    .breadcrumb {
        max-width: 1400px;
        margin: -30px auto 12px;
        padding: 0 20px;
    }
    .breadcrumb a {
        color: #130325;
        text-decoration: none;
        font-weight: 600;
        font-size: 14px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .page-header {
        max-width: 1400px;
        margin: 0 auto 12px;
        padding: 0 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
    }
    .page-header h1 {
        margin: 0;
        font-size: 24px;
        font-weight: 700;
        color: #130325;
        text-shadow: none !important;
    }
    .page-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .container {
        max-width: 1400px;
        margin: 0 auto 30px;
        padding: 0 20px;
    }

    /* Main Profile Section - Horizontal Layout */
    .profile-section {
        background: #ffffff;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        display: flex;
        gap: 32px;
        flex-wrap: wrap;
    }

    .profile-info {
        flex: 1;
        min-width: 280px;
    }
    .profile-info h2 {
        margin: 0 0 16px 0;
        font-size: 18px;
        font-weight: 700;
        color: #130325;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .info-row {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
        margin-bottom: 16px;
    }
    .info-item {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .info-item label {
        font-size: 11px;
        font-weight: 600;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .info-item span {
        font-size: 14px;
        font-weight: 500;
        color: #130325;
        word-break: break-word;
    }

    .profile-stats {
        flex: 1;
        min-width: 280px;
    }
    .profile-stats h2 {
        margin: 0 0 16px 0;
        font-size: 18px;
        font-weight: 700;
        color: #130325;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    .stat-card {
        background: #f8f9fa;
        border: 1px solid rgba(0,0,0,0.08);
        border-radius: 8px;
        padding: 16px;
        text-align: center;
    }
    .stat-number {
        font-size: 24px;
        font-weight: 700;
        color: #130325;
        margin-bottom: 4px;
    }
    .stat-label {
        font-size: 11px;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .status-active {
        background: #d1fae5;
        color: #065f46;
    }
    .status-inactive {
        background: #fee2e2;
        color: #991b1b;
    }

    .action-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        text-decoration: none;
        border: none;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .action-btn:hover { transform: translateY(-1px); }
    .btn-activate { background: #28a745; color: #ffffff; }
    .btn-activate:hover { background: #218838; }
    .btn-deactivate { background: #ffc107; color: #130325; }
    .btn-deactivate:hover { background: #e0a800; }
    .btn-delete { background: #dc3545; color: #ffffff; }
    .btn-delete:hover { background: #c82333; }

    /* Orders Section */
    .orders-section {
        background: #ffffff;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 12px;
        padding: 24px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .orders-section h2 {
        margin: 0 0 20px 0;
        font-size: 18px;
        font-weight: 700;
        color: #130325;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .table-container {
        overflow-x: auto;
        border-radius: 8px;
        border: 1px solid rgba(0,0,0,0.08);
    }
    table {
        width: 100%;
        border-collapse: collapse;
    }
    thead {
        background: #130325;
    }
    th {
        padding: 12px 16px;
        text-align: left;
        font-size: 12px;
        color: #ffffff;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    td {
        padding: 12px 16px;
        border-bottom: 1px solid #f0f0f0;
        font-size: 14px;
        color: #130325;
    }
    tbody tr:hover {
        background: rgba(255, 215, 54, 0.05);
    }
    .status-pending { background: #fef3c7; color: #92400e; }
    .status-completed { background: #d1fae5; color: #065f46; }
    .status-cancelled { background: #fee2e2; color: #991b1b; }

    .pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 20px;
    }
    .page-link {
        padding: 8px 14px;
        background: #ffffff !important;
        color: #130325 !important;
        text-decoration: none;
        border-radius: 6px;
        border: 1px solid #e5e7eb !important;
        font-weight: 600;
        font-size: 13px;
        transition: all 0.2s ease;
    }
    .page-link:hover { 
        background: #130325 !important; 
        color: #ffffff !important; 
        border-color: #130325 !important;
    }
    .page-link.active { 
        background: #130325 !important; 
        color: #ffffff !important; 
        border-color: #130325 !important;
    }

    /* Confirmation Modal - Same style as admin-dashboard.php */
    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.35);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 9999;
    }
    .modal-dialog {
        width: 320px;
        max-width: 90vw;
        background: #ffffff;
        border: none;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    }
    .modal-header {
        padding: 8px 12px;
        background: #130325;
        color: #F9F9F9;
        border-bottom: none;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-radius: 12px 12px 0 0;
    }
    .modal-title {
        font-size: 12px;
        font-weight: 800;
        letter-spacing: .3px;
    }
    .modal-close {
        background: transparent;
        border: none;
        color: #F9F9F9;
        font-size: 16px;
        line-height: 1;
        cursor: pointer;
    }
    .modal-body {
        padding: 12px;
        color: #130325 !important;
        font-size: 12px;
        line-height: 1.5;
    }
    
    .modal-body p {
        color: #130325 !important;
        font-size: 12px;
        margin: 0;
    }
    .modal-actions {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
        padding: 0 12px 12px 12px;
    }
    .btn-outline {
        background: #ffffff;
        color: #130325;
        border: none;
        border-radius: 8px;
        padding: 6px 10px;
        font-weight: 700;
        font-size: 12px;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .btn-primary-y {
        background: linear-gradient(135deg, #FFD736 0%, #FFC107 100%);
        color: #130325;
        border: none;
        border-radius: 8px;
        padding: 6px 10px;
        font-weight: 700;
        font-size: 12px;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .btn-danger {
        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        color: #ffffff;
        border: none;
        border-radius: 8px;
        padding: 6px 10px;
        font-weight: 700;
        font-size: 12px;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    @media (max-width: 768px) {
        .profile-section {
            flex-direction: column;
        }
        .info-row, .stats-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType; ?>">
    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : ($messageType === 'warning' ? 'exclamation-triangle' : 'times-circle'); ?>"></i>
    <?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<div class="breadcrumb">
    <a href="admin-customers.php">
        <i class="fas fa-arrow-left"></i> Back to Customers
    </a>
</div>

<div class="page-header">
    <h1><i class="fas fa-user"></i> Customer Details</h1>
    <div class="page-actions">
        <?php if ($isActiveExists): ?>
            <?php if ($user['active_status']): ?>
                <a href="#" onclick="openConfirmModal('deactivate'); return false;" 
                   class="action-btn btn-deactivate">
                    <i class="fas fa-user-slash"></i> Deactivate
                </a>
            <?php else: ?>
                <a href="#" onclick="openConfirmModal('activate'); return false;" 
                   class="action-btn btn-activate">
                    <i class="fas fa-user-check"></i> Activate
                </a>
            <?php endif; ?>
            <a href="#" onclick="openConfirmModal('delete'); return false;" 
               class="action-btn btn-delete">
                <i class="fas fa-trash-alt"></i> Delete
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="container">
    <!-- Profile Section - Horizontal Layout -->
    <div class="profile-section">
        <div class="profile-info">
            <h2><i class="fas fa-user-circle"></i> Profile Information</h2>
            <div class="info-row">
                <div class="info-item">
                    <label>User ID</label>
                    <span>#<?php echo (int)$user['id']; ?></span>
                </div>
                <div class="info-item">
                    <label>Status</label>
                    <span>
                        <span class="status-badge status-<?php echo $user['active_status'] ? 'active' : 'inactive'; ?>">
                            <?php echo $user['active_status'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </span>
                </div>
                <div class="info-item">
                    <label>Username</label>
                    <span><?php echo htmlspecialchars($user['username'] ?? ''); ?></span>
                </div>
                <div class="info-item">
                    <label>Email</label>
                    <span><?php echo htmlspecialchars($user['email'] ?? ''); ?></span>
                </div>
                <div class="info-item">
                    <label>Full Name</label>
                    <span><?php echo htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'N/A'); ?></span>
                </div>
                <div class="info-item">
                    <label>Phone</label>
                    <span><?php echo htmlspecialchars($user['phone'] ?? 'Not provided'); ?></span>
                </div>
                <div class="info-item">
                    <label>Address</label>
                    <span><?php echo htmlspecialchars($user['address'] ?? 'Not provided'); ?></span>
                </div>
                <div class="info-item">
                    <label>Registered</label>
                    <span><?php echo $user['created_at'] ? date('M j, Y', strtotime($user['created_at'])) : 'N/A'; ?></span>
                </div>
            </div>
        </div>

        <div class="profile-stats">
            <h2><i class="fas fa-chart-line"></i> Statistics</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['total_orders']); ?></div>
                    <div class="stat-label">Total Orders</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">₱<?php echo number_format($stats['total_spent'], 2); ?></div>
                    <div class="stat-label">Total Spent</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['completed_orders']); ?></div>
                    <div class="stat-label">Completed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['pending_orders']); ?></div>
                    <div class="stat-label">Pending</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['cancelled_orders']); ?></div>
                    <div class="stat-label">Cancelled</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['account_age_days']; ?></div>
                    <div class="stat-label">Account Age (Days)</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Orders Section -->
    <div class="orders-section">
        <h2><i class="fas fa-receipt"></i> Recent Orders (<?php echo count($orders); ?>)</h2>
        <?php if (empty($orders)): ?>
            <p style="text-align: center; color: #6b7280; padding: 40px;">No orders found.</p>
        <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><strong>#<?php echo htmlspecialchars($order['id']); ?></strong></td>
                                <td><?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower($order['status']); ?>">
                                        <?php echo ucfirst($order['status']); ?>
                                    </span>
                                </td>
                                <td>₱<?php echo number_format($order['total_amount'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalOrderPages > 1): ?>
                <div class="pagination">
                    <?php if ($orderPage > 1): ?>
                        <a href="user-details.php?id=<?php echo $userId; ?>&order_page=<?php echo $orderPage - 1; ?>" class="page-link">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $totalOrderPages; $i++): ?>
                        <a href="user-details.php?id=<?php echo $userId; ?>&order_page=<?php echo $i; ?>" 
                           class="page-link <?php echo $i === $orderPage ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($orderPage < $totalOrderPages): ?>
                        <a href="user-details.php?id=<?php echo $userId; ?>&order_page=<?php echo $orderPage + 1; ?>" class="page-link">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Confirmation Modals -->
<!-- Deactivate Modal -->
<div id="deactivateModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-header">
            <div class="modal-title">Confirm Deactivation</div>
            <button class="modal-close" aria-label="Close" onclick="closeConfirmModal('deactivate')">×</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to deactivate this user? They will not be able to log in.</p>
        </div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeConfirmModal('deactivate')">Cancel</button>
            <a href="user-details.php?id=<?php echo $userId; ?>&action=deactivate" class="btn-primary-y">Confirm</a>
        </div>
    </div>
</div>

<!-- Activate Modal -->
<div id="activateModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-header">
            <div class="modal-title">Confirm Activation</div>
            <button class="modal-close" aria-label="Close" onclick="closeConfirmModal('activate')">×</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to activate this user? They will be able to log in and use the system.</p>
        </div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeConfirmModal('activate')">Cancel</button>
            <a href="user-details.php?id=<?php echo $userId; ?>&action=activate" class="btn-primary-y">Confirm</a>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-header">
            <div class="modal-title">Confirm Deletion</div>
            <button class="modal-close" aria-label="Close" onclick="closeConfirmModal('delete')">×</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete this user? This action cannot be undone. Users with orders cannot be deleted.</p>
        </div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeConfirmModal('delete')">Cancel</button>
            <a href="user-details.php?id=<?php echo $userId; ?>&action=delete" class="btn-danger">Delete</a>
        </div>
    </div>
</div>

<script>
function openConfirmModal(action) {
    const modal = document.getElementById(action + 'Modal');
    if (modal) {
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        
        // Auto-dismiss after 5 seconds
        clearTimeout(modal.autoDismissTimer);
        modal.autoDismissTimer = setTimeout(() => {
            closeConfirmModal(action);
        }, 5000);
    }
}

function closeConfirmModal(action) {
    const modal = document.getElementById(action + 'Modal');
    if (modal) {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        if (modal.autoDismissTimer) {
            clearTimeout(modal.autoDismissTimer);
        }
    }
}

// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) {
            this.style.display = 'none';
            this.setAttribute('aria-hidden', 'true');
        }
    });
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
        });
    }
});
</script>
