<?php
require_once __DIR__ . '/../config/database.php';

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
            WHERE admin_id = ? AND (is_read = 0 OR is_read = FALSE)
        ");
        $stmt->execute([$adminId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['count'] ?? 0);
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
            SELECT *,
                   CONVERT_TZ(created_at, '+00:00', '+08:00') as created_at_ph
            FROM admin_notifications 
            WHERE admin_id = ? 
            ORDER BY created_at DESC 
            LIMIT " . intval($limit)
        );
        $stmt->execute([$adminId]);
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
 * Delete all admin notifications
 */
function deleteAllAdminNotifications($adminId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("DELETE FROM admin_notifications WHERE admin_id = ?");
        return $stmt->execute([$adminId]);
    } catch (Exception $e) {
        error_log("Error deleting all admin notifications: " . $e->getMessage());
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
 * Check for product data issues (missing images, invalid prices, empty descriptions)
 */
function checkAdminSystemErrors($adminId) {
    global $pdo;
    
    try {
        // Check for products with data issues
        $stmt = $pdo->prepare("
            SELECT id, name 
            FROM products 
            WHERE status = 'active'
              AND (
                (image_url IS NULL OR image_url = '' OR image_url = 'images/placeholder.jpg')
             OR (price IS NULL OR price <= 0)
             OR (description IS NULL OR description = '')
              )
            LIMIT 1
        ");
        $stmt->execute();
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product) {
            // Count total issues
            $countStmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM products 
                WHERE status = 'active'
                  AND (
                    (image_url IS NULL OR image_url = '' OR image_url = 'images/placeholder.jpg')
                 OR (price IS NULL OR price <= 0)
                 OR (description IS NULL OR description = '')
                  )
            ");
            $countStmt->execute();
            $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
            $issueCount = $countResult['count'] ?? 1;
            
            createAdminNotification(
                $adminId,
                "Product Data Issues",
                "{$issueCount} product(s) have data issues (missing images, invalid prices, or empty descriptions). Click to review.",
                'error',
                'admin-products.php?product_id=' . $product['id']
            );
            return $issueCount;
        }
        
        return 0;
    } catch (Exception $e) {
        error_log("Error checking admin system errors: " . $e->getMessage());
        return 0;
    }
}

/**
 * Check for suspicious activity (brute force login attempts)
 */
function checkAdminSuspiciousActivity($adminId) {
    global $pdo;
    
    try {
        // Create login_attempts table if it doesn't exist
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS login_attempts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(255) NOT NULL,
                user_id INT UNSIGNED NULL,
                user_type VARCHAR(50) NULL,
                ip_address VARCHAR(45) NULL,
                success TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_username_created (username, created_at),
                INDEX idx_user_id_created (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // Check for users with 5+ failed login attempts in last 15 minutes
        $stmt = $pdo->prepare("
            SELECT username, user_id, user_type, COUNT(*) as attempt_count
            FROM login_attempts
            WHERE success = 0 
              AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            GROUP BY username, user_id, user_type
            HAVING attempt_count >= 5
            LIMIT 1
        ");
        $stmt->execute();
        $suspicious = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($suspicious) {
            // Get user display name
            $displayName = 'Unknown User';
            $redirectUrl = 'admin-dashboard.php';
            
            if ($suspicious['user_id']) {
                $userStmt = $pdo->prepare("
                    SELECT id, username, display_name, first_name, last_name, user_type 
                    FROM users 
                    WHERE id = ?
                ");
                $userStmt->execute([$suspicious['user_id']]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    $displayName = $user['display_name'] ?? trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $user['username'];
                    
                    // Set redirect based on user type
                    if ($user['user_type'] === 'customer') {
                        $redirectUrl = 'user-details.php?id=' . $user['id'];
                    } elseif ($user['user_type'] === 'seller') {
                        $redirectUrl = 'seller-details.php?id=' . $user['id'];
                    }
                }
            } else {
                $displayName = $suspicious['username'];
            }
            
            createAdminNotification(
                $adminId,
                "Suspicious Activity Detected",
                "User '{$displayName}' ({$suspicious['username']}) has {$suspicious['attempt_count']} failed login attempts in the last 15 minutes. Possible brute force attack.",
                'warning',
                $redirectUrl
            );
            return 1;
        }
        
        return 0;
    } catch (Exception $e) {
        error_log("Error checking admin suspicious activity: " . $e->getMessage());
        return 0;
    }
}

/**
 * Run all admin notification checks
 */
function runAllAdminNotificationChecks($adminId) {
    // Only check if we haven't checked in the last 5 minutes (to avoid spam)
    $cacheKey = "admin_notification_checks_{$adminId}";
    $lastCheck = $_SESSION[$cacheKey] ?? 0;
    
    if (time() - $lastCheck < 300) { // 5 minutes
        return;
    }
    
    $_SESSION[$cacheKey] = time();
    
    // Check for existing notifications in the last hour to avoid duplicates
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM admin_notifications 
            WHERE admin_id = ? 
              AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
              AND title IN ('New Seller Requests', 'Product Data Issues', 'Suspicious Activity Detected')
        ");
        $stmt->execute([$adminId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Only create new notifications if we don't have too many recent ones
        if (($result['count'] ?? 0) < 10) {
            checkAdminNewSellerRequests($adminId);
            checkAdminSystemErrors($adminId);
            checkAdminSuspiciousActivity($adminId);
        }
    } catch (Exception $e) {
        error_log("Error in runAllAdminNotificationChecks: " . $e->getMessage());
    }
}
?>
