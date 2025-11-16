<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

requireSeller();

$userId = $_SESSION['user_id'];

if (!isset($_GET['id'])) {
    header("Location: manage-products.php");
    exit();
}

$productId = intval($_GET['id']);

// Check if product belongs to the seller
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND seller_id = ?");
$stmt->execute([$productId, $userId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    echo "<p class='error-message'>Product not found or you don't have permission to edit it.</p>";
    echo "<a href='manage-products.php' class='back-button'>Back to Products</a>";
    require_once 'includes/footer.php';
    exit();
}

// Handle form submission
if (isset($_POST['update_product'])) {
    // Prevent sellers from editing products that were suspended or rejected by admin
    if (in_array($product['status'], ['suspended', 'rejected'])) {
        $_SESSION['product_toast'] = ['type' => 'error', 'text' => 'This product was ' . $product['status'] . ' by admin and cannot be edited. Please contact admin for assistance.'];
        header("Location: edit-product.php?id=" . $productId);
        exit();
    }
    
    $name = sanitizeInput($_POST['name']);
    $description = sanitizeInput($_POST['description']);
    $price = floatval($_POST['price']);
    $categoryIds = isset($_POST['category_id']) ? $_POST['category_id'] : [];
    $categoryId = !empty($categoryIds) ? intval($categoryIds[0]) : 0;
    $stockQuantity = intval($_POST['stock_quantity']);
    $status = sanitizeInput($_POST['status']);
    
    // Handle image upload
    $imageUrl = $product['image_url'];
    
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'assets/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        $fileType = $_FILES['image']['type'];
        
        if (in_array($fileType, $allowedTypes)) {
            $fileName = time() . '_' . basename($_FILES['image']['name']);
            $uploadFile = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadFile)) {
                if ($product['image_url'] !== 'assets/uploads/tempo_image.jpg' && file_exists($product['image_url'])) {
                    unlink($product['image_url']);
                }
                $imageUrl = $uploadFile;
            } else {
                echo "<p class='warning-message'>Image upload failed. Keeping existing image.</p>";
            }
        } else {
            echo "<p class='warning-message'>Invalid file type. Using existing image.</p>";
        }
    }
    
    $stmt = $pdo->prepare("SELECT id FROM categories WHERE id = ? AND seller_id IS NULL");
    $stmt->execute([$categoryId]);
    $categoryExists = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$categoryExists) {
        echo "<p class='error-message'>Invalid category selected.</p>";
    } else {
        $stmt = $pdo->prepare("UPDATE products SET name = ?, description = ?, price = ?, category_id = ?, stock_quantity = ?, status = ?, image_url = ? WHERE id = ?");
        if ($stmt->execute([$name, $description, $price, $categoryId, $stockQuantity, $status, $imageUrl, $productId])) {
            $_SESSION['product_toast'] = ['type' => 'success', 'text' => 'Product updated successfully!'];
            header("Location: edit-product.php?id=" . $productId);
            exit();
        } else {
            $_SESSION['product_toast'] = ['type' => 'error', 'text' => 'Error updating product. Please try again.'];
            header("Location: edit-product.php?id=" . $productId);
            exit();
        }
    }
}

// Get all platform categories
$stmt = $pdo->prepare("SELECT * FROM categories WHERE seller_id IS NULL ORDER BY name");
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if product is suspended or rejected by admin (read-only mode)
$isReadOnly = in_array($product['status'], ['suspended', 'rejected']);

// Include header after form processing is complete (to avoid headers already sent)
require_once 'includes/seller_header.php';
?>

