<?php
require_once '../config/database.php';
require_once 'includes/admin_header.php';

requireAdmin();

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
                echo "<p class='success-message'>$message</p>";
            }
        } else {
            echo "<p class='error-message'>Seller not found.</p>";
        }
    }
}

// Get sellers with filter
$statusFilter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : 'all';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build query based on filters
$query = "SELECT * FROM users WHERE user_type = 'seller'";
$params = [];
$countParams = [];

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
if ($statusFilter !== 'all') {
    $countQuery .= " AND seller_status = ?";
}

$stmt = $pdo->prepare($countQuery);
$stmt->execute($countParams);
$totalSellers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalSellers / $limit);
?>

<h1>Manage Sellers</h1>

<div class="admin-filters">
    <h2>Filter by Status</h2>
    <div class="status-filters">
        <a href="admin-sellers.php?status=all" class="<?php echo $statusFilter === 'all' ? 'active' : ''; ?>">All</a>
        <a href="admin-sellers.php?status=pending" class="<?php echo $statusFilter === 'pending' ? 'active' : ''; ?>">Pending</a>
        <a href="admin-sellers.php?status=approved" class="<?php echo $statusFilter === 'approved' ? 'active' : ''; ?>">Approved</a>
        <a href="admin-sellers.php?status=rejected" class="<?php echo $statusFilter === 'rejected' ? 'active' : ''; ?>">Rejected</a>
        <a href="admin-sellers.php?status=suspended" class="<?php echo $statusFilter === 'suspended' ? 'active' : ''; ?>">Suspended</a>
        <a href="admin-sellers.php?status=banned" class="<?php echo $statusFilter === 'banned' ? 'active' : ''; ?>">Banned</a>
    </div>
</div>

<div class="admin-content">
    <h2>Sellers List (<?php echo $totalSellers; ?> total)</h2>
    
    <?php if (empty($sellers)): ?>
        <p>No sellers found.</p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Documents</th>
                    <th>Registered</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sellers as $seller): ?>
                    <tr>
                        <td><?php echo $seller['id']; ?></td>
                        <td><?php echo $seller['username']; ?></td>
                        <td><?php echo $seller['email']; ?></td>
                        <td>
                            <span class="status-badge status-<?php echo $seller['seller_status']; ?>">
                                <?php echo ucfirst($seller['seller_status']); ?>
                            </span>
                            <?php if ($seller['document_verified']): ?>
                                <br><small class="verified">Documents Verified</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($seller['document_path']): ?>
                                <a href="<?php echo $seller['document_path']; ?>" target="_blank">View Document</a>
                            <?php else: ?>
                                No document
                            <?php endif; ?>
                        </td>
                        <td><?php echo date('M j, Y', strtotime($seller['created_at'])); ?></td>
                        <td>
                            <div class="action-buttons">
                                <?php if ($seller['seller_status'] === 'pending'): ?>
                                    <a href="admin-sellers.php?action=approve&id=<?php echo $seller['id']; ?>" class="btn-approve">Approve</a>
                                    <a href="admin-sellers.php?action=reject&id=<?php echo $seller['id']; ?>" class="btn-reject">Reject</a>
                                <?php endif; ?>
                                
                                <?php if ($seller['seller_status'] === 'approved'): ?>
                                    <a href="admin-sellers.php?action=suspend&id=<?php echo $seller['id']; ?>" class="btn-suspend">Suspend</a>
                                <?php endif; ?>
                                
                                <?php if (in_array($seller['seller_status'], ['approved', 'suspended'])): ?>
                                    <a href="admin-sellers.php?action=ban&id=<?php echo $seller['id']; ?>" class="btn-ban">Ban</a>
                                <?php endif; ?>
                                
                                <?php if (in_array($seller['seller_status'], ['suspended', 'banned'])): ?>
                                    <a href="admin-sellers.php?action=restore&id=<?php echo $seller['id']; ?>" class="btn-restore" onclick="return confirm('Are you sure you want to restore this seller to approved status?');">Restore to Approved</a>
                                <?php endif; ?>
                                
                                <?php if ($seller['document_path'] && !$seller['document_verified']): ?>
                                    <a href="admin-sellers.php?action=verify_documents&id=<?php echo $seller['id']; ?>" class="btn-verify">Verify Docs</a>
                                <?php endif; ?>
                                
                                <?php if ($seller['document_verified']): ?>
                                    <a href="admin-sellers.php?action=unverify_documents&id=<?php echo $seller['id']; ?>" class="btn-unverify">Unverify Docs</a>
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
                    <a href="admin-sellers.php?status=<?php echo $statusFilter; ?>&page=<?php echo $page - 1; ?>">Previous</a>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="admin-sellers.php?status=<?php echo $statusFilter; ?>&page=<?php echo $i; ?>" 
                       class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="admin-sellers.php?status=<?php echo $statusFilter; ?>&page=<?php echo $page + 1; ?>">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<style>


/* Admin Sellers Page Styles */

/* Page Header */
h1 {
    color: #2c3e50;
    font-size: 2rem;
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 3px solid #3498db;
}

/* Filters Section */
.admin-filters {
    background: #fff;
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
}

.admin-filters h2 {
    color: #34495e;
    font-size: 1.2rem;
    margin-bottom: 1rem;
}

