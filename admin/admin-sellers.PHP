<?php
require_once '../config/database.php';
require_once 'includes/admin_header.php';

requireAdmin();

$message = '';
$messageType = '';

$userId = $_SESSION['user_id'];

// Handle seller actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = sanitizeInput($_GET['action']);
    $sellerId = intval($_GET['id']);
    
    $validActions = ['approve', 'reject', 'suspend', 'ban', 'verify_documents', 'unverify_documents', 'restore'];
    
    if (in_array($action, $validActions)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND user_type = 'seller'");
        $stmt->execute([$sellerId]);
        $seller = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($seller) {
            switch ($action) {
                case 'approve':
                    $stmt = $pdo->prepare("UPDATE users SET seller_status = 'approved' WHERE id = ?");
                    $stmt->execute([$sellerId]);
                    $message = "Seller approved successfully!";
                    break;
                    
                case 'reject':
                    $stmt = $pdo->prepare("UPDATE users SET seller_status = 'rejected' WHERE id = ?");
                    $stmt->execute([$sellerId]);
                    $message = "Seller rejected successfully!";
                    break;
                    
                case 'suspend':
                    $stmt = $pdo->prepare("UPDATE users SET seller_status = 'suspended' WHERE id = ?");
                    $stmt->execute([$sellerId]);
                    $message = "Seller suspended successfully!";
                    break;
                    
                case 'ban':
                    $stmt = $pdo->prepare("UPDATE users SET seller_status = 'banned' WHERE id = ?");
                    $stmt->execute([$sellerId]);
                    $message = "Seller banned successfully!";
                    break;
                    
                case 'restore':
                    $stmt = $pdo->prepare("UPDATE users SET seller_status = 'approved' WHERE id = ?");
                    $stmt->execute([$sellerId]);
                    $message = "Seller restored to approved status successfully!";
                    break;
                    
                case 'verify_documents':
                    $stmt = $pdo->prepare("UPDATE users SET document_verified = TRUE WHERE id = ?");
                    $stmt->execute([$sellerId]);
                    $message = "Documents verified successfully!";
                    break;
                    
                case 'unverify_documents':
                    $stmt = $pdo->prepare("UPDATE users SET document_verified = FALSE WHERE id = ?");
                    $stmt->execute([$sellerId]);
                    $message = "Documents verification removed!";
                    break;
            }
            
            if (isset($message)) {
                $messageType = 'success';
            }
        } else {
            $message = 'Seller not found.';
            $messageType = 'error';
        }
    }
}

// Get sellers with filter
$statusFilter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : 'all';
$searchTerm = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build query based on filters
$query = "SELECT * FROM users WHERE user_type = 'seller'";
$params = [];
$countParams = [];

if (!empty($searchTerm)) {
    $query .= " AND (username LIKE ? OR email LIKE ? OR first_name LIKE ? OR last_name LIKE ?)";
    $like = "%{$searchTerm}%";
    array_push($params, $like, $like, $like, $like);
    array_push($countParams, $like, $like, $like, $like);
}

if ($statusFilter !== 'all') {
    $query .= " AND seller_status = ?";
    $params[] = $statusFilter;
    $countParams[] = $statusFilter;
}

$query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
// Add limit and offset as integers to parameters array
$params[] = $limit;
$params[] = $offset;

// Get sellers
$stmt = $pdo->prepare($query);

// Bind parameters with proper types
foreach ($params as $key => $value) {
    $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
    $stmt->bindValue($key + 1, $value, $paramType);
}

$stmt->execute();
$sellers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$countQuery = "SELECT COUNT(*) as total FROM users WHERE user_type = 'seller'";
if (!empty($searchTerm)) {
    $countQuery .= " AND (username LIKE ? OR email LIKE ? OR first_name LIKE ? OR last_name LIKE ?)";
}
if ($statusFilter !== 'all') {
    $countQuery .= " AND seller_status = ?";
}

