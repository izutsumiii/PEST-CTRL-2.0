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
        // Ensure site_settings table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT NOT NULL,
            updated_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
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
        
        $success = "Maintenance settings updated successfully!";
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





    <!-- Maintenance Mode Settings Card -->
    <form method="POST" action="">
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
            
            <button type="submit" name="toggle_maintenance" class="btn-save">
                <i class="fas fa-save"></i> Save Maintenance Settings
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


    // Auto-refresh settings to check scheduled maintenance status
    if (document.getElementById('maintenance_auto_enable')) {
    setInterval(function() {
        // Only refresh if scheduled maintenance is enabled
        const autoEnableCheckbox = document.getElementById('maintenance_auto_enable');
        if (autoEnableCheckbox && autoEnableCheckbox.checked) {
            // Silent check without full page reload
            fetch(window.location.href)
                .then(response => response.text())
                .then(html => {
                    // Parse the response to check current status
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const statusBadge = doc.querySelector('.status-badge');
                    const currentStatusBadge = document.querySelector('.status-badge');
                    
                    // Update status badge if it changed
                    if (statusBadge && currentStatusBadge && 
                        statusBadge.className !== currentStatusBadge.className) {
                        location.reload();
                    }
                });
        }
    }, 30000); // Check every 30 seconds
}
</script>
