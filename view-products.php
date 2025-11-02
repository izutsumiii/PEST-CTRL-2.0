<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

requireSeller();

$userId = $_SESSION['user_id'];

// Get all products for the seller
$stmt = $pdo->prepare("
    SELECT p.*, c.name as category_name 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    WHERE p.seller_id = ? 
    ORDER BY p.created_at DESC
");
$stmt->execute([$userId]);
$allProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle product status toggle
if (isset($_GET['toggle_status'])) {
    $productId = intval($_GET['toggle_status']);
    
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND seller_id = ?");
    $stmt->execute([$productId, $userId]);
    $product = $stmt->fetch();
    
    if ($product) {
        $newStatus = $product['status'] == 'active' ? 'inactive' : 'active';
        $stmt = $pdo->prepare("UPDATE products SET status = ? WHERE id = ?");
        if ($stmt->execute([$newStatus, $productId])) {
            $statusText = $newStatus == 'active' ? 'activated' : 'deactivated';
            $_SESSION['product_message'] = ['type' => 'success', 'text' => "Product $statusText successfully!"];
        }
    }
    header("Location: view-products.php");
    exit();
}

// Handle product deletion
if (isset($_GET['delete'])) {
    $productId = intval($_GET['delete']);
    
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND seller_id = ?");
    $stmt->execute([$productId, $userId]);
    $product = $stmt->fetch();
    
    if ($product) {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM cart WHERE product_id = ?");
            $stmt->execute([$productId]);
            $cartCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM order_items WHERE product_id = ?");
            $stmt->execute([$productId]);
            $orderItemsCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($cartCount > 0 || $orderItemsCount > 0) {
                $stmt = $pdo->prepare("UPDATE products SET status = 'inactive' WHERE id = ?");
                $stmt->execute([$productId]);
                
                if ($cartCount > 0) {
                    $stmt = $pdo->prepare("DELETE FROM cart WHERE product_id = ?");
                    $stmt->execute([$productId]);
                }
                
                $pdo->commit();
                $_SESSION['product_message'] = ['type' => 'success', 'text' => 'Product deactivated successfully.'];
            } else {
                $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
                $stmt->execute([$productId]);
                
                $pdo->commit();
                $_SESSION['product_message'] = ['type' => 'success', 'text' => 'Product deleted successfully!'];
            }
            
        } catch (Exception $e) {
            $pdo->rollback();
            $_SESSION['product_message'] = ['type' => 'error', 'text' => 'Error deleting product.'];
        }
    }
    header("Location: view-products.php");
    exit();
}
// Include header after form processing is complete (to avoid headers already sent)
require_once 'includes/seller_header.php';
?>

<style>
html, body { 
    background: #f0f2f5 !important; 
    margin: 0; 
    padding: 0; 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}
main { 
    background: #f0f2f5 !important; 
    margin-left: 120px !important; 
    margin-top: 0 !important;
    margin-bottom: 0 !important;
    padding-top: 0 !important;
    padding-bottom: 40px !important;
    padding-left: 30px !important;
    padding-right: 30px !important;
    min-height: calc(100vh - 60px) !important; 
    transition: margin-left 0.3s ease !important;
}
main.sidebar-collapsed { margin-left: 0px !important; }

.notification-toast {
    position: fixed;
    top: 100px;
    right: 20px;
    max-width: 450px;
    min-width: 350px;
    background: #ffffff;
    border: 1px solid rgba(0, 0, 0, 0.1);
    border-radius: 16px;
    padding: 20px 24px;
    color: #130325;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
    z-index: 10000;
    animation: slideInRight 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    display: flex;
    align-items: center;
    gap: 16px;
    font-family: var(--font-primary, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif);
}

.notification-toast::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    border-radius: 16px 16px 0 0;
    background: linear-gradient(90deg, #FFD736, #FFA500);
}

.notification-toast.success {
    background: #ffffff;
    border-color: rgba(40, 167, 69, 0.3);
    border-top: 4px solid #28a745;
}

