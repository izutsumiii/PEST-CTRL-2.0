<?php
require_once 'config/database.php';

/**
 * Create a notification for an admin
 */
function createAdminNotification($adminId, $title, $message, $type = 'info', $actionUrl = null) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO admin_notifications (admin_id, title, message, type, action_url) 
            VALUES (?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$adminId, $title, $message, $type, $actionUrl]);
    } catch (Exception $e) {
        error_log("Error creating admin notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Get unread notification count for an admin
 */
function getAdminUnreadCount($adminId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM admin_notifications 
            WHERE admin_id = ? AND is_read = FALSE
        ");
        $stmt->execute([$adminId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] ?? 0;
    } catch (Exception $e) {
        error_log("Error getting admin unread count: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get recent notifications for an admin
 */
function getAdminNotifications($adminId, $limit = 10) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM admin_notifications 
            WHERE admin_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$adminId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting admin notifications: " . $e->getMessage());
        return [];
    }
}

/**
 * Mark admin notification as read
 */
function markAdminNotificationAsRead($notificationId, $adminId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE admin_notifications 
            SET is_read = TRUE 
            WHERE id = ? AND admin_id = ?
        ");
        return $stmt->execute([$notificationId, $adminId]);
    } catch (Exception $e) {
        error_log("Error marking admin notification as read: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark all admin notifications as read
 */
function markAllAdminNotificationsAsRead($adminId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE admin_notifications 
            SET is_read = TRUE 
            WHERE admin_id = ? AND is_read = FALSE
        ");
        return $stmt->execute([$adminId]);
    } catch (Exception $e) {
        error_log("Error marking all admin notifications as read: " . $e->getMessage());
        return false;
    }
}

/**
 * Check for new seller requests and create notifications
 */
function checkAdminNewSellerRequests($adminId) {
    global $pdo;
    
    try {
        // Get pending seller requests
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM users 
            WHERE user_type = 'seller' AND status = 'pending'
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $pendingCount = $result['count'] ?? 0;
        
        if ($pendingCount > 0) {
            createAdminNotification(
                $adminId,
                "New Seller Requests",
                "You have {$pendingCount} pending seller registration requests to review",
                'info',
                'admin-sellers.php'
            );
        }
        
        return $pendingCount;
    } catch (Exception $e) {
        error_log("Error checking admin new seller requests: " . $e->getMessage());
        return 0;
    }
}

/**
 * Check for new return requests and create notifications
 */
function checkAdminNewReturnRequests($adminId) {
    global $pdo;
    
    try {
        // Get pending return requests
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM return_requests 
            WHERE status = 'pending'
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $pendingCount = $result['count'] ?? 0;
        
        if ($pendingCount > 0) {
            createAdminNotification(
                $adminId,
                "Pending Return Requests",
                "There are {$pendingCount} return requests pending seller approval",
                'warning',
                'admin-dashboard.php'
            );
        }
        
        return $pendingCount;
    } catch (Exception $e) {
        error_log("Error checking admin new return requests: " . $e->getMessage());
        return 0;
    }
}
?>
