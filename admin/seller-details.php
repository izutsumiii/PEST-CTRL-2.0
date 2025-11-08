<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

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
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM products WHERE seller_id = ?");
    $stmt->execute([$sellerId]);
    $totalProducts = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM products WHERE seller_id = ? AND is_active = 1");
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
        margin: 0 auto;
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
        margin: 0 auto 10px;
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
        padding: 8px 16px;
        border-radius: 6px;
        font-size: 13px;
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
        <?php if (($seller['seller_status'] ?? 'pending') === 'pending'): ?>
            <a href="seller-details.php?action=approve&id=<?php echo (int)$sellerId; ?>" 
               class="action-btn btn-approve">
                <i class="fas fa-check-circle"></i> Approve
            </a>
            <a href="seller-details.php?action=reject&id=<?php echo (int)$sellerId; ?>" 
               class="action-btn btn-reject">
                <i class="fas fa-times-circle"></i> Reject
            </a>
        <?php endif; ?>
        
        <?php if (($seller['seller_status'] ?? '') === 'approved'): ?>
            <a href="#" onclick="openConfirmModal('suspend'); return false;" 
               class="action-btn btn-suspend">
                <i class="fas fa-pause-circle"></i> Suspend
            </a>
            <a href="#" onclick="openConfirmModal('ban'); return false;" 
               class="action-btn btn-ban">
                <i class="fas fa-ban"></i> Ban
            </a>
        <?php endif; ?>
        
        <?php if (in_array(($seller['seller_status'] ?? ''), ['suspended','banned','rejected'])): ?>
            <a href="seller-details.php?action=restore&id=<?php echo (int)$sellerId; ?>" 
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

    <!-- Recent Products Section -->
    <div class="products-section">
        <h2><i class="fas fa-shopping-bag"></i> Recent Products (<?php echo count($products); ?>)</h2>
        <?php if (empty($products)): ?>
            <p style="text-align: center; color: #6b7280; padding: 40px;">No products found.</p>
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

<!-- Confirmation Modals -->
<!-- Suspend Modal -->
<div id="suspendModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-header">
            <div class="modal-title">Confirm Suspension</div>
            <button class="modal-close" aria-label="Close" onclick="closeConfirmModal('suspend')">×</button>
        </div>
        <div class="modal-body">
            Are you sure you want to suspend this seller? They will not be able to sell products temporarily.
        </div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeConfirmModal('suspend')">Cancel</button>
            <a href="seller-details.php?action=suspend&id=<?php echo (int)$sellerId; ?>" class="btn-primary-y">Confirm</a>
        </div>
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
            Are you sure you want to ban this seller? This is a severe action that will permanently prevent them from selling.
        </div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeConfirmModal('ban')">Cancel</button>
            <a href="seller-details.php?action=ban&id=<?php echo (int)$sellerId; ?>" class="btn-danger">Ban</a>
        </div>
    </div>
</div>

<script>
function openConfirmModal(action) {
    const modal = document.getElementById(action + 'Modal');
    if (modal) {
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
    }
}

function closeConfirmModal(action) {
    const modal = document.getElementById(action + 'Modal');
    if (modal) {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
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
