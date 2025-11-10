<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

requireAdmin();

ob_start(); // Start output buffering to prevent headers already sent errors

$sellerId = 0;
$sellerUsername = null;

// Handle both ID and username (for ID 0 sellers)
if (isset($_GET['id'])) {
    $sellerId = intval($_GET['id']);
} elseif (isset($_GET['username'])) {
    $sellerUsername = sanitizeInput($_GET['username']);
}

if (!$sellerId && !$sellerUsername) {
    ob_end_clean();
    header('Location: admin-sellers.php?msg=' . urlencode('Invalid seller ID') . '&type=error');
    exit();
}

// Initialize message variables
$message = '';
$messageType = '';

// Handle actions - support both GET (for approve/reject/ban/restore) and POST (for suspend with reason)
if (isset($_GET['action']) || (isset($_POST['action']) && $_POST['action'] === 'suspend')) {
    $action = isset($_GET['action']) ? $_GET['action'] : $_POST['action'];
    
    // Get seller lookup info
    $actionSellerId = isset($_GET['id']) ? intval($_GET['id']) : (isset($_POST['id']) ? intval($_POST['id']) : 0);
    $actionSellerUsername = isset($_GET['username']) ? sanitizeInput($_GET['username']) : (isset($_POST['username']) ? sanitizeInput($_POST['username']) : null);
    
    // Find seller for action
    $actionSeller = null;
    if ($actionSellerId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND (user_type = 'seller' OR user_type IS NULL OR user_type = '')");
        $stmt->execute([$actionSellerId]);
        $actionSeller = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($actionSellerUsername) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND (user_type = 'seller' OR user_type IS NULL OR user_type = '')");
        $stmt->execute([$actionSellerUsername]);
        $actionSeller = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($actionSeller) {
            $actionSellerId = (int)$actionSeller['id'];
        }
    }
    
    if ($actionSeller) {
        $whereClause = $actionSeller['id'] > 0 ? "id = ?" : "username = ?";
        $whereParam = $actionSeller['id'] > 0 ? $actionSeller['id'] : $actionSeller['username'];
        
        try {
            require_once '../includes/seller_notification_functions.php';
            
            switch ($action) {
                case 'approve':
                    $stmt = $pdo->prepare("UPDATE users SET seller_status = 'approved', user_type = 'seller' WHERE $whereClause");
                    $stmt->execute([$whereParam]);
                    $message = "Seller approved successfully!";
                    $messageType = 'success';
                    break;
                    
                case 'reject':
                    $stmt = $pdo->prepare("UPDATE users SET seller_status = 'rejected', user_type = 'seller' WHERE $whereClause");
                    $stmt->execute([$whereParam]);
                    $message = "Seller rejected.";
                    $messageType = 'warning';
                    break;
                    
                case 'suspend':
                    $suspendReason = isset($_POST['suspend_reason']) ? trim($_POST['suspend_reason']) : '';
                    if (empty($suspendReason)) {
                        $message = "Suspension reason is required.";
                        $messageType = 'error';
                    } else {
                        $stmt = $pdo->prepare("UPDATE users SET seller_status = 'suspended', user_type = 'seller' WHERE $whereClause");
                        $stmt->execute([$whereParam]);
                        
                        // Create seller notification with reason
                        createSellerNotification(
                            $actionSellerId > 0 ? $actionSellerId : $actionSeller['id'],
                            "⏸️ Account Suspended",
                            "Your seller account has been suspended by admin. Reason: " . htmlspecialchars($suspendReason),
                            'warning',
                            'seller-dashboard.php'
                        );
                        
                        $message = "Seller suspended successfully.";
                        $messageType = 'warning';
                    }
                    break;
                    
                case 'ban':
                    $stmt = $pdo->prepare("UPDATE users SET seller_status = 'banned', user_type = 'seller' WHERE $whereClause");
                    $stmt->execute([$whereParam]);
                    $message = "Seller banned.";
                    $messageType = 'error';
                    break;
                    
                case 'restore':
                    $stmt = $pdo->prepare("UPDATE users SET seller_status = 'approved', user_type = 'seller' WHERE $whereClause");
                    $stmt->execute([$whereParam]);
                    $message = "Seller restored to approved status.";
                    $messageType = 'success';
                    break;
            }
            
            // Redirect after action to prevent resubmission
            if (isset($message) && $action !== 'suspend' || (isset($message) && $action === 'suspend' && $messageType !== 'error')) {
                ob_end_clean();
                $redirectUrl = "seller-details.php?" . ($actionSeller['id'] > 0 ? "id=" . $actionSeller['id'] : "username=" . urlencode($actionSeller['username']));
                if (isset($_GET['product_page'])) {
                    $redirectUrl .= "&product_page=" . intval($_GET['product_page']);
                }
                header("Location: $redirectUrl?msg=" . urlencode($message) . "&type=" . $messageType);
                exit();
            }
        } catch (PDOException $e) {
            $message = "Error: " . $e->getMessage();
            $messageType = 'error';
        }
    } else {
        $message = "Seller not found for action.";
        $messageType = 'error';
    }
}

