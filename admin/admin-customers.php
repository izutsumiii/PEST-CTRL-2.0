<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

requireAdmin();

// Initialize variables
$message = '';
$messageType = '';

// Handle customer actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = sanitizeInput($_GET['action']);
    $customerId = intval($_GET['id']);
    
    try {
        switch ($action) {
            case 'deactivate':
                $stmt = $pdo->prepare("UPDATE users SET is_active = FALSE WHERE id = ? AND user_type = 'customer'");
                $stmt->execute([$customerId]);
                if ($stmt->rowCount() > 0) {
                    $message = "Customer deactivated successfully!";
                    $messageType = 'success';
                } else {
                    $message = "Customer not found or already inactive.";
                    $messageType = 'error';
                }
                break;
                
            case 'activate':
                $stmt = $pdo->prepare("UPDATE users SET is_active = TRUE WHERE id = ? AND user_type = 'customer'");
                $stmt->execute([$customerId]);
                if ($stmt->rowCount() > 0) {
                    $message = "Customer activated successfully!";
                    $messageType = 'success';
                } else {
                    $message = "Customer not found or already active.";
                    $messageType = 'error';
                }
                break;
                
            case 'delete':
                // Check if customer has any orders before deleting
                $stmt = $pdo->prepare("SELECT COUNT(*) as order_count FROM orders WHERE user_id = ?");
                $stmt->execute([$customerId]);
                $orderCount = $stmt->fetch(PDO::FETCH_ASSOC)['order_count'];
                
                if ($orderCount > 0) {
                    $message = "Cannot delete customer. Customer has {$orderCount} order(s). Please deactivate instead.";
                    $messageType = 'error';
                } else {
                    // Safe to delete - no orders associated
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND user_type = 'customer'");
                    $stmt->execute([$customerId]);
                    if ($stmt->rowCount() > 0) {
                        $message = "Customer deleted successfully!";
                        $messageType = 'success';
                    } else {
                        $message = "Customer not found or could not be deleted.";
                        $messageType = 'error';
                    }
                }
                break;
                
            default:
                $message = "Invalid action.";
                $messageType = 'error';
        }
    } catch (PDOException $e) {
        $message = "Database error: " . $e->getMessage();
        $messageType = 'error';
    }
    
    // Redirect to avoid resubmission on refresh
    $redirectUrl = 'admin-customers.php';
    if (isset($_GET['page'])) {
        $redirectUrl .= '?page=' . intval($_GET['page']);
    }
    if (!empty($message)) {
        $redirectUrl .= (strpos($redirectUrl, '?') !== false ? '&' : '?') . 'msg=' . urlencode($message) . '&type=' . $messageType;
    }
    header("Location: $redirectUrl");
    exit();
}

// Display messages from redirect
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message = sanitizeInput($_GET['msg']);
    $messageType = sanitizeInput($_GET['type']);
}

// Pagination setup
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Search and status filter functionality
$searchTerm = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : 'all'; // all|active|inactive
$searchCondition = '';
$searchParams = [];

if (!empty($searchTerm)) {
    $searchCondition = " AND (username LIKE ? OR email LIKE ? OR first_name LIKE ? OR last_name LIKE ?)";
    $searchTerm = "%{$searchTerm}%";
    $searchParams = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
}

// Check if is_active column exists
$columnExists = false;
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_active'");
    $columnExists = $stmt->rowCount() > 0;
} catch (PDOException $e) {
    $columnExists = false;
}

// Build query based on whether is_active column exists
if ($columnExists) {
    // Append status filter if requested
    $statusCondition = '';
    if ($statusFilter === 'active') {
        $statusCondition = ' AND is_active = 1';
    } elseif ($statusFilter === 'inactive') {
        $statusCondition = ' AND is_active = 0';
    }

    $query = "SELECT *, is_active as active_status FROM users WHERE user_type = 'customer'" . $searchCondition . $statusCondition . " ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $countQuery = "SELECT COUNT(*) as total FROM users WHERE user_type = 'customer'" . $searchCondition . $statusCondition;
} else {
    $query = "SELECT *, TRUE as active_status FROM users WHERE user_type = 'customer'" . $searchCondition . " ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $countQuery = "SELECT COUNT(*) as total FROM users WHERE user_type = 'customer'" . $searchCondition;
}

