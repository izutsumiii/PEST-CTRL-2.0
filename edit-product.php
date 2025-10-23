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
// Include header after form processing is complete (to avoid headers already sent)
require_once 'includes/seller_header.php';
?>

<?php if (isset($_SESSION['product_toast'])): ?>
    <div class="notification-toast <?php echo $_SESSION['product_toast']['type']; ?>">
        <?php echo htmlspecialchars($_SESSION['product_toast']['text']); ?>
    </div>
    <?php unset($_SESSION['product_toast']); ?>
<?php endif; ?>

<h1>Edit Product</h1>

<div class="product-management">
    <div class="edit-product-form">
        <form method="POST" action="" enctype="multipart/form-data">
            <div class="form-grid">
                <div class="form-left">
                    <div class="form-group">
                        <label for="product_name">Product Name:</label>
                        <input type="text" id="product_name" name="name" value="<?php echo htmlspecialchars($product['name']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="product_description">Description:</label>
                        <textarea id="product_description" name="description" required><?php echo htmlspecialchars($product['description']); ?></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="price">Price (₱):</label>
                            <input type="number" id="price" name="price" step="0.01" min="0" value="<?php echo htmlspecialchars($product['price']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="stock_quantity">Stock Quantity:</label>
                            <input type="number" id="stock_quantity" name="stock_quantity" min="0" value="<?php echo htmlspecialchars($product['stock_quantity']); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="status">Status:</label>
                            <select id="status" name="status" required>
                                <option value="active" <?php echo $product['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $product['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
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
                                                    <?php echo htmlspecialchars($parent['name']); ?>
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
                                                    <label class="category-checkbox-label child-label">
                                                        <input type="checkbox" 
                                                            name="category_id[]" 
                                                            value="<?php echo $child['id']; ?>" 
                                                            class="category-checkbox child-checkbox"
                                                            data-parent="<?php echo $parent['id']; ?>"
                                                            <?php echo ($child['id'] == $product['category_id']) ? 'checked' : ''; ?>>
                                                        <span class="category-name child-name">
                                                            <?php echo htmlspecialchars($child['name']); ?>
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
                                            <label class="category-checkbox-label child-label">
                                                <input type="checkbox" 
                                                    name="category_id[]" 
                                                    value="<?php echo $orphan['id']; ?>" 
                                                    class="category-checkbox"
                                                    <?php echo ($orphan['id'] == $product['category_id']) ? 'checked' : ''; ?>>
                                                <span class="category-name child-name">
                                                    <?php echo htmlspecialchars($orphan['name']); ?>
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
                    </div>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" name="update_product">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 8px;">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                        <polyline points="17 21 17 13 7 13 7 21"></polyline>
                        <polyline points="7 3 7 8 15 8"></polyline>
                    </svg>
                    Update Product
                </button>
                <a href="manage-products.php" class="btn-cancel">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 8px;">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<style>
/* Toast Notification */
.notification-toast {
    position: fixed;
    top: 100px;
    right: 20px;
    max-width: 400px;
    background: #1a0a2e;
    border: 1px solid rgba(255,215,54,0.5);
    border-left: 4px solid #FFD736;
    border-radius: 10px;
    padding: 16px 20px;
    color: #F9F9F9;
    box-shadow: 0 10px 30px rgba(0,0,0,0.4);
    z-index: 10000;
    animation: slideInRight 0.3s ease;
}

.notification-toast.success { border-left-color: #28a745; }
.notification-toast.error { border-left-color: #dc3545; }

@keyframes slideInRight {
    from { transform: translateX(400px); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
/* Import all styles from manage-products.php */
.category-selection-container {
    max-height: 400px;
    overflow-y: auto;
    border: 2px solid rgba(255, 215, 54, 0.3);
    border-radius: 12px;
    padding: 16px;
    background: rgba(255, 255, 255, 0.08);
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.2);
}

.category-group {
    margin-bottom: 8px;
    border-radius: 8px;
    overflow: hidden;
    background: rgba(255, 255, 255, 0.03);
}

.parent-category {
    background: rgba(255, 215, 54, 0.12);
    border-left: 4px solid #FFD736;
}

.parent-label {
    padding: 14px 16px;
    font-size: 16px;
    background: rgba(255, 215, 54, 0.12);
    border: none;
    position: relative;
    margin: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    transition: background 0.3s ease;
}

.parent-label:hover {
    background: rgba(255, 215, 54, 0.2);
}

.parent-name {
    font-size: 16px;
    font-weight: 700;
    color: #FFD736;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    flex: 1;
}

.toggle-children {
    margin-left: auto;
    cursor: pointer;
    color: #FFD736;
    transition: transform 0.3s ease;
    display: inline-flex;
    align-items: center;
    padding: 4px 8px;
    border-radius: 4px;
}

.toggle-children:hover {
    background: rgba(255, 215, 54, 0.2);
}

.toggle-children.rotated svg {
    transform: rotate(-90deg);
}

.child-categories {
    padding: 8px 0 8px 24px;
    background: rgba(0, 0, 0, 0.2);
    max-height: 500px;
    overflow: hidden;
    transition: max-height 0.4s ease, padding 0.4s ease;
    display: block !important;
}

.child-categories.collapsed {
    max-height: 0;
    padding: 0 0 0 24px;
}

.child-label {
    padding: 10px 16px !important;
    margin: 4px 0 !important;
    border-left: 2px solid rgba(255, 215, 54, 0.3);
    display: grid !important;
    grid-template-columns: 20px 1fr !important;
    gap: 12px !important;
    align-items: center !important;
}

.child-name {
    font-size: 14px !important;
    color: #E0E0E0 !important;
    font-weight: 500 !important;
    text-align: left !important;
    margin: 0 !important;
    padding: 0 !important;
    justify-self: start !important;
}

.category-checkbox-label {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    padding: 12px 14px;
    margin: 2px 0;
    cursor: pointer;
    border-radius: 6px;
    transition: all 0.3s ease;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid transparent;
    text-align: left;
}

.category-checkbox-label:hover {
    background: rgba(255, 215, 54, 0.15);
    border-color: rgba(255, 215, 54, 0.4);
    transform: translateX(4px);
}

.category-checkbox-label:has(.category-checkbox:checked) {
    background: linear-gradient(135deg, rgba(255, 215, 54, 0.25), rgba(255, 215, 54, 0.15));
    border-color: #FFD736;
    box-shadow: 0 2px 8px rgba(255, 215, 54, 0.3);
}

.category-checkbox {
    width: 20px !important;
    height: 20px !important;
    margin: 0 !important;
    cursor: pointer;
    accent-color: #FFD736;
    justify-self: start !important;
}

.category-name {
    color: #F9F9F9;
    font-size: 15px;
    font-weight: 500;
    letter-spacing: 0.3px;
    flex: 1;
    text-align: left;
}

.category-checkbox:checked + .category-name {
    font-weight: 700;
    color: #FFD736;
}

.orphan-categories-header {
    padding: 12px 16px;
    background: rgba(255, 215, 54, 0.08);
    border-left: 4px solid rgba(255, 215, 54, 0.5);
    margin-bottom: 8px;
}

.no-categories {
    color: #dc3545;
    padding: 20px;
    text-align: center;
    font-style: italic;
}

.category-selection-container::-webkit-scrollbar {
    width: 10px;
}

.category-selection-container::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.05);
    border-radius: 5px;
}

.category-selection-container::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #FFD736, #f0c419);
    border-radius: 5px;
    border: 2px solid rgba(255, 255, 255, 0.1);
}

.category-selection-container::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #f0c419, #FFD736);
    box-shadow: 0 0 6px rgba(255, 215, 54, 0.5);
}

