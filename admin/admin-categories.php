<?php
require_once 'includes/admin_header.php';
requireAdmin();

// Ensure PDO connection is available
if (!isset($pdo)) {
    require_once '../config/database.php';
}

/* ---------------------------
   CATEGORY MANAGEMENT LOGIC
----------------------------*/

// Handle category actions (add, edit, delete)
if (isset($_POST['add_category'])) {
    if (!isset($_POST['name']) || empty(trim($_POST['name']))) {
        $error = "Category name is required.";
    } else {
        $name = sanitizeInput($_POST['name']);
        $parentId = isset($_POST['parent_id']) && !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : NULL;

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE name = ? AND seller_id IS NULL");
        $stmt->execute([$name]);
        $exists = $stmt->fetchColumn();

        if ($exists > 0) {
            $error = "A category with the name '{$name}' already exists. Please choose a different name.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO categories (name, parent_id, seller_id) VALUES (?, ?, NULL)");
            try {
                $result = $stmt->execute([$name, $parentId]);
                if ($result) {
                    if ($parentId) {
                        $success = "Subcategory '{$name}' added successfully! All sellers can now use this category.";
                    } else {
                        $success = "Category '{$name}' added successfully! All sellers can now use this category.";
                    }
                } else {
                    $error = "Error adding category. Please try again.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}

if (isset($_POST['update_category'])) {
    $categoryId = intval($_POST['category_id']);
    $name = sanitizeInput($_POST['name']);
    $parentId = isset($_POST['parent_id']) && !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : NULL;
    
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ? AND seller_id IS NULL");
    $stmt->execute([$categoryId]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($category) {
        $stmt = $pdo->prepare("UPDATE categories SET name = ?, parent_id = ? WHERE id = ?");
        $result = $stmt->execute([$name, $parentId, $categoryId]);
        if ($result) {
            $success = "Category updated successfully!";
        } else {
            $error = "Error updating category.";
        }
    } else {
        $error = "Category not found or cannot be edited.";
    }
}

if (isset($_GET['delete_category'])) {
    $categoryId = intval($_GET['delete_category']);
    
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ? AND seller_id IS NULL");
    $stmt->execute([$categoryId]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($category) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
        $stmt->execute([$categoryId]);
        $productCount = $stmt->fetchColumn();
        
        if ($productCount > 0) {
            $error = "Cannot delete category '{$category['name']}' because it has {$productCount} product(s) associated with it. Please reassign or delete those products first.";
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE parent_id = ?");
            $stmt->execute([$categoryId]);
            $subcategoryCount = $stmt->fetchColumn();
            
            if ($subcategoryCount > 0) {
                $error = "Cannot delete category '{$category['name']}' because it has {$subcategoryCount} subcategory(ies). Please delete subcategories first.";
            } else {
                $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
                $result = $stmt->execute([$categoryId]);
                $success = $result ? "Category deleted successfully!" : "Error deleting category.";
            }
        }
    }
}

// Pagination parameters
$limit = 20; // Items per page
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// Get total count for pagination
$stmt = $pdo->prepare("SELECT COUNT(*) as total 
                      FROM categories c1 
                      WHERE c1.seller_id IS NULL");
$stmt->execute();
$totalCategories = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalCategories / $limit);

// Get all admin categories with pagination
$stmt = $pdo->prepare("SELECT c1.*, c2.name as parent_name 
                      FROM categories c1 
                      LEFT JOIN categories c2 ON c1.parent_id = c2.id 
                      WHERE c1.seller_id IS NULL 
                      ORDER BY c1.parent_id, c1.name
                      LIMIT ? OFFSET ?");
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get parent categories for dropdown
$stmt = $pdo->prepare("SELECT * FROM categories WHERE parent_id IS NULL AND seller_id IS NULL ORDER BY name");
$stmt->execute();
$parentCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Function to remove emojis from text
function removeEmojis($text) {
    // Remove emojis and other Unicode symbols
    return preg_replace('/[\x{1F300}-\x{1F9FF}]|[\x{2600}-\x{26FF}]|[\x{2700}-\x{27BF}]/u', '', $text);
}
?>

<!-- Static modals removed; dynamic overlays will be created via JS to match logout confirmation design -->

<div class="page-header">
    <h1 class="page-heading-title">Category Management</h1>
</div>

<?php if (isset($success)): ?>
    <div class="toast-notification toast-success" id="successToast">
        <i class="fas fa-check-circle"></i>
        <span><?php echo htmlspecialchars($success); ?></span>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="toast-notification toast-error" id="errorToast">
        <i class="fas fa-exclamation-circle"></i>
        <span><?php echo htmlspecialchars($error); ?></span>
    </div>
<?php endif; ?>

<div class="container-section">
    <div class="filter-section">
        <h3>Add New Category</h3>
        <form method="POST" action="" class="add-category-form">
            <div class="form-row">
                <div class="form-group">
                    <label for="name">Category Name <span style="color: #dc3545;">*</span></label>
                    <input type="text" id="name" name="name" required placeholder="Enter category name">
                </div>
                
                <div class="form-group">
                    <label for="parent_id">Parent Category (Optional)</label>
                    <select id="parent_id" name="parent_id">
                        <option value="">-- None (Top-Level Category) --</option>
                        <?php foreach ($parentCategories as $parent): ?>
                            <option value="<?php echo $parent['id']; ?>">
                                <?php echo htmlspecialchars(trim(removeEmojis($parent['name']))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group form-group-button">
                    <label>&nbsp;</label>
                    <button type="submit" name="add_category" class="btn-add-category">Add Category</button>
                </div>
            </div>
            <small class="form-help">Leave parent as "None" to create a main category, or select a parent to create a subcategory</small>
        </form>
    </div>

    <div class="table-container">
        <?php if (empty($categories)): ?>
            <div class="empty-state">
                <i class="fas fa-tags"></i>
                <p>No categories found. Add your first category above.</p>
            </div>
        <?php else: ?>
            <table class="admin-table" id="categoriesTable">
                <thead>
                    <tr>
                        <th class="sortable">Category Name <span class="sort-indicator"></span></th>
                        <th class="sortable">Parent Category <span class="sort-indicator"></span></th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $groupedCategories = [];
                    foreach ($categories as $category) {
                        $parentId = $category['parent_id'] ? $category['parent_id'] : 0;
                        $groupedCategories[$parentId][] = $category;
                    }
                    
                    function displayCategories($categories, $parentId = 0, $level = 0) {
                        if (!isset($categories[$parentId])) return;
                        foreach ($categories[$parentId] as $category) {
                            $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level);
                            $hasChildren = isset($categories[$category['id']]) && !empty($categories[$category['id']]);
                            
                            // Remove emojis from category names
                            $categoryName = removeEmojis($category['name']);
                            $parentName = $category['parent_name'] ? removeEmojis($category['parent_name']) : 'None';
                            
                            echo '<tr>';
                            echo '<td>' . $indent . '<strong>' . htmlspecialchars(trim($categoryName)) . '</strong></td>';
                            echo '<td>' . htmlspecialchars(trim($parentName)) . '</td>';
                            echo '<td>';
                            echo '<div class="action-buttons">';
                            echo '<a href="#" class="action-btn btn-edit" title="Edit Category" onclick="openEditModal(' . $category['id'] . ', \'' . addslashes(trim($categoryName)) . '\', ' . ($category['parent_id'] ? $category['parent_id'] : 'null') . '); return false;">';
                            echo '<i class="fas fa-edit"></i>';
                            echo '</a>';
                            
                            if (!$hasChildren) {
                                echo '<a href="#" class="action-btn btn-delete" title="Delete Category" onclick="openDeleteModal(' . $category['id'] . ', \'' . addslashes(trim($categoryName)) . '\'); return false;">';
                                echo '<i class="fas fa-trash"></i>';
                                echo '</a>';
                            }
                            
                            echo '</div>';
                            echo '</td>';
                            echo '</tr>';
                            displayCategories($categories, $category['id'], $level + 1);
                        }
                    }
                    
                    displayCategories($groupedCategories);
                    ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                    $baseUrl = "admin-categories.php";
                    ?>
                    
                    <?php if ($page > 1): ?>
                        <a href="<?php echo $baseUrl; ?>?page=<?php echo $page - 1; ?>" class="page-link">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php 
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <a href="<?php echo $baseUrl; ?>?page=<?php echo $i; ?>" 
                           class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo $baseUrl; ?>?page=<?php echo $page + 1; ?>" class="page-link">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<style>
    body {
        background: #f0f2f5 !important;
        color: #130325 !important;
        min-height: 100vh;
        margin: 0;
        font-family: 'Inter', 'Segoe UI', sans-serif;
    }

    .page-header {
        font-size: 18px !important;
        font-weight: 800 !important;
        color: #130325 !important;
        margin: 20px auto 20px auto !important;
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
        margin-top: 0 !important;
        margin-bottom: 0 !important;
        margin-left: 0 !important;
        margin-right: 0 !important;
        display: inline-flex !important;
        align-items: center !important;
        text-shadow: none !important;
    }

    .container-section {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 24px 24px;
    }

    .filter-section {
        background: #ffffff;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }

    .filter-section h3 {
        font-size: 18px;
        font-weight: 600;
        color: #130325;
        margin: 0 0 20px 0;
        text-shadow: none !important;
    }

    .add-category-form {
        margin-top: 0;
    }

    .form-row {
        display: grid;
        grid-template-columns: 2fr 2fr 1fr;
        gap: 16px;
        align-items: end;
    }

    .form-group {
        display: flex;
        flex-direction: column;
    }

    .form-group label {
        font-size: 13px;
        font-weight: 600;
        color: #130325;
        margin-bottom: 6px;
    }

    .form-group input,
    .form-group select {
        padding: 10px 12px;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 8px;
        font-size: 14px;
        background: #ffffff;
        color: #130325;
        transition: border-color 0.2s;
    }

    .form-group input:focus,
    .form-group select:focus {
        outline: none;
        border-color: #130325;
    }

    .form-group-button {
        display: flex;
        flex-direction: column;
        justify-content: flex-end;
    }

    .btn-add-category {
        background: linear-gradient(135deg, #FFD736 0%, #FFC107 100%);
        color: #130325;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        font-weight: 700;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.2s ease;
        height: 40px;
    }

    .btn-add-category:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(255, 215, 54, 0.3);
    }

    .form-help {
        font-size: 12px;
        color: #6b7280;
        margin-top: 8px;
        display: block;
    }

    .table-container {
        background: #ffffff;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 12px;
        padding: 0;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        overflow: hidden;
    }

    .admin-table {
        width: 100%;
        border-collapse: collapse;
    }

    .admin-table thead {
        background: #130325 !important;
    }

    .admin-table thead tr {
        background: #130325 !important;
    }

    .admin-table th {
        background: #130325 !important;
        color: #ffffff !important;
        padding: 14px 16px;
        text-align: left;
        font-weight: 600;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: none;
        position: relative;
    }

    .admin-table thead th.sortable {
        background: #130325 !important;
        color: #ffffff !important;
    }

    th.sortable {
        cursor: pointer;
        user-select: none;
    }


    .sort-indicator {
        display: inline-block;
        margin-left: 6px;
        font-size: 12px;
        opacity: 0.7;
        vertical-align: middle;
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

    .admin-table td {
        padding: 14px 16px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        color: #130325;
    }

    .admin-table tbody tr:hover {
        background: rgba(255, 215, 54, 0.05);
    }

    .admin-table tbody tr:last-child td {
        border-bottom: none;
    }

    .action-buttons {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 6px;
        text-decoration: none;
        transition: all 0.2s ease;
        cursor: pointer;
        border: none;
    }

    .btn-edit {
        background: #3b82f6;
        color: #ffffff;
    }

    .btn-edit:hover {
        background: #2563eb;
        transform: translateY(-1px);
    }

    .btn-delete {
        background: #dc3545;
        color: #ffffff;
    }

    .btn-delete:hover {
        background: #c82333;
        transform: translateY(-1px);
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #6b7280;
    }

    .empty-state i {
        font-size: 48px;
        color: #d1d5db;
        margin-bottom: 16px;
    }

    .empty-state p {
        font-size: 14px;
        color: #6b7280;
    }

    /* Toast Notification */
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

    .toast-success {
        background: #10b981;
        color: #ffffff;
    }

    .toast-error {
        background: #ef4444;
        color: #ffffff;
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

    /* Modal styles removed; dynamic overlays mirror logout confirmation with inline styles */

    /* Pagination */
    .pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 20px;
        padding: 20px;
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

    @media (max-width: 768px) {
        .form-row {
            grid-template-columns: 1fr;
        }
    }
</style>

<script>
// Reusable confirm dialog matching logout style
function adminCatConfirm(message, label){
    return new Promise(function(resolve){
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:10000;opacity:0;transition:opacity .2s ease';
        const card = document.createElement('div');
        card.style.cssText = 'background:#ffffff;border-radius:12px;overflow:hidden;min-width:320px;max-width:420px;box-shadow:0 10px 40px rgba(0,0,0,0.2)';
        const header = document.createElement('div');
        header.style.cssText = 'background:#130325;color:#ffffff;padding:16px 20px;display:flex;align-items:center;gap:10px;';
        header.innerHTML = '<h3 style="margin:0;font-size:14px;font-weight:700;color:#ffffff;">Confirm Action</h3>';
        const body = document.createElement('div');
        body.style.cssText = 'padding:20px;color:#130325;font-size:13px;';
        body.textContent = message;
        const footer = document.createElement('div');
        footer.style.cssText = 'padding:12px 16px;display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #e5e7eb;';
        const cancelBtn = document.createElement('button');
        cancelBtn.textContent = 'Cancel';
        cancelBtn.style.cssText = 'padding:8px 16px;background:#f3f4f6;color:#130325;border:1px solid #e5e7eb;border-radius:6px;font-weight:600;cursor:pointer;';
        const okBtn = document.createElement('button');
        okBtn.textContent = label || 'Confirm';
        okBtn.style.cssText = 'padding:8px 16px;background:#130325;color:#ffffff;border:none;border-radius:6px;font-weight:600;cursor:pointer;';
        footer.appendChild(cancelBtn); footer.appendChild(okBtn);
        card.appendChild(header); card.appendChild(body); card.appendChild(footer);
        overlay.appendChild(card);
        document.body.appendChild(overlay);
        requestAnimationFrame(()=> overlay.style.opacity='1');
        function close(res){ overlay.style.opacity='0'; setTimeout(()=>overlay.remove(),150); resolve(res); }
        overlay.addEventListener('click',(e)=>{ if(e.target===overlay) close(false); });
        cancelBtn.addEventListener('click',()=> close(false));
        okBtn.addEventListener('click',()=> close(true));
    });
}

// Provide parent categories data to JS for building the select
const parentCategoriesData = <?php echo json_encode($parentCategories); ?>;

function openEditModal(id, name, parentId) {
    // Build a dynamic overlay styled like logout confirmation, but with a form
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:10000;opacity:0;transition:opacity .2s ease';
    const card = document.createElement('div');
    card.style.cssText = 'background:#ffffff;border-radius:12px;overflow:hidden;min-width:320px;max-width:480px;width:92vw;box-shadow:0 10px 40px rgba(0,0,0,0.2)';

    const header = document.createElement('div');
    header.style.cssText = 'background:#130325;color:#ffffff;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;';
    const title = document.createElement('h3');
    title.textContent = 'Edit Category';
    title.style.cssText = 'margin:0;font-size:14px;font-weight:800;color:#ffffff;letter-spacing:.3px;';
    const closeBtn = document.createElement('button');
    closeBtn.innerHTML = '&times;';
    closeBtn.style.cssText = 'background:none;border:none;color:#ffffff;font-size:22px;cursor:pointer;line-height:1;';
    header.appendChild(title);
    header.appendChild(closeBtn);

    const body = document.createElement('div');
    body.style.cssText = 'padding:16px 18px;color:#130325;';

    // Create form
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '';

    const idInput = document.createElement('input');
    idInput.type = 'hidden';
    idInput.name = 'category_id';
    idInput.value = id;

    const nameLabel = document.createElement('label');
    nameLabel.setAttribute('for','edit_name');
    nameLabel.textContent = 'Category Name ';
    nameLabel.style.cssText = 'display:block;margin-bottom:8px;font-weight:600;color:#130325;font-size:14px;';
    const req = document.createElement('span');
    req.textContent = '*';
    req.style.color = '#dc3545';
    nameLabel.appendChild(req);

    const nameInput = document.createElement('input');
    nameInput.type = 'text';
    nameInput.id = 'edit_name';
    nameInput.name = 'name';
    nameInput.required = true;
    nameInput.placeholder = 'Enter category name';
    nameInput.value = name || '';
    nameInput.style.cssText = 'width:100%;padding:10px 12px;border:none;border-radius:8px;font-size:14px;background:#f8f9fb;color:#130325;margin-bottom:16px;outline:none;box-shadow:0 0 0 0 rgba(0,0,0,0)';
    nameInput.addEventListener('focus',function(){ this.style.boxShadow='0 0 0 2px rgba(19,3,37,0.2)'; this.style.background='#ffffff'; });
    nameInput.addEventListener('blur',function(){ this.style.boxShadow='none'; this.style.background='#f8f9fb'; });

    const parentLabel = document.createElement('label');
    parentLabel.setAttribute('for','edit_parent_id');
    parentLabel.textContent = 'Parent Category (Optional)';
    parentLabel.style.cssText = 'display:block;margin-bottom:8px;font-weight:600;color:#130325;font-size:14px;';

    const parentSelect = document.createElement('select');
    parentSelect.id = 'edit_parent_id';
    parentSelect.name = 'parent_id';
    parentSelect.style.cssText = 'width:100%;padding:10px 12px;border:none;border-radius:8px;font-size:14px;background:#f8f9fb;color:#130325;margin-bottom:8px;outline:none;';
    parentSelect.addEventListener('focus',function(){ this.style.boxShadow='0 0 0 2px rgba(19,3,37,0.2)'; this.style.background='#ffffff'; });
    parentSelect.addEventListener('blur',function(){ this.style.boxShadow='none'; this.style.background='#f8f9fb'; });

    const noneOpt = document.createElement('option');
    noneOpt.value = '';
    noneOpt.textContent = '-- None (Top-Level Category) --';
    parentSelect.appendChild(noneOpt);
    (parentCategoriesData || []).forEach(function(p){
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = (p.name || '').replace(/[\u{1F300}-\u{1F9FF}\u{2600}-\u{26FF}\u{2700}-\u{27BF}]/gu,'').trim();
        parentSelect.appendChild(opt);
    });
    parentSelect.value = parentId || '';

    const help = document.createElement('small');
    help.textContent = 'Leave as "None" to make it a main category';
    help.style.cssText = 'display:block;font-size:12px;color:#6b7280;margin-top:8px;margin-bottom:8px;';

    const footer = document.createElement('div');
    footer.style.cssText = 'padding:12px 16px;display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #e5e7eb;margin-top:12px;';
    const cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.style.cssText = 'padding:10px 20px;background:#6c757d;color:#ffffff;border:none;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer;';
    const saveBtn = document.createElement('button');
    saveBtn.type = 'submit';
    saveBtn.name = 'update_category';
    saveBtn.textContent = 'Update Category';
    saveBtn.style.cssText = 'padding:10px 20px;background:linear-gradient(135deg,#FFD736 0%,#FFC107 100%);color:#130325;border:none;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer;';

    footer.appendChild(cancelBtn);
    footer.appendChild(saveBtn);

    form.appendChild(idInput);
    form.appendChild(nameLabel);
    form.appendChild(nameInput);
    form.appendChild(parentLabel);
    form.appendChild(parentSelect);
    form.appendChild(help);
    form.appendChild(footer);

    body.appendChild(form);

    card.appendChild(header);
    card.appendChild(body);
    overlay.appendChild(card);
    document.body.appendChild(overlay);
    requestAnimationFrame(()=> overlay.style.opacity='1');

    function close(){ overlay.style.opacity='0'; setTimeout(()=>overlay.remove(),150); }
    overlay.addEventListener('click',function(e){ if(e.target===overlay) close(); });
    closeBtn.addEventListener('click',close);
    cancelBtn.addEventListener('click',close);
}

function openDeleteModal(id, name) {
    adminCatConfirm('Delete category "'+ name +'"? This cannot be undone.', 'Delete').then(function(ok){
        if (!ok) return;
        // Create a temporary form and submit
        var form = document.createElement('form');
        form.method = 'GET';
        form.action = 'admin-categories.php';
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'delete_category';
        input.value = id;
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Escape key closes any dynamic overlay by removing the top-most one
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const overlays = Array.from(document.querySelectorAll('body > div')).filter(function(el){
            return el && el.style && el.style.position === 'fixed' && el.style.inset === '0px';
        });
        const last = overlays.pop();
        if (last) { last.remove(); }
    }
});

// Auto-dismiss toast notifications
document.addEventListener('DOMContentLoaded', function() {
    const successToast = document.getElementById('successToast');
    const errorToast = document.getElementById('errorToast');
    
    function showAndDismissToast(toast) {
        if (toast) {
            setTimeout(() => {
                toast.classList.add('show');
            }, 10);
            
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => {
                    toast.remove();
                }, 300);
            }, 1500);
        }
    }
    
    showAndDismissToast(successToast);
    showAndDismissToast(errorToast);
});

// Table Sorting Functionality
function getCellValue(tr, idx) {
    const cell = tr.querySelectorAll('td')[idx];
    if (!cell) return '';
    const text = cell.textContent.trim();
    // Remove HTML entities and extra spaces
    return text.replace(/\s+/g, ' ').replace(/None/g, '');
}

function comparer(idx, asc) {
    return (a, b) => {
        const v1 = getCellValue(asc ? a : b, idx);
        const v2 = getCellValue(asc ? b : a, idx);
        
        // Simple string comparison for categories
        return v1.toString().localeCompare(v2.toString());
    };
}

const table = document.getElementById('categoriesTable');
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
</script>