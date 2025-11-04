<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

requireSeller();

$userId = $_SESSION['user_id'];

// Handle product status toggle
if (isset($_GET['toggle_status'])) {
    $productId = intval($_GET['toggle_status']);
    
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND seller_id = ?");
    $stmt->execute([$productId, $userId]);
    $product = $stmt->fetch();
    
    if ($product) {
        // Prevent sellers from toggling products that were suspended or rejected by admin
        if (in_array($product['status'], ['suspended', 'rejected'])) {
            $_SESSION['product_message'] = ['type' => 'error', 'text' => "Cannot toggle product status. This product was {$product['status']} by admin and requires admin approval to reactivate."];
        } else {
            $newStatus = $product['status'] == 'active' ? 'inactive' : 'active';
            $stmt = $pdo->prepare("UPDATE products SET status = ? WHERE id = ?");
            if ($stmt->execute([$newStatus, $productId])) {
                $statusText = $newStatus == 'active' ? 'activated' : 'deactivated';
                $_SESSION['product_message'] = ['type' => 'success', 'text' => "Product $statusText successfully!"];
            }
        }
    }
    header("Location: manage-products.php");
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
            
            // Check if product has orders - ensure we get an integer
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM order_items WHERE product_id = ?");
            $stmt->execute([$productId]);
            $orderResult = $stmt->fetch(PDO::FETCH_ASSOC);
            $orderItemsCount = intval($orderResult['count'] ?? 0);
            
            // Check if product is in cart
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM cart WHERE product_id = ?");
            $stmt->execute([$productId]);
            $cartResult = $stmt->fetch(PDO::FETCH_ASSOC);
            $cartCount = intval($cartResult['count'] ?? 0);
            
            // Only prevent deletion if there are actual completed/pending orders (not cancelled)
            // Check for orders that are not cancelled
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM order_items oi 
                INNER JOIN orders o ON oi.order_id = o.id 
                WHERE oi.product_id = ? AND o.status != 'cancelled'
            ");
            $stmt->execute([$productId]);
            $activeOrderResult = $stmt->fetch(PDO::FETCH_ASSOC);
            $activeOrderCount = intval($activeOrderResult['count'] ?? 0);
            
            if ($activeOrderCount > 0) {
                // Product has orders - cannot delete, only deactivate
                $stmt = $pdo->prepare("UPDATE products SET status = 'inactive' WHERE id = ?");
                $stmt->execute([$productId]);
                
                // Remove from cart if present
                if ($cartCount > 0) {
                    $stmt = $pdo->prepare("DELETE FROM cart WHERE product_id = ?");
                    $stmt->execute([$productId]);
                }
                
                $pdo->commit();
                $_SESSION['product_message'] = ['type' => 'success', 'text' => 'Product deactivated successfully. Cannot delete product with existing active orders.'];
            } else {
                // No orders - safe to delete
                // First, delete from cart if present
                if ($cartCount > 0) {
                    $stmt = $pdo->prepare("DELETE FROM cart WHERE product_id = ?");
                    $stmt->execute([$productId]);
                }
                
                // Delete from product_categories if table exists
                try {
                    $stmt = $pdo->prepare("DELETE FROM product_categories WHERE product_id = ?");
                    $stmt->execute([$productId]);
                } catch (PDOException $e) {
                    // Table might not exist, ignore
                }
                
                // Delete from reviews if table exists
                try {
                    $stmt = $pdo->prepare("DELETE FROM reviews WHERE product_id = ?");
                    $stmt->execute([$productId]);
                } catch (PDOException $e) {
                    // Table might not exist, ignore
                }
                
                // Delete from wishlist if table exists
                try {
                    $stmt = $pdo->prepare("DELETE FROM wishlist WHERE product_id = ?");
                    $stmt->execute([$productId]);
                } catch (PDOException $e) {
                    // Table might not exist, ignore
                }
                
                // Delete the product
                $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
                $result = $stmt->execute([$productId]);
                
                if ($result && $stmt->rowCount() > 0) {
                    $pdo->commit();
                    $_SESSION['product_message'] = ['type' => 'success', 'text' => 'Product deleted successfully!'];
                } else {
                    $pdo->rollback();
                    $_SESSION['product_message'] = ['type' => 'error', 'text' => 'Failed to delete product. The product may have existing dependencies.'];
                }
            }
            
        } catch (Exception $e) {
            $pdo->rollback();
            $_SESSION['product_message'] = ['type' => 'error', 'text' => 'Error deleting product: ' . $e->getMessage()];
        }
    } else {
        $_SESSION['product_message'] = ['type' => 'error', 'text' => 'Product not found or you do not have permission to delete it.'];
    }
    header("Location: manage-products.php");
    exit();
}