try {
    // Get customers
    $stmt = $pdo->prepare($query);
    $paramIndex = 1;
    foreach ($searchParams as $param) {
        $stmt->bindValue($paramIndex++, $param, PDO::PARAM_STR);
    }
    $stmt->bindValue($paramIndex++, $limit, PDO::PARAM_INT);
    $stmt->bindValue($paramIndex, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count
    $stmt = $pdo->prepare($countQuery);
    $paramIndex = 1;
    foreach ($searchParams as $param) {
        $stmt->bindValue($paramIndex++, $param, PDO::PARAM_STR);
    }
    $stmt->execute();
    $totalCustomers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalCustomers / $limit);

} catch (PDOException $e) {
    $message = "Error retrieving customers: " . $e->getMessage();
    $messageType = 'error';
    $customers = [];
    $totalCustomers = 0;
    $totalPages = 0;
}

// Function to get order count for a customer
function getCustomerOrderCount($pdo, $customerId) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM orders WHERE user_id = ?");
        $stmt->execute([$customerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    } catch (PDOException $e) {
        return 0;
    }
}
?>

<!-- Removed legacy h1 to place heading inside container -->

<?php // Removed legacy message block to avoid duplicate alerts ?>

<?php require_once 'includes/admin_header.php'; ?>

<?php if (!$columnExists): ?>
    <div class="warning-message">
        <p><strong>Warning:</strong> The 'is_active' column is missing from the database. Some functionality may be limited.</p>
        <p>Run this SQL query to fix: <code>ALTER TABLE users ADD COLUMN is_active BOOLEAN DEFAULT TRUE AFTER user_type;</code></p>
    </div>
<?php endif; ?>

