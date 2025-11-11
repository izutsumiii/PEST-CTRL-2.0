<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
// Access control BEFORE any output
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ../login_admin.php');
    exit();
}

// Handle discount code operations that redirect - MUST be before header output
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
        $_SESSION['admin_error'] = "Discount code cannot be empty.";
        header("Location: admin-settings.php?section=order");
        exit();
    } elseif ($discountValue <= 0) {
        $_SESSION['admin_error'] = "Discount value must be greater than 0.";
        header("Location: admin-settings.php?section=order");
        exit();
    } elseif ($discountType === 'percentage' && $discountValue > 100) {
        $_SESSION['admin_error'] = "Percentage discount cannot exceed 100%.";
        header("Location: admin-settings.php?section=order");
        exit();
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
            $_SESSION['admin_success'] = "Discount code '{$code}' created successfully!";
            // Redirect to maintain section parameter
            header("Location: admin-settings.php?section=order");
            exit();
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $_SESSION['admin_error'] = "Discount code '{$code}' already exists.";
            } else {
                $_SESSION['admin_error'] = "Error creating discount code: " . $e->getMessage();
            }
            header("Location: admin-settings.php?section=order");
            exit();
        }
    }
}

if (isset($_GET['delete_discount']) && is_numeric($_GET['delete_discount'])) {
    $id = intval($_GET['delete_discount']);
    try {
        $stmt = $pdo->prepare("DELETE FROM discount_codes WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['admin_success'] = "Discount code deleted successfully!";
        // Redirect to maintain section parameter
        header("Location: admin-settings.php?section=order");
        exit();
    } catch (PDOException $e) {
        $_SESSION['admin_error'] = "Error deleting discount code: " . $e->getMessage();
        header("Location: admin-settings.php?section=order");
        exit();
    }
}

// Handle discount code update - MUST be before header output
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
        $_SESSION['admin_success'] = "Discount code updated successfully!";
        header("Location: admin-settings.php?section=order");
        exit();
    } catch (PDOException $e) {
        $_SESSION['admin_error'] = "Error updating discount code: " . $e->getMessage();
        header("Location: admin-settings.php?section=order&edit_discount=" . $id);
        exit();
    }
}

require_once 'includes/admin_header.php';

