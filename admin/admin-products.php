<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/seller_notification_functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireAdmin();

$message = '';
$error = '';

// Handle product actions - MUST BE BEFORE ANY OUTPUT
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = sanitizeInput($_GET['action']);
    $productId = intval($_GET['id']);
    
    $validActions = ['approve', 'reject', 'suspend', 'reactivate'];
    
    if (in_array($action, $validActions)) {
        try {
            $stmt = $pdo->prepare("SELECT p.*, u.username as seller_name FROM products p JOIN users u ON p.seller_id = u.id WHERE p.id = ?");
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($product) {
                switch ($action) {
                    case 'approve':
                        $stmt = $pdo->prepare("UPDATE products SET status = 'active' WHERE id = ?");
                        $stmt->execute([$productId]);
                        $message = "Product '{$product['name']}' approved successfully!";
                        
                        // Notify seller that their product was approved
                        try {
                            createSellerNotification(
                                $product['seller_id'],
                                "Product Approved",
                                "Your product '{$product['name']}' (ID: #{$productId}) has been approved by admin and is now active and visible to customers.",
                                'success',
                                'view-products.php?product_id=' . $productId
                            );
                        } catch (Exception $e) {
                            error_log("Error creating seller notification for product approval: " . $e->getMessage());
                        }
                        break;
                        
                    case 'reject':
                        $stmt = $pdo->prepare("UPDATE products SET status = 'rejected' WHERE id = ?");
                        $stmt->execute([$productId]);
                        $message = "Product '{$product['name']}' rejected successfully!";
                        
                        // Notify seller that their product was rejected
                        try {
                            createSellerNotification(
                                $product['seller_id'],
                                "Product Rejected",
                                "Your product '{$product['name']}' (ID: #{$productId}) has been rejected by admin. Please review and resubmit if needed.",
                                'error',
                                'view-products.php?product_id=' . $productId
                            );
                        } catch (Exception $e) {
                            error_log("Error creating seller notification for product rejection: " . $e->getMessage());
                        }
                        break;
                        
                    case 'suspend':
                        $stmt = $pdo->prepare("UPDATE products SET status = 'suspended' WHERE id = ?");
                        $stmt->execute([$productId]);
                        $message = "Product '{$product['name']}' suspended successfully!";
                        
                        // Notify seller that their product was suspended
                        try {
                            createSellerNotification(
                                $product['seller_id'],
                                "Product Suspended",
                                "Your product '{$product['name']}' (ID: #{$productId}) has been suspended by admin. It is temporarily hidden from customers.",
                                'warning',
                                'view-products.php?product_id=' . $productId
                            );
                        } catch (Exception $e) {
                            error_log("Error creating seller notification for product suspension: " . $e->getMessage());
                        }
                        break;
                        
                    case 'reactivate':
                        $stmt = $pdo->prepare("UPDATE products SET status = 'active' WHERE id = ?");
                        $stmt->execute([$productId]);
                        $message = "Product '{$product['name']}' reactivated successfully!";
                        
                        // Notify seller that their product was reactivated
                        try {
                            createSellerNotification(
                                $product['seller_id'],
                                "Product Reactivated",
                                "Your product '{$product['name']}' (ID: #{$productId}) has been reactivated by admin and is now active and visible to customers again.",
                                'success',
                                'view-products.php?product_id=' . $productId
                            );
                        } catch (Exception $e) {
                            error_log("Error creating seller notification for product reactivation: " . $e->getMessage());
                        }
                        break;
                }
            } else {
                $error = "Product not found.";
            }
        } catch (Exception $e) {
            $error = "An error occurred while processing the action: " . $e->getMessage();
        }
    } else {
        $error = "Invalid action specified.";
    }
    
    $redirectUrl = "admin-products.php";
    if (isset($_GET['status'])) {
        $redirectUrl .= "?status=" . urlencode($_GET['status']);
    }
    if (isset($_GET['page'])) {
        $redirectUrl .= (strpos($redirectUrl, '?') !== false ? '&' : '?') . "page=" . intval($_GET['page']);
    }
    if (isset($_GET['search'])) {
        $redirectUrl .= (strpos($redirectUrl, '?') !== false ? '&' : '?') . "search=" . urlencode($_GET['search']);
    }
    
    $_SESSION['admin_message'] = $message;
    $_SESSION['admin_error'] = $error;
    
    header("Location: $redirectUrl");
    exit();
}

// Handle admin notes update - MUST BE BEFORE ANY OUTPUT
if (isset($_POST['update_notes'])) {
    $productId = intval($_POST['product_id']);
    $adminNotes = sanitizeInput($_POST['admin_notes']);
    
    try {
        $stmt = $pdo->prepare("UPDATE products SET admin_notes = ? WHERE id = ?");
        $stmt->execute([$adminNotes, $productId]);
        $_SESSION['admin_message'] = "Admin notes updated successfully!";
    } catch (Exception $e) {
        $_SESSION['admin_error'] = "Error updating notes: " . $e->getMessage();
    }
    
    $redirectUrl = "admin-products.php";
    if (isset($_GET['status'])) {
        $redirectUrl .= "?status=" . urlencode($_GET['status']);
    }
    
    header("Location: $redirectUrl");
    exit();
}

// Now include the header - all redirects are done
require_once 'includes/admin_header.php';

// Update products.status column to support new status values if it's still an old ENUM
try {
    $pdo->exec("ALTER TABLE products MODIFY COLUMN status ENUM('pending', 'active', 'inactive', 'suspended', 'rejected') DEFAULT 'pending'");
} catch (Exception $e) {
    // Column might already be updated or error occurred, continue anyway
    error_log("Error updating products.status column: " . $e->getMessage());
}

// Display stored messages
if (isset($_SESSION['admin_message'])) {
    $message = $_SESSION['admin_message'];
    unset($_SESSION['admin_message']);
}
if (isset($_SESSION['admin_error'])) {
    $error = $_SESSION['admin_error'];
    unset($_SESSION['admin_error']);
}

// Get products with filter
$statusFilter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : 'all';
$searchTerm = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Check if product_categories table exists
$tableExists = false;
try {
    $checkTable = $pdo->query("SHOW TABLES LIKE 'product_categories'");
    $tableExists = $checkTable->rowCount() > 0;
} catch (Exception $e) {
    $tableExists = false;
}

// Build query based on filters - Get multiple categories if table exists
if ($tableExists) {
    $query = "SELECT p.*, u.username as seller_name, u.email as seller_email,
              GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') as category_names,
              GROUP_CONCAT(DISTINCT c.id ORDER BY c.id SEPARATOR ',') as category_ids
              FROM products p 
              JOIN users u ON p.seller_id = u.id 
              LEFT JOIN product_categories pc ON p.id = pc.product_id
              LEFT JOIN categories c ON (pc.category_id = c.id OR p.category_id = c.id)
              WHERE 1=1";
} else {
    // Fallback to single category if product_categories table doesn't exist
    $query = "SELECT p.*, u.username as seller_name, u.email as seller_email,
              c.name as category_name,
              c.id as category_id
              FROM products p 
              JOIN users u ON p.seller_id = u.id 
              LEFT JOIN categories c ON p.category_id = c.id
              WHERE 1=1";
}
$params = [];
$countParams = [];

if ($statusFilter !== 'all') {
    if ($statusFilter === 'pending') {
        // Include both 'pending' status and empty/null status as pending
        $query .= " AND (p.status = 'pending' OR p.status IS NULL OR p.status = '' OR p.status NOT IN ('active', 'inactive', 'suspended', 'rejected'))";
        // Don't add to params for pending since it uses raw SQL
    } else {
        $query .= " AND p.status = ?";
        $params[] = $statusFilter;
        $countParams[] = $statusFilter;
    }
}

