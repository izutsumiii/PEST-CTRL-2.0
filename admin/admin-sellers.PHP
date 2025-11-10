<?php
require_once '../config/database.php';
require_once 'includes/admin_header.php';

requireAdmin();

$message = '';
$messageType = '';

$userId = $_SESSION['user_id'];

// Handle seller actions
if (isset($_GET['action'])) {
    $action = sanitizeInput($_GET['action']);
    $validActions = ['approve', 'reject', 'suspend', 'ban', 'verify_documents', 'unverify_documents', 'restore', 'fix_id'];
    
    if (in_array($action, $validActions)) {
        // Handle fix_id separately (uses username)
        if ($action === 'fix_id' && isset($_GET['username'])) {
            $username = sanitizeInput($_GET['username']);
            try {
                // Get the seller by username
                $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND (user_type = 'seller' OR user_type IS NULL OR user_type = '')");
                $stmt->execute([$username]);
                $seller = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($seller && $seller['id'] == 0) {
                    // Get next auto-increment value
                    $stmt = $pdo->query("SHOW TABLE STATUS LIKE 'users'");
                    $tableStatus = $stmt->fetch(PDO::FETCH_ASSOC);
                    $nextId = $tableStatus['Auto_increment'] ?? 1;
                    
                    // Temporarily disable foreign key checks
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                    
                    // Update the seller's ID
                    $pdo->exec("UPDATE users SET id = $nextId WHERE username = '$username' AND id = 0");
                    
                    // Update related foreign keys
                    $pdo->exec("UPDATE products SET seller_id = $nextId WHERE seller_id = 0");
                    $pdo->exec("UPDATE orders SET seller_id = $nextId WHERE seller_id = 0");
                    
                    // Re-enable foreign key checks
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                    
                    $message = "Seller ID fixed successfully! New ID: #$nextId";
                    $messageType = 'success';
                } else {
                    $message = 'Seller not found or ID is not 0.';
                    $messageType = 'error';
                }
            } catch (Exception $e) {
                $message = 'Error fixing seller ID: ' . $e->getMessage();
                $messageType = 'error';
            }
        } elseif (isset($_GET['id']) || isset($_GET['username'])) {
            $sellerId = isset($_GET['id']) ? intval($_GET['id']) : 0;
            $sellerUsername = isset($_GET['username']) ? sanitizeInput($_GET['username']) : null;
            
            // Find seller by ID or username (for ID 0 sellers)
            if ($sellerId > 0) {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND (user_type = 'seller' OR user_type IS NULL OR user_type = '')");
                $stmt->execute([$sellerId]);
            } elseif ($sellerUsername) {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND (user_type = 'seller' OR user_type IS NULL OR user_type = '')");
                $stmt->execute([$sellerUsername]);
            } else {
                $seller = null;
            }
            
            if (isset($stmt)) {
                $seller = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            if ($seller) {
                $updateId = $seller['id'] > 0 ? $seller['id'] : $seller['username'];
                $whereClause = $seller['id'] > 0 ? "id = ?" : "username = ?";
                $whereParam = $seller['id'] > 0 ? $seller['id'] : $seller['username'];
                
                switch ($action) {
                    case 'approve':
                        $stmt = $pdo->prepare("UPDATE users SET seller_status = 'approved', user_type = 'seller' WHERE $whereClause");
                        $stmt->execute([$whereParam]);
                        $message = "Seller approved successfully!";
                        break;
                        
                    case 'reject':
                        $stmt = $pdo->prepare("UPDATE users SET seller_status = 'rejected', user_type = 'seller' WHERE $whereClause");
                        $stmt->execute([$whereParam]);
                        $message = "Seller rejected successfully!";
                        break;
                        
                    case 'suspend':
                        $stmt = $pdo->prepare("UPDATE users SET seller_status = 'suspended', user_type = 'seller' WHERE $whereClause");
                        $stmt->execute([$whereParam]);
                        $message = "Seller suspended successfully!";
                        break;
                        
                    case 'ban':
                        $stmt = $pdo->prepare("UPDATE users SET seller_status = 'banned', user_type = 'seller' WHERE $whereClause");
                        $stmt->execute([$whereParam]);
                        $message = "Seller banned successfully!";
                        break;
                        
                    case 'restore':
                        $stmt = $pdo->prepare("UPDATE users SET seller_status = 'approved', user_type = 'seller' WHERE $whereClause");
                        $stmt->execute([$whereParam]);
                        $message = "Seller restored to approved status successfully!";
                        break;
                        
                    case 'verify_documents':
                        $stmt = $pdo->prepare("UPDATE users SET document_verified = TRUE, user_type = 'seller' WHERE $whereClause");
                        $stmt->execute([$whereParam]);
                        $message = "Documents verified successfully!";
                        break;
                        
                    case 'unverify_documents':
                        $stmt = $pdo->prepare("UPDATE users SET document_verified = FALSE, user_type = 'seller' WHERE $whereClause");
                        $stmt->execute([$whereParam]);
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
}

// Get sellers with filter
$statusFilter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : 'all';
$searchTerm = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build query based on filters - include manually added sellers (without user_type='seller')
$query = "SELECT * FROM users WHERE (user_type = 'seller' OR user_type IS NULL OR user_type = '')";
$params = [];
$countParams = [];

if (!empty($searchTerm)) {
    $query .= " AND (username LIKE ? OR email LIKE ? OR first_name LIKE ? OR last_name LIKE ?)";
    $like = "%{$searchTerm}%";
    array_push($params, $like, $like, $like, $like);
    array_push($countParams, $like, $like, $like, $like);
}

if ($statusFilter !== 'all') {
    $query .= " AND (seller_status = ? OR (seller_status IS NULL AND ? = 'pending'))";
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

// Get total count for pagination - include manually added sellers
$countQuery = "SELECT COUNT(*) as total FROM users WHERE (user_type = 'seller' OR user_type IS NULL OR user_type = '')";
if (!empty($searchTerm)) {
    $countQuery .= " AND (username LIKE ? OR email LIKE ? OR first_name LIKE ? OR last_name LIKE ?)";
}
if ($statusFilter !== 'all') {
    $countQuery .= " AND (seller_status = ? OR (seller_status IS NULL AND ? = 'pending'))";
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
        background: #f0f2f5 !important;
        color: #130325 !important;
        min-height: 100vh;
        margin: 0;
        font-family: 'Inter', 'Segoe UI', sans-serif;
    }

    /* Message Alerts */
    .alert { max-width: 1400px; margin: 20px auto 0; padding: 14px 18px; border-radius: 10px; font-weight: 700; display: flex; align-items: center; gap: 10px; border: 2px solid transparent; }
    .alert-success { background: #f0fdf4; color: #166534; border-color: #86efac; }
    .alert-error { background: #fef2f2; color: #991b1b; border-color: #fca5a5; }
    .alert-warning { background: #fffbeb; color: #92400e; border-color: #fde68a; }

    /* Removed legacy custom confirm styles; using unified logout-style confirm */

    /* Page Header */
    h1 {
        max-width: 1400px;
        margin: 10px auto 30px;
        padding: 0 20px;
        font-size: 28px;
        font-weight: 800;
        color: var(--text-light);
        text-shadow: none !important;
    }
    .container > h1 { 
        padding: 0; 
        margin: 0 0 10px 0; 
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        text-shadow: none !important;
    }
    .page-heading-title { font-size: 20px; font-weight: 800; color: #130325; margin: 0; text-shadow: none !important; }
    
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
        margin-left: 0;
        border: 1px solid #130325;
        vertical-align: middle;
    }

    /* Main Container */
    .container {
        max-width: 1400px;
        margin: -30px auto 30px;
        padding: 5px 20px 0;
    }

    /* Filter Section - same width as table container */
    .filter-section { 
        background: #ffffff; 
        border: 1px solid rgba(0,0,0,0.1); 
        border-radius: 8px; 
        padding: 16px 24px; 
        margin-bottom: 16px; 
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        width: 100%;
        box-sizing: border-box;
    }

    .status-filters { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: center; }
    .seller-filter-form { display: flex; align-items: center; gap: 12px; flex-wrap: nowrap; justify-content: center; }
    .seller-filter-form .filter-select { min-width: 160px; padding: 10px 14px; background: #ffffff; border: 2px solid #e5e7eb; border-radius: 8px; color: #130325; font-size: 14px; height: 40px; }
    .seller-filter-form .search-input { flex: 1; min-width: 260px; padding: 10px 14px; background: #ffffff; border: 2px solid #e5e7eb; border-radius: 8px; color: #130325; font-size: 14px; height: 40px; transition: all 0.2s ease; }
    .seller-filter-form .search-input:focus { border-color: #FFD736; box-shadow: 0 0 0 3px rgba(255,215,54,0.12); outline: none; }
    .seller-filter-form .btn-search { 
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
        flex-shrink: 0;
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

    /* Table Container - exact match from manage-products.php */
    .table-container {
        background: #ffffff;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 8px;
        padding: 24px;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        width: 100%;
        box-sizing: border-box;
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
            min-width: 85px;
            max-width: 85px;
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
        min-width: 90px;
        max-width: 90px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
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

    /* Action Buttons - no borders */
    .action-buttons, .table-actions {
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

    .btn-approve {
        background: #28a745;
        color: #ffffff;
    }

    .btn-approve:hover {
        background: #218838;
        transform: scale(1.1);
    }

    .btn-reject {
        background: #dc3545;
        color: #ffffff;
    }

    .btn-reject:hover {
        background: #c82333;
        transform: scale(1.1);
    }

    .btn-suspend {
        background: #6c757d;
        color: #ffffff;
    }

    .btn-suspend:hover {
        background: #5a6268;
        transform: scale(1.1);
    }

    .btn-ban {
        background: #c0392b;
        color: #ffffff;
    }

    .btn-ban:hover {
        background: #a93226;
        transform: scale(1.1);
    }

    .btn-restore {
        background: #3498db;
        color: #ffffff;
    }

    .btn-restore:hover {
        background: #2980b9;
        transform: scale(1.1);
    }

    .status-btn {
        background: #FFD736;
        color: #130325;
    }

    .status-btn:hover {
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

    /* Responsive */
    @media (max-width: 768px) {
        .status-filters {
            flex-direction: column;
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
</style>

<script>
// Uniform confirm dialog for admin seller actions
function adminConfirmSeller(message, label){
  return new Promise(function(resolve){
    const overlay = document.createElement('div');
    overlay.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:10000;opacity:0;transition:opacity .2s ease';
    const dialog=document.createElement('div');
    dialog.style.cssText='background:#ffffff;border-radius:12px;overflow:hidden;min-width:320px;max-width:420px;box-shadow:0 10px 40px rgba(0,0,0,0.2)';
    const header=document.createElement('div'); header.style.cssText='background:#130325;color:#ffffff;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;'; header.innerHTML='<h3 style="margin:0;font-size:14px;font-weight:700;color:#ffffff;">Confirm Action</h3>';
    const body=document.createElement('div'); body.style.cssText='padding:20px;color:#130325;font-size:13px;'; body.textContent=message;
    const footer=document.createElement('div'); footer.style.cssText='padding:12px 16px;display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #e5e7eb;';
    const cancelBtn=document.createElement('button'); cancelBtn.textContent='Cancel'; cancelBtn.style.cssText='padding:8px 16px;background:#f3f4f6;color:#130325;border:1px solid #e5e7eb;border-radius:6px;font-weight:600;cursor:pointer;';
    const okBtn=document.createElement('button'); okBtn.textContent=label||'Confirm'; okBtn.style.cssText='padding:8px 16px;background:#130325;color:#ffffff;border:none;border-radius:6px;font-weight:600;cursor:pointer;';
    footer.appendChild(cancelBtn); footer.appendChild(okBtn); dialog.appendChild(header); dialog.appendChild(body); dialog.appendChild(footer); overlay.appendChild(dialog); document.body.appendChild(overlay);
    requestAnimationFrame(()=> overlay.style.opacity='1');
    function close(res){ overlay.style.opacity='0'; setTimeout(()=>overlay.remove(),150); resolve(res); }
    overlay.addEventListener('click',(e)=>{ if(e.target===overlay) close(false); }); cancelBtn.addEventListener('click',()=>close(false)); okBtn.addEventListener('click',()=>close(true));
  });
}

document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('a[href*="admin-sellers.PHP?action="], a[href*="admin-sellers.php?action="]').forEach(function(link){
    link.addEventListener('click', function(e){
      e.preventDefault();
      const url = new URL(link.href, window.location.origin);
      const action = (url.searchParams.get('action')||'').toLowerCase();
      let msg='Proceed with this action?'; let label='Confirm';
      if (action==='approve') { msg='Approve this seller?'; label='Approve'; }
      else if (action==='reject') { msg='Reject this seller?'; label='Reject'; }
      else if (action==='suspend') { msg='Suspend this seller?'; label='Suspend'; }
      else if (action==='ban') { msg='Ban this seller?'; label='Ban'; }
      else if (action==='restore') { msg='Restore this seller to approved?'; label='Restore'; }
      else if (action==='verify_documents') { msg='Verify this seller\'s documents?'; label='Verify'; }
      else if (action==='unverify_documents') { msg='Remove document verification for this seller?'; label='Unverify'; }
      adminConfirmSeller(msg, label).then((ok)=>{ if (ok) window.location.href = link.href; });
    });
  });
});
</script>

<!-- Removed legacy showCustomConfirm; unified confirm is used above -->

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $messageType; ?>" id="page-alert">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : ($messageType === 'warning' ? 'exclamation-triangle' : 'times-circle'); ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div class="container">
    <h1 style="padding:0; margin: 0 0 10px 0;">
        <span class="page-heading-title">Manage Sellers</span>
        <span class="total-count-badge">
            <?php echo $totalSellers; ?> total
        </span>
    </h1>
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
    
    <?php if (empty($sellers)): ?>
        <div style="text-align: center; padding: 40px; color: rgba(249, 249, 249, 0.6);">
            <i class="fas fa-store" style="font-size: 48px; display: block; margin-bottom: 16px; opacity: 0.5;"></i>
            <p>No sellers found.</p>
        </div>
    <?php else: ?>
        <div class="table-container">
            <div class="table-wrapper">
                <table class="admin-table" id="sellersTable">
                <thead>
                    <tr>
                        <th class="sortable">ID <span class="sort-indicator"></span></th>
                        <th class="sortable">Username <span class="sort-indicator"></span></th>
                        <th class="sortable">Email <span class="sort-indicator"></span></th>
                        <th class="sortable">Status <span class="sort-indicator"></span></th>
                        <th class="sortable">Registered <span class="sort-indicator"></span></th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sellers as $seller): 
                        $sellerId = (int)$seller['id'];
                        $hasInvalidId = $sellerId === 0;
                        $sellerStatus = $seller['seller_status'] ?? 'pending';
                    ?>
                        <tr>
                            <td>
                                <strong>#<?php echo $sellerId; ?></strong>
                                <?php if ($hasInvalidId): ?>
                                    <span style="color: #dc2626; margin-left: 8px;" title="Invalid ID - This seller needs to be fixed">⚠️ Invalid ID</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($seller['username']); ?></td>
                            <td><?php echo htmlspecialchars($seller['email']); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $sellerStatus; ?>">
                                    <i class="fas fa-<?php 
                                        switch($sellerStatus) {
                                            case 'pending': echo 'clock'; break;
                                            case 'approved': echo 'check-circle'; break;
                                            case 'rejected': echo 'times-circle'; break;
                                            case 'suspended': echo 'pause-circle'; break;
                                            case 'banned': echo 'ban'; break;
                                            default: echo 'question-circle';
                                        }
                                    ?>"></i>
                                    <?php echo ucfirst($sellerStatus); ?>
                                </span>
                                <?php if ($seller['document_verified'] ?? false): ?>
                                    <br><small class="verified"><i class="fas fa-check-circle"></i> Documents Verified</small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('M j, Y', strtotime($seller['created_at'])); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <a href="seller-details.php?<?php echo $hasInvalidId ? 'username=' . urlencode($seller['username']) : 'id=' . $sellerId; ?>" class="action-btn btn-view" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if ($hasInvalidId): ?>
                                        <a href="admin-sellers.php?action=fix_id&username=<?php echo urlencode($seller['username']); ?>" 
                                           class="action-btn" 
                                           style="background: #dc2626; color: #ffffff;"
                                           title="Fix ID"
                                           onclick="return confirm('This will assign a proper ID to this seller. Continue?');">
                                            <i class="fas fa-wrench"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($sellerStatus === 'pending'): ?>
                                        <a href="admin-sellers.php?action=approve&<?php echo $hasInvalidId ? 'username=' . urlencode($seller['username']) : 'id=' . $sellerId; ?>" class="action-btn btn-approve" title="Approve">
                                            <i class="fas fa-check"></i>
                                        </a>
                                        <a href="admin-sellers.php?action=reject&<?php echo $hasInvalidId ? 'username=' . urlencode($seller['username']) : 'id=' . $sellerId; ?>" class="action-btn btn-reject" title="Reject">
                                            <i class="fas fa-times"></i>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($sellerStatus === 'approved'): ?>
                                        <a href="admin-sellers.php?action=suspend&<?php echo $hasInvalidId ? 'username=' . urlencode($seller['username']) : 'id=' . $sellerId; ?>" class="action-btn btn-suspend" title="Suspend">
                                            <i class="fas fa-pause"></i>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if (in_array($sellerStatus, ['approved', 'suspended'])): ?>
                                        <a href="admin-sellers.php?action=ban&<?php echo $hasInvalidId ? 'username=' . urlencode($seller['username']) : 'id=' . $sellerId; ?>" class="action-btn btn-ban" title="Ban">
                                            <i class="fas fa-ban"></i>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if (in_array($sellerStatus, ['suspended', 'banned'])): ?>
                                        <a href="admin-sellers.php?action=restore&<?php echo $hasInvalidId ? 'username=' . urlencode($seller['username']) : 'id=' . $sellerId; ?>" class="action-btn btn-restore" title="Restore">
                                            <i class="fas fa-undo"></i>
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

    // Removed legacy showCustomConfirm wiring; unified confirm handler already attached

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
    const table = document.getElementById('sellersTable');
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
