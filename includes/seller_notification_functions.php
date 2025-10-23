<?php
require_once 'config/database.php';

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
            SELECT * FROM seller_notifications 
            WHERE seller_id = ? 
            ORDER BY created_at DESC 
            LIMIT " . intval($limit)
        );
        $stmt->execute([$sellerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
?>