if (!empty($searchTerm)) {
    $query .= " AND (p.name LIKE ? OR p.description LIKE ? OR u.username LIKE ?)";
    $searchParam = "%$searchTerm%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if ($tableExists) {
    $query .= " GROUP BY p.id ORDER BY p.created_at DESC LIMIT ? OFFSET ?";
} else {
    $query .= " ORDER BY p.created_at DESC LIMIT ? OFFSET ?";
}
$params[] = $limit;
$params[] = $offset;

// Get products
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
    $stmt->bindValue($key + 1, $value, $paramType);
}
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$countQuery = "SELECT COUNT(*) as total FROM products p JOIN users u ON p.seller_id = u.id WHERE 1=1";
$countQueryParams = [];
if ($statusFilter !== 'all') {
    if ($statusFilter === 'pending') {
        // Include both 'pending' status and empty/null status as pending
        $countQuery .= " AND (p.status = 'pending' OR p.status IS NULL OR p.status = '' OR p.status NOT IN ('active', 'inactive', 'suspended', 'rejected'))";
    } else {
        $countQuery .= " AND p.status = ?";
        $countQueryParams[] = $statusFilter;
    }
}
if (!empty($searchTerm)) {
    $countQuery .= " AND (p.name LIKE ? OR p.description LIKE ? OR u.username LIKE ?)";
    $searchParam = "%$searchTerm%";
    $countQueryParams[] = $searchParam;
    $countQueryParams[] = $searchParam;
    $countQueryParams[] = $searchParam;
}

$stmt = $pdo->prepare($countQuery);
$stmt->execute($countQueryParams);
$totalProducts = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalProducts / $limit);

// Get statistics
$stats = [];
$statusList = ['pending', 'active', 'rejected', 'suspended'];
foreach ($statusList as $status) {
    if ($status === 'pending') {
        // Count pending products including empty/null status
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM products WHERE status = 'pending' OR status IS NULL OR status = '' OR status NOT IN ('active', 'inactive', 'suspended', 'rejected')");
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM products WHERE status = ?");
        $stmt->execute([$status]);
    }
    $stats[$status] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
}
$totalProductsAll = array_sum($stats);
?>

