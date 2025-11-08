<?php
require_once 'includes/header.php';
require_once 'config/database.php';

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'PHPMailer/PHPMailer/src/Exception.php';
require 'PHPMailer/PHPMailer/src/PHPMailer.php';
require 'PHPMailer/PHPMailer/src/SMTP.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Ensure secure password_resets schema exists (user_id, token_hash, expires_at)
function ensureSecurePasswordResetSchema(PDO $pdo) {
    try {
        // Check if table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'password_resets'");
        $exists = $stmt->fetch();

        if (!$exists) {
            // Create table WITHOUT foreign key initially
            $createSql = "
                CREATE TABLE password_resets (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    token_hash VARCHAR(64) NOT NULL,
                    expires_at DATETIME NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    used_at TIMESTAMP NULL DEFAULT NULL,
                    INDEX idx_user_id (user_id),
                    INDEX idx_token_hash (token_hash),
                    INDEX idx_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";
            $pdo->exec($createSql);
            
            // Try to add foreign key constraint separately (if it fails, table still works)
            try {
                $pdo->exec("
                    ALTER TABLE password_resets 
                    ADD CONSTRAINT fk_password_resets_user 
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ");
            } catch (Exception $fkError) {
                error_log('Foreign key creation failed (non-critical): ' . $fkError->getMessage());
            }
            return;
        }

        // Table exists - check if it has the correct schema
        $colsStmt = $pdo->query("DESCRIBE password_resets");
        $columns = $colsStmt->fetchAll(PDO::FETCH_COLUMN);

        $hasUserId = in_array('user_id', $columns, true);
        $hasTokenHash = in_array('token_hash', $columns, true);
        $hasExpiresAt = in_array('expires_at', $columns, true);

        // Check for legacy columns
        $isLegacy = in_array('email', $columns, true) || 
                    in_array('otp', $columns, true) || 
                    in_array('token', $columns, true);

        // If schema is incorrect, recreate table
        if (!$hasUserId || !$hasTokenHash || !$hasExpiresAt || $isLegacy) {
            // Drop foreign key constraints first if they exist
            try {
                $pdo->exec("ALTER TABLE password_resets DROP FOREIGN KEY fk_password_resets_user");
            } catch (Exception $e) {
                // Constraint might not exist, ignore
            }
            
            // Drop the table
            $pdo->exec("DROP TABLE IF EXISTS password_resets");
            
            // Recreate with correct schema (without FK initially)
            $createSql = "
                CREATE TABLE password_resets (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    token_hash VARCHAR(64) NOT NULL,
                    expires_at DATETIME NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    used_at TIMESTAMP NULL DEFAULT NULL,
                    INDEX idx_user_id (user_id),
                    INDEX idx_token_hash (token_hash),
                    INDEX idx_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";
            $pdo->exec($createSql);
            
            // Try to add FK constraint
            try {
                $pdo->exec("
                    ALTER TABLE password_resets 
                    ADD CONSTRAINT fk_password_resets_user 
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ");
            } catch (Exception $fkError) {
                error_log('Foreign key creation failed (non-critical): ' . $fkError->getMessage());
            }
        }
    } catch (Exception $e) {
        error_log('ensureSecurePasswordResetSchema error: ' . $e->getMessage());
        // Don't throw - let the page continue
    }
}

// Add sanitizeInput function if not exists
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($input) {
        return htmlspecialchars(strip_tags(trim($input)));
    }
}

/**
 * Generate a secure password reset token
 * @return string 64+ character secure token
 */
function generateSecureToken() {
    return bin2hex(random_bytes(32)); // 64 character token
}

/**
 * Send password reset email with secure link
 * @param string $email User's email
 * @param string $token Secure reset token
 * @param string $name User's name
 * @return bool Success status
 */
function sendPasswordResetEmail($email, $token, $name) {
    $resetLink = "http://localhost/GITHUB_PEST-CTRL/reset-password-secure.php?token=" . urlencode($token);
    $lastError = '';

    // Helper to configure message common fields
    $configureMessage = function(PHPMailer $m) use ($email, $name, $resetLink) {
        $m->setFrom('jhongujol1299@gmail.com', 'PEST-CTRL');
        $m->addAddress($email, $name);
        $m->addReplyTo('jhongujol1299@gmail.com', 'PEST-CTRL Support');
        $m->isHTML(true);
        $m->CharSet = 'UTF-8';
        $m->Subject = '🔐 Password Reset Request - PEST-CTRL';
        $m->Body = "
        <div style='font-family: Inter, Segoe UI, Arial, sans-serif; max-width: 620px; margin: 0 auto; padding: 0; background: #130325;'>
            <div style='padding: 24px 20px; border-bottom: 2px solid #FFD736; text-align: center;'>
                <h1 style='margin: 0; font-size: 22px; letter-spacing: 0.5px; color: #FFD736;'>PEST-CTRL</h1>
                <div style='margin-top: 3px; font-size: 13px; color: rgba(255,255,255,0.85);'>Password Reset Request</div>
            </div>
            <div style='padding: 26px 20px 8px 20px; background: #1a0a2e;'>
                <h2 style='margin: 0 0 14px 0; color: #FFFFFF; font-size: 18px;'>Hello {$name},</h2>
                <p style='margin: 0 0 14px 0; color: rgba(255,255,255,0.88); font-size: 15px; line-height: 1.6;'>
                    You requested to reset your password for your PEST-CTRL account. Click the button below to create a new password.
                </p>
                <div style='text-align: center; margin: 26px 0 18px;'>
                    <a href='{$resetLink}' style='display: inline-block; background: linear-gradient(135deg, #FFD736, #E6C230); color: #130325; padding: 14px 26px; text-decoration: none; border-radius: 10px; font-weight: 700; font-size: 15px;'>
                        🔐 Reset My Password
                    </a>
                </div>
                <div style='background: rgba(255,215,54,0.12); border: 1px solid rgba(255,215,54,0.45); padding: 12px; border-radius: 10px; margin: 12px 0 18px;'>
                    <p style='margin: 0; color: #FFD736; font-size: 13px;'>
                        <strong style='color:#FFD736;'>Important:</strong> This link expires in 1 hour. If you didn't request a reset, you can safely ignore this email.
                    </p>
                </div>
                <p style='margin: 0; color: rgba(255,255,255,0.8); font-size: 13px; line-height: 1.6;'>
                    If the button doesn't work, paste this link into your browser:<br>
                    <a href='{$resetLink}' style='color: #FFD736; word-break: break-all; text-decoration: none;'>{$resetLink}</a>
                </p>
            </div>
            <div style='text-align: center; border-top: 1px solid rgba(255,255,255,0.08); padding: 18px 12px 24px; color: rgba(255,255,255,0.7); font-size: 12px; background: #130325;'>
                <p style='margin: 0 0 6px 0;'>Best regards,<br>PEST-CTRL Team</p>
                <p style='margin: 0;'>© 2024 PEST-CTRL</p>
            </div>
        </div>";
        $m->AltBody = "Hello {$name},\n\nVisit this link to reset your PEST-CTRL password: {$resetLink}\n\nThis link expires in 1 hour.";
    };
 
    // Attempt 1: SSL 465
    try {
        $mail = new PHPMailer(true);
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'jhongujol1299@gmail.com';
        $mail->Password = 'ljdo ohkv pehx idkv';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // SSL
        $mail->Port = 465;
        $mail->SMTPOptions = [ 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true] ];
        $configureMessage($mail);
        if ($mail->send()) return true;
        $lastError = $mail->ErrorInfo;
    } catch (\Throwable $e) { $lastError = $e->getMessage(); }

    // Attempt 2: TLS 587
    try {
        $mail = new PHPMailer(true);
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'jhongujol1299@gmail.com';
        $mail->Password = 'ljdo ohkv pehx idkv';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // TLS
        $mail->Port = 587;
        $mail->SMTPOptions = [ 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true] ];
        $configureMessage($mail);
        if ($mail->send()) return true;
        $lastError = $mail->ErrorInfo;
    } catch (\Throwable $e) { $lastError = $e->getMessage(); }

    // Attempt 3: PHP mail()
    try {
        $mail = new PHPMailer(true);
        $mail->isMail();
        $configureMessage($mail);
        if ($mail->send()) return true;
        $lastError = $mail->ErrorInfo;
    } catch (\Throwable $e) { $lastError = $e->getMessage(); }

    $lastMailerError = $lastError;
    error_log('Password reset email failed: ' . $lastError);
    return false;
}

$success = '';
$error = '';
$lastMailerError = '';

// Handle password reset request
if (isset($_POST['request_reset'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid form submission. Please try again.";
    } else {
        // Ensure the password_resets table matches the secure schema before writing
        ensureSecurePasswordResetSchema($pdo);

        $email = sanitizeInput($_POST['email']);
        
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            // Check if email exists in database
            $stmt = $pdo->prepare("SELECT id, email, first_name, last_name FROM users WHERE email = ? AND is_active = 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Clean up any existing reset tokens for this user
                $stmt = $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?");
                $stmt->execute([$user['id']]);

                // Clean up expired tokens globally
                $pdo->exec("DELETE FROM password_resets WHERE expires_at < NOW()");
                
                // Generate secure token
                $token = generateSecureToken();
                // Hash the token for storage
                $tokenHash = hash('sha256', $token);
                
                // Set expiration time (1 hour from now)
                $stmt = $pdo->query("SELECT DATE_ADD(NOW(), INTERVAL 1 HOUR) as expiry");
                $expiryResult = $stmt->fetch(PDO::FETCH_ASSOC);
                $expiry = $expiryResult['expiry'];
                
                // Store token hash with user_id in database
                $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
                $result = $stmt->execute([$user['id'], $tokenHash, $expiry]);
                
                if ($result) {
                    // Send password reset email
                    $name = trim($user['first_name'] . ' ' . $user['last_name']);
                    $emailSent = sendPasswordResetEmail($email, $token, $name);
                    
                    if ($emailSent) {
                        $success = "Password reset link has been sent to your email address! Please check your inbox.";
                        error_log("Reset token generated for user ID {$user['id']}: Token hash stored in database");
                    } else {
                        $error = "Failed to send email. Please try again later or contact support.";
                        error_log("Email sending failed for user: {$email}");
                    }
                } else {
                    $error = "Database error occurred. Please try again.";
                    error_log("Database insert failed for password reset");
                }
            } else {
                // Don't reveal if email exists or not (security best practice)
                $success = "If that email address exists in our system, you will receive a password reset link shortly.";
                error_log("Password reset requested for non-existent email: {$email}");
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - PEST-CTRL</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, var(--primary-dark) 0%, rgba(19, 3, 37, 0.95) 100%);
            min-height: 100vh;
            padding: 0;
            position: relative;
            overflow-x: hidden;
        }

        .forgot-password-container {
            background: var(--primary-dark);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border-secondary);
            padding: 40px 30px;
            border-radius: 24px;
            box-shadow: 0 20px 40px var(--shadow-light);
            max-width: 480px;
            width: 100%;
            text-align: center;
            position: relative;
            overflow: hidden;
            margin: 80px auto 24px;
            color: var(--primary-light);
        }

        .forgot-password-container h1 {
            color: var(--accent-yellow);
            font-size: 1.8rem;
            margin-bottom: 10px;
            font-weight: 700;
        }

        .forgot-password-container p {
            color: rgba(249, 249, 249, 0.8);
            margin-bottom: 30px;
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 24px;
            text-align: left;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--primary-light);
            font-size: 14px;
        }

        .form-group input[type="email"] {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--border-secondary);
            border-radius: 12px;
            font-size: 15px;
            background: var(--primary-light);
            color: var(--primary-dark);
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
        }

        .form-group input[type="email"]:focus {
            outline: none;
            border-color: var(--accent-yellow);
            box-shadow: 0 0 0 3px rgba(255, 215, 54, 0.15);
        }

        .btn-primary {
            width: 100%;
            padding: 14px;
            background: var(--accent-yellow);
            color: var(--primary-dark);
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 215, 54, 0.4);
            background: #e6c230;
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .success-message, .error-message {
            padding: 18px;
            border-radius: 12px;
            margin-bottom: 24px;
            text-align: left;
            font-weight: 500;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 14px;
            line-height: 1.6;
        }

        .success-message {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
            border: 2px solid #28a745;
        }

        .success-message i {
            color: #28a745;
            font-size: 18px;
            margin-top: 2px;
        }

        .error-message {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
            border: 2px solid #dc3545;
        }

        .error-message i {
            color: #dc3545;
            font-size: 18px;
            margin-top: 2px;
        }

        .links {
            text-align: center;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .links a {
            color: var(--accent-yellow);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            transition: color 0.3s ease;
        }

        .links a:hover {
            color: #e6c230;
            text-decoration: underline;
        }

        .links p {
            text-decoration: none;
            font-weight: 400;
            font-size: 0.95rem;
            color: rgba(249, 249, 249, 0.8);
            margin: 8px 0;
            line-height: 1.4;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .forgot-password-container {
                margin: 100px auto 24px;
                padding: 30px 20px;
            }

            .forgot-password-container h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="forgot-password-container">
        <h1><i class="fas fa-lock"></i> Forgot Password</h1>
        <p>Enter your email address and we'll send you a link to reset your password.</p>

        <?php if ($success): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                <div><?php echo $success; ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <?php echo $error; ?>
                    <?php if (!empty($lastMailerError)): ?>
                        <div style="margin-top:6px; font-size:12px; color:#f8d7da; opacity:0.9;">
                            Mailer error: <?php echo htmlspecialchars($lastMailerError); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="forgot-form">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="form-group">
                <label for="email">
                    <i class="fas fa-envelope"></i> Email Address
                </label>
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                    placeholder="Enter your email address"
                    required 
                    autocomplete="email">
            </div>
            
            <button type="submit" name="request_reset" id="submit-btn" class="btn-primary">
                <i class="fas fa-paper-plane"></i> Send Reset Link
            </button>
        </form>

        <div class="links">
            <p>Remember your password? <a href="login_customer.php">Login here</a></p>
            <p>Need an account? <a href="register.php">Register here</a></p>
        </div>
    </div>

    <script>
        // Auto-focus email input
        document.getElementById('email').focus();
    </script>
</body>
</html>

<?php require_once 'includes/footer.php'; ?>