// Get seller details - handle both ID and username lookups
try {
    if ($sellerId > 0) {
        // First try with user_type check
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND (user_type = 'seller' OR user_type IS NULL OR user_type = '')");
        $stmt->execute([$sellerId]);
        $seller = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If not found, try without user_type restriction
        if (!$seller) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$sellerId]);
            $seller = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } elseif ($sellerUsername) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND (user_type = 'seller' OR user_type IS NULL OR user_type = '')");
        $stmt->execute([$sellerUsername]);
        $seller = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($seller) {
            $sellerId = (int)$seller['id'];
        }
    } else {
        $seller = null;
    }
    
    if (!$seller) {
        ob_end_clean();
        header('Location: admin-sellers.php?msg=' . urlencode('Seller not found') . '&type=error');
        exit();
    }
    
    // CRITICAL: Always use the seller ID from the database lookup, not the GET parameter
    // This ensures we use the correct ID even if seller was found by username
    $sellerId = (int)$seller['id'];
    
    // If seller was found by username and we have a username, keep it for fallback
    if (!$sellerUsername && isset($seller['username'])) {
        $sellerUsername = $seller['username'];
    }
    
    // Ensure seller_status defaults to 'pending' if not set
    if (!isset($seller['seller_status']) || $seller['seller_status'] === null) {
        $seller['seller_status'] = 'pending';
    }
} catch (PDOException $e) {
    ob_end_clean();
    header('Location: admin-sellers.php?msg=' . urlencode('Error retrieving seller details') . '&type=error');
    exit();
}

// Get seller statistics - use the sellerId from database lookup (line 166)
try {
    // Ensure sellerId is valid before querying
    if ($sellerId <= 0) {
        $totalProducts = 0;
        $activeProducts = 0;
        $outOfStock = 0;
        $totalOrders = 0;
        $totalRevenue = 0;
        $avgPrice = 0;
        $totalViews = 0;
        $accountAgeDays = 0;
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM products WHERE seller_id = ?");
        $stmt->execute([$sellerId]);
        $totalProducts = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Note: is_active column doesn't exist, using status='active' instead
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM products WHERE seller_id = ? AND status = 'active'");
        $stmt->execute([$sellerId]);
        $activeProducts = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM products WHERE seller_id = ? AND stock_quantity = 0");
        $stmt->execute([$sellerId]);
        $outOfStock = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT o.id) as total 
            FROM orders o 
            JOIN order_items oi ON o.id = oi.order_id 
            JOIN products p ON oi.product_id = p.id 
            WHERE p.seller_id = ?
        ");
        $stmt->execute([$sellerId]);
        $totalOrders = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(oi.price * oi.quantity), 0) as total_revenue
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            WHERE p.seller_id = ?
        ");
        $stmt->execute([$sellerId]);
        $totalRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total_revenue'];
        
        $stmt = $pdo->prepare("SELECT COALESCE(AVG(price), 0) as avg_price FROM products WHERE seller_id = ?");
        $stmt->execute([$sellerId]);
        $avgPrice = $stmt->fetch(PDO::FETCH_ASSOC)['avg_price'];
        
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(views), 0) as total_views FROM products WHERE seller_id = ?");
        $stmt->execute([$sellerId]);
        $totalViews = $stmt->fetch(PDO::FETCH_ASSOC)['total_views'];
        
        $accountAgeDays = $seller['created_at'] ? floor((time() - strtotime($seller['created_at'])) / 86400) : 0;
    }
} catch (PDOException $e) {
    $totalProducts = $activeProducts = $outOfStock = $totalOrders = 0;
    $totalRevenue = $avgPrice = $totalViews = 0;
    $accountAgeDays = 0;
}

