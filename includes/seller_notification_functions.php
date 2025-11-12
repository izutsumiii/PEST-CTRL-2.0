<?php
require_once __DIR__ . '/../config/database.php';

/**
 * Create a notification for a seller
 */
function createSellerNotification($sellerId, $title, $message, $type = 'info', $actionUrl = null) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO seller_notifications (seller_id, title, message, type, action_url) 
            VALUES (?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$sellerId, $title, $message, $type, $actionUrl]);
    } catch (Exception $e) {
        error_log("Error creating seller notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Get unread notification count for a seller
 */
function getSellerUnreadCount($sellerId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM seller_notifications 
            WHERE seller_id = ? AND is_read = FALSE
        ");
        $stmt->execute([$sellerId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] ?? 0;
    } catch (Exception $e) {
        error_log("Error getting seller unread count: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get recent notifications for a seller
 */
function getSellerNotifications($sellerId, $limit = 10) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT *,
                   CONVERT_TZ(created_at, '+00:00', '+08:00') as created_at_ph
            FROM seller_notifications 
            WHERE seller_id = ? 
            ORDER BY created_at DESC 
            LIMIT " . intval($limit)
        );
        $stmt->execute([$sellerId]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Use Philippine time for created_at
        foreach ($notifications as &$notification) {
            if (isset($notification['created_at_ph'])) {
                $notification['created_at'] = $notification['created_at_ph'];
                unset($notification['created_at_ph']);
            }
        }
        
        return $notifications;
    } catch (Exception $e) {
        error_log("Error getting seller notifications: " . $e->getMessage());
        return [];
    }
}

/**
 * Mark seller notification as read
 */
function markSellerNotificationAsRead($notificationId, $sellerId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE seller_notifications 
            SET is_read = TRUE 
            WHERE id = ? AND seller_id = ?
        ");
        return $stmt->execute([$notificationId, $sellerId]);
    } catch (Exception $e) {
        error_log("Error marking seller notification as read: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark all seller notifications as read
 */
function markAllSellerNotificationsAsRead($sellerId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE seller_notifications 
            SET is_read = TRUE 
            WHERE seller_id = ? AND is_read = FALSE
        ");
        return $stmt->execute([$sellerId]);
    } catch (Exception $e) {
        error_log("Error marking all seller notifications as read: " . $e->getMessage());
        return false;
    }
}

/**
 * Check for low stock and create notifications
 */
function checkSellerLowStock($sellerId) {
    global $pdo;
    
    try {
        // Get products with low stock (less than 10)
        $stmt = $pdo->prepare("
            SELECT name, stock_quantity 
            FROM products 
            WHERE seller_id = ? AND stock_quantity <= 10 AND status = 'active'
        ");
        $stmt->execute([$sellerId]);
        $lowStockProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($lowStockProducts as $product) {
            createSellerNotification(
                $sellerId,
                "Low Stock Alert",
                "Product '{$product['name']}' is running low (only {$product['stock_quantity']} left)",
                'warning',
                'manage-products.php'
            );
        }
        
        return count($lowStockProducts);
    } catch (Exception $e) {
        error_log("Error checking seller low stock: " . $e->getMessage());
        return 0;
    }
}
// Add this function to create low stock notifications
function createLowStockNotification($sellerId, $productId, $productName, $stockQuantity) {
    global $pdo;
    
    $title = "Low Stock Alert";
    $message = "Product '{$productName}' is running low on stock. Only {$stockQuantity} items remaining.";
    $type = "warning";
    $actionUrl = "edit-product.php?id={$productId}";
    
    // Check if notification already exists for this product
    $stmt = $pdo->prepare("SELECT id FROM seller_notifications 
                          WHERE seller_id = ? AND type = 'warning' 
                          AND action_url = ? AND is_read = 0");
    $stmt->execute([$sellerId, $actionUrl]);
    
    if (!$stmt->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO seller_notifications 
                              (seller_id, title, message, type, action_url, created_at) 
                              VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$sellerId, $title, $message, $type, $actionUrl]);
    }
}

// Add this function to create new order notifications
function createNewOrderNotification($sellerId, $orderId, $customerName, $productName) {
    global $pdo;
    
    $title = "New Order Received";
    $message = "New order #" . str_pad($orderId, 6, '0', STR_PAD_LEFT) . " from {$customerName} for '{$productName}'.";
    $type = "success";
    $actionUrl = "seller-orders.php?order_id={$orderId}";
    
    $stmt = $pdo->prepare("INSERT INTO seller_notifications 
                          (seller_id, title, message, type, action_url, created_at) 
                          VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$sellerId, $title, $message, $type, $actionUrl]);
}

// Seller notification helper functions

function createReturnRequestNotification($sellerId, $returnRequestId, $orderId, $productName) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO seller_notifications 
            (seller_id, type, title, message, action_url, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");
        
        $title = "New Return Request";
        $message = "Customer requested return for Order #" . str_pad($orderId, 6, '0', STR_PAD_LEFT) . " - " . htmlspecialchars($productName);
        $actionUrl = "seller-returns.php?return_id=" . $returnRequestId;
        
        return $stmt->execute([
            $sellerId,
            'warning',
            $title,
            $message,
            $actionUrl
        ]);
    } catch (Exception $e) {
        error_log("Notification creation error: " . $e->getMessage());
        return false;
    }
}
?>
