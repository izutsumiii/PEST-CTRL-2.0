<?php
require_once '../config/database.php';
require_once 'includes/admin_header.php';

requireAdmin();

$sellerId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$sellerId) {
    header('Location: admin-sellers.php?msg=' . urlencode('Invalid seller ID') . '&type=error');
    exit();
}

// Initialize message variables
$message = '';
$messageType = '';

// Handle actions
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    try {
        switch ($action) {
            case 'approve':
                $stmt = $pdo->prepare("UPDATE users SET seller_status = 'approved' WHERE id = ? AND user_type = 'seller'");
                $stmt->execute([$sellerId]);
                $message = "Seller approved successfully!";
                $messageType = 'success';
                break;
                
            case 'reject':
                $stmt = $pdo->prepare("UPDATE users SET seller_status = 'rejected' WHERE id = ? AND user_type = 'seller'");
                $stmt->execute([$sellerId]);
                $message = "Seller rejected.";
                $messageType = 'warning';
                break;
                
            case 'suspend':
                $stmt = $pdo->prepare("UPDATE users SET seller_status = 'suspended' WHERE id = ? AND user_type = 'seller'");
                $stmt->execute([$sellerId]);
                $message = "Seller suspended.";
                $messageType = 'warning';
                break;
                
            case 'ban':
                $stmt = $pdo->prepare("UPDATE users SET seller_status = 'banned' WHERE id = ? AND user_type = 'seller'");
                $stmt->execute([$sellerId]);
                $message = "Seller banned.";
                $messageType = 'error';
                break;
                
            case 'restore':
                $stmt = $pdo->prepare("UPDATE users SET seller_status = 'approved' WHERE id = ? AND user_type = 'seller'");
                $stmt->execute([$sellerId]);
                $message = "Seller restored to approved status.";
                $messageType = 'success';
                break;
        }
    } catch (PDOException $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Get seller details
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND user_type = 'seller'");
    $stmt->execute([$sellerId]);
    $seller = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$seller) {
        header('Location: admin-sellers.php?msg=' . urlencode('Seller not found') . '&type=error');
        exit();
    }
} catch (PDOException $e) {
    header('Location: admin-sellers.php?msg=' . urlencode('Error retrieving seller details') . '&type=error');
    exit();
}

// Get seller statistics
try {
    // Total products
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM products WHERE seller_id = ?");
    $stmt->execute([$sellerId]);
    $totalProducts = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Active products
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM products WHERE seller_id = ? AND is_active = 1");
    $stmt->execute([$sellerId]);
    $activeProducts = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Out of stock products
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM products WHERE seller_id = ? AND stock_quantity = 0");
    $stmt->execute([$sellerId]);
    $outOfStock = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Total orders
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT o.id) as total 
        FROM orders o 
        JOIN order_items oi ON o.id = oi.order_id 
        JOIN products p ON oi.product_id = p.id 
        WHERE p.seller_id = ?
    ");
    $stmt->execute([$sellerId]);
    $totalOrders = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Total revenue
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(oi.price * oi.quantity), 0) as total_revenue
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        WHERE p.seller_id = ?
    ");
    $stmt->execute([$sellerId]);
    $totalRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total_revenue'];
    
    // Average product price
    $stmt = $pdo->prepare("SELECT COALESCE(AVG(price), 0) as avg_price FROM products WHERE seller_id = ?");
    $stmt->execute([$sellerId]);
    $avgPrice = $stmt->fetch(PDO::FETCH_ASSOC)['avg_price'];
    
    // Total product views (if you have a views column)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(views), 0) as total_views FROM products WHERE seller_id = ?");
    $stmt->execute([$sellerId]);
    $totalViews = $stmt->fetch(PDO::FETCH_ASSOC)['total_views'];
    
    // Account age
    $accountAgeDays = $seller['created_at'] ? floor((time() - strtotime($seller['created_at'])) / 86400) : 0;
    
} catch (PDOException $e) {
    $totalProducts = $activeProducts = $outOfStock = $totalOrders = 0;
    $totalRevenue = $avgPrice = $totalViews = 0;
    $accountAgeDays = 0;
}

