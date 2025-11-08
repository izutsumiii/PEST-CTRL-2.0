<?php
// Maintenance Mode Check
// Include this file at the top of every public-facing page (not admin pages)

if (!isset($pdo)) {
    require_once __DIR__ . '/../config/database.php';
}

// Check if user is admin or seller (both should bypass maintenance)
$isAdmin = false;
$isSeller = false;

if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['role'])) {
        $isAdmin = ($_SESSION['role'] === 'admin');
    }
    // Check user_type from session or database
    if (isset($_SESSION['user_type'])) {
        $isSeller = ($_SESSION['user_type'] === 'seller');
    } else {
        // Fallback: check database if user_type not in session
        try {
            if (isset($pdo)) {
                $stmt = $pdo->prepare("SELECT user_type FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $userType = $stmt->fetchColumn();
                $isSeller = ($userType === 'seller');
            }
        } catch (PDOException $e) {
            // Ignore database errors for this check
        }
    }
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
            // Set timezone to match your server/database timezone
            $timezone = new DateTimeZone('Asia/Manila'); // Change this to your timezone
            
            // Parse the format stored in database: Y-m-d H:i:s (e.g., 2024-11-06 14:30:00)
            $startDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $maintenanceStart, $timezone);
            $endDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $maintenanceEnd, $timezone);
            $currentDateTime = new DateTime('now', $timezone);
            
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

// Final check: Show maintenance page if maintenance is active and user is not admin or seller
if ($shouldShowMaintenance && !$isAdmin && !$isSeller) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Maintenance Mode - Site Under Maintenance</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Inconsolata:wght@400;700&display=swap" rel="stylesheet">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            html, body {
                height: 100%;
                background: #130325;
                color: #F9F9F9;
                font-family: 'Inconsolata', monospace;
                font-size: 100%;
                overflow-x: hidden;
            }

            .maintenance {
                text-transform: uppercase;
                margin-bottom: 1rem;
                font-size: 3rem;
                color: #FFD736;
                font-weight: 700;
            }

            .container {
                display: table;
                margin: 0 auto;
                max-width: 1024px;
                width: 100%;
                height: 100%;
                align-content: center;
                position: relative;
                box-sizing: border-box;
            }

            .what-is-up {
                position: absolute;
                width: 100%;
                top: 50%;
                transform: translateY(-50%);
                display: block;
                vertical-align: middle;
                text-align: center;
                box-sizing: border-box;
                padding: 20px;
            }

            .spinny-cogs {
                display: block;
                margin-bottom: 2rem;
            }

            .spinny-cogs .fa {
                color: #FFD736;
                margin: 0 10px;
            }

            .spinny-cogs .fa:nth-of-type(1) {
                -webkit-animation: fa-spin-one 1s infinite linear;
                animation: fa-spin-one 1s infinite linear;
            }

            .spinny-cogs .fa:nth-of-type(3) {
                -webkit-animation: fa-spin-two 2s infinite linear;
                animation: fa-spin-two 2s infinite linear;
            }

            .what-is-up h2 {
                font-size: 1.2rem;
                color: rgba(255, 255, 255, 0.9);
                line-height: 1.6;
                max-width: 600px;
                margin: 0 auto 2rem;
            }

            .maintenance-message {
                background: rgba(255, 215, 54, 0.1);
                border: 2px solid #FFD736;
                padding: 20px;
                border-radius: 8px;
                margin: 2rem auto;
                max-width: 600px;
                text-align: left;
            }

            .maintenance-message p {
                margin: 0;
                color: #ffffff;
                word-wrap: break-word;
                overflow-wrap: break-word;
                font-size: 1rem;
            }

            @-webkit-keyframes fa-spin-one {
                0% {
                    -webkit-transform: translateY(-2rem) rotate(0deg);
                    transform: translateY(-2rem) rotate(0deg);
                }
                100% {
                    -webkit-transform: translateY(-2rem) rotate(-359deg);
                    transform: translateY(-2rem) rotate(-359deg);
                }
            }

            @keyframes fa-spin-one {
                0% {
                    -webkit-transform: translateY(-2rem) rotate(0deg);
                    transform: translateY(-2rem) rotate(0deg);
                }
                100% {
                    -webkit-transform: translateY(-2rem) rotate(-359deg);
                    transform: translateY(-2rem) rotate(-359deg);
                }
            }

            @-webkit-keyframes fa-spin-two {
                0% {
                    -webkit-transform: translateY(0.5rem) rotate(0deg);
                    transform: translateY(0.5rem) rotate(0deg);
                }
                100% {
                    -webkit-transform: translateY(0.5rem) rotate(-359deg);
                    transform: translateY(0.5rem) rotate(-359deg);
                }
            }

            @keyframes fa-spin-two {
                0% {
                    -webkit-transform: translateY(0.5rem) rotate(0deg);
                    transform: translateY(0.5rem) rotate(0deg);
                }
                100% {
                    -webkit-transform: translateY(0.5rem) rotate(-359deg);
                    transform: translateY(0.5rem) rotate(-359deg);
                }
            }

            /* Responsive */
            @media (max-width: 768px) {
                .maintenance {
                    font-size: 2rem;
                }

                .what-is-up h2 {
                    font-size: 1rem;
                }

                .spinny-cogs .fa {
                    font-size: 2rem !important;
                }
            }

            @media (max-width: 576px) {
                .maintenance {
                    font-size: 1.5rem;
                }

                .what-is-up h2 {
                    font-size: 0.9rem;
                }

                .spinny-cogs .fa {
                    font-size: 1.5rem !important;
                    margin: 0 5px;
                }

                .maintenance-message {
                    padding: 15px;
                }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="what-is-up">
                <div class="spinny-cogs">
                    <i class="fa fa-cog" aria-hidden="true"></i>
                    <i class="fa fa-5x fa-cog fa-spin" aria-hidden="true"></i>
                    <i class="fa fa-3x fa-cog" aria-hidden="true"></i>
                </div>
                <h1 class="maintenance">Under Maintenance</h1>
                <h2>Our developers are hard at work updating your system. Please wait while we do this. We have also made the spinning cogs to distract you.</h2>
                <div class="maintenance-message">
                    <p><?php echo htmlspecialchars($maintenanceMessage); ?></p>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>
