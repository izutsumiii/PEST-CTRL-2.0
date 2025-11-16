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
            
            // Check for orders that are not cancelled AND not fully returned/refunded
$stmt = $pdo->prepare("
SELECT COUNT(DISTINCT o.id) as count 
FROM order_items oi 
INNER JOIN orders o ON oi.order_id = o.id 
LEFT JOIN return_requests rr ON o.id = rr.order_id AND rr.status = 'completed'
WHERE oi.product_id = ? 
AND o.status NOT IN ('cancelled', 'return_completed')
AND (rr.id IS NULL OR rr.status != 'completed')
");
$stmt->execute([$productId]);
$activeOrderResult = $stmt->fetch(PDO::FETCH_ASSOC);
$activeOrderCount = intval($activeOrderResult['count'] ?? 0);

// Additional check: If all orders are either cancelled or have completed returns, allow deletion
if ($activeOrderCount > 0) {
// Double-check if there are ANY pending/processing/shipped/delivered orders without completed returns
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT o.id) as count 
    FROM order_items oi 
    INNER JOIN orders o ON oi.order_id = o.id 
    LEFT JOIN return_requests rr ON o.id = rr.order_id
    WHERE oi.product_id = ? 
    AND o.status IN ('pending', 'processing', 'shipped', 'delivered')
    AND (rr.id IS NULL OR rr.status NOT IN ('completed', 'rejected'))
");
$stmt->execute([$productId]);
$strictCheckResult = $stmt->fetch(PDO::FETCH_ASSOC);
$strictActiveCount = intval($strictCheckResult['count'] ?? 0);

if ($strictActiveCount > 0) {
    // Product has active orders - cannot delete, only deactivate
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
    // All orders are either cancelled or have completed returns - safe to delete
    goto safeDelete;
}
} else {
safeDelete:
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
    header("Location: view-products.php");
    exit();
}

// Handle product view
if (isset($_GET['view'])) {
    $productId = intval($_GET['view']);

    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND seller_id = ?");
    $stmt->execute([$productId, $userId]);
    $viewProduct = $stmt->fetch();

    if ($viewProduct) {
        // Get product images
        $stmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, sort_order ASC");
        $stmt->execute([$productId]);
        $viewProduct['images'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // For now, redirect back to products page with a success message
        // This can be enhanced later to show a detailed view modal
        $_SESSION['product_message'] = ['type' => 'success', 'text' => 'Viewing product: ' . htmlspecialchars($viewProduct['name'])];
        header("Location: view-products.php");
        exit();
    } else {
        $_SESSION['product_message'] = ['type' => 'error', 'text' => 'Product not found.'];
        header("Location: view-products.php");
        exit();
    }
}

// Include header after form processing is complete (to avoid headers already sent)
require_once 'includes/seller_header.php';
?>

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

/* Reply Modal Overlay - Matches Logout Modal Style */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.35);
    z-index: 99999;
    align-items: center;
    justify-content: center;
}

.modal-dialog {
    width: 400px;
    max-width: 90vw;
    background: #ffffff;
    border: none;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
}

.modal-header {
    padding: 8px 12px;
    background: #130325;
    color: #F9F9F9;
    border-bottom: none;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-radius: 12px 12px 0 0;
}

.modal-title {
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 0.3px;
    margin: 0;
}

.modal-close {
    background: transparent;
    border: none;
    color: #F9F9F9;
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
    padding: 0;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-close:hover {
    opacity: 0.8;
}

.modal-body {
    padding: 12px;
    color: #130325;
    font-size: 12px;
}

.modal-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    padding: 0 12px 12px 12px;
}

.btn-outline {
    background: #ffffff;
    color: #130325;
    border: 1px solid rgba(0, 0, 0, 0.1);
    border-radius: 8px;
    padding: 6px 10px;
    font-weight: 700;
    font-size: 12px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s ease;
}

.btn-outline:hover {
    background: #f3f4f6;
}

.btn-primary-y {
    background: linear-gradient(135deg, #FFD736 0%, #FFC107 100%);
    color: #130325;
    border: none;
    border-radius: 8px;
    padding: 6px 10px;
    font-weight: 700;
    font-size: 12px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s ease;
}

.btn-primary-y:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(255, 215, 54, 0.3);
}

.btn-primary-y:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
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

/* ============================================
   FILTER SECTION - MODERN DESIGN
   ============================================ */

.filter-section {
    margin-bottom: 16px;
    padding: 14px 16px;
    background: var(--bg-white);
    border-radius: 6px;
    border: 1px solid rgba(19, 3, 37, 0.08);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    transition: all 0.2s ease;
}

.filter-section:hover {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border-color: rgba(19, 3, 37, 0.12);
}

.filter-wrapper {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

.filter-search {
    position: relative;
    flex: 1;
    min-width: 200px;
}

.filter-search i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-light);
    font-size: 14px;
    pointer-events: none;
    z-index: 1;
}

.filter-search input {
    width: 100%;
    padding: 10px 12px 10px 36px;
    border: 1px solid var(--border-light);
    border-radius: 6px;
    background: var(--bg-white);
    color: var(--primary-dark);
    font-size: 14px;
    transition: all 0.2s ease;
    font-family: inherit;
}

