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
$currentSection = isset($_GET['section']) ? sanitizeInput($_GET['section']) : 'order';

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




// Handle maintenance mode toggle
if (isset($_POST['toggle_maintenance'])) {
    $maintenanceMode = isset($_POST['maintenance_mode']) ? '1' : '0';
    $maintenanceMessage = isset($_POST['maintenance_message']) ? sanitizeInput($_POST['maintenance_message']) : 'We are currently performing scheduled maintenance. Please check back soon!';
    // Format datetime inputs to match database format (Y-m-d H:i:s)
    $maintenanceStart = '';
    $maintenanceEnd = '';
    
    if (!empty($_POST['maintenance_start'])) {
        // Convert from datetime-local format (Y-m-d\TH:i) to database format (Y-m-d H:i:s)
        $maintenanceStart = date('Y-m-d H:i:s', strtotime($_POST['maintenance_start']));
    }
    
    if (!empty($_POST['maintenance_end'])) {
        $maintenanceEnd = date('Y-m-d H:i:s', strtotime($_POST['maintenance_end']));
    }
    $maintenanceAuto = isset($_POST['maintenance_auto_enable']) ? '1' : '0';
    
    try {
        // Ensure site_settings table exists (create without foreign key first to avoid constraint errors)
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT NOT NULL,
            updated_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        // Try to add foreign key constraint separately if it doesn't exist
        try {
            // Check if foreign key already exists
            $fkCheck = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE 
                                   WHERE TABLE_SCHEMA = DATABASE() 
                                   AND TABLE_NAME = 'site_settings' 
                                   AND COLUMN_NAME = 'updated_by' 
                                   AND REFERENCED_TABLE_NAME = 'users'");
            if ($fkCheck->rowCount() == 0) {
                // Check if users table exists and has id column
                $usersCheck = $pdo->query("SHOW TABLES LIKE 'users'");
                if ($usersCheck->rowCount() > 0) {
                    // Try to add foreign key constraint
                    $pdo->exec("ALTER TABLE site_settings 
                               ADD CONSTRAINT fk_site_settings_updated_by 
                               FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL");
                }
            }
        } catch (PDOException $fkError) {
            // Foreign key constraint failed - that's okay, table exists and will work without it
            // We'll just store the user_id without referential integrity enforcement
        }
        
        // Update all maintenance settings
        $settings = [
            'maintenance_mode' => $maintenanceMode,
            'maintenance_message' => $maintenanceMessage,
            'maintenance_start' => $maintenanceStart,
            'maintenance_end' => $maintenanceEnd,
            'maintenance_auto_enable' => $maintenanceAuto
        ];
        
        foreach ($settings as $key => $value) {
            $stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, updated_by) 
                                   VALUES (?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?");
            $stmt->execute([$key, $value, $_SESSION['user_id'], $value, $_SESSION['user_id']]);
        }
        
        // Determine success message based on maintenance mode status
        if ($maintenanceMode === '1') {
            $success = "Maintenance mode has been ENABLED! The site is now in maintenance mode.";
        } else {
            $success = "Maintenance mode has been DISABLED! The site is now accessible to all users.";
        }
    } catch (PDOException $e) {
        $error = "Error updating maintenance mode: " . $e->getMessage();
    }
}

// Get current maintenance settings
try {
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings 
                           WHERE setting_key IN ('maintenance_mode', 'maintenance_message', 'maintenance_start', 'maintenance_end', 'maintenance_auto_enable')");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $maintenanceMode = isset($settings['maintenance_mode']) ? $settings['maintenance_mode'] : '0';
    $maintenanceMessage = isset($settings['maintenance_message']) ? $settings['maintenance_message'] : 'We are currently performing scheduled maintenance. Please check back soon!';
    $maintenanceStart = isset($settings['maintenance_start']) ? $settings['maintenance_start'] : '';
    $maintenanceEnd = isset($settings['maintenance_end']) ? $settings['maintenance_end'] : '';
    $maintenanceAuto = isset($settings['maintenance_auto_enable']) ? $settings['maintenance_auto_enable'] : '0';
} catch (PDOException $e) {
    // Table might not exist yet
    $maintenanceMode = '0';
    $maintenanceMessage = 'We are currently performing scheduled maintenance. Please check back soon!';
    $maintenanceStart = '';
    $maintenanceEnd = '';
    $maintenanceAuto = '0';
}

// Handle discount code operations
if (isset($_POST['create_discount_code'])) {
    $code = strtoupper(trim(sanitizeInput($_POST['discount_code'])));
    $discountType = sanitizeInput($_POST['discount_type']); // 'percentage' or 'fixed'
    $discountValue = floatval($_POST['discount_value']);
    $minOrderAmount = floatval($_POST['min_order_amount']);
    $maxUses = intval($_POST['max_uses']);
    $startDate = !empty($_POST['start_date']) ? date('Y-m-d H:i:s', strtotime($_POST['start_date'])) : date('Y-m-d H:i:s');
    $endDate = !empty($_POST['end_date']) ? date('Y-m-d H:i:s', strtotime($_POST['end_date'])) : null;
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($code)) {
        $error = "Discount code cannot be empty.";
    } elseif ($discountValue <= 0) {
        $error = "Discount value must be greater than 0.";
    } elseif ($discountType === 'percentage' && $discountValue > 100) {
        $error = "Percentage discount cannot exceed 100%.";
    } else {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS discount_codes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(50) NOT NULL UNIQUE,
                discount_type ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage',
                discount_value DECIMAL(10,2) NOT NULL,
                min_order_amount DECIMAL(10,2) DEFAULT 0,
                max_uses INT DEFAULT NULL,
                used_count INT DEFAULT 0,
                start_date DATETIME NOT NULL,
                end_date DATETIME NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            
            $stmt = $pdo->prepare("INSERT INTO discount_codes (code, discount_type, discount_value, min_order_amount, max_uses, start_date, end_date, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$code, $discountType, $discountValue, $minOrderAmount, $maxUses > 0 ? $maxUses : null, $startDate, $endDate, $isActive, $_SESSION['user_id']]);
            $success = "Discount code '{$code}' created successfully!";
            // Redirect to maintain section parameter
            header("Location: admin-settings.php?section=order");
            exit();
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $error = "Discount code '{$code}' already exists.";
            } else {
                $error = "Error creating discount code: " . $e->getMessage();
            }
        }
    }
}

