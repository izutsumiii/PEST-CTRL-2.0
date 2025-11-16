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
        background: #f0f2f5 !important;
        color: #130325 !important;
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

    /* Page Header - override admin_header.php */
    .page-admin-customers .page-heading,
    .page-heading {
        font-size: 18px !important;
        font-weight: 800 !important;
        color: #130325 !important;
        margin: -80px 0 20px 0 !important;
        padding: 0 20px !important;
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
        flex-wrap: wrap !important;
        max-width: 1400px !important;
        margin-left: auto !important;
        margin-right: auto !important;
        text-shadow: none !important;
    }
    .page-heading-title { 
        font-size: 20px; 
        font-weight: 800; 
        color: #130325; 
        margin-top: 60px;
        margin-bottom: 0;
        margin-left: 0;
        margin-right: 0;
        display: inline-flex;
        align-items: center;
        text-shadow: none !important;
    }
    
    /* Total count styling */
    .total-count-badge {
        display: inline-flex;
        align-items: center;
        background: #130325;
        color: #ffffff;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 14px;
        font-weight: 700;
        margin-top: 60px;
        margin-bottom: 0;
        margin-left: 0;
        margin-right: 0;
        border: 1px solid #130325;
    } 

    /* Main Container */
    .container {
        max-width: 1400px;
        margin: -10px auto 30px;
        padding: 0 20px;
    }

    /* Search Section - same width as table container - override admin_header.php */
    body.page-admin-customers .search-section,
    .page-admin-customers .container .search-section,
    .page-admin-customers .search-section,
    .container .search-section,
    .search-section { 
        background: #ffffff !important; 
        border: 1px solid rgba(0,0,0,0.1) !important; 
        border-radius: 8px !important; 
        padding: 24px !important; 
        margin: 0 0 16px 0 !important;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1) !important; 
        color: #130325 !important;
        width: 100% !important;
        box-sizing: border-box !important;
        display: block !important;
        min-width: 0 !important;
        max-width: 100% !important;
    }
    .search-section .search-form { background: transparent !important; }

    .page-admin-customers .search-form,
    .container .search-form,
    .search-section .search-form,
    .search-form { 
        display: flex !important; 
        gap: 12px !important; 
        align-items: center !important; 
        justify-content: center !important;
        flex-wrap: nowrap !important;
        width: 100% !important;
        max-width: 100% !important;
    }

    /* Search Input - override admin_header.php width: 360px - match admin-sellers.PHP */
    body.page-admin-customers .search-input,
    .page-admin-customers .search-form .search-input,
    .page-admin-customers .search-section .search-input,
    .container .search-form .search-input,
    .search-section .search-form .search-input,
    .search-form .search-input,
    .search-input { 
        flex: 1 !important; 
        min-width: 260px !important; 
        max-width: 320px !important;
        width: auto !important;
        padding: 10px 14px !important; 
        background: #ffffff !important; 
        border: 2px solid #e5e7eb !important; 
        border-radius: 8px !important; 
        color: #130325 !important; 
        font-size: 14px !important; 
        transition: all 0.2s ease !important; 
        height: 40px !important; 
    }

    body.page-admin-customers .search-input:focus,
    .page-admin-customers .search-form .search-input:focus,
    .search-section .search-form .search-input:focus,
    .search-form .search-input:focus,
    .search-input:focus { 
        outline: none !important; 
        border-color: #FFD736 !important; 
        box-shadow: 0 0 0 3px rgba(255,215,54,0.12) !important; 
    }

    body.page-admin-customers .search-input::placeholder,
    .page-admin-customers .search-form .search-input::placeholder,
    .search-section .search-form .search-input::placeholder,
    .search-form .search-input::placeholder,
    .search-input::placeholder { 
        color: #9ca3af !important; 
    }

    .filter-select { min-width: 150px; padding: 10px 14px; background: #ffffff; border: 2px solid #e5e7eb; border-radius: 8px; color: #130325; font-size: 14px; transition: all 0.2s ease; height: 40px; }

    .filter-select:focus { outline: none; border-color: #FFD736; box-shadow: 0 0 0 3px rgba(255,215,54,0.12); }

    .filter-select option { background: #ffffff; color: #130325; }

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
        background: linear-gradient(135deg, #FFD736, #FFC107); 
        color: #130325; 
        border: none; 
        height: 40px; 
        width: 40px; 
        min-width: 40px;
        display: inline-flex; 
        align-items: center; 
        justify-content: center; 
        line-height: 1; 
        box-sizing: border-box; 
        border-radius: 8px; 
        padding: 0; 
        flex-shrink: 0;
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

    .btn-clear { background: #ffffff; color: #6b7280; border: 2px solid #e5e7eb; }

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

    /* Table Container - exact match from manage-products.php - override admin_header.php */
    .page-admin-customers .table-container,
    .container .table-container,
    .table-container {
        background: #ffffff !important;
        border: 1px solid rgba(0,0,0,0.1) !important;
        border-radius: 8px !important;
        padding: 24px !important;
        margin: 0 0 20px 0 !important;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1) !important;
        width: 100% !important;
        box-sizing: border-box !important;
        display: block !important;
        min-width: 0 !important;
        max-width: 100% !important;
        overflow-x: visible !important;
    }

    .table-wrapper {
        overflow-x: visible;
        overflow-y: visible;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
        min-width: 800px;
    }
    
    /* Responsive table */
    @media (max-width: 1200px) {
        .table-wrapper {
            overflow-x: visible;
            overflow-y: visible;
        }
        
        table {
            font-size: 13px;
        }
        
        th, td {
            padding: 10px 12px;
            font-size: 12px;
        }
        
        .status-badge {
            min-width: 70px;
            max-width: 70px;
            font-size: 10px;
            padding: 3px 8px;
        }
    }

    thead {
        background: #130325 !important;
        border-bottom: 2px solid #130325;
    }

    th {
        padding: 12px 16px;
        text-align: left;
        font-weight: 700;
        color: #ffffff !important;
        background: #130325 !important;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        position: relative;
        user-select: none;
        vertical-align: middle;
    }

    th.sortable {
        cursor: pointer;
        transition: background 0.2s ease;
        padding-right: 32px;
    }

    th.sortable:hover {
        background: #130325 !important;
    }

    td {
        padding: 14px 16px;
        color: #130325;
        vertical-align: middle;
        border-bottom: 1px solid #f0f0f0;
        background: #ffffff;
        font-size: 14px;
    }

    tbody tr {
        border-bottom: 1px solid #f0f0f0;
        transition: background 0.2s ease;
    }

    tbody tr:hover {
        background: rgba(255, 215, 54, 0.05);
    }

    /* Sortable headers */
    .sort-indicator {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 10px;
        color: rgba(255, 255, 255, 0.7);
        transition: all 0.2s ease;
    }

    .sort-indicator::before {
        content: '↕';
        display: block;
    }

    th.sort-asc .sort-indicator::before {
        content: '↑';
        color: rgba(255, 255, 255, 0.9);
    }

    th.sort-desc .sort-indicator::before {
        content: '↓';
        color: rgba(255, 255, 255, 0.9);
    }

    /* Status Badges - fixed size */
    .status-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.3px;
        text-transform: uppercase;
        min-width: 75px;
        max-width: 75px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
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

    /* Action Buttons - no borders */
    .table-actions {
        display: flex;
        gap: 8px;
        flex-wrap: nowrap;
    }

    .action-btn {
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 4px;
        transition: all 0.2s ease;
        text-decoration: none;
        color: #130325;
        border: none;
        position: relative;
    }
    
    .action-btn i {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-size: 14px;
        line-height: 1;
    }

    .btn-view, .edit-btn {
        background: #130325;
        color: #ffffff;
    }

    .btn-view:hover, .edit-btn:hover {
        background: #0a0218;
        transform: scale(1.1);
    }

    .btn-change-status, .status-btn {
        background: #FFD736;
        color: #130325;
    }

    .btn-change-status:hover, .status-btn:hover {
        background: #f5d026;
        transform: scale(1.1);
    }

    /* Pagination */
    .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
    .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
    .page-link { padding: 8px 14px; background: #ffffff !important; color: #130325 !important; text-decoration: none; border-radius: 6px; border: 1px solid #e5e7eb !important; font-weight: 600; font-size: 13px; transition: all 0.2s ease; }
    .page-link i { color: #130325 !important; }
    .page-link:hover { background: #f8f9fa !important; color: #130325 !important; border-color: #130325 !important; transform: translateY(-1px); }
    .page-link:hover i { color: #130325 !important; }
    .page-link.active { background: #130325 !important; color: #ffffff !important; border-color: #130325 !important; box-shadow: 0 2px 8px rgba(19, 3, 37, 0.3); }
    .page-link.active i { color: #ffffff !important; }
    .page-ellipsis { padding: 8px 8px; color: #9ca3af; font-weight: 700; }
    .pagination-info { text-align: center; margin-top: 12px; color: #6b7280; font-size: 13px; font-weight: 500; }

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
        
        .action-buttons, .table-actions {
            flex-direction: row;
            flex-wrap: nowrap;
        }
        
        .btn {
            width: 100%;
            justify-content: center;
        }
    }

    /* (Removed legacy status modal styles; using logout-style confirm instead) */


    /* Enhanced Mobile Responsive Styles */
@media (max-width: 768px) {
    /* Container adjustments */
    .container {
        padding: 0 12px;
        margin-top: 0;
    }
    
    /* Page heading - stack vertically */
    .page-heading {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 8px !important;
        padding: 0 12px !important;
    }
    
    .page-heading-title {
        margin-top: 40px !important;
        font-size: 18px !important;
    }
    
    .total-count-badge {
        margin-top: 0 !important;
        font-size: 13px;
        padding: 5px 12px;
    }
    
    /* Search section - stack inputs */
    .search-section {
        padding: 16px !important;
    }
    
    .search-form {
        flex-direction: column !important;
        gap: 10px !important;
    }
    
    .search-input,
    .filter-select {
        min-width: 100% !important;
        max-width: 100% !important;
        width: 100% !important;
    }
    
    .btn-search,
    .btn-clear {
        width: 100% !important;
        height: 44px !important;
        font-size: 14px;
    }
    
    .btn-search {
        justify-content: center;
        gap: 8px;
    }
    
    .btn-search::after {
        content: 'Search';
        margin-left: 4px;
    }
    
    /* Table container - card style on mobile */
    .table-container {
        padding: 0 !important;
        border: none !important;
        background: transparent !important;
        box-shadow: none !important;
    }
    
    .table-wrapper {
        overflow-x: visible !important;
    }
    
    /* Hide table, show cards */
    table {
        display: none;
    }
    
    /* Mobile card layout */
    .mobile-card-container {
        display: block;
    }
    
    .customer-card {
        background: #ffffff;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }
    
    .customer-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 12px;
        padding-bottom: 12px;
        border-bottom: 1px solid #f0f0f0;
    }
    
    .customer-card-id {
        font-size: 16px;
        font-weight: 700;
        color: #130325;
    }
    
    .customer-card-status {
        /* Status badge styles already defined */
    }
    
    .customer-card-body {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    
    .customer-card-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 6px 0;
    }
    
    .customer-card-label {
        font-size: 12px;
        font-weight: 600;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .customer-card-value {
        font-size: 14px;
        font-weight: 500;
        color: #130325;
        text-align: right;
        max-width: 60%;
        word-break: break-word;
    }
    
    .customer-card-actions {
        display: flex;
        gap: 8px;
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px solid #f0f0f0;
    }
    
    .customer-card-actions .action-btn {
        flex: 1;
        width: auto;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        font-size: 13px;
        font-weight: 600;
    }
    
    .customer-card-actions .action-btn i {
        position: static;
        transform: none;
    }
    
    .customer-card-actions .btn-view::after {
        content: 'View';
    }
    
    .customer-card-actions .btn-change-status::after {
        content: attr(data-action-label);
    }
    
    /* Pagination adjustments */
    .pagination {
        flex-wrap: wrap;
        gap: 6px;
    }
    
    .page-link {
        padding: 6px 10px;
        font-size: 12px;
    }
    
    .pagination-info {
        font-size: 12px;
        margin-top: 10px;
    }
    
    /* Alert adjustments */
    .alert {
        margin: 12px;
        padding: 12px 16px;
        font-size: 13px;
    }
}

/* Tablet adjustments */
@media (min-width: 769px) and (max-width: 1024px) {
    .container {
        max-width: 100%;
        padding: 0 16px;
    }
    
    .search-input {
        min-width: 200px !important;
        max-width: 280px !important;
    }
    
    table {
        font-size: 13px;
    }
    
    th, td {
        padding: 10px 12px;
    }
}

/* Hide mobile cards on desktop */
@media (min-width: 769px) {
    .mobile-card-container {
        display: none;
    }
}
</style>

<script>

// Generate mobile-friendly cards from table data
function generateMobileCards() {
    if (window.innerWidth > 768) return;
    
    const table = document.getElementById('customersTable');
    if (!table) return;
    
    const tbody = table.querySelector('tbody');
    if (!tbody) return;
    
    let container = document.querySelector('.mobile-card-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'mobile-card-container';
        table.parentNode.insertBefore(container, table);
    }
    
    container.innerHTML = '';
    
    const rows = tbody.querySelectorAll('tr');
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length < 9) return;
        
        const card = document.createElement('div');
        card.className = 'customer-card';
        
        const id = cells[0].textContent.trim();
        const username = cells[1].textContent.trim();
        const email = cells[2].textContent.trim();
        const name = cells[3].textContent.trim();
        const phone = cells[4].textContent.trim();
        const statusBadge = cells[5].querySelector('.status-badge');
        const registered = cells[6].textContent.trim();
        const orders = cells[7].textContent.trim();
        const actions = cells[8].querySelector('.table-actions');
        
        const statusClass = statusBadge ? statusBadge.className : '';
        const statusText = statusBadge ? statusBadge.textContent.trim() : '';
        
        card.innerHTML = `
            <div class="customer-card-header">
                <div class="customer-card-id">${id}</div>
                <span class="${statusClass}">${statusText}</span>
            </div>
            <div class="customer-card-body">
                <div class="customer-card-row">
                    <span class="customer-card-label">Username</span>
                    <span class="customer-card-value">${username}</span>
                </div>
                <div class="customer-card-row">
                    <span class="customer-card-label">Email</span>
                    <span class="customer-card-value">${email}</span>
                </div>
                <div class="customer-card-row">
                    <span class="customer-card-label">Name</span>
                    <span class="customer-card-value">${name}</span>
                </div>
                <div class="customer-card-row">
                    <span class="customer-card-label">Phone</span>
                    <span class="customer-card-value">${phone}</span>
                </div>
                <div class="customer-card-row">
                    <span class="customer-card-label">Registered</span>
                    <span class="customer-card-value">${registered}</span>
                </div>
                <div class="customer-card-row">
                    <span class="customer-card-label">Orders</span>
                    <span class="customer-card-value">${orders}</span>
                </div>
            </div>
            <div class="customer-card-actions"></div>
        `;
        
        if (actions) {
            const cardActions = card.querySelector('.customer-card-actions');
            const actionButtons = actions.querySelectorAll('.action-btn');
            actionButtons.forEach(btn => {
                const newBtn = btn.cloneNode(true);
                if (newBtn.classList.contains('btn-change-status')) {
                    const currentStatus = newBtn.getAttribute('data-current-status');
                    newBtn.setAttribute('data-action-label', currentStatus === 'active' ? 'Deactivate' : 'Activate');
                }
                cardActions.appendChild(newBtn);
            });
        }
        
        container.appendChild(card);
    });
    
    // Re-attach event listeners for mobile cards
    attachMobileEventListeners();
}

function attachMobileEventListeners() {
    // Re-attach status toggle listeners
    document.querySelectorAll('.mobile-card-container .btn-change-status').forEach(btn => {
        btn.addEventListener('click', () => {
            const userId = btn.getAttribute('data-user-id');
            const current = btn.getAttribute('data-current-status');
            const action = current === 'active' ? 'deactivate' : 'activate';
            const label = action === 'activate' ? 'Activate' : 'Deactivate';
            const msg = action === 'activate'
                ? 'Activate this customer?'
                : 'Deactivate this customer? They will not be able to log in.';
            const url = `admin-customers.php?action=${action}&id=${encodeURIComponent(userId)}&page=<?php echo $page; ?>`;
            adminShowConfirm(msg, label).then(ok => { if (ok) window.location.href = url; });
        });
    });
}

// Call on load and resize
window.addEventListener('load', generateMobileCards);
window.addEventListener('resize', generateMobileCards);




// Reusable confirm modal matching logout style
function adminShowConfirm(message, confirmText) {
  return new Promise(function(resolve){
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:10000;opacity:0;transition:opacity .2s ease';
    const dialog = document.createElement('div');
    dialog.style.cssText = 'background:#ffffff;border-radius:12px;overflow:hidden;min-width:320px;max-width:420px;box-shadow:0 10px 40px rgba(0,0,0,0.2)';
    const header = document.createElement('div');
    header.style.cssText = 'background:#130325;color:#ffffff;padding:16px 20px;display:flex;align-items:center;gap:10px;';
    header.innerHTML = '<h3 style="margin:0;font-size:14px;font-weight:700;color:#ffffff;">Confirm Action</h3>';
    const body = document.createElement('div');
    body.style.cssText = 'padding:20px;color:#130325;font-size:13px;';
    body.textContent = message;
    const footer = document.createElement('div');
    footer.style.cssText = 'padding:12px 16px;display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #e5e7eb;';
    const cancelBtn = document.createElement('button');
    cancelBtn.textContent = 'Cancel';
    cancelBtn.style.cssText = 'padding:8px 16px;background:#f3f4f6;color:#130325;border:1px solid #e5e7eb;border-radius:6px;font-weight:600;cursor:pointer;';
    const okBtn = document.createElement('button');
    okBtn.textContent = confirmText || 'Confirm';
    okBtn.style.cssText = 'padding:8px 16px;background:#130325;color:#ffffff;border:none;border-radius:6px;font-weight:600;cursor:pointer;';
    footer.appendChild(cancelBtn); footer.appendChild(okBtn);
    dialog.appendChild(header); dialog.appendChild(body); dialog.appendChild(footer);
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);
    requestAnimationFrame(()=> overlay.style.opacity = '1');
    function close(res){ overlay.style.opacity='0'; setTimeout(()=>overlay.remove(),150); resolve(res); }
    overlay.addEventListener('click', (e)=>{ if (e.target===overlay) close(false); });
    cancelBtn.addEventListener('click', ()=> close(false));
    okBtn.addEventListener('click', ()=> close(true));
  });
}

// Hook admin customer action links
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('a[href*="admin-customers.php?action="]').forEach(function(link){
    link.addEventListener('click', function(e){
      e.preventDefault();
      const url = new URL(link.href, window.location.origin);
      const action = url.searchParams.get('action') || '';
      let msg = 'Are you sure?'; let label = 'Confirm';
      if (action==='activate') { msg = 'Activate this customer?'; label = 'Activate'; }
      else if (action==='deactivate') { msg = 'Deactivate this customer?'; label = 'Deactivate'; }
      else if (action==='delete') { msg = 'Delete this customer? This cannot be undone.'; label = 'Delete'; }
      adminShowConfirm(msg, label).then((ok)=>{ if (ok) window.location.href = link.href; });
    });
  });
});
</script>

    <h1 class="page-heading">
        <span class="page-heading-title">Manage Customers</span>
        <span class="total-count-badge">
            <?php echo $totalCustomers; ?> total<?php echo !empty($searchTerm) ? ', filtered' : ''; ?>
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
            <div class="table-wrapper">
                <table class="admin-table" id="customersTable">
                <thead>
                    <tr>
                        <th class="sortable">ID <span class="sort-indicator"></span></th>
                        <th class="sortable">Username <span class="sort-indicator"></span></th>
                        <th class="sortable">Email <span class="sort-indicator"></span></th>
                        <th class="sortable">Name <span class="sort-indicator"></span></th>
                        <th class="sortable">Phone <span class="sort-indicator"></span></th>
                        <th>Status</th>
                        <th class="sortable">Registered <span class="sort-indicator"></span></th>
                        <th class="sortable">Orders <span class="sort-indicator"></span></th>
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
                                <div class="table-actions">
                                    <a href="user-details.php?id=<?php echo $customer['id']; ?>" 
                                       class="action-btn btn-view" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if ($columnExists): ?>
                                        <button type="button" class="action-btn btn-change-status" 
                                                data-user-id="<?php echo $customer['id']; ?>" 
                                                data-current-status="<?php echo $customer['active_status'] ? 'active' : 'inactive'; ?>"
                                                title="Toggle Status">
                                            <i class="fas fa-<?php echo $customer['active_status'] ? 'toggle-on' : 'toggle-off'; ?>"></i>
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

    // Deactivate (anchor links)
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

    // Activate (anchor links)
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

    // Delete (anchor links)
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

    // Change Status toggle button -> direct logout-style confirm
    document.querySelectorAll('.btn-change-status').forEach(btn => {
        btn.addEventListener('click', () => {
            const userId = btn.getAttribute('data-user-id');
            const current = btn.getAttribute('data-current-status');
            const action = current === 'active' ? 'deactivate' : 'activate';
            const label = action === 'activate' ? 'Activate' : 'Deactivate';
            const msg = action === 'activate'
                ? 'Activate this customer?'
                : 'Deactivate this customer? They will not be able to log in.';
            const url = `admin-customers.php?action=${action}&id=${encodeURIComponent(userId)}&page=${encodeURIComponent(<?php echo json_encode($page); ?>)}`;
            adminShowConfirm(msg, label).then(ok => { if (ok) window.location.href = url; });
        });
    });

    // Sortable table headers
    function getCellValue(row, idx) {
        const cell = row.children[idx];
        return cell ? cell.textContent.trim() : '';
    }
    function comparer(idx, asc) {
        return (a, b) => {
            const v1 = getCellValue(asc ? a : b, idx);
            const v2 = getCellValue(asc ? b : a, idx);
            const n1 = parseFloat(v1.replace(/[^0-9.]/g, ''));
            const n2 = parseFloat(v2.replace(/[^0-9.]/g, ''));
            if (!isNaN(n1) && !isNaN(n2)) return n1 - n2;
            const d1 = Date.parse(v1);
            const d2 = Date.parse(v2);
            if (!isNaN(d1) && !isNaN(d2)) return d1 - d2;
            return v1.localeCompare(v2);
        };
    }
    const table = document.getElementById('customersTable');
    if (table) {
        const headers = table.querySelectorAll('thead th.sortable');
        headers.forEach((th, idx) => {
            th.addEventListener('click', () => {
                headers.forEach(h => h.classList.remove('sort-asc','sort-desc'));
                const asc = !th.classList.contains('sort-asc');
                th.classList.toggle('sort-asc', asc);
                th.classList.toggle('sort-desc', !asc);
                const tbody = table.tBodies[0];
                Array.from(tbody.querySelectorAll('tr'))
                    .sort(comparer(idx, asc))
                    .forEach(tr => tbody.appendChild(tr));
            });
        });
    }
});
</script>