<style>
    :root {
        --primary-dark: #1a0a2e;
        --secondary-dark: #130325;
        --accent-yellow: #FFD736;
        --text-light: #F9F9F9;
        --border-color: rgba(255, 215, 54, 0.3);
        --success-green: #28a745;
        --warning-yellow: #ffc107;
        --danger-red: #dc3545;
    }

    body {
        background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
        color: var(--text-light);
        min-height: 100vh;
        margin: 0;
        font-family: 'Inter', 'Segoe UI', sans-serif;
    }

    /* Message Alerts */
    .alert {
        max-width: 1400px;
        margin: 20px auto;
        padding: 16px 20px;
        border-radius: 10px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .alert-success {
        background: rgba(40, 167, 69, 0.15);
        color: var(--success-green);
        border: 2px solid var(--success-green);
    }
    
    .alert-warning {
        background: rgba(255, 193, 7, 0.15);
        color: var(--warning-yellow);
        border: 2px solid var(--warning-yellow);
    }
    
    .alert-error {
        background: rgba(220, 53, 69, 0.15);
        color: var(--danger-red);
        border: 2px solid var(--danger-red);
    }

    /* Page Header */
    .page-heading {
        font-size: 18px;
        font-weight: 800;
        color: var(--text-light);
        margin: 0 0 10px 0;
        padding: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .page-heading-title { font-size: 20px; font-weight: 800; color: var(--text-light); margin-left: 28px; margin-bottom: 20px;} 

    /* Main Container */
    .container {
        max-width: 1400px;
        margin: 0 auto 30px;
        padding: 0 20px;
    }

    /* Search Section */
    .search-section {
        background: #1a0a2e !important; /* force dark background on the container */
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 16px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.35);
        color: #f9f9f9;
    }
    .search-section .search-form { background: transparent !important; }

    .search-form { display: flex; gap: 12px; align-items: center; flex-wrap: nowrap; }

    .search-input {
        flex: 1;
        min-width: 300px;
        padding: 12px 16px;
        background: #0f0a1f; /* dark field */
        border: 1px solid #3a2b55;
        border-radius: 8px;
        color: #f9f9f9;
        font-size: 14px;
        transition: all 0.3s ease;
        height: 42px;
    }

    .search-input:focus {
        outline: none;
        border-color: #ffd736;
        box-shadow: 0 0 0 3px rgba(255, 215, 54, 0.12);
    }

    .search-input::placeholder { color: rgba(249, 249, 249, 0.6); }

    .filter-select {
        min-width: 150px;
        padding: 12px 16px;
        background: #0f0a1f; /* dark select */
        border: 2px solid #ffd736;
        border-radius: 8px;
        color: #f9f9f9;
        font-size: 14px;
        transition: all 0.3s ease;
        height: 42px;
    }

    .filter-select:focus {
        outline: none;
        border-color: #ffd736;
        box-shadow: 0 0 0 3px rgba(255, 215, 54, 0.12);
    }

    .filter-select option { background: #1a0a2e; color: #f9f9f9; }

    /* Buttons */
    .btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 12px 20px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 700;
        text-decoration: none;
        border: none;
        cursor: pointer;
        transition: all 0.3s ease;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.3);
    }

    .btn-search {
        background: linear-gradient(135deg, #ffd736, #FFE066);
        color: #1a0a2e;
        border: 2px solid #ffd736;
        height: 40px;
        width: 40px; /* square icon button */
        display: inline-flex;
        align-items: center;
        justify-content: center;
        line-height: 1;
        box-sizing: border-box;
        border-radius: 8px;
        padding: 0;
    }
    .btn-search i {
        color: #1a0a2e;
        font-size: 14px;
        line-height: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        vertical-align: middle;
        transform: translateY(0); /* ensure no offset */
        margin-top: 0;
        margin-bottom: 0;
    }
    .btn-search:hover { filter: brightness(0.96); }
    .btn-search:focus { outline: none; box-shadow: 0 0 0 3px rgba(255, 215, 54, 0.18); }
    .btn-search:active { transform: translateY(0); filter: brightness(0.92); }

    .btn-search:hover {
        background: linear-gradient(135deg, #FFE066, var(--accent-yellow));
    }

    .btn-clear {
        background: rgba(108, 117, 125, 0.2);
        color: #6c757d;
        border: 2px solid #6c757d;
    }

    .btn-clear:hover {
        background: #6c757d;
        color: white;
    }

    /* Page Title */
    .page-title {
        font-size: 24px;
        font-weight: 700;
        color: var(--accent-yellow);
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .page-title i {
        font-size: 28px;
    }

    /* Table Container */
    .table-container {
        background: var(--primary-dark);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
        margin-bottom: 20px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    thead {
        background: rgba(255, 215, 54, 0.1);
    }

    th {
        padding: 16px 12px;
        text-align: left;
        font-size: 13px;
        color: var(--accent-yellow);
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 2px solid var(--accent-yellow);
        vertical-align: middle;
    }

    thead th i { font-size: 14px; position: relative; top: 1px; margin-right: 6px; }

    td {
        padding: 16px 12px;
        border-bottom: 1px solid var(--border-color);
        font-size: 14px;
        color: var(--text-light);
    }

    tr:hover {
        background: rgba(255, 255, 255, 0.02);
    }

    /* Status Badges */
    .status-badge {
        display: inline-block;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.5px;
        text-transform: uppercase;
    }

    .status-active {
        background: rgba(40, 167, 69, 0.2);
        color: var(--success-green);
        border: 1px solid var(--success-green);
    }

    .status-inactive {
        background: rgba(220, 53, 69, 0.2);
        color: var(--danger-red);
        border: 1px solid var(--danger-red);
    }

    /* Action Buttons */
    .action-buttons { display: flex; gap: 8px; flex-wrap: wrap; }

    .btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 20px; border-radius: 8px; font-size: 14px; font-weight: 700; text-decoration: none; border: none; cursor: pointer; transition: all 0.3s ease; text-transform: uppercase; letter-spacing: 0.5px; }
    .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.3); }

    .btn-view { background: linear-gradient(135deg, #3498db, #5dade2); color: white; }
    .btn-activate { background: linear-gradient(135deg, var(--success-green), #20c997); color: white; }
    .btn-deactivate { background: linear-gradient(135deg, var(--warning-yellow), #e6a800); color: #1a0a2e; }
    .btn-delete { background: linear-gradient(135deg, var(--danger-red), #e74c3c); color: white; }

    /* Change Status button */
    .btn-change-status { background: linear-gradient(135deg, var(--accent-yellow), var(--accent-yellow)); color: #1a0a2e; border: 2px solid var(--accent-yellow); }

    /* Pagination */
    .pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 20px;
    }

    .page-link {
        padding: 10px 16px;
        background: var(--primary-dark);
        color: var(--accent-yellow);
        text-decoration: none;
        border-radius: 6px;
        border: 1px solid var(--border-color);
        font-weight: 700;
        font-size: 14px;
        transition: all 0.3s ease;
    }

    .page-link:hover {
        background: var(--accent-yellow);
        color: var(--primary-dark);
        transform: translateY(-2px);
    }

    .page-link.active {
        background: var(--accent-yellow);
        color: var(--primary-dark);
    }

    .page-ellipsis {
        padding: 10px 8px;
        color: rgba(249, 249, 249, 0.5);
        font-weight: 700;
    }

    .pagination-info {
        text-align: center;
        margin-top: 16px;
        color: rgba(249, 249, 249, 0.7);
        font-size: 14px;
        font-weight: 500;
    }

    /* Custom Confirmation Dialog Styles */
    .custom-confirm-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.8);
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
        background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-dark) 100%);
        border: 2px solid var(--accent-yellow);
        border-radius: 12px;
        padding: 24px;
        max-width: 420px;
        width: 92%;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
        transform: translateY(-8px) scale(0.98);
        transition: transform 0.25s ease;
    }

    .custom-confirm-overlay.show .custom-confirm-dialog { transform: translateY(0) scale(1); }

    .custom-confirm-header { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
    .custom-confirm-icon { width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 20px; }
    .custom-confirm-icon.warning { background: linear-gradient(135deg, var(--warning-yellow), #e6a800); color: #1a0a2e; }
    .custom-confirm-icon.danger { background: linear-gradient(135deg, var(--danger-red), #e74c3c); }
    .custom-confirm-title { font-size: 18px; font-weight: 800; color: var(--text-light); margin: 0; }
    .custom-confirm-message { color: rgba(249, 249, 249, 0.85); font-size: 14px; line-height: 1.5; margin-bottom: 18px; }
    .custom-confirm-buttons { display: flex; gap: 10px; justify-content: flex-end; }
    .custom-confirm-btn { padding: 10px 16px; border-radius: 8px; font-size: 12px; font-weight: 800; border: 2px solid transparent; cursor: pointer; transition: all 0.2s ease; text-transform: uppercase; letter-spacing: 0.5px; }
    .custom-confirm-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.3); }
    .custom-confirm-btn.cancel { background: rgba(108,117,125,0.15); color: #adb5bd; border-color: #6c757d; }
    .custom-confirm-btn.cancel:hover { background: #6c757d; color: #fff; }
    .custom-confirm-btn.warning { background: linear-gradient(135deg, var(--warning-yellow), #e6a800); color: #1a0a2e; border-color: var(--warning-yellow); }
    .custom-confirm-btn.confirm { background: linear-gradient(135deg, var(--danger-red), #e74c3c); color: #fff; border-color: var(--danger-red); }

    /* Responsive */
    @media (max-width: 768px) {
        .search-form {
            flex-direction: column;
            align-items: stretch;
        }
        
        .search-input {
            min-width: auto;
        }
        
        .action-buttons {
            flex-direction: column;
        }
        
        .btn {
            width: 100%;
            justify-content: center;
        }
    }

    /* Status Modal */
    .status-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.8); display: none; align-items: center; justify-content: center; z-index: 11000; }
    .status-modal-overlay.show { display: flex; }
    .status-modal { background: linear-gradient(135deg, #1a0a2e 0%, #130325 100%); border: 2px solid var(--accent-yellow); border-radius: 12px; padding: 22px; width: 92%; max-width: 420px; box-shadow: 0 20px 40px rgba(0,0,0,0.5); }
    .status-modal h3 { margin: 0 0 12px 0; color: #F9F9F9; font-size: 18px; font-weight: 800; }
    .status-modal .modal-row { display: flex; flex-direction: column; gap: 10px; margin-bottom: 14px; }
    .status-modal label { color: rgba(249,249,249,0.85); font-size: 14px; font-weight: 700; }
    .status-modal select { background: #0f0a1f; color: #F9F9F9; border: 2px solid var(--accent-yellow); border-radius: 8px; padding: 10px 12px; font-size: 14px; }
    .status-modal .confirm-row { display: flex; align-items: center; gap: 10px; color: rgba(249,249,249,0.9); font-size: 13px; }
    .status-modal .modal-actions { display: flex; justify-content: flex-end; gap: 10px; }
    .status-modal .btn-cancel { background: rgba(108,117,125,0.2); color: #adb5bd; border: 2px solid #6c757d; }
    .status-modal .btn-apply { background: linear-gradient(135deg, var(--accent-yellow), var(--accent-yellow)); color: #1a0a2e; border: 2px solid var(--accent-yellow); }
</style>

    <h1 class="page-heading">
        <span class="page-heading-title">Manage Customers</span>
        <span style="color: rgba(249,249,249,0.7); font-size: 16px; font-weight: 600; margin-bottom: 20px;">
        (<?php echo $totalCustomers; ?> total<?php echo !empty($searchTerm) ? ', filtered results' : ''; ?>)
        </span>
    </h1>

<div class="container">
    <!-- Search Form -->
    <div class="search-section">
        <form method="GET" action="admin-customers.php" class="search-form">
            <?php if ($columnExists): ?>
                <select name="status" class="filter-select" title="Filter by status" onchange="this.form.submit()">
                    <option value="all" <?php echo ($statusFilter==='all')?'selected':''; ?>>All customers</option>
                    <option value="active" <?php echo ($statusFilter==='active')?'selected':''; ?>>Active</option>
                    <option value="inactive" <?php echo ($statusFilter==='inactive')?'selected':''; ?>>Inactive</option>
                </select>
            <?php endif; ?>
            <input type="text" name="search" placeholder="Search by customer..." 
                   value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" class="search-input">
            <button type="submit" class="btn btn-search" aria-label="Search">
                <i class="fas fa-search" aria-hidden="true"></i>
            </button>
            <?php if (!empty($searchTerm) || ($columnExists && $statusFilter !== 'all')): ?>
                <a href="admin-customers.php" class="btn btn-clear" title="Clear filters">
                    <i class="fas fa-times"></i>
                </a>
            <?php endif; ?>
        </form>
    </div>


    
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?>" id="page-alert">
            <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : ($messageType === 'warning' ? 'exclamation-triangle' : 'times-circle'); ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if (empty($customers)): ?>
        <div style="text-align: center; padding: 40px; color: rgba(249, 249, 249, 0.6);">
            <i class="fas fa-users" style="font-size: 48px; display: block; margin-bottom: 16px; opacity: 0.5;"></i>
        <p><?php echo !empty($searchTerm) ? 'No customers found matching your search.' : 'No customers found.'; ?></p>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th>Registered</th>
                        <th>Orders</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $customer): ?>
                        <?php $orderCount = getCustomerOrderCount($pdo, $customer['id']); ?>
                        <tr>
                            <td><strong>#<?php echo htmlspecialchars($customer['id']); ?></strong></td>
                            <td><?php echo htmlspecialchars($customer['username']); ?></td>
                            <td><?php echo htmlspecialchars($customer['email']); ?></td>
                            <td>
                                <?php 
                                $fullName = trim($customer['first_name'] . ' ' . $customer['last_name']);
                                echo htmlspecialchars($fullName ?: 'N/A'); 
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($customer['phone'] ?: 'N/A'); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $customer['active_status'] ? 'active' : 'inactive'; ?>">
                                    <i class="fas fa-<?php echo $customer['active_status'] ? 'check-circle' : 'times-circle'; ?>"></i>
                                    <?php echo $customer['active_status'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                echo $customer['created_at'] ? date('M j, Y', strtotime($customer['created_at'])) : 'N/A'; 
                                ?>
                            </td>
                            <td>
                                <span style="background: rgba(255, 215, 54, 0.1); padding: 4px 8px; border-radius: 12px; font-weight: 600;">
                                    <?php echo $orderCount; ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="user-details.php?id=<?php echo $customer['id']; ?>" 
                                       class="btn btn-view" title="View Customer Details">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <?php if ($columnExists): ?>
                                        <button type="button" class="btn btn-change-status" 
                                                data-user-id="<?php echo $customer['id']; ?>" 
                                                data-current-status="<?php echo $customer['active_status'] ? 'active' : 'inactive'; ?>">
                                            <i class="fas fa-exchange-alt"></i> Change Status
                                        </button>
                                    <?php else: ?>
                                        <span style="color: #6c757d; font-size: 12px;" title="Database column missing">Action unavailable</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php
                $baseUrl = 'admin-customers.php?';
                if (!empty($_GET['search'])) {
                    $baseUrl .= 'search=' . urlencode($_GET['search']) . '&';
                }
                if ($columnExists && isset($_GET['status']) && in_array($_GET['status'], ['all','active','inactive'])) {
                    $baseUrl .= 'status=' . urlencode($_GET['status']) . '&';
                }
                ?>
                
                <?php if ($page > 1): ?>
                    <a href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>" class="page-link"><i class="fas fa-chevron-left"></i> Previous</a>
                <?php endif; ?>
                
                <?php
                // Show pagination numbers with ellipsis for large page counts
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                if ($startPage > 1): ?>
                    <a href="<?php echo $baseUrl; ?>page=1" class="page-link">1</a>
                    <?php if ($startPage > 2): ?>
                        <span class="page-ellipsis">...</span>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <a href="<?php echo $baseUrl; ?>page=<?php echo $i; ?>" 
                       class="page-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <span class="page-ellipsis">...</span>
                    <?php endif; ?>
                    <a href="<?php echo $baseUrl; ?>page=<?php echo $totalPages; ?>" class="page-link"><?php echo $totalPages; ?></a>
                <?php endif; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>" class="page-link">Next <i class="fas fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
            
            <div class="pagination-info">
                Showing <?php echo ($offset + 1); ?>-<?php echo min($offset + $limit, $totalCustomers); ?> 
                of <?php echo $totalCustomers; ?> customers
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
// Custom Confirmation Dialog System (matches detail view styling)
function showCustomConfirm(title, message, type = 'warning', onConfirm) {
    const existing = document.querySelector('.custom-confirm-overlay');
    if (existing) existing.remove();

    const overlay = document.createElement('div');
    overlay.className = 'custom-confirm-overlay';

    const dialog = document.createElement('div');
    dialog.className = 'custom-confirm-dialog';

    const header = document.createElement('div');
    header.className = 'custom-confirm-header';

    const icon = document.createElement('div');
    icon.className = `custom-confirm-icon ${type === 'danger' ? 'danger' : 'warning'}`;
    icon.innerHTML = type === 'danger' ? '<i class="fas fa-exclamation-circle"></i>' : '<i class="fas fa-exclamation-triangle"></i>';

    const titleEl = document.createElement('h3');
    titleEl.className = 'custom-confirm-title';
    titleEl.textContent = title;

    const msgEl = document.createElement('div');
    msgEl.className = 'custom-confirm-message';
    msgEl.textContent = message;

    const btns = document.createElement('div');
    btns.className = 'custom-confirm-buttons';

    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'custom-confirm-btn cancel';
    cancelBtn.textContent = 'Cancel';

    const confirmBtn = document.createElement('button');
    confirmBtn.className = `custom-confirm-btn ${type === 'danger' ? 'confirm' : 'warning'}`;
    confirmBtn.textContent = type === 'danger' ? 'Delete' : 'Confirm';

    header.appendChild(icon);
    header.appendChild(titleEl);
    dialog.appendChild(header);
    dialog.appendChild(msgEl);
    btns.appendChild(cancelBtn);
    btns.appendChild(confirmBtn);
    dialog.appendChild(btns);
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);

    requestAnimationFrame(() => overlay.classList.add('show'));

    function close() {
        overlay.classList.remove('show');
        setTimeout(() => overlay.remove(), 250);
    }

    cancelBtn.addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    confirmBtn.addEventListener('click', () => { close(); if (typeof onConfirm === 'function') onConfirm(); });
}

document.addEventListener('DOMContentLoaded', function() {
    // Auto-dismiss page alert after 1.2s
    const alertEl = document.getElementById('page-alert');
    if (alertEl) {
        setTimeout(() => {
            alertEl.style.transition = 'opacity 250ms ease, transform 250ms ease';
            alertEl.style.opacity = '0';
            alertEl.style.transform = 'translateY(-6px)';
            setTimeout(() => alertEl.remove(), 260);
        }, 1200);
    }

    // Deactivate
    document.querySelectorAll('a[href*="action=deactivate"]').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            showCustomConfirm(
                'Deactivate Customer',
                'Are you sure you want to deactivate this customer? They will not be able to log in.',
                'warning',
                () => { window.location.href = link.href; }
            );
        });
    });

    // Activate
    document.querySelectorAll('a[href*="action=activate"]').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            showCustomConfirm(
                'Activate Customer',
                'Are you sure you want to activate this customer?',
                'warning',
                () => { window.location.href = link.href; }
            );
        });
    });

    // Delete
    document.querySelectorAll('a[href*="action=delete"]').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            showCustomConfirm(
                'Delete Customer',
                'Are you sure you want to delete this customer? This action cannot be undone. Customers with orders cannot be deleted.',
                'danger',
                () => { window.location.href = link.href; }
            );
        });
    });

    // Change Status modal wiring
    const statusOverlay = document.createElement('div');
    statusOverlay.className = 'status-modal-overlay';
    statusOverlay.innerHTML = `
        <div class="status-modal">
            <h3>Change Customer Status</h3>
            <div class="modal-row">
                <label for="status-action">Select action</label>
                <select id="status-action">
                    <option value="activate">Activate</option>
                    <option value="deactivate">Deactivate</option>
                    <option value="delete">Delete</option>
                </select>
            </div>
            <div class="confirm-row">
                <input type="checkbox" id="status-confirm" />
                <label for="status-confirm">I confirm this change</label>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-cancel" id="status-cancel">Cancel</button>
                <button type="button" class="btn btn-apply" id="status-apply">Apply</button>
            </div>
        </div>`;
    document.body.appendChild(statusOverlay);

    let statusTargetUserId = null;
    document.querySelectorAll('.btn-change-status').forEach(btn => {
        btn.addEventListener('click', () => {
            statusTargetUserId = btn.getAttribute('data-user-id');
            const current = btn.getAttribute('data-current-status');
            const select = statusOverlay.querySelector('#status-action');
            // default suggestion opposite of current
            select.value = current === 'active' ? 'deactivate' : 'activate';
            statusOverlay.classList.add('show');
        });
    });

    statusOverlay.addEventListener('click', (e) => {
        if (e.target === statusOverlay) statusOverlay.classList.remove('show');
    });
    statusOverlay.querySelector('#status-cancel').addEventListener('click', () => statusOverlay.classList.remove('show'));
    statusOverlay.querySelector('#status-apply').addEventListener('click', () => {
        const action = statusOverlay.querySelector('#status-action').value;
        const confirmed = statusOverlay.querySelector('#status-confirm').checked;
        if (!confirmed) {
            showCustomConfirm('Confirmation required', 'Please check the confirmation box to proceed.', 'warning');
            return;
        }
        if (!statusTargetUserId) return;
        const url = `admin-customers.php?action=${action}&id=${encodeURIComponent(statusTargetUserId)}&page=${encodeURIComponent(<?php echo json_encode($page); ?>)}`;
        window.location.href = url;
    });
});
</script>

