<?php
require_once 'includes/header.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

// Get search query
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 24;
$offset = ($page - 1) * $perPage;

// Build query to search sellers (users with user_type = 'seller')
$whereClause = "u.user_type = 'seller'";
$params = [];

if (!empty($search)) {
    $whereClause .= " AND (u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
    $searchTerm = "%$search%";
    $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
}

// Count total sellers
$countSql = "SELECT COUNT(DISTINCT u.id) as cnt 
             FROM users u
             WHERE $whereClause";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalCount = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
$totalPages = max(1, (int)ceil($totalCount / $perPage));

// Fetch sellers with product counts
$sql = "SELECT 
            u.id,
            u.username,
            u.first_name,
            u.last_name,
            u.email,
            u.created_at,
            COUNT(DISTINCT p.id) as product_count,
            COUNT(DISTINCT CASE WHEN p.status = 'active' THEN p.id END) as active_products
        FROM users u
        LEFT JOIN products p ON u.id = p.seller_id
        WHERE $whereClause
        GROUP BY u.id, u.username, u.first_name, u.last_name, u.email, u.created_at
        ORDER BY u.created_at DESC
        LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sellers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    main {
        padding: 40px 20px;
        max-width: 1400px;
        margin: -30px auto 0 auto !important;
    }
    
    .page-header {
        margin-bottom: 30px;
    }
    
    .page-header h1 {
        color: #130325;
        font-size: 28px;
        font-weight: 700;
        margin: 0 0 10px 0;
    }
    
    .page-header p {
        color: #6b7280;
        font-size: 14px;
        margin: 0;
    }
    
    .search-info {
        margin-bottom: 24px;
        padding: 12px 16px;
        background: #f8f9fa;
        border-radius: 8px;
        border: 1px solid #e5e7eb;
    }
    
    .search-info strong {
        color: #130325;
    }
    
    .sellers-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 24px;
        margin-bottom: 40px;
    }
    
    .seller-card {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 24px;
        transition: all 0.3s ease;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .seller-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        transform: translateY(-2px);
    }
    
    .seller-header {
        display: flex;
        align-items: center;
        gap: 16px;
        margin-bottom: 16px;
    }
    
    .seller-avatar {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: linear-gradient(135deg, #FFD736 0%, #FFC107 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #130325;
        font-size: 24px;
        font-weight: 700;
        flex-shrink: 0;
    }
    
    .seller-info h3 {
        margin: 0 0 4px 0;
        color: #130325;
        font-size: 18px;
        font-weight: 600;
    }
    
    .seller-info p {
        margin: 0;
        color: #6b7280;
        font-size: 13px;
    }
    
    .seller-details {
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px solid #f0f0f0;
    }
    
    .seller-stat {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
        color: #130325;
        font-size: 14px;
    }
    
    .seller-stat:last-child {
        margin-bottom: 0;
    }
    
    .seller-stat i {
        color: #FFD736;
        width: 16px;
    }
    
    .seller-stat strong {
        color: #130325;
    }
    
    .view-seller-btn {
        margin-top: 16px;
        width: 100%;
        padding: 10px 16px;
        background: #FFD736;
        color: #130325;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.2s ease;
        text-decoration: none;
        display: block;
        text-align: center;
    }
    
    .view-seller-btn:hover {
        background: #f5d026;
        transform: translateY(-1px);
        box-shadow: 0 2px 8px rgba(255, 215, 54, 0.3);
    }
    
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #6b7280;
    }
    
    .empty-state i {
        font-size: 64px;
        color: #d1d5db;
        margin-bottom: 16px;
    }
    
    .empty-state h3 {
        color: #130325;
        font-size: 20px;
        margin: 0 0 8px 0;
    }
    
    .empty-state p {
        color: #6b7280;
        font-size: 14px;
        margin: 0;
    }
    
    .pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 8px;
        margin-top: 40px;
    }
    
    .pagination a,
    .pagination span {
        padding: 8px 12px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 14px;
        transition: all 0.2s ease;
    }
    
    .pagination a {
        background: #ffffff;
        color: #130325;
        border: 1px solid #e5e7eb;
    }
    
    .pagination a:hover {
        background: #FFD736;
        color: #130325;
        border-color: #FFD736;
    }
    
    .pagination .current {
        background: #FFD736;
        color: #130325;
        border: 1px solid #FFD736;
        font-weight: 600;
    }
    
    .pagination .disabled {
        opacity: 0.5;
        cursor: not-allowed;
        pointer-events: none;
    }
    
    @media (max-width: 768px) {
        .sellers-grid {
            grid-template-columns: 1fr;
        }
        
        main {
            padding: 20px 15px;
        }
    }
</style>

<main>
    <div class="page-header">
        <h1>Search Sellers</h1>
        <p>Find sellers and browse their products</p>
    </div>
    
    <?php if (!empty($search)): ?>
        <div class="search-info">
            <strong>Search results for:</strong> "<?php echo htmlspecialchars($search); ?>" 
            (<?php echo $totalCount; ?> <?php echo $totalCount == 1 ? 'seller' : 'sellers'; ?> found)
        </div>
    <?php endif; ?>
    
    <?php if (empty($sellers)): ?>
        <div class="empty-state">
            <i class="fas fa-store-slash"></i>
            <h3>No Sellers Found</h3>
            <p><?php echo !empty($search) ? 'Try a different search term.' : 'No sellers available at the moment.'; ?></p>
        </div>
    <?php else: ?>
        <div class="sellers-grid">
            <?php foreach ($sellers as $seller): 
                $sellerName = trim(($seller['first_name'] ?? '') . ' ' . ($seller['last_name'] ?? '')) ?: ($seller['username'] ?? 'Seller');
                $initials = strtoupper(substr($seller['username'] ?? 'S', 0, 1));
                $activeProducts = (int)($seller['active_products'] ?? 0);
                $totalProducts = (int)($seller['product_count'] ?? 0);
            ?>
                <div class="seller-card">
                    <div class="seller-header">
                        <div class="seller-avatar"><?php echo htmlspecialchars($initials); ?></div>
                        <div class="seller-info">
                            <h3><?php echo htmlspecialchars($sellerName); ?></h3>
                            <p>@<?php echo htmlspecialchars($seller['username'] ?? ''); ?></p>
                        </div>
                    </div>
                    
                    <div class="seller-details">
                        <div class="seller-stat">
                            <i class="fas fa-box"></i>
                            <span><strong><?php echo $activeProducts; ?></strong> active product<?php echo $activeProducts != 1 ? 's' : ''; ?></span>
                        </div>
                        <div class="seller-stat">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Joined <?php echo date('M Y', strtotime($seller['created_at'])); ?></span>
                        </div>
                    </div>
                    
                    <a href="seller.php?seller_id=<?php echo (int)$seller['id']; ?>" class="view-seller-btn">
                        <i class="fas fa-store"></i> View Seller Products
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?search=<?php echo urlencode($search); ?>&page=<?php echo $page - 1; ?>">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                <?php else: ?>
                    <span class="disabled"><i class="fas fa-chevron-left"></i> Previous</span>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?search=<?php echo urlencode($search); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?search=<?php echo urlencode($search); ?>&page=<?php echo $page + 1; ?>">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php else: ?>
                    <span class="disabled">Next <i class="fas fa-chevron-right"></i></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>

<?php include 'includes/footer.php'; ?>