// Handle product actions (add, edit, delete, toggle) - MUST BE BEFORE ANY OUTPUT
if (isset($_POST['add_product'])) {
    $name = sanitizeInput($_POST['name']);
    $description = sanitizeInput($_POST['description']);
    $price = floatval($_POST['price']);
    $categoryIds = isset($_POST['category_id']) ? $_POST['category_id'] : [];
    // Sanitize category IDs
    $categoryIds = array_filter(array_map('intval', $categoryIds));
    $categoryId = !empty($categoryIds) ? intval($categoryIds[0]) : 0;
    $stockQuantity = intval($_POST['stock_quantity']);
    // New products should be pending for admin approval
    $status = 'pending';
    
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
    
    if (addProduct($name, $description, $price, $categoryId, $userId, $stockQuantity, $imageUrl, $status, $categoryIds)) {
        $_SESSION['product_message'] = ['type' => 'success', 'text' => 'Product added successfully!'];
    } else {
        $_SESSION['product_message'] = ['type' => 'error', 'text' => 'Error adding product.'];
    }
    header("Location: manage-products.php");
    exit();
}


$stmt = $pdo->prepare("SELECT * FROM categories WHERE seller_id IS NULL ORDER BY name");
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch seller's products for the table (sorted by created_at DESC by default, client-side will handle sorting)
$productsStmt = $pdo->prepare("SELECT * FROM products WHERE seller_id = ? ORDER BY created_at DESC");
$productsStmt->execute([$userId]);
$sellerProducts = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

// Now include the header after all redirects are handled
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
    margin-top: -20px !important;
    margin-bottom: 0 !important;
    padding-top: 5px !important;
    padding-bottom: 40px !important;
    padding-left: 30px !important;
    padding-right: 30px !important;
    min-height: calc(100vh - 60px) !important; 
    transition: margin-left 0.3s ease !important;
}
main.sidebar-collapsed { margin-left: 0px !important; }

h1 { 
    color: #130325 !important; 
    font-size: 20px !important; 
    font-weight: 700 !important; 
    margin: 0 !important;
    margin-bottom: 16px !important;
    padding: 0 !important; 
    text-shadow: none !important;
}


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
}

.products-container h1 {
    font-size: 20px;
    font-weight: 700;
    margin: 0 0 16px 0;
    color: #130325;
    text-shadow: none !important;
}

.add-product-card {
    background: #ffffff;
    border: 1px solid rgba(0,0,0,0.1);
    border-radius: 8px;
    padding: 32px;
    margin-bottom: 0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.add-product-card h2 {
    color: #130325;
    margin: 0 0 16px 0;
    padding-bottom: 12px;
    border-bottom: 1px solid rgba(0,0,0,0.1);
    font-size: 16px;
    font-weight: 600;
    text-shadow: none !important;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 24px;
    margin-bottom: 0;
}

.form-left {
    display: flex;
    flex-direction: column;
    gap: 0;
}

.form-right {
    display: flex;
    flex-direction: column;
    background: #ffffff;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    padding: 20px;
    height: fit-content;
    position: sticky;
    top: 20px;
}

.form-right .form-group {
    margin-bottom: 24px;
}

.form-right .form-group:last-child {
    margin-bottom: 0;
}

.pricing-section {
    padding-bottom: 20px;
    border-bottom: 1px solid rgba(19, 3, 37, 0.1);
    margin-bottom: 20px;
}

.pricing-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.pricing-input-wrapper::before {
    content: '₱';
    position: absolute;
    left: 12px;
    color: #130325;
    font-weight: 600;
    font-size: 15px;
    z-index: 1;
}

.pricing-input-wrapper input {
    padding-left: 32px !important;
    padding-right: 14px;
    padding-top: 10px;
    padding-bottom: 10px;
    background: #ffffff;
    border: 1.5px solid rgba(19, 3, 37, 0.15);
    border-radius: 6px;
    color: #130325;
    font-size: 15px;
    font-weight: 600;
    transition: all 0.2s ease;
}

.pricing-input-wrapper input:focus {
    outline: none;
    border-color: #130325;
    box-shadow: 0 0 0 3px rgba(19, 3, 37, 0.1);
}

.availability-section, .stock-section {
    margin-bottom: 20px;
    padding-bottom: 20px;
    border-bottom: 1px solid rgba(19, 3, 37, 0.1);
}

.availability-section:last-of-type, .stock-section:last-of-type {
    margin-bottom: 0;
    padding-bottom: 0;
    border-bottom: none;
}

.availability-section label {
    display: flex;
    align-items: center;
    gap: 8px;
}

.status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-badge.active {
    background: rgba(40, 167, 69, 0.15);
    color: #28a745;
    border: 1px solid rgba(40, 167, 69, 0.3);
}

.status-badge.inactive {
    background: rgba(220, 53, 69, 0.15);
    color: #dc3545;
    border: 1px solid rgba(220, 53, 69, 0.3);
}

.toggle-switch-container {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 8px;
}

.toggle-switch {
    position: relative;
    display: inline-block;
    width: 38px;
    height: 20px;
}

.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .3s;
    border-radius: 20px;
}

.toggle-slider:before {
    position: absolute;
    content: "";
    height: 14px;
    width: 14px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}

.toggle-switch input:checked + .toggle-slider {
    background-color: #28a745;
}

.toggle-switch input:checked + .toggle-slider:before {
    transform: translateX(18px);
}

.toggle-label {
    color: #130325;
    font-size: 12px;
    font-weight: 600;
}

.stock-section label,
.pricing-section label {
    font-weight: 600 !important;
    color: #130325 !important;
    font-size: 14px !important;
    margin-bottom: 10px !important;
    display: block;
}