// Get seller's products with pagination
$productPage = isset($_GET['product_page']) ? intval($_GET['product_page']) : 1;
$productsPerPage = 10;
$productOffset = ($productPage - 1) * $productsPerPage;

try {
    // CRITICAL FIX: Always use seller ID from the database lookup, not the GET parameter
    // Double-check we're using the correct seller ID
    $actualSellerId = isset($seller['id']) ? (int)$seller['id'] : $sellerId;
    if ($actualSellerId > 0 && $actualSellerId != $sellerId) {
        $sellerId = $actualSellerId;
    }
    
    // Always try to get products using sellerId first (from database lookup)
    if ($sellerId > 0) {
        // Get total product count - NO FILTERS, show ALL products
        $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM products WHERE seller_id = ?");
        $countStmt->execute([$sellerId]);
        $totalProductsCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        $totalProductPages = ceil($totalProductsCount / $productsPerPage);
        
        // Get paginated products - show ALL products regardless of status
        $stmt = $pdo->prepare("
            SELECT id, name, price, stock_quantity, COALESCE(status, 'active') as status, created_at, image_url, seller_id
            FROM products 
            WHERE seller_id = ? 
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $sellerId, PDO::PARAM_INT);
        $stmt->bindValue(2, $productsPerPage, PDO::PARAM_INT);
        $stmt->bindValue(3, $productOffset, PDO::PARAM_INT);
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($sellerUsername) {
        // Fallback: Seller ID is 0 or invalid - try to find products by joining with users table using username
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM products p
            JOIN users u ON p.seller_id = u.id
            WHERE u.username = ?
        ");
        $countStmt->execute([$sellerUsername]);
        $totalProductsCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        $totalProductPages = ceil($totalProductsCount / $productsPerPage);
        
        $stmt = $pdo->prepare("
            SELECT p.id, p.name, p.price, p.stock_quantity, COALESCE(p.status, 'active') as status, p.created_at, p.image_url, p.seller_id
            FROM products p
            JOIN users u ON p.seller_id = u.id
            WHERE u.username = ?
            ORDER BY p.created_at DESC 
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $sellerUsername, PDO::PARAM_STR);
        $stmt->bindValue(2, $productsPerPage, PDO::PARAM_INT);
        $stmt->bindValue(3, $productOffset, PDO::PARAM_INT);
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // No seller ID or username - show empty
        $products = [];
        $totalProductsCount = 0;
        $totalProductPages = 1;
    }
} catch (PDOException $e) {
    error_log("Error fetching products for seller: " . $e->getMessage());
    $products = [];
    $totalProductsCount = 0;
    $totalProductPages = 1;
}
// Safe to output after any redirects above
require_once 'includes/admin_header.php';
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
    .status-pending { background: #fef3c7; color: #92400e; }
    .status-approved { background: #d1fae5; color: #065f46; }
    .status-rejected, .status-banned { background: #fee2e2; color: #991b1b; }
    .status-suspended { background: #e5e7eb; color: #374151; }

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
    .btn-approve { background: #28a745; color: #ffffff; }
    .btn-approve:hover { background: #218838; }
    .btn-reject { background: #dc3545; color: #ffffff; }
    .btn-reject:hover { background: #c82333; }
    .btn-suspend { background: #6c757d; color: #ffffff; }
    .btn-suspend:hover { background: #5a6268; }
    .btn-ban { background: #c0392b; color: #ffffff; }
    .btn-ban:hover { background: #a93226; }
    .btn-restore { background: #3498db; color: #ffffff; }
    .btn-restore:hover { background: #2980b9; }

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

    /* Products Section */
    .products-section {
        background: #ffffff;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 12px;
        padding: 24px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .products-section h2 {
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
    .product-image {
        width: 50px;
        height: 50px;
        object-fit: cover;
        border-radius: 6px;
        border: 1px solid rgba(0,0,0,0.1);
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
    
    .modal-body textarea {
        font-size: 12px;
    }
    
    .modal-body label {
        font-size: 12px;
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
    <a href="admin-sellers.php">
        <i class="fas fa-arrow-left"></i> Back to Sellers
    </a>
</div>

<div class="page-header">
    <h1><i class="fas fa-store"></i> Seller Details</h1>
    <div class="page-actions">
        <?php 
        $sellerIdParam = $sellerId > 0 ? "id=" . (int)$sellerId : "username=" . urlencode($seller['username']);
        $sellerStatus = $seller['seller_status'] ?? 'pending';
        ?>
        <?php if ($sellerStatus === 'pending'): ?>
            <a href="#" onclick="openConfirmModal('approve'); return false;" 
               class="action-btn btn-approve">
                <i class="fas fa-check-circle"></i> Approve
            </a>
            <a href="#" onclick="openConfirmModal('reject'); return false;" 
               class="action-btn btn-reject">
                <i class="fas fa-times-circle"></i> Reject
            </a>
        <?php endif; ?>
        
        <?php if ($sellerStatus === 'approved'): ?>
            <a href="#" onclick="openConfirmModal('suspend'); return false;" 
               class="action-btn btn-suspend">
                <i class="fas fa-pause-circle"></i> Suspend
            </a>
            <a href="#" onclick="openConfirmModal('ban'); return false;" 
               class="action-btn btn-ban">
                <i class="fas fa-ban"></i> Ban
            </a>
        <?php endif; ?>
        
        <?php if (in_array($sellerStatus, ['suspended','banned','rejected'])): ?>
            <a href="#" onclick="openConfirmModal('restore'); return false;" 
               class="action-btn btn-restore">
                <i class="fas fa-undo"></i> Restore
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
                    <label>Seller ID</label>
                    <span>#<?php echo (int)$seller['id']; ?></span>
                </div>
                <div class="info-item">
                    <label>Status</label>
                    <span>
                        <span class="status-badge status-<?php echo htmlspecialchars(strtolower($seller['seller_status'] ?? 'pending')); ?>">
                            <?php echo htmlspecialchars(ucfirst($seller['seller_status'] ?? 'Pending')); ?>
                        </span>
                    </span>
                </div>
                <div class="info-item">
                    <label>Username</label>
                    <span><?php echo htmlspecialchars($seller['username'] ?? ''); ?></span>
                </div>
                <div class="info-item">
                    <label>Email</label>
                    <span><?php echo htmlspecialchars($seller['email'] ?? ''); ?></span>
                </div>
                <div class="info-item">
                    <label>Full Name</label>
                    <span><?php echo htmlspecialchars(trim(($seller['first_name'] ?? '') . ' ' . ($seller['last_name'] ?? '')) ?: 'N/A'); ?></span>
                </div>
                <div class="info-item">
                    <label>Phone</label>
                    <span><?php echo htmlspecialchars($seller['phone'] ?? 'Not provided'); ?></span>
                </div>
                <div class="info-item">
                    <label>Address</label>
                    <span><?php echo htmlspecialchars($seller['address'] ?? 'Not provided'); ?></span>
                </div>
                <div class="info-item">
                    <label>Registered</label>
                    <span><?php echo $seller['created_at'] ? date('M j, Y', strtotime($seller['created_at'])) : 'N/A'; ?></span>
                </div>
            </div>
        </div>

        <div class="profile-stats">
            <h2><i class="fas fa-chart-line"></i> Statistics</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($totalProducts); ?></div>
                    <div class="stat-label">Total Products</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($activeProducts); ?></div>
                    <div class="stat-label">Active Products</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($totalOrders); ?></div>
                    <div class="stat-label">Total Orders</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">₱<?php echo number_format($totalRevenue, 2); ?></div>
                    <div class="stat-label">Total Revenue</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($outOfStock); ?></div>
                    <div class="stat-label">Out of Stock</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $accountAgeDays; ?></div>
                    <div class="stat-label">Account Age (Days)</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Products Section -->
    <div class="products-section">
        <h2><i class="fas fa-shopping-bag"></i> Products (<?php echo number_format($totalProductsCount); ?>)</h2>
        <?php if (empty($products)): ?>
            <p style="text-align: center; color: #6b7280; padding: 40px;">No products found for this seller.</p>
        <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Product ID</th>
                            <th>Product Name</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td><strong>#<?php echo (int)$product['id']; ?></strong></td>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td>₱<?php echo number_format($product['price'], 2); ?></td>
                                <td><?php echo number_format($product['stock_quantity']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo ($product['status'] ?? 'active') === 'active' ? 'approved' : 'suspended'; ?>">
                                        <?php echo ucfirst($product['status'] ?? 'Active'); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($product['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($totalProductPages > 1): ?>
                <div class="pagination">
                    <?php if ($productPage > 1): ?>
                        <a href="seller-details.php?<?php echo $sellerId > 0 ? "id=" . $sellerId : "username=" . urlencode($seller['username']); ?>&product_page=<?php echo $productPage - 1; ?>" class="page-link">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $totalProductPages; $i++): ?>
                        <a href="seller-details.php?<?php echo $sellerId > 0 ? "id=" . $sellerId : "username=" . urlencode($seller['username']); ?>&product_page=<?php echo $i; ?>" 
                           class="page-link <?php echo $i === $productPage ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($productPage < $totalProductPages): ?>
                        <a href="seller-details.php?<?php echo $sellerId > 0 ? "id=" . $sellerId : "username=" . urlencode($seller['username']); ?>&product_page=<?php echo $productPage + 1; ?>" class="page-link">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Confirmation Modals -->
<!-- Approve Modal -->
<div id="approveModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-header">
            <div class="modal-title">Confirm Approval</div>
            <button class="modal-close" aria-label="Close" onclick="closeConfirmModal('approve')">×</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to approve this seller? They will be able to sell products on the platform.</p>
        </div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeConfirmModal('approve')">Cancel</button>
            <a href="seller-details.php?action=approve&<?php echo $sellerIdParam; ?>" class="btn-primary-y">Approve</a>
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
            <p>Are you sure you want to reject this seller? They will not be able to sell products on the platform.</p>
        </div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeConfirmModal('reject')">Cancel</button>
            <a href="seller-details.php?action=reject&<?php echo $sellerIdParam; ?>" class="btn-danger">Reject</a>
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
        <form method="POST" action="seller-details.php">
            <input type="hidden" name="action" value="suspend">
            <input type="hidden" name="<?php echo $sellerId > 0 ? 'id' : 'username'; ?>" value="<?php echo $sellerId > 0 ? (int)$sellerId : htmlspecialchars($seller['username']); ?>">
            <div class="modal-body">
                <p>Are you sure you want to suspend this seller? They will not be able to sell products temporarily.</p>
                <div style="margin-top: 12px;">
                    <label for="suspend_reason" style="display: block; margin-bottom: 6px; font-weight: 600; color: #130325; font-size: 12px;">Suspension Reason <span style="color: #dc2626;">*</span></label>
                    <textarea id="suspend_reason" name="suspend_reason" required 
                              style="width: 100%; padding: 6px 10px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; min-height: 60px; resize: vertical;"
                              placeholder="Enter the reason for suspending this seller..."></textarea>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-outline" onclick="closeConfirmModal('suspend')">Cancel</button>
                <button type="submit" class="btn-primary-y">Confirm</button>
            </div>
        </form>
    </div>
</div>

<!-- Ban Modal -->
<div id="banModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-header">
            <div class="modal-title">Confirm Ban</div>
            <button class="modal-close" aria-label="Close" onclick="closeConfirmModal('ban')">×</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to ban this seller? This is a severe action that will permanently prevent them from selling.</p>
        </div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeConfirmModal('ban')">Cancel</button>
            <a href="seller-details.php?action=ban&<?php echo $sellerIdParam; ?>" class="btn-danger">Ban</a>
        </div>
    </div>
</div>

<!-- Restore Modal -->
<div id="restoreModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-header">
            <div class="modal-title">Confirm Restore</div>
            <button class="modal-close" aria-label="Close" onclick="closeConfirmModal('restore')">×</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to restore this seller? They will be able to sell products again.</p>
        </div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeConfirmModal('restore')">Cancel</button>
            <a href="seller-details.php?action=restore&<?php echo $sellerIdParam; ?>" class="btn-primary-y">Restore</a>
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