.notification-toast.success::before {
    background: linear-gradient(90deg, #28a745, #20c997);
}

.notification-toast.error {
    background: #ffffff;
    border-color: rgba(220, 53, 69, 0.3);
    border-top: 4px solid #dc3545;
}

.notification-toast.error::before {
    background: linear-gradient(90deg, #dc3545, #fd7e14);
}

@keyframes slideInRight {
    0% { 
        transform: translateX(100%) scale(0.8); 
        opacity: 0; 
    }
    50% {
        transform: translateX(-10px) scale(1.02);
        opacity: 0.8;
    }
    100% { 
        transform: translateX(0) scale(1); 
        opacity: 1; 
    }
}

@keyframes slideOutRight {
    0% { 
        transform: translateX(0) scale(1); 
        opacity: 1; 
    }
    100% { 
        transform: translateX(100%) scale(0.8); 
        opacity: 0; 
    }
}

.notification-toast.slide-out {
    animation: slideOutRight 0.3s ease forwards;
}

.notification-toast .toast-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    background: rgba(255, 215, 54, 0.15);
}

.notification-toast.success .toast-icon {
    background: rgba(40, 167, 69, 0.15);
    color: #28a745;
}

.notification-toast.error .toast-icon {
    background: rgba(220, 53, 69, 0.15);
    color: #dc3545;
}

.notification-toast .toast-content {
    flex: 1;
    min-width: 0;
}

.notification-toast .toast-title {
    font-size: 16px;
    font-weight: 700;
    margin: 0 0 4px 0;
    color: #130325;
    line-height: 1.3;
}

.notification-toast .toast-message {
    font-size: 14px;
    margin: 0;
    color: #130325;
    opacity: 0.8;
    line-height: 1.4;
}

.notification-toast .toast-close {
    position: absolute;
    top: 12px;
    right: 12px;
    background: none;
    border: none;
    color: #130325;
    opacity: 0.6;
    font-size: 18px;
    cursor: pointer;
    padding: 4px;
    border-radius: 6px;
    transition: all 0.2s ease;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.notification-toast .toast-close:hover {
    background: rgba(255, 215, 54, 0.15);
    color: #130325;
    opacity: 1;
    transform: scale(1.1);
}

h1 { 
    color: #130325 !important; 
    font-size: 32px !important; 
    font-weight: 700 !important; 
    margin: 0 !important;
    margin-bottom: 28px !important;
    padding: 0 !important; 
    text-shadow: none !important;
}

.products-container {
    max-width: 1600px;
    margin: 0 auto;
}

.search-card {
    background: #ffffff;
    border: 1px solid rgba(0,0,0,0.1);
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 30px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.search-wrapper {
    display: flex;
    gap: 12px;
    align-items: center;
}

.search-input-group {
    display: flex;
    gap: 8px;
    flex: 1;
}

.search-bar {
    flex: 1;
    padding: 12px 14px;
    background: #ffffff;
    border: 1px solid #ddd;
    border-radius: 4px;
    color: #130325;
    font-size: 14px;
    transition: all 0.2s ease;
}

.search-bar:focus {
    outline: none;
    border-color: #FFD736;
    box-shadow: 0 0 0 2px rgba(255,215,54,0.2);
}

.search-bar::placeholder {
    color: #999;
}

.search-btn {
    padding: 12px 16px;
    background: #FFD736;
    color: #130325;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.search-btn:hover {
    background: #f5d026;
}

.filter-dropdown {
    padding: 12px 14px;
    background: #ffffff;
    border: 1px solid #ddd;
    border-radius: 4px;
    color: #130325;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s ease;
    min-width: 160px;
}

.filter-dropdown:focus {
    outline: none;
    border-color: #FFD736;
    box-shadow: 0 0 0 2px rgba(255,215,54,0.2);
}

.filter-dropdown option {
    background: #ffffff;
    color: #130325;
}

.products-table-container {
    background: #ffffff;
    border: 1px solid rgba(0,0,0,0.1);
    border-radius: 8px;
    padding: 24px;
    margin-top: 0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.table-wrapper {
    overflow-x: auto;
}

.products-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.products-table thead {
    background: #f8f9fa;
    border-bottom: 2px solid #e5e7eb;
}

.products-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 700;
    color: #130325;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    position: relative;
    user-select: none;
}

.products-table th.sortable {
    cursor: pointer;
    transition: background 0.2s ease;
    padding-right: 32px;
}

.products-table th.sortable:hover {
    background: rgba(255, 215, 54, 0.1);
}

.sort-indicator {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 10px;
    color: #9ca3af;
    transition: all 0.2s ease;
}

.sort-indicator::before {
    content: '↕';
    display: block;
}

.sort-indicator.asc::before {
    content: '↑';
    color: #130325;
}

.sort-indicator.desc::before {
    content: '↓';
    color: #130325;
}

.products-table tbody tr {
    border-bottom: 1px solid #f0f0f0;
    transition: background 0.2s ease;
}

.products-table tbody tr:hover {
    background: rgba(255, 215, 54, 0.05);
}

.products-table td {
    padding: 14px 16px;
    color: #130325;
    vertical-align: middle;
}

.product-name-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}

.product-image {
    width: 50px;
    height: 50px;
    object-fit: cover;
    border-radius: 4px;
    border: 1px solid #e5e7eb;
}

.stock-badge-table {
    display: inline-block;
    padding: 0;
    border-radius: 0;
    font-weight: 600;
    font-size: 14px;
    background: transparent;
    color: #130325;
}

.status-badge-table {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 4px;
    font-weight: 600;
    font-size: 12px;
    text-transform: uppercase;
}

.status-badge-table.active {
    background: rgba(40, 167, 69, 0.15);
    color: #28a745;
}

.status-badge-table.inactive {
    background: rgba(220, 53, 69, 0.15);
    color: #dc3545;
}

.table-actions {
    display: flex;
    gap: 8px;
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
}

.edit-btn {
    background: #130325;
    color: #ffffff;
}

.edit-btn:hover {
    background: #0a0218;
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

.delete-btn {
    background: #dc3545;
    color: #ffffff;
}

.delete-btn:hover {
    background: #c82333;
    transform: scale(1.1);
}

.no-products-message {
    text-align: center;
    padding: 40px;
    color: #6b7280;
    font-size: 14px;
}

/* Custom Confirmation Modal */
.custom-confirm-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.6);
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
    background: #ffffff;
    border-radius: 10px;
    padding: 18px 20px;
    width: 92%;
    max-width: 420px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.18);
}