<style>
    body {
        background: #f0f2f5 !important;
        color: #130325 !important;
        min-height: 100vh;
        margin: 0;
        font-family: 'Inter', 'Segoe UI', sans-serif;
    }

    /* Toast Notification - Centered Popup */
    .toast-notification {
        position: fixed;
        top: 80px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 10000;
        min-width: 300px;
        max-width: 500px;
        padding: 16px 20px;
        border-radius: 12px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        animation: toastSlideIn 0.3s ease-out;
        opacity: 0;
        pointer-events: none;
    }
    .toast-notification.show {
        opacity: 1;
        pointer-events: auto;
    }
    .toast-notification.hide {
        animation: toastSlideOut 0.3s ease-out forwards;
    }
    @keyframes toastSlideIn {
        from {
            opacity: 0;
            transform: translateX(-50%) translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
    }
    @keyframes toastSlideOut {
        from {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
        to {
            opacity: 0;
            transform: translateX(-50%) translateY(-20px);
        }
    }
    .toast-success {
        background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
        color: #065f46;
        border: 2px solid #10b981;
    }
    .toast-error {
        background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
        color: #991b1b;
        border: 2px solid #ef4444;
    }
    .toast-notification i {
        font-size: 20px;
        flex-shrink: 0;
    }
    .toast-notification .toast-message {
        flex: 1;
        font-size: 14px;
        line-height: 1.4;
    }
    
    /* Legacy alert styles (keep for backward compatibility) */
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
    .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }

    /* Page Header */
    .page-header {
        font-size: 18px !important;
        font-weight: 800 !important;
        color: #130325 !important;
        margin: -60px auto 12px auto !important;
        margin-top: -60px !important;
        padding: 0 20px !important;
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
        flex-wrap: wrap !important;
        max-width: 1400px !important;
        text-shadow: none !important;
        position: relative !important;
        z-index: 1 !important;
    }
    .page-header h1,
    .page-heading-title {
        font-size: 20px !important;
        font-weight: 800 !important;
        color: #130325 !important;
        margin-top: -60px !important;
        margin-bottom: 0 !important;
        margin-left: 0 !important;
        margin-right: 0 !important;
        display: inline-flex !important;
        align-items: center !important;
        text-shadow: none !important;
    }

    /* Main Container */
    .container {
        max-width: 1400px;
        margin: -20px auto 30px auto;
        padding: 0 20px;
    }

    /* Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
        margin-bottom: 20px;
    }
    .stat-card {
        background: #ffffff;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 8px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .stat-number {
        font-size: 28px;
        font-weight: 700;
        color: #130325;
        margin-bottom: 8px;
    }
    .stat-label {
        font-size: 12px;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
    }
    .stat-card.pending { border-left: 4px solid #f59e0b; }
    .stat-card.active { border-left: 4px solid #22c55e; }
    .stat-card.rejected { border-left: 4px solid #ef4444; }
    .stat-card.suspended { border-left: 4px solid #6b7280; }

    /* Filter Section */
    .filter-section {
        background: #ffffff;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 8px;
        padding: 24px;
        margin-bottom: 16px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .filter-row {
        display: flex;
        align-items: center;
        gap: 20px;
        flex-wrap: wrap;
        justify-content: center;
    }
    .status-filters {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        align-items: center;
    }
    .status-filters a {
        padding: 8px 16px;
        background: #f8f9fa;
        color: #130325;
        text-decoration: none;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        transition: all 0.2s ease;
        border: 1px solid #e5e7eb;
    }
    .status-filters a:hover {
        background: #e9ecef;
        border-color: #dee2e6;
    }
    .status-filters a.active {
        background: #FFD736;
        color: #130325;
        border-color: #FFD736;
    }

    /* Search Form */
    .search-form {
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: nowrap;
    }
    .search-input {
        flex: 1;
        min-width: 260px;
        max-width: 320px;
        padding: 10px 14px;
        background: #ffffff;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        color: #130325;
        font-size: 14px;
        height: 40px;
        transition: all 0.2s ease;
    }
    .search-input:focus {
        outline: none;
        border-color: #FFD736;
        box-shadow: 0 0 0 3px rgba(255,215,54,0.12);
    }
    .search-input::placeholder {
        color: #9ca3af;
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
        border-radius: 8px;
        cursor: pointer;
        flex-shrink: 0;
    }
    .btn-clear {
        background: #ffffff;
        color: #6b7280;
        border: 1px solid #e5e7eb;
        padding: 8px 14px;
        border-radius: 8px;
        text-decoration: none;
        font-size: 13px;
        font-weight: 600;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .btn-clear:hover {
        background: #f3f4f6;
        border-color: #d1d5db;
    }

    /* Table Container */
    .table-container {
        background: #ffffff;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 8px;
        padding: 24px;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .table-wrapper {
        overflow-x: visible;
        overflow-y: visible;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
        min-width: 1000px;
    }
    thead {
        background: #130325 !important;
        border-bottom: 2px solid #130325;
    }
    th {
        padding: 12px 16px;
        text-align: left;
        font-size: 12px;
        color: #ffffff !important;
        background: #130325 !important;
        font-weight: 700;
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
    td {
        padding: 14px 16px;
        border-bottom: 1px solid #f0f0f0;
        color: #130325;
        vertical-align: middle;
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

    /* Status Badges */
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
        min-width: 80px;
        max-width: 80px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .status-pending { background: #fef3c7; color: #92400e; }
    .status-active { background: #d1fae5; color: #065f46; }
    .status-rejected { background: #fee2e2; color: #991b1b; }
    .status-suspended { background: #e5e7eb; color: #374151; }

    /* Action Buttons */
    .action-buttons {
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
        color: #ffffff;
        border: none;
        position: relative;
        cursor: pointer;
    }
    .action-btn i {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-size: 14px;
        line-height: 1;
    }
    .action-btn:hover {
        transform: scale(1.1);
    }
    .btn-view { background: #130325; }
    .btn-view:hover { background: #0a0218; }
    .btn-approve { background: #22c55e; }
    .btn-approve:hover { background: #16a34a; }
    .btn-reject { background: #ef4444; }
    .btn-reject:hover { background: #dc2626; }
    .btn-suspend { background: #6b7280; }
    .btn-suspend:hover { background: #4b5563; }
    .btn-reactivate { background: #22c55e; }
    .btn-reactivate:hover { background: #16a34a; }

    /* Pagination */
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
    .page-link i {
        color: #130325 !important;
    }
    .page-link:hover {
        background: #f8f9fa !important;
        color: #130325 !important;
        border-color: #130325 !important;
        transform: translateY(-1px);
    }
    .page-link:hover i {
        color: #130325 !important;
    }
    .page-link.active {
        background: #130325 !important;
        color: #ffffff !important;
        border-color: #130325 !important;
        box-shadow: 0 2px 8px rgba(19, 3, 37, 0.3);
    }
    .page-link.active i {
        color: #ffffff !important;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #6b7280;
    }
    .empty-state i {
        font-size: 48px;
        color: #d1d5db;
        margin-bottom: 16px;
        display: block;
    }
    .empty-state h3 {
        color: #6b7280;
        font-size: 18px;
        margin-bottom: 8px;
    }
    .empty-state p {
        color: #9ca3af;
        font-size: 14px;
    }

    @media (max-width: 1200px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        table {
            font-size: 13px;
        }
        th, td {
            padding: 10px 12px;
        }
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
        width: 360px;
        max-width: 90vw;
        background: #ffffff;
        border: none;
        border-radius: 12px;
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
        color: #130325;
        font-size: 13px;
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

    /* Product View Modal - Matching Preview Style */
    .product-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.7);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        overflow-y: auto;
        padding: 20px;
    }
    .product-modal-overlay[aria-hidden="false"] {
        display: flex;
    }
    .product-modal-dialog {
        width: 1000px;
        max-width: 95vw;
        max-height: 90vh;
        background: #f0f2f5;
        border: none;
        border-radius: 8px;
        position: relative;
        margin: auto;
        animation: productModalSlideIn 0.3s ease;
        box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    }
    @keyframes productModalSlideIn {
        from {
            opacity: 0;
            transform: scale(0.95) translateY(-20px);
        }
        to {
            opacity: 1;
            transform: scale(1) translateY(0);
        }
    }
    .product-modal-close {
        position: absolute;
        top: 15px;
        right: 15px;
        background: rgba(255, 255, 255, 0.9);
        border: none;
        color: #130325;
        font-size: 28px;
        cursor: pointer;
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: all 0.2s ease;
        z-index: 10;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    }
    .product-modal-close:hover {
        background: rgba(255, 255, 255, 1);
        transform: scale(1.1);
    }
    .product-modal-body {
        padding: 20px;
        color: #130325;
        max-height: calc(90vh - 40px);
        overflow-y: auto;
    }
    .product-modal-body::-webkit-scrollbar {
        width: 8px;
    }
    .product-modal-body::-webkit-scrollbar-track {
        background: #e9ecef;
        border-radius: 4px;
    }
    .product-modal-body::-webkit-scrollbar-thumb {
        background: #adb5bd;
        border-radius: 4px;
    }
    .product-modal-body::-webkit-scrollbar-thumb:hover {
        background: #868e96;
    }
    .product-view-main {
        display: flex;
        gap: 20px;
        margin-bottom: 20px;
    }
    .product-image-gallery {
        flex: 0 0 200px;
    }
    .product-main-image-container {
        background: #ffffff;
        border: 2px solid #666666;
        padding: 10px;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 200px;
        max-width: 200px;
        border-radius: 4px;
    }
    .product-main-image {
        max-width: 100%;
        max-height: 200px;
        width: auto;
        height: auto;
        object-fit: contain;
    }
    .product-info-section {
        background: #ffffff;
        padding: 20px;
        display: flex;
        gap: 20px;
        align-items: flex-start;
        flex: 1;
        border-radius: 4px;
    }
    .product-details {
        flex: 1;
    }
    .product-title {
        font-size: 20px;
        font-weight: 900;
        color: #130325;
        margin-bottom: 12px;
        line-height: 1.2;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        text-shadow: none !important;
    }
    .product-price-section {
        background: rgba(255, 215, 54, 0.1);
        padding: 12px;
        border-radius: 8px;
        margin-bottom: 12px;
        border: 1px solid rgba(255, 215, 54, 0.3);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }
    .product-price-left {
        flex: 1;
    }
    .product-price-label {
        font-size: 11px;
        color: rgba(19, 3, 37, 0.6);
        margin-bottom: 4px;
    }
    .product-price-amount {
        font-size: 20px;
        font-weight: 900;
        color: #130325;
        line-height: 1;
    }
    .product-price-stock {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .product-price-stock-label {
        font-size: 11px;
        color: rgba(19, 3, 37, 0.6);
    }
    .product-info-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-bottom: 15px;
    }
    .product-info-row {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        background: rgba(255, 255, 255, 0.5);
        border-radius: 6px;
        border: 1px solid rgba(19, 3, 37, 0.1);
    }
    .product-info-row-full {
        display: flex;
        flex-direction: column;
        gap: 4px;
        padding: 8px 12px;
        background: rgba(255, 255, 255, 0.5);
        border-radius: 6px;
        border: 1px solid rgba(19, 3, 37, 0.1);
    }
    .product-info-item {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        background: rgba(255, 255, 255, 0.5);
        border-radius: 6px;
        border: 1px solid rgba(19, 3, 37, 0.1);
        min-width: 0;
    }
    .product-info-row-item {
        display: flex;
        align-items: center;
        gap: 6px;
        flex: 1;
    }
    .product-info-row-item-separator {
        color: rgba(19, 3, 37, 0.3);
        margin: 0 4px;
    }
    .product-info-icon {
        color: #FFD736;
        font-size: 12px;
        width: 16px;
        text-align: center;
    }
    .product-info-label {
        color: rgba(19, 3, 37, 0.7);
        font-size: 10px;
        margin-right: 4px;
    }
    .product-info-value {
        color: #130325;
        font-weight: 600;
        font-size: 10px;
    }
    .product-stock-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
    }
    .product-stock-in {
        background: #d4edda;
        color: #155724;
    }
    .product-stock-low {
        background: #fff3cd;
        color: #856404;
    }
    .product-stock-out {
        background: #f8d7da;
        color: #721c24;
    }
    .product-status-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
    }
    .product-status-badge.pending { background: #fef3c7; color: #92400e; }
    .product-status-badge.active { background: #d1fae5; color: #065f46; }
    .product-status-badge.rejected { background: #fee2e2; color: #991b1b; }
    .product-status-badge.suspended { background: #e5e7eb; color: #374151; }
    .product-details-section {
        background: #ffffff;
        padding: 14px 16px;
        margin-bottom: 15px;
        border-radius: 4px;
    }
    .product-details-section h3 {
        font-size: 13px;
        font-weight: 600;
        color: #130325;
        margin-bottom: 6px;
        text-shadow: none !important;
    }
    .product-category-text {
        font-size: 12px;
        color: #666;
        margin: 0;
    }
    .product-modal-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        padding: 20px 0 0 0;
        margin-top: 20px;
        border-top: 1px solid rgba(0,0,0,0.1);
    }
    .btn-approve-modal,
    .btn-reject-modal,
    .btn-suspend-modal {
        padding: 10px 20px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s ease;
    }
    .btn-approve-modal {
        background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
        color: #ffffff;
    }
    .btn-approve-modal:hover {
        background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
        transform: translateY(-1px);
    }
    .btn-reject-modal {
        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        color: #ffffff;
    }
    .btn-reject-modal:hover {
        background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
        transform: translateY(-1px);
    }
    .btn-suspend-modal {
        background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
        color: #ffffff;
    }
    .btn-suspend-modal:hover {
        background: linear-gradient(135deg, #4b5563 0%, #374151 100%);
        transform: translateY(-1px);
    }
    .product-description-content {
        font-size: 12px;
        color: #666;
        line-height: 1.5;
        white-space: pre-wrap;
        margin: 0;
    }
    .preview-product-category,
    .preview-product-description {
        margin-bottom: 12px;
    }
    .preview-product-category:last-child,
    .preview-product-description:last-child {
        margin-bottom: 0;
    }
    .product-loading {
        text-align: center;
        padding: 60px 40px;
        color: #6b7280;
    }
    .product-loading i {
        font-size: 32px;
        margin-bottom: 12px;
        display: block;
        animation: spin 1s linear infinite;
        color: #130325;
    }
    .product-loading p {
        font-size: 14px;
        margin: 0;
    }
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    
    /* Reviews Section in Modal */
    .product-reviews-section {
        background: #ffffff;
        padding: 20px;
        margin-top: 20px;
        border-radius: 4px;
        border-top: 2px solid #FFD736;
    }
    .reviews-section-title {
        font-size: 16px;
        font-weight: 700;
        color: #130325;
        margin: 0 0 15px 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .reviews-section-title i {
        color: #FFD736;
        font-size: 18px;
    }
    .reviews-container-modal {
        max-height: 400px;
        overflow-y: auto;
    }
    .reviews-container-modal::-webkit-scrollbar {
        width: 6px;
    }
    .reviews-container-modal::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 3px;
    }
    .reviews-container-modal::-webkit-scrollbar-thumb {
        background: #adb5bd;
        border-radius: 3px;
    }
    .reviews-container-modal::-webkit-scrollbar-thumb:hover {
        background: #868e96;
    }
    .reviews-loading {
        text-align: center;
        padding: 30px;
        color: #6b7280;
    }
    .reviews-loading i {
        font-size: 24px;
        margin-bottom: 8px;
        display: block;
        color: #130325;
    }
    .reviews-loading p {
        margin: 0;
        font-size: 13px;
    }
    .reviews-error {
        text-align: center;
        padding: 30px;
        color: #dc3545;
    }
    .reviews-error i {
        font-size: 24px;
        margin-bottom: 8px;
        display: block;
    }
    .reviews-error p {
        margin: 0;
        font-size: 13px;
    }
    .no-reviews-modal {
        text-align: center;
        padding: 40px 20px;
        color: #130325;
    }
    .no-reviews-modal i {
        font-size: 32px;
        margin-bottom: 12px;
        display: block;
        color: #FFD736;
        opacity: 0.5;
    }
    .no-reviews-modal p {
        margin: 0;
        font-size: 14px;
        font-weight: 600;
        color: #130325;
    }
    .review-item-modal {
        padding: 15px;
        border-bottom: 1px solid rgba(19, 3, 37, 0.1);
        transition: background 0.2s ease;
    }
    .review-item-modal:last-child {
        border-bottom: none;
    }
    .review-item-modal:hover {
        background: rgba(255, 215, 54, 0.03);
    }
    .review-item-modal.review-hidden {
        opacity: 0.6;
        background: rgba(108, 117, 125, 0.05);
    }
    .review-header-modal {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }
    .review-user-info {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .review-username {
        font-size: 13px;
        font-weight: 700;
        color: #130325;
    }
    .review-date-small {
        font-size: 11px;
        color: rgba(19, 3, 37, 0.4);
    }
    .review-rating-modal {
        color: #FFD736;
        font-size: 14px;
    }
    .review-text-modal {
        font-size: 13px;
        color: #130325;
        line-height: 1.5;
        margin-bottom: 12px;
    }
    .review-hidden-message {
        padding: 10px 14px;
        background: rgba(108, 117, 125, 0.1);
        border-left: 3px solid #6c757d;
        border-radius: 4px;
        display: flex;
        align-items: center;
        gap: 8px;
        color: rgba(19, 3, 37, 0.5);
        margin-bottom: 12px;
    }
    .review-hidden-message i {
        font-size: 14px;
        color: #6c757d;
    }
    .review-hidden-message em {
        font-size: 12px;
        font-style: italic;
    }
    .hidden-reason {
        font-size: 11px;
        color: rgba(19, 3, 37, 0.4);
    }
    .seller-reply-modal {
        margin-top: 12px;
        padding: 12px 14px;
        background: rgba(255, 215, 54, 0.08);
        border-left: 3px solid #FFD736;
        border-radius: 4px;
        margin-bottom: 12px;
    }
    .reply-header-modal {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 8px;
    }
    .reply-header-modal i {
        color: #FFD736;
        font-size: 12px;
    }
    .reply-label {
        font-weight: 700;
        color: #130325;
        font-size: 12px;
    }
    .reply-date-small {
        font-size: 10px;
        color: rgba(19, 3, 37, 0.4);
        margin-left: auto;
    }
    .reply-text-modal {
        color: #130325;
        font-size: 12px;
        line-height: 1.5;
    }
    .review-actions {
        margin-top: 12px;
        display: flex;
        gap: 8px;
    }
    .btn-hide-review, .btn-unhide-review {
        background: transparent;
        border: none;
        padding: 6px 12px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s ease;
        border-radius: 4px;
    }
    .btn-hide-review {
        color: #dc3545;
    }
    .btn-hide-review:hover {
        background: rgba(220, 53, 69, 0.1);
    }
    .btn-unhide-review {
        color: #28a745;
    }
    .btn-unhide-review:hover {
        background: rgba(40, 167, 69, 0.1);
    }
    .btn-hide-review i, .btn-unhide-review i {
        font-size: 12px;
    }
    
    @media (max-width: 768px) {
        .product-view-main {
            flex-direction: column;
        }
        .product-image-gallery {
            flex: 1;
        }
        .product-main-image-container {
            max-width: 100%;
        }
        .product-modal-dialog {
            width: 95vw;
        }
        .product-price-section {
            flex-direction: column;
            align-items: flex-start;
        }
        .product-info-row {
            flex-wrap: wrap;
        }
        .stats-grid {
            grid-template-columns: 1fr;
        }
        .filter-row {
            flex-direction: column;
            align-items: stretch;
        }
        .search-form {
            flex-direction: column;
        }
        .search-input {
            max-width: 100%;
        }
    }
</style>

<?php if ($message): ?>
<div class="toast-notification toast-success" id="successToast">
    <i class="fas fa-check-circle"></i>
    <span class="toast-message"><?php echo htmlspecialchars($message); ?></span>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="toast-notification toast-error" id="errorToast">
    <i class="fas fa-exclamation-triangle"></i>
    <span class="toast-message"><?php echo htmlspecialchars($error); ?></span>
</div>
<?php endif; ?>

<div class="page-header">
    <h1 class="page-heading-title">Product Management</h1>
</div>

<div class="container">
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card pending">
            <div class="stat-number"><?php echo $stats['pending']; ?></div>
            <div class="stat-label">Pending Review</div>
        </div>
        <div class="stat-card active">
            <div class="stat-number"><?php echo $stats['active']; ?></div>
            <div class="stat-label">Active Products</div>
        </div>
        <div class="stat-card rejected">
            <div class="stat-number"><?php echo $stats['rejected']; ?></div>
            <div class="stat-label">Rejected</div>
        </div>
        <div class="stat-card suspended">
            <div class="stat-number"><?php echo $stats['suspended']; ?></div>
            <div class="stat-label">Suspended</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-section">
        <div class="filter-row">
            <div class="status-filters">
                <a href="admin-products.php?<?php echo !empty($searchTerm) ? "search=" . urlencode($searchTerm) . "&" : ""; ?>status=all" 
                   class="<?php echo $statusFilter === 'all' ? 'active' : ''; ?>">
                    All (<?php echo $totalProductsAll; ?>)
                </a>
                <a href="admin-products.php?<?php echo !empty($searchTerm) ? "search=" . urlencode($searchTerm) . "&" : ""; ?>status=pending" 
                   class="<?php echo $statusFilter === 'pending' ? 'active' : ''; ?>">
                    Pending (<?php echo $stats['pending']; ?>)
                </a>
                <a href="admin-products.php?<?php echo !empty($searchTerm) ? "search=" . urlencode($searchTerm) . "&" : ""; ?>status=active" 
                   class="<?php echo $statusFilter === 'active' ? 'active' : ''; ?>">
                    Active (<?php echo $stats['active']; ?>)
                </a>
                <a href="admin-products.php?<?php echo !empty($searchTerm) ? "search=" . urlencode($searchTerm) . "&" : ""; ?>status=rejected" 
                   class="<?php echo $statusFilter === 'rejected' ? 'active' : ''; ?>">
                    Rejected (<?php echo $stats['rejected']; ?>)
                </a>
                <a href="admin-products.php?<?php echo !empty($searchTerm) ? "search=" . urlencode($searchTerm) . "&" : ""; ?>status=suspended" 
                   class="<?php echo $statusFilter === 'suspended' ? 'active' : ''; ?>">
                    Suspended (<?php echo $stats['suspended']; ?>)
                </a>
            </div>
            
            <form method="GET" class="search-form">
                <?php if ($statusFilter !== 'all'): ?>
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                <?php endif; ?>
                <input type="text" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" 
                       placeholder="Search products, sellers..." class="search-input">
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i>
                </button>
                <?php if (!empty($searchTerm)): ?>
                    <a href="admin-products.php?<?php echo $statusFilter !== 'all' ? "status=" . urlencode($statusFilter) : ""; ?>" class="btn-clear">
                        <i class="fas fa-times"></i> Clear
                    </a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Products Table -->
    <div class="table-container">
        <?php if (empty($products)): ?>
            <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <h3>No products found</h3>
                <p>
                    <?php if (!empty($searchTerm)): ?>
                        Try adjusting your search terms or filters.
                    <?php else: ?>
                        Products will appear here once sellers start adding them.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="admin-table" id="productsTable">
                    <thead>
                        <tr>
                            <th class="sortable">ID <span class="sort-indicator"></span></th>
                            <th class="sortable">Product Info <span class="sort-indicator"></span></th>
                            <th class="sortable">Seller <span class="sort-indicator"></span></th>
                            <th class="sortable">Price & Stock <span class="sort-indicator"></span></th>
                            <th class="sortable">Status <span class="sort-indicator"></span></th>
                            <th class="sortable">Dates <span class="sort-indicator"></span></th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td><strong>#<?php echo $product['id']; ?></strong></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($product['name']); ?></strong><br>
                                    <small style="color: #6b7280;">
                                        Categories: <?php echo htmlspecialchars(($product['category_names'] ?? $product['category_name'] ?? 'N/A')); ?>
                                    </small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($product['seller_name']); ?></strong><br>
                                    <small style="color: #6b7280;"><?php echo htmlspecialchars($product['seller_email']); ?></small>
                                </td>
                                <td>
                                    <strong>₱<?php echo number_format($product['price'], 2); ?></strong><br>
                                    <small style="color: #6b7280;">Stock: <?php echo $product['stock_quantity']; ?></small>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo htmlspecialchars($product['status'] ?: 'pending'); ?>">
                                        <?php echo ucfirst($product['status'] ?: 'Pending'); ?>
                                    </span>
                                </td>
                                <td>
                                    <small style="color: #6b7280;">
                                        Created: <?php echo date('M j, Y', strtotime($product['created_at'])); ?><br>
                                        <?php if (!empty($product['updated_at']) && $product['updated_at'] !== '0000-00-00 00:00:00'): ?>
                                            Updated: <?php echo date('M j, Y', strtotime($product['updated_at'])); ?>
                                        <?php else: ?>
                                            <span style="color: #d1d5db;">Not updated</span>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="#" 
                                           class="action-btn btn-view" title="View Product"
                                           onclick="openProductModal(<?php echo $product['id']; ?>); return false;">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <?php if ($product['status'] === 'pending'): ?>
                                            <a href="#" 
                                               class="action-btn btn-approve" title="Approve Product"
                                               onclick="openConfirmModal('approve', <?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>'); return false;">
                                                <i class="fas fa-check"></i>
                                            </a>
                                            <a href="#" 
                                               class="action-btn btn-reject" title="Reject Product"
                                               onclick="openConfirmModal('reject', <?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>'); return false;">
                                                <i class="fas fa-times"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if (in_array($product['status'], ['active', 'pending'])): ?>
                                            <a href="#" 
                                               class="action-btn btn-suspend" title="Suspend Product"
                                               onclick="openConfirmModal('suspend', <?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>'); return false;">
                                                <i class="fas fa-pause"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if (in_array($product['status'], ['rejected', 'suspended'])): ?>
                                            <a href="#" 
                                               class="action-btn btn-reactivate" title="Reactivate Product"
                                               onclick="openConfirmModal('reactivate', <?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>'); return false;">
                                                <i class="fas fa-play"></i>
                                            </a>
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
                    $baseUrl = "admin-products.php?" . http_build_query(['status' => $statusFilter, 'search' => $searchTerm]);
                    ?>
                    
                    <?php if ($page > 1): ?>
                        <a href="<?php echo $baseUrl; ?>&page=<?php echo $page - 1; ?>" class="page-link">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php 
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <a href="<?php echo $baseUrl; ?>&page=<?php echo $i; ?>" 
                           class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo $baseUrl; ?>&page=<?php echo $page + 1; ?>" class="page-link">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// Sortable table headers
function getCellValue(row, idx) {
    const cell = row.children[idx];
    if (!cell) return '';
    const text = cell.textContent.trim();
    
    // Extract number from price/stock cell
    if (idx === 3) {
        const priceMatch = text.match(/₱([\d,]+\.?\d*)/);
        if (priceMatch) return parseFloat(priceMatch[1].replace(/,/g, ''));
    }
    
    // Extract number from ID cell
    if (idx === 0) {
        const idMatch = text.match(/#(\d+)/);
        if (idMatch) return parseInt(idMatch[1]);
    }
    
    // Date parsing
    const dateMatch = text.match(/([A-Za-z]{3}\s+\d{1,2},\s+\d{4})/);
    if (dateMatch) {
        const date = new Date(dateMatch[1]);
        if (!isNaN(date.getTime())) return date.getTime();
    }
    
    return text;
}

function comparer(idx, asc) {
    return (a, b) => {
        const v1 = getCellValue(asc ? a : b, idx);
        const v2 = getCellValue(asc ? b : a, idx);
        
        if (typeof v1 === 'number' && typeof v2 === 'number') {
            return v1 - v2;
        }
        
        if (v1 instanceof Date && v2 instanceof Date) {
            return v1 - v2;
        }
        
        return v1.toString().localeCompare(v2.toString());
    };
}

const table = document.getElementById('productsTable');
if (table) {
    const headers = table.querySelectorAll('thead th.sortable');
    headers.forEach((th, idx) => {
        th.addEventListener('click', () => {
            headers.forEach(h => h.classList.remove('sort-asc', 'sort-desc'));
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

// Confirmation Modal Functions
function openConfirmModal(action, productId, productName) {
    const modal = document.getElementById(action + 'Modal');
    if (modal) {
        // Set the product ID and name in the modal
        const confirmLink = modal.querySelector('.modal-confirm-link');
        if (confirmLink) {
            const params = new URLSearchParams(window.location.search);
            const status = params.get('status') || 'all';
            const page = params.get('page') || '1';
            const search = params.get('search') || '';
            
            let url = `admin-products.php?action=${action}&id=${productId}`;
            if (status !== 'all') url += `&status=${encodeURIComponent(status)}`;
            if (page !== '1') url += `&page=${page}`;
            if (search) url += `&search=${encodeURIComponent(search)}`;
            
            confirmLink.href = url;
        }
        
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
}

// Function to open confirmation modal from product view modal
function openModalConfirm(action, productId, productName) {
    // Close product view modal first
    closeProductModal();
    
    // Open the appropriate confirmation modal
    const modal = document.getElementById(action + 'Modal');
    if (modal) {
        const confirmLink = modal.querySelector('.modal-confirm-link');
        if (confirmLink) {
            const params = new URLSearchParams(window.location.search);
            const status = params.get('status') || 'all';
            const page = params.get('page') || '1';
            const search = params.get('search') || '';
            
            let url = `admin-products.php?action=${action}&id=${productId}`;
            if (status !== 'all') url += `&status=${encodeURIComponent(status)}`;
            if (page !== '1') url += `&page=${page}`;
            if (search) url += `&search=${encodeURIComponent(search)}`;
            
            confirmLink.href = url;
        }
        
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
}

function closeConfirmModal(action) {
    const modal = document.getElementById(action + 'Modal');
    if (modal) {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
}

// Close confirmation modals on overlay click
document.querySelectorAll('#approveModal, #rejectModal, #suspendModal, #reactivateModal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.style.display = 'none';
            this.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }
    });
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        // Close product view modal
        const productModal = document.getElementById('productViewModal');
        if (productModal && productModal.getAttribute('aria-hidden') === 'false') {
            closeProductModal();
        }
        // Close confirmation modals
        document.querySelectorAll('#approveModal, #rejectModal, #suspendModal, #reactivateModal').forEach(modal => {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        });
    }
});

// Product View Modal Functions
function openProductModal(productId) {
    const modal = document.getElementById('productViewModal');
    if (!modal) return;
    
    // Show loading state
    const modalBody = modal.querySelector('.product-modal-body');
    modalBody.innerHTML = '<div class="product-loading"><i class="fas fa-spinner"></i><p>Loading product details...</p></div>';
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    
    // Fetch product details
    fetch(`../ajax/get-product-details.php?id=${productId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayProductDetails(data.product);
            } else {
                modalBody.innerHTML = `<div class="product-loading"><i class="fas fa-exclamation-triangle"></i><p>${data.message || 'Error loading product details'}</p></div>`;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            modalBody.innerHTML = '<div class="product-loading"><i class="fas fa-exclamation-triangle"></i><p>Error loading product details. Please try again.</p></div>';
        });
}

function displayProductDetails(product) {
    const modalBody = document.querySelector('#productViewModal .product-modal-body');
    
    // Build images HTML
    // Fix image path - if it starts with 'assets/', add '../' prefix for admin folder
    let mainImage = '../assets/uploads/tempo_image.jpg'; // default
    let imageHTML = '';
    
    if (product.images && product.images.length > 0) {
        let imgPath = product.images[0].image_url || '';
        if (imgPath) {
            mainImage = imgPath.startsWith('assets/') ? '../' + imgPath : imgPath.startsWith('../') ? imgPath : '../' + imgPath;
        }
    } else if (product.image_url) {
        let imgPath = product.image_url;
        if (imgPath) {
            mainImage = imgPath.startsWith('assets/') ? '../' + imgPath : imgPath.startsWith('../') ? imgPath : '../' + imgPath;
        }
    }
    
    imageHTML = `
        <div class="product-main-image-container">
            <img src="${mainImage}" alt="${escapeHtml(product.name)}" class="product-main-image" id="productMainImage" onerror="this.src='../assets/uploads/tempo_image.jpg'">
        </div>
    `;
    
    // Build status badge
    const statusClass = product.status || 'pending';
    const statusText = product.status ? product.status.charAt(0).toUpperCase() + product.status.slice(1) : 'Pending';
    
    // Stock badge
    const stock = parseInt(product.stock_quantity || 0);
    let stockBadgeClass = 'product-stock-out';
    let stockBadgeText = 'Out of Stock';
    if (stock > 10) {
        stockBadgeClass = 'product-stock-in';
        stockBadgeText = 'In Stock';
    } else if (stock > 0) {
        stockBadgeClass = 'product-stock-low';
        stockBadgeText = 'Low Stock';
    }
    
    modalBody.innerHTML = `
        <div class="product-view-main">
            <div class="product-image-gallery">
                ${imageHTML}
            </div>
            
            <div class="product-info-section">
                <div class="product-details">
                    <h1 class="product-title">${escapeHtml(product.name)}</h1>
                    
                    <div class="product-price-section">
                        <div class="product-price-left">
                            <div class="product-price-label">Price:</div>
                            <div class="product-price-amount">₱${parseFloat(product.price || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                        </div>
                        <div class="product-price-stock">
                            <span class="product-price-stock-label">Stock:</span>
                            <span class="product-stock-badge ${stockBadgeClass}">${stockBadgeText}</span>
                        </div>
                    </div>
                    
                    <div class="product-info-list">
                        <div class="product-info-row">
                            <i class="fas fa-hashtag product-info-icon"></i>
                            <span class="product-info-label">Product ID:</span>
                            <span class="product-info-value">#${product.id}</span>
                            <span class="product-info-row-item-separator">•</span>
                            <i class="fas fa-store product-info-icon"></i>
                            <span class="product-info-label">Seller:</span>
                            <span class="product-info-value">#${product.seller_id || 'N/A'} - ${escapeHtml(product.seller_name || 'N/A')}</span>
                        </div>
                        
                        <div class="product-info-row-full">
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <i class="fas fa-envelope product-info-icon"></i>
                                <span class="product-info-label">Seller Email:</span>
                            </div>
                            <span class="product-info-value" style="margin-left: 22px; word-break: break-word;">${escapeHtml(product.seller_email || 'N/A')}</span>
                        </div>
                        
                        <div class="product-info-row">
                            <i class="fas fa-calendar-plus product-info-icon"></i>
                            <span class="product-info-label">Created:</span>
                            <span class="product-info-value">${product.created_at_formatted}</span>
                            <span class="product-info-row-item-separator">•</span>
                            <i class="fas fa-tag product-info-icon"></i>
                            <span class="product-info-label">Status:</span>
                            <span class="product-status-badge ${statusClass}">${statusText}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="product-details-section">
            <div class="preview-product-category">
                <h3>Product Categories</h3>
                <p class="product-category-text">${escapeHtml(product.category_names || product.category_name || 'No category assigned')}</p>
            </div>
            <div class="preview-product-description">
                <h3>Product Description</h3>
                <div class="product-description-content">${escapeHtml(product.description || 'No description provided.')}</div>
            </div>
        </div>
        
        <!-- Reviews Section -->
        <div class="product-reviews-section">
            <h3 class="reviews-section-title">
                <i class="fas fa-star"></i> Product Reviews
            </h3>
            <div id="reviewsContainer" class="reviews-container-modal">
                <div class="reviews-loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading reviews...</p>
                </div>
            </div>
        </div>
        
        <div class="product-modal-actions">
            ${product.status === 'pending' || !product.status || product.status === '' ? `
                <button class="btn-approve-modal" onclick="openModalConfirm('approve', ${product.id}, '${escapeHtml(product.name)}'); return false;">
                    <i class="fas fa-check"></i> Approve Product
                </button>
                <button class="btn-reject-modal" onclick="openModalConfirm('reject', ${product.id}, '${escapeHtml(product.name)}'); return false;">
                    <i class="fas fa-times"></i> Reject Product
                </button>
            ` : ''}
            ${product.status === 'active' ? `
                <button class="btn-suspend-modal" onclick="openModalConfirm('suspend', ${product.id}, '${escapeHtml(product.name)}'); return false;">
                    <i class="fas fa-pause"></i> Suspend Product
                </button>
            ` : ''}
            ${(product.status === 'rejected' || product.status === 'suspended') ? `
                <button class="btn-approve-modal" onclick="openModalConfirm('reactivate', ${product.id}, '${escapeHtml(product.name)}'); return false;">
                    <i class="fas fa-play"></i> Reactivate Product
                </button>
            ` : ''}
        </div>
    `;
    
    // Load reviews after displaying product details
    loadProductReviews(product.id);
}

// Load product reviews
function loadProductReviews(productId) {
    const container = document.getElementById('reviewsContainer');
    
    fetch(`../ajax/get-product-reviews.php?product_id=${productId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayReviews(data.reviews);
            } else {
                container.innerHTML = `<div class="reviews-error"><i class="fas fa-exclamation-triangle"></i><p>${data.message}</p></div>`;
            }
        })
        .catch(error => {
            console.error('Error loading reviews:', error);
            container.innerHTML = '<div class="reviews-error"><i class="fas fa-exclamation-triangle"></i><p>Error loading reviews</p></div>';
        });
}

// Display reviews in modal (Admin side)
function displayReviews(reviews) {
    const container = document.getElementById('reviewsContainer');
    
    console.log('displayReviews called with:', reviews);
    
    if (!reviews || reviews.length === 0) {
        container.innerHTML = `
            <div class="no-reviews-modal">
                <i class="fas fa-comments"></i>
                <p>No reviews yet for this product</p>
            </div>
        `;
        return;
    }
    
    const reviewsHTML = reviews.map(review => {
        console.log('Processing review:', review);
        const stars = '★'.repeat(review.rating) + '☆'.repeat(5 - review.rating);
        const isHidden = review.is_hidden;
        const hasReply = review.seller_reply && review.seller_reply.trim() !== '';
        
        return `
            <div class="review-item-modal ${isHidden ? 'review-hidden' : ''}" data-review-id="${review.id}">
                <div class="review-header-modal">
                    <div class="review-user-info">
                        <span class="review-username">${escapeHtml(review.customer_name)}</span>
                        <span class="review-date-small">${review.created_at_formatted}</span>
                    </div>
                    <div class="review-rating-modal">${stars}</div>
                </div>
                
                ${isHidden ? `
                    <div class="review-hidden-message">
                        <i class="fas fa-eye-slash"></i>
                        <em>This review is hidden</em>
                        ${review.hidden_reason ? `<span class="hidden-reason"> - ${escapeHtml(review.hidden_reason)}</span>` : ''}
                    </div>
                ` : `
                    <div class="review-text-modal">${escapeHtml(review.review_text).replace(/\n/g, '<br>')}</div>
                `}
                
                ${hasReply ? `
                    <div class="seller-reply-modal">
                        <div class="reply-header-modal">
                            <i class="fas fa-store"></i>
                            <span class="reply-label">Seller Reply</span>
                            <span class="reply-date-small">${review.seller_replied_at ? new Date(review.seller_replied_at).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'}) : ''}</span>
                        </div>
                        <div class="reply-text-modal">${escapeHtml(review.seller_reply).replace(/\n/g, '<br>')}</div>
                    </div>
                ` : ''}
                
                <div class="review-actions">
                    ${isHidden ? `
                        <button class="btn-unhide-review" onclick="openHideModal(${review.id}, 'unhide', '${review.customer_name.replace(/'/g, "\\'")}'); return false;">
                            <i class="fas fa-eye"></i> Unhide Review
                        </button>
                    ` : `
                        <button class="btn-hide-review" onclick="openHideModal(${review.id}, 'hide', '${review.customer_name.replace(/'/g, "\\'")}'); return false;">
                            <i class="fas fa-eye-slash"></i> Hide Review
                        </button>
                    `}
                </div>
            </div>
        `;
    }).join('');
    
    container.innerHTML = reviewsHTML;
    container.dataset.productId = reviews[0]?.product_id || '';
}

function closeProductModal() {
    const modal = document.getElementById('productViewModal');
    if (modal) {
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
}

// Close modal when clicking outside
document.addEventListener('click', function(e) {
    const modal = document.getElementById('productViewModal');
    if (e.target === modal) {
        closeProductModal();
    }
});

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Auto-dismiss toast notifications after 1.5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const successToast = document.getElementById('successToast');
    const errorToast = document.getElementById('errorToast');
    
    function showAndDismissToast(toast) {
        if (toast) {
            // Show toast
            setTimeout(() => {
                toast.classList.add('show');
            }, 10);
            
            // Hide toast after 1.5 seconds
            setTimeout(() => {
                toast.classList.remove('show');
                toast.classList.add('hide');
                
                // Remove from DOM after animation
                setTimeout(() => {
                    toast.remove();
                }, 300);
            }, 1500);
        }
    }
    
    showAndDismissToast(successToast);
    showAndDismissToast(errorToast);
    
    // Check if product_id is in URL parameters and open product modal
    const urlParams = new URLSearchParams(window.location.search);
    const productId = urlParams.get('product_id');
    if (productId) {
        // Remove product_id from URL to clean it up
        urlParams.delete('product_id');
        const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
        window.history.replaceState({}, '', newUrl);
        
        // Wait a bit for the page to fully load, then open the modal
        setTimeout(() => {
            openProductModal(parseInt(productId));
        }, 500);
    }
});

// Hide/Unhide Review Functions
let currentReviewId = null;
let currentReviewAction = null;

function openHideModal(reviewId, action, customerName) {
    console.log('openHideModal called with reviewId:', reviewId, 'action:', action, 'customerName:', customerName);
    currentReviewId = reviewId;
    currentReviewAction = action;
    
    const modal = document.getElementById('hideReviewModal');
    const titleEl = document.getElementById('hideModalTitle');
    const messageEl = document.getElementById('hideModalMessage');
    const reasonGroup = document.getElementById('hideReasonGroup');
    const reasonInput = document.getElementById('hideReasonInput');
    const confirmBtn = document.getElementById('hideConfirmBtn');
    
    if (action === 'hide') {
        titleEl.textContent = 'Hide Review';
        messageEl.innerHTML = `Hide review from <strong>${customerName}</strong>?<br><small style="color: rgba(19, 3, 37, 0.6);">The customer and seller will be notified.</small>`;
        reasonGroup.style.display = 'block';
        reasonInput.value = '';
        confirmBtn.innerHTML = '<i class="fas fa-eye-slash"></i> Hide Review';
        confirmBtn.className = 'btn-primary-y';
    } else {
        titleEl.textContent = 'Unhide Review';
        messageEl.innerHTML = `Unhide review from <strong>${customerName}</strong>?<br><small style="color: rgba(19, 3, 37, 0.6);">This review will be visible again.</small>`;
        reasonGroup.style.display = 'none';
        confirmBtn.innerHTML = '<i class="fas fa-eye"></i> Unhide Review';
        confirmBtn.className = 'btn-primary-y';
    }
    
    if (modal) {
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        if (action === 'hide') {
            setTimeout(() => reasonInput && reasonInput.focus(), 100);
        }
    }
}

function closeHideModal() {
    const modal = document.getElementById('hideReviewModal');
    const reasonInput = document.getElementById('hideReasonInput');
    
    if (modal) {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
    if (reasonInput) {
        reasonInput.value = '';
    }
    currentReviewId = null;
    currentReviewAction = null;
}

function submitHideAction() {
    console.log('submitHideAction called, currentReviewId:', currentReviewId, 'currentReviewAction:', currentReviewAction);
    const reasonInput = document.getElementById('hideReasonInput');
    const reason = reasonInput ? reasonInput.value.trim() : '';
    
    if (currentReviewAction === 'hide' && !reason) {
        alert('Please provide a reason for hiding this review');
        return;
    }
    
    if (currentReviewId === null || currentReviewId === undefined || !currentReviewAction) {
        console.error('currentReviewId or currentReviewAction is null/undefined');
        alert('Error: Review ID or action not found');
        return;
    }
    
    console.log('Sending hide/unhide request with:', { review_id: currentReviewId, action: currentReviewAction, reason: reason });
    
    // Disable button to prevent double submission
    const confirmBtn = document.getElementById('hideConfirmBtn');
    if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    }
    
    fetch('../ajax/admin-hide-review.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            review_id: currentReviewId,
            action: currentReviewAction,
            reason: reason
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeHideModal();
            // Reload reviews to show the updated status
            const container = document.getElementById('reviewsContainer');
            const productId = container ? container.dataset.productId : null;
            if (productId) {
                loadProductReviews(parseInt(productId));
            }
            // Show success modal
            showHideSuccessModal(currentReviewAction);
        } else {
            showHideErrorModal(data.message || 'Failed to process request');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showHideErrorModal('Error processing request. Please try again.');
    })
    .finally(() => {
        if (confirmBtn) {
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = currentReviewAction === 'hide' 
                ? '<i class="fas fa-eye-slash"></i> Hide Review' 
                : '<i class="fas fa-eye"></i> Unhide Review';
        }
    });
}

// Success/Error modals for hide/unhide actions
function showHideSuccessModal(action) {
    const modal = document.getElementById('hideSuccessModal');
    const message = document.getElementById('hideSuccessMessage');
    if (message) {
        message.textContent = action === 'hide' 
            ? 'Review hidden successfully! The customer and seller have been notified.' 
            : 'Review unhidden successfully! This review is now visible again.';
    }
    if (modal) {
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
}

function closeHideSuccessModal() {
    const modal = document.getElementById('hideSuccessModal');
    if (modal) {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
}

function showHideErrorModal(errorMessage) {
    const modal = document.getElementById('hideErrorModal');
    const message = document.getElementById('hideErrorMessage');
    if (message) {
        message.textContent = errorMessage;
    }
    if (modal) {
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
}

function closeHideErrorModal() {
    const modal = document.getElementById('hideErrorModal');
    if (modal) {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
}
</script>

<!-- Confirmation Modals -->
<!-- Approve Modal -->
<div id="approveModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-header">
            <div class="modal-title">Confirm Approval</div>
            <button class="modal-close" aria-label="Close" onclick="closeConfirmModal('approve')">×</button>
        </div>
        <div class="modal-body">
            Are you sure you want to approve this product? It will become active and visible to customers.
        </div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeConfirmModal('approve')">Cancel</button>
            <a href="#" class="btn-primary-y modal-confirm-link">Confirm</a>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-header">
            <div class="modal-title">Confirm Rejection</div>
            <button class="modal-close" aria-label="Close" onclick="closeConfirmModal('reject')">×</button>
        </div>
        <div class="modal-body">
            Are you sure you want to reject this product? It will be marked as rejected and hidden from customers.
        </div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeConfirmModal('reject')">Cancel</button>
            <a href="#" class="btn-primary-y modal-confirm-link">Confirm</a>
        </div>
    </div>
</div>

<!-- Suspend Modal -->
<div id="suspendModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-header">
            <div class="modal-title">Confirm Suspension</div>
            <button class="modal-close" aria-label="Close" onclick="closeConfirmModal('suspend')">×</button>
        </div>
        <div class="modal-body">
            Are you sure you want to suspend this product? It will be hidden from customers temporarily.
        </div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeConfirmModal('suspend')">Cancel</button>
            <a href="#" class="btn-primary-y modal-confirm-link">Confirm</a>
        </div>
    </div>
</div>

<!-- Reactivate Modal -->
<div id="reactivateModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-header">
            <div class="modal-title">Confirm Reactivation</div>
            <button class="modal-close" aria-label="Close" onclick="closeConfirmModal('reactivate')">×</button>
        </div>
        <div class="modal-body">
            Are you sure you want to reactivate this product? It will become active and visible to customers again.
        </div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeConfirmModal('reactivate')">Cancel</button>
            <a href="#" class="btn-primary-y modal-confirm-link">Confirm</a>
        </div>
    </div>
</div>

<!-- Product View Modal -->
<div id="productViewModal" class="product-modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="product-modal-dialog">
        <button class="product-modal-close" aria-label="Close" onclick="closeProductModal()">&times;</button>
        <div class="product-modal-body">
            <div class="product-loading">
                <i class="fas fa-spinner"></i>
                <p>Loading product details...</p>
            </div>
        </div>
    </div>
</div>

<!-- Hide/Unhide Review Modal -->
<div id="hideReviewModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="modal-dialog" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div class="modal-title" id="hideModalTitle">Hide Review</div>
            <button type="button" class="modal-close" onclick="closeHideModal()">×</button>
        </div>
        <div class="modal-body">
            <p id="hideModalMessage" style="margin: 0 0 12px 0; color: #130325; font-size: 12px; line-height: 1.5; font-weight: 500;"></p>
            <div class="form-group" id="hideReasonGroup" style="margin-bottom: 8px;">
                <label for="hideReasonInput" style="font-size: 11px; color: #6b7280; margin-bottom: 6px; display: block; font-weight: 600;">Reason for hiding:</label>
                <textarea 
                    id="hideReasonInput" 
                    maxlength="255" 
                    rows="3"
                    placeholder="Explain why this review is being hidden..."
                    style="padding: 8px 10px; border: 1px solid rgba(0,0,0,0.15); border-radius: 6px; font-size: 13px; width: 100%; font-family: inherit; resize: vertical;"></textarea>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-outline" onclick="closeHideModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="button" class="btn-primary-y" id="hideConfirmBtn" onclick="submitHideAction()">
                <i class="fas fa-eye-slash"></i> Hide Review
            </button>
        </div>
    </div>
</div>

<!-- Hide/Unhide Success Modal -->
<div id="hideSuccessModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" style="display: none;">
    <div class="modal-dialog" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div class="modal-title">Success</div>
            <button type="button" class="modal-close" onclick="closeHideSuccessModal()">×</button>
        </div>
        <div class="modal-body">
            <div style="text-align: center; padding: 10px 0;">
                <i class="fas fa-check-circle" style="font-size: 48px; color: #28a745; margin-bottom: 12px;"></i>
                <p id="hideSuccessMessage" style="margin: 0; color: #130325; font-size: 14px; line-height: 1.5; font-weight: 500;"></p>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-primary-y" onclick="closeHideSuccessModal()">
                <i class="fas fa-check"></i> Got it!
            </button>
        </div>
    </div>
</div>

<!-- Hide/Unhide Error Modal -->
<div id="hideErrorModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" style="display: none;">
    <div class="modal-dialog" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div class="modal-title">Error</div>
            <button type="button" class="modal-close" onclick="closeHideErrorModal()">×</button>
        </div>
        <div class="modal-body">
            <div style="text-align: center; padding: 10px 0;">
                <i class="fas fa-exclamation-circle" style="font-size: 48px; color: #dc3545; margin-bottom: 12px;"></i>
                <p id="hideErrorMessage" style="margin: 0; color: #130325; font-size: 14px; line-height: 1.5; font-weight: 500;"></p>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-primary-y" onclick="closeHideErrorModal()">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>