// Get success/error messages from session (set by form processing before header)
$success = isset($_SESSION['admin_success']) ? $_SESSION['admin_success'] : '';
$error = isset($_SESSION['admin_error']) ? $_SESSION['admin_error'] : '';
// Clear session messages after reading
unset($_SESSION['admin_success'], $_SESSION['admin_error']);

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
    // Verify password/PIN - same as admin login
    $pinVerified = false;
    if (isset($_POST['maintenance_pin']) && !empty($_POST['maintenance_pin'])) {
        $enteredPin = sanitizeInput($_POST['maintenance_pin']);
        
        // Verify against admin password (same as login_admin.php)
        try {
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $adminPasswordHash = $stmt->fetchColumn();
            
            if ($adminPasswordHash && password_verify($enteredPin, $adminPasswordHash)) {
                $pinVerified = true;
            } else {
                $error = "Invalid password. Maintenance settings not saved.";
            }
        } catch (PDOException $e) {
            $error = "Error verifying password: " . $e->getMessage();
        }
    } else {
        $error = "Password is required to change maintenance settings.";
    }
    
    if ($pinVerified || empty($error)) {
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

// Get all discount codes
$discountCodes = [];
try {
    $stmt = $pdo->query("SELECT * FROM discount_codes ORDER BY created_at DESC");
    $discountCodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table might not exist yet
}

// Get discount code for editing
$editingDiscount = null;
$editDiscountId = isset($_GET['edit_discount']) ? intval($_GET['edit_discount']) : 0;
if ($editDiscountId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM discount_codes WHERE id = ?");
        $stmt->execute([$editDiscountId]);
        $editingDiscount = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Error loading discount code for editing: " . $e->getMessage();
    }
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
        margin: -30px auto 10px auto !important;
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
        margin: 10px auto 0 auto;
        padding: 0 24px 24px;
    }
    
    .settings-container h2 {
        margin-bottom: 16px;
        color: #130325;
        font-size: 16px;
        font-weight: 700;
    }

    .settings-card {
        background: #ffffff;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 16px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .form-group select {
        padding: 6px 10px;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 6px;
        font-size: 12px;
        background: #ffffff;
        color: #130325;
        width: 100%;
        max-width: 200px;
        transition: border-color 0.2s;
    }
    
    .form-group select:focus {
        outline: none;
        border-color: #130325;
    }

    .section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 16px;
        padding-bottom: 12px;
        border-bottom: 1px solid rgba(0,0,0,0.08);
    }
    
    .settings-card h3 {
        font-size: 14px;
        font-weight: 700;
        color: #130325;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
        text-shadow: none !important;
    }
    
    .section-header .info-icon {
        margin-left: auto;
        margin-right: 0;
    }

    .form-group {
        margin-bottom: 12px;
    }
    
    .form-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 12px;
        margin-bottom: 12px;
    }

    .form-group label {
        font-size: 12px;
        font-weight: 600;
        color: #130325;
        margin-bottom: 6px;
        display: block;
    }
    
    .view-content {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-bottom: 16px;
    }
    
    .view-item {
        font-size: 12px;
        color: #130325;
        line-height: 1.6;
    }
    
    .view-item strong {
        font-weight: 600;
        margin-right: 8px;
        color: #130325;
    }
    
    .view-item span {
        color: #6b7280;
    }
    
    .btn-edit {
        background: linear-gradient(135deg, #FFD736 0%, #FFC107 100%);
        color: #130325;
        border: none;
        padding: 6px 12px;
        border-radius: 6px;
        font-weight: 600;
        font-size: 12px;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    
    .btn-edit:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(255, 215, 54, 0.3);
    }

    .info-icon {
        color: #130325;
        cursor: pointer;
        font-size: 14px;
        transition: none;
        background: none;
        border: none;
        padding: 0;
        margin-left: 6px;
    }

    .info-icon:hover {
        color: #130325;
        transform: none;
    }

    .input-group {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .form-group input {
        padding: 6px 10px;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 6px;
        font-size: 12px;
        background: #ffffff;
        color: #130325;
        width: 120px;
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
        padding: 6px 12px;
        border-radius: 6px;
        font-weight: 600;
        font-size: 12px;
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
    
    /* Maintenance Confirmation Modal - EXACT MATCH TO LOGOUT MODAL */
    /* Use same class as logout modal - no ID selector needed */
    #maintenanceConfirmModal.modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.35);
        align-items: center;
        justify-content: center;
        z-index: 9999;
    }

    #maintenanceConfirmModal .modal-dialog {
        width: 360px;
        max-width: 90vw;
        background: #ffffff;
        border: none;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    }

    #maintenanceConfirmModal .modal-header {
        padding: 8px 12px;
        background: #130325;
        color: #F9F9F9;
        border-bottom: none;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-radius: 12px 12px 0 0;
    }

    #maintenanceConfirmModal .modal-title {
        font-size: 12px;
        font-weight: 800;
        letter-spacing: .3px;
        margin: 0;
    }

    #maintenanceConfirmModal .modal-close {
        background: transparent;
        border: none;
        color: #F9F9F9;
        font-size: 20px;
        line-height: 1;
        cursor: pointer;
        padding: 0;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    #maintenanceConfirmModal .modal-close:hover {
        opacity: 0.8;
    }

    #maintenanceConfirmModal .modal-body {
        padding: 12px;
        color: #130325;
        font-size: 12px;
    }

    #maintenanceConfirmModal .modal-actions {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
        padding: 0 12px 12px 12px;
    }

    #maintenanceConfirmModal .btn-outline {
        background: #ffffff;
        color: #130325;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 8px;
        padding: 6px 10px;
        font-weight: 700;
        font-size: 12px;
        cursor: pointer;
    }

    #maintenanceConfirmModal .btn-outline:hover {
        background: #f3f4f6;
    }

    #maintenanceConfirmModal .btn-primary-y {
        background: linear-gradient(135deg, #FFD736 0%, #FFC107 100%);
        color: #130325;
        border: none;
        border-radius: 8px;
        padding: 6px 10px;
        font-weight: 700;
        font-size: 12px;
        cursor: pointer;
    }

    #maintenanceConfirmModal .btn-primary-y:hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 8px rgba(255, 215, 54, 0.3);
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

    .status-badge-compact {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: 700;
        cursor: default;
        white-space: nowrap;
    }

    .status-active {
        background: rgba(16, 185, 129, 0.15);
        color: #059669;
        border: 1px solid #10b981;
    }

    .status-active i {
        color: #10b981;
    }

    .status-inactive {
        background: rgba(220, 38, 38, 0.15);
        color: #dc2626;
        border: 1px solid #dc2626;
    }
    
    .status-inactive i {
        color: #dc2626;
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
        width: 44px;
        height: 22px;
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
        border-radius: 22px;
    }

    .toggle-slider:before {
        position: absolute;
        content: "";
        height: 16px;
        width: 16px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: 0.3s;
        border-radius: 50%;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }

    input:checked + .toggle-slider {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    }

    input:checked + .toggle-slider:before {
        transform: translateX(22px);
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
        font-size: 12px;
        color: #130325;
        margin-bottom: 4px;
        font-weight: 600;
    }

    .toggle-info p {
        font-size: 11px;
        color: #6b7280;
        margin: 0;
        line-height: 1.4;
    }

    .form-group textarea {
        width: 100%;
        padding: 6px 10px;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 6px;
        font-size: 12px;
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
        font-size: 13px;
        font-weight: 600;
        color: #130325;
        margin: 0 0 6px 0;
        display: flex;
        align-items: center;
        gap: 8px;
        text-shadow: none !important;
    }

    .schedule-header p {
        font-size: 11px;
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
        background: #130325;
        color: #ffffff;
    }

    .discount-table th {
        padding: 12px 16px;
        text-align: left;
        font-weight: 600;
        font-size: 13px;
        color: #ffffff;
        border-bottom: 2px solid rgba(255, 255, 255, 0.15);
    }

    .discount-table th.sortable {
        cursor: pointer;
        user-select: none;
        transition: background 0.2s ease, color 0.2s ease;
    }

    .discount-table th.sortable:hover {
        background: #1f0641;
    }

    .discount-table th .sort-label {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .discount-table th .sort-indicator {
        font-size: 12px;
        opacity: 0.5;
        transition: opacity 0.2s ease;
        min-width: 10px;
        display: inline-block;
        text-align: center;
    }

    .discount-table th.sortable.active-asc .sort-indicator,
    .discount-table th.sortable.active-desc .sort-indicator {
        opacity: 1;
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
        padding: 0;
        font-size: 14px;
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
            <div class="section-header">
                <h3>
                    <i class="fas fa-shopping-cart"></i> Customer Cancellation Grace Period
                </h3>
                <i class="fas fa-info-circle info-icon" onclick="openInfoModal('gracePeriod')" title="Click for instructions"></i>
            </div>
            
            <div id="gracePeriodView">
                <div class="current-value-badge" style="margin-top: 0;">
                    Current Setting: <?php echo $currentGracePeriod; ?> minutes
                </div>
                <button type="button" class="btn-save" onclick="editGracePeriod()" style="margin-top: 12px;">
                    <i class="fas fa-edit"></i> Edit
                </button>
            </div>
            
            <div id="gracePeriodFields" style="display: none;">
                <div class="form-group">
                    <label for="grace_period">Grace Period (minutes)</label>
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
                </div>
                
                <div style="display: flex; gap: 8px; margin-top: 12px;">
                    <button type="button" class="btn-save" onclick="confirmGracePeriodSave()">
                        <i class="fas fa-save"></i> Save
                    </button>
                    <button type="button" class="btn-cancel" onclick="cancelGracePeriodEdit()" style="background: #6c757d; color: #ffffff; border: none; padding: 6px 12px; border-radius: 6px; font-weight: 600; font-size: 12px; cursor: pointer;">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </form>

    <!-- Discount/Promo Codes Section -->
    <div class="settings-card">
        <div class="section-header">
            <h3>
                <i class="fas fa-tag"></i> Discount & Promo Codes
            </h3>
        </div>
        
        <div class="discount-codes-list">
            <table class="discount-table">
                <thead>
                    <tr>
                        <th class="sortable" data-column="code" data-type="text">
                            <span class="sort-label">Code <span class="sort-indicator"></span></span>
                        </th>
                        <th class="sortable" data-column="type" data-type="text">
                            <span class="sort-label">Type <span class="sort-indicator"></span></span>
                        </th>
                        <th class="sortable" data-column="value" data-type="number">
                            <span class="sort-label">Value <span class="sort-indicator"></span></span>
                        </th>
                        <th class="sortable" data-column="min-order" data-type="number">
                            <span class="sort-label">Min Order <span class="sort-indicator"></span></span>
                        </th>
                        <th class="sortable" data-column="uses" data-type="ratio">
                            <span class="sort-label">Uses <span class="sort-indicator"></span></span>
                        </th>
                        <th class="sortable" data-column="period" data-type="date">
                            <span class="sort-label">Valid Period <span class="sort-indicator"></span></span>
                        </th>
                        <th class="sortable" data-column="status" data-type="status">
                            <span class="sort-label">Status <span class="sort-indicator"></span></span>
                        </th>
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
                    <?php
                        $codeSort = strtolower($dc['code']);
                        $valueSort = number_format((float)$dc['discount_value'], 6, '.', '');
                        $minOrderSort = number_format((float)$dc['min_order_amount'], 6, '.', '');
                        $usedCount = isset($dc['used_count']) ? (int)$dc['used_count'] : 0;
                        $maxUses = isset($dc['max_uses']) && $dc['max_uses'] ? (int)$dc['max_uses'] : null;
                        $usesSort = $maxUses ? $usedCount / max($maxUses, 1) : -1;
                        $usesDisplay = $usedCount . '/' . ($maxUses ? $maxUses : '∞');
                        $startTimestamp = $dc['start_date'] ? strtotime($dc['start_date']) : 0;
                        $statusSort = $dc['is_active'] ? 1 : 0;
                    ?>
                    <tr>
                        <td data-sort-value="<?php echo htmlspecialchars($codeSort, ENT_QUOTES); ?>">
                            <strong><?php echo htmlspecialchars($dc['code']); ?></strong>
                        </td>
                        <td data-sort-value="<?php echo htmlspecialchars($dc['discount_type'], ENT_QUOTES); ?>">
                            <?php echo ucfirst($dc['discount_type']); ?>
                        </td>
                        <td data-sort-value="<?php echo $valueSort; ?>">
                            <?php 
                            if ($dc['discount_type'] === 'percentage') {
                                echo number_format($dc['discount_value'], 0) . '%';
                            } else {
                                echo '₱' . number_format($dc['discount_value'], 2);
                            }
                            ?>
                        </td>
                        <td data-sort-value="<?php echo $minOrderSort; ?>">₱<?php echo number_format($dc['min_order_amount'], 2); ?></td>
                        <td data-sort-value="<?php echo $usesSort; ?>"><?php echo $usesDisplay; ?></td>
                        <td data-sort-value="<?php echo $startTimestamp; ?>">
                            <?php 
                            echo date('M d, Y', strtotime($dc['start_date']));
                            if ($dc['end_date']) {
                                echo ' - ' . date('M d, Y', strtotime($dc['end_date']));
                            } else {
                                echo ' (No expiry)';
                            }
                            ?>
                        </td>
                        <td data-sort-value="<?php echo $statusSort; ?>">
                            <span class="status-badge-compact <?php echo $dc['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                <?php echo $dc['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </td>
                        <td>
                            <button type="button" onclick="showEditDiscountConfirm(<?php echo $dc['id']; ?>);" class="btn-edit-small" title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button type="button" onclick="showDeleteDiscountConfirm(<?php echo $dc['id']; ?>);" class="btn-delete-small" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if (!$editingDiscount): ?>
        <button type="button" class="btn-save" onclick="toggleDiscountForm()" style="margin-top: 16px;">
            <i class="fas fa-plus"></i> Create Code
        </button>
        <?php else: ?>
        <div style="margin-top: 16px; padding: 12px; background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 8px;">
            <strong style="color: #3b82f6;"><i class="fas fa-edit"></i> Editing Discount Code: <?php echo htmlspecialchars($editingDiscount['code']); ?></strong>
            <a href="?section=order" style="float: right; color: #6c757d; text-decoration: none; font-size: 12px;">
                <i class="fas fa-times"></i> Cancel Edit
            </a>
        </div>
        <?php endif; ?>
        
        <div id="discountForm" class="discount-form" style="display: <?php echo $editingDiscount ? 'block' : 'none'; ?>;">
            <form method="POST" action="?section=order">
                <?php if ($editingDiscount): ?>
                    <input type="hidden" name="discount_id" value="<?php echo $editingDiscount['id']; ?>">
                <?php endif; ?>
                <div class="form-row">
                    <div class="form-group">
                        <label for="discount_code">Discount Code *</label>
                        <input type="text" id="discount_code" name="discount_code" required placeholder="e.g., SUMMER2024" maxlength="50" value="<?php echo $editingDiscount ? htmlspecialchars($editingDiscount['code']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="discount_type">Discount Type *</label>
                        <select id="discount_type" name="discount_type" required onchange="updateDiscountType()">
                            <option value="percentage" <?php echo ($editingDiscount && $editingDiscount['discount_type'] === 'percentage') ? 'selected' : ''; ?>>Percentage (%)</option>
                            <option value="fixed" <?php echo ($editingDiscount && $editingDiscount['discount_type'] === 'fixed') ? 'selected' : ''; ?>>Fixed Amount (₱)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="discount_value">Discount Value *</label>
                        <input type="number" id="discount_value" name="discount_value" step="0.01" min="0.01" required placeholder="10" value="<?php echo $editingDiscount ? htmlspecialchars($editingDiscount['discount_value']) : ''; ?>">
                        <small id="discount_value_hint">Enter <?php echo ($editingDiscount && $editingDiscount['discount_type'] === 'percentage') ? 'percentage (0-100)' : 'fixed amount in pesos'; ?></small>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="min_order_amount">Minimum Order Amount (₱)</label>
                        <input type="number" id="min_order_amount" name="min_order_amount" step="0.01" min="0" value="<?php echo $editingDiscount ? htmlspecialchars($editingDiscount['min_order_amount']) : '0'; ?>" placeholder="0.00">
                    </div>
                    
                    <div class="form-group">
                        <label for="max_uses">Maximum Uses (0 = unlimited)</label>
                        <input type="number" id="max_uses" name="max_uses" min="0" value="<?php echo $editingDiscount ? htmlspecialchars($editingDiscount['max_uses'] ?? 0) : '0'; ?>" placeholder="0">
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="is_active" <?php echo ($editingDiscount && $editingDiscount['is_active']) || !$editingDiscount ? 'checked' : ''; ?>> Active
                        </label>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="start_date">Start Date</label>
                        <input type="datetime-local" id="start_date" name="start_date" value="<?php echo $editingDiscount ? date('Y-m-d\TH:i', strtotime($editingDiscount['start_date'])) : date('Y-m-d\TH:i'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="end_date">End Date (Optional)</label>
                        <input type="datetime-local" id="end_date" name="end_date" value="<?php echo ($editingDiscount && $editingDiscount['end_date']) ? date('Y-m-d\TH:i', strtotime($editingDiscount['end_date'])) : ''; ?>">
                    </div>
                </div>
                
                <div class="form-actions" style="display: flex; gap: 8px; margin-top: 16px;">
                    <?php if ($editingDiscount): ?>
                        <button type="submit" name="update_discount_code" class="btn-save">
                            <i class="fas fa-save"></i> Update Code
                        </button>
                        <a href="?section=order" class="btn-cancel" style="background: #6c757d; color: #ffffff; border: none; padding: 6px 12px; border-radius: 6px; font-weight: 600; font-size: 12px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center;">
                            Cancel
                        </a>
                    <?php else: ?>
                        <button type="submit" name="create_discount_code" class="btn-save">
                            <i class="fas fa-plus"></i> Create Code
                        </button>
                        <button type="button" class="btn-cancel" onclick="toggleDiscountForm()" style="background: #6c757d; color: #ffffff; border: none; padding: 6px 12px; border-radius: 6px; font-weight: 600; font-size: 12px; cursor: pointer;">
                            Cancel
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <?php elseif ($currentSection === 'maintenance'): ?>
    <!-- Maintenance Mode Settings Card -->
    <form method="POST" action="" id="maintenanceForm">
        <div class="settings-card">
            <div class="section-header">
                <h3>
                    <i class="fas fa-tools"></i> Maintenance Mode Control
                    <div class="status-badge <?php echo $maintenanceMode === '1' ? 'status-active' : 'status-inactive'; ?>">
                        <i class="fas fa-circle"></i>
                        <?php echo $maintenanceMode === '1' ? 'ACTIVE' : 'INACTIVE'; ?>
                    </div>
                </h3>
                <i class="fas fa-info-circle info-icon" onclick="openMaintenanceInfoModal()" title="Click for instructions"></i>
            </div>
            
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

            <button type="button" onclick="showMaintenanceConfirm(); return false;" class="btn-save" style="margin-top: 16px;">
                <i class="fas fa-save"></i> Save Settings
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
            <div class="section-header">
                <h3>
                    <i class="fas fa-cog"></i> Site Settings
                </h3>
            </div>
            
            <div id="siteSettingsFields" style="display: none;">
                <div class="form-row">
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
                
                <div style="display: flex; gap: 8px;">
                    <button type="button" class="btn-save" onclick="confirmSiteSettingsSave()">
                        <i class="fas fa-save"></i> Save
                    </button>
                    <button type="button" class="btn-cancel" onclick="cancelSiteSettingsEdit()" style="background: #6c757d; color: #ffffff; border: none; padding: 6px 12px; border-radius: 6px; font-weight: 600; font-size: 12px; cursor: pointer;">
                        Cancel
                    </button>
                </div>
            </div>
            
            <div id="siteSettingsView">
                <div class="view-content">
                    <div class="view-item"><strong>Contact Email:</strong> <span><?php echo htmlspecialchars($currentSiteEmail) ?: 'Not set'; ?></span></div>
                    <div class="view-item"><strong>Contact Phone:</strong> <span><?php echo htmlspecialchars($currentSitePhone) ?: 'Not set'; ?></span></div>
                    <div class="view-item"><strong>Business Address:</strong> <span><?php echo htmlspecialchars($currentSiteAddress) ?: 'Not set'; ?></span></div>
                </div>
                <button type="button" class="btn-edit" onclick="editSiteSettings()">
                    <i class="fas fa-edit"></i> Edit
                </button>
            </div>
        </div>
    </form>
    
    <!-- Password/PIN Change Section -->
    <form method="POST" action="">
        <div class="settings-card">
            <div class="section-header">
                <h3>
                    <i class="fas fa-key"></i> Change Password / PIN
                </h3>
            </div>
            
            <div id="passwordFields" style="display: none;">
                <div class="form-group">
                    <label for="change_type">Change Type</label>
                    <select id="change_type" name="change_type" onchange="updateChangeType()" style="padding: 6px 10px; border: 1px solid rgba(0,0,0,0.1); border-radius: 6px; font-size: 12px; width: 200px;">
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
                
                <div style="display: flex; gap: 8px;">
                    <button type="button" class="btn-save" onclick="confirmPasswordChange()">
                        <i class="fas fa-save"></i> Save
                    </button>
                    <button type="button" class="btn-cancel" onclick="cancelPasswordEdit()" style="background: #6c757d; color: #ffffff; border: none; padding: 6px 12px; border-radius: 6px; font-weight: 600; font-size: 12px; cursor: pointer;">
                        Cancel
                    </button>
                </div>
            </div>
            
            <div id="passwordView">
                <button type="button" class="btn-edit" onclick="editPassword()">
                    <i class="fas fa-edit"></i> Edit
                </button>
            </div>
        </div>
    </form>
    
    <?php elseif ($currentSection === 'profile'): ?>
    <!-- Edit Profile Section -->
    <form method="POST" action="" id="profileForm">
        <div class="settings-card">
            <div class="section-header">
                <h3>
                    <i class="fas fa-user-edit"></i> Edit Profile
                </h3>
            </div>
            
            <div id="profileFields" style="display: none;">
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
                
                <div style="display: flex; gap: 8px; margin-top: 12px;">
                    <button type="button" class="btn-save" onclick="confirmProfileUpdate()">
                        <i class="fas fa-save"></i> Save
                    </button>
                    <button type="button" class="btn-cancel" onclick="cancelProfileEdit()">
                        Cancel
                    </button>
                </div>
            </div>
            
            <div id="profileView">
                <div class="view-content">
                    <div class="view-item"><strong>First Name:</strong> <span><?php echo htmlspecialchars($userProfile['first_name'] ?? ''); ?></span></div>
                    <div class="view-item"><strong>Last Name:</strong> <span><?php echo htmlspecialchars($userProfile['last_name'] ?? ''); ?></span></div>
                    <div class="view-item"><strong>Display Name:</strong> <span><?php echo htmlspecialchars($userProfile['display_name'] ?? 'Not set'); ?></span></div>
                    <div class="view-item"><strong>Username:</strong> <span><?php echo htmlspecialchars($userProfile['username'] ?? ''); ?></span></div>
                    <div class="view-item"><strong>Email:</strong> <span><?php echo htmlspecialchars($userProfile['email'] ?? ''); ?></span></div>
                    <div class="view-item"><strong>Phone:</strong> <span><?php echo htmlspecialchars($userProfile['phone'] ?? 'Not set'); ?></span></div>
                </div>
                <button type="button" class="btn-edit" onclick="editProfile()">
                    <i class="fas fa-edit"></i> Edit
                </button>
            </div>
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
</div>

<!-- Maintenance Save Confirmation Modal - MATCHES LOGOUT MODAL -->
<!-- MOVED OUTSIDE authModal TO FIX DISPLAY ISSUE -->
<div id="maintenanceConfirmModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-header">
            <div class="modal-title">Confirm Maintenance Settings</div>
            <button class="modal-close" aria-label="Close" onclick="closeMaintenanceConfirm()">×</button>
        </div>
        <div class="modal-body">
            <p id="maintenanceConfirmMessage" style="margin: 0 0 12px 0; color: #130325; font-size: 12px; line-height: 1.5; font-weight: 500;"></p>
            <div class="form-group" style="margin-bottom: 0;">
                <label for="maintenance_confirm_pin" style="font-size: 11px; color: #6b7280; margin-bottom: 6px; display: block; font-weight: 600;">Enter your admin password to confirm:</label>
                <input type="password" 
                       id="maintenance_confirm_pin" 
                       style="padding: 8px 10px; border: 1px solid rgba(0,0,0,0.15); border-radius: 6px; font-size: 13px; width: 100%;"
                       placeholder="Enter your admin password">
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-outline" onclick="closeMaintenanceConfirm()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="button" class="btn-primary-y" onclick="submitMaintenanceForm()">
                <i class="fas fa-check"></i> Confirm
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
    console.log('DEBUG: openInfoModal called with type:', type);
    const modal = document.getElementById('infoModal');
    
    if (!modal) {
        console.error('DEBUG: infoModal not found');
        alert('Error: Info modal not found. Please refresh the page.');
        return;
    }
    
    const titleEl = modal.querySelector('.info-modal-title');
    const bodyEl = document.getElementById('infoModalContent');
    
    console.log('DEBUG: Modal found:', modal);
    console.log('DEBUG: Title element:', titleEl);
    console.log('DEBUG: Body element:', bodyEl);
    
    if (type === 'gracePeriod') {
        if (titleEl) titleEl.textContent = 'Order Grace Period Guide';
        if (bodyEl) {
            bodyEl.innerHTML = `
                <h4 style="font-size: 13px; font-weight: 600; margin: 0 0 8px 0; color: #130325;">What is the Grace Period?</h4>
                <p style="margin: 0 0 12px 0; font-size: 12px; color: #130325; line-height: 1.5;">The grace period is the time window after an order is placed during which customers can cancel their order without penalty. After this period expires, the order moves to "processing" status and sellers can begin fulfillment.</p>
                <h4 style="font-size: 13px; font-weight: 600; margin: 12px 0 8px 0; color: #130325;">How it works:</h4>
                <ul style="margin: 0; padding-left: 20px; font-size: 12px; color: #130325;">
                    <li style="margin: 4px 0;">Order placed at 10:00 AM with 15-minute grace period</li>
                    <li style="margin: 4px 0;">Grace period active until 10:15 AM</li>
                    <li style="margin: 4px 0;">Customer can cancel anytime before 10:15 AM</li>
                    <li style="margin: 4px 0;">Seller can process order starting at 10:15 AM</li>
                </ul>
            `;
        }
    } else if (type === 'maintenance') {
        if (titleEl) titleEl.textContent = 'Maintenance Mode Guide';
        if (bodyEl) {
            bodyEl.innerHTML = `
                <h4 style="font-size: 13px; font-weight: 600; margin: 0 0 8px 0; color: #130325;">How it works:</h4>
                <ul style="margin: 0; padding-left: 20px; font-size: 12px; color: #130325;">
                    <li style="margin: 4px 0;"><strong>Manual Mode:</strong> Toggle "Enable Maintenance Mode" to activate immediately</li>
                    <li style="margin: 4px 0;"><strong>Scheduled Mode:</strong> Set start/end times and enable "Automatic Scheduled Maintenance"</li>
                    <li style="margin: 4px 0;">Site will automatically enter maintenance during the scheduled window</li>
                    <li style="margin: 4px 0;">Admins can always access the site regardless of maintenance mode</li>
                </ul>
            `;
        }
    }
    
    // Force show the modal
    modal.style.setProperty('display', 'flex', 'important');
    modal.style.setProperty('visibility', 'visible', 'important');
    modal.style.setProperty('opacity', '1', 'important');
    modal.classList.add('show');
    document.body.style.setProperty('overflow', 'hidden', 'important');
    
    console.log('DEBUG: Info modal should be visible now');
}

function closeInfoModal() {
    const modal = document.getElementById('infoModal');
    if (modal) {
        modal.classList.remove('show');
        modal.style.setProperty('display', 'none', 'important');
        modal.style.removeProperty('visibility');
        modal.style.removeProperty('opacity');
        document.body.style.removeProperty('overflow');
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
        
        // Close maintenance modal if open
        const maintenanceModal = document.getElementById('maintenanceConfirmModal');
        if (maintenanceModal && maintenanceModal.classList.contains('show')) {
            closeMaintenanceConfirm();
        }
        
    }
});

