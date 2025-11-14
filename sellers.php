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
    $whereClause .= " AND (u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.display_name LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
    $searchTerm = "%$search%";
    $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm];
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
            u.display_name,
            u.email,
            u.created_at,
            COUNT(DISTINCT p.id) as product_count,
            COUNT(DISTINCT CASE WHEN p.status = 'active' THEN p.id END) as active_products
        FROM users u
        LEFT JOIN products p ON u.id = p.seller_id
        WHERE $whereClause
        GROUP BY u.id, u.username, u.first_name, u.last_name, u.display_name, u.email, u.created_at
        ORDER BY u.created_at DESC
        LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sellers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
:root {
    --primary-dark: #130325;
    --accent-yellow: #FFD736;
    --text-dark: #1a1a1a;
    --text-light: #6b7280;
    --border-light: #e5e7eb;
    --bg-light: #f9fafb;
    --bg-white: #ffffff;
    --success-green: #10b981;
    --error-red: #ef4444;
}

body {
    background: var(--bg-light);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}

main {
    padding: 12px 20px;
        max-width: 1600px;
        margin: 0 auto !important;
}

.page-header {
    margin-bottom: 20px;
}

.page-header h1 {
    color: var(--text-dark);
    font-size: 1.35rem;
    font-weight: 600;
    margin: 0 0 8px 0;
    letter-spacing: -0.3px;
    line-height: 1.2;
}

.page-header p {
    color: var(--text-light);
    font-size: 13px;
    margin: 0;
}
    
.search-info {
    margin-bottom: 16px;
    padding: 10px 14px;
    background: var(--bg-white);
    border-radius: 8px;
    border: 1px solid var(--border-light);
    font-size: 12px;
}

.search-info strong {
    color: var(--text-dark);
    font-weight: 600;
}

.sellers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.seller-card {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    padding: 14px 16px;
    transition: all 0.2s ease;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.seller-card:hover {
    box-shadow: 0 4px 12px rgba(19, 3, 37, 0.15);
    transform: translateY(-2px);
    border-color: var(--primary-dark);
}

.seller-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.seller-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: var(--primary-dark);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--bg-white);
    font-size: 18px;
    font-weight: 600;
    flex-shrink: 0;
}

.seller-info h3 {
    margin: 0 0 4px 0;
    color: var(--text-dark);
    font-size: 15px;
    font-weight: 600;
}

.seller-info p {
    margin: 0;
    color: var(--text-light);
    font-size: 12px;
}

.seller-details {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--border-light);
}

.seller-stat {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;
    color: var(--text-dark);
    font-size: 12px;
}

.seller-stat:last-child {
    margin-bottom: 0;
}

.seller-stat i {
    color: var(--primary-dark);
    width: 14px;
    font-size: 12px;
}

.seller-stat strong {
    color: var(--text-dark);
    font-weight: 600;
}

.seller-info h3 a {
    color: var(--text-dark);
    text-decoration: none;
    transition: color 0.2s ease;
}

.seller-info h3 a:hover {
    color: var(--primary-dark);
}

.visit-seller-btn {
    margin-top: 12px;
    padding: 8px 14px;
    background: var(--primary-dark);
    color: var(--bg-white);
    border: 1px solid var(--primary-dark);
    border-radius: 8px;
    font-weight: 600;
    font-size: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    display: inline-block;
    text-align: center;
    width: 100%;
}

.visit-seller-btn:hover {
    background: #0a0118;
    border-color: #0a0118;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(19, 3, 37, 0.3);
    color: var(--bg-white);
}
    
.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-light);
}

.empty-state i {
    font-size: 48px;
    color: var(--border-light);
    margin-bottom: 12px;
}

.empty-state h3 {
    color: var(--text-dark);
    font-size: 1.1rem;
    margin: 0 0 8px 0;
    font-weight: 600;
}

.empty-state p {
    color: var(--text-light);
    font-size: 13px;
    margin: 0;
}

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 6px;
    margin-top: 24px;
    flex-wrap: wrap;
}

.pagination a,
.pagination span {
    padding: 6px 12px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 12px;
    transition: all 0.2s ease;
}

.pagination a {
    background: var(--bg-white);
    color: var(--text-dark);
    border: 1px solid var(--border-light);
}

.pagination a:hover {
    background: var(--primary-dark);
    color: var(--bg-white);
    border-color: var(--primary-dark);
}

.pagination .current {
    background: var(--primary-dark);
    color: var(--bg-white);
    border: 1px solid var(--primary-dark);
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
        padding: 12px 15px;
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
                $sellerName = $seller['display_name'] ?? trim(($seller['first_name'] ?? '') . ' ' . ($seller['last_name'] ?? '')) ?: ($seller['username'] ?? 'Seller');
                $initials = strtoupper(substr($seller['username'] ?? 'S', 0, 1));
                $activeProducts = (int)($seller['active_products'] ?? 0);
                $totalProducts = (int)($seller['product_count'] ?? 0);
            ?>
                <div class="seller-card">
                    <div class="seller-header">
                        <div class="seller-avatar"><?php echo htmlspecialchars($initials); ?></div>
                        <div class="seller-info">
                            <h3><a href="seller.php?seller_id=<?php echo (int)$seller['id']; ?>"><?php echo htmlspecialchars($sellerName); ?></a></h3>
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
                    
                    <a href="seller.php?seller_id=<?php echo (int)$seller['id']; ?>" class="visit-seller-btn">
                        <i class="fas fa-store"></i> Visit Store
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

