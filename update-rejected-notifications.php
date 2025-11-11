<?php
/**
 * One-time script to update old "Product Rejected" notification URLs
 * This updates manage-products.php URLs to view-products.php with product_id
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

// Check if user is logged in as admin
if (!isLoggedIn() || $_SESSION['user_type'] !== 'admin') {
    die('Unauthorized access. Admin only.');
}

try {
    // Update all Product Rejected notifications with old URLs
    $stmt = $pdo->prepare("
        UPDATE seller_notifications 
        SET action_url = CONCAT('view-products.php?product_id=', 
            SUBSTRING_INDEX(SUBSTRING_INDEX(message, '(ID: #', -1), ')', 1))
        WHERE title LIKE '%Product Rejected%' 
        AND action_url = 'manage-products.php'
        AND message LIKE '%(ID: #%'
    ");
    
    $result = $stmt->execute();
    $affectedRows = $stmt->rowCount();
    
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Update Notifications - PEST-CTRL</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                padding: 40px;
                background: linear-gradient(135deg, #130325 0%, #1a0a2e 100%);
                color: #F9F9F9;
                min-height: 100vh;
            }
            .container {
                max-width: 600px;
                margin: 0 auto;
                background: rgba(255, 255, 255, 0.05);
                padding: 30px;
                border-radius: 12px;
                box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            }
            h1 { color: #FFD736; }
            .success { 
                background: rgba(40, 167, 69, 0.2);
                color: #28a745;
                padding: 15px;
                border-radius: 8px;
                border: 1px solid rgba(40, 167, 69, 0.4);
                margin: 20px 0;
            }
            .btn {
                background: #FFD736;
                color: #130325;
                padding: 10px 20px;
                text-decoration: none;
                border-radius: 6px;
                display: inline-block;
                margin-top: 20px;
                font-weight: bold;
            }
            .btn:hover {
                background: #ffed4e;
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <h1>✅ Notifications Updated</h1>
            <div class='success'>
                <strong>Success!</strong><br>
                Updated {$affectedRows} 'Product Rejected' notification(s) to use the new URL format.<br>
                <small>Old: manage-products.php → New: view-products.php?product_id=X</small>
            </div>
            <p>All existing 'Product Rejected' notifications now redirect to view-products.php with the specific product ID.</p>
            <a href='admin/admin-dashboard.php' class='btn'>← Back to Dashboard</a>
        </div>
    </body>
    </html>";
    
} catch (Exception $e) {
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Error - PEST-CTRL</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                padding: 40px;
                background: linear-gradient(135deg, #130325 0%, #1a0a2e 100%);
                color: #F9F9F9;
            }
            .container {
                max-width: 600px;
                margin: 0 auto;
                background: rgba(255, 255, 255, 0.05);
                padding: 30px;
                border-radius: 12px;
            }
            h1 { color: #FFD736; }
            .error { 
                background: rgba(220, 53, 69, 0.2);
                color: #dc3545;
                padding: 15px;
                border-radius: 8px;
                border: 1px solid rgba(220, 53, 69, 0.4);
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <h1>❌ Error</h1>
            <div class='error'>
                <strong>Error updating notifications:</strong><br>
                " . htmlspecialchars($e->getMessage()) . "
            </div>
        </div>
    </body>
    </html>";
    error_log("Error updating rejected notifications: " . $e->getMessage());
}
?>