<main>
<div class="products-container">
    <?php if (isset($_SESSION['product_toast'])): ?>
        <div class="notification-toast <?php echo $_SESSION['product_toast']['type']; ?>" id="notificationToast">
            <div class="toast-icon">
                <?php if ($_SESSION['product_toast']['type'] === 'success'): ?>
                    <i class="fas fa-check-circle"></i>
                <?php else: ?>
                    <i class="fas fa-exclamation-circle"></i>
                <?php endif; ?>
            </div>
            <div class="toast-content">
                <div class="toast-title">
                    <?php echo $_SESSION['product_toast']['type'] === 'success' ? 'Success!' : 'Error!'; ?>
                </div>
                <div class="toast-message">
                    <?php echo htmlspecialchars($_SESSION['product_toast']['text']); ?>
                </div>
            </div>
            <button class="toast-close" onclick="closeNotification()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['product_toast']); ?>
    <?php endif; ?>

    <h1><?php echo $isReadOnly ? 'View Product Details' : 'Edit Product'; ?></h1>

    <?php if ($isReadOnly): ?>
        <div style="background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; padding: 16px; margin-bottom: 20px; color: #92400e;">
            <strong><i class="fas fa-exclamation-triangle"></i> This product was <?php echo ucfirst($product['status']); ?> by admin.</strong>
            <p style="margin: 8px 0 0 0; font-size: 14px;">You can only view the product details. This product cannot be edited or reactivated. Please contact admin for assistance.</p>
        </div>
    <?php endif; ?>