.filter-search input:focus {
    outline: none;
    border-color: var(--primary-dark);
    box-shadow: 0 0 0 3px rgba(19, 3, 37, 0.1);
}

.filter-search input::placeholder {
    color: var(--text-light);
}

.filter-dropdown {
    position: relative;
    min-width: 140px;
}

.filter-dropdown select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border-light);
    border-radius: 6px;
    background: var(--bg-white);
    color: var(--primary-dark);
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s ease;
    font-family: inherit;
    appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6,9 12,15 18,9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 10px center;
    background-size: 16px;
    padding-right: 36px;
}

.filter-dropdown select:focus {
    outline: none;
    border-color: var(--primary-dark);
    box-shadow: 0 0 0 3px rgba(19, 3, 37, 0.1);
}

.filter-dropdown select:hover {
    border-color: rgba(19, 3, 37, 0.15);
}

.filter-dropdown option {
    background: var(--bg-white);
    color: var(--primary-dark);
    padding: 8px;
}

/* Products Table Container - Modern */
.products-table-container {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    padding: 24px;
    margin-top: 0;
    box-shadow: 0 2px 8px rgba(19, 3, 37, 0.08);
}

.table-wrapper {
    overflow-x: auto;
    border-radius: 8px;
}

.products-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
    table-layout: fixed;
}

.products-table thead {
    background: linear-gradient(135deg, var(--primary-dark) 0%, rgba(19, 3, 37, 0.95) 100%);
    border-bottom: 2px solid var(--accent-yellow);
}

.products-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 700;
    color: var(--bg-white);
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    position: relative;
    user-select: none;
    border: none;
    box-sizing: border-box;
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
    color: var(--accent-yellow);
    transition: all 0.2s ease;
}

.sort-indicator::before {
    content: '↕';
    display: block;
}

.sort-indicator.asc::before {
    content: '↑';
    color: var(--accent-yellow);
}

.sort-indicator.desc::before {
    content: '↓';
    color: var(--accent-yellow);
}

.products-table tbody tr {
    border-bottom: 1px solid var(--border-light);
    transition: background 0.2s ease;
}

.products-table tbody tr:hover {
    background: rgba(255, 215, 54, 0.03);
}