.stock-section input[type="number"] {
    padding: 8px 12px !important;
    border: 1.5px solid rgba(19, 3, 37, 0.15) !important;
    border-radius: 6px !important;
    background: #ffffff !important;
    color: #130325 !important;
    font-size: 15px !important;
    font-weight: 600 !important;
    transition: all 0.2s ease !important;
    width: 100px !important;
    margin-left: 10px !important;
}

.stock-section input[type="number"]:focus {
    outline: none !important;
    border-color: #130325 !important;
    box-shadow: 0 0 0 3px rgba(19, 3, 37, 0.1) !important;
}

.stock-section > div {
    display: flex;
    align-items: center;
    gap: 8px;
}

.stock-section > div > span {
    color: #130325;
    font-size: 14px;
    font-weight: 500;
}

.action-link {
    color: #007bff;
    font-size: 13px;
    text-decoration: none;
    cursor: pointer;
}

.action-link:hover {
    text-decoration: underline;
}

.preview-button {
    width: auto;
    padding: 8px 16px;
    background: #FFD736;
    color: #130325;
    border: none;
    border-radius: 4px;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    margin-top: 20px;
    transition: background 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.preview-button:hover {
    background: #f5d026;
}

/* Preview Modal */
.preview-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    z-index: 10000;
    overflow-y: auto;
    padding: 20px;
}

.preview-modal.active {
    display: block;
}

.preview-container {
    max-width: 1000px;
    margin: 20px auto;
    background: #f0f2f5;
    border-radius: 8px;
    position: relative;
    animation: previewSlideIn 0.3s ease;
}