if (isset($_POST['update_discount_code'])) {
    $id = intval($_POST['discount_id']);
    $code = strtoupper(trim(sanitizeInput($_POST['discount_code'])));
    $discountType = sanitizeInput($_POST['discount_type']);
    $discountValue = floatval($_POST['discount_value']);
    $minOrderAmount = floatval($_POST['min_order_amount']);
    $maxUses = intval($_POST['max_uses']);
    $startDate = !empty($_POST['start_date']) ? date('Y-m-d H:i:s', strtotime($_POST['start_date'])) : date('Y-m-d H:i:s');
    $endDate = !empty($_POST['end_date']) ? date('Y-m-d H:i:s', strtotime($_POST['end_date'])) : null;
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    try {
        $stmt = $pdo->prepare("UPDATE discount_codes SET code = ?, discount_type = ?, discount_value = ?, min_order_amount = ?, max_uses = ?, start_date = ?, end_date = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$code, $discountType, $discountValue, $minOrderAmount, $maxUses > 0 ? $maxUses : null, $startDate, $endDate, $isActive, $id]);
        $success = "Discount code updated successfully!";
    } catch (PDOException $e) {
        $error = "Error updating discount code: " . $e->getMessage();
    }
}

if (isset($_GET['delete_discount']) && is_numeric($_GET['delete_discount'])) {
    $id = intval($_GET['delete_discount']);
    try {
        $stmt = $pdo->prepare("DELETE FROM discount_codes WHERE id = ?");
        $stmt->execute([$id]);
        $success = "Discount code deleted successfully!";
        // Redirect to maintain section parameter
        header("Location: admin-settings.php?section=order");
        exit();
    } catch (PDOException $e) {
        $error = "Error deleting discount code: " . $e->getMessage();
    }
}

// Get all discount codes
$discountCodes = [];
try {
    $stmt = $pdo->query("SELECT * FROM discount_codes ORDER BY created_at DESC");
    $discountCodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table might not exist yet
}

// Handle general site settings (with authentication)
if (isset($_POST['update_site_settings'])) {
    // Verify password/PIN before allowing changes
    $authPassword = isset($_POST['auth_password']) ? $_POST['auth_password'] : '';
    if (empty($authPassword)) {
        $error = "Authentication required. Please enter your password or PIN.";
    } else {
        // Verify password
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !password_verify($authPassword, $user['password'])) {
            // Check if it's a PIN (4-6 digits)
            if (preg_match('/^\d{4,6}$/', $authPassword)) {
                $stmt = $pdo->prepare("SELECT pin FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $userPin = $stmt->fetchColumn();
                if (!$userPin || !password_verify($authPassword, $userPin)) {
                    $error = "Invalid password or PIN. Site settings not updated.";
                }
            } else {
                $error = "Invalid password or PIN. Site settings not updated.";
            }
        }
        
        if (empty($error)) {
            $siteName = sanitizeInput($_POST['site_name']);
            $siteEmail = sanitizeInput($_POST['site_email']);
            $sitePhone = sanitizeInput($_POST['site_phone']);
            $siteAddress = sanitizeInput($_POST['site_address']);
            
            try {
                // First try to create table without foreign key
                $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    setting_key VARCHAR(100) NOT NULL UNIQUE,
                    setting_value TEXT NOT NULL,
                    updated_by INT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                
                // Try to add foreign key constraint if table was just created or doesn't have it
                try {
                    // Check if foreign key already exists
                    $fkCheck = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE 
                                           WHERE TABLE_SCHEMA = DATABASE() 
                                           AND TABLE_NAME = 'site_settings' 
                                           AND COLUMN_NAME = 'updated_by' 
                                           AND REFERENCED_TABLE_NAME = 'users'");
                    if ($fkCheck->rowCount() == 0) {
                        // Add foreign key constraint
                        $pdo->exec("ALTER TABLE site_settings 
                                   ADD CONSTRAINT fk_site_settings_updated_by 
                                   FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL");
                    }
                } catch (PDOException $fkError) {
                    // Foreign key constraint failed, but table exists - that's okay
                    // We'll just store the user_id without referential integrity
                }
                
                $settings = [
                    'site_name' => $siteName,
                    'site_email' => $siteEmail,
                    'site_phone' => $sitePhone,
                    'site_address' => $siteAddress
                ];
                
                foreach ($settings as $key => $value) {
                    $stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?");
                    $stmt->execute([$key, $value, $_SESSION['user_id'], $value, $_SESSION['user_id']]);
                }
                
                $success = "Site settings updated successfully!";
            } catch (PDOException $e) {
                $error = "Error updating site settings: " . $e->getMessage();
            }
        }
    }
}

// Handle password/PIN change
if (isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $changeType = $_POST['change_type'] ?? 'password'; // 'password' or 'pin'
    
    if (empty($currentPassword) || empty($newPassword)) {
        $error = "All fields are required.";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "New password and confirmation do not match.";
    } elseif ($changeType === 'pin' && (!preg_match('/^\d{4,6}$/', $newPassword) || strlen($newPassword) < 4 || strlen($newPassword) > 6)) {
        $error = "PIN must be 4-6 digits.";
    } elseif ($changeType === 'password' && strlen($newPassword) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        // Verify current password
        $stmt = $pdo->prepare("SELECT password, pin FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $isValid = false;
        if ($changeType === 'password') {
            $isValid = password_verify($currentPassword, $user['password']);
        } else {
            if (preg_match('/^\d{4,6}$/', $currentPassword) && $user['pin']) {
                $isValid = password_verify($currentPassword, $user['pin']);
            }
        }
        
        if (!$isValid) {
            $error = "Current " . ($changeType === 'pin' ? 'PIN' : 'password') . " is incorrect.";
        } else {
            try {
                if ($changeType === 'password') {
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([$hashedPassword, $_SESSION['user_id']]);
                } else {
                    $hashedPin = password_hash($newPassword, PASSWORD_DEFAULT);
                    // Check if PIN column exists, if not add it
                    try {
                        $stmt = $pdo->prepare("UPDATE users SET pin = ? WHERE id = ?");
                        $stmt->execute([$hashedPin, $_SESSION['user_id']]);
                    } catch (PDOException $e) {
                        // PIN column might not exist, add it
                        $pdo->exec("ALTER TABLE users ADD COLUMN pin VARCHAR(255) NULL");
                        $stmt = $pdo->prepare("UPDATE users SET pin = ? WHERE id = ?");
                        $stmt->execute([$hashedPin, $_SESSION['user_id']]);
                    }
                }
                $success = ucfirst($changeType) . " changed successfully!";
            } catch (PDOException $e) {
                $error = "Error updating " . $changeType . ": " . $e->getMessage();
            }
        }
    }
}

// Handle profile update
if (isset($_POST['update_profile'])) {
    $firstName = sanitizeInput($_POST['first_name'] ?? '');
    $lastName = sanitizeInput($_POST['last_name'] ?? '');
    $displayName = sanitizeInput($_POST['display_name'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $phone = sanitizeInput($_POST['phone'] ?? '');
    
    try {
        $stmt = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, display_name = ?, email = ?, phone = ? WHERE id = ?");
        $stmt->execute([$firstName, $lastName, $displayName, $email, $phone, $_SESSION['user_id']]);
        $success = "Profile updated successfully!";
        // Update session
        $_SESSION['username'] = $displayName ?: $firstName . ' ' . $lastName;
    } catch (PDOException $e) {
        $error = "Error updating profile: " . $e->getMessage();
    }
}

// Get current site settings
$siteSettings = [];
try {
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('site_name', 'site_email', 'site_phone', 'site_address')");
    $stmt->execute();
    $siteSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    // Table might not exist yet
}

$currentSiteName = $siteSettings['site_name'] ?? 'PEST-CTRL';
$currentSiteEmail = $siteSettings['site_email'] ?? '';
$currentSitePhone = $siteSettings['site_phone'] ?? '';
$currentSiteAddress = $siteSettings['site_address'] ?? '';

// Get current user profile
$userProfile = null;
try {
    $stmt = $pdo->prepare("SELECT first_name, last_name, display_name, email, phone, username FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $userProfile = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $userProfile = null;
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



    /* Maintenance Mode Styles */
    .settings-card h3 {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
    }

    .status-badge {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.5px;
    }

    .status-badge i {
        font-size: 8px;
    }

    .status-active {
        background: #fee2e2;
        color: #dc2626;
    }

    .status-active i {
        color: #dc2626;
        animation: pulse 2s infinite;
    }

    .status-inactive {
        background: #d1fae5;
        color: #059669;
    }

    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }

    .toggle-container {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 16px;
        background: #f9fafb;
        border-radius: 8px;
        border: 1px solid rgba(0,0,0,0.05);
    }

    .toggle-label {
        position: relative;
        display: inline-block;
        width: 56px;
        height: 28px;
        cursor: pointer;
        margin: 0;
        flex-shrink: 0;
    }

    .toggle-label input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .toggle-slider {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #cbd5e1;
        transition: 0.3s;
        border-radius: 28px;
    }

    .toggle-slider:before {
        position: absolute;
        content: "";
        height: 20px;
        width: 20px;
        left: 4px;
        bottom: 4px;
        background-color: white;
        transition: 0.3s;
        border-radius: 50%;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }

    input:checked + .toggle-slider {
        background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    }

    input:checked + .toggle-slider:before {
        transform: translateX(28px);
    }

    .toggle-slider-schedule {
        background-color: #cbd5e1 !important;
    }

    input:checked + .toggle-slider-schedule {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%) !important;
    }

    .toggle-info {
        flex: 1;
    }

    .toggle-info strong {
        display: block;
        font-size: 15px;
        color: #130325;
        margin-bottom: 4px;
    }

    .toggle-info p {
        font-size: 13px;
        color: #6b7280;
        margin: 0;
    }

    .form-group textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 8px;
        font-size: 14px;
        background: #ffffff;
        color: #130325;
        resize: vertical;
        font-family: inherit;
    }

    .form-group textarea:focus {
        outline: none;
        border-color: #130325;
    }

    .form-help-text {
        display: block;
        font-size: 12px;
        color: #6b7280;
        margin-top: 6px;
    }

    .schedule-header {
        margin-bottom: 20px;
    }

    .schedule-header h4 {
        font-size: 16px;
        font-weight: 600;
        color: #130325;
        margin: 0 0 8px 0;
        display: flex;
        align-items: center;
        gap: 8px;
        text-shadow: none !important;
    }

    .schedule-header p {
        font-size: 13px;
        color: #6b7280;
        margin: 0;
    }

    .schedule-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 20px;
    }

    .form-group input[type="datetime-local"] {
        width: 100%;
    }

    /* Discount Codes Styles */
    .discount-codes-list {
        margin-bottom: 24px;
    }

    .discount-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
        background: #ffffff;
        border-radius: 8px;
        overflow: hidden;
    }

    .discount-table thead {
        background: #f9fafb;
    }

    .discount-table th {
        padding: 12px 16px;
        text-align: left;
        font-weight: 600;
        font-size: 13px;
        color: #130325;
        border-bottom: 2px solid #e5e7eb;
    }

    .discount-table td {
        padding: 12px 16px;
        font-size: 14px;
        color: #374151;
        border-bottom: 1px solid #e5e7eb;
    }

    .discount-table tbody tr:hover {
        background: #f9fafb;
    }

    .btn-add-discount {
        background: linear-gradient(135deg, #FFD736 0%, #FFC107 100%);
        color: #130325;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        font-weight: 700;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .btn-add-discount:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(255, 215, 54, 0.3);
    }

    .discount-form {
        margin-top: 24px;
        padding: 24px;
        background: #f9fafb;
        border-radius: 8px;
        border: 1px solid #e5e7eb;
    }

    .form-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .form-group select {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 8px;
        font-size: 14px;
        background: #ffffff;
        color: #130325;
    }

    .form-group select:focus {
        outline: none;
        border-color: #130325;
    }

    .form-actions {
        display: flex;
        gap: 12px;
        margin-top: 24px;
    }

    .btn-cancel {
        background: #ffffff;
        color: #130325;
        border: 1px solid rgba(0,0,0,0.1);
        padding: 12px 24px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .btn-cancel:hover {
        background: #f9fafb;
        border-color: #130325;
    }

    .btn-edit-small, .btn-delete-small {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 6px;
        border: none;
        cursor: pointer;
        transition: all 0.2s ease;
        text-decoration: none;
        margin: 0 4px;
    }

    .btn-edit-small {
        background: #3b82f6;
        color: #ffffff;
    }

    .btn-edit-small:hover {
        background: #2563eb;
        transform: translateY(-1px);
    }

    .btn-delete-small {
        background: #ef4444;
        color: #ffffff;
    }

    .btn-delete-small:hover {
        background: #dc2626;
        transform: translateY(-1px);
    }

    .info-box-maintenance {
        display: flex;
        gap: 12px;
        padding: 16px;
        background: #e7f3ff;
        border: 1px solid #bfdbfe;
        border-radius: 8px;
        margin-bottom: 20px;
        color: #1e40af;
    }

    .info-box-maintenance i {
        font-size: 20px;
        color: #3b82f6;
        flex-shrink: 0;
    }

    .info-box-maintenance strong {
        display: block;
        margin-bottom: 8px;
        color: #1e40af;
    }

    .info-box-maintenance ul {
        margin: 8px 0 0 0;
        padding-left: 20px;
        list-style: disc;
    }

    .info-box-maintenance li {
        margin: 4px 0;
        font-size: 13px;
        color: #1e40af;
    }

    .maintenance-warning {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px;
        background: #fee2e2;
        border: 1px solid #fecaca;
        border-radius: 8px;
        margin-top: 20px;
        color: #991b1b;
    }

    .maintenance-warning i {
        font-size: 20px;
        color: #dc2626;
        flex-shrink: 0;
    }

    .maintenance-warning strong {
        color: #991b1b;
    }

    .schedule-info-box {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px;
        background: #dbeafe;
        border: 1px solid #93c5fd;
        border-radius: 8px;
        margin-top: 20px;
        color: #1e3a8a;
    }

    .schedule-info-box i {
        font-size: 20px;
        color: #3b82f6;
        flex-shrink: 0;
    }

    .schedule-info-box strong {
        display: block;
        margin-bottom: 4px;
        color: #1e3a8a;
    }

    .schedule-info-box p {
        margin: 2px 0;
        font-size: 13px;
        color: #1e3a8a;
    }

/* ===== RESPONSIVE STYLES ===== */

    /* Tablet Devices (Portrait and smaller desktops) */
    @media (max-width: 1024px) {
        .settings-container {
            padding: 0 20px 20px;
        }

        .page-header {
            padding: 0 16px !important;
        }
    }

    /* Tablet Devices (Portrait) */
    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            align-items: flex-start !important;
            gap: 8px !important;
            padding: 0 12px !important;
            margin: 16px auto 16px auto !important;
        }

        .page-header h1,
        .page-heading-title {
            font-size: 18px !important;
        }

        .settings-container {
            padding: 0 16px 16px;
        }

        .settings-card {
            padding: 20px;
            margin-bottom: 20px;
        }

        .settings-card h3 {
            font-size: 16px;
            flex-direction: column;
            align-items: flex-start !important;
        }

        .status-badge {
            align-self: flex-start;
        }

        .schedule-grid {
            grid-template-columns: 1fr;
            gap: 16px;
        }

        .toggle-container {
            flex-direction: column;
            align-items: flex-start;
            gap: 12px;
        }

        .input-group {
            flex-wrap: wrap;
        }

        .form-group input {
            width: 100%;
            max-width: 200px;
        }

        .form-group input[type="datetime-local"] {
            max-width: 100%;
        }

        .btn-save {
            width: 100%;
            justify-content: center;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-modal-dialog {
            padding: 24px;
            width: 95%;
        }

        .info-modal-title {
            font-size: 18px;
        }

        .toast-notification {
            min-width: 280px;
            max-width: calc(100vw - 40px);
            top: 70px;
        }
    }

    /* Mobile Devices (Large) */
    @media (max-width: 576px) {
        .page-header {
            margin: 12px auto 12px auto !important;
        }

        .page-header h1,
        .page-heading-title {
            font-size: 16px !important;
        }

        .settings-container {
            padding: 0 12px 12px;
        }

        .settings-card {
            padding: 16px;
            margin-bottom: 16px;
            border-radius: 10px;
        }

        .settings-card h3 {
            font-size: 15px;
            margin-bottom: 16px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            font-size: 12px;
            flex-wrap: wrap;
        }

        .info-icon {
            font-size: 14px;
        }

        .form-group input {
            padding: 8px 10px;
            font-size: 13px;
        }

        .form-group textarea {
            padding: 10px;
            font-size: 13px;
        }

        .input-suffix {
            font-size: 13px;
        }

        .current-value-badge {
            font-size: 12px;
            padding: 5px 10px;
        }

        .btn-save {
            padding: 10px 20px;
            font-size: 13px;
        }

        .toggle-label {
            width: 50px;
            height: 26px;
        }

        .toggle-slider:before {
            height: 18px;
            width: 18px;
        }

        input:checked + .toggle-slider:before {
            transform: translateX(24px);
        }

        .toggle-info strong {
            font-size: 14px;
        }

        .toggle-info p {
            font-size: 12px;
        }

        .schedule-header h4 {
            font-size: 15px;
        }

        .schedule-header p {
            font-size: 12px;
        }

        .form-help-text {
            font-size: 11px;
        }

        .info-box-maintenance,
        .maintenance-warning,
        .schedule-info-box {
            padding: 12px;
            flex-direction: column;
            align-items: flex-start;
        }

        .info-box-maintenance i,
        .maintenance-warning i,
        .schedule-info-box i {
            font-size: 18px;
        }

        .info-box-maintenance li {
            font-size: 12px;
        }

        .schedule-info-box p {
            font-size: 12px;
        }

        .status-badge {
            font-size: 10px;
            padding: 5px 12px;
        }

        .info-modal-dialog {
            padding: 20px;
            max-height: 85vh;
        }

        .info-modal-title {
            font-size: 16px;
        }

        .info-modal-body {
            font-size: 14px;
        }

        .info-modal-body h4 {
            font-size: 15px;
        }

        .info-modal-body li {
            font-size: 13px;
        }

        .toast-notification {
            min-width: 260px;
            padding: 14px 16px;
            font-size: 14px;
            top: 60px;
        }
    }

    /* Mobile Devices (Small) */
    @media (max-width: 400px) {
        .settings-card {
            padding: 14px;
        }

        .settings-card h3 {
            font-size: 14px;
        }

        .form-group label {
            font-size: 11px;
        }

        .form-group input,
        .form-group textarea {
            font-size: 12px;
        }

        .btn-save {
            padding: 9px 18px;
            font-size: 12px;
        }

        .toggle-label {
            width: 46px;
            height: 24px;
        }

        .toggle-slider:before {
            height: 16px;
            width: 16px;
        }

        input:checked + .toggle-slider:before {
            transform: translateX(22px);
        }

        .toggle-info strong {
            font-size: 13px;
        }

        .toggle-info p {
            font-size: 11px;
        }

        .current-value-badge {
            font-size: 11px;
            padding: 4px 8px;
        }

        .info-box-maintenance,
        .maintenance-warning,
        .schedule-info-box {
            padding: 10px;
        }

        .info-box-maintenance i,
        .maintenance-warning i,
        .schedule-info-box i {
            font-size: 16px;
        }

        .info-modal-dialog {
            padding: 16px;
        }

        .info-modal-close {
            width: 28px;
            height: 28px;
            font-size: 24px;
        }

        .toast-notification {
            min-width: 240px;
            padding: 12px 14px;
            font-size: 13px;
        }
    }

    /* Landscape Mode for Mobile */
    @media (max-height: 600px) and (orientation: landscape) {
        .settings-card {
            padding: 16px;
        }

        .form-group {
            margin-bottom: 12px;
        }

        .settings-card h3 {
            margin-bottom: 12px;
        }

        .toggle-container {
            padding: 12px;
        }

        .info-box-maintenance,
        .maintenance-warning,
        .schedule-info-box {
            padding: 10px;
        }

        .info-modal-dialog {
            max-height: 90vh;
            padding: 20px;
        }

        .toast-notification {
            top: 50px;
        }
    }

    /* Very small devices */
    @media (max-width: 320px) {
        .page-header h1,
        .page-heading-title {
            font-size: 15px !important;
        }

        .settings-card {
            padding: 12px;
        }

        .settings-card h3 {
            font-size: 13px;
        }

        .btn-save {
            padding: 8px 16px;
            font-size: 11px;
        }

        .form-group input,
        .form-group textarea {
            font-size: 11px;
            padding: 7px 9px;
        }

        .info-modal-dialog {
            padding: 14px;
        }
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
    <?php if ($currentSection === 'order'): ?>
    <form method="POST" action="">
        <div class="settings-card">
            <h3>
                Order Settings
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

    <!-- Discount/Promo Codes Section -->
    <div class="settings-card">
        <h3>
            <i class="fas fa-tag"></i> Discount & Promo Codes
        </h3>
        
        <div class="discount-codes-list">
            <table class="discount-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Type</th>
                        <th>Value</th>
                        <th>Min Order</th>
                        <th>Uses</th>
                        <th>Valid Period</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($discountCodes)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 20px; color: #6b7280;">No discount codes created yet.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($discountCodes as $dc): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($dc['code']); ?></strong></td>
                        <td><?php echo ucfirst($dc['discount_type']); ?></td>
                        <td>
                            <?php 
                            if ($dc['discount_type'] === 'percentage') {
                                echo number_format($dc['discount_value'], 0) . '%';
                            } else {
                                echo '₱' . number_format($dc['discount_value'], 2);
                            }
                            ?>
                        </td>
                        <td>₱<?php echo number_format($dc['min_order_amount'], 2); ?></td>
                        <td><?php echo $dc['used_count']; ?>/<?php echo $dc['max_uses'] ? $dc['max_uses'] : '∞'; ?></td>
                        <td>
                            <?php 
                            echo date('M d, Y', strtotime($dc['start_date']));
                            if ($dc['end_date']) {
                                echo ' - ' . date('M d, Y', strtotime($dc['end_date']));
                            } else {
                                echo ' (No expiry)';
                            }
                            ?>
                        </td>
                        <td>
                            <span class="status-badge <?php echo $dc['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                <?php echo $dc['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </td>
                        <td>
                            <a href="?section=order&edit_discount=<?php echo $dc['id']; ?>" class="btn-edit-small" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <a href="?section=order&delete_discount=<?php echo $dc['id']; ?>" class="btn-delete-small" title="Delete" onclick="return confirm('Are you sure you want to delete this discount code?');">
                                <i class="fas fa-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <button type="button" class="btn-add-discount" onclick="toggleDiscountForm()">
            <i class="fas fa-plus"></i> Create New Discount Code
        </button>
        
        <div id="discountForm" class="discount-form" style="display: none;">
            <form method="POST" action="?section=order">
                <div class="form-row">
                    <div class="form-group">
                        <label for="discount_code">Discount Code *</label>
                        <input type="text" id="discount_code" name="discount_code" required placeholder="e.g., SUMMER2024" maxlength="50">
                    </div>
                    
                    <div class="form-group">
                        <label for="discount_type">Discount Type *</label>
                        <select id="discount_type" name="discount_type" required onchange="updateDiscountType()">
                            <option value="percentage">Percentage (%)</option>
                            <option value="fixed">Fixed Amount (₱)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="discount_value">Discount Value *</label>
                        <input type="number" id="discount_value" name="discount_value" step="0.01" min="0.01" required placeholder="10">
                        <small id="discount_value_hint">Enter percentage (0-100)</small>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="min_order_amount">Minimum Order Amount (₱)</label>
                        <input type="number" id="min_order_amount" name="min_order_amount" step="0.01" min="0" value="0" placeholder="0.00">
                    </div>
                    
                    <div class="form-group">
                        <label for="max_uses">Maximum Uses (0 = unlimited)</label>
                        <input type="number" id="max_uses" name="max_uses" min="0" value="0" placeholder="0">
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="is_active" checked> Active
                        </label>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="start_date">Start Date</label>
                        <input type="datetime-local" id="start_date" name="start_date" value="<?php echo date('Y-m-d\TH:i'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="end_date">End Date (Optional)</label>
                        <input type="datetime-local" id="end_date" name="end_date">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="create_discount_code" class="btn-save">
                        <i class="fas fa-save"></i> Create Discount Code
                    </button>
                    <button type="button" class="btn-cancel" onclick="toggleDiscountForm()">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php elseif ($currentSection === 'maintenance'): ?>
    <!-- Maintenance Mode Settings Card -->
    <form method="POST" action="" id="maintenanceForm">
        <div class="settings-card">
            <h3>
                <i class="fas fa-tools"></i> Maintenance Mode Control
                <div class="status-badge <?php echo $maintenanceMode === '1' ? 'status-active' : 'status-inactive'; ?>">
                    <i class="fas fa-circle"></i>
                    <?php echo $maintenanceMode === '1' ? 'ACTIVE' : 'INACTIVE'; ?>
                </div>
            </h3>
            
            <!-- Manual Maintenance Mode -->
            <div class="form-group">
                <div class="toggle-container">
                    <label class="toggle-label">
                        <input type="checkbox" name="maintenance_mode" id="maintenance_mode" 
                               <?php echo $maintenanceMode === '1' ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <div class="toggle-info">
                        <strong>Enable Maintenance Mode (Manual Override)</strong>
                        <p>When enabled, visitors will see a maintenance page immediately. Admins can still access the site.</p>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="maintenance_message">Maintenance Message</label>
                <textarea name="maintenance_message" id="maintenance_message" rows="4" 
                          placeholder="Enter the message to display to visitors during maintenance..."><?php echo htmlspecialchars($maintenanceMessage); ?></textarea>
                <small class="form-help-text">This message will be displayed to visitors when maintenance mode is active.</small>
            </div>

            <!-- Scheduled Maintenance -->
            <hr style="margin: 30px 0; border: none; border-top: 2px solid rgba(0,0,0,0.05);">
            
            <div class="schedule-header">
                <h4><i class="fas fa-calendar-alt"></i> Scheduled Maintenance</h4>
                <p>Automatically enable maintenance mode during a specific time window</p>
            </div>

            <div class="form-group">
                <div class="toggle-container">
                    <label class="toggle-label">
                        <input type="checkbox" name="maintenance_auto_enable" id="maintenance_auto_enable"
                               <?php echo $maintenanceAuto === '1' ? 'checked' : ''; ?>>
                        <span class="toggle-slider toggle-slider-schedule"></span>
                    </label>
                    <div class="toggle-info">
                        <strong>Enable Automatic Scheduled Maintenance</strong>
                        <p>Site will automatically enter maintenance mode during the scheduled window below.</p>
                    </div>
                </div>
            </div>

            <div class="schedule-grid">
                <div class="form-group">
                    <label for="maintenance_start"><i class="fas fa-play-circle"></i> Start Date & Time</label>
                    <input type="datetime-local" id="maintenance_start" name="maintenance_start" 
                    value="<?php echo !empty($maintenanceStart) ? date('Y-m-d\TH:i', strtotime($maintenanceStart)) : ''; ?>">
                    <small class="form-help-text">When maintenance mode should automatically start</small>
                </div>
                
                <div class="form-group">
                    <label for="maintenance_end"><i class="fas fa-stop-circle"></i> End Date & Time</label>
                    <input type="datetime-local" id="maintenance_end" name="maintenance_end" 
                    value="<?php echo !empty($maintenanceEnd) ? date('Y-m-d\TH:i', strtotime($maintenanceEnd)) : ''; ?>">
                    <small class="form-help-text">When maintenance mode should automatically end</small>
                </div>
            </div>

            <div class="info-box-maintenance">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>How it works:</strong>
                    <ul>
                        <li><strong>Manual Mode:</strong> Toggle "Enable Maintenance Mode" to activate immediately</li>
                        <li><strong>Scheduled Mode:</strong> Set start/end times and enable "Automatic Scheduled Maintenance"</li>
                        <li>Site will automatically enter maintenance during the scheduled window</li>
                        <li>Admins can always access the site regardless of maintenance mode</li>
                    </ul>
                </div>
            </div>
            
            <button type="button" onclick="console.log('DEBUG: Save button clicked!'); confirmMaintenanceSave(event);" class="btn-save">
                <i class="fas fa-save"></i> Save
            </button>
            
            <?php if ($maintenanceMode === '1'): ?>
            <div class="maintenance-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Warning:</strong> Your website is currently in maintenance mode. Regular users cannot access the site.
                </div>
            </div>
            <?php endif; ?>

            <?php if ($maintenanceAuto === '1' && !empty($maintenanceStart) && !empty($maintenanceEnd)): ?>
            <div class="schedule-info-box">
                <i class="fas fa-clock"></i>
                <div>
                    <strong>Scheduled Maintenance Active</strong>
                    <p>Start: <?php echo date('F j, Y g:i A', strtotime($maintenanceStart)); ?></p>
                    <p>End: <?php echo date('F j, Y g:i A', strtotime($maintenanceEnd)); ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </form>
    <?php elseif ($currentSection === 'site'): ?>
    <!-- Site Settings Section -->
    <form method="POST" action="" id="siteSettingsForm">
        <div class="settings-card">
            <h3>
                <i class="fas fa-cog"></i> Site Settings
                <span style="font-size: 12px; color: #ef4444; font-weight: 600;">
                    <i class="fas fa-lock"></i> Authentication Required
                </span>
            </h3>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="site_name">Site Name</label>
                    <input type="text" id="site_name" name="site_name" value="<?php echo htmlspecialchars($currentSiteName); ?>" placeholder="PEST-CTRL">
                </div>
                
                <div class="form-group">
                    <label for="site_email">Contact Email</label>
                    <input type="email" id="site_email" name="site_email" value="<?php echo htmlspecialchars($currentSiteEmail); ?>" placeholder="contact@pestctrl.com">
                </div>
                
                <div class="form-group">
                    <label for="site_phone">Contact Phone</label>
                    <input type="text" id="site_phone" name="site_phone" value="<?php echo htmlspecialchars($currentSitePhone); ?>" placeholder="+63 XXX XXX XXXX">
                </div>
            </div>
            
            <div class="form-group">
                <label for="site_address">Business Address</label>
                <textarea id="site_address" name="site_address" rows="3" placeholder="Enter business address..."><?php echo htmlspecialchars($currentSiteAddress); ?></textarea>
            </div>
            
            <button type="button" class="btn-save" onclick="openAuthModal('site')">
                <i class="fas fa-save"></i> Save Site Settings
            </button>
        </div>
    </form>
    
    <!-- Password/PIN Change Section -->
    <form method="POST" action="">
        <div class="settings-card">
            <h3>
                <i class="fas fa-key"></i> Change Password / PIN
            </h3>
            
            <div class="form-group">
                <label for="change_type">Change Type</label>
                <select id="change_type" name="change_type" onchange="updateChangeType()">
                    <option value="password">Password</option>
                    <option value="pin">PIN (4-6 digits)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="current_password">Current Password/PIN</label>
                <input type="password" id="current_password" name="current_password" required>
            </div>
            
            <div class="form-group">
                <label for="new_password">New Password/PIN</label>
                <input type="password" id="new_password" name="new_password" required>
                <small id="password_hint" class="form-help-text">Password must be at least 6 characters</small>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm New Password/PIN</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            
            <button type="submit" name="change_password" class="btn-save">
                <i class="fas fa-save"></i> Change Password/PIN
            </button>
        </div>
    </form>
    
    <?php elseif ($currentSection === 'profile'): ?>
    <!-- Edit Profile Section -->
    <form method="POST" action="">
        <div class="settings-card">
            <h3>
                <i class="fas fa-user-edit"></i> Edit Profile
            </h3>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($userProfile['first_name'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($userProfile['last_name'] ?? ''); ?>" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="display_name">Display Name</label>
                    <input type="text" id="display_name" name="display_name" value="<?php echo htmlspecialchars($userProfile['display_name'] ?? ''); ?>" placeholder="Optional">
                </div>
                
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" value="<?php echo htmlspecialchars($userProfile['username'] ?? ''); ?>" disabled style="background: #f3f4f6; cursor: not-allowed;">
                    <small class="form-help-text">Username cannot be changed</small>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($userProfile['email'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="phone">Phone</label>
                    <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($userProfile['phone'] ?? ''); ?>" placeholder="+63 XXX XXX XXXX">
                </div>
            </div>
            
            <button type="submit" name="update_profile" class="btn-save">
                <i class="fas fa-save"></i> Update Profile
            </button>
        </div>
    </form>
    <?php endif; ?>
</div>

<!-- Authentication Modal for Site Settings -->
<div id="authModal" class="info-modal-overlay" style="display: none;">
    <div class="info-modal-dialog" onclick="event.stopPropagation()">
        <div class="info-modal-header">
            <h3 class="info-modal-title">Authentication Required</h3>
            <button class="info-modal-close" onclick="closeAuthModal()" aria-label="Close">&times;</button>
        </div>
        <div class="info-modal-body">
            <p style="margin-bottom: 20px; color: #6b7280;">Please enter your password or PIN to save site settings.</p>
            <form id="authForm" method="POST" action="">
                <input type="hidden" name="update_site_settings" value="1">
                <input type="hidden" id="auth_site_name" name="site_name">
                <input type="hidden" id="auth_site_email" name="site_email">
                <input type="hidden" id="auth_site_phone" name="site_phone">
                <input type="hidden" id="auth_site_address" name="site_address">
                
                <div class="form-group">
                    <label for="auth_password">Password or PIN</label>
                    <input type="password" id="auth_password" name="auth_password" required autofocus>
                    <small class="form-help-text">Enter your account password or PIN (4-6 digits)</small>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn-save">
                        <i class="fas fa-check"></i> Authenticate & Save
                    </button>
                    <button type="button" class="btn-cancel" onclick="closeAuthModal()">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

<!-- Maintenance Save Confirmation Modal -->
<div id="maintenanceConfirmModal" class="info-modal-overlay" onclick="if(event.target === this) closeMaintenanceModal();" style="display: none !important;">
    <div class="info-modal-dialog" onclick="event.stopPropagation()">
        <div class="info-modal-header">
            <h3 class="info-modal-title">
                <i class="fas fa-exclamation-triangle" style="color: #FFD736; margin-right: 8px;"></i>
                Confirm Save Maintenance Settings
            </h3>
            <button class="info-modal-close" onclick="closeMaintenanceModal()" aria-label="Close">&times;</button>
        </div>
        <div class="info-modal-body">
            <p style="margin: 0; color: #130325; line-height: 1.6;">
                Are you sure you want to save these maintenance mode settings? 
            </p>
            <p style="margin: 12px 0 0 0; color: #6b7280; font-size: 13px;">
                <strong style="color: #dc2626;">Changes will take effect immediately and may affect site accessibility for regular users.</strong>
            </p>
        </div>
        <div class="info-modal-footer" style="padding: 16px 24px; border-top: 1px solid #e5e7eb; display: flex; gap: 10px; justify-content: flex-end;">
            <button type="button" class="btn-cancel" onclick="closeMaintenanceModal()" style="padding: 8px 20px; background: #f3f4f6; color: #130325; border: 1px solid #e5e7eb; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.2s ease;">
                Cancel
            </button>
            <button type="button" class="btn-save" onclick="confirmMaintenanceSubmit();" style="padding: 8px 20px; background: #130325; color: #ffffff; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.2s ease;">
                <i class="fas fa-save"></i> Save Settings
            </button>
        </div>
    </div>
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
        
        // Also close maintenance modal if open
        const maintenanceModal = document.getElementById('maintenanceConfirmModal');
        if (maintenanceModal && maintenanceModal.style.display === 'flex') {
            closeMaintenanceModal();
        }
    }
});

// FIXED: Maintenance Save Confirmation Modal
function confirmMaintenanceSave(e) {
    console.log('=== DEBUG: confirmMaintenanceSave called ===');
    console.log('Event:', e);
    
    if (e) {
        e.preventDefault();
        e.stopPropagation();
        console.log('DEBUG: Prevented default and stopped propagation');
    }
    
    const modal = document.getElementById('maintenanceConfirmModal');
    console.log('DEBUG: Modal element:', modal);
    
    if (!modal) {
        console.error('DEBUG: Modal not found!');
        alert('Error: Confirmation modal not found. Please refresh the page.');
        return false;
    }
    
    // CRITICAL FIX: Clear any existing overflow styles first
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('overflow-x');
    document.body.style.removeProperty('overflow-y');
    console.log('DEBUG: Cleared overflow styles');
    
    // Show modal immediately - ensure all styles are set with !important
    modal.setAttribute('style', 'display: flex !important; position: fixed !important; top: 0 !important; left: 0 !important; width: 100% !important; height: 100% !important; background: rgba(0, 0, 0, 0.5) !important; z-index: 99999 !important; align-items: center !important; justify-content: center !important; visibility: visible !important; opacity: 1 !important;');
    
    // Also set via style object as backup
    modal.style.display = 'flex';
    modal.style.position = 'fixed';
    modal.style.top = '0';
    modal.style.left = '0';
    modal.style.width = '100%';
    modal.style.height = '100%';
    modal.style.background = 'rgba(0, 0, 0, 0.5)';
    modal.style.zIndex = '99999';
    modal.style.alignItems = 'center';
    modal.style.justifyContent = 'center';
    modal.style.visibility = 'visible';
    modal.style.opacity = '1';
    
    console.log('DEBUG: Modal display set to flex, z-index:', modal.style.zIndex);
    console.log('DEBUG: Modal computed style:', window.getComputedStyle(modal).display);
    console.log('DEBUG: Modal position:', window.getComputedStyle(modal).position);
    console.log('DEBUG: Modal visibility:', window.getComputedStyle(modal).visibility);
    console.log('DEBUG: Modal opacity:', window.getComputedStyle(modal).opacity);
    console.log('DEBUG: Modal z-index:', window.getComputedStyle(modal).zIndex);
    console.log('DEBUG: Modal offsetTop:', modal.offsetTop);
    console.log('DEBUG: Modal offsetLeft:', modal.offsetLeft);
    console.log('DEBUG: Modal offsetWidth:', modal.offsetWidth);
    console.log('DEBUG: Modal offsetHeight:', modal.offsetHeight);
    
    // Force a reflow to ensure styles are applied
    void modal.offsetHeight;
    
    // Don't set body overflow to hidden - this causes the freeze
    
    return false;
}

function closeMaintenanceModal() {
    const modal = document.getElementById('maintenanceConfirmModal');
    if (modal) {
        modal.style.display = 'none';
    }
    // Ensure body scrolling is enabled
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('overflow-x');
    document.body.style.removeProperty('overflow-y');
}

function confirmMaintenanceSubmit() {
    console.log('=== DEBUG: confirmMaintenanceSubmit called ===');
    
    const form = document.getElementById('maintenanceForm');
    console.log('DEBUG: Form element:', form);
    
    if (!form) {
        console.error('DEBUG: Form not found!');
        alert('Error: Maintenance form not found!');
        return false;
    }
    
    // CRITICAL FIX: Close modal and clear ALL overflow styles immediately
    const modal = document.getElementById('maintenanceConfirmModal');
    console.log('DEBUG: Modal element:', modal);
    
    if (modal) {
        modal.style.display = 'none';
        console.log('DEBUG: Modal hidden');
    }
    
    // Force remove all overflow styles
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('overflow-x');
    document.body.style.removeProperty('overflow-y');
    document.body.style.overflow = 'visible';
    document.documentElement.style.overflow = 'visible';
    console.log('DEBUG: Overflow styles cleared');
    
    // Create hidden input
    const hiddenInput = document.createElement('input');
    hiddenInput.type = 'hidden';
    hiddenInput.name = 'toggle_maintenance';
    hiddenInput.value = '1';
    form.appendChild(hiddenInput);
    console.log('DEBUG: Hidden input created and appended:', hiddenInput);
    console.log('DEBUG: Form action:', form.action);
    console.log('DEBUG: Form method:', form.method);
    
    // Submit form
    console.log('DEBUG: About to submit form...');
    try {
        form.submit();
        console.log('DEBUG: Form.submit() called successfully');
    } catch (error) {
        console.error('DEBUG: Form submission error:', error);
        alert('Error submitting form: ' + error.message);
    }
    
    return false;
}

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
            }, 5000);
        }
    }
    
    if (successToast && successToast.textContent.trim()) {
        showAndDismissToast(successToast);
        if (successToast.textContent.includes('Maintenance')) {
            setTimeout(() => {
                showMaintenancePopup();
            }, 500);
        }
    }
    
    if (errorToast && errorToast.textContent.trim()) {
        showAndDismissToast(errorToast);
    }
    
    // CRITICAL FIX: Ensure body is scrollable on page load
    document.body.style.overflow = 'visible';
    document.documentElement.style.overflow = 'visible';
});