.products-table td {
    padding: 12px 16px;
    color: var(--text-dark);
    vertical-align: middle;
    border: none;
    box-sizing: border-box;
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

.status-badge-table.rejected {
    background: rgba(220, 53, 69, 0.2);
    color: #dc3545;
    border: 1px solid rgba(220, 53, 69, 0.4);
}

.status-badge-table.suspended {
    background: rgba(108, 117, 125, 0.15);
    color: #6c757d;
}

.status-badge-table.pending {
    background: rgba(255, 193, 7, 0.15);
    color: #ffc107;
}

.table-actions {
    display: flex;
    gap: 6px;
}

.action-btn {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: all 0.2s ease;
    text-decoration: none;
    color: var(--primary-dark);
    background: transparent;
    border: 2px solid var(--primary-dark);
    font-size: 14px;
}

.action-btn:hover {
    background: var(--primary-dark);
    color: var(--bg-white);
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(19, 3, 37, 0.2);
}

.edit-btn {
    background: transparent;
    color: var(--primary-dark);
    border: 2px solid var(--primary-dark);
}

.edit-btn:hover {
    background: var(--primary-dark);
    color: var(--bg-white);
}

.status-btn {
    background: transparent;
    color: var(--success-green);
    border: 2px solid var(--success-green);
}

.status-btn:hover {
    background: var(--success-green);
    color: var(--bg-white);
}

.delete-btn {
    background: transparent;
    color: var(--error-red);
    border: 2px solid var(--error-red);
}

.delete-btn:hover {
    background: var(--error-red);
    color: var(--bg-white);
}

.no-products-message {
    text-align: center;
    padding: 40px;
    color: #6b7280;
    font-size: 14px;
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

    .filter-section {
        padding: 8px 10px;
        margin-bottom: 10px;
    }

    .filter-wrapper {
        gap: 6px;
    }

    .filter-search {
        min-width: 120px;
        flex: 1;
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

    .filter-section {
        padding: 6px 8px;
    }

    .filter-wrapper {
        flex-direction: column;
        gap: 6px;
    }

    .filter-search,
    .filter-dropdown {
        width: 100%;
        min-width: auto;
    }

    h1 {
        font-size: 1.2rem;
        margin-bottom: 12px;
    }

    .products-table-container {
        padding: 16px 12px;
        margin-top: 16px;
    }

    .products-table {
        font-size: 12px;
    }

    .products-table th,
    .products-table td {
        padding: 8px 12px;
    }

    .table-actions {
        gap: 4px;
    }

    .action-btn {
        width: 28px;
        height: 28px;
        font-size: 12px;
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

    .filter-section {
        padding: 8px 6px;
        margin-bottom: 12px;
    }

    .filter-wrapper {
        gap: 4px;
    }

    h1 {
        font-size: 1.1rem;
        margin-bottom: 10px;
    }

    .products-table-container {
        padding: 12px 8px;
        margin-top: 12px;
    }

    .products-table th,
    .products-table td {
        padding: 6px 8px;
        font-size: 11px;
    }

    .action-btn {
        width: 24px;
        height: 24px;
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

    .filter-section {
        padding: 6px 4px;
        margin-bottom: 8px;
    }

    h1 {
        font-size: 1rem;
        margin-bottom: 8px;
    }

    .products-table-container {
        padding: 10px 6px;
        margin-top: 10px;
    }

    .products-table th,
    .products-table td {
        padding: 4px 6px;
        font-size: 10px;
    }

    .action-btn {
        width: 20px;
        height: 20px;
        font-size: 10px;
    }
}

/* Product ID Link */
.product-id-link {
    color: #130325;
    text-decoration: underline;
    font-weight: 600;
    cursor: pointer;
}
.product-id-link:hover {
    text-decoration: underline;
}

/* Product View Modal - Matching Admin Style */
.product-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.7);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    overflow-y: auto;
    padding: 20px;
}
.product-modal-overlay[aria-hidden="false"] {
    display: flex;
}
.product-modal-dialog {
    width: 1000px;
    max-width: 95vw;
    max-height: 90vh;
    background: #f0f2f5;
    border: none;
    border-radius: 8px;
    position: relative;
    margin: auto;
    animation: productModalSlideIn 0.3s ease;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
}
@keyframes productModalSlideIn {
    from {
        opacity: 0;
        transform: scale(0.95) translateY(-20px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}
.product-modal-close {
    position: absolute;
    top: 15px;
    right: 15px;
    background: rgba(255, 255, 255, 0.9);
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
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}
.product-modal-close:hover {
    background: rgba(255, 255, 255, 1);
    transform: scale(1.1);
}
.product-modal-body {
    padding: 20px;
    color: #130325;
    max-height: calc(90vh - 40px);
    overflow-y: auto;
}
.product-modal-body::-webkit-scrollbar {
    width: 8px;
}
.product-modal-body::-webkit-scrollbar-track {
    background: #e9ecef;
    border-radius: 4px;
}
.product-modal-body::-webkit-scrollbar-thumb {
    background: #adb5bd;
    border-radius: 4px;
}
.product-modal-body::-webkit-scrollbar-thumb:hover {
    background: #868e96;
}
.product-view-main {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
}
.product-image-gallery {
    flex: 0 0 200px;
}
.product-main-image-container {
    background: #ffffff;
    border: 2px solid #666666;
    padding: 10px;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 200px;
    max-width: 200px;
    border-radius: 4px;
}
.product-main-image {
    max-width: 100%;
    max-height: 200px;
    width: auto;
    height: auto;
    object-fit: contain;
}
.product-info-section {
    background: #ffffff;
    padding: 20px;
    display: flex;
    gap: 20px;
    align-items: flex-start;
    flex: 1;
    border-radius: 4px;
}
.product-details {
    flex: 1;
}
.product-title {
    font-size: 20px;
    font-weight: 900;
    color: #130325;
    margin-bottom: 12px;
    line-height: 1.2;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    text-shadow: none !important;
}
.product-price-section {
    background: rgba(255, 215, 54, 0.1);
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 12px;
    border: 1px solid rgba(255, 215, 54, 0.3);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.product-price-left {
    flex: 1;
}
.product-price-label {
    font-size: 11px;
    color: rgba(19, 3, 37, 0.6);
    margin-bottom: 4px;
}
.product-price-amount {
    font-size: 20px;
    font-weight: 900;
    color: #130325;
    line-height: 1;
}
.product-price-stock {
    display: flex;
    align-items: center;
    gap: 6px;
}
.product-price-stock-label {
    font-size: 11px;
    color: rgba(19, 3, 37, 0.6);
}
.product-info-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 15px;
}
.product-info-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: rgba(255, 255, 255, 0.5);
    border-radius: 6px;
    border: 1px solid rgba(19, 3, 37, 0.1);
}
.product-info-row-full {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 8px 12px;
    background: rgba(255, 255, 255, 0.5);
    border-radius: 6px;
    border: 1px solid rgba(19, 3, 37, 0.1);
}
.product-info-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    background: rgba(255, 255, 255, 0.5);
    border-radius: 6px;
    border: 1px solid rgba(19, 3, 37, 0.1);
    min-width: 0;
}
.product-info-row-item {
    display: flex;
    align-items: center;
    gap: 6px;
    flex: 1;
}
.product-info-row-item-separator {
    color: rgba(19, 3, 37, 0.3);
    margin: 0 4px;
}
.product-info-icon {
    color: #FFD736;
    font-size: 12px;
    width: 16px;
    text-align: center;
}
.product-info-label {
    color: rgba(19, 3, 37, 0.7);
    font-size: 10px;
    margin-right: 4px;
}
.product-info-value {
    color: #130325;
    font-weight: 600;
    font-size: 10px;
}
.product-stock-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
}
.product-stock-in {
    background: #d4edda;
    color: #155724;
}
.product-stock-low {
    background: #fff3cd;
    color: #856404;
}
.product-stock-out {
    background: #f8d7da;
    color: #721c24;
}
.product-status-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}
.product-status-badge.pending { background: #fef3c7; color: #92400e; }
.product-status-badge.active { background: #d1fae5; color: #065f46; }
.product-status-badge.inactive { background: #fee2e2; color: #991b1b; }
.product-status-badge.rejected { background: #fee2e2; color: #991b1b; }
.product-status-badge.suspended { background: #e5e7eb; color: #374151; }
.product-details-section {
    background: #ffffff;
    padding: 14px 16px;
    margin-bottom: 15px;
    border-radius: 4px;
}
.product-details-section h3 {
    font-size: 13px;
    font-weight: 600;
    color: #130325;
    margin-bottom: 6px;
    text-shadow: none !important;
}
.product-category-text {
    font-size: 12px;
    color: #666;
    margin: 0;
}
.product-description-content {
    font-size: 12px;
    color: #666;
    line-height: 1.5;
    white-space: pre-wrap;
    margin: 0;
}
.preview-product-category,
.preview-product-description {
    margin-bottom: 12px;
}
.preview-product-category:last-child,
.preview-product-description:last-child {
    margin-bottom: 0;
}
.product-loading {
    text-align: center;
    padding: 60px 40px;
    color: #6b7280;
}
.product-loading i {
    font-size: 32px;
    margin-bottom: 12px;
    display: block;
    animation: spin 1s linear infinite;
    color: #130325;
}
.product-loading p {
    font-size: 14px;
    margin: 0;
}
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* Reviews Section in Modal */
.product-reviews-section {
    background: #ffffff;
    padding: 20px;
    margin-top: 20px;
    border-radius: 4px;
    border-top: 2px solid #FFD736;
}

.reviews-section-title {
    font-size: 16px;
    font-weight: 700;
    color: #130325;
    margin: 0 0 15px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.reviews-section-title i {
    color: #FFD736;
    font-size: 18px;
}

.reviews-container-modal {
    max-height: 400px;
    overflow-y: auto;
}

.reviews-container-modal::-webkit-scrollbar {
    width: 6px;
}

.reviews-container-modal::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.reviews-container-modal::-webkit-scrollbar-thumb {
    background: #adb5bd;
    border-radius: 3px;
}

.reviews-container-modal::-webkit-scrollbar-thumb:hover {
    background: #868e96;
}

.reviews-loading {
    text-align: center;
    padding: 30px;
    color: #6b7280;
}

.reviews-loading i {
    font-size: 24px;
    margin-bottom: 8px;
    display: block;
    color: #130325;
}

.reviews-loading p {
    margin: 0;
    font-size: 13px;
}

.reviews-error {
    text-align: center;
    padding: 30px;
    color: #dc3545;
}

.reviews-error i {
    font-size: 24px;
    margin-bottom: 8px;
    display: block;
}

.reviews-error p {
    margin: 0;
    font-size: 13px;
}

.no-reviews-modal {
    text-align: center;
    padding: 40px 20px;
    color: #130325;
}

.no-reviews-modal i {
    font-size: 32px;
    margin-bottom: 12px;
    display: block;
    color: #FFD736;
    opacity: 0.5;
}

.no-reviews-modal p {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
    color: #130325;
}

.review-item-modal {
    padding: 15px;
    border-bottom: 1px solid rgba(19, 3, 37, 0.1);
    transition: background 0.2s ease;
}

.review-item-modal:last-child {
    border-bottom: none;
}

.review-item-modal:hover {
    background: rgba(255, 215, 54, 0.03);
}

.review-item-modal.review-hidden {
    opacity: 0.6;
    background: rgba(108, 117, 125, 0.05);
}

.review-header-modal {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.review-user-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.review-username {
    font-size: 13px;
    font-weight: 700;
    color: #130325;
}

.review-date-small {
    font-size: 11px;
    color: rgba(19, 3, 37, 0.4);
}

.review-rating-modal {
    color: #FFD736;
    font-size: 14px;
}

.review-text-modal {
    font-size: 13px;
    color: #130325;
    line-height: 1.5;
    margin-bottom: 12px;
}

.review-hidden-message {
    padding: 12px 16px;
    background: rgba(108, 117, 125, 0.1);
    border-left: 3px solid #6c757d;
    border-radius: 4px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    color: rgba(19, 3, 37, 0.5);
}

.review-hidden-message i {
    font-size: 14px;
    color: #6c757d;
}

.review-hidden-message em {
    font-size: 12px;
    font-style: italic;
}

.seller-reply-modal {
    margin-top: 12px;
    padding: 12px 14px;
    background: rgba(255, 215, 54, 0.08);
    border-left: 3px solid #FFD736;
    border-radius: 4px;
}

.reply-header-modal {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 8px;
}

.reply-header-modal i {
    color: #FFD736;
    font-size: 12px;
}

.reply-label {
    font-weight: 700;
    color: #130325;
    font-size: 12px;
}

.reply-date-small {
    font-size: 10px;
    color: rgba(19, 3, 37, 0.4);
    margin-left: auto;
}

.reply-text-modal {
    color: #130325;
    font-size: 12px;
    line-height: 1.5;
}

.review-actions {
    margin-top: 12px;
}

.btn-reply-review {
    background: transparent;
    color: #130325;
    border: none;
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
    border-radius: 4px;
}

.btn-reply-review:hover {
    background: rgba(255, 215, 54, 0.1);
    color: #130325;
}

.btn-reply-review i {
    font-size: 12px;
}

@media (max-width: 768px) {
    .product-view-main {
        flex-direction: column;
    }
    .product-image-gallery {
        flex: 1;
    }
    .product-main-image-container {
        max-width: 100%;
    }
    .product-modal-dialog {
        width: 95vw;
    }
    .product-price-section {
        flex-direction: column;
        align-items: flex-start;
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

    <h1>View Products</h1>

    <div class="filter-section">
        <div class="filter-wrapper">
            <div class="filter-search">
                <i class="fas fa-search"></i>
                <input type="text" id="productSearch" placeholder="Search by name, category, or ID..." onkeyup="filterProducts()">
            </div>
            <div class="filter-dropdown">
                <select id="statusFilter" onchange="filterProducts()">
                    <option value="all">All Products</option>
                    <option value="active">Active Only</option>
                    <option value="inactive">Inactive Only</option>
                    <option value="pending">Pending Approval</option>
                    <option value="rejected">Rejected</option>
                    <option value="suspended">Suspended</option>
                </select>
            </div>
        </div>
    </div>

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
                        <?php if (empty($allProducts)): ?>
                        <tr>
                            <td colspan="8" style="text-align:center; color:#6b7280; padding:20px;">No products found.</td>
                        </tr>
                        <?php else: foreach ($allProducts as $product): ?>
                            <tr data-id="<?php echo (int)$product['id']; ?>"
                                data-name="<?php echo htmlspecialchars($product['name']); ?>"
                                data-price="<?php echo (float)$product['price']; ?>"
                                data-stock="<?php echo (int)$product['stock_quantity']; ?>"
                                data-status="<?php echo htmlspecialchars($product['status'] ?? 'inactive'); ?>"
                                data-date="<?php echo isset($product['created_at']) ? strtotime($product['created_at']) : 0; ?>">
                                <td>
                                    <a href="#" class="product-id-link" onclick="openProductModal(<?php echo $product['id']; ?>); return false;" title="View Product Details">
                                        #<?php echo $product['id']; ?>
                                    </a>
                                </td>
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
                                    <span class="status-badge-table <?php echo htmlspecialchars($product['status'] ?? 'inactive'); ?>">
                                        <?php echo ucfirst($product['status'] ?? 'inactive'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="?view=<?php echo $product['id']; ?>" class="action-btn view-btn" title="View Product">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if (!in_array($product['status'], ['suspended', 'rejected'])): ?>
                                            <a href="edit-product.php?id=<?php echo $product['id']; ?>" class="action-btn edit-btn" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="?toggle_status=<?php echo $product['id']; ?>" 
                                               class="action-btn status-btn product-toggle"
                                               title="<?php echo ($product['status'] == 'active') ? 'Toggle Inactive' : 'Toggle Active'; ?>"
                                               data-action="<?php echo ($product['status'] == 'active') ? 'deactivate' : 'activate'; ?>"
                                               data-product-name="<?php echo htmlspecialchars($product['name']); ?>">
                                                <i class="fas fa-<?php echo ($product['status'] == 'active') ? 'toggle-on' : 'toggle-off'; ?>"></i>
                                            </a>
                                            <a href="?delete=<?php echo $product['id']; ?>" 
                                               class="action-btn delete-btn product-delete"
                                               title="Delete Product"
                                               data-action="delete"
                                               data-product-name="<?php echo htmlspecialchars($product['name']); ?>">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
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
        if (statusFilter !== 'all') {
            matchesStatus = productStatus === statusFilter;
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

    // Intercept product toggle and delete with custom confirm - Matching Logout Modal Design
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

    document.querySelectorAll('a.product-toggle').forEach(function(link){
        link.addEventListener('click', function(e){
            e.preventDefault();
            const action = link.getAttribute('data-action');
            const name = link.getAttribute('data-product-name') || 'this product';
            const message = `Are you sure you want to ${action} ${name}?`;
            showConfirm(message, 'Yes').then(function(ok){ if (ok) window.location.href = link.href; });
        });
    });

    document.querySelectorAll('a.edit-btn').forEach(function(link){
        link.addEventListener('click', function(e){
            e.preventDefault();
            const row = link.closest('tr');
            // Product name is in the td after the image (third td: ID, image, name)
            const name = row ? (row.querySelector('td:nth-child(3)')?.textContent?.trim() || 'this product') : 'this product';
            const message = `Are you sure you want to edit ${name}?`;
            showConfirm(message, 'Edit').then(function(ok){ if (ok) window.location.href = link.href; });
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

// Product View Modal Functions
function openProductModal(productId) {
    const modal = document.getElementById('productViewModal');
    if (!modal) return;
    
    // Show loading state
    const modalBody = modal.querySelector('.product-modal-body');
    modalBody.innerHTML = '<div class="product-loading"><i class="fas fa-spinner"></i><p>Loading product details...</p></div>';
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    
    // Fetch product details
    fetch(`ajax/get-product-details.php?id=${productId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayProductDetails(data.product);
            } else {
                modalBody.innerHTML = `<div class="product-loading"><i class="fas fa-exclamation-triangle"></i><p>${data.message || 'Error loading product details'}</p></div>`;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            modalBody.innerHTML = '<div class="product-loading"><i class="fas fa-exclamation-triangle"></i><p>Error loading product details. Please try again.</p></div>';
        });
}

function displayProductDetails(product) {
    const modalBody = document.querySelector('#productViewModal .product-modal-body');
    
    // Build images HTML
    let mainImage = 'assets/uploads/tempo_image.jpg'; // default
    let imageHTML = '';
    
    if (product.images && product.images.length > 0) {
        let imgPath = product.images[0].image_url || '';
        if (imgPath) {
            mainImage = imgPath;
        }
    } else if (product.image_url) {
        mainImage = product.image_url;
    }
    
    imageHTML = `
        <div class="product-main-image-container">
            <img src="${mainImage}" alt="${escapeHtml(product.name)}" class="product-main-image" id="productMainImage" onerror="this.src='assets/uploads/tempo_image.jpg'">
        </div>
    `;
    
    // Build status badge
    const statusClass = product.status || 'inactive';
    const statusText = product.status ? product.status.charAt(0).toUpperCase() + product.status.slice(1) : 'Inactive';
    
    // Stock badge
    const stock = parseInt(product.stock_quantity || 0);
    let stockBadgeClass = 'product-stock-out';
    let stockBadgeText = 'Out of Stock';
    if (stock > 10) {
        stockBadgeClass = 'product-stock-in';
        stockBadgeText = 'In Stock';
    } else if (stock > 0) {
        stockBadgeClass = 'product-stock-low';
        stockBadgeText = 'Low Stock';
    }
    
    modalBody.innerHTML = `
        <div class="product-view-main">
            <div class="product-image-gallery">
                ${imageHTML}
            </div>
            
            <div class="product-info-section">
                <div class="product-details">
                    <h1 class="product-title">${escapeHtml(product.name)}</h1>
                    
                    <div class="product-price-section">
                        <div class="product-price-left">
                            <div class="product-price-label">Price:</div>
                            <div class="product-price-amount">₱${parseFloat(product.price || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                        </div>
                        <div class="product-price-stock">
                            <span class="product-price-stock-label">Stock:</span>
                            <span class="product-stock-badge ${stockBadgeClass}">${stockBadgeText}</span>
                        </div>
                    </div>
                    
                    <div class="product-info-list">
                        <div class="product-info-row">
                            <i class="fas fa-hashtag product-info-icon"></i>
                            <span class="product-info-label">Product ID:</span>
                            <span class="product-info-value">#${product.id}</span>
                            <span class="product-info-row-item-separator">•</span>
                            <i class="fas fa-calendar-plus product-info-icon"></i>
                            <span class="product-info-label">Created:</span>
                            <span class="product-info-value">${product.created_at_formatted}</span>
                        </div>
                        
                        <div class="product-info-row">
                            <i class="fas fa-tag product-info-icon"></i>
                            <span class="product-info-label">Status:</span>
                            <span class="product-status-badge ${statusClass}">${statusText}</span>
                            <span class="product-info-row-item-separator">•</span>
                            <i class="fas fa-box product-info-icon"></i>
                            <span class="product-info-label">Stock Quantity:</span>
                            <span class="product-info-value">${stock}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="product-details-section">
            <div class="preview-product-category">
                <h3>Product Category</h3>
                <p class="product-category-text">${escapeHtml(product.category_name || 'No category assigned')}</p>
            </div>
            <div class="preview-product-description">
                <h3>Product Description</h3>
                <div class="product-description-content">${escapeHtml(product.description || 'No description provided.')}</div>
            </div>
        </div>
        
        <!-- Reviews Section -->
        <div class="product-reviews-section">
            <h3 class="reviews-section-title">
                <i class="fas fa-star"></i> Product Reviews
            </h3>
            <div id="reviewsContainer" class="reviews-container-modal">
                <div class="reviews-loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading reviews...</p>
                </div>
            </div>
        </div>
    `;
    
    // Load reviews after displaying product details
    loadProductReviews(product.id);
}

// Load product reviews
function loadProductReviews(productId) {
    const container = document.getElementById('reviewsContainer');
    
    fetch(`ajax/get-product-reviews.php?product_id=${productId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayReviews(data.reviews);
            } else {
                container.innerHTML = `<div class="reviews-error"><i class="fas fa-exclamation-triangle"></i><p>${data.message}</p></div>`;
            }
        })
        .catch(error => {
            console.error('Error loading reviews:', error);
            container.innerHTML = '<div class="reviews-error"><i class="fas fa-exclamation-triangle"></i><p>Error loading reviews</p></div>';
        });
}

// Display reviews in modal
function displayReviews(reviews) {
    const container = document.getElementById('reviewsContainer');
    
    console.log('displayReviews called with:', reviews);
    
    if (!reviews || reviews.length === 0) {
        container.innerHTML = `
            <div class="no-reviews-modal">
                <i class="fas fa-comments"></i>
                <p>No reviews yet for this product</p>
            </div>
        `;
        return;
    }
    
    const reviewsHTML = reviews.map(review => {
        console.log('Processing review:', review);
        console.log('Review ID being used:', review.id, 'Type:', typeof review.id);
        const stars = '★'.repeat(review.rating) + '☆'.repeat(5 - review.rating);
        const isHidden = review.is_hidden;
        const hasReply = review.seller_reply && review.seller_reply.trim() !== '';
        
        return `
            <div class="review-item-modal ${isHidden ? 'review-hidden' : ''}" data-review-id="${review.id}">
                <div class="review-header-modal">
                    <div class="review-user-info">
                        <span class="review-username">${escapeHtml(review.customer_name)}</span>
                        <span class="review-date-small">${review.created_at_formatted}</span>
                    </div>
                    <div class="review-rating-modal">${stars}</div>
                </div>
                
                ${isHidden ? `
    <div class="review-hidden-message">
        <i class="fas fa-eye-slash"></i>
        <div style="flex: 1;">
            <em>This review has been hidden by admin.</em>
            ${review.hidden_reason ? `
                <div class="hidden-reason" style="margin-top: 8px; padding: 8px 12px; background: rgba(108, 117, 125, 0.08); border-radius: 4px; border: 1px solid rgba(108, 117, 125, 0.15);">
                    <strong style="color: #6c757d; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 4px;">Admin's Reason:</strong>
                    <div style="color: rgba(19, 3, 37, 0.8); font-size: 13px; line-height: 1.5;">${escapeHtml(review.hidden_reason).replace(/\n/g, '<br>')}</div>
                </div>
            ` : ''}
        </div>
    </div>
` : `
                    <div class="review-text-modal">${escapeHtml(review.review_text).replace(/\n/g, '<br>')}</div>
                    
                    ${hasReply ? `
                        <div class="seller-reply-modal">
                            <div class="reply-header-modal">
                                <i class="fas fa-store"></i>
                                <span class="reply-label">Your Reply</span>
                                <span class="reply-date-small">${review.seller_replied_at ? new Date(review.seller_replied_at).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'}) : ''}</span>
                            </div>
                            <div class="reply-text-modal">${escapeHtml(review.seller_reply).replace(/\n/g, '<br>')}</div>
                        </div>
                    ` : `
                        <div class="review-actions">
                            <button class="btn-reply-review" onclick="openReplyModal(${review.id}, '${review.customer_name.replace(/'/g, "\\'")}'); return false;">
                                <i class="fas fa-reply"></i> Reply
                            </button>
                        </div>
                    `}
                `}
            </div>
        `;
    }).join('');
    
    container.innerHTML = reviewsHTML;
}

function closeProductModal() {
    const modal = document.getElementById('productViewModal');
    if (modal) {
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
}

// Close modal when clicking outside
document.addEventListener('click', function(e) {
    const modal = document.getElementById('productViewModal');
    if (modal && e.target === modal) {
        closeProductModal();
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const productModal = document.getElementById('productViewModal');
        if (productModal && productModal.getAttribute('aria-hidden') === 'false') {
            closeProductModal();
        }
    }
});

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Reply to review functions
let currentReviewId = null;

function openReplyModal(reviewId, customerName) {
    console.log('openReplyModal called with reviewId:', reviewId, 'customerName:', customerName);
    currentReviewId = reviewId;
    const modal = document.getElementById('replyConfirmModal');
    const messageEl = document.getElementById('replyCustomerName');
    const textarea = document.getElementById('replyTextarea');
    const charCount = document.getElementById('replyCharCount');
    
    if (messageEl) {
        messageEl.textContent = customerName;
    }
    if (textarea) {
        textarea.value = '';
        if (charCount) charCount.textContent = '0';
    }
    
    if (modal) {
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        setTimeout(() => textarea && textarea.focus(), 100);
    }
}

function closeReplyModal() {
    const modal = document.getElementById('replyConfirmModal');
    const textarea = document.getElementById('replyTextarea');
    
    if (modal) {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
    if (textarea) {
        textarea.value = '';
    }
    currentReviewId = null;
}

function updateCharCount() {
    const textarea = document.getElementById('replyTextarea');
    const charCount = document.getElementById('replyCharCount');
    if (textarea && charCount) {
        const length = textarea.value.length;
        charCount.textContent = length;
        if (length > 500) {
            charCount.style.color = '#dc3545';
        } else {
            charCount.style.color = 'rgba(19, 3, 37, 0.6)';
        }
    }
}

function submitReply() {
    console.log('submitReply called, currentReviewId:', currentReviewId);
    const textarea = document.getElementById('replyTextarea');
    const replyText = textarea ? textarea.value.trim() : '';
    
    if (!replyText) {
        showReplyErrorModal('Please enter a reply');
        return;
    }
    
    if (replyText.length > 500) {
        showReplyErrorModal('Reply must be 500 characters or less');
        return;
    }
    
    if (currentReviewId === null || currentReviewId === undefined) {
        console.error('currentReviewId is null or undefined');
        showReplyErrorModal('Error: Review ID not found');
        return;
    }
    
    console.log('Sending reply request with:', { review_id: currentReviewId, reply_text: replyText });
    
    // Disable button to prevent double submission
    const submitBtn = document.querySelector('#replyConfirmModal .btn-primary-y');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    }
    
    fetch('ajax/seller-reply-review.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            review_id: currentReviewId,
            reply_text: replyText
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeReplyModal();
            // Reload reviews to show the new reply
            const container = document.getElementById('reviewsContainer');
            const productId = container ? container.dataset.productId : null;
            if (productId) {
                loadProductReviews(parseInt(productId));
            }
            showReplySuccessModal('Reply posted successfully!');
        } else {
            showReplyErrorModal(data.message || 'Failed to post reply');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showReplyErrorModal('Error posting reply. Please try again.');
    })
    .finally(() => {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-check"></i> Confirm';
        }
    });
}

// Success modal for seller reply
function showReplySuccessModal(message) {
    const modal = document.getElementById('replySuccessModal');
    const messageEl = document.getElementById('replySuccessMessage');
    if (messageEl) {
        messageEl.textContent = message;
    }
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function closeReplySuccessModal() {
    const modal = document.getElementById('replySuccessModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

// Error modal for seller reply
function showReplyErrorModal(message) {
    const modal = document.getElementById('replyErrorModal');
    const messageEl = document.getElementById('replyErrorMessage');
    if (messageEl) {
        messageEl.textContent = message;
    }
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function closeReplyErrorModal() {
    const modal = document.getElementById('replyErrorModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

// Store product ID when loading reviews
const originalLoadReviews = loadProductReviews;
loadProductReviews = function(productId) {
    const container = document.getElementById('reviewsContainer');
    if (container) {
        container.dataset.productId = productId;
    }
    originalLoadReviews(productId);
};

// Check if product_id is in URL and auto-open modal
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const productId = urlParams.get('product_id');
    
    if (productId) {
        // Small delay to ensure page is fully loaded
        setTimeout(function() {
            openProductModal(parseInt(productId));
            // Remove product_id from URL without refreshing
            const newUrl = window.location.pathname;
            window.history.replaceState({}, document.title, newUrl);
        }, 500);
    }
    
    // Close modals on outside click
    document.getElementById('replySuccessModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeReplySuccessModal();
    });
    document.getElementById('replyErrorModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeReplyErrorModal();
    });
    
    // Close modals on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const replySuccessModal = document.getElementById('replySuccessModal');
            const replyErrorModal = document.getElementById('replyErrorModal');
            
            if (replySuccessModal && replySuccessModal.style.display === 'flex') {
                closeReplySuccessModal();
            }
            if (replyErrorModal && replyErrorModal.style.display === 'flex') {
                closeReplyErrorModal();
            }
        }
    });
});
</script>

<!-- Product View Modal -->
<div id="productViewModal" class="product-modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="product-modal-dialog">
        <button class="product-modal-close" aria-label="Close" onclick="closeProductModal()">&times;</button>
        <div class="product-modal-body">
            <div class="product-loading">
                <i class="fas fa-spinner"></i>
                <p>Loading product details...</p>
            </div>
        </div>
    </div>
</div>

<!-- Reply Confirmation Modal -->
<div id="replyConfirmModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="modal-dialog" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div class="modal-title">Reply to Review</div>
            <button type="button" class="modal-close" onclick="closeReplyModal()">×</button>
        </div>
        <div class="modal-body">
            <p style="margin: 0 0 12px 0; color: #130325; font-size: 12px; line-height: 1.5; font-weight: 500;">
                Reply to <strong id="replyCustomerName"></strong>'s review
            </p>
            <div class="form-group" style="margin-bottom: 8px;">
                <label for="replyTextarea" style="font-size: 11px; color: #6b7280; margin-bottom: 6px; display: block; font-weight: 600;">Your Reply:</label>
                <textarea 
                    id="replyTextarea" 
                    maxlength="500" 
                    rows="4"
                    placeholder="Write your reply here..."
                    oninput="updateCharCount()"
                    style="padding: 8px 10px; border: 1px solid rgba(0,0,0,0.15); border-radius: 6px; font-size: 13px; width: 100%; font-family: inherit; resize: vertical;"></textarea>
                <div style="text-align: right; font-size: 11px; color: rgba(19, 3, 37, 0.6); margin-top: 4px;">
                    <span id="replyCharCount">0</span>/500
                </div>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-outline" onclick="closeReplyModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="button" class="btn-primary-y" onclick="submitReply()">
                <i class="fas fa-check"></i> Confirm
            </button>
        </div>
    </div>
</div>

<!-- Reply Success Modal -->
<div id="replySuccessModal" class="modal-overlay" style="display: none;">
    <div class="modal-dialog" onclick="event.stopPropagation()" style="max-width: 400px;">
        <div class="modal-body" style="text-align: center; padding: 30px;">
            <i class="fas fa-check-circle" style="font-size: 48px; color: #28a745; margin-bottom: 16px;"></i>
            <h3 style="color: #130325; font-size: 18px; font-weight: 700; margin-bottom: 12px;">Success</h3>
            <p id="replySuccessMessage" style="color: #130325; font-size: 14px; margin-bottom: 20px;"></p>
            <button type="button" class="btn-primary-y" onclick="closeReplySuccessModal()" style="padding: 8px 20px;">
                <i class="fas fa-check"></i> Got it!
            </button>
        </div>
    </div>
</div>

<!-- Reply Error Modal -->
<div id="replyErrorModal" class="modal-overlay" style="display: none;">
    <div class="modal-dialog" onclick="event.stopPropagation()" style="max-width: 400px;">
        <div class="modal-body" style="text-align: center; padding: 30px;">
            <i class="fas fa-exclamation-circle" style="font-size: 48px; color: #dc3545; margin-bottom: 16px;"></i>
            <h3 style="color: #130325; font-size: 18px; font-weight: 700; margin-bottom: 12px;">Error</h3>
            <p id="replyErrorMessage" style="color: #130325; font-size: 14px; margin-bottom: 20px;"></p>
            <button type="button" class="btn-primary-y" onclick="closeReplyErrorModal()" style="padding: 8px 20px;">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>