body {
    background: linear-gradient(135deg, #130325 0%, #1a0a2e 100%);
    min-height: 100vh;
    color: #F9F9F9;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

h1 {
    color: #F9F9F9;
    font-size: 2.2em;
    margin-bottom: 30px;
    text-align: center;
    text-transform: uppercase;
    letter-spacing: 3px;
    border-bottom: 3px solid #FFD736;
    padding-bottom: 15px;
    font-weight: 700;
}

.product-management {
    max-width: 1400px;
    margin: 0 auto;
    padding: 30px 20px;
}

.edit-product-form {
    background: rgba(255, 255, 255, 0.08);
    border: 2px solid rgba(255, 215, 54, 0.3);
    border-radius: 16px;
    padding: 35px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.4);
    backdrop-filter: blur(15px);
}

.form-grid {
    display: grid;
    grid-template-columns: 1.2fr 0.8fr;
    gap: 40px;
    margin-bottom: 30px;
}

.form-left,
.form-right {
    display: flex;
    flex-direction: column;
}

.form-group {
    margin-bottom: 25px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 10px;
    font-weight: 600;
    color: #FFD736;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 14px;
    border: 2px solid rgba(255, 215, 54, 0.3);
    border-radius: 8px;
    font-size: 15px;
    transition: all 0.3s ease;
    background: rgba(255, 255, 255, 0.1);
    color: #F9F9F9;
    font-family: inherit;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #FFD736;
    box-shadow: 0 0 0 4px rgba(255, 215, 54, 0.2);
    background: rgba(255, 255, 255, 0.15);
}

.form-group textarea {
    min-height: 120px;
    resize: vertical;
}

.current-image-preview {
    width: 100%;
    margin-bottom: 20px;
    border: 2px solid #FFD736;
    border-radius: 12px;
    overflow: hidden;
}

.product-preview {
    width: 100%;
    height: 250px;
    object-fit: cover;
}

.upload-area {
    width: 100%;
    min-height: 200px;
    border: 3px dashed rgba(255, 215, 54, 0.5);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.05);
    cursor: pointer;
    transition: all 0.3s ease;
    overflow: hidden;
    position: relative;
}

