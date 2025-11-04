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
    body {
        background: #f0f2f5 !important;
        color: #130325 !important;
        min-height: 100vh;
        margin: 0;
        font-family: 'Inter', 'Segoe UI', sans-serif;
    }

    .page-header {
        font-size: 18px !important;
        font-weight: 800 !important;
        color: #130325 !important;
        margin: 20px auto 20px auto !important;
        padding: 0 20px !important;
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
        flex-wrap: wrap !important;
        max-width: 1400px !important;
        text-shadow: none !important;
        position: relative !important;
        z-index: 1 !important;
    }

    .page-header h1,
    .page-heading-title {
        font-size: 20px !important;
        font-weight: 800 !important;
        color: #130325 !important;
        margin-top: 0 !important;
        margin-bottom: 0 !important;
        margin-left: 0 !important;
        margin-right: 0 !important;
        display: inline-flex !important;
        align-items: center !important;
        text-shadow: none !important;
    }

    .settings-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 24px 24px;
    }

    .settings-card {
        background: #ffffff;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }

    .settings-card h3 {
        font-size: 18px;
        font-weight: 600;
        color: #130325;
        margin: 0 0 20px 0;
        display: flex;
        align-items: center;
        gap: 8px;
        text-shadow: none !important;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        font-size: 13px;
        font-weight: 600;
        color: #130325;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .info-icon {
        color: #3b82f6;
        cursor: pointer;
        font-size: 16px;
        transition: all 0.2s ease;
    }

    .info-icon:hover {
        color: #2563eb;
        transform: scale(1.1);
    }

    .input-group {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .form-group input {
        padding: 10px 12px;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 8px;
        font-size: 14px;
        background: #ffffff;
        color: #130325;
        width: 150px;
        transition: border-color 0.2s;
    }

    .form-group input:focus {
        outline: none;
        border-color: #130325;
    }

    .input-suffix {
        color: #6b7280;
        font-weight: 600;
        font-size: 14px;
    }

    .current-value-badge {
        display: inline-block;
        background: rgba(255, 215, 54, 0.1);
        color: #130325;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        margin-top: 12px;
    }

    .btn-save {
        background: linear-gradient(135deg, #FFD736 0%, #FFC107 100%);
        color: #130325;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        font-weight: 700;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .btn-save:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(255, 215, 54, 0.3);
    }

    /* Toast Notification */
    .toast-notification {
        position: fixed;
        top: 80px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 10000;
        min-width: 300px;
        max-width: 500px;
        padding: 16px 20px;
        border-radius: 12px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        animation: toastSlideIn 0.3s ease-out;
        opacity: 0;
        pointer-events: none;
    }

    .toast-notification.show {
        opacity: 1;
        pointer-events: auto;
    }

    .toast-success {
        background: #10b981;
        color: #ffffff;
    }

    .toast-error {
        background: #ef4444;
        color: #ffffff;
    }

    @keyframes toastSlideIn {
        from {
            opacity: 0;
            transform: translateX(-50%) translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
    }

    /* Modal Styles */
    .info-modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 10000;
        align-items: center;
        justify-content: center;
    }

    .info-modal-overlay.show {
        display: flex;
    }

    .info-modal-dialog {
        background: #ffffff;
        padding: 32px;
        border-radius: 12px;
        max-width: 600px;
        width: 90%;
        max-height: 80vh;
        overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        position: relative;
        border: none;
    }

    .info-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 16px;
        border-bottom: 1px solid rgba(0,0,0,0.1);
    }

    .info-modal-title {
        font-size: 20px;
        font-weight: 700;
        color: #130325;
        margin: 0;
        text-shadow: none !important;
    }

    .info-modal-close {
        background: none;
        border: none;
        font-size: 28px;
        cursor: pointer;
        color: #130325;
        line-height: 1;
        padding: 0;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 6px;
        transition: all 0.2s ease;
    }

    .info-modal-close:hover {
        background: rgba(0,0,0,0.05);
        color: #6b7280;
    }

    .info-modal-body {
        color: #130325;
        line-height: 1.6;
    }

    .info-modal-body h4 {
        font-size: 16px;
        font-weight: 600;
        color: #130325;
        margin: 16px 0 8px 0;
        text-shadow: none !important;
    }

    .info-modal-body ul,
    .info-modal-body ol {
        margin: 12px 0;
        padding-left: 24px;
    }

    .info-modal-body li {
        margin-bottom: 8px;
        color: #374151;
    }

    .info-modal-body strong {
        color: #130325;
        font-weight: 600;
    }
</style>

<div class="page-header">
    <h1 class="page-heading-title">System Settings</h1>
</div>

<?php if (!empty($success)): ?>
    <div class="toast-notification toast-success" id="successToast">
        <i class="fas fa-check-circle"></i>
        <span><?php echo htmlspecialchars($success); ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="toast-notification toast-error" id="errorToast">
        <i class="fas fa-exclamation-circle"></i>
        <span><?php echo htmlspecialchars($error); ?></span>
    </div>
<?php endif; ?>

<div class="settings-container">
    <form method="POST" action="">
        <div class="settings-card">
            <h3>
                Order Grace Period
                <i class="fas fa-info-circle info-icon" onclick="openInfoModal('gracePeriod')" title="Click for instructions"></i>
            </h3>
            
            <div class="form-group">
                <label for="grace_period">
                    Customer Cancellation Grace Period
                    <i class="fas fa-info-circle info-icon" onclick="openInfoModal('gracePeriod')" title="Click for instructions"></i>
                </label>
                
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
                
                <div class="current-value-badge">
                    Current Setting: <?php echo $currentGracePeriod; ?> minutes
                </div>
            </div>
            
            <button type="submit" name="update_settings" class="btn-save">
                <i class="fas fa-save"></i> Save Settings
            </button>
        </div>
    </form>
</div>

<!-- Info Modal -->
<div id="infoModal" class="info-modal-overlay" onclick="closeInfoModalOnOverlay(event)">
    <div class="info-modal-dialog" onclick="event.stopPropagation()">
        <div class="info-modal-header">
            <h3 class="info-modal-title">Order Grace Period Instructions</h3>
            <button class="info-modal-close" onclick="closeInfoModal()" aria-label="Close">&times;</button>
        </div>
        <div class="info-modal-body" id="infoModalContent">
            <p><strong>What is the Grace Period?</strong></p>
            <p>The grace period is the time period after an order is placed during which customers have priority to cancel their orders without seller intervention.</p>
            
            <h4>How It Works:</h4>
            <ol>
                <li><strong>Order Placed:</strong> When a customer places an order, it starts with "pending" status.</li>
                <li><strong>Grace Period Active:</strong> For the set duration (in minutes), sellers cannot process the order. The order remains in "pending" status.</li>
                <li><strong>Customer Priority:</strong> During this time, customers can cancel their orders directly without requiring seller approval.</li>
                <li><strong>Grace Period Ends:</strong> Once the grace period expires, sellers can process the order (change status to "processing").</li>
                <li><strong>Order Locked:</strong> After processing starts, customer cancellation requires seller approval.</li>
            </ol>
            
            <h4>Important Notes:</h4>
            <ul>
                <li>Grace period must be between 1 and 60 minutes.</li>
                <li>Changing this setting only affects new orders going forward.</li>
                <li>Existing orders will continue to use the grace period that was active when they were placed.</li>
                <li>This protects customers from immediate processing and allows cancellation flexibility.</li>
            </ul>
            
            <h4>Example:</h4>
            <p>If you set the grace period to 15 minutes:</p>
            <ul>
                <li>Customer places order at 10:00 AM</li>
                <li>Grace period active until 10:15 AM</li>
                <li>Customer can cancel anytime before 10:15 AM</li>
                <li>Seller can process order starting at 10:15 AM</li>
            </ul>
        </div>
    </div>
</div>

<script>
function openInfoModal(type) {
    const modal = document.getElementById('infoModal');
    if (modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
}

function closeInfoModal() {
    const modal = document.getElementById('infoModal');
    if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
}

function closeInfoModalOnOverlay(event) {
    if (event.target.classList.contains('info-modal-overlay')) {
        closeInfoModal();
    }
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeInfoModal();
    }
});

// Auto-dismiss toast notifications
document.addEventListener('DOMContentLoaded', function() {
    const successToast = document.getElementById('successToast');
    const errorToast = document.getElementById('errorToast');
    
    function showAndDismissToast(toast) {
        if (toast) {
            setTimeout(() => {
                toast.classList.add('show');
            }, 10);
            
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => {
                    toast.remove();
                }, 300);
            }, 3000);
        }
    }
    
    showAndDismissToast(successToast);
    showAndDismissToast(errorToast);
});
</script>
