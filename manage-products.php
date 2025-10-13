<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

requireSeller();

$userId = $_SESSION['user_id'];

// Handle product actions (add, edit, delete, toggle) - MUST BE BEFORE ANY OUTPUT
if (isset($_POST['add_product'])) {
    $name = sanitizeInput($_POST['name']);
    $description = sanitizeInput($_POST['description']);
    $price = floatval($_POST['price']);
    $categoryIds = isset($_POST['category_id']) ? $_POST['category_id'] : [];
    $categoryId = !empty($categoryIds) ? intval($categoryIds[0]) : 0;
    $stockQuantity = intval($_POST['stock_quantity']);
    
    $imageUrl = 'assets/uploads/tempo_image.jpg';
    
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
                $imageUrl = $uploadFile;
            }
        }
    }
    
    if (addProduct($name, $description, $price, $categoryId, $userId, $stockQuantity, $imageUrl)) {
        $_SESSION['product_message'] = ['type' => 'success', 'text' => 'Product added successfully!'];
    } else {
        $_SESSION['product_message'] = ['type' => 'error', 'text' => 'Error adding product.'];
    }
    header("Location: manage-products.php");
    exit();
}

if (isset($_GET['delete'])) {
    $productId = intval($_GET['delete']);
    
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND seller_id = ?");
    $stmt->execute([$productId, $userId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($product) {
        try {
            $pdo->beginTransaction();
            
            // Remove from cart first
            $stmt = $pdo->prepare("DELETE FROM cart WHERE product_id = ?");
            $stmt->execute([$productId]);
            
            // Delete the product completely
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            
            $pdo->commit();
            $_SESSION['product_message'] = ['type' => 'success', 'text' => 'Product deleted successfully!'];
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollback();
            }
            $_SESSION['product_message'] = ['type' => 'error', 'text' => 'Error deleting product: ' . $e->getMessage()];
        }
    }
    header("Location: manage-products.php");
    exit();
}

if (isset($_GET['toggle_status'])) {
    $productId = intval($_GET['toggle_status']);
    
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND seller_id = ?");
    $stmt->execute([$productId, $userId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($product) {
        $newStatus = $product['status'] == 'active' ? 'inactive' : 'active';
        $stmt = $pdo->prepare("UPDATE products SET status = ? WHERE id = ?");
        if ($stmt->execute([$newStatus, $productId])) {
            $statusText = $newStatus == 'active' ? 'activated' : 'deactivated';
            $_SESSION['product_message'] = ['type' => 'success', 'text' => "Product $statusText successfully!"];
        }
    }
    header("Location: manage-products.php");
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM categories WHERE seller_id IS NULL ORDER BY name");
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$activeProducts = getSellerActiveProducts($userId);
$inactiveProducts = getSellerInactiveProducts($userId);

// Now include the header after all redirects are handled
require_once 'includes/seller_header.php';
?>

<style>
html, body { background:#130325 !important; margin:0; padding:0; }
main { background:transparent !important; margin-left: 120px !important; padding: 20px 30px 60px 30px !important; min-height: calc(100vh - 60px) !important; transition: margin-left 0.3s ease; margin-top: -20px !important; }
main.sidebar-collapsed { margin-left: 0px !important; }
h1 { color:#F9F9F9 !important; font-family:var(--font-primary) !important; font-size:24px !important; font-weight:700 !important; text-align:left !important; margin:0 0 15px 0 !important; padding-left:20px !important; background:none !important; text-shadow:none !important; }

/* Action buttons styling */
.action-buttons {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.action-btn {
    width: 100%;
    padding: 10px 16px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.3s ease;
    text-align: center;
    text-decoration: none;
    display: inline-block;
    color: white !important;
}

.btn-edit {
    background: #007bff;
    color: white !important;
}

.btn-edit:hover {
    background: #0056b3;
    color: white !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,123,255,0.4);
}

.btn-toggle {
    background: #28a745;
    color: white !important;
}

.btn-toggle:hover {
    background: #1e7e34;
    color: white !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(40,167,69,0.4);
}

.btn-delete {
    background: #dc3545;
    color: white !important;
}

.btn-delete:hover {
    background: #c82333;
    color: white !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(220,53,69,0.4);
}

.notification-toast {
    position: fixed;
    top: 100px;
    right: 20px;
    max-width: 450px;
    min-width: 350px;
    background: linear-gradient(135deg, #1a0a2e 0%, #16213e 100%);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 16px;
    padding: 20px 24px;
    color: #F9F9F9;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.05);
    z-index: 10000;
    animation: slideInRight 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    backdrop-filter: blur(10px);
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
    background: linear-gradient(135deg, #0d2818 0%, #1a4d2e 100%);
    border-color: rgba(40, 167, 69, 0.3);
}

.notification-toast.success::before {
    background: linear-gradient(90deg, #28a745, #20c997);
}

.notification-toast.error {
    background: linear-gradient(135deg, #2d0d0d 0%, #4d1a1a 100%);
    border-color: rgba(220, 53, 69, 0.3);
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
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(5px);
}

.notification-toast.success .toast-icon {
    background: rgba(40, 167, 69, 0.2);
    color: #28a745;
}

.notification-toast.error .toast-icon {
    background: rgba(220, 53, 69, 0.2);
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
    color: #ffffff;
    line-height: 1.3;
}

.notification-toast .toast-message {
    font-size: 14px;
    margin: 0;
    color: rgba(249, 249, 249, 0.9);
    line-height: 1.4;
}

.notification-toast .toast-close {
    position: absolute;
    top: 12px;
    right: 12px;
    background: none;
    border: none;
    color: rgba(249, 249, 249, 0.6);
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
    background: rgba(255, 255, 255, 0.1);
    color: #ffffff;
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

@media (max-width: 768px) {
    .notification-toast {
        top: 80px;
        right: 10px;
        left: 10px;
        max-width: none;
        min-width: auto;
    }
}

.products-container {
    max-width: 1600px;
    margin: 0 auto;
    margin-top: -20px !important;
}

.products-container h1 {
    color: #F9F9F9 !important;
    font-family: var(--font-primary) !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    text-align: left !important;
    margin: 0 0 15px 0 !important;
    padding-left: 20px !important;
    background: none !important;
    text-shadow: none !important;
}

.products-container > p {
    color: #ffffff;
    text-align: center;
    opacity: 0.95;
    margin: 0 0 40px 0;
}

.add-product-card {
    background: #1a0a2e;
    border: 1px solid #2d1b4e;
    border-radius: 12px;
    padding: 30px;
    margin-bottom: 40px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}

.add-product-card h2 {
    color: #FFD736;
    margin: 0 0 25px 0;
    padding-bottom: 15px;
    border-bottom: 2px solid rgba(255,215,54,0.3);
    font-size: 24px;
}

.form-grid {
    display: grid;
    grid-template-columns: 1.2fr 0.8fr;
    gap: 30px;
    margin-bottom: 25px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    color: #FFD736;
    font-weight: 600;
    margin-bottom: 8px;
    font-size: 14px;
}

.form-group input,
.form-group textarea,
.form-group select {
    width: 100%;
    padding: 12px;
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,215,54,0.3);
    border-radius: 8px;
    color: #F9F9F9;
    font-size: 14px;
    transition: all 0.3s ease;
}

.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {
    outline: none;
    border-color: #FFD736;
    box-shadow: 0 0 0 3px rgba(255,215,54,0.2);
    background: rgba(255,255,255,0.15);
}

.form-group textarea {
    min-height: 100px;
    resize: vertical;
    font-family: inherit;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.upload-area {
    width: 100%;
    min-height: 280px;
    border: 2px dashed rgba(255,215,54,0.5);
    border-radius: 12px;
    background: rgba(255,255,255,0.05);
    cursor: pointer;
    transition: all 0.3s ease;
    overflow: hidden;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
}

.upload-area:hover {
    border-color: #FFD736;
    background: rgba(255,215,54,0.1);
}

.upload-placeholder {
    text-align: center;
    color: #FFD736;
    padding: 20px;
}

.upload-placeholder svg {
    margin-bottom: 10px;
    opacity: 0.7;
}

.upload-placeholder p {
    margin: 8px 0 4px 0;
    font-weight: 600;
}

.upload-placeholder small {
    color: #bbb;
    font-size: 12px;
}

.image-preview {
    width: 100%;
    height: 280px;
    object-fit: cover;
    border-radius: 8px;
}

.category-selection-container {
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid rgba(255,215,54,0.3);
    border-radius: 8px;
    padding: 8px;
    background: rgba(255,255,255,0.05);
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
}

/* Custom scrollbar for category container */
.category-selection-container::-webkit-scrollbar {
    width: 6px;
}

.category-selection-container::-webkit-scrollbar-track {
    background: rgba(255,255,255,0.1);
    border-radius: 3px;
}

.category-selection-container::-webkit-scrollbar-thumb {
    background: rgba(255,215,54,0.5);
    border-radius: 3px;
}

.category-selection-container::-webkit-scrollbar-thumb:hover {
    background: rgba(255,215,54,0.7);
}

.category-group {
    margin-bottom: 8px;
    border: 1px solid rgba(255,215,54,0.2);
    border-radius: 6px;
    background: rgba(255,255,255,0.02);
    overflow: hidden;
}

.parent-category {
    background: rgba(255,215,54,0.1);
    border-bottom: 1px solid rgba(255,215,54,0.2);
}

.parent-label {
    padding: 10px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    transition: background 0.2s ease;
    min-height: 18px;
    border-bottom: 1px solid rgba(255,215,54,0.2);
}

.parent-label:hover {
    background: rgba(255,215,54,0.15);
}

.parent-name {
    color: #FFD736;
    font-weight: 700;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    flex: 1;
    line-height: 1.2;
}

.toggle-children {
    color: #FFD736;
    transition: transform 0.3s ease;
    display: flex;
    align-items: center;
}

.toggle-children.rotated svg {
    transform: rotate(-90deg);
}

.child-categories {
    padding: 0;
    background: rgba(0,0,0,0.1);
    max-height: 500px;
    overflow: hidden;
    transition: max-height 0.3s ease;
    border-top: 1px solid rgba(255,215,54,0.1);
}

.child-categories.collapsed {
    max-height: 0;
}

.child-label {
    display: flex;
    align-items: center;
    padding: 8px 16px;
    margin: 0;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    transition: all 0.2s ease;
    cursor: pointer;
    min-height: 20px;
    position: relative;
    width: 100%;
}

.child-label:last-child {
    border-bottom: none;
}

.child-label:hover {
    background: rgba(255,215,54,0.08);
}

.child-label:has(.category-checkbox:checked) {
    background: rgba(255,215,54,0.12);
    border-left: 4px solid #FFD736;
}

.category-checkbox {
    width: 16px;
    height: 16px;
    cursor: pointer;
    accent-color: #FFD736;
    margin: 0 0 0 auto;
    flex-shrink: 0;
    border-radius: 3px;
}

.child-name {
    color: #F9F9F9;
    font-size: 13px;
    font-weight: 500;
    line-height: 1.2;
    margin-right: auto;
}

.form-actions {
    display: flex;
    gap: 15px;
    padding-top: 20px;
    border-top: 1px solid rgba(255,215,54,0.2);
}

.form-actions button {
    flex: 1;
    padding: 14px 24px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.form-actions button[type="submit"] {
    background: #FFD736;
    color: white;
}

.form-actions button[type="submit"]:hover:not(:disabled) {
    background: #e6c230;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(255,215,54,0.4);
}

.form-actions button[type="submit"]:disabled {
    background: #6c757d;
    color: white;
    cursor: not-allowed;
    opacity: 0.6;
}

.btn-reset {
    background: rgba(255,255,255,0.1);
    color: white;
    border: 1px solid rgba(255,255,255,0.3);
}

.btn-reset:hover {
    background: rgba(255,255,255,0.15);
}

.products-section {
    margin-top: 40px;
}

.products-section h2 {
    color: #FFD736;
    margin: 0 0 20px 0;
    padding-bottom: 12px;
    border-bottom: 2px solid rgba(255,215,54,0.3);
    font-size: 22px;
}

.table-wrapper {
    background: #1a0a2e;
    border: 1px solid #2d1b4e;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    margin-bottom: 30px;
}

.products-table {
    width: 100%;
    border-collapse: collapse;
}

.products-table thead {
    background: rgba(255,215,54,0.1);
    border-bottom: 2px solid #FFD736;
}

.products-table th {
    padding: 14px 12px;
    text-align: left;
    color: #FFD736;
    font-weight: 700;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.products-table td {
    padding: 14px 12px;
    color: #F9F9F9;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.products-table tbody tr {
    transition: all 0.2s ease;
}

.products-table tbody tr:hover {
    background: rgba(255,215,54,0.05);
}

.product-thumbnail {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border-radius: 8px;
    border: 2px solid #FFD736;
}

.status-badge {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}

.status-active {
    background: rgba(40,167,69,0.2);
    color: #28a745;
    border: 1px solid #28a745;
}

.status-inactive {
    background: rgba(220,53,69,0.2);
    color: #dc3545;
    border: 1px solid #dc3545;
}

.products-table a {
    color: #FFD736;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s ease;
}

.products-table a:hover {
    color: #fff;
}

.no-products {
    background: #1a0a2e;
    border: 1px solid #2d1b4e;
    color: #F9F9F9;
    border-radius: 8px;
    padding: 40px;
    text-align: center;
    opacity: 0.8;
}

@media (max-width: 1024px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    main { padding: 70px 15px 60px 15px !important; }
    .add-product-card { padding: 20px; }
    .form-row { grid-template-columns: 1fr; }
    .form-actions { flex-direction: column; }
    .products-table { font-size: 12px; }
}

/* Delete Modal Styles */
.delete-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.8);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 10000;
    backdrop-filter: blur(5px);
}

.delete-modal-content {
    background: #1a0a2e;
    border: 2px solid #dc3545;
    border-radius: 16px;
    max-width: 500px;
    width: 90%;
    box-shadow: 0 20px 60px rgba(220, 53, 69, 0.3);
    animation: modalSlideIn 0.3s ease-out;
}

.delete-modal-header {
    background: linear-gradient(135deg, #dc3545, #c82333);
    color: #ffffff;
    padding: 20px 25px;
    border-radius: 14px 14px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.delete-modal-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
}

.delete-modal-header h3 i {
    font-size: 20px;
}

.close-modal {
    background: none;
    border: none;
    color: #ffffff;
    font-size: 24px;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.2s ease;
}

.close-modal:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: scale(1.1);
}

.delete-modal-body {
    padding: 25px;
    color: #F9F9F9;
}

.delete-modal-body p {
    margin: 0 0 20px 0;
    font-size: 16px;
    line-height: 1.5;
}

.product-info {
    background: rgba(255, 215, 54, 0.1);
    border: 1px solid rgba(255, 215, 54, 0.3);
    border-radius: 8px;
    padding: 15px;
    margin: 20px 0;
    text-align: center;
}

.product-info strong {
    color: #FFD736;
    font-size: 18px;
    font-weight: 700;
}

.warning-message {
    background: rgba(220, 53, 69, 0.1);
    border: 1px solid rgba(220, 53, 69, 0.3);
    border-radius: 8px;
    padding: 15px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-top: 20px;
}

.warning-message i {
    color: #dc3545;
    font-size: 18px;
    margin-top: 2px;
    flex-shrink: 0;
}

.warning-message span {
    color: #F9F9F9;
    font-size: 14px;
    line-height: 1.4;
}

.delete-modal-footer {
    padding: 20px 25px;
    border-top: 1px solid #2d1b4e;
    display: flex;
    gap: 15px;
    justify-content: flex-end;
}

.btn-cancel, .btn-delete-confirm {
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
    min-width: 120px;
}

.btn-cancel {
    background: #6c757d;
    color: #ffffff;
}

.btn-cancel:hover {
    background: #5a6268;
    transform: translateY(-2px);
}

.btn-delete-confirm {
    background: linear-gradient(135deg, #dc3545, #c82333);
    color: #ffffff;
    box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
}

.btn-delete-confirm:hover {
    background: linear-gradient(135deg, #c82333, #bd2130);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4);
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: scale(0.8) translateY(-50px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}

@media (max-width: 768px) {
    .delete-modal-content {
        width: 95%;
        margin: 20px;
    }
    
    .delete-modal-footer {
        flex-direction: column;
    }
    
    .btn-cancel, .btn-delete-confirm {
        width: 100%;
    }
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

    <h1>Product Management</h1>

    <div class="add-product-card">
        <h2>Add New Product</h2>
        <form method="POST" action="" enctype="multipart/form-data">
            <div class="form-grid">
                <div class="form-left">
                    <div class="form-group">
                        <label for="product_name">Product Name</label>
                        <input type="text" id="product_name" name="name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="product_description">Description</label>
                        <textarea id="product_description" name="description" required></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="price">Price (₱)</label>
                            <input type="number" id="price" name="price" step="0.01" min="0" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="stock_quantity">Stock Quantity</label>
                            <input type="number" id="stock_quantity" name="stock_quantity" min="0" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Product Categories</label>
                        <div class="category-selection-container">
                            <?php if (empty($categories)): ?>
                                <p style="color: #dc3545; text-align: center; padding: 20px;">No categories available</p>
                            <?php else: ?>
                                <?php 
                                $groupedCats = [];
                                foreach ($categories as $cat) {
                                    if ($cat['parent_id'] === null) {
                                        $groupedCats[$cat['id']] = ['info' => $cat, 'children' => []];
                                    }
                                }
                                
                                foreach ($categories as $cat) {
                                    if ($cat['parent_id'] !== null && isset($groupedCats[$cat['parent_id']])) {
                                        $groupedCats[$cat['parent_id']]['children'][] = $cat;
                                    }
                                }
                                
                                foreach ($groupedCats as $parentId => $data): 
                                    $parent = $data['info'];
                                    $hasChildren = !empty($data['children']);
                                ?>
                                    <div class="category-group">
                                        <div class="parent-category">
                                            <div class="parent-label" onclick="toggleChildren(<?php echo $parent['id']; ?>, event)">
                                                <span class="parent-name"><?php echo html_entity_decode(htmlspecialchars(preg_replace('/[\x{1F600}-\x{1F64F}]|[\x{1F300}-\x{1F5FF}]|[\x{1F680}-\x{1F6FF}]|[\x{1F1E0}-\x{1F1FF}]|[\x{2600}-\x{26FF}]|[\x{2700}-\x{27BF}]/u', '', $parent['name'])), ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php if ($hasChildren): ?>
                                                    <span class="toggle-children" id="toggle-<?php echo $parent['id']; ?>">
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
                                                    <label class="child-label">
                                                        <input type="checkbox" name="category_id[]" value="<?php echo $child['id']; ?>" class="category-checkbox">
                                                        <span class="child-name"><?php echo html_entity_decode(htmlspecialchars(preg_replace('/[\x{1F600}-\x{1F64F}]|[\x{1F300}-\x{1F5FF}]|[\x{1F680}-\x{1F6FF}]|[\x{1F1E0}-\x{1F1FF}]|[\x{2600}-\x{26FF}]|[\x{2700}-\x{27BF}]/u', '', $child['name'])), ENT_QUOTES, 'UTF-8'); ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="form-right">
                    <div class="form-group">
                        <label>Product Image</label>
                        <div class="upload-area" id="upload-area" onclick="document.getElementById('image').click()">
                            <div class="upload-placeholder" id="upload-placeholder">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                    <polyline points="21 15 16 10 5 21"></polyline>
                                </svg>
                                <p>Click to upload</p>
                                <small>PNG, JPG, GIF up to 10MB</small>
                            </div>
                            <img id="image-preview" src="" alt="Preview" class="image-preview" style="display: none;">
                        </div>
                        <input type="file" id="image" name="image" accept="image/*" onchange="previewImage(event)" style="display: none;">
                    </div>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" name="add_product" <?php echo empty($categories) ? 'disabled' : ''; ?>>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    Add Product
                </button>
                <button type="reset" class="btn-reset" onclick="resetForm()">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="1 4 1 10 7 10"></polyline>
                        <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
                    </svg>
                    Reset
                </button>
            </div>
        </form>
    </div>
    
    <div class="products-section">
        <h2>Active Products</h2>
        <?php if (empty($activeProducts)): ?>
            <div class="no-products">No active products found.</div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="products-table">
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Name</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeProducts as $product): ?>
                            <tr>
                                <td>
                                    <img src="<?php echo htmlspecialchars($product['image_url']); ?>" 
                                         alt="<?php echo htmlspecialchars($product['name']); ?>" 
                                         class="product-thumbnail">
                                </td>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td>₱<?php echo number_format($product['price'], 2); ?></td>
                                <td><?php echo $product['stock_quantity']; ?></td>
                                <td><span class="status-badge status-active">Active</span></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="edit-product.php?id=<?php echo $product['id']; ?>" class="action-btn btn-edit">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <a href="manage-products.php?toggle_status=<?php echo $product['id']; ?>" class="action-btn btn-toggle">
                                            <i class="fas fa-pause"></i> Deactivate
                                        </a>
                                        <a href="#" class="action-btn btn-delete" onclick="showDeleteModal(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>', 'active'); return false;">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
        <h2>Inactive Products</h2>
        <?php if (empty($inactiveProducts)): ?>
            <div class="no-products">No inactive products found.</div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="products-table">
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Name</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inactiveProducts as $product): ?>
                            <tr>
                                <td>
                                    <img src="<?php echo htmlspecialchars($product['image_url']); ?>" 
                                         alt="<?php echo htmlspecialchars($product['name']); ?>" 
                                         class="product-thumbnail">
                                </td>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td>₱<?php echo number_format($product['price'], 2); ?></td>
                                <td><?php echo $product['stock_quantity']; ?></td>
                                <td><span class="status-badge status-inactive">Inactive</span></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="edit-product.php?id=<?php echo $product['id']; ?>" class="action-btn btn-edit">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <a href="manage-products.php?toggle_status=<?php echo $product['id']; ?>" class="action-btn btn-toggle">
                                            <i class="fas fa-play"></i> Activate
                                        </a>
                                        <a href="#" class="action-btn btn-delete" onclick="showDeleteModal(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>', 'inactive'); return false;">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="delete-modal" style="display: none;">
    <div class="delete-modal-content">
        <div class="delete-modal-header">
            <h3><i class="fas fa-exclamation-triangle"></i> Delete Product</h3>
            <button class="close-modal" onclick="hideDeleteModal()">&times;</button>
        </div>
        <div class="delete-modal-body">
            <p>Are you sure you want to delete this product?</p>
            <div class="product-info">
                <strong id="productNameToDelete"></strong>
            </div>
            <div class="warning-message">
                <i class="fas fa-warning"></i>
                <span>This action cannot be undone. The product will be permanently removed from your store.</span>
            </div>
        </div>
        <div class="delete-modal-footer">
            <button class="btn-cancel" onclick="hideDeleteModal()">Cancel</button>
            <button class="btn-delete-confirm" onclick="confirmDelete()">Delete Product</button>
        </div>
    </div>
</div>

</main>

<script>
function toggleChildren(parentId, event) {
    event.stopPropagation();
    const childContainer = document.getElementById('children_' + parentId);
    const toggleIcon = document.getElementById('toggle-' + parentId);
    
    if (childContainer.classList.contains('collapsed')) {
        childContainer.classList.remove('collapsed');
        toggleIcon.classList.add('rotated');
    } else {
        childContainer.classList.add('collapsed');
        toggleIcon.classList.remove('rotated');
    }
}

function previewImage(event) {
    const reader = new FileReader();
    const imagePreview = document.getElementById('image-preview');
    const uploadPlaceholder = document.getElementById('upload-placeholder');
    
    reader.onload = function() {
        if (reader.readyState == 2) {
            imagePreview.src = reader.result;
            imagePreview.style.display = 'block';
            uploadPlaceholder.style.display = 'none';
        }
    }
    
    if (event.target.files[0]) {
        reader.readAsDataURL(event.target.files[0]);
    }
}

function resetForm() {
    document.getElementById('image-preview').style.display = 'none';
    document.getElementById('upload-placeholder').style.display = 'flex';
}

document.addEventListener('DOMContentLoaded', function() {
    // Enhanced notification handling
    const toast = document.querySelector('.notification-toast');
    if (toast) {
        // Auto-hide after 5 seconds with smooth animation
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

    // Form validation
    const form = document.querySelector('form[method="POST"]');
    const submitButton = form.querySelector('button[name="add_product"]');
    const productName = document.getElementById('product_name');
    const price = document.getElementById('price');
    const stockQuantity = document.getElementById('stock_quantity');
    const categoryCheckboxes = document.querySelectorAll('.category-checkbox');
    
    function validateForm() {
        const nameValid = productName.value.trim() !== '';
        const priceValid = price.value.trim() !== '' && parseFloat(price.value) >= 0;
        const stockValid = stockQuantity.value.trim() !== '' && parseInt(stockQuantity.value) >= 0;
        
        let categorySelected = false;
        categoryCheckboxes.forEach(function(checkbox) {
            if (checkbox.checked) {
                categorySelected = true;
            }
        });
        
        if (nameValid && priceValid && stockValid && categorySelected) {
            submitButton.disabled = false;
            submitButton.style.opacity = '1';
            submitButton.style.cursor = 'pointer';
        } else {
            submitButton.disabled = true;
            submitButton.style.opacity = '0.6';
            submitButton.style.cursor = 'not-allowed';
        }
    }
    
    validateForm();
    
    productName.addEventListener('input', validateForm);
    price.addEventListener('input', validateForm);
    stockQuantity.addEventListener('input', validateForm);
    
    categoryCheckboxes.forEach(function(checkbox) {
        checkbox.addEventListener('change', validateForm);
    });
});

// Delete Modal Functions
let productToDelete = null;
let productStatus = null;

function showDeleteModal(productId, productName, status) {
    productToDelete = productId;
    productStatus = status;
    document.getElementById('productNameToDelete').textContent = productName;
    
    // Always show delete modal since delete button will actually delete
    const modalHeader = document.querySelector('.delete-modal-header h3');
    const modalBody = document.querySelector('.delete-modal-body p');
    const warningMessage = document.querySelector('.warning-message span');
    const deleteButton = document.querySelector('.btn-delete-confirm');
    
    modalHeader.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Delete Product';
    modalBody.textContent = 'Are you sure you want to permanently delete this product?';
    warningMessage.textContent = 'This action cannot be undone. The product will be permanently removed from your store and any cart items will be removed.';
    deleteButton.textContent = 'Delete Product';
    deleteButton.style.background = 'linear-gradient(135deg, #dc3545, #c82333)';
    
    document.getElementById('deleteModal').style.display = 'flex';
    
    // Add body scroll lock
    document.body.style.overflow = 'hidden';
    
    // Focus on cancel button for accessibility
    setTimeout(() => {
        document.querySelector('.btn-cancel').focus();
    }, 100);
}

function hideDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
    productToDelete = null;
    productStatus = null;
    
    // Remove body scroll lock
    document.body.style.overflow = '';
}

function confirmDelete() {
    if (productToDelete) {
        // Show loading state
        const deleteBtn = document.querySelector('.btn-delete-confirm');
        deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
        deleteBtn.disabled = true;
        
        // Redirect to delete URL
        window.location.href = 'manage-products.php?delete=' + productToDelete;
    }
}

// Close modal when clicking outside
document.addEventListener('click', function(event) {
    const modal = document.getElementById('deleteModal');
    if (event.target === modal) {
        hideDeleteModal();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        hideDeleteModal();
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