// Get seller's products
try {
    $stmt = $pdo->prepare("
        SELECT id, name, price, stock_quantity, is_active, created_at, image_url
        FROM products 
        WHERE seller_id = ? 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$sellerId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $products = [];
}

// Get recent orders
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT o.id, o.created_at, o.status, o.total_amount
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN products p ON oi.product_id = p.id
        WHERE p.seller_id = ?
        ORDER BY o.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$sellerId]);
    $recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recentOrders = [];
}
?>

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

    /* Breadcrumb */
    .breadcrumb {
        max-width: 1400px;
        margin: 20px auto 10px;
        padding: 0 20px;
    }
    
    .breadcrumb a {
        color: var(--accent-yellow);
        text-decoration: none;
        font-weight: 700;
        font-size: 14px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s ease;
    }
    
    .breadcrumb a:hover {
        color: #e6c230;
        transform: translateX(-3px);
    }

    /* Page Header */
    h1 {
        max-width: 1400px;
        margin: 10px auto 30px;
        padding: 0 20px;
        font-size: 28px;
        font-weight: 800;
        color: var(--text-light);
    }

    /* Main Container */
    .container {
        max-width: 1400px;
        margin: 0 auto 30px;
        padding: 0 20px;
    }

    /* Grid Layout */
    .grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 20px;
    }

    /* Card Styles */
    .card {
        background: var(--primary-dark);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
        transition: all 0.3s ease;
    }

    .card:hover {
        border-color: var(--accent-yellow);
        box-shadow: 0 8px 24px rgba(255, 215, 54, 0.2);
    }

    .card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 18px 20px;
        border-bottom: 2px solid var(--accent-yellow);
        background: rgba(255, 215, 54, 0.06);
    }

    .card-header h2 {
        margin: 0;
        font-size: 18px;
        color: var(--accent-yellow);
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .card-header-actions {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .action-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 6px;
        text-decoration: none;
        transition: all 0.3s ease;
        cursor: pointer;
        border: none;
        font-size: 14px;
    }

    .action-icon:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    }

    .action-icon-approve {
        background: rgba(40, 167, 69, 0.2);
        color: var(--success-green);
        border: 1px solid var(--success-green);
    }

    .action-icon-approve:hover {
        background: var(--success-green);
        color: white;
    }

    .action-icon-reject {
        background: rgba(220, 53, 69, 0.2);
        color: var(--danger-red);
        border: 1px solid var(--danger-red);
    }

    .action-icon-reject:hover {
        background: var(--danger-red);
        color: white;
    }

    .action-icon-suspend {
        background: rgba(108, 117, 125, 0.2);
        color: #6c757d;
        border: 1px solid #6c757d;
    }

    .action-icon-suspend:hover {
        background: #6c757d;
        color: white;
    }

    .action-icon-ban {
        background: rgba(192, 57, 43, 0.2);
        color: #c0392b;
        border: 1px solid #c0392b;
    }

    .action-icon-ban:hover {
        background: #c0392b;
        color: white;
    }

    .action-icon-restore {
        background: rgba(52, 152, 219, 0.2);
        color: #3498db;
        border: 1px solid #3498db;
    }

    .action-icon-restore:hover {
        background: #3498db;
        color: white;
    }

    /* Custom Confirmation Dialog Styles */
    .custom-confirm-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.8);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10000;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }

    .custom-confirm-overlay.show {
        opacity: 1;
        visibility: visible;
    }

    .custom-confirm-dialog {
        background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-dark) 100%);
        border: 2px solid var(--accent-yellow);
        border-radius: 12px;
        padding: 30px;
        max-width: 400px;
        width: 90%;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
        transform: scale(0.8) translateY(-20px);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .custom-confirm-overlay.show .custom-confirm-dialog {
        transform: scale(1) translateY(0);
    }

    .custom-confirm-dialog::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, 
            var(--accent-yellow) 0%, 
            #FFE066 25%, 
            var(--accent-yellow) 50%, 
            #FFE066 75%, 
            var(--accent-yellow) 100%);
        background-size: 200% 100%;
        animation: shimmer 3s linear infinite;
    }

    @keyframes shimmer {
        0% { background-position: -200% 0; }
        100% { background-position: 200% 0; }
    }

    .custom-confirm-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 20px;
    }

    .custom-confirm-icon {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        color: white;
    }

    .custom-confirm-icon.warning {
        background: linear-gradient(135deg, var(--warning-yellow), #e6a800);
    }

    .custom-confirm-icon.danger {
        background: linear-gradient(135deg, var(--danger-red), #e74c3c);
    }

    .custom-confirm-icon.success {
        background: linear-gradient(135deg, var(--success-green), #20c997);
    }

    .custom-confirm-title {
        font-size: 20px;
        font-weight: 700;
        color: var(--text-light);
        margin: 0;
    }

    .custom-confirm-message {
        color: rgba(249, 249, 249, 0.8);
        font-size: 16px;
        line-height: 1.5;
        margin-bottom: 30px;
    }

    .custom-confirm-buttons {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    .custom-confirm-btn {
        padding: 12px 24px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 700;
        border: none;
        cursor: pointer;
        transition: all 0.3s ease;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .custom-confirm-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.3);
    }

    .custom-confirm-btn.cancel {
        background: rgba(108, 117, 125, 0.2);
        color: #6c757d;
        border: 2px solid #6c757d;
    }

    .custom-confirm-btn.cancel:hover {
        background: #6c757d;
        color: white;
    }

    .custom-confirm-btn.confirm {
        background: linear-gradient(135deg, var(--danger-red), #e74c3c);
        color: white;
        border: 2px solid var(--danger-red);
    }

    .custom-confirm-btn.confirm:hover {
        background: linear-gradient(135deg, #c82333, #dc3545);
    }

    .custom-confirm-btn.warning {
        background: linear-gradient(135deg, var(--warning-yellow), #e6a800);
        color: #1a0a2e;
        border: 2px solid var(--warning-yellow);
    }

    .custom-confirm-btn.warning:hover {
        background: linear-gradient(135deg, #e6a800, #ffc107);
    }

    .custom-confirm-btn.success {
        background: linear-gradient(135deg, var(--success-green), #20c997);
        color: white;
        border: 2px solid var(--success-green);
    }

    .custom-confirm-btn.success:hover {
        background: linear-gradient(135deg, #1e7e34, #28a745);
    }

    .card-content {
        padding: 20px;
    }

    /* Info Grid */
    .info-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
    }

    .info-item {
        padding: 14px;
        background: rgba(255, 255, 255, 0.03);
        border-radius: 8px;
        border: 1px solid var(--border-color);
        transition: all 0.3s ease;
    }

    .info-item:hover {
        background: rgba(255, 255, 255, 0.05);
        transform: translateX(3px);
    }

    .info-item label {
        display: block;
        font-size: 12px;
        color: rgba(249, 249, 249, 0.7);
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
    }

    .info-item span {
        font-size: 15px;
        color: var(--text-light);
        font-weight: 500;
        display: block;
        word-break: break-word;
    }

    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
    }

    .stat-item {
        padding: 20px;
        background: rgba(255, 215, 54, 0.05);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        text-align: center;
        transition: all 0.3s ease;
    }

    .stat-item:hover {
        background: rgba(255, 215, 54, 0.1);
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(255, 215, 54, 0.2);
    }

    .stat-number {
        font-size: 32px;
        font-weight: 800;
        color: var(--accent-yellow);
        margin-bottom: 8px;
    }

    .stat-label {
        font-size: 13px;
        color: rgba(249, 249, 249, 0.8);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* Status Badges */
    .status-badge {
        display: inline-block;
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 0.5px;
        text-transform: uppercase;
    }

    .status-pending {
        background: rgba(255, 193, 7, 0.2);
        color: var(--warning-yellow);
        border: 2px solid var(--warning-yellow);
    }

    .status-approved {
        background: rgba(40, 167, 69, 0.2);
        color: var(--success-green);
        border: 2px solid var(--success-green);
    }

    .status-rejected, .status-banned {
        background: rgba(220, 53, 69, 0.2);
        color: var(--danger-red);
        border: 2px solid var(--danger-red);
    }

    .status-suspended {
        background: rgba(108, 117, 125, 0.2);
        color: #6c757d;
        border: 2px solid #6c757d;
    }

    /* Tables */
    .table-container {
        overflow-x: auto;
        border-radius: 8px;
        border: 1px solid var(--border-color);
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    thead {
        background: rgba(255, 215, 54, 0.1);
    }

    th {
        padding: 14px 12px;
        text-align: left;
        font-size: 13px;
        color: var(--accent-yellow);
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    td {
        padding: 14px 12px;
        border-bottom: 1px solid var(--border-color);
        font-size: 14px;
        color: var(--text-light);
    }

    tr:hover {
        background: rgba(255, 255, 255, 0.02);
    }

    .product-image {
        width: 50px;
        height: 50px;
        object-fit: cover;
        border-radius: 6px;
        border: 2px solid var(--border-color);
    }

    /* Action Buttons */
    .action-buttons {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 20px;
    }

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
    }

    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.3);
    }

    .btn-approve {
        background: linear-gradient(135deg, var(--success-green), #20c997);
        color: white;
    }

    .btn-reject {
        background: linear-gradient(135deg, var(--danger-red), #e74c3c);
        color: white;
    }

    .btn-suspend {
        background: linear-gradient(135deg, #6c757d, #95a5a6);
        color: white;
    }

    .btn-ban {
        background: linear-gradient(135deg, #c0392b, #e74c3c);
        color: white;
    }

    .btn-restore {
        background: linear-gradient(135deg, #3498db, #5dade2);
        color: white;
    }

    /* Full Width Cards */
    .full-width {
        grid-column: 1 / -1;
    }

    /* Responsive */
    @media (max-width: 1200px) {
        .grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .grid {
            grid-template-columns: 1fr;
        }
        
        .info-grid, .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .action-buttons {
            flex-direction: column;
        }
        
        .btn {
            width: 100%;
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

<h1><i class="fas fa-store"></i> Seller Details</h1>

<div class="container">
    <div class="grid">
        <!-- Seller Profile Card -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-user-circle"></i> Seller Profile</h2>
                <div class="card-header-actions">
                    <span class="status-badge status-<?php echo htmlspecialchars(strtolower($seller['seller_status'] ?? 'pending')); ?>">
                        <?php echo htmlspecialchars(ucfirst($seller['seller_status'] ?? 'Pending')); ?>
                    </span>
                    <?php if (($seller['seller_status'] ?? 'pending') === 'pending'): ?>
                        <a href="seller-details.php?action=approve&id=<?php echo (int)$sellerId; ?>" 
                           class="action-icon action-icon-approve"
                           title="Approve Seller">
                            <i class="fas fa-check-circle"></i>
                        </a>
                        <a href="seller-details.php?action=reject&id=<?php echo (int)$sellerId; ?>" 
                           class="action-icon action-icon-reject"
                           title="Reject Seller">
                            <i class="fas fa-times-circle"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php if (($seller['seller_status'] ?? '') === 'approved'): ?>
                        <a href="seller-details.php?action=suspend&id=<?php echo (int)$sellerId; ?>" 
                           class="action-icon action-icon-suspend"
                           title="Suspend Seller">
                            <i class="fas fa-pause-circle"></i>
                        </a>
                        <a href="seller-details.php?action=ban&id=<?php echo (int)$sellerId; ?>" 
                           class="action-icon action-icon-ban"
                           title="Ban Seller">
                            <i class="fas fa-ban"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php if (in_array(($seller['seller_status'] ?? ''), ['suspended','banned','rejected'])): ?>
                        <a href="seller-details.php?action=restore&id=<?php echo (int)$sellerId; ?>" 
                           class="action-icon action-icon-restore"
                           title="Restore to Approved">
                            <i class="fas fa-undo"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-content">
                <div class="info-grid">
                    <div class="info-item">
                        <label><i class="fas fa-hashtag"></i> Seller ID</label>
                        <span><?php echo (int)$seller['id']; ?></span>
                    </div>
                    <div class="info-item">
                        <label><i class="fas fa-user"></i> Username</label>
                        <span><?php echo htmlspecialchars($seller['username'] ?? ''); ?></span>
                    </div>
                    <div class="info-item">
                        <label><i class="fas fa-signature"></i> Full Name</label>
                        <span><?php echo htmlspecialchars(trim(($seller['first_name'] ?? '') . ' ' . ($seller['last_name'] ?? ''))); ?></span>
                    </div>
                    <div class="info-item">
                        <label><i class="fas fa-envelope"></i> Email</label>
                        <span><?php echo htmlspecialchars($seller['email'] ?? ''); ?></span>
                    </div>
                    <div class="info-item">
                        <label><i class="fas fa-phone"></i> Phone</label>
                        <span><?php echo htmlspecialchars($seller['phone'] ?? 'Not provided'); ?></span>
                    </div>
                    <div class="info-item">
                        <label><i class="fas fa-map-marker-alt"></i> Address</label>
                        <span><?php echo htmlspecialchars($seller['address'] ?? 'Not provided'); ?></span>
                    </div>
                    <div class="info-item">
                        <label><i class="fas fa-calendar-alt"></i> Registration Date</label>
                        <span><?php echo $seller['created_at'] ? date('F j, Y g:i A', strtotime($seller['created_at'])) : 'N/A'; ?></span>
                    </div>
                    <div class="info-item">
                        <label><i class="fas fa-clock"></i> Account Age</label>
                        <span><?php echo $accountAgeDays; ?> days</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Seller Statistics Card -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-chart-line"></i> Statistics</h2>
            </div>
            <div class="card-content">
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo number_format($totalProducts); ?></div>
                        <div class="stat-label">Total Products</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo number_format($activeProducts); ?></div>
                        <div class="stat-label">Active Products</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo number_format($totalOrders); ?></div>
                        <div class="stat-label">Total Orders</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">₱<?php echo number_format($totalRevenue, 2); ?></div>
                        <div class="stat-label">Total Revenue</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Product Analytics Card -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-box"></i> Product Analytics</h2>
            </div>
            <div class="card-content">
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo number_format($outOfStock); ?></div>
                        <div class="stat-label">Out of Stock</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">₱<?php echo number_format($avgPrice, 2); ?></div>
                        <div class="stat-label">Avg Product Price</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo number_format($totalViews); ?></div>
                        <div class="stat-label">Total Views</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">
                            <?php echo $totalOrders > 0 ? '₱' . number_format($totalRevenue / $totalOrders, 2) : '₱0.00'; ?>
                        </div>
                        <div class="stat-label">Avg Order Value</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Products Card -->
        <div class="card full-width">
            <div class="card-header">
                <h2><i class="fas fa-shopping-bag"></i> Recent Products (<?php echo count($products); ?>)</h2>
            </div>
            <div class="card-content">
                <?php if (empty($products)): ?>
                    <p style="text-align: center; color: rgba(249, 249, 249, 0.6);">No products found.</p>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Image</th>
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
                                        <td>
                                            <img src="<?php echo htmlspecialchars($product['image_url'] ?? 'images/placeholder.jpg'); ?>" 
                                                 alt="Product" class="product-image">
                                        </td>
                                        <td><?php echo htmlspecialchars($product['name']); ?></td>
                                        <td>₱<?php echo number_format($product['price'], 2); ?></td>
                                        <td><?php echo number_format($product['stock_quantity']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $product['is_active'] ? 'approved' : 'suspended'; ?>">
                                                <?php echo $product['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($product['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Orders Card -->
        <div class="card full-width">
            <div class="card-header">
                <h2><i class="fas fa-receipt"></i> Recent Orders (<?php echo count($recentOrders); ?>)</h2>
            </div>
            <div class="card-content">
                <?php if (empty($recentOrders)): ?>
                    <p style="text-align: center; color: rgba(249, 249, 249, 0.6);">No orders found.</p>
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
                                <?php foreach ($recentOrders as $order): ?>
                                    <tr>
                                        <td>#<?php echo htmlspecialchars($order['id']); ?></td>
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
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Custom Confirmation Dialog System
function showCustomConfirm(title, message, type = 'warning', onConfirm) {
    // Create overlay
    const overlay = document.createElement('div');
    overlay.className = 'custom-confirm-overlay';
    
    // Create dialog
    const dialog = document.createElement('div');
    dialog.className = 'custom-confirm-dialog';
    
    // Create header
    const header = document.createElement('div');
    header.className = 'custom-confirm-header';
    
    const icon = document.createElement('div');
    icon.className = `custom-confirm-icon ${type}`;
    if (type === 'danger') {
        icon.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
    } else if (type === 'success') {
        icon.innerHTML = '<i class="fas fa-check-circle"></i>';
    } else {
        icon.innerHTML = '<i class="fas fa-question-circle"></i>';
    }
    
    const titleEl = document.createElement('h3');
    titleEl.className = 'custom-confirm-title';
    titleEl.textContent = title;
    
    header.appendChild(icon);
    header.appendChild(titleEl);
    
    // Create message
    const messageEl = document.createElement('div');
    messageEl.className = 'custom-confirm-message';
    messageEl.textContent = message;
    
    // Create buttons
    const buttons = document.createElement('div');
    buttons.className = 'custom-confirm-buttons';
    
    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'custom-confirm-btn cancel';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.onclick = () => closeDialog();
    
    const confirmBtn = document.createElement('button');
    confirmBtn.className = `custom-confirm-btn ${type}`;
    if (type === 'danger') {
        confirmBtn.textContent = 'Confirm';
    } else if (type === 'success') {
        confirmBtn.textContent = 'Approve';
    } else {
        confirmBtn.textContent = 'Confirm';
    }
    confirmBtn.onclick = () => {
        closeDialog();
        onConfirm();
    };
    
    buttons.appendChild(cancelBtn);
    buttons.appendChild(confirmBtn);
    
    // Assemble dialog
    dialog.appendChild(header);
    dialog.appendChild(messageEl);
    dialog.appendChild(buttons);
    overlay.appendChild(dialog);
    
    // Add to page
    document.body.appendChild(overlay);
    
    // Show with animation
    setTimeout(() => overlay.classList.add('show'), 10);
    
    // Close function
    function closeDialog() {
        overlay.classList.remove('show');
        setTimeout(() => {
            if (overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
            }
        }, 300);
    }
    
    // Close on overlay click
    overlay.onclick = (e) => {
        if (e.target === overlay) {
            closeDialog();
        }
    };
    
    // Close on escape key
    const handleEscape = (e) => {
        if (e.key === 'Escape') {
            closeDialog();
            document.removeEventListener('keydown', handleEscape);
        }
    };
    document.addEventListener('keydown', handleEscape);
}

// Override default confirm for action icons
document.addEventListener('DOMContentLoaded', function() {
    // Handle approve actions
    const approveLinks = document.querySelectorAll('a[href*="action=approve"]');
    approveLinks.forEach(link => {
        link.onclick = function(e) {
            e.preventDefault();
            showCustomConfirm(
                'Approve Seller',
                'Are you sure you want to approve this seller? They will be able to start selling products.',
                'success',
                () => {
                    window.location.href = this.href;
                }
            );
        };
    });
    
    // Handle reject actions
    const rejectLinks = document.querySelectorAll('a[href*="action=reject"]');
    rejectLinks.forEach(link => {
        link.onclick = function(e) {
            e.preventDefault();
            showCustomConfirm(
                'Reject Seller',
                'Are you sure you want to reject this seller? This action will prevent them from selling.',
                'warning',
                () => {
                    window.location.href = this.href;
                }
            );
        };
    });
    
    // Handle suspend actions
    const suspendLinks = document.querySelectorAll('a[href*="action=suspend"]');
    suspendLinks.forEach(link => {
        link.onclick = function(e) {
            e.preventDefault();
            showCustomConfirm(
                'Suspend Seller',
                'Are you sure you want to suspend this seller? They will not be able to sell products temporarily.',
                'warning',
                () => {
                    window.location.href = this.href;
                }
            );
        };
    });
    
    // Handle ban actions
    const banLinks = document.querySelectorAll('a[href*="action=ban"]');
    banLinks.forEach(link => {
        link.onclick = function(e) {
            e.preventDefault();
            showCustomConfirm(
                'Ban Seller',
                'Are you sure you want to ban this seller? This is a severe action that will permanently prevent them from selling.',
                'danger',
                () => {
                    window.location.href = this.href;
                }
            );
        };
    });
    
    // Handle restore actions
    const restoreLinks = document.querySelectorAll('a[href*="action=restore"]');
    restoreLinks.forEach(link => {
        link.onclick = function(e) {
            e.preventDefault();
            showCustomConfirm(
                'Restore Seller',
                'Are you sure you want to restore this seller to approved status? They will be able to sell products again.',
                'success',
                () => {
                    window.location.href = this.href;
                }
            );
        };
    });
});
</script>