$stmt = $pdo->prepare($countQuery);
$stmt->execute($countParams);
$totalSellers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalSellers / $limit);
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
        margin: 20px auto 0;
        padding: 14px 18px;
        border-radius: 10px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
        border: 2px solid transparent;
    }
    .alert-success { background: rgba(40,167,69,0.15); color: var(--success-green); border-color: var(--success-green); }
    .alert-error { background: rgba(220,53,69,0.15); color: var(--danger-red); border-color: var(--danger-red); }
    .alert-warning { background: rgba(255,193,7,0.15); color: var(--warning-yellow); border-color: var(--warning-yellow); }

    /* Custom Confirmation Dialog Styles (shared) */
    .custom-confirm-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.8); display: flex; align-items: center; justify-content: center; z-index: 10000; opacity: 0; visibility: hidden; transition: all 0.25s ease; }
    .custom-confirm-overlay.show { opacity: 1; visibility: visible; }
    .custom-confirm-dialog { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-dark) 100%); border: 2px solid var(--accent-yellow); border-radius: 12px; padding: 24px; max-width: 420px; width: 92%; box-shadow: 0 20px 40px rgba(0,0,0,0.5); transform: translateY(-8px) scale(0.98); transition: transform 0.25s ease; }
    .custom-confirm-overlay.show .custom-confirm-dialog { transform: translateY(0) scale(1); }
    .custom-confirm-header { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
    .custom-confirm-icon { width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 20px; }
    .custom-confirm-icon.warning { background: linear-gradient(135deg, var(--warning-yellow), #e6a800); color: #1a0a2e; }
    .custom-confirm-icon.danger { background: linear-gradient(135deg, var(--danger-red), #e74c3c); }
    .custom-confirm-title { font-size: 18px; font-weight: 800; color: var(--text-light); margin: 0; }
    .custom-confirm-message { color: rgba(249,249,249,0.85); font-size: 14px; line-height: 1.5; margin-bottom: 18px; }
    .custom-confirm-buttons { display: flex; gap: 10px; justify-content: flex-end; }
    .custom-confirm-btn { padding: 10px 16px; border-radius: 8px; font-size: 12px; font-weight: 800; border: 2px solid transparent; cursor: pointer; transition: all 0.2s ease; text-transform: uppercase; letter-spacing: 0.5px; }
    .custom-confirm-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.3); }
    .custom-confirm-btn.cancel { background: rgba(108,117,125,0.15); color: #adb5bd; border-color: #6c757d; }
    .custom-confirm-btn.cancel:hover { background: #6c757d; color: #fff; }
    .custom-confirm-btn.warning { background: linear-gradient(135deg, var(--warning-yellow), #e6a800); color: #1a0a2e; border-color: var(--warning-yellow); }
    .custom-confirm-btn.confirm { background: linear-gradient(135deg, var(--danger-red), #e74c3c); color: #fff; border-color: var(--danger-red); }

    /* Page Header */
    h1 {
        max-width: 1400px;
        margin: 10px auto 30px;
        padding: 0 20px;
        font-size: 28px;
        font-weight: 800;
        color: var(--text-light);
    }
    .container > h1 { padding: 0; margin: 0 0 10px 0; }
    .page-heading-title { font-size: 20px; font-weight: 800; color: var(--text-light); margin: 0; }

    /* Main Container */
    .container {
        max-width: 1400px;
        margin: 0 auto 30px;
        padding: 0 20px;
    }

    /* Filter Section */
    .filter-section {
        background: var(--primary-dark);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
    }

    .status-filters { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: center; }
    .seller-filter-form { display: flex; align-items: center; gap: 10px; flex-wrap: nowrap; justify-content: center; }
    .seller-filter-form .filter-select { min-width: 160px; padding: 12px 16px; background: #0f0a1f; border: 2px solid var(--accent-yellow); border-radius: 8px; color: #F9F9F9; font-size: 14px; height: 42px; }
    .seller-filter-form .search-input { flex: 1; min-width: 260px; padding: 12px 16px; background: #0f0a1f; border: 1px solid #3a2b55; border-radius: 8px; color: #F9F9F9; font-size: 14px; height: 42px; transition: all 0.25s ease; }
    .seller-filter-form .search-input:focus { border-color: var(--accent-yellow); box-shadow: 0 0 0 3px rgba(255, 215, 54, 0.12); outline: none; }
    .seller-filter-form .btn-search { background: linear-gradient(135deg, var(--accent-yellow), var(--accent-yellow)); color: #1a0a2e; border: 2px solid var(--accent-yellow); height: 40px; width: 40px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; }

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
    }

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

    .status-pending {
        background: rgba(255, 193, 7, 0.2);
        color: var(--warning-yellow);
        border: 1px solid var(--warning-yellow);
    }

    .status-approved {
        background: rgba(40, 167, 69, 0.2);
        color: var(--success-green);
        border: 1px solid var(--success-green);
    }

    .status-rejected, .status-banned {
        background: rgba(220, 53, 69, 0.2);
        color: var(--danger-red);
        border: 1px solid var(--danger-red);
    }

    .status-suspended {
        background: rgba(108, 117, 125, 0.2);
        color: #6c757d;
        border: 1px solid #6c757d;
    }

    .verified {
        color: var(--success-green);
        font-size: 11px;
        font-weight: 600;
        margin-top: 4px;
        display: block;
    }

    /* Action Buttons */
    .action-buttons {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
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
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    }

    .btn-view { background: linear-gradient(135deg, #3498db, #5dade2); color: white; }

    .btn-approve { background: linear-gradient(135deg, var(--success-green), #20c997); color: white; }

    .btn-reject { background: linear-gradient(135deg, var(--danger-red), #e74c3c); color: white; }

    .btn-suspend { background: linear-gradient(135deg, #6c757d, #95a5a6); color: white; }

    .btn-ban { background: linear-gradient(135deg, #c0392b, #e74c3c); color: white; }

    .btn-restore { background: linear-gradient(135deg, #3498db, #5dade2); color: white; }

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

    /* Responsive */
    @media (max-width: 768px) {
        .status-filters {
            flex-direction: column;
        }
        
        .action-buttons {
            flex-direction: column;
        }
        
        .btn {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<script>
// Shared custom confirmation used for restore action
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
    confirmBtn.textContent = 'Confirm';
    header.appendChild(icon); header.appendChild(titleEl);
    dialog.appendChild(header); dialog.appendChild(msgEl);
    btns.appendChild(cancelBtn); btns.appendChild(confirmBtn);
    dialog.appendChild(btns); overlay.appendChild(dialog);
    document.body.appendChild(overlay);
    requestAnimationFrame(() => overlay.classList.add('show'));
    function close() { overlay.classList.remove('show'); setTimeout(() => overlay.remove(), 250); }
    cancelBtn.addEventListener('click', close);
    overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
    confirmBtn.addEventListener('click', () => { close(); if (typeof onConfirm === 'function') onConfirm(); });
}
</script>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $messageType; ?>" id="page-alert">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : ($messageType === 'warning' ? 'exclamation-triangle' : 'times-circle'); ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div class="container">
    <h1 style="padding:0; margin: 0 0 10px 0;"><span class="page-heading-title">Manage Sellers</span></h1>
    <!-- Filter Section -->
    <div class="filter-section">
        <div class="status-filters">
            <form method="GET" action="admin-sellers.php" class="seller-filter-form">
                <select name="status" class="filter-select" title="Filter by status" onchange="this.form.submit()">
                    <option value="all" <?php echo $statusFilter==='all'?'selected':''; ?>>All</option>
                    <option value="pending" <?php echo $statusFilter==='pending'?'selected':''; ?>>Pending</option>
                    <option value="approved" <?php echo $statusFilter==='approved'?'selected':''; ?>>Approved</option>
                    <option value="rejected" <?php echo $statusFilter==='rejected'?'selected':''; ?>>Rejected</option>
                    <option value="suspended" <?php echo $statusFilter==='suspended'?'selected':''; ?>>Suspended</option>
                    <option value="banned" <?php echo $statusFilter==='banned'?'selected':''; ?>>Banned</option>
                </select>
                <input type="text" name="search" placeholder="Search sellers..." value="<?php echo htmlspecialchars($searchTerm ?? ''); ?>" class="search-input" />
                <button type="submit" class="btn btn-search" aria-label="Search"><i class="fas fa-search" aria-hidden="true"></i></button>
            </form>
        </div>
    </div>

    <div class="page-title">
        Sellers List 
        <span style="color: var(--text-light); font-size: 18px; font-weight: 500;">
            (<?php echo $totalSellers; ?> total)
        </span>
    </div>
    
    <?php if (empty($sellers)): ?>
        <div style="text-align: center; padding: 40px; color: rgba(249, 249, 249, 0.6);">
            <i class="fas fa-store" style="font-size: 48px; display: block; margin-bottom: 16px; opacity: 0.5;"></i>
            <p>No sellers found.</p>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Registered</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sellers as $seller): ?>
                        <tr>
                            <td><strong>#<?php echo $seller['id']; ?></strong></td>
                            <td><?php echo htmlspecialchars($seller['username']); ?></td>
                            <td><?php echo htmlspecialchars($seller['email']); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $seller['seller_status']; ?>">
                                    <i class="fas fa-<?php 
                                        switch($seller['seller_status']) {
                                            case 'pending': echo 'clock'; break;
                                            case 'approved': echo 'check-circle'; break;
                                            case 'rejected': echo 'times-circle'; break;
                                            case 'suspended': echo 'pause-circle'; break;
                                            case 'banned': echo 'ban'; break;
                                            default: echo 'question-circle';
                                        }
                                    ?>"></i>
                                    <?php echo ucfirst($seller['seller_status']); ?>
                                </span>
                                <?php if ($seller['document_verified']): ?>
                                    <br><small class="verified"><i class="fas fa-check-circle"></i> Documents Verified</small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('M j, Y', strtotime($seller['created_at'])); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <a href="seller-details.php?id=<?php echo $seller['id']; ?>" class="btn btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <?php if ($seller['seller_status'] === 'pending'): ?>
                                        <a href="admin-sellers.php?action=approve&id=<?php echo $seller['id']; ?>" class="btn btn-approve">
                                            <i class="fas fa-check"></i> Approve
                                        </a>
                                        <a href="admin-sellers.php?action=reject&id=<?php echo $seller['id']; ?>" class="btn btn-reject">
                                            <i class="fas fa-times"></i> Reject
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($seller['seller_status'] === 'approved'): ?>
                                        <a href="admin-sellers.php?action=suspend&id=<?php echo $seller['id']; ?>" class="btn btn-suspend">
                                            <i class="fas fa-pause"></i> Suspend
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if (in_array($seller['seller_status'], ['approved', 'suspended'])): ?>
                                        <a href="admin-sellers.php?action=ban&id=<?php echo $seller['id']; ?>" class="btn btn-ban">
                                            <i class="fas fa-ban"></i> Ban
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if (in_array($seller['seller_status'], ['suspended', 'banned'])): ?>
                                        <a href="admin-sellers.php?action=restore&id=<?php echo $seller['id']; ?>" class="btn btn-restore">
                                            <i class="fas fa-undo"></i> Restore
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="admin-sellers.php?status=<?php echo $statusFilter; ?>&search=<?php echo urlencode($searchTerm); ?>&page=<?php echo $page - 1; ?>" class="page-link">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="admin-sellers.php?status=<?php echo $statusFilter; ?>&search=<?php echo urlencode($searchTerm); ?>&page=<?php echo $i; ?>" 
                       class="page-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="admin-sellers.php?status=<?php echo $statusFilter; ?>&search=<?php echo urlencode($searchTerm); ?>&page=<?php echo $page + 1; ?>" class="page-link">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            
            <div class="pagination-info">
                Showing <?php echo (($page - 1) * $limit + 1); ?>-<?php echo min($page * $limit, $totalSellers); ?> 
                of <?php echo $totalSellers; ?> sellers
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const alertEl = document.getElementById('page-alert');
    if (alertEl) {
        setTimeout(() => {
            alertEl.style.transition = 'opacity 250ms ease, transform 250ms ease';
            alertEl.style.opacity = '0';
            alertEl.style.transform = 'translateY(-6px)';
            setTimeout(() => alertEl.remove(), 260);
        }, 1200);
    }

    // Wire restore confirmation
    document.querySelectorAll('a[href*="action=restore"]').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            showCustomConfirm(
                'Restore Seller',
                'Are you sure you want to restore this seller to approved status?',
                'warning',
                () => { window.location.href = link.href; }
            );
        });
    });

    // Wire suspend confirmation
    document.querySelectorAll('a[href*="action=suspend"]').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            showCustomConfirm(
                'Suspend Seller',
                'Suspend this seller account? They will not be able to list or sell items until restored.',
                'warning',
                () => { window.location.href = link.href; }
            );
        });
    });

    // Wire ban confirmation
    document.querySelectorAll('a[href*="action=ban"]').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            showCustomConfirm(
                'Ban Seller',
                'Are you sure you want to permanently ban this seller? This action is intended for severe violations.',
                'danger',
                () => { window.location.href = link.href; }
            );
        });
    });
});
</script>