// Maintenance Modal Functions - SIMPLIFIED AND WORKING
function showMaintenanceConfirm() {
    console.log('=== DEBUG: showMaintenanceConfirm CALLED ===');
    
    let modal = document.getElementById('maintenanceConfirmModal');
    if (!modal) {
        alert('Error: Modal element not found. Please refresh the page.');
        console.error('DEBUG: Modal element NOT FOUND in DOM!');
        return;
    }
    
    // CRITICAL FIX: If modal is inside a hidden parent (like authModal), move it to body
    const modalParent = modal.parentElement;
    if (modalParent && (modalParent.id === 'authModal' || window.getComputedStyle(modalParent).display === 'none')) {
        console.log('DEBUG: Modal is inside hidden parent, moving to body');
        document.body.appendChild(modal);
        console.log('DEBUG: Modal moved to body, new parent:', modal.parentElement);
    }
    
    console.log('DEBUG: Modal element found:', modal);
    console.log('DEBUG: Modal ID:', modal.id);
    console.log('DEBUG: Modal classes:', modal.className);
    console.log('DEBUG: Modal tagName:', modal.tagName);
    console.log('DEBUG: Modal parentElement:', modal.parentElement);
    console.log('DEBUG: Modal offsetParent:', modal.offsetParent);
    console.log('DEBUG: Modal isConnected:', modal.isConnected);
    
    // Check modal location in DOM
    const rect = modal.getBoundingClientRect();
    console.log('DEBUG: Modal getBoundingClientRect:', {
        top: rect.top,
        left: rect.left,
        width: rect.width,
        height: rect.height,
        visible: rect.width > 0 && rect.height > 0
    });
    
    // Check computed styles BEFORE setting display
    const computedBefore = window.getComputedStyle(modal);
    console.log('DEBUG: Computed styles BEFORE:', {
        display: computedBefore.display,
        visibility: computedBefore.visibility,
        opacity: computedBefore.opacity,
        zIndex: computedBefore.zIndex,
        position: computedBefore.position,
        top: computedBefore.top,
        left: computedBefore.left,
        width: computedBefore.width,
        height: computedBefore.height
    });
    
    // Check inline styles BEFORE
    console.log('DEBUG: Inline styles BEFORE:', {
        display: modal.style.display,
        visibility: modal.style.visibility,
        opacity: modal.style.opacity
    });
    
    // Check parent elements
    let parent = modal.parentElement;
    let level = 0;
    while (parent && level < 5) {
        const parentStyle = window.getComputedStyle(parent);
        console.log(`DEBUG: Parent level ${level}:`, {
            tag: parent.tagName,
            id: parent.id,
            class: parent.className,
            display: parentStyle.display,
            visibility: parentStyle.visibility,
            opacity: parentStyle.opacity,
            overflow: parentStyle.overflow,
            zIndex: parentStyle.zIndex
        });
        parent = parent.parentElement;
        level++;
    }
    
    // Get maintenance mode checkbox
    const maintenanceCheckbox = document.getElementById('maintenance_mode');
    const isEnabled = maintenanceCheckbox ? maintenanceCheckbox.checked : false;
    const currentState = <?php echo $maintenanceMode === '1' ? 'true' : 'false'; ?>;
    
    // Set message based on action
    const messageEl = document.getElementById('maintenanceConfirmMessage');
    if (messageEl) {
        if (isEnabled !== currentState) {
            messageEl.innerHTML = isEnabled 
                ? '<strong style="color: #dc2626;">⚠️ You are about to START maintenance mode.</strong><br>Regular users will be blocked from accessing the site.'
                : '<strong style="color: #059669;">✓ You are about to END maintenance mode.</strong><br>The site will be accessible to all users.';
        } else {
            messageEl.textContent = 'Are you sure you want to save these maintenance settings?';
        }
    }
    
    // Clear PIN input
    const pinInput = document.getElementById('maintenance_confirm_pin');
    if (pinInput) {
        pinInput.value = '';
    }
    
    // CRITICAL: Show modal - EXACT SAME AS LOGOUT MODAL
    console.log('DEBUG: Setting modal.style.display = "flex"');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    
    // Check inline styles AFTER
    console.log('DEBUG: Inline styles AFTER:', {
        display: modal.style.display,
        visibility: modal.style.visibility,
        opacity: modal.style.opacity
    });
    
    // Check computed styles AFTER
    setTimeout(() => {
        const computedAfter = window.getComputedStyle(modal);
        console.log('DEBUG: Computed styles AFTER (100ms delay):', {
            display: computedAfter.display,
            visibility: computedAfter.visibility,
            opacity: computedAfter.opacity,
            zIndex: computedAfter.zIndex,
            position: computedAfter.position
        });
        
        const rectAfter = modal.getBoundingClientRect();
        console.log('DEBUG: getBoundingClientRect AFTER:', {
            top: rectAfter.top,
            left: rectAfter.left,
            width: rectAfter.width,
            height: rectAfter.height,
            visible: rectAfter.width > 0 && rectAfter.height > 0
        });
        
        // Check if modal is visible in viewport
        const isVisible = rectAfter.width > 0 && rectAfter.height > 0 && 
                         rectAfter.top >= 0 && rectAfter.left >= 0 &&
                         rectAfter.top < window.innerHeight && rectAfter.left < window.innerWidth;
        console.log('DEBUG: Modal visible in viewport:', isVisible);
        
        // Check elements at center of screen
        const centerX = window.innerWidth / 2;
        const centerY = window.innerHeight / 2;
        const elementsAtCenter = document.elementsFromPoint(centerX, centerY);
        console.log('DEBUG: Elements at center of screen:', elementsAtCenter.map(el => ({
            tag: el.tagName,
            id: el.id,
            class: el.className,
            zIndex: window.getComputedStyle(el).zIndex
        })));
    }, 100);
    
    // Focus PIN input after modal is shown
    setTimeout(() => {
        if (pinInput) {
            pinInput.focus();
        }
    }, 100);
    
    console.log('=== DEBUG: showMaintenanceConfirm END ===');
}

