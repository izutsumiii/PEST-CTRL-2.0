<?php
// Maintenance Mode Check
// Include this file at the top of every public-facing page (not admin pages)

if (!isset($pdo)) {
    require_once __DIR__ . '/../config/database.php';
}

// Check if user is admin
$isAdmin = false;

if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    $isAdmin = ($_SESSION['role'] === 'admin');
}

$maintenanceMode = '0';
$maintenanceMessage = 'We are currently performing scheduled maintenance. Please check back soon!';
$maintenanceStart = '';
$maintenanceEnd = '';
$maintenanceAuto = '0';
$shouldShowMaintenance = false;

try {
    // Get all maintenance settings in one query
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings 
                           WHERE setting_key IN ('maintenance_mode', 'maintenance_message', 'maintenance_start', 'maintenance_end', 'maintenance_auto_enable')");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $maintenanceMode = isset($settings['maintenance_mode']) ? $settings['maintenance_mode'] : '0';
    $maintenanceMessage = isset($settings['maintenance_message']) ? $settings['maintenance_message'] : 'We are currently performing scheduled maintenance. Please check back soon!';
    $maintenanceStart = isset($settings['maintenance_start']) ? $settings['maintenance_start'] : '';
    $maintenanceEnd = isset($settings['maintenance_end']) ? $settings['maintenance_end'] : '';
    $maintenanceAuto = isset($settings['maintenance_auto_enable']) ? $settings['maintenance_auto_enable'] : '0';

    // Check scheduled maintenance ONLY if auto-enable is turned on
    if ($maintenanceAuto === '1' && !empty($maintenanceStart) && !empty($maintenanceEnd)) {
        try {
            // Parse the format stored in database: Y-m-d H:i:s (e.g., 2024-11-06 14:30:00)
            $startDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $maintenanceStart);
            $endDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $maintenanceEnd);
            $currentDateTime = new DateTime();
            
            // Validate that times were parsed correctly
            if ($startDateTime && $endDateTime) {
                // Check if current time is within maintenance window
                if ($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime) {
                    $shouldShowMaintenance = true;
                }
            }
        } catch (Exception $e) {
            // If there's an error parsing dates, log it but don't break the site
            error_log("Maintenance check error: " . $e->getMessage());
        }
    }

    // Check manual maintenance mode (takes priority)
    if ($maintenanceMode === '1') {
        $shouldShowMaintenance = true;
    }

} catch (PDOException $e) {
    // Database error - log but don't break the site
    error_log("Maintenance check database error: " . $e->getMessage());
}

// Final check: Show maintenance page if maintenance is active and user is not admin
if ($shouldShowMaintenance && !$isAdmin) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Maintenance Mode - Site Under Maintenance</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Inter', 'Segoe UI', sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            .maintenance-container {
                background: #ffffff;
                border-radius: 20px;
                padding: 60px 40px;
                max-width: 600px;
                width: 100%;
                text-align: center;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                animation: fadeInUp 0.6s ease-out;
            }

            @keyframes fadeInUp {
                from {
                    opacity: 0;
                    transform: translateY(30px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .maintenance-icon {
                font-size: 80px;
                color: #667eea;
                margin-bottom: 30px;
                animation: toolRotate 3s ease-in-out infinite;
            }

            @keyframes toolRotate {
                0%, 100% { transform: rotate(-15deg); }
                50% { transform: rotate(15deg); }
            }

            .maintenance-container h1 {
                font-size: 32px;
                color: #130325;
                margin-bottom: 20px;
                font-weight: 700;
            }

            .maintenance-container p {
                font-size: 16px;
                color: #6b7280;
                line-height: 1.6;
                margin-bottom: 30px;
            }

            .maintenance-message {
                background: #f3f4f6;
                border-left: 4px solid #667eea;
                padding: 20px;
                border-radius: 8px;
                margin: 30px 0;
                text-align: left;
            }

            .maintenance-message p {
                margin: 0;
                color: #130325;
            }

            .loader {
                display: inline-block;
                width: 50px;
                height: 50px;
                border: 5px solid #f3f4f6;
                border-top: 5px solid #667eea;
                border-radius: 50%;
                animation: spin 1s linear infinite;
                margin-top: 20px;
            }

            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }

            .social-links {
                margin-top: 40px;
                display: flex;
                gap: 15px;
                justify-content: center;
            }

            .social-links a {
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background: #f3f4f6;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #667eea;
                text-decoration: none;
                transition: all 0.3s ease;
            }

            .social-links a:hover {
                background: #667eea;
                color: #ffffff;
                transform: translateY(-3px);
            }

            .back-soon {
                font-size: 14px;
                color: #9ca3af;
                margin-top: 30px;
                font-style: italic;
            }

            @media (max-width: 768px) {
                .maintenance-container {
                    padding: 40px 30px;
                }

                .maintenance-container h1 {
                    font-size: 24px;
                }

                .maintenance-icon {
                    font-size: 60px;
                }
            }
        </style>
    </head>
    <body>
        <div class="maintenance-container">
            <div class="maintenance-icon">
                <i class="fas fa-tools"></i>
            </div>
            
            <h1>We'll Be Back Soon!</h1>
            
            <p>Our website is currently undergoing scheduled maintenance to improve your experience.</p>
            
            <div class="maintenance-message">
                <p><?php echo htmlspecialchars($maintenanceMessage); ?></p>
            </div>
            
            <div class="loader"></div>
            
            <p class="back-soon">Thank you for your patience. We'll be back online shortly.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>
