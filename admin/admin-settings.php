<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
// Access control BEFORE any output
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ../login_admin.php');
    exit();
}

require_once 'includes/admin_header.php';

$success = '';
$error = '';

// Get current grace period setting
$currentGracePeriod = 5;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'order_grace_period'");
    $stmt->execute();
    $val = $stmt->fetchColumn();
    if ($val !== false && $val !== null) {
        $currentGracePeriod = (int)$val;
    }
} catch (PDOException $e) {
    // table might not exist yet, ignore here
}

// Handle update
if (isset($_POST['update_settings'])) {
    $gracePeriod = intval($_POST['grace_period']);
    
    // Validate grace period (between 1 and 60 minutes)
    if ($gracePeriod < 1 || $gracePeriod > 60) {
        $error = "Grace period must be between 1 and 60 minutes.";
    } else {
        try {
            // Ensure settings table exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) NOT NULL UNIQUE,
                setting_value TEXT NOT NULL,
                description TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // Check if setting exists
            $stmt = $pdo->prepare("SELECT id FROM settings WHERE setting_key = 'order_grace_period'");
            $stmt->execute();
            $exists = $stmt->fetch();
            
            if ($exists) {
                // Update existing setting
                $stmt = $pdo->prepare("UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = 'order_grace_period'");
                $stmt->execute([$gracePeriod]);
            } else {
                // Insert new setting
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES ('order_grace_period', ?, NOW(), NOW())");
                $stmt->execute([$gracePeriod]);
            }
            
            $currentGracePeriod = $gracePeriod;
            $success = "Grace period updated successfully to {$gracePeriod} minutes.";
        } catch (PDOException $e) {
            $error = "Error updating settings: " . $e->getMessage();
        }
    }
}
?>

<style>
/* Settings Container - White Theme */
.settings-container {
    max-width: 900px !important;
    margin: 32px auto !important;
    padding: 32px 28px !important;
    background: #ffffff !important;
    border: 2px solid #e5e7eb !important;
    border-radius: 12px !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06) !important;
    color: #130325 !important;
}

.setting-group {
    margin-bottom: 28px !important;
    padding: 24px 20px !important;
    background: #ffffff !important;
    border: 2px solid #e5e7eb !important;
    border-radius: 12px !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06) !important;
}

.setting-group h3 {
    color: #130325 !important;
    font-size: 18px !important;
    font-weight: 700 !important;
    margin-top: 0 !important;
    margin-bottom: 16px !important;
    border-bottom: 2px solid #f3f4f6 !important;
    padding-bottom: 12px !important;
}

.setting-description {
    color: #6b7280 !important;
    font-size: 14px !important;
    line-height: 1.6 !important;
}

.setting-description ul,
.setting-description ol {
    margin-top: 8px;
    padding-left: 20px;
}

.setting-description li {
    margin-bottom: 8px;
}

.settings-container input,
.settings-container select,
.settings-container textarea {
    background: #ffffff !important;
    border: 2px solid #e5e7eb !important;
    color: #130325 !important;
    padding: 10px 14px !important;
    border-radius: 8px !important;
    font-size: 14px !important;
    transition: all 0.2s ease !important;
}

.settings-container input:focus,
.settings-container select:focus,
.settings-container textarea:focus {
    border-color: #FFD736 !important;
    box-shadow: 0 0 0 3px rgba(255, 215, 54, 0.1) !important;
    outline: none !important;
}

.settings-container label {
    font-size: 13px !important;
    font-weight: 600 !important;
    color: #130325 !important;
    margin-bottom: 8px !important;
    display: block;
}

.input-group {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 12px;
}

.input-suffix {
    color: #6b7280 !important;
    font-weight: 600 !important;
    font-size: 14px !important;
}

.current-value {
    background: #f9fafb !important;
    border: 2px solid #e5e7eb !important;
    border-left: 4px solid #FFD736 !important;
    padding: 12px 16px !important;
    border-radius: 8px !important;
    margin-top: 12px !important;
    color: #130325 !important;
    font-size: 14px !important;
}

.warning-box {
    background: #fffbeb !important;
    border: 2px solid #fde68a !important;
    color: #92400e !important;
    padding: 14px 16px !important;
    border-radius: 8px !important;
    margin-top: 16px !important;
    font-size: 13px !important;
}

.btn {
    background: linear-gradient(135deg, #FFD736 0%, #FFC107 100%) !important;
    color: #130325 !important;
    padding: 12px 24px !important;
    border: 2px solid #FFD736 !important;
    border-radius: 8px !important;
    font-size: 14px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
    box-shadow: 0 2px 6px rgba(255, 215, 54, 0.2) !important;
}

.btn:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 4px 12px rgba(255, 215, 54, 0.3) !important;
}

.success-message {
    background: #f0fdf4 !important;
    color: #166534 !important;
    border: 2px solid #86efac !important;
    border-radius: 8px !important;
    padding: 14px 18px !important;
    margin-bottom: 20px !important;
    font-size: 14px !important;
    font-weight: 600 !important;
}

.error-message {
    background: #fef2f2 !important;
    color: #991b1b !important;
    border: 2px solid #fca5a5 !important;
    border-radius: 8px !important;
    padding: 14px 18px !important;
    margin-bottom: 20px !important;
    font-size: 14px !important;
    font-weight: 600 !important;
}

.page-header {
    margin-top: 50px;
    margin-bottom: 20px;
}

.page-header h1 {
    color: #130325;
    font-size: 24px;
    font-weight: 700;
}
</style>

<main>
<div class="page-header" style="margin-top: 50px;">
    <h1>System Settings</h1>
</div>

<div class="settings-container">
    <?php if (!empty($success)): ?>
        <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <form method="POST" action="">
        <div class="setting-group">
            <h3>Order Grace Period</h3>
            <label for="grace_period">Customer Cancellation Grace Period:</label>
            
            <div class="input-group">
                <input type="number" 
                       id="grace_period" 
                       name="grace_period" 
                       value="<?php echo $currentGracePeriod; ?>" 
                       min="1" 
                       max="60" 
                       required>
                <span class="input-suffix">minutes</span>
            </div>
            
            <div class="current-value">
                <strong>Current Setting:</strong> <?php echo $currentGracePeriod; ?> minutes
            </div>
            
            <div class="setting-description">
                This is the time period after an order is placed during which:
                <ul>
                    <li>Customers have priority to cancel their orders</li>
                    <li>Sellers cannot process orders (orders remain in "pending" status)</li>
                    <li>This protects customers from immediate processing and allows cancellation flexibility</li>
                </ul>
            </div>
            
            <div class="warning-box">
                <strong>Important:</strong> Changing this setting will affect all new orders going forward. 
                Existing orders will continue to use the grace period that was active when they were placed.
            </div>
        </div>
        
        <button type="submit" name="update_settings" class="btn">Update Settings</button>
    </form>
    
    <div class="setting-group" style="margin-top: 30px;">
        <h3>How Grace Period Works</h3>
        <div class="setting-description">
            <ol>
                <li><strong>Order Placed:</strong> Customer places an order (status: "pending")</li>
                <li><strong>Grace Period Active:</strong> For the set duration, sellers cannot process the order</li>
                <li><strong>Customer Priority:</strong> During this time, customers can cancel without seller intervention</li>
                <li><strong>Grace Period Ends:</strong> Sellers can now process the order (change to "processing")</li>
                <li><strong>Order Locked:</strong> Once processing starts, customer cancellation requires seller approval</li>
            </ol>
        </div>
    </div>
</div>
</main>

</body>
</html>