.upload-area:hover {
    border-color: #FFD736;
    background: rgba(255, 215, 54, 0.1);
}

.upload-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 200px;
    color: #FFD736;
    text-align: center;
    padding: 20px;
}

.upload-placeholder svg {
    margin-bottom: 15px;
    opacity: 0.7;
}

.upload-placeholder p {
    font-size: 16px;
    font-weight: 600;
    margin: 10px 0 5px 0;
}

.upload-placeholder small {
    font-size: 13px;
    color: #bbb;
}

.image-preview {
    width: 100%;
    height: 200px;
    object-fit: cover;
    border-radius: 8px;
}

.form-help {
    display: block;
    margin-top: 8px;
    font-size: 12px;
    color: #bbb;
    font-style: italic;
}

.form-actions {
    display: flex;
    gap: 15px;
    padding-top: 20px;
    border-top: 1px solid rgba(255, 215, 54, 0.2);
}

.form-actions button,
.btn-cancel {
    flex: 1;
    padding: 16px 32px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 16px;
    font-weight: 700;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 1px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
}

.form-actions button[type="submit"] {
    background: linear-gradient(135deg, #FFD736 0%, #f0c419 100%);
    color: #130325;
}

.form-actions button[type="submit"]:hover {
    background: linear-gradient(135deg, #f0c419 0%, #FFD736 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(255, 215, 54, 0.4);
}

.btn-cancel {
    background: rgba(255, 255, 255, 0.1);
    color: #F9F9F9;
    border: 2px solid rgba(255, 255, 255, 0.3);
}

.btn-cancel:hover {
    background: rgba(255, 255, 255, 0.2);
    border-color: rgba(255, 255, 255, 0.5);
    transform: translateY(-2px);
}

#status {
    background: rgba(255, 255, 255, 0.1) !important;
    color: #F9F9F9;
}

#status option {
    background: #1a0a2e;
    color: #F9F9F9;
}

.success-message,
.error-message,
.warning-message {
    padding: 16px 24px;
    border-radius: 10px;
    margin: 20px 0;
    font-weight: 600;
    box-shadow: 0 4px 6px rgba(0,0,0,0.2);
}

.success-message {
    background: linear-gradient(135deg, #d4edda, #c3e6cb);
    color: #155724;
    border: 2px solid #28a745;
}

.error-message {
    background: linear-gradient(135deg, #f8d7da, #f5c6cb);
    color: #721c24;
    border: 2px solid #dc3545;
}

.warning-message {
    background: linear-gradient(135deg, #fff3cd, #ffeaa7);
    color: #856404;
    border: 2px solid #ffc107;
}

@media (max-width: 1024px) {
    .form-grid {
        grid-template-columns: 1fr;
        gap: 30px;
    }
}

@media (max-width: 768px) {
    .product-management {
        padding: 20px 10px;
    }
    
    .edit-product-form {
        padding: 25px 20px;
    }
    
    .form-row {
        grid-template-columns: 1fr;
        gap: 0;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    h1 {
        font-size: 1.6em;
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
            toast.style.transition = 'opacity 0.5s ease';
            toast.style.opacity = '0';
            setTimeout(function() { toast.remove(); }, 500);
        }, 3000);
    }
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

document.addEventListener('DOMContentLoaded', function() {
    const messages = document.querySelectorAll('.success-message, .error-message, .warning-message');
    
    messages.forEach(function(message) {
        setTimeout(function() {
            message.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            message.style.opacity = '0';
            message.style.transform = 'translateY(-20px)';
            
            setTimeout(function() {
                message.remove();
            }, 500);
        }, 4000);
    });
});
</script>