.status-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.status-filters a {
    padding: 0.5rem 1.25rem;
    background: #ecf0f1;
    color: #2c3e50;
    text-decoration: none;
    border-radius: 5px;
    transition: all 0.3s ease;
    font-weight: 500;
    border: 2px solid transparent;
}

.status-filters a:hover {
    background: #bdc3c7;
    transform: translateY(-2px);
}

.status-filters a.active {
    background: #3498db;
    color: white;
    border-color: #2980b9;
}

/* Content Section */
.admin-content {
    background: #fff;
    padding: 2rem;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.admin-content h2 {
    color: #34495e;
    font-size: 1.5rem;
    margin-bottom: 1.5rem;
}

/* Messages */
.success-message {
    background: #2ecc71;
    color: white;
    padding: 1rem;
    border-radius: 5px;
    margin-bottom: 1rem;
    font-weight: 500;
}

.error-message {
    background: #e74c3c;
    color: white;
    padding: 1rem;
    border-radius: 5px;
    margin-bottom: 1rem;
    font-weight: 500;
}

/* Table Styles */
.admin-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 1rem;
    background: white;
}

.admin-table thead {
    background: #34495e;
    color: white;
}

.admin-table th {
    padding: 1rem;
    text-align: left;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.85rem;
    letter-spacing: 0.5px;
}

.admin-table td {
    padding: 1rem;
    border-bottom: 1px solid #ecf0f1;
    vertical-align: middle;
}

.admin-table tbody tr {
    transition: background 0.2s ease;
}

.admin-table tbody tr:hover {
    background: #f8f9fa;
}

/* Status Badges */
.status-badge {
    display: inline-block;
    padding: 0.4rem 0.8rem;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-pending {
    background: #f39c12;
    color: white;
}

.status-approved {
    background: #2ecc71;
    color: white;
}

.status-rejected {
    background: #e74c3c;
    color: white;
}

.status-suspended {
    background: #e67e22;
    color: white;
}

.status-banned {
    background: #c0392b;
    color: white;
}

.verified {
    color: #27ae60;
    font-weight: 600;
    font-style: italic;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.action-buttons a,
.action-buttons button {
    padding: 0.5rem 1rem;
    border-radius: 5px;
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    white-space: nowrap;
}

.btn-approve {
    background: #2ecc71;
    color: white;
}

.btn-approve:hover {
    background: #27ae60;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(46, 204, 113, 0.3);
}

.btn-reject {
    background: #e74c3c;
    color: white;
}

.btn-reject:hover {
    background: #c0392b;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(231, 76, 60, 0.3);
}

.btn-suspend {
    background: #e67e22;
    color: white;
}

.btn-suspend:hover {
    background: #d35400;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(230, 126, 34, 0.3);
}

.btn-ban {
    background: #c0392b;
    color: white;
}

.btn-ban:hover {
    background: #a93226;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(192, 57, 43, 0.3);
}

.btn-restore {
    background: #3498db;
    color: white;
}

.btn-restore:hover {
    background: #2980b9;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(52, 152, 219, 0.3);
}

.btn-verify {
    background: #9b59b6;
    color: white;
}

.btn-verify:hover {
    background: #8e44ad;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(155, 89, 182, 0.3);
}

.btn-unverify {
    background: #95a5a6;
    color: white;
}

.btn-unverify:hover {
    background: #7f8c8d;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(149, 165, 166, 0.3);
}

/* Pagination */
.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 0.5rem;
    margin-top: 2rem;
    padding-top: 2rem;
    border-top: 2px solid #ecf0f1;
}

.pagination a {
    padding: 0.6rem 1rem;
    background: #ecf0f1;
    color: #2c3e50;
    text-decoration: none;
    border-radius: 5px;
    font-weight: 500;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.pagination a:hover {
    background: #bdc3c7;
    transform: translateY(-2px);
}

.pagination a.active {
    background: #3498db;
    color: white;
    border-color: #2980b9;
}

/* Document Links */
.admin-table a[href*="document"] {
    color: #3498db;
    text-decoration: none;
    font-weight: 500;
}

.admin-table a[href*="document"]:hover {
    color: #2980b9;
    text-decoration: underline;
}

/* Empty State */
.admin-content > p {
    text-align: center;
    color: #7f8c8d;
    font-size: 1.1rem;
    padding: 3rem;
}

/* Responsive Design */
@media (max-width: 1200px) {
    .admin-table {
        font-size: 0.9rem;
    }
    
    .admin-table th,
    .admin-table td {
        padding: 0.75rem;
    }
}

@media (max-width: 992px) {
    .action-buttons {
        flex-direction: column;
    }
    
    .action-buttons a,
    .action-buttons button {
        width: 100%;
        text-align: center;
    }
}

@media (max-width: 768px) {
    .admin-filters,
    .admin-content {
        padding: 1rem;
    }
    
    .status-filters {
        flex-direction: column;
    }
    
    .status-filters a {
        text-align: center;
    }
    
    .admin-table {
        display: block;
        overflow-x: auto;
        white-space: nowrap;
    }
    
    h1 {
        font-size: 1.5rem;
    }
}

@media (max-width: 576px) {
    .pagination {
        flex-wrap: wrap;
    }
    
    .pagination a {
        padding: 0.5rem 0.75rem;
        font-size: 0.85rem;
    }
}


</style>
<?php require_once '../includes/footer.php'; ?>