function closeMaintenanceConfirm() {
    const modal = document.getElementById('maintenanceConfirmModal');
    if (modal) {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }
}

function submitMaintenanceForm() {
    console.log('submitMaintenanceForm called');
    
    const pinInput = document.getElementById('maintenance_confirm_pin');
    const pin = pinInput ? pinInput.value.trim() : '';
    
    // Validate password
    if (!pin) {
        alert('Please enter your admin password.');
        if (pinInput) pinInput.focus();
        return;
    }
    
    if (pin.length < 6) {
        alert('Password must be at least 6 characters.');
        if (pinInput) pinInput.focus();
        return;
    }
    
    const form = document.getElementById('maintenanceForm');
    if (!form) {
        alert('Error: Form not found!');
        console.error('maintenanceForm not found');
        return;
    }
    
    // Add PIN as hidden input
    let pinHidden = form.querySelector('input[name="maintenance_pin"]');
    if (!pinHidden) {
        pinHidden = document.createElement('input');
        pinHidden.type = 'hidden';
        pinHidden.name = 'maintenance_pin';
        form.appendChild(pinHidden);
    }
    pinHidden.value = pin;
    
    // Add submit trigger
    let submitHidden = form.querySelector('input[name="toggle_maintenance"]');
    if (!submitHidden) {
        submitHidden = document.createElement('input');
        submitHidden.type = 'hidden';
        submitHidden.name = 'toggle_maintenance';
        submitHidden.value = '1';
        form.appendChild(submitHidden);
    }
    
    // Close modal
    closeMaintenanceConfirm();
    
    // Submit form
    console.log('Submitting form...');
    form.submit();
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('maintenanceConfirmModal');
        if (modal && modal.style.display === 'flex') {
            closeMaintenanceConfirm();
        }
    }
});

