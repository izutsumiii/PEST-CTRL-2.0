<?php
// Start output buffering to prevent header errors
if (!ob_get_level()) {
    ob_start();
}

require_once 'config/database.php';
require_once 'includes/functions.php';

if (isLoggedIn()) {
    header("Location: index.php");
    exit();
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Add sanitizeInput function if not exists
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($input) {
        return htmlspecialchars(strip_tags(trim($input)));
    }
}

$token = isset($_GET['token']) ? sanitizeInput($_GET['token']) : '';
$error = '';
$success = '';
$validToken = false;
$userEmail = '';

// Validate token if provided
if ($token) {
    if (strlen($token) !== 64) {
        $error = "Invalid reset link. Please request a new password reset.";
    } else {
        // Check if token exists and is valid using token_hash
        $tokenHash = hash('sha256', $token);
        $stmt = $pdo->prepare("
            SELECT pr.* 
            FROM password_resets pr 
            WHERE pr.token_hash = ? 
            AND pr.expires_at > NOW()
            AND pr.used_at IS NULL
        ");
        $stmt->execute([$tokenHash]);
        $resetRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($resetRecord) {
            // Get user's email by joining users via user_id
            $validToken = true;
            $_SESSION['reset_user_id'] = $resetRecord['user_id'];
            $_SESSION['reset_token_hash'] = $tokenHash;
            
            $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
            $stmt->execute([$resetRecord['user_id']]);
            $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $userEmail = $userRow ? $userRow['email'] : '';
        } else {
            $error = "Invalid or expired reset link. Please request a new password reset.";
        }
    }
} else {
    $error = "No reset token provided. Please use the link from your email.";
}

// Handle password reset
if (isset($_POST['reset_password']) && $validToken) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid form submission. Please try again.";
    } else {
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validate passwords
        if (empty($password)) {
            $error = "Password is required.";
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters long.";
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $password)) {
            $error = "Password must contain at least one lowercase letter, one uppercase letter, and one number.";
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";
        } else {
            // Verify token is still valid using user_id and token_hash
            $stmt = $pdo->prepare("
                SELECT * FROM password_resets 
                WHERE user_id = ? AND token_hash = ? AND expires_at > NOW() AND used_at IS NULL
            ");
            $stmt->execute([$_SESSION['reset_user_id'], $_SESSION['reset_token_hash']]);
            $resetRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($resetRecord) {
                // Hash the new password
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                
                // Start transaction
                $pdo->beginTransaction();
                
                try {
                    // Update user's password via user_id
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $updateResult = $stmt->execute([$hashedPassword, $_SESSION['reset_user_id']]);
                    
                    // Mark token as used
                    $stmt = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?");
                    $deleteResult = $stmt->execute([$resetRecord['id']]);
                    
                    // Delete all other reset tokens for this user
                    $stmt = $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?");
                    $cleanupResult = $stmt->execute([$_SESSION['reset_user_id']]);
                    
                    // Commit transaction
                    $pdo->commit();
                    
                    // Log successful password reset
                    error_log("Password reset successful for user_id: " . $_SESSION['reset_user_id']);
                    
                    // Clear session variables
                    unset($_SESSION['reset_user_id']);
                    unset($_SESSION['reset_token_hash']);
                    
                    // Clear output buffer before redirect (CRITICAL: must be before any HTML output)
                    if (ob_get_level()) {
                        ob_end_clean();
                    }
                    
                    // Redirect to customer login immediately (before any HTML output)
                    header("Location: login_customer.php?reset=success");
                    exit();
                
                } catch (Exception $e) {
                    // Rollback transaction
                    $pdo->rollback();
                    $error = "Failed to update password. Please try again.";
                    error_log("Password reset failed: " . $e->getMessage());
                }
            } else {
                $error = "Reset token has expired or been used. Please request a new password reset.";
            }
        }
    }
}

