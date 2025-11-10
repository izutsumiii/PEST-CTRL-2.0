<?php
/**
 * Test Script: Create Fake Admin Notifications
 * This script creates sample notifications for all admin notification types
 * 
 * Usage: Navigate to this file in your browser
 * Example: http://localhost/GITHUB_PEST-CTRL/admin/test-admin-notifications.php
 */

require_once '../config/database.php';
require_once '../includes/admin_notification_functions.php';

// Check admin login
require_once '../includes/functions.php';
if (!isLoggedIn() || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../login_admin.php");
    exit();
}

$adminId = $_SESSION['user_id'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Admin Notifications - PEST-CTRL</title>
    <link rel="icon" type="image/x-icon" href="../assets/uploads/pest_icon_216780.ico">
    <link href="../assets/css/pest-ctrl.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: var(--font-primary);
            background: linear-gradient(135deg, #130325 0%, #1a0a2e 100%);
            color: #F9F9F9;
            min-height: 100vh;
            padding: 40px 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }
        h1 {
            color: #FFD736;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            color: #9ca3af;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .notification-item {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 215, 54, 0.2);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .notification-item i {
            font-size: 24px;
            width: 40px;
            text-align: center;
        }
        .notification-item.success i { color: #28a745; }
        .notification-item.info i { color: #17a2b8; }
        .notification-item.warning i { color: #ffc107; }
        .notification-item.error i { color: #dc3545; }
        .notification-content {
            flex: 1;
        }
        .notification-title {
            font-weight: 700;
            color: #FFD736;
            margin-bottom: 5px;
            font-size: 16px;
        }
        .notification-message {
            color: #F9F9F9;
            font-size: 13px;
            opacity: 0.9;
        }
        .btn {
            background: #FFD736;
            color: #130325;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-weight: 700;
            text-decoration: none;
            display: inline-block;
            margin-top: 20px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .btn:hover {
            background: #ffed4e;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 215, 54, 0.4);
        }
        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: #F9F9F9;
            margin-left: 10px;
        }
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 10px;
        }
        .status.success {
            background: rgba(40, 167, 69, 0.2);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.4);
        }
        .status.error {
            background: rgba(220, 53, 69, 0.2);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.4);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-bell"></i> Test Admin Notifications</h1>
        <p class="subtitle">Creating sample notifications for all admin notification types</p>
        
        <?php
        try {
            $created = 0;
            $errors = [];
            
            // 1. New Product Pending Approval
            echo '<div class="notification-item info">';
            echo '<i class="fas fa-box"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">New Product Pending Approval</div>';
            echo '<div class="notification-message">Creating notification for product approval...</div>';
            echo '</div></div>';
            
            if (createAdminNotification(
                $adminId,
                "New Product Pending Approval",
                "Seller 'Andrea Mendez' has added a new product 'Original Miraculous Insecticide Chalk' (ID: #34) that requires your approval.",
                'info',
                'admin-products.php?status=pending&product_id=34'
            )) {
                $created++;
                echo '<div class="status success">✅ Created</div>';
            } else {
                $errors[] = "New Product Pending Approval";
                echo '<div class="status error">❌ Failed</div>';
            }
            
            // 2. New Seller Requests
            echo '<div class="notification-item info">';
            echo '<i class="fas fa-user-plus"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">New Seller Requests</div>';
            echo '<div class="notification-message">Creating notification for pending seller requests...</div>';
            echo '</div></div>';
            
            if (createAdminNotification(
                $adminId,
                "New Seller Requests",
                "You have 3 pending seller registration requests to review",
                'info',
                'admin-sellers.php?status=pending'
            )) {
                $created++;
                echo '<div class="status success">✅ Created</div>';
            } else {
                $errors[] = "New Seller Requests";
                echo '<div class="status error">❌ Failed</div>';
            }
            
            // 3. Product Data Issues
            echo '<div class="notification-item error">';
            echo '<i class="fas fa-exclamation-triangle"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">Product Data Issues</div>';
            echo '<div class="notification-message">Creating notification for product data issues...</div>';
            echo '</div></div>';
            
            if (createAdminNotification(
                $adminId,
                "Product Data Issues",
                "3 product(s) have data issues (missing images, invalid prices, or empty descriptions). Click to review.",
                'error',
                'admin-products.php?product_id=34'
            )) {
                $created++;
                echo '<div class="status success">✅ Created</div>';
            } else {
                $errors[] = "Product Data Issues";
                echo '<div class="status error">❌ Failed</div>';
            }
            
            // 4. Suspicious Activity Detected (Customer)
            echo '<div class="notification-item warning">';
            echo '<i class="fas fa-shield-alt"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">Suspicious Activity Detected (Customer)</div>';
            echo '<div class="notification-message">Creating notification for customer brute force attempt...</div>';
            echo '</div></div>';
            
            if (createAdminNotification(
                $adminId,
                "Suspicious Activity Detected",
                "User 'John Doe' (john.doe@example.com) has 7 failed login attempts in the last 15 minutes. Possible brute force attack.",
                'warning',
                'user-details.php?id=12'
            )) {
                $created++;
                echo '<div class="status success">✅ Created</div>';
            } else {
                $errors[] = "Suspicious Activity Detected (Customer)";
                echo '<div class="status error">❌ Failed</div>';
            }
            
            // 5. Suspicious Activity Detected (Seller)
            echo '<div class="notification-item warning">';
            echo '<i class="fas fa-shield-alt"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">Suspicious Activity Detected (Seller)</div>';
            echo '<div class="notification-message">Creating notification for seller brute force attempt...</div>';
            echo '</div></div>';
            
            if (createAdminNotification(
                $adminId,
                "Suspicious Activity Detected",
                "User 'Jane Smith' (jane.smith@seller.com) has 6 failed login attempts in the last 15 minutes. Possible brute force attack.",
                'warning',
                'seller-details.php?id=11'
            )) {
                $created++;
                echo '<div class="status success">✅ Created</div>';
            } else {
                $errors[] = "Suspicious Activity Detected (Seller)";
                echo '<div class="status error">❌ Failed</div>';
            }
            
            echo '<hr style="border: 1px solid rgba(255, 215, 54, 0.2); margin: 30px 0;">';
            echo '<h2 style="color: #FFD736; margin-bottom: 20px;">Summary</h2>';
            echo '<p style="font-size: 16px; color: #F9F9F9;">';
            echo "✅ <strong>{$created}</strong> notifications created successfully";
            if (!empty($errors)) {
                echo "<br>❌ <strong>" . count($errors) . "</strong> notifications failed: " . implode(", ", $errors);
            }
            echo '</p>';
            
        } catch (Exception $e) {
            echo '<div class="status error">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            error_log("Test admin notifications error: " . $e->getMessage());
        }
        ?>
        
        <div style="margin-top: 30px;">
            <a href="admin-notifications.php" class="btn">
                <i class="fas fa-bell"></i> View All Notifications
            </a>
            <a href="admin-dashboard.php" class="btn btn-secondary">
                <i class="fas fa-tachometer-alt"></i> Go to Dashboard
            </a>
        </div>
    </div>
</body>
</html>