// Close modal on Enter key in PIN field
document.addEventListener('DOMContentLoaded', function() {
    const pinInput = document.getElementById('maintenance_confirm_pin');
    if (pinInput) {
        pinInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                submitMaintenanceForm();
            }
        });
    }
});


function openMaintenanceInfoModal() {
    openInfoModal('maintenance');
}

// OLD Maintenance Modal Functions (keeping for reference but not used)
function confirmMaintenanceSave(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    const maintenanceMode = document.getElementById('maintenance_mode').checked;
    const isCurrentlyActive = <?php echo $maintenanceMode === '1' ? 'true' : 'false'; ?>;
    const isChanging = (maintenanceMode && !isCurrentlyActive) || (!maintenanceMode && isCurrentlyActive);
    
    const modal = document.getElementById('maintenanceConfirmModal');
    if (!modal) {
        alert('Error: Confirmation modal not found. Please refresh the page.');
        return false;
    }
    
    // Set message based on action
    const messageEl = document.getElementById('maintenanceConfirmMessage');
    if (messageEl) {
        if (isChanging) {
            messageEl.textContent = 'Are you sure you want to ' + (maintenanceMode ? 'start' : 'end') + ' maintenance mode? Enter your PIN to confirm.';
        } else {
            messageEl.textContent = 'Are you sure you want to save these maintenance mode settings? Enter your PIN to confirm.';
        }
    }
    
    // Clear PIN field
    const pinInput = document.getElementById('maintenance_confirm_pin');
    if (pinInput) pinInput.value = '';
    
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    if (pinInput) pinInput.focus();
    
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
    const pinInput = document.getElementById('maintenance_confirm_pin');
    const pin = pinInput ? pinInput.value.trim() : '';
    
    if (!pin || pin.length < 4 || pin.length > 6 || !/^\d+$/.test(pin)) {
        alert('Please enter a valid PIN (4-6 digits).');
        if (pinInput) pinInput.focus();
        return false;
    }
    
    const form = document.getElementById('maintenanceForm');
    if (!form) {
        alert('Error: Maintenance form not found!');
        return false;
    }
    
    // Store PIN in hidden field
    let pinHidden = document.getElementById('maintenance_pin');
    if (!pinHidden) {
        pinHidden = document.createElement('input');
        pinHidden.type = 'hidden';
        pinHidden.id = 'maintenance_pin';
        pinHidden.name = 'maintenance_pin';
        form.appendChild(pinHidden);
    }
    pinHidden.value = pin;
    
    // Close modal
    const modal = document.getElementById('maintenanceConfirmModal');
    if (modal) {
        modal.style.display = 'none';
    }
    document.body.style.removeProperty('overflow');
    
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

// Discount table sorting
document.addEventListener('DOMContentLoaded', function() {
    const discountTable = document.querySelector('.discount-table');
    if (!discountTable) return;

    const headers = discountTable.querySelectorAll('th.sortable');

    const getSortValue = (cell, type) => {
        const rawValue = cell ? (cell.dataset.sortValue !== undefined ? cell.dataset.sortValue : cell.textContent.trim()) : '';
        switch (type) {
            case 'number':
            case 'ratio': {
                const numericValue = parseFloat(rawValue);
                return isNaN(numericValue) ? 0 : numericValue;
            }
            case 'date': {
                const dateValue = parseInt(rawValue, 10);
                return isNaN(dateValue) ? 0 : dateValue;
            }
            case 'status': {
                const statusValue = parseInt(rawValue, 10);
                return isNaN(statusValue) ? 0 : statusValue;
            }
            default:
                return (rawValue || '').toString().toLowerCase();
        }
    };

    const sortDiscountTable = (table, columnIndex, type, order) => {
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        const rows = Array.from(tbody.querySelectorAll('tr')).filter(row => row.querySelectorAll('td').length > 1);
        const multiplier = order === 'asc' ? 1 : -1;

        rows.sort((rowA, rowB) => {
            const cellA = rowA.children[columnIndex];
            const cellB = rowB.children[columnIndex];

            const valueA = getSortValue(cellA, type);
            const valueB = getSortValue(cellB, type);

            if (type === 'text') {
                return valueA.localeCompare(valueB) * multiplier;
            }

            if (valueA < valueB) return -1 * multiplier;
            if (valueA > valueB) return 1 * multiplier;
            return 0;
        });

        rows.forEach(row => tbody.appendChild(row));
    };

    headers.forEach((header, index) => {
        header.addEventListener('click', function() {
            const type = header.dataset.type || 'text';
            const currentOrder = header.dataset.order === 'asc' ? 'desc' : 'asc';

            headers.forEach(h => {
                h.dataset.order = '';
                h.classList.remove('active-asc', 'active-desc');
                const indicator = h.querySelector('.sort-indicator');
                if (indicator) indicator.textContent = '';
            });

            header.dataset.order = currentOrder;
            header.classList.add(currentOrder === 'asc' ? 'active-asc' : 'active-desc');
            const indicator = header.querySelector('.sort-indicator');
            if (indicator) indicator.textContent = currentOrder === 'asc' ? '▲' : '▼';

            sortDiscountTable(discountTable, index, type, currentOrder);
        });
    });
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

// Initialize discount type on page load if editing
document.addEventListener('DOMContentLoaded', function() {
    const discountType = document.getElementById('discount_type');
    if (discountType) {
        updateDiscountType();
    }
});

// Show edit discount confirmation modal
function showEditDiscountConfirm(discountId) {
    const modal = document.getElementById('editDiscountModal');
    if (modal) {
        modal._discountId = discountId;
        modal.style.display = 'flex';
    }
}

// Show delete discount confirmation modal
function showDeleteDiscountConfirm(discountId) {
    const modal = document.getElementById('deleteDiscountModal');
    if (modal) {
        modal._discountId = discountId;
        modal.style.display = 'flex';
    }
}

// Confirm edit discount
function confirmEditDiscount() {
    const modal = document.getElementById('editDiscountModal');
    if (modal && modal._discountId) {
        window.location.href = '?section=order&edit_discount=' + modal._discountId;
    }
}

// Confirm delete discount
function confirmDeleteDiscount() {
    const modal = document.getElementById('deleteDiscountModal');
    if (modal && modal._discountId) {
        window.location.href = '?section=order&delete_discount=' + modal._discountId;
    }
}

// Close modals
function closeEditDiscountModal() {
    const modal = document.getElementById('editDiscountModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

function closeDeleteDiscountModal() {
    const modal = document.getElementById('deleteDiscountModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

// Edit Functions
function editSiteSettings() {
    document.getElementById('siteSettingsView').style.display = 'none';
    document.getElementById('siteSettingsFields').style.display = 'block';
}

function cancelSiteSettingsEdit() {
    document.getElementById('siteSettingsFields').style.display = 'none';
    document.getElementById('siteSettingsView').style.display = 'block';
}

function confirmSiteSettingsSave() {
    // Show confirmation modal matching logout style
    if (!confirm('Are you sure you want to save these site settings?')) {
        return;
    }
    const form = document.getElementById('siteSettingsForm');
    if (form) {
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'update_site_settings';
        hiddenInput.value = '1';
        form.appendChild(hiddenInput);
        form.submit();
    }
}

function editPassword() {
    document.getElementById('passwordView').style.display = 'none';
    document.getElementById('passwordFields').style.display = 'block';
}

function cancelPasswordEdit() {
    document.getElementById('passwordFields').style.display = 'none';
    document.getElementById('passwordView').style.display = 'block';
    // Clear password fields
    document.getElementById('current_password').value = '';
    document.getElementById('new_password').value = '';
    document.getElementById('confirm_password').value = '';
}

function confirmPasswordChange() {
    const current = document.getElementById('current_password').value;
    const newPass = document.getElementById('new_password').value;
    const confirmPass = document.getElementById('confirm_password').value;
    
    if (!current || !newPass || !confirmPass) {
        alert('Please fill in all password fields.');
        return;
    }
    
    if (newPass !== confirmPass) {
        alert('New password and confirmation do not match.');
        return;
    }
    
    // Show confirmation modal
    if (!confirm('Are you sure you want to change your password/PIN?')) {
        return;
    }
    
    const form = document.querySelector('form[action=""]');
    if (form) {
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'change_password';
        hiddenInput.value = '1';
        form.appendChild(hiddenInput);
        form.submit();
    }
}

// Profile Edit Functions
function editProfile() {
    document.getElementById('profileView').style.display = 'none';
    document.getElementById('profileFields').style.display = 'block';
}

function cancelProfileEdit() {
    document.getElementById('profileFields').style.display = 'none';
    document.getElementById('profileView').style.display = 'block';
    // Reset form values
    location.reload();
}

function confirmProfileUpdate() {
    // Show confirmation modal
    if (!confirm('Are you sure you want to update your profile?')) {
        return;
    }
    
    const form = document.getElementById('profileForm');
    if (form) {
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'update_profile';
        hiddenInput.value = '1';
        form.appendChild(hiddenInput);
        form.submit();
    }
}

function editGracePeriod() {
    document.getElementById('gracePeriodView').style.display = 'none';
    document.getElementById('gracePeriodFields').style.display = 'block';
}

function cancelGracePeriodEdit() {
    document.getElementById('gracePeriodFields').style.display = 'none';
    document.getElementById('gracePeriodView').style.display = 'block';
    // Reset to original value
    const currentValue = <?php echo $currentGracePeriod; ?>;
    document.getElementById('grace_period').value = currentValue;
}

function confirmGracePeriodSave() {
    const gracePeriod = document.getElementById('grace_period').value;
    if (!gracePeriod || gracePeriod < 1 || gracePeriod > 60) {
        alert('Grace period must be between 1 and 60 minutes.');
        return;
    }
    
    if (!confirm('Are you sure you want to save the grace period setting?')) {
        return;
    }
    
    const form = document.querySelector('form[action=""]');
    if (form) {
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'update_settings';
        hiddenInput.value = '1';
        form.appendChild(hiddenInput);
        form.submit();
    }
}

// Authentication Modal Functions
function openAuthModal(section) {
    if (section === 'site') {
        const siteEmail = document.getElementById('site_email').value;
        const sitePhone = document.getElementById('site_phone').value;
        const siteAddress = document.getElementById('site_address').value;
        
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

<!-- Edit Discount Confirmation Modal -->
<div id="editDiscountModal" class="modal-overlay" style="display: none;">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fas fa-edit" style="color: #FFD736; margin-right: 8px;"></i>
                Edit Discount Code
            </h3>
            <button class="modal-close" onclick="closeEditDiscountModal()" aria-label="Close">×</button>
        </div>
        <div class="modal-body">
            <p style="margin: 0; font-size: 13px; line-height: 1.5; color: #130325;">Are you sure you want to edit this discount code? You will be redirected to the edit form.</p>
        </div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeEditDiscountModal()">Cancel</button>
            <button class="btn-primary-y" onclick="confirmEditDiscount()">Edit</button>
        </div>
    </div>
</div>

<!-- Delete Discount Confirmation Modal -->
<div id="deleteDiscountModal" class="modal-overlay" style="display: none;">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fas fa-trash" style="color: #FFD736; margin-right: 8px;"></i>
                Delete Discount Code
            </h3>
            <button class="modal-close" onclick="closeDeleteDiscountModal()" aria-label="Close">×</button>
        </div>
        <div class="modal-body">
            <p style="margin: 0; font-size: 13px; line-height: 1.5; color: #130325;">Are you sure you want to delete this discount code? This action cannot be undone.</p>
        </div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeDeleteDiscountModal()">Cancel</button>
            <button class="btn-primary-y" onclick="confirmDeleteDiscount()" style="background: #ef4444; color: #ffffff;">Delete</button>
        </div>
    </div>
</div>

<script>
// Close modals on overlay click
document.addEventListener('click', function(e) {
    const editModal = document.getElementById('editDiscountModal');
    const deleteModal = document.getElementById('deleteDiscountModal');
    
    if (editModal && e.target === editModal) {
        closeEditDiscountModal();
    }
    if (deleteModal && e.target === deleteModal) {
        closeDeleteDiscountModal();
    }
});

// Close modals on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEditDiscountModal();
        closeDeleteDiscountModal();
    }
});
</script>