// Only include header if we're not redirecting
// This prevents "headers already sent" errors
if (!isset($password_reset_success) || !$password_reset_success) {
    require_once 'includes/header.php';
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
    .reset-password-wrapper {
        display: flex;
        justify-content: center;
        align-items: flex-start;
        min-height: calc(100vh - 80px);
        padding: 40px 20px;
    }

    /* Reset Password Container */
    .reset-password-container {
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

    .reset-password-header {
        text-align: center;
        margin-bottom: 20px;
    }

    .reset-password-header h1 {
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

    .reset-password-header h1 i {
        color: var(--primary-dark);
        font-size: 1.2rem;
    }

    .reset-password-header .subtitle {
        font-size: 12px;
        color: var(--text-light);
        line-height: 1.4;
    }

    .email-display {
        background: rgba(19, 3, 37, 0.05);
        border: 1.5px solid var(--primary-dark);
        border-radius: 8px;
        padding: 10px 14px;
        margin-bottom: 16px;
        text-align: center;
        color: var(--text-dark);
        font-size: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }

    .email-display i {
        color: var(--primary-dark);
        font-size: 14px;
    }

    .email-display strong {
        color: var(--primary-dark);
        font-weight: 600;
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

    .password-input-container {
        position: relative;
        display: flex;
        align-items: center;
    }

    .form-group input[type="password"],
    .form-group input[type="text"] {
        width: 100%;
        padding: 10px 14px;
        padding-right: 40px;
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

    .toggle-password {
        position: absolute;
        right: 8px;
        background: none;
        border: none;
        cursor: pointer;
        padding: 3px;
        color: #130325;
        opacity: 0.6;
        transition: opacity 0.3s ease;
        font-size: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .toggle-password:hover {
        opacity: 1;
    }

    .form-group small {
        display: block;
        margin-top: 4px;
        color: var(--text-light);
        font-size: 11px;
        line-height: 1.4;
    }

    .reset-password-container button[type="submit"] {
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

    .reset-password-container button[type="submit"]:hover:not(:disabled) {
        background-color: #0a0118;
        color: var(--bg-white);
        border-color: #0a0118;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(19, 3, 37, 0.15);
    }

    .reset-password-container button[type="submit"]:disabled {
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

    .reset-links {
        text-align: center;
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px solid rgba(19, 3, 37, 0.1);
    }

    .reset-links p {
        font-size: 11px;
        color: #130325;
        margin: 4px 0;
    }

    .reset-links a {
        color: #130325;
        text-decoration: none;
        font-weight: 600;
    }

    .reset-links a:hover {
        color: #130325;
        text-decoration: underline;
    }

    /* Password strength indicator */
    .password-strength {
        margin-top: 6px;
        font-size: 11px;
        display: flex;
        gap: 4px;
        align-items: center;
    }

    .password-strength-item {
        flex: 1;
        height: 3px;
        background: var(--border-light);
        border-radius: 2px;
        transition: all 0.3s ease;
    }

    .password-strength-item.valid {
        background: var(--success-green);
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .reset-password-wrapper {
            padding: 30px 16px;
        }

        .reset-password-container {
            padding: 20px 16px;
            max-width: 100%;
        }

        .reset-password-header h1 {
            font-size: 1.2rem;
        }

        .reset-password-header .subtitle {
            font-size: 11px;
        }
    }

    @media (max-width: 480px) {
        .reset-password-wrapper {
            padding: 20px 12px;
        }

        .reset-password-container {
            padding: 18px 14px;
        }

        .form-group input[type="password"],
        .form-group input[type="text"] {
            padding: 9px 12px;
            padding-right: 36px;
            font-size: 12px;
        }

        .reset-password-container button[type="submit"] {
            padding: 9px 14px;
            font-size: 12px;
        }
    }
</style>

<div class="reset-password-wrapper">
    <div class="reset-password-container">
        <div class="reset-password-header">
            <h1><i class="fas fa-key"></i> Reset Password</h1>
            <p class="subtitle">Enter your new password below</p>
        </div>

        <?php if ($validToken && $userEmail): ?>
            <div class="email-display">
                <i class="fas fa-envelope"></i>
                <span>Resetting password for: <strong><?php echo htmlspecialchars($userEmail); ?></strong></span>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                <div><?php echo htmlspecialchars($success); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($validToken && !isset($password_reset_success)): ?>
            <form method="POST" action="" id="reset-form">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="form-group">
                    <label for="password">
                        <i class="fas fa-lock"></i> New Password
                    </label>
                    <div class="password-input-container">
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            placeholder="Enter new password"
                            required 
                            autocomplete="new-password"
                            minlength="8">
                        <button type="button" class="toggle-password" onclick="togglePassword('password')" tabindex="-1">
                            <i class="fas fa-eye" id="password-eye"></i>
                        </button>
                    </div>
                    <small>Must be at least 8 characters with uppercase, lowercase, and number</small>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">
                        <i class="fas fa-lock"></i> Confirm Password
                    </label>
                    <div class="password-input-container">
                        <input 
                            type="password" 
                            id="confirm_password" 
                            name="confirm_password" 
                            placeholder="Confirm new password"
                            required 
                            autocomplete="new-password"
                            minlength="8">
                        <button type="button" class="toggle-password" onclick="togglePassword('confirm_password')" tabindex="-1">
                            <i class="fas fa-eye" id="confirm_password-eye"></i>
                        </button>
                    </div>
                    <small>Enter the same password again</small>
                </div>
                
                <button type="submit" name="reset_password" id="reset-btn" class="btn-primary">
                    <i class="fas fa-save"></i> Update Password
                </button>
            </form>
        <?php endif; ?>

        <div class="reset-links">
            <p>Remember your password? <a href="login_customer.php">Login here</a></p>
        </div>
    </div>
</div>

<script>
    // Password toggle functionality
    function togglePassword(inputId) {
        const input = document.getElementById(inputId);
        const eye = document.getElementById(inputId + '-eye');
        
        if (input.type === 'password') {
            input.type = 'text';
            eye.className = 'fas fa-eye-slash';
        } else {
            input.type = 'password';
            eye.className = 'fas fa-eye';
        }
    }

    // Form validation and submission
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('reset-form');
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const submitBtn = document.getElementById('reset-btn');
        
        if (form && passwordInput && confirmPasswordInput) {
            // Real-time password matching validation
            function validatePasswords() {
                const password = passwordInput.value;
                const confirmPassword = confirmPasswordInput.value;
                
                if (confirmPassword && password !== confirmPassword) {
                    confirmPasswordInput.setCustomValidity('Passwords do not match');
                    confirmPasswordInput.style.borderColor = 'var(--error-red)';
                } else {
                    confirmPasswordInput.setCustomValidity('');
                    confirmPasswordInput.style.borderColor = '';
                }
            }
            
            passwordInput.addEventListener('input', validatePasswords);
            confirmPasswordInput.addEventListener('input', validatePasswords);
            
            // Form submission handler
            form.addEventListener('submit', function(e) {
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
                }
            });
        }

        // Auto-focus password input if form is visible
        if (passwordInput && passwordInput.offsetParent !== null) {
            passwordInput.focus();
        }
    });
</script>

<?php 
// Only include footer if header was included (not redirecting)
if (!isset($password_reset_success) || !$password_reset_success) {
    require_once 'includes/footer.php';
}
?>
