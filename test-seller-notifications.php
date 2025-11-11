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

// Check seller login
require_once 'includes/functions.php';
if (!isLoggedIn() || $_SESSION['user_type'] !== 'seller') {
    header("Location: login_seller.php");
    exit();
}

$sellerId = $_SESSION['user_id'];

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
        <h1><i class="fas fa-bell"></i> Test Seller Notifications</h1>
        <p class="subtitle">Creating sample notifications for all seller notification types</p>
        
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
                "New order #000123 from John Doe for 'Original Miraculous Insecticide Chalk'.",
                'success',
                'seller-orders.php?order_id=123'
            )) {
                $created++;
                echo '<div class="status success">✅ Created</div>';
            } else {
                $errors[] = "New Order Received";
                echo '<div class="status error">❌ Failed</div>';
            }
            
            // 2. Order Status Update
            echo '<div class="notification-item info">';
            echo '<i class="fas fa-truck"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">Order Status Update</div>';
            echo '<div class="notification-message">Creating notification for order processing...</div>';
            echo '</div></div>';
            
            if (createSellerNotification(
                $sellerId,
                "Order Processing",
                "Order #000124 has been marked as 'Processing' and is ready for shipment.",
                'info',
                'seller-orders.php?order_id=124'
            )) {
                $created++;
                echo '<div class="status success">✅ Created</div>';
            } else {
                $errors[] = "Order Status Update";
                echo '<div class="status error">❌ Failed</div>';
            }
            
            // 3. Low Stock Alert
            echo '<div class="notification-item warning">';
            echo '<i class="fas fa-exclamation-triangle"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">Low Stock Alert</div>';
            echo '<div class="notification-message">Creating notification for low stock products...</div>';
            echo '</div></div>';
            
            if (createSellerNotification(
                $sellerId,
                "Low Stock Alert",
                "Product 'Baygon Multi Insect Killer' is running low (only 8 left)",
                'warning',
                'view-products.php'
            )) {
                $created++;
                echo '<div class="status success">✅ Created</div>';
            } else {
                $errors[] = "Low Stock Alert";
                echo '<div class="status error">❌ Failed</div>';
            }
            
            // 4. New Return Request
            echo '<div class="notification-item warning">';
            echo '<i class="fas fa-undo"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">New Return Request</div>';
            echo '<div class="notification-message">Creating notification for return request...</div>';
            echo '</div></div>';
            
            if (createSellerNotification(
                $sellerId,
                "New Return Request",
                "Customer requested return for Order #000125 - Original Miraculous Insecticide Chalk",
                'warning',
                'seller-returns.php?return_id=15'
            )) {
                $created++;
                echo '<div class="status success">✅ Created</div>';
            } else {
                $errors[] = "New Return Request";
                echo '<div class="status error">❌ Failed</div>';
            }
            
            // 5. Product Approved
            echo '<div class="notification-item success">';
            echo '<i class="fas fa-check-circle"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">Product Approved</div>';
            echo '<div class="notification-message">Creating notification for product approval...</div>';
            echo '</div></div>';
            
            if (createSellerNotification(
                $sellerId,
                "Product Approved",
                "Your product 'Raid Multi-Purpose Insect Killer' has been approved by the admin and is now live on the marketplace.",
                'success',
                'view-products.php?product_id=34'
            )) {
                $created++;
                echo '<div class="status success">✅ Created</div>';
            } else {
                $errors[] = "Product Approved";
                echo '<div class="status error">❌ Failed</div>';
            }
            
            // 6. Product Rejected
            echo '<div class="notification-item error">';
            echo '<i class="fas fa-times-circle"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">Product Rejected</div>';
            echo '<div class="notification-message">Creating notification for product rejection...</div>';
            echo '</div></div>';
            
            if (createSellerNotification(
                $sellerId,
                "Product Rejected",
                "Your product 'Pesticide XYZ' has been rejected by the admin. Reason: Product does not meet marketplace standards. Please review and resubmit.",
                'error',
                'view-products.php?product_id=35'
            )) {
                $created++;
                echo '<div class="status success">✅ Created</div>';
            } else {
                $errors[] = "Product Rejected";
                echo '<div class="status error">❌ Failed</div>';
            }
            
            // 7. Return Request Approved
            echo '<div class="notification-item info">';
            echo '<i class="fas fa-check"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">Return Request Approved</div>';
            echo '<div class="notification-message">Creating notification for return approval...</div>';
            echo '</div></div>';
            
            if (createSellerNotification(
                $sellerId,
                "Return Request Approved",
                "Return request for Order #000126 has been approved. Please process the refund.",
                'info',
                'seller-returns.php?return_id=16'
            )) {
                $created++;
                echo '<div class="status success">✅ Created</div>';
            } else {
                $errors[] = "Return Request Approved";
                echo '<div class="status error">❌ Failed</div>';
            }
            
            // 8. Payment Received
            echo '<div class="notification-item success">';
            echo '<i class="fas fa-money-bill-wave"></i>';
            echo '<div class="notification-content">';
            echo '<div class="notification-title">Payment Received</div>';
            echo '<div class="notification-message">Creating notification for payment...</div>';
            echo '</div></div>';
            
            if (createSellerNotification(
                $sellerId,
                "Payment Received",
                "Payment of ₱1,250.00 received for Order #000127. Funds will be transferred to your account within 3-5 business days.",
                'success',
                'seller-orders.php?order_id=127'
            )) {
                $created++;
                echo '<div class="status success">✅ Created</div>';
            } else {
                $errors[] = "Payment Received";
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
            error_log("Test seller notifications error: " . $e->getMessage());
        }
        ?>
        
        <div style="margin-top: 30px;">
            <a href="seller-dashboard.php" class="btn">
                <i class="fas fa-tachometer-alt"></i> Go to Dashboard
            </a>
            <a href="view-products.php" class="btn btn-secondary">
                <i class="fas fa-box"></i> View Products
            </a>
        </div>
    </div>
</body>
</html>