.custom-confirm-title { color: #111827; font-weight: 600; font-size: 1.1rem; margin: 0 0 10px 0; text-transform: none; letter-spacing: normal; }
.custom-confirm-message { color: #374151; font-size: 0.92rem; margin-bottom: 20px; line-height: 1.5; }
.custom-confirm-buttons { display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px; }
.custom-confirm-btn { padding: 6px 12px; border-radius: 6px; font-size: 0.85rem; font-weight: 600; text-transform: none; letter-spacing: normal; border: none; cursor: pointer; }
.custom-confirm-btn.cancel { background: #6c757d; color: white; }
.custom-confirm-btn.primary { background: #dc3545; color: white; }
.custom-confirm-btn.primary:hover { background: #c82333; }
.custom-confirm-btn.cancel:hover { background: #5a6268; }

@media (max-width: 768px) {
    main { padding: 30px 24px 60px 24px !important; }
    .search-wrapper { flex-direction: column; }
    .filter-dropdown { width: 100%; }
    .products-table { font-size: 12px; }
    .table-actions { flex-direction: row; }
}
</style>

<main>
<div class="products-container">
    <?php if (isset($_SESSION['product_message'])): ?>
        <div class="notification-toast <?php echo $_SESSION['product_message']['type']; ?>" id="notificationToast">
            <div class="toast-icon">
                <?php if ($_SESSION['product_message']['type'] === 'success'): ?>
                    <i class="fas fa-check-circle"></i>
                <?php else: ?>
                    <i class="fas fa-exclamation-circle"></i>
                <?php endif; ?>
            </div>
            <div class="toast-content">
                <div class="toast-title">
                    <?php echo $_SESSION['product_message']['type'] === 'success' ? 'Success!' : 'Error!'; ?>
                </div>
                <div class="toast-message">
                    <?php echo htmlspecialchars($_SESSION['product_message']['text']); ?>
                </div>
            </div>
            <button class="toast-close" onclick="closeNotification()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['product_message']); ?>
    <?php endif; ?>

    <h1>View Products</h1>

    <div class="search-card">
        <div class="search-wrapper">
            <div class="search-input-group">
                <input type="text" class="search-bar" id="productSearch" placeholder="Search by name, category, or ID..." onkeyup="filterProducts()">
                <button class="search-btn" onclick="filterProducts()" title="Search">
                    <i class="fas fa-search"></i>
                </button>
            </div>
            <select class="filter-dropdown" id="statusFilter" onchange="filterProducts()">
                <option value="all">All Products</option>
                <option value="active">Active Only</option>
                <option value="inactive">Inactive Only</option>
            </select>
        </div>
    </div>

    <?php if (empty($allProducts)): ?>
        <div class="products-table-container">
            <div class="no-products-message">
                <p>No products found. Add your first product!</p>
            </div>
        </div>
    <?php else: ?>
        <div class="products-table-container">
            <div class="table-wrapper">
                <table class="products-table" id="products-table">
                    <thead>
                        <tr>
                            <th class="sortable" data-column="id">
                                ID
                                <span class="sort-indicator"></span>
                            </th>
                            <th>Image</th>
                            <th class="sortable" data-column="name">
                                Name
                                <span class="sort-indicator"></span>
                            </th>
                            <th>Category</th>
                            <th class="sortable" data-column="price">
                                Price
                                <span class="sort-indicator"></span>
                            </th>
                            <th class="sortable" data-column="stock">
                                Stock
                                <span class="sort-indicator"></span>
                            </th>
                            <th class="sortable" data-column="status">
                                Status
                                <span class="sort-indicator"></span>
                            </th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allProducts as $product): ?>
                            <tr data-id="<?php echo (int)$product['id']; ?>"
                                data-name="<?php echo htmlspecialchars($product['name']); ?>"
                                data-price="<?php echo (float)$product['price']; ?>"
                                data-stock="<?php echo (int)$product['stock_quantity']; ?>"
                                data-status="<?php echo htmlspecialchars($product['status'] ?? 'inactive'); ?>"
                                data-date="<?php echo isset($product['created_at']) ? strtotime($product['created_at']) : 0; ?>">
                                <td><?php echo $product['id']; ?></td>
                                <td>
                                    <img src="<?php echo htmlspecialchars($product['image_url']); ?>" 
                                         alt="<?php echo htmlspecialchars($product['name']); ?>" 
                                         class="product-image"
                                         onerror="this.src='assets/uploads/tempo_image.jpg'">
                                </td>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td><?php echo htmlspecialchars($product['category_name'] ?? 'No Category'); ?></td>
                                <td>₱<?php echo number_format($product['price'], 2); ?></td>
                                <td>
                                    <span class="stock-badge-table">
                                        <?php echo (int)$product['stock_quantity']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge-table <?php echo ($product['status'] == 'active') ? 'active' : 'inactive'; ?>">
                                        <?php echo ucfirst($product['status'] ?? 'inactive'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="edit-product.php?id=<?php echo $product['id']; ?>" class="action-btn edit-btn" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="?toggle_status=<?php echo $product['id']; ?>" 
                                           class="action-btn status-btn product-toggle"
                                           title="<?php echo ($product['status'] == 'active') ? 'Toggle Inactive' : 'Toggle Active'; ?>"
                                           data-action="<?php echo ($product['status'] == 'active') ? 'deactivate' : 'activate'; ?>"
                                           data-product-name="<?php echo htmlspecialchars($product['name']); ?>">
                                            <i class="fas fa-<?php echo ($product['status'] == 'active') ? 'eye-slash' : 'eye'; ?>"></i>
                                        </a>
                                        <a href="?delete=<?php echo $product['id']; ?>" 
                                           class="action-btn delete-btn product-delete"
                                           title="Delete"
                                           data-action="delete"
                                           data-product-name="<?php echo htmlspecialchars($product['name']); ?>">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
</main>

<script>
function filterProducts() {
    const searchTerm = document.getElementById('productSearch').value.toLowerCase();
    const statusFilter = document.getElementById('statusFilter').value;
    const productRows = document.querySelectorAll('.products-table tbody tr');
    
    productRows.forEach(row => {
        const productId = row.querySelector('td:nth-child(1)')?.textContent.toLowerCase() || '';
        const productName = row.querySelector('td:nth-child(3)')?.textContent.toLowerCase() || '';
        const productCategory = row.querySelector('td:nth-child(4)')?.textContent.toLowerCase() || '';
        const productStatus = row.getAttribute('data-status') || 'inactive';
        
        const matchesSearch = productId.includes(searchTerm) || 
                             productName.includes(searchTerm) || 
                             productCategory.includes(searchTerm);
        
        let matchesStatus = true;
        if (statusFilter === 'active') {
            matchesStatus = productStatus === 'active';
        } else if (statusFilter === 'inactive') {
            matchesStatus = productStatus === 'inactive';
        }
        
        if (matchesSearch && matchesStatus) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// Close notification function
function closeNotification() {
    const toast = document.getElementById('notificationToast');
    if (toast) {
        toast.classList.add('slide-out');
        setTimeout(function() {
            toast.remove();
        }, 300);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const toast = document.querySelector('.notification-toast');
    if (toast) {
        setTimeout(function() {
            closeNotification();
        }, 5000);
    }

    const main = document.querySelector('main');
    const sidebar = document.getElementById('sellerSidebar');
    
    function updateMainMargin() {
        if (sidebar && sidebar.classList.contains('collapsed')) {
            main.classList.add('sidebar-collapsed');
        } else {
            main.classList.remove('sidebar-collapsed');
        }
    }
    
    updateMainMargin();
    
    const observer = new MutationObserver(updateMainMargin);
    if (sidebar) {
        observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
    }

    // Intercept product toggle and delete with custom confirm
    function showConfirm(message, confirmText) {
        return new Promise(function(resolve){
            const overlay = document.createElement('div');
            overlay.className = 'custom-confirm-overlay';
            overlay.innerHTML = `
                <div class="custom-confirm-dialog">
                    <div class="custom-confirm-title">Confirm Action</div>
                    <div class="custom-confirm-message">${message}</div>
                    <div class="custom-confirm-buttons">
                        <button type="button" class="custom-confirm-btn cancel">Cancel</button>
                        <button type="button" class="custom-confirm-btn primary">${confirmText || 'Confirm'}</button>
                    </div>
                </div>`;
            document.body.appendChild(overlay);
            requestAnimationFrame(()=> overlay.classList.add('show'));
            const close = ()=>{ overlay.classList.remove('show'); setTimeout(()=>overlay.remove(), 200); };
            overlay.addEventListener('click', (e)=>{ if(e.target === overlay){ close(); resolve(false);} });
            overlay.querySelector('.cancel').addEventListener('click', ()=>{ close(); resolve(false); });
            overlay.querySelector('.primary').addEventListener('click', ()=>{ close(); resolve(true); });
        });
    }

    document.querySelectorAll('a.product-toggle').forEach(function(link){
        link.addEventListener('click', function(e){
            e.preventDefault();
            const action = link.getAttribute('data-action');
            const name = link.getAttribute('data-product-name') || 'this product';
            const message = `Are you sure you want to ${action} ${name}?`;
            showConfirm(message, 'Yes').then(function(ok){ if (ok) window.location.href = link.href; });
        });
    });

    document.querySelectorAll('a.product-delete').forEach(function(link){
        link.addEventListener('click', function(e){
            e.preventDefault();
            const name = link.getAttribute('data-product-name') || 'this product';
            const message = `Are you sure you want to delete ${name}? This action cannot be undone.`;
            showConfirm(message, 'Delete').then(function(ok){ if (ok) window.location.href = link.href; });
        });
    });

    // Handle table sorting (client-side, no page reload)
    const table = document.getElementById('products-table');
    if (table) {
        const tbody = table.querySelector('tbody');
        const sortableHeaders = document.querySelectorAll('.products-table th.sortable');
        let currentSort = null;
        let currentOrder = 'desc';
        
        // Store original rows data
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const rowsData = rows.map(row => {
            return {
                element: row,
                id: parseInt(row.getAttribute('data-id')) || 0,
                name: row.getAttribute('data-name') || '',
                price: parseFloat(row.getAttribute('data-price')) || 0,
                stock: parseInt(row.getAttribute('data-stock')) || 0,
                status: row.getAttribute('data-status') || 'inactive',
                date: parseInt(row.getAttribute('data-date')) || 0
            };
        });
        
        function updateSortIndicators(activeColumn, order) {
            sortableHeaders.forEach(header => {
                const indicator = header.querySelector('.sort-indicator');
                const column = header.getAttribute('data-column');
                
                // Remove all sort classes
                if (indicator) {
                    indicator.classList.remove('asc', 'desc');
                    
                    // Add active sort class
                    if (column === activeColumn) {
                        indicator.classList.add(order);
                    }
                }
            });
        }
        
        function sortTable(column, order) {
            const sortedData = [...rowsData].sort((a, b) => {
                let aVal, bVal;
                
                switch(column) {
                    case 'id':
                        aVal = a.id;
                        bVal = b.id;
                        break;
                    case 'name':
                        aVal = a.name.toLowerCase();
                        bVal = b.name.toLowerCase();
                        break;
                    case 'price':
                        aVal = a.price;
                        bVal = b.price;
                        break;
                    case 'stock':
                        aVal = a.stock;
                        bVal = b.stock;
                        break;
                    case 'status':
                        aVal = a.status;
                        bVal = b.status;
                        break;
                    default:
                        return 0;
                }
                
                if (aVal < bVal) return order === 'asc' ? -1 : 1;
                if (aVal > bVal) return order === 'asc' ? 1 : -1;
                return 0;
            });
            
            // Clear tbody
            tbody.innerHTML = '';
            
            // Append sorted rows
            sortedData.forEach(data => {
                tbody.appendChild(data.element);
            });
            
            // Update indicators
            updateSortIndicators(column, order);
            
            currentSort = column;
            currentOrder = order;
        }
        
        // Add click handlers
        sortableHeaders.forEach(header => {
            header.addEventListener('click', function() {
                const column = this.getAttribute('data-column');
                let newOrder = 'asc';
                
                // If clicking the same column, toggle order
                if (column === currentSort) {
                    newOrder = currentOrder === 'asc' ? 'desc' : 'asc';
                }
                
                // Sort table without reload
                sortTable(column, newOrder);
            });
        });
    }
});
</script>