@keyframes previewSlideIn {
    from {
        opacity: 0;
        transform: scale(0.95) translateY(-20px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}

.preview-close {
    position: absolute;
    top: 15px;
    right: 15px;
    background: transparent;
    border: none;
    color: #130325;
    font-size: 28px;
    cursor: pointer;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.2s ease;
    z-index: 10;
    background: rgba(255, 255, 255, 0.9);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

.preview-close:hover {
    background: rgba(255, 255, 255, 1);
    transform: scale(1.1);
}

.preview-content {
    padding: 20px;
}

/* Copy product-detail styles but scaled down */
.preview-product-main {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
}

.preview-image-gallery {
    flex: 0 0 200px;
}

.preview-main-image-container {
    background: #ffffff;
    border: 2px solid #666666;
    padding: 10px;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 200px;
    max-width: 200px;
}

.preview-main-image {
    max-width: 100%;
    max-height: 200px;
    width: auto;
    height: auto;
    object-fit: contain;
}

.preview-product-info-section {
    background: #ffffff;
    padding: 20px;
    display: flex;
    gap: 20px;
    align-items: flex-start;
    flex: 1;
}

.preview-product-details {
    flex: 1;
}

.preview-product-title {
    font-size: 20px;
    font-weight: 900;
    color: #130325;
    margin-bottom: 12px;
    line-height: 1.2;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    text-shadow: none !important;
}

.preview-rating-section {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    padding-bottom: 12px;
    border-bottom: 1px solid rgba(19, 3, 37, 0.2);
}

.preview-stars {
    color: #FFD736;
    font-size: 14px;
    letter-spacing: 1px;
}

.preview-rating-text {
    font-size: 11px;
    color: #130325;
}

.preview-price-section {
    background: rgba(255, 215, 54, 0.1);
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 12px;
    border: 1px solid rgba(255, 215, 54, 0.3);
}

.preview-price-label {
    font-size: 11px;
    color: rgba(19, 3, 37, 0.6);
    margin-bottom: 4px;
}

.preview-price-amount {
    font-size: 20px;
    font-weight: 900;
    color: #130325;
    line-height: 1;
}

.preview-info-grid {
    display: grid;
    gap: 8px;
    margin-bottom: 15px;
}

.preview-info-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    background: rgba(255, 255, 255, 0.5);
    border-radius: 6px;
    border: 1px solid rgba(19, 3, 37, 0.1);
}

.preview-info-icon {
    color: #FFD736;
    font-size: 12px;
    width: 16px;
    text-align: center;
}

.preview-info-label {
    color: rgba(19, 3, 37, 0.7);
    font-size: 10px;
    margin-right: 4px;
}

.preview-info-value {
    color: #130325;
    font-weight: 600;
    font-size: 10px;
}

.preview-stock-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
}

.preview-stock-in {
    background: #d4edda;
    color: #155724;
}

.preview-stock-low {
    background: #fff3cd;
    color: #856404;
}

.preview-stock-out {
    background: #f8d7da;
    color: #721c24;
}

.preview-quantity-section {
    margin: 15px 0;
    padding: 12px;
    background: rgba(255, 255, 255, 0.5);
    border-radius: 8px;
    border: 1px solid rgba(19, 3, 37, 0.1);
}

.preview-quantity-selector {
    display: flex;
    align-items: center;
    gap: 12px;
}

.preview-quantity-label {
    font-size: 11px;
    color: #130325;
    font-weight: 600;
}

.preview-quantity-control {
    display: flex;
    align-items: center;
    border: 1px solid #130325;
    border-radius: 6px;
    overflow: hidden;
}

.preview-qty-btn {
    background: #f8f9fa;
    border: none;
    padding: 6px 10px;
    cursor: pointer;
    font-size: 14px;
    font-weight: bold;
    color: #130325;
    transition: background 0.2s;
}

.preview-qty-btn:hover {
    background: #e9ecef;
}

.preview-qty-input {
    border: none;
    padding: 6px 10px;
    text-align: center;
    width: 50px;
    font-size: 12px;
    font-weight: 600;
    color: #130325;
    background: white;
}

.preview-stock-status {
    font-size: 10px;
    color: #28a745;
    font-weight: 600;
    background: rgba(40, 167, 69, 0.1);
    padding: 3px 6px;
    border-radius: 4px;
}

.preview-action-buttons {
    display: flex;
    gap: 8px;
    margin-top: 15px;
}

.preview-btn-add-to-cart {
    background: #FFD736;
    color: #130325;
    border: 2px solid #130325;
    padding: 10px 14px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-width: 120px;
    width: auto;
}

.preview-btn-add-to-cart:hover {
    background: #130325;
    color: #FFD736;
}

.preview-btn-buy-now {
    background: #130325;
    color: white;
    border: none;
    padding: 10px 16px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-width: 100px;
    width: auto;
}

.preview-btn-buy-now:hover {
    background: #FFD736;
    color: #130325;
}

.preview-product-details-section {
    background: #ffffff;
    padding: 20px;
    margin-bottom: 20px;
}

.preview-product-category h3,
.preview-product-description h3 {
    font-size: 16px;
    font-weight: 600;
    color: #130325;
    margin-bottom: 10px;
    text-shadow: none !important;
}

.preview-product-category p {
    font-size: 14px;
    color: #666;
}

.preview-description-content {
    font-size: 14px;
    color: #666;
    line-height: 1.6;
    white-space: pre-wrap;
    word-wrap: break-word;
    margin-bottom: 10px;
}

.preview-product-description {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid rgba(0, 0, 0, 0.1);
}

.form-group {
    margin-bottom: 24px;
}

.form-group:last-child {
    margin-bottom: 0;
}

.form-group label {
    display: block;
    color: #130325;
    font-weight: 500;
    margin-bottom: 8px;
    font-size: 14px;
    text-shadow: none !important;
}

.form-group input,
.form-group textarea,
.form-group select {
    width: 100%;
    padding: 12px 14px;
    background: #ffffff;
    border: 1px solid #ddd;
    border-radius: 4px;
    color: #130325;
    font-size: 14px;
    transition: all 0.2s ease;
    font-family: inherit;
}

.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {
    outline: none;
    border-color: #FFD736;
    box-shadow: 0 0 0 2px rgba(255,215,54,0.2);
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

/* Products Table Styles */
.products-table-container {
    background: #ffffff;
    border: 1px solid rgba(0,0,0,0.1);
    border-radius: 8px;
    padding: 24px;
    margin-top: 30px;
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

.product-thumb {
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

.image-upload-section {
    margin-bottom: 24px;
}

.main-image-upload {
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

.main-image-upload:hover {
    border-color: #130325;
    background: #f0f2f5;
    border-style: solid;
}

.main-image-upload .upload-placeholder {
    text-align: center;
    color: #130325;
    padding: 40px 20px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.main-image-upload .upload-placeholder svg {
    width: 48px;
    height: 48px;
    margin-bottom: 12px;
    color: #130325;
}

.main-image-upload .upload-placeholder p {
    margin: 0 0 4px 0;
    font-weight: 500;
    font-size: 15px;
    color: #130325;
}

.main-image-upload .upload-placeholder small {
    color: #666;
    font-size: 13px;
}

.image-preview {
    width: 100%;
    height: 320px;
    object-fit: cover;
    border-radius: 4px;
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

/* FIXED CATEGORY CHECKBOX POSITIONING */

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

/* Category name on the LEFT */
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

/* Checkbox on the RIGHT */
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

.form-actions {
    display: flex;
    gap: 10px;
    padding-top: 24px;
    margin-top: 24px;
    border-top: 1px solid #e0e0e0;
    justify-content: flex-start;
}

.form-actions button {
    padding: 8px 16px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    font-family: inherit;
}

.form-actions button[type="submit"] {
    background: #FFD736;
    color: #130325;
}

.form-actions button[type="submit"]:hover:not(:disabled) {
    background: #f5d026;
}

.form-actions button[type="submit"]:active:not(:disabled) {
    background: #e6c230;
}

.form-actions button[type="submit"]:disabled {
    background: #e0e0e0;
    color: #999;
    cursor: not-allowed;
}

.btn-reset {
    background: #dc3545;
    color: #ffffff;
    border: 1px solid #dc3545;
}

.btn-reset:hover {
    background: #c82333;
    border-color: #c82333;
}


@media (max-width: 1024px) {
    .form-grid {
        grid-template-columns: 1fr;
        gap: 32px;
    }
}

@media (max-width: 768px) {
    main { padding: 30px 24px 60px 24px !important; }
    .add-product-card { 
        padding: 32px 24px; 
        border-radius: 12px;
    }
    .form-row { grid-template-columns: 1fr; }
    .form-actions { 
        flex-direction: column; 
        gap: 10px;
    }
    h1 {
        font-size: 18px;
        margin-bottom: 16px;
    }
    .add-product-card h2 {
        font-size: 14px;
        margin-bottom: 16px;
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
    background: #ffffff;
    border-radius: 12px;
    padding: 0;
    width: 90%;
    max-width: 400px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    animation: slideDown 0.3s ease;
    overflow: hidden;
}

.custom-confirm-header {
    background: #130325;
    color: #ffffff;
    padding: 16px 20px;
    border-radius: 12px 12px 0 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.custom-confirm-title { 
    color: #ffffff; 
    font-weight: 700; 
    font-size: 14px; 
    margin: 0; 
    text-transform: none; 
    letter-spacing: normal; 
}

.custom-confirm-message { 
    color: #130325; 
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
    border-top: 1px solid #e5e7eb;
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
    color: #130325; 
    border: 1px solid #e5e7eb;
}

.custom-confirm-btn.cancel:hover { 
    background: #e5e7eb; 
}

.custom-confirm-btn.primary { 
    background: #130325; 
    color: #ffffff; 
}

.custom-confirm-btn.primary:hover { 
    background: #0a0218; 
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
                    <div class="image-upload-section">
                        <div class="main-image-upload" id="upload-area" onclick="document.getElementById('image').click()">
                            <div class="upload-placeholder" id="upload-placeholder">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                    <polyline points="21 15 16 10 5 21"></polyline>
                                </svg>
                                <p>Upload Images</p>
                            </div>
                            <img id="image-preview" src="" alt="Preview" class="image-preview" style="display: none;">
                        </div>
                        <input type="file" id="image" name="image" accept="image/*" onchange="previewImage(event)" style="display: none;">
                    </div>
                    
                    <div class="form-group">
                        <label for="product_name">Name</label>
                        <input type="text" id="product_name" name="name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="product_description">Description</label>
                        <textarea id="product_description" name="description" required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Product Categories</label>
                        <div class="category-selection-container">
                            <?php if (empty($categories)): ?>
                                <p style="color: #130325; text-align: center; padding: 20px;">No categories available</p>
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
                                                <span class="parent-name"><?php 
                                                    $cleanName = preg_replace('/[\x{1F300}-\x{1F9FF}]|[\x{2600}-\x{26FF}]|[\x{2700}-\x{27BF}]|[\x{FE0F}]|[\x{200D}]|[\x{1F600}-\x{1F64F}]|[\x{1F1E0}-\x{1F1FF}]/u', '', $parent['name']);
                                                    echo html_entity_decode(htmlspecialchars(trim($cleanName)), ENT_QUOTES, 'UTF-8'); 
                                                ?></span>
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
                                                        <span class="child-name"><?php 
                                                            $cleanChildName = preg_replace('/[\x{1F300}-\x{1F9FF}]|[\x{2600}-\x{26FF}]|[\x{2700}-\x{27BF}]|[\x{FE0F}]|[\x{200D}]|[\x{1F600}-\x{1F64F}]|[\x{1F1E0}-\x{1F1FF}]/u', '', $child['name']);
                                                            echo html_entity_decode(htmlspecialchars(trim($cleanChildName)), ENT_QUOTES, 'UTF-8'); 
                                                        ?></span>
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
                    <div class="pricing-section">
                        <label>Pricing</label>
                        <div class="pricing-input-wrapper">
                            <input type="number" id="price" name="price" step="0.01" min="0" required placeholder="0.00">
                        </div>
                    </div>
                    
                    <div class="availability-section">
                        <label>
                            Availability 
                            <span class="status-badge" id="availability-status-badge">Active</span>
                        </label>
                        <div class="toggle-switch-container">
                            <label class="toggle-switch">
                                <input type="checkbox" id="product_status" name="product_status" checked>
                                <span class="toggle-slider"></span>
                            </label>
                            <span class="toggle-label" id="toggle-label">Active</span>
                        </div>
                    </div>
                    
                    <div class="stock-section">
                        <label>Stock Control</label>
                        <div>
                            <span>In Stock</span>
                            <input type="number" id="stock_quantity" name="stock_quantity" min="0" required placeholder="0">
                        </div>
                    </div>
                    
                    <button type="button" class="preview-button" onclick="showPreview()">
                        <i class="fas fa-eye"></i>
                        Preview product
                    </button>
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
</div>

<!-- Products Table -->
<div class="products-table-container">
    <?php if (empty($sellerProducts)): ?>
        <div class="no-products-message">
            <p>No products found. Add your first product above!</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="products-table" id="products-table">
                <thead>
                    <tr>
                        <th class="sortable" data-column="name">
                            Product Name
                            <span class="sort-indicator"></span>
                        </th>
                        <th class="sortable" data-column="price">
                            Price
                            <span class="sort-indicator"></span>
                        </th>
                        <th class="sortable" data-column="stock_quantity">
                            Stock
                            <span class="sort-indicator"></span>
                        </th>
                        <th class="sortable" data-column="status">
                            Status
                            <span class="sort-indicator"></span>
                        </th>
                        <th class="sortable" data-column="created_at">
                            Date Added
                            <span class="sort-indicator"></span>
                        </th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sellerProducts as $product): ?>
                        <tr data-name="<?php echo htmlspecialchars($product['name']); ?>" 
                            data-price="<?php echo $product['price']; ?>" 
                            data-stock="<?php echo (int)$product['stock_quantity']; ?>" 
                            data-status="<?php echo $product['status']; ?>" 
                            data-date="<?php echo strtotime($product['created_at']); ?>">
                            <td>
                                <div class="product-name-cell">
                                    <?php if ($product['image_url']): ?>
                                        <img src="<?php echo htmlspecialchars($product['image_url']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="product-thumb">
                                    <?php endif; ?>
                                    <span><?php echo htmlspecialchars($product['name']); ?></span>
                                </div>
                            </td>
                            <td>₱<?php echo number_format($product['price'], 2); ?></td>
                            <td>
                                <span class="stock-badge-table <?php echo $product['stock_quantity'] > 10 ? 'in-stock' : ($product['stock_quantity'] > 0 ? 'low-stock' : 'out-stock'); ?>">
                                    <?php echo (int)$product['stock_quantity']; ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge-table <?php echo $product['status'] == 'active' ? 'active' : 'inactive'; ?>">
                                    <?php echo ucfirst($product['status']); ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($product['created_at'])); ?></td>
                            <td>
                                <div class="table-actions">
                                    <?php if (in_array($product['status'], ['suspended', 'rejected'])): ?>
                                        <a href="edit-product.php?id=<?php echo $product['id']; ?>" class="action-btn edit-btn" title="View Details (Read-Only)">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    <?php else: ?>
                                        <a href="edit-product.php?id=<?php echo $product['id']; ?>" class="action-btn edit-btn" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="?toggle_status=<?php echo $product['id']; ?>" class="action-btn status-btn" title="<?php echo $product['status'] == 'active' ? 'Toggle Inactive' : 'Toggle Active'; ?>">
                                            <i class="fas fa-<?php echo $product['status'] == 'active' ? 'toggle-on' : 'toggle-off'; ?>"></i>
                                        </a>
                                        <a href="?delete=<?php echo $product['id']; ?>" 
                                           class="action-btn delete-btn product-delete" 
                                           title="Delete Product"
                                           data-product-name="<?php echo htmlspecialchars($product['name']); ?>">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Preview Modal -->
<div class="preview-modal" id="previewModal">
    <div class="preview-container">
        <button class="preview-close" onclick="closePreview()">&times;</button>
        <div class="preview-content">
            <!-- Main Product Section -->
            <div class="preview-product-main">
                <!-- Product Info -->
                <div class="preview-product-info-section">
                    <!-- Image Gallery -->
                    <div class="preview-image-gallery">
                        <div class="preview-main-image-container">
                            <div id="preview-image-container">
                                <div style="color: #999; font-size: 14px;">No image uploaded</div>
                            </div>
                        </div>
                    </div>

                    <!-- Product Details -->
                    <div class="preview-product-details">
                        <h1 class="preview-product-title" id="preview-title">Product Name</h1>
                        
                        <!-- Rating -->
                        <div class="preview-rating-section">
                            <div class="preview-stars">☆☆☆☆☆</div>
                            <span class="preview-rating-text">0.0 out of 5</span>
                            <a href="#" class="review-link" style="color: #130325; text-decoration: none; font-size: 12px; font-weight: 600; margin-left: 8px;">0 reviews</a>
                        </div>

                        <!-- Price -->
                        <div class="preview-price-section">
                            <div class="preview-price-label">Price:</div>
                            <div class="preview-price-amount" id="preview-price">₱0.00</div>
                        </div>

                        <!-- Info Grid -->
                        <div class="preview-info-grid">
                            <div class="preview-info-item">
                                <i class="fas fa-box preview-info-icon"></i>
                                <span class="preview-info-label">Stock:</span>
                                <span class="preview-info-value">
                                    <span class="preview-stock-badge preview-stock-out" id="preview-stock-badge">Out of Stock</span>
                                </span>
                            </div>
                            
                            <div class="preview-info-item">
                                <i class="fas fa-store preview-info-icon"></i>
                                <span class="preview-info-label">Sold by:</span>
                                <span class="preview-info-value" id="preview-seller">Your Store</span>
                            </div>
                        </div>

                        <!-- Quantity Selector -->
                        <div class="preview-quantity-section">
                            <div class="preview-quantity-selector">
                                <span class="preview-quantity-label">Quantity:</span>
                                <div class="preview-quantity-control">
                                    <button type="button" class="preview-qty-btn" onclick="previewDecreaseQty()">−</button>
                                    <input type="number" id="preview-quantity" class="preview-qty-input" value="1" min="1" max="1" readonly>
                                    <button type="button" class="preview-qty-btn" onclick="previewIncreaseQty()">+</button>
                                </div>
                                <span class="preview-stock-status" id="preview-stock-status">IN STOCK</span>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="preview-action-buttons">
                            <button type="button" class="preview-btn-add-to-cart">
                                <i class="fas fa-shopping-cart"></i>
                                Add to Cart
                            </button>
                            <button type="button" class="preview-btn-buy-now">
                                Buy Now
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Product Details Section -->
            <div class="preview-product-details-section">
                <div class="preview-product-category">
                    <h3>Product Category</h3>
                    <p id="preview-categories">No categories selected.</p>
                </div>
                <div class="preview-product-description">
                    <h3>Product Description</h3>
                    <div class="preview-description-content" id="preview-description">No description provided.</div>
                </div>
            </div>
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
    const imagePreview = document.getElementById('image-preview');
    const uploadPlaceholder = document.getElementById('upload-placeholder');
    if (imagePreview) imagePreview.style.display = 'none';
    if (uploadPlaceholder) uploadPlaceholder.style.display = 'flex';
    document.getElementById('image').value = '';
}

function showPreview() {
    // Get form values
    const productName = document.getElementById('product_name').value || 'Product Name';
    const description = document.getElementById('product_description').value || 'No description provided.';
    const price = document.getElementById('price').value || '0.00';
    const stock = parseInt(document.getElementById('stock_quantity').value) || 0;
    
    // Get selected categories
    const checkedCategories = document.querySelectorAll('.category-checkbox:checked');
    let categoriesText = 'No categories selected.';
    if (checkedCategories.length > 0) {
        const categoryNames = Array.from(checkedCategories).map(cb => {
            const label = cb.closest('.child-label');
            return label ? label.querySelector('.child-name').textContent.trim() : '';
        }).filter(name => name);
        categoriesText = categoryNames.length > 0 ? categoryNames.join(', ') : 'No categories selected.';
    }
    
    // Get image preview
    const imagePreview = document.getElementById('image-preview');
    const imageContainer = document.getElementById('preview-image-container');
    
    if (imagePreview && imagePreview.src && imagePreview.style.display !== 'none') {
        imageContainer.innerHTML = '<img src="' + imagePreview.src + '" alt="' + productName + '" class="preview-main-image">';
    } else {
        imageContainer.innerHTML = '<div style="color: #999; font-size: 14px;">No image uploaded</div>';
    }
    
    // Update preview content
    document.getElementById('preview-title').textContent = productName;
    document.getElementById('preview-price').textContent = '₱' + parseFloat(price).toFixed(2);
    
    // Update stock badge
    const stockBadge = document.getElementById('preview-stock-badge');
    stockBadge.className = 'preview-stock-badge ';
    if (stock <= 0) {
        stockBadge.className += 'preview-stock-out';
        stockBadge.textContent = 'Out of Stock';
        document.getElementById('preview-stock-status').textContent = 'OUT OF STOCK';
        document.getElementById('preview-stock-status').style.color = '#dc3545';
        document.getElementById('preview-stock-status').style.background = 'rgba(220, 53, 69, 0.1)';
    } else if (stock <= 10) {
        stockBadge.className += 'preview-stock-low';
        stockBadge.textContent = 'Low Stock (' + stock + ' left)';
        document.getElementById('preview-stock-status').textContent = 'LOW STOCK';
        document.getElementById('preview-stock-status').style.color = '#856404';
        document.getElementById('preview-stock-status').style.background = 'rgba(255, 193, 7, 0.1)';
    } else {
        stockBadge.className += 'preview-stock-in';
        stockBadge.textContent = 'In Stock (' + stock + ' available)';
        document.getElementById('preview-stock-status').textContent = 'IN STOCK';
        document.getElementById('preview-stock-status').style.color = '#28a745';
        document.getElementById('preview-stock-status').style.background = 'rgba(40, 167, 69, 0.1)';
    }
    
    // Update quantity input max
    const qtyInput = document.getElementById('preview-quantity');
    qtyInput.max = stock > 0 ? stock : 1;
    previewQty = Math.min(1, stock > 0 ? stock : 1);
    qtyInput.value = previewQty;
    
    document.getElementById('preview-description').textContent = description;
    document.getElementById('preview-categories').textContent = categoriesText;
    
    // Show/hide quantity section and buttons based on stock
    const quantitySection = document.querySelector('.preview-quantity-section');
    const actionButtons = document.querySelector('.preview-action-buttons');
    if (stock <= 0) {
        if (quantitySection) quantitySection.style.display = 'none';
        if (actionButtons) {
            actionButtons.innerHTML = '<div style="padding: 10px; background: #f8d7da; color: #721c24; border-radius: 6px; font-size: 12px;"><i class="fas fa-exclamation-triangle"></i> Out of Stock - This product is currently unavailable.</div>';
        }
    } else {
        if (quantitySection) quantitySection.style.display = 'block';
        if (actionButtons) {
            actionButtons.innerHTML = '<button type="button" class="preview-btn-add-to-cart"><i class="fas fa-shopping-cart"></i> Add to Cart</button><button type="button" class="preview-btn-buy-now">Buy Now</button>';
        }
    }
    
    // Show modal
    document.getElementById('previewModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

let previewQty = 1;

function previewIncreaseQty() {
    const qtyInput = document.getElementById('preview-quantity');
    const max = parseInt(qtyInput.max);
    if (previewQty < max) {
        previewQty++;
        qtyInput.value = previewQty;
    }
}

function previewDecreaseQty() {
    if (previewQty > 1) {
        previewQty--;
        document.getElementById('preview-quantity').value = previewQty;
    }
}

function closePreview() {
    document.getElementById('previewModal').classList.remove('active');
    document.body.style.overflow = '';
}

// Close modal when clicking outside
document.addEventListener('click', function(e) {
    const modal = document.getElementById('previewModal');
    if (e.target === modal) {
        closePreview();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('previewModal');
        if (modal.classList.contains('active')) {
            closePreview();
        }
    }
});

// Handle table sorting (client-side, no page reload)
document.addEventListener('DOMContentLoaded', function() {
    const table = document.getElementById('products-table');
    if (!table) return;
    
    const tbody = table.querySelector('tbody');
    const sortableHeaders = document.querySelectorAll('.products-table th.sortable');
    let currentSort = null;
    let currentOrder = 'desc';
    
    // Store original rows data
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const rowsData = rows.map(row => {
        return {
            element: row,
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
            indicator.classList.remove('asc', 'desc');
            
            // Add active sort class
            if (column === activeColumn) {
                indicator.classList.add(order);
            }
        });
    }
    
    function sortTable(column, order) {
        const sortedData = [...rowsData].sort((a, b) => {
            let aVal, bVal;
            
            switch(column) {
                case 'name':
                    aVal = a.name;
                    bVal = b.name;
                    break;
                case 'price':
                    aVal = a.price;
                    bVal = b.price;
                    break;
                case 'stock_quantity':
                    aVal = a.stock;
                    bVal = b.stock;
                    break;
                case 'status':
                    aVal = a.status;
                    bVal = b.status;
                    break;
                case 'created_at':
                    aVal = a.date; // timestamp
                    bVal = b.date; // timestamp
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
    
    // Initial sort indicator (none shown initially)
});

// Handle availability toggle switch
document.addEventListener('DOMContentLoaded', function() {
    const statusToggle = document.getElementById('product_status');
    const statusBadge = document.getElementById('availability-status-badge');
    const toggleLabel = document.getElementById('toggle-label');
    
    if (statusToggle && statusBadge && toggleLabel) {
        function updateStatus() {
            if (statusToggle.checked) {
                statusBadge.textContent = 'Active';
                statusBadge.className = 'status-badge active';
                toggleLabel.textContent = 'Active';
            } else {
                statusBadge.textContent = 'Inactive';
                statusBadge.className = 'status-badge inactive';
                toggleLabel.textContent = 'Inactive';
            }
        }
        
        statusToggle.addEventListener('change', updateStatus);
        updateStatus(); // Initialize on page load
    }
});

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

    // Custom Confirmation Modal - Matching Logout Modal Design
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
            confirmBtn.onmouseover = function() { this.style.background = '#0a0218'; };
            confirmBtn.onmouseout = function() { this.style.background = '#130325'; };
            
            footer.appendChild(cancelBtn);
            footer.appendChild(confirmBtn);
            
            dialog.appendChild(header);
            dialog.appendChild(body);
            dialog.appendChild(footer);
            overlay.appendChild(dialog);
            
            document.body.appendChild(overlay);
            
            requestAnimationFrame(()=> overlay.classList.add('show'));
            
            const close = ()=>{ 
                overlay.classList.remove('show'); 
                setTimeout(()=>overlay.remove(), 300); 
            };
            
            overlay.addEventListener('click', (e)=>{ 
                if(e.target === overlay){ 
                    close(); 
                    resolve(false);
                } 
            });
            
            cancelBtn.addEventListener('click', ()=>{ 
                close(); 
                resolve(false); 
            });
            
            confirmBtn.addEventListener('click', ()=>{ 
                close(); 
                resolve(true); 
            });
        });
    }

    // Intercept edit button clicks
    document.querySelectorAll('a.edit-btn').forEach(function(link){
        link.addEventListener('click', function(e){
            e.preventDefault();
            const row = this.closest('tr');
            const productName = row ? (row.getAttribute('data-name') || row.querySelector('td:first-child')?.textContent?.trim() || 'this product') : 'this product';
            const message = `Are you sure you want to edit ${productName}?`;
            showConfirm(message, 'Edit').then(function(ok){ 
                if (ok) window.location.href = link.href; 
            });
        });
    });

    // Intercept toggle status button clicks
    document.querySelectorAll('a.status-btn').forEach(function(link){
        link.addEventListener('click', function(e){
            e.preventDefault();
            const row = this.closest('tr');
            const productName = row ? (row.getAttribute('data-name') || row.querySelector('td:first-child')?.textContent?.trim() || 'this product') : 'this product';
            const statusText = this.title.includes('Inactive') ? 'deactivate' : 'activate';
            const message = `Are you sure you want to ${statusText} ${productName}?`;
            showConfirm(message, 'Yes').then(function(ok){ 
                if (ok) window.location.href = link.href; 
            });
        });
    });

    // Intercept delete button clicks
    document.querySelectorAll('a.product-delete').forEach(function(link){
        link.addEventListener('click', function(e){
            e.preventDefault();
            const name = link.getAttribute('data-product-name') || 'this product';
            const message = `Are you sure you want to delete ${name}? This action cannot be undone.`;
            showConfirm(message, 'Delete').then(function(ok){ 
                if (ok) window.location.href = link.href; 
            });
        });
    });
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
