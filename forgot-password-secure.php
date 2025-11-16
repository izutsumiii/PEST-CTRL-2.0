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

<style>
    :root {
        --primary-dark: #130325;
        --accent-yellow: #FFD736;
        --text-dark: #1a1a1a;
        --text-light: #6b7280;
        --border-light: #e5e7eb;
        --bg-light: #f9fafb;
        --bg-white: #ffffff;
        --success-green: #10b981;
        --error-red: #ef4444;
    }

    /* Override body background */
    body {
        background: linear-gradient(135deg, var(--primary-dark) 0%, rgba(19, 3, 37, 0.95) 50%, #0a0118 100%);
        min-height: 100vh;
        margin: 0;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }

    /* Main wrapper */
    .forgot-password-wrapper {
        display: flex;
        justify-content: center;
        align-items: flex-start;
        min-height: calc(100vh - 80px);
        padding: 40px 20px;
    }

    /* Forgot Password Container */
    .forgot-password-container {
        width: 100%;
        max-width: 420px;
        padding: 24px 20px;
        border-radius: 12px;
        background: var(--bg-white);
        color: var(--text-dark);
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        border: 1px solid var(--border-light);
        position: relative;
        overflow: hidden;
    }

    .forgot-password-header {
        text-align: center;
        margin-bottom: 20px;
    }

    .forgot-password-header h1 {
        color: var(--text-dark);
        font-size: 1.35rem;
        font-weight: 600;
        margin-bottom: 6px;
        letter-spacing: -0.3px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .forgot-password-header h1 i {
        color: var(--primary-dark);
        font-size: 1.2rem;
    }

    .forgot-password-header .subtitle {
        font-size: 12px;
        color: var(--text-light);
        line-height: 1.4;
    }

    .form-group {
        margin-bottom: 12px;
    }

    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
        color: #130325;
        font-size: 12px;
    }

    .form-group input[type="email"] {
        width: 100%;
        padding: 10px 14px;
        border: 1.5px solid var(--primary-dark);
        border-radius: 8px;
        font-size: 13px;
        background: var(--bg-white);
        color: var(--text-dark);
        box-sizing: border-box;
        transition: all 0.2s ease;
    }

    .form-group input:focus {
        outline: none;
        background: var(--bg-white);
        border-color: var(--primary-dark);
        box-shadow: 0 0 0 3px rgba(19, 3, 37, 0.1);
        border-width: 2px;
    }

    .form-group input::placeholder {
        color: rgba(19, 3, 37, 0.5);
    }

    .forgot-password-container button[type="submit"] {
        width: 100%;
        padding: 10px 16px;
        background-color: var(--primary-dark);
        color: var(--bg-white);
        border: 1.5px solid var(--primary-dark);
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        text-align: center;
        transition: all 0.2s ease;
        margin-top: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .forgot-password-container button[type="submit"]:hover:not(:disabled) {
        background-color: #0a0118;
        color: var(--bg-white);
        border-color: #0a0118;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(19, 3, 37, 0.15);
    }

    .forgot-password-container button[type="submit"]:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .success-message, .error-message {
        padding: 12px 14px;
        border-radius: 8px;
        margin-bottom: 16px;
        text-align: left;
        font-weight: 500;
        display: flex;
        align-items: flex-start;
        gap: 10px;
        font-size: 12px;
        line-height: 1.5;
    }

    .success-message {
        background: rgba(16, 185, 129, 0.1);
        color: var(--success-green);
        border: 1.5px solid var(--success-green);
    }

    .success-message i {
        color: var(--success-green);
        font-size: 16px;
        margin-top: 1px;
        flex-shrink: 0;
    }

    .error-message {
        background: rgba(239, 68, 68, 0.1);
        color: var(--error-red);
        border: 1.5px solid var(--error-red);
    }

    .error-message i {
        color: var(--error-red);
        font-size: 16px;
        margin-top: 1px;
        flex-shrink: 0;
    }

    .error-details {
        margin-top: 6px;
        font-size: 11px;
        color: rgba(239, 68, 68, 0.8);
        opacity: 0.9;
    }

    .forgot-links {
        text-align: center;
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px solid rgba(19, 3, 37, 0.1);
    }

    .forgot-links p {
        font-size: 11px;
        color: #130325;
        margin: 4px 0;
    }

    .forgot-links a {
        color: #130325;
        text-decoration: none;
        font-weight: 600;
    }

    .forgot-links a:hover {
        color: #130325;
        text-decoration: underline;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .forgot-password-wrapper {
            padding: 30px 16px;
        }

        .forgot-password-container {
            padding: 20px 16px;
            max-width: 100%;
        }

        .forgot-password-header h1 {
            font-size: 1.2rem;
        }

        .forgot-password-header .subtitle {
            font-size: 11px;
        }
    }

    @media (max-width: 480px) {
        .forgot-password-wrapper {
            padding: 20px 12px;
        }

        .forgot-password-container {
            padding: 18px 14px;
        }

        .form-group input[type="email"] {
            padding: 9px 12px;
            font-size: 12px;
        }

        .forgot-password-container button[type="submit"] {
            padding: 9px 14px;
            font-size: 12px;
        }
    }
</style>

<div class="forgot-password-wrapper">
    <div class="forgot-password-container">
        <div class="forgot-password-header">
            <h1><i class="fas fa-lock"></i> Forgot Password</h1>
            <p class="subtitle">Enter your email address and we'll send you a link to reset your password.</p>
        </div>

        <?php if ($success): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                <div><?php echo htmlspecialchars($success); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <?php echo htmlspecialchars($error); ?>
                    <?php if (!empty($lastMailerError)): ?>
                        <div class="error-details">
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

        <div class="forgot-links">
            <p>Remember your password? <a href="login_customer.php">Login here</a></p>
            <p>Need an account? <a href="register.php">Register here</a></p>
        </div>
    </div>
</div>

<script>
    // Auto-focus email input
    document.addEventListener('DOMContentLoaded', function() {
        const emailInput = document.getElementById('email');
        if (emailInput) {
            emailInput.focus();
        }

        // Form submission handler
        const form = document.getElementById('forgot-form');
        const submitBtn = document.getElementById('submit-btn');
        
        if (form && submitBtn) {
            form.addEventListener('submit', function(e) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            });
        }
    });
</script>

<?php require_once 'includes/footer.php'; ?>