// Show maintenance popup notification
function showMaintenancePopup() {
    const successToast = document.getElementById('successToast');
    const isEnabled = successToast && successToast.textContent.includes('ENABLED');
    
    const popup = document.createElement('div');
    popup.id = 'maintenancePopup';
    popup.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #130325; color: #ffffff; padding: 20px 24px; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); z-index: 10001; max-width: 400px; animation: slideInRight 0.3s ease;';
    
    const title = isEnabled ? 'Maintenance Has Started!' : 'Maintenance Mode Disabled!';
    const message = isEnabled 
        ? 'The site is now in maintenance mode. Regular users cannot access the site.'
        : 'The site is now accessible to all users.';
    const icon = isEnabled ? 'fa-tools' : 'fa-check-circle';
    
    popup.innerHTML = `
        <div style="display: flex; align-items: center; gap: 12px;">
            <i class="fas ${icon}" style="font-size: 24px; color: #FFD736;"></i>
            <div>
                <strong style="display: block; font-size: 16px; margin-bottom: 4px;">${title}</strong>
                <span style="font-size: 13px; opacity: 0.9;">${message}</span>
            </div>
        </div>
    `;
    document.body.appendChild(popup);
    
    setTimeout(() => {
        popup.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => {
            popup.remove();
        }, 300);
    }, 5000);
    
    if (!document.getElementById('maintenancePopupStyles')) {
        const style = document.createElement('style');
        style.id = 'maintenancePopupStyles';
        style.textContent = `
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOutRight {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
    }
}

// Discount Code Functions
function toggleDiscountForm() {
    const form = document.getElementById('discountForm');
    if (form) {
        form.style.display = form.style.display === 'none' ? 'block' : 'none';
    }
}

function updateDiscountType() {
    const type = document.getElementById('discount_type').value;
    const valueInput = document.getElementById('discount_value');
    const hint = document.getElementById('discount_value_hint');
    
    if (type === 'percentage') {
        valueInput.max = 100;
        valueInput.placeholder = '10';
        if (hint) hint.textContent = 'Enter percentage (0-100)';
    } else {
        valueInput.max = null;
        valueInput.placeholder = '100.00';
        if (hint) hint.textContent = 'Enter fixed amount in pesos';
    }
}

// Authentication Modal Functions
function openAuthModal(section) {
    if (section === 'site') {
        const siteName = document.getElementById('site_name').value;
        const siteEmail = document.getElementById('site_email').value;
        const sitePhone = document.getElementById('site_phone').value;
        const siteAddress = document.getElementById('site_address').value;
        
        document.getElementById('auth_site_name').value = siteName;
        document.getElementById('auth_site_email').value = siteEmail;
        document.getElementById('auth_site_phone').value = sitePhone;
        document.getElementById('auth_site_address').value = siteAddress;
        
        const modal = document.getElementById('authModal');
        if (modal) {
            modal.style.display = 'flex';
            document.getElementById('auth_password').focus();
        }
    }
}

function closeAuthModal() {
    const modal = document.getElementById('authModal');
    if (modal) {
        modal.style.display = 'none';
    }
    document.body.style.removeProperty('overflow');
}

// Close auth modal on overlay click
document.addEventListener('click', function(e) {
    const modal = document.getElementById('authModal');
    if (modal && e.target === modal) {
        closeAuthModal();
    }
});

// Update change type hint
function updateChangeType() {
    const type = document.getElementById('change_type').value;
    const hint = document.getElementById('password_hint');
    if (hint) {
        if (type === 'pin') {
            hint.textContent = 'PIN must be 4-6 digits';
        } else {
            hint.textContent = 'Password must be at least 6 characters';
        }
    }
}
</script>
