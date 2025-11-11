<?php
/**
 * Test Script: Create Fake Seller Notifications
 * This script creates sample notifications for all seller notification types
 * 
 * Usage: Navigate to this file in your browser
 * Example: http://localhost/GITHUB_PEST-CTRL/test-seller-notifications.php
 */

require_once 'config/database.php';
require_once 'includes/seller_notification_functions.php';

// Get agritech seller user ID
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'agritech' AND user_type = 'seller'");
$stmt->execute();
$seller = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$seller) {
    die("Error: AgriTech seller user not found. Please make sure the user exists.");
}

$sellerId = $seller['id'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Seller Notifications - PEST-CTRL</title>
    <link rel="icon" type="image/x-icon" href="assets/uploads/pest_icon_216780.ico">
    <link href="assets/css/pest-ctrl.css" rel="stylesheet">
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
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn:hover {
            background: #ffc107;
            transform: translateY(-2px);
        }
        .status {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 700;
            margin-left: 10px;
        }
        .status.success {
            background: #28a745;
            color: #fff;
        }
        .status.error {
            background: #dc3545;
            color: #fff;
        }
        .summary {
            background: rgba(255, 215, 54, 0.1);
            border: 1px solid rgba(255, 215, 54, 0.3);
            border-radius: 8px;
            padding: 20px;
            margin-top: 30px;
        }
        .summary h2 {
            color: #FFD736;
            margin-bottom: 15px;
            font-size: 20px;
        }
        .summary p {
            color: #F9F9F9;
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-bell"></i> Test Seller Notifications</h1>
        <p class="subtitle">Creating sample notifications for AgriTech seller (ID: <?php echo $sellerId; ?>) - All notification types</p>
        
        <?php
        try {
            $created = 0;
            $errors = [];
            
            // 1. New Order Received
            echo '<div class="notification-item success">';
            echo '<i class="fas fa-shopping-cart"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">New Order Received</div>';
            echo '<div class="notification-message">Creating notification for new order...</div>';
            echo '</div></div>';
            
            if (createSellerNotification(
                $sellerId,
                "New Order Received",
                "You have a new order #000300 totaling ₱1,250.00. Please review and process it.",
                'success',
                'seller-order-details.php?order_id=300'
            )) {
                $created++;
                echo '<div class="status success">Created</div>';
            } else {
                $errors[] = "New Order Received";
                echo '<div class="status error">Failed</div>';
            }
            
            // 2. New Order Received (Alternative format)
            echo '<div class="notification-item success">';
            echo '<i class="fas fa-shopping-cart"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">New Order Received (Alternative)</div>';
            echo '<div class="notification-message">Creating notification using createNewOrderNotification...</div>';
            echo '</div></div>';
            
            if (createNewOrderNotification($sellerId, 301, "John Doe", "Organic Pesticide Spray")) {
                $created++;
                echo '<div class="status success">Created</div>';
            } else {
                $errors[] = "New Order Received (Alternative)";
                echo '<div class="status error">Failed</div>';
            }
            
            // 3. Low Stock Alert (Specific Product)
            echo '<div class="notification-item warning">';
            echo '<i class="fas fa-exclamation-triangle"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">Low Stock Alert (Specific Product)</div>';
            echo '<div class="notification-message">Creating low stock notification for specific product...</div>';
            echo '</div></div>';
            
            if (createLowStockNotification($sellerId, 25, "Organic Pesticide Spray", 5)) {
                $created++;
                echo '<div class="status success">Created</div>';
            } else {
                $errors[] = "Low Stock Alert (Specific Product)";
                echo '<div class="status error">Failed</div>';
            }
            
            // 4. Low Stock Alert (General)
            echo '<div class="notification-item warning">';
            echo '<i class="fas fa-exclamation-triangle"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">Low Stock Alert (General)</div>';
            echo '<div class="notification-message">Creating general low stock notification...</div>';
            echo '</div></div>';
            
            if (createSellerNotification(
                $sellerId,
                "Low Stock Alert",
                "Product 'Garden Fertilizer' is running low (only 8 left)",
                'warning',
                'manage-products.php'
            )) {
                $created++;
                echo '<div class="status success">Created</div>';
            } else {
                $errors[] = "Low Stock Alert (General)";
                echo '<div class="status error">Failed</div>';
            }
            
            // 5. New Return Request
            echo '<div class="notification-item warning">';
            echo '<i class="fas fa-undo"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">New Return Request</div>';
            echo '<div class="notification-message">Creating return request notification...</div>';
            echo '</div></div>';
            
            if (createReturnRequestNotification($sellerId, 10, 250, "Organic Pesticide Spray")) {
                $created++;
                echo '<div class="status success">Created</div>';
            } else {
                $errors[] = "New Return Request";
                echo '<div class="status error">Failed</div>';
            }
            
            // 6. Product Approved
            echo '<div class="notification-item success">';
            echo '<i class="fas fa-check-circle"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">Product Approved</div>';
            echo '<div class="notification-message">Creating product approval notification...</div>';
            echo '</div></div>';
            
            if (createSellerNotification(
                $sellerId,
                "Product Approved",
                "Your product 'Premium Garden Tools Set' (ID: #45) has been approved by admin and is now active and visible to customers.",
                'success',
                'manage-products.php'
            )) {
                $created++;
                echo '<div class="status success">Created</div>';
            } else {
                $errors[] = "Product Approved";
                echo '<div class="status error">Failed</div>';
            }
            
            // 7. Product Rejected
            echo '<div class="notification-item warning">';
            echo '<i class="fas fa-times-circle"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">Product Rejected</div>';
            echo '<div class="notification-message">Creating product rejection notification...</div>';
            echo '</div></div>';
            
            if (createSellerNotification(
                $sellerId,
                "Product Rejected",
                "Your product 'Test Product Name' (ID: #46) has been rejected by admin. Please review and resubmit if needed.",
                'warning',
                'manage-products.php'
            )) {
                $created++;
                echo '<div class="status success">Created</div>';
            } else {
                $errors[] = "Product Rejected";
                echo '<div class="status error">Failed</div>';
            }
            
            // 8. Product Suspended
            echo '<div class="notification-item warning">';
            echo '<i class="fas fa-pause-circle"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">Product Suspended</div>';
            echo '<div class="notification-message">Creating product suspension notification...</div>';
            echo '</div></div>';
            
            if (createSellerNotification(
                $sellerId,
                "Product Suspended",
                "Your product 'Suspended Product Example' (ID: #47) has been suspended by admin. It is temporarily hidden from customers.",
                'warning',
                'manage-products.php'
            )) {
                $created++;
                echo '<div class="status success">Created</div>';
            } else {
                $errors[] = "Product Suspended";
                echo '<div class="status error">Failed</div>';
            }
            
            // 9. Product Reactivated
            echo '<div class="notification-item success">';
            echo '<i class="fas fa-check-circle"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">Product Reactivated</div>';
            echo '<div class="notification-message">Creating product reactivation notification...</div>';
            echo '</div></div>';
            
            if (createSellerNotification(
                $sellerId,
                "Product Reactivated",
                "Your product 'Reactivated Product Example' (ID: #48) has been reactivated by admin and is now active and visible to customers again.",
                'success',
                'manage-products.php'
            )) {
                $created++;
                echo '<div class="status success">Created</div>';
            } else {
                $errors[] = "Product Reactivated";
                echo '<div class="status error">Failed</div>';
            }
            
            // 10. Order Completed
            echo '<div class="notification-item success">';
            echo '<i class="fas fa-check"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">Order Completed</div>';
            echo '<div class="notification-message">Creating order completed notification...</div>';
            echo '</div></div>';
            
            if (createSellerNotification(
                $sellerId,
                'Order Completed',
                'Order #000302 has been completed by customer',
                'success',
                'seller-order-details.php?order_id=302'
            )) {
                $created++;
                echo '<div class="status success">Created</div>';
            } else {
                $errors[] = "Order Completed";
                echo '<div class="status error">Failed</div>';
            }
            
            // 11. Order Status Updated
            echo '<div class="notification-item info">';
            echo '<i class="fas fa-info-circle"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">Order Status Updated</div>';
            echo '<div class="notification-message">Creating order status update notification...</div>';
            echo '</div></div>';
            
            if (createSellerNotification(
                $sellerId,
                'Order Status Updated',
                'Order #000303 status has been updated to Processing',
                'info',
                'seller-order-details.php?order_id=303'
            )) {
                $created++;
                echo '<div class="status success">Created</div>';
            } else {
                $errors[] = "Order Status Updated";
                echo '<div class="status error">Failed</div>';
            }
            
            // 12. Account Suspended
            echo '<div class="notification-item warning">';
            echo '<i class="fas fa-ban"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">Account Suspended</div>';
            echo '<div class="notification-message">Creating account suspension notification...</div>';
            echo '</div></div>';
            
            if (createSellerNotification(
                $sellerId,
                "Account Suspended",
                "Your seller account has been suspended by admin. Reason: Test suspension for notification testing.",
                'warning',
                'seller-dashboard.php'
            )) {
                $created++;
                echo '<div class="status success">Created</div>';
            } else {
                $errors[] = "Account Suspended";
                echo '<div class="status error">Failed</div>';
            }
            
            // Summary
            echo '<div class="summary">';
            echo '<h2>Summary</h2>';
            echo "<p><strong>{$created}</strong> notifications created successfully</p>";
            if (count($errors) > 0) {
                echo "<p><strong>" . count($errors) . "</strong> notifications failed: " . implode(", ", $errors) . "</p>";
            }
            echo '<p style="margin-top: 15px;">You can now test the notifications in:</p>';
            echo '<ul style="margin-left: 20px; margin-top: 10px;">';
            echo '<li><a href="seller-notifications.php" style="color: #FFD736;">Seller Notifications Page</a></li>';
            echo '<li>Seller Dashboard Dropdown (bell icon in header)</li>';
            echo '</ul>';
            echo '</div>';
            
        } catch (Exception $e) {
            echo '<div class="status error">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        ?>
        
        <a href="seller-notifications.php" class="btn">
            <i class="fas fa-arrow-right"></i> View Notifications
        </a>
    </div>
</body>
</html>