<div class="product-management">
    <div class="edit-product-form">
        <form method="POST" action="" enctype="multipart/form-data" <?php echo $isReadOnly ? 'onsubmit="return false;"' : ''; ?>>
            <div class="form-grid">
                <div class="form-left">
                    <div class="form-group">
                        <label for="product_name">Product Name:</label>
                        <input type="text" id="product_name" name="name" value="<?php echo htmlspecialchars($product['name']); ?>" <?php echo $isReadOnly ? 'readonly' : 'required'; ?> style="<?php echo $isReadOnly ? 'background: #f3f4f6; cursor: not-allowed;' : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="product_description">Description:</label>
                        <textarea id="product_description" name="description" <?php echo $isReadOnly ? 'readonly' : 'required'; ?> style="<?php echo $isReadOnly ? 'background: #f3f4f6; cursor: not-allowed;' : ''; ?>"><?php echo htmlspecialchars($product['description']); ?></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="price">Price (₱):</label>
                            <input type="number" id="price" name="price" step="0.01" min="0" value="<?php echo htmlspecialchars($product['price']); ?>" <?php echo $isReadOnly ? 'readonly' : 'required'; ?> style="<?php echo $isReadOnly ? 'background: #f3f4f6; cursor: not-allowed;' : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="stock_quantity">Stock Quantity:</label>
                            <input type="number" id="stock_quantity" name="stock_quantity" min="0" value="<?php echo htmlspecialchars($product['stock_quantity']); ?>" <?php echo $isReadOnly ? 'readonly' : 'required'; ?> style="<?php echo $isReadOnly ? 'background: #f3f4f6; cursor: not-allowed;' : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="status">Status:</label>
                            <?php if (in_array($product['status'], ['suspended', 'rejected'])): ?>
                                <div style="padding: 10px 12px; border: 1px solid rgba(0,0,0,0.1); border-radius: 8px; background: #f3f4f6; color: #6b7280;">
                                    <strong><?php echo ucfirst($product['status']); ?></strong> - Cannot be changed. This product was <?php echo $product['status']; ?> by admin and requires admin approval to reactivate.
                                </div>
                                <input type="hidden" name="status" value="<?php echo htmlspecialchars($product['status']); ?>">
                            <?php else: ?>
                                <select id="status" name="status" required>
                                    <option value="active" <?php echo $product['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $product['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="category_id">Product Categories:</label>
                        <div class="category-selection-container">
                            <?php if (empty($categories)): ?>
                                <p class="no-categories">No categories available - Contact admin</p>
                            <?php else: ?>
                                <?php 
                                $groupedCats = [];
                                $orphanChildren = [];
                                
                                foreach ($categories as $cat) {
                                    if ($cat['parent_id'] === null) {
                                        $groupedCats[$cat['id']] = [
                                            'info' => $cat,
                                            'children' => []
                                        ];
                                    }
                                }
                                
                                foreach ($categories as $cat) {
                                    if ($cat['parent_id'] !== null) {
                                        if (isset($groupedCats[$cat['parent_id']])) {
                                            $groupedCats[$cat['parent_id']]['children'][] = $cat;
                                        } else {
                                            $orphanChildren[] = $cat;
                                        }
                                    }
                                }
                                
                                foreach ($groupedCats as $parentId => $data): 
                                    $parent = $data['info'];
                                    $hasChildren = !empty($data['children']);
                                ?>
                                    <div class="category-group">
                                        <div class="parent-category">
                                            <div class="parent-label">
                                                <span class="category-name parent-name">
                                                    <?php 
                                                        $cleanName = preg_replace('/[\x{1F300}-\x{1F9FF}]|[\x{2600}-\x{26FF}]|[\x{2700}-\x{27BF}]|[\x{FE0F}]|[\x{200D}]|[\x{1F600}-\x{1F64F}]|[\x{1F1E0}-\x{1F1FF}]/u', '', $parent['name']);
                                                        echo html_entity_decode(htmlspecialchars(trim($cleanName)), ENT_QUOTES, 'UTF-8'); 
                                                    ?>
                                                </span>
                                                <?php if ($hasChildren): ?>
                                                    <span class="toggle-children" onclick="toggleChildren(<?php echo $parent['id']; ?>, event)">
                                                        <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                                                            <path d="M8 11L3 6h10l-5 5z"/>
                                                        </svg>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <?php if ($hasChildren): ?>
                                            <div class="child-categories collapsed" id="children_<?php echo $parent['id']; ?>">
                                                <?php foreach ($data['children'] as $child): ?>
                                                    <label class="category-checkbox-label child-label" style="<?php echo $isReadOnly ? 'opacity: 0.6; cursor: not-allowed;' : ''; ?>">
                                                        <input type="checkbox" 
                                                            name="category_id[]" 
                                                            value="<?php echo $child['id']; ?>" 
                                                            class="category-checkbox child-checkbox"
                                                            data-parent="<?php echo $parent['id']; ?>"
                                                            <?php echo ($child['id'] == $product['category_id']) ? 'checked' : ''; ?>
                                                            <?php echo $isReadOnly ? 'disabled' : ''; ?>>
                                                        <span class="category-name child-name">
                                                            <?php 
                                                                $cleanChildName = preg_replace('/[\x{1F300}-\x{1F9FF}]|[\x{2600}-\x{26FF}]|[\x{2700}-\x{27BF}]|[\x{FE0F}]|[\x{200D}]|[\x{1F600}-\x{1F64F}]|[\x{1F1E0}-\x{1F1FF}]/u', '', $child['name']);
                                                                echo html_entity_decode(htmlspecialchars(trim($cleanChildName)), ENT_QUOTES, 'UTF-8'); 
                                                            ?>
                                                        </span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php if (!empty($orphanChildren)): ?>
                                    <div class="category-group">
                                        <div class="orphan-categories-header">
                                            <span style="color: #FFD736; font-size: 14px; font-weight: 600;">Other Categories</span>
                                        </div>
                                        <?php foreach ($orphanChildren as $orphan): ?>
                                            <label class="category-checkbox-label child-label" style="<?php echo $isReadOnly ? 'opacity: 0.6; cursor: not-allowed;' : ''; ?>">
                                                <input type="checkbox" 
                                                    name="category_id[]" 
                                                    value="<?php echo $orphan['id']; ?>" 
                                                    class="category-checkbox"
                                                    <?php echo ($orphan['id'] == $product['category_id']) ? 'checked' : ''; ?>
                                                    <?php echo $isReadOnly ? 'disabled' : ''; ?>>
                                                <span class="category-name child-name">
                                                    <?php 
                                                        $cleanOrphanName = preg_replace('/[\x{1F300}-\x{1F9FF}]|[\x{2600}-\x{26FF}]|[\x{2700}-\x{27BF}]|[\x{FE0F}]|[\x{200D}]|[\x{1F600}-\x{1F64F}]|[\x{1F1E0}-\x{1F1FF}]/u', '', $orphan['name']);
                                                        echo html_entity_decode(htmlspecialchars(trim($cleanOrphanName)), ENT_QUOTES, 'UTF-8'); 
                                                    ?>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <small class="form-help">Click parent categories to expand/collapse. Select subcategories for your product.</small>
                    </div>
                </div>
                
                <div class="form-right">
                    <div class="form-group">
                        <label>Current Image:</label>
                        <div class="current-image-preview">
                            <img src="<?php echo htmlspecialchars($product['image_url']); ?>" 
                                 alt="<?php echo htmlspecialchars($product['name']); ?>" 
                                 class="product-preview">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="image">Change Product Image:</label>
                        <?php if ($isReadOnly): ?>
                            <div style="padding: 10px 12px; border: 1px solid rgba(0,0,0,0.1); border-radius: 8px; background: #f3f4f6; color: #6b7280;">
                                Image cannot be changed. This product is <?php echo $product['status']; ?> by admin.
                            </div>
                        <?php else: ?>
                            <div class="upload-area" id="upload-area">
                                <div class="upload-placeholder" id="upload-placeholder">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                    </svg>
                                    <p>Click to upload or drag and drop</p>
                                    <small>PNG, JPG, GIF up to 10MB</small>
                                </div>
                                <img id="image-preview" src="" alt="Preview" class="image-preview" style="display: none;">
                            </div>
                            <input type="file" id="image" name="image" accept="image/*" onchange="previewImage(event)" style="display: none;">
                            <small class="form-help">Leave empty to keep current image.</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="form-actions">
                <?php if (!$isReadOnly): ?>
                    <button type="submit" name="update_product">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 8px;">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                            <polyline points="17 21 17 13 7 13 7 21"></polyline>
                            <polyline points="7 3 7 8 15 8"></polyline>
                        </svg>
                        Update Product
                    </button>
                <?php endif; ?>
                <a href="manage-products.php" class="btn-cancel">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 8px;">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                    <?php echo $isReadOnly ? 'Back to Products' : 'Cancel'; ?>
                </a>
            </div>
        </form>
    </div>
</div>
</main>

<style>
/* Modern CSS Variables - Matching Customer Side */
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

/* Base Styles */
html, body {
    background: var(--bg-light) !important;
    margin: 0;
    padding: 0;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    color: var(--text-dark);
}

/* Main Layout - Compact and Responsive */
main {
    background: var(--bg-light) !important;
    min-height: calc(100vh - 60px);
    margin-top: 15px;
    margin-left: 240px;
    margin-bottom: 0;
    padding: 12px;
    transition: margin-left 0.3s ease !important;
}

main.sidebar-collapsed {
    margin-left: 70px;
}

/* Container - Adjusted for sidebar */
.products-container {
    max-width: 1400px;
    margin: 0;
    margin-left: -220px;
    padding: 0 16px;
    transition: margin-left 0.3s ease;
}

main.sidebar-collapsed .products-container {
    margin-left: -150px;
}

/* Notification Toast - Modern */
.notification-toast {
    position: fixed;
    top: 100px;
    right: 20px;
    max-width: 450px;
    min-width: 350px;
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    padding: 20px 24px;
    color: var(--text-dark);
    box-shadow: 0 8px 24px rgba(19, 3, 37, 0.15);
    z-index: 10000;
    animation: slideInRight 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    display: flex;
    align-items: center;
    gap: 16px;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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

/* Page Title */
h1 {
    color: var(--primary-dark) !important;
    font-size: 1.35rem !important;
    font-weight: 700 !important;
    margin: 0 !important;
    margin-bottom: 16px !important;
    padding: 0 !important;
    text-shadow: none !important;
}

/* Edit Product Form - Modern */
.edit-product-form {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(19, 3, 37, 0.08);
}
.category-selection-container {
    max-height: 320px;
    overflow-y: auto;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 12px;
    background: #ffffff;
}

.category-selection-container::-webkit-scrollbar {
    width: 6px;
}

.category-selection-container::-webkit-scrollbar-track {
    background: #f5f5f5;
}

.category-selection-container::-webkit-scrollbar-thumb {
    background: #ccc;
    border-radius: 3px;
}

.category-selection-container::-webkit-scrollbar-thumb:hover {
    background: #999;
}

.category-group {
    margin-bottom: 8px;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    background: #ffffff;
    overflow: hidden;
}

.parent-category {
    background: transparent;
}

.parent-label {
    padding: 12px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    transition: all 0.2s ease;
    border-bottom: 1px solid #e0e0e0;
    background: #fafafa;
}

.parent-label:hover {
    background: rgba(255,215,54,0.1);
}

.parent-name {
    color: #130325;
    font-weight: 600;
    font-size: 13px;
    flex: 1;
    text-shadow: none !important;
}

.toggle-children {
    color: #666;
    transition: transform 0.2s ease;
    display: flex;
    align-items: center;
}

.toggle-children.rotated svg {
    transform: rotate(-90deg);
}

.child-categories {
    padding: 0;
    background: #ffffff;
    max-height: 400px;
    overflow: hidden;
    transition: max-height 0.25s ease;
}

.child-categories.collapsed {
    max-height: 0;
}

.child-label {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 10px 14px !important;
    margin: 0 !important;
    border-bottom: 1px solid #f0f0f0 !important;
    transition: all 0.15s ease !important;
    cursor: pointer !important;
    position: relative !important;
    width: 100% !important;
    gap: 10px !important;
}

.child-label:last-child {
    border-bottom: none !important;
}

.child-label:hover {
    background: rgba(255,215,54,0.08) !important;
}

.child-label:has(.category-checkbox:checked) {
    background: rgba(255,215,54,0.12) !important;
}

.child-name {
    color: #130325 !important;
    font-size: 14px !important;
    font-weight: 400 !important;
    margin: 0 !important;
    text-shadow: none !important;
    line-height: 1.5 !important;
    flex: 1 !important;
    text-align: left !important;
    order: 1 !important;
}

.category-checkbox {
    width: 18px !important;
    height: 18px !important;
    cursor: pointer !important;
    accent-color: #FFD736 !important;
    margin: 0 !important;
    flex-shrink: 0 !important;
    order: 2 !important;
    margin-left: auto !important;
}

.orphan-categories-header {
    padding: 12px 16px;
    background: #fafafa;
    border-bottom: 1px solid #e0e0e0;
}

.orphan-categories-header span {
    color: #130325;
    font-size: 13px;
    font-weight: 600;
}

.no-categories {
    color: #dc3545;
    padding: 20px;
    text-align: center;
    font-style: italic;
}

/* Form Layout - Modern Grid */
.form-grid {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 24px;
    margin-bottom: 0;
}

.form-left,
.form-right {
    display: flex;
    flex-direction: column;
}

/* Form Groups - Modern */
.form-group {
    margin-bottom: 20px;
}

.form-group:last-child {
    margin-bottom: 0;
}

.form-group label {
    display: block;
    color: var(--primary-dark);
    font-weight: 600;
    margin-bottom: 8px;
    font-size: 14px;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px 14px;
    background: var(--bg-white);
    border: 1.5px solid var(--border-light);
    border-radius: 8px;
    color: var(--primary-dark);
    font-size: 14px;
    transition: all 0.2s ease;
    font-family: inherit;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: var(--primary-dark);
    box-shadow: 0 0 0 3px rgba(19, 3, 37, 0.1);
}

.form-group textarea {
    min-height: 120px;
    resize: vertical;
    font-family: inherit;
    line-height: 1.5;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

/* Form Right Panel - Modern */
.form-right {
    display: flex;
    flex-direction: column;
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 8px;
    padding: 20px;
    height: fit-content;
    position: sticky;
    top: 20px;
    box-shadow: 0 2px 8px rgba(19, 3, 37, 0.06);
}

.current-image-preview {
    width: 100%;
    margin-bottom: 20px;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
}

.product-preview {
    width: 100%;
    height: 250px;
    object-fit: cover;
}

.upload-area {
    width: 100%;
    min-height: 320px;
    border: 2px dashed #130325;
    border-radius: 4px;
    background: #f8f9fa;
    cursor: pointer;
    transition: all 0.2s ease;
    overflow: hidden;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    margin-bottom: 12px;
}

.upload-area:hover {
    border-color: #130325;
    background: #f0f2f5;
    border-style: solid;
}

.upload-placeholder {
    text-align: center;
    color: #130325;
    padding: 40px 20px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.upload-placeholder svg {
    width: 48px;
    height: 48px;
    margin-bottom: 12px;
    color: #130325;
}

.upload-placeholder p {
    margin: 0 0 4px 0;
    font-weight: 500;
    font-size: 15px;
    color: #130325;
}

.upload-placeholder small {
    color: #666;
    font-size: 13px;
}

.image-preview {
    width: 100%;
    height: 320px;
    object-fit: cover;
    border-radius: 4px;
}

.form-help {
    display: block;
    margin-top: 8px;
    font-size: 12px;
    color: #666;
    font-style: italic;
}

/* Form Actions - Modern */
.form-actions {
    display: flex;
    gap: 12px;
    padding-top: 24px;
    margin-top: 24px;
    border-top: 1px solid var(--border-light);
    justify-content: flex-start;
}

.form-actions button,
.btn-cancel {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    font-family: inherit;
    text-decoration: none;
}

.form-actions button[type="submit"] {
    background: var(--primary-dark);
    color: var(--bg-white);
    border: 1px solid var(--primary-dark);
}

.form-actions button[type="submit"]:hover:not(:disabled) {
    background: var(--accent-yellow);
    color: var(--primary-dark);
    border-color: var(--accent-yellow);
    transform: translateY(-1px);
}

.form-actions button[type="submit"]:active:not(:disabled) {
    transform: translateY(0);
}

.btn-cancel {
    background: var(--error-red);
    color: var(--bg-white);
    border: 1px solid var(--error-red);
}

.btn-cancel:hover {
    background: #dc2626;
    border-color: #dc2626;
    transform: translateY(-1px);
}

#status {
    background: #ffffff !important;
    color: #130325;
}

#status option {
    background: #ffffff;
    color: #130325;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

/* Responsive Design - Modern */
@media (max-width: 1024px) {
    main {
        margin-left: 70px;
        padding: 10px;
        margin-top: 15px;
    }

    .products-container {
        margin-left: -150px;
        padding: 0 12px;
    }

    .edit-product-form {
        padding: 20px;
    }

    .form-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }

    .form-right {
        position: static;
        width: 100%;
    }
}

@media (max-width: 768px) {
    main {
        margin-left: 0;
        padding: 10px 8px;
        margin-top: 15px;
    }

    .products-container {
        margin-left: 0;
        padding: 0 12px;
        max-width: 100%;
    }

    .edit-product-form {
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 16px;
    }

    .form-grid {
        gap: 16px;
    }

    .form-right {
        padding: 16px;
    }

    h1 {
        font-size: 1.2rem;
        margin-bottom: 12px;
    }

    .form-actions {
        flex-direction: column;
        gap: 10px;
    }

    .form-actions button,
    .btn-cancel {
        width: 100%;
        justify-content: center;
        padding: 10px 16px;
        font-size: 14px;
    }
}

@media (max-width: 480px) {
    main {
        padding: 8px 6px;
        margin-top: 12px;
    }

    .products-container {
        padding: 0 8px;
    }

    .edit-product-form {
        padding: 12px;
        margin-bottom: 12px;
    }

    .form-right {
        padding: 12px;
    }

    h1 {
        font-size: 1.1rem;
        margin-bottom: 10px;
    }
}

@media (max-width: 360px) {
    main {
        padding: 6px 4px;
        margin-top: 10px;
    }

    .products-container {
        padding: 0 6px;
    }

    .edit-product-form {
        padding: 10px;
        margin-bottom: 10px;
    }

    h1 {
        font-size: 1rem;
        margin-bottom: 8px;
    }

    .form-actions button,
    .btn-cancel {
        padding: 6px 12px;
        font-size: 12px;
        gap: 4px;
    }
}

@media (max-width: 360px) {
    .form-actions button,
    .btn-cancel {
        padding: 6px 10px;
        font-size: 11px;
        gap: 3px;
    }
}

/* Custom Confirmation Modal - Matching Logout Modal Design */
.custom-confirm-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
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
    background: var(--bg-white);
    border-radius: 12px;
    padding: 0;
    width: 90%;
    max-width: 400px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
    animation: slideDown 0.3s ease;
    overflow: hidden;
}

.custom-confirm-header {
    background: var(--primary-dark);
    color: var(--bg-white);
    padding: 16px 20px;
    border-radius: 12px 12px 0 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.custom-confirm-title {
    color: var(--bg-white);
    font-weight: 700;
    font-size: 14px;
    margin: 0;
    text-transform: none;
    letter-spacing: normal;
}

.custom-confirm-message {
    color: var(--primary-dark);
    font-size: 13px;
    margin: 0;
    padding: 20px;
    line-height: 1.5;
}

.custom-confirm-buttons {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    padding: 16px 24px;
    border-top: 1px solid var(--border-light);
}

.custom-confirm-btn {
    padding: 8px 20px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    text-transform: none;
    letter-spacing: normal;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
}

.custom-confirm-btn.cancel {
    background: #f3f4f6;
    color: var(--primary-dark);
    border: 1px solid var(--border-light);
}

.custom-confirm-btn.cancel:hover {
    background: var(--border-light);
}

.custom-confirm-btn.primary {
    background: var(--primary-dark);
    color: var(--bg-white);
}

.custom-confirm-btn.primary:hover {
    background: var(--accent-yellow);
    color: var(--primary-dark);
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>

<script>
function toggleChildren(parentId, event) {
    event.preventDefault();
    event.stopPropagation();
    
    const childContainer = document.getElementById('children_' + parentId);
    const toggleIcon = event.currentTarget;
    
    if (childContainer.classList.contains('collapsed')) {
        childContainer.classList.remove('collapsed');
        toggleIcon.classList.add('rotated');
    } else {
        childContainer.classList.add('collapsed');
        toggleIcon.classList.remove('rotated');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const parentLabels = document.querySelectorAll('.parent-label');
    parentLabels.forEach(function(label) {
        label.addEventListener('click', function(e) {
            if (e.target === label || e.target.classList.contains('parent-name')) {
                const toggleBtn = label.querySelector('.toggle-children');
                if (toggleBtn) {
                    toggleBtn.click();
                }
            }
        });
    });
    const toast = document.querySelector('.notification-toast');
    if (toast) {
        setTimeout(function() {
            closeNotification();
        }, 5000);
    }
    
    // Sidebar state handling
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

    // Modern Confirmation Modal - Matching Logout Modal Design
    function showConfirm(message, confirmText) {
        return new Promise(function(resolve){
            const overlay = document.createElement('div');
            overlay.className = 'custom-confirm-overlay';

            const dialog = document.createElement('div');
            dialog.className = 'custom-confirm-dialog';

            // Create header matching logout modal
            const header = document.createElement('div');
            header.className = 'custom-confirm-header';
            header.innerHTML = '<h3 class="custom-confirm-title">Confirm Action</h3>';

            // Create body
            const body = document.createElement('div');
            body.className = 'custom-confirm-message';
            body.textContent = message;

            // Create footer with buttons matching logout modal
            const footer = document.createElement('div');
            footer.className = 'custom-confirm-buttons';

            const cancelBtn = document.createElement('button');
            cancelBtn.className = 'custom-confirm-btn cancel';
            cancelBtn.textContent = 'Cancel';
            cancelBtn.onmouseover = function() { this.style.background = '#e5e7eb'; };
            cancelBtn.onmouseout = function() { this.style.background = '#f3f4f6'; };

            const confirmBtn = document.createElement('button');
            confirmBtn.className = 'custom-confirm-btn primary';
            confirmBtn.textContent = confirmText || 'Confirm';
            confirmBtn.onmouseover = function() { this.style.background = '#FFD736'; this.style.color = '#130325'; };
            confirmBtn.onmouseout = function() { this.style.background = '#130325'; this.style.color = '#ffffff'; };

            footer.appendChild(cancelBtn);
            footer.appendChild(confirmBtn);

            dialog.appendChild(header);
            dialog.appendChild(body);
            dialog.appendChild(footer);
            overlay.appendChild(dialog);

            document.body.appendChild(overlay);

            requestAnimationFrame(()=> overlay.classList.add('show'));

            const close = ()=>{ overlay.classList.remove('show'); setTimeout(()=>overlay.remove(), 300); };

            overlay.addEventListener('click', (e)=>{ if(e.target === overlay){ close(); resolve(false); } });
            cancelBtn.addEventListener('click', ()=>{ close(); resolve(false); });
            confirmBtn.addEventListener('click', ()=>{ close(); resolve(true); });
        });
    }

    // Intercept back button clicks
    document.querySelectorAll('.btn-cancel').forEach(function(btn){
        btn.addEventListener('click', function(e){
            e.preventDefault();
            const message = this.textContent.trim() === 'Cancel' ? 'Are you sure you want to cancel? Any unsaved changes will be lost.' : 'Are you sure you want to go back to products?';
            showConfirm(message, 'Yes').then(function(ok){
                if (ok) window.location.href = btn.href || 'manage-products.php';
            });
        });
    });
});

function previewImage(event) {
    var reader = new FileReader();
    var imagePreview = document.getElementById('image-preview');
    var uploadPlaceholder = document.getElementById('upload-placeholder');
    
    reader.onload = function() {
        if (reader.readyState == 2) {
            imagePreview.src = reader.result;
            imagePreview.style.display = 'block';
            uploadPlaceholder.style.display = 'none';
        }
    }
    
    if (event.target.files[0]) {
        reader.readAsDataURL(event.target.files[0]);
    } else {
        imagePreview.style.display = 'none';
        uploadPlaceholder.style.display = 'flex';
    }
}

document.getElementById('upload-area').addEventListener('click', function() {
    document.getElementById('image').click();
});

var uploadArea = document.getElementById('upload-area');

uploadArea.addEventListener('dragover', function(e) {
    e.preventDefault();
    uploadArea.style.borderColor = '#FFD736';
    uploadArea.style.background = 'rgba(255, 215, 54, 0.2)';
});

uploadArea.addEventListener('dragleave', function(e) {
    e.preventDefault();
    uploadArea.style.borderColor = 'rgba(255, 215, 54, 0.5)';
    uploadArea.style.background = 'rgba(255, 255, 255, 0.05)';
});

uploadArea.addEventListener('drop', function(e) {
    e.preventDefault();
    uploadArea.style.borderColor = 'rgba(255, 215, 54, 0.5)';
    uploadArea.style.background = 'rgba(255, 255, 255, 0.05)';
    
    var files = e.dataTransfer.files;
    if (files.length > 0) {
        document.getElementById('image').files = files;
        previewImage({ target: { files: files } });
    }
});

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
</script>
