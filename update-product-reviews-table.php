<?php
/**
 * Database Migration: Add Review Reply and Hide Features to product_reviews table
 * Run this once to add new columns for seller replies and admin hide functionality
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

// Check if user is logged in as admin
if (!isLoggedIn() || $_SESSION['user_type'] !== 'admin') {
    die('Unauthorized access. Admin only.');
}

$errors = [];
$success = [];

try {
    // Check if columns already exist
    $stmt = $pdo->query("SHOW COLUMNS FROM product_reviews LIKE 'seller_reply'");
    $sellerReplyExists = $stmt->rowCount() > 0;
    
    if (!$sellerReplyExists) {
        // Add seller reply columns
        $pdo->exec("ALTER TABLE product_reviews ADD COLUMN seller_reply TEXT NULL AFTER review_text");
        $success[] = "Added 'seller_reply' column";
        
        $pdo->exec("ALTER TABLE product_reviews ADD COLUMN seller_replied_at TIMESTAMP NULL AFTER seller_reply");
        $success[] = "Added 'seller_replied_at' column";
        
        // Add hidden review columns
        $pdo->exec("ALTER TABLE product_reviews ADD COLUMN is_hidden BOOLEAN DEFAULT FALSE AFTER seller_replied_at");
        $success[] = "Added 'is_hidden' column";
        
        $pdo->exec("ALTER TABLE product_reviews ADD COLUMN hidden_by INT NULL AFTER is_hidden");
        $success[] = "Added 'hidden_by' column";
        
        $pdo->exec("ALTER TABLE product_reviews ADD COLUMN hidden_at TIMESTAMP NULL AFTER hidden_by");
        $success[] = "Added 'hidden_at' column";
        
        $pdo->exec("ALTER TABLE product_reviews ADD COLUMN hidden_reason VARCHAR(255) NULL AFTER hidden_at");
        $success[] = "Added 'hidden_reason' column";
        
        // Add index for is_hidden for faster queries
        $pdo->exec("ALTER TABLE product_reviews ADD INDEX idx_is_hidden (is_hidden)");
        $success[] = "Added index on 'is_hidden' column";
        
    } else {
        $errors[] = "Columns already exist. No changes made.";
    }
    
} catch (Exception $e) {
    $errors[] = "Error: " . $e->getMessage();
    error_log("Database migration error: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Migration - PEST-CTRL</title>
    <link rel="icon" type="image/x-icon" href="assets/uploads/pest_icon_216780.ico">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #130325 0%, #1a0a2e 100%);
            color: #F9F9F9;
            min-height: 100vh;
            padding: 40px 20px;
            margin: 0;
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
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .subtitle {
            color: #9ca3af;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .message-box {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .success-box {
            background: rgba(40, 167, 69, 0.2);
            border: 1px solid rgba(40, 167, 69, 0.4);
            color: #28a745;
        }
        .error-box {
            background: rgba(220, 53, 69, 0.2);
            border: 1px solid rgba(220, 53, 69, 0.4);
            color: #dc3545;
        }
        .message-icon {
            font-size: 20px;
            flex-shrink: 0;
        }
        .message-content {
            flex: 1;
        }
        .message-list {
            margin: 10px 0 0 0;
            padding-left: 20px;
        }
        .message-list li {
            margin-bottom: 5px;
        }
        .btn {
            background: #FFD736;
            color: #130325;
            padding: 12px 24px;
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
        .info-box {
            background: rgba(23, 162, 184, 0.2);
            border: 1px solid rgba(23, 162, 184, 0.4);
            color: #17a2b8;
            padding: 15px 20px;
            border-radius: 8px;
            margin-top: 30px;
        }
        .info-box h3 {
            margin: 0 0 10px 0;
            font-size: 16px;
        }
        .info-box ul {
            margin: 5px 0;
            padding-left: 20px;
        }
        .info-box li {
            margin-bottom: 5px;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>
            <span>🗄️</span>
            Database Migration: Product Reviews
        </h1>
        <p class="subtitle">Adding seller reply and admin hide functionality to product_reviews table</p>
        
        <?php if (!empty($success)): ?>
            <div class="success-box message-box">
                <div class="message-icon">✅</div>
                <div class="message-content">
                    <strong>Migration Successful!</strong>
                    <ul class="message-list">
                        <?php foreach ($success as $msg): ?>
                            <li><?php echo htmlspecialchars($msg); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="error-box message-box">
                <div class="message-icon">❌</div>
                <div class="message-content">
                    <strong>Migration Issues:</strong>
                    <ul class="message-list">
                        <?php foreach ($errors as $msg): ?>
                            <li><?php echo htmlspecialchars($msg); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="info-box">
            <h3>📋 New Columns Added:</h3>
            <ul>
                <li><code>seller_reply</code> - TEXT - Stores seller's reply to the review</li>
                <li><code>seller_replied_at</code> - TIMESTAMP - When seller replied</li>
                <li><code>is_hidden</code> - BOOLEAN - If admin hid the review</li>
                <li><code>hidden_by</code> - INT - Admin user ID who hid it</li>
                <li><code>hidden_at</code> - TIMESTAMP - When it was hidden</li>
                <li><code>hidden_reason</code> - VARCHAR(255) - Reason for hiding</li>
            </ul>
        </div>
        
        <div style="margin-top: 30px;">
            <a href="admin/admin-dashboard.php" class="btn">
                ← Back to Dashboard
            </a>
        </div>
    </div>
</body>
</html>

