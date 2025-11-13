<?php 
// Start output buffering to prevent header issues
ob_start();

require_once 'includes/functions.php';
require_once 'config/database.php';

// Check login and redirect if needed
if (isLoggedIn()) {     
    header("Location: seller-dashboard.php");     
    exit(); 
}  

// Generate CSRF token 
if (empty($_SESSION['csrf_token'])) {     
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); 
}

// Handle form submission BEFORE including header
if (isset($_POST['login'])) {     
    // Validate CSRF token     
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {         
        $error = "Invalid form submission. Please try again.";     
    } else {         
        $username = sanitizeInput($_POST['username']);         
        $password = $_POST['password'];         
        $rememberMe = isset($_POST['remember_me']);                  
        
        // Only allow seller logins on this page
        $stmt = $pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND user_type = 'seller' AND is_active = 1");         
        $stmt->execute([$username, $username]);         
        $user = $stmt->fetch(PDO::FETCH_ASSOC);                  
        
        if ($user) {
            // Check seller status
            if ($user['seller_status'] === 'banned') {
                $error = "Your seller account has been banned. Please contact support.";
            } elseif ($user['seller_status'] === 'suspended') {
                $error = "Your seller account is suspended. Please contact support.";
            } elseif ($user['seller_status'] === 'rejected') {
                $error = "Your seller application was rejected. Please contact support.";
            } elseif ($user['seller_status'] === 'pending') {
                $error = "Your seller account is still pending approval. Please wait for admin approval.";
            } elseif (password_verify($password, $user['password'])) {                 
                // Set session variables                 
                $_SESSION['user_id'] = $user['id'];                 
                $_SESSION['username'] = $user['username'];                 
                $_SESSION['user_type'] = $user['user_type'];
                $_SESSION['last_activity'] = time();                                  
                
                // Set remember me token if requested                 
                if ($rememberMe) {                     
                    $rememberToken = bin2hex(random_bytes(32));                     
                    $expiry = time() + (30 * 24 * 60 * 60); // 30 days                                          
                    
                    // Store token in database                     
                    $stmt = $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?");                     
                    $stmt->execute([$rememberToken, $user['id']]);                                          
                    
                    // Set cookie with proper settings for ngrok/production
                    $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
                    setcookie('remember_token', $rememberToken, $expiry, '/', '', $isSecure, true);
                    error_log('Login Seller - Remember token cookie set | Secure: ' . ($isSecure ? 'yes' : 'no'));                 
                }                                  
                
                // Update last login                 
                $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $stmt->execute([$user['id']]);
                
                // Clean output buffer before redirect
                ob_clean();
                                 
                // Redirect to seller dashboard
                header("Location: seller-dashboard.php");                 
                exit();             
            } else {                 
                $error = "Invalid username or password.";             
            }         
        } else {             
            $error = "Invalid seller credentials or account not found.";         
        }     
    } 
}  

// Check for remember me cookie 
if (isset($_COOKIE['remember_token']) && !isLoggedIn()) {     
    $stmt = $pdo->prepare("SELECT * FROM users WHERE remember_token = ? AND user_type = 'seller' AND is_active = 1");     
    $stmt->execute([$_COOKIE['remember_token']]);     
    $user = $stmt->fetch(PDO::FETCH_ASSOC);          
    
    if ($user && $user['seller_status'] === 'approved') {         
        $_SESSION['user_id'] = $user['id'];         
        $_SESSION['username'] = $user['username'];         
        $_SESSION['user_type'] = $user['user_type'];
        $_SESSION['last_activity'] = time();
        
        // Clean output buffer before redirect
        ob_clean();
                
        header("Location: seller-dashboard.php");         
        exit();     
    } 
} 

// Include header after all redirects and session operations
require_once 'includes/header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Libre+Barcode+128+Text&display=swap" rel="stylesheet">

<style>
    /* Override body background */
    body {
        background: linear-gradient(135deg, var(--primary-dark) 0%, rgba(19, 3, 37, 0.9) 100%);
        min-height: 100vh;
        margin: 0;
    }

    /* Main wrapper matching login_customer.php layout */
    .login-page-wrapper {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        height: 100vh;
        padding: 40px 60px 0 60px;
        gap: 50px;
        max-width: 1400px;
        margin: 0 auto;
        overflow: hidden;
    }

    /* Left Side Branding */
    .branding-section {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: flex-start;
        text-align: center;
        padding-top: 80px;
    }

    .main-logo {
        font-size: 70px;
        font-family: 'Libre Barcode 128 Text', monospace;
        font-weight: 400;
        color: #FFD736;
        margin-bottom: 20px;
        text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
    }

    .tagline {
        font-size: 24px;
        color: #FFD736;
        font-weight: 700;
        margin-bottom: 10px;
        opacity: 0.95;
        letter-spacing: 0.5px;
    }

    .subtagline {
        font-size: 16px;
        color: #FFD736;
        opacity: 0.75;
        font-weight: 400;
        max-width: 400px;
        line-height: 1.4;
    }

    /* Login Container - Extra Minimized */
    .login-container {
        max-width: 380px; 
        width: 100%;
        padding: 18px;
        border-radius: 8px;
        background: #ffffff;
        color: #130325;
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.3);
        position: relative;
        overflow: hidden;
        margin-top:0px;
    }

    .login-header {
        text-align: center;
        margin-bottom: 12px;
    }

    .login-header h1 {
        color: #130325;
        font-size: 17px;
        font-weight: 700;
        margin-bottom: 3px;
    }

    .login-header .subtitle {
        font-size: 10px;
        color: #130325;
        opacity: 0.7;
    }

    .seller-requirements {
        background: rgba(255, 215, 54, 0.1);
        border: 1px solid rgba(255, 215, 54, 0.3);
        padding: 8px;
        border-radius: 6px;
        margin-bottom: 8px;
        font-size: 10px;
    }

    .seller-requirements h4 {
        margin: 0 0 6px 0;
        font-size: 12px;
        color: #130325;
        font-weight: 700;
    }

    .seller-requirements ul {
        margin: 4px 0;
        padding-left: 18px;
    }

    .seller-requirements li {
        margin-bottom: 3px;
        color: #130325;
    }
    
    .form-group {
        margin-bottom: 10px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 4px;
        font-weight: 600;
        color: #130325;
        font-size: 11px;
    }
    
    .form-group input[type="text"],
    .form-group input[type="password"] {
        width: 100%;
        padding: 7px 10px;
        border: 2px solid rgba(19, 3, 37, 0.2);
        border-radius: 6px;
        font-size: 12px;
        background: #ffffff;
        color: #130325;
        box-sizing: border-box;
        transition: all 0.3s ease;
    }

    .form-group input:focus {
        outline: none;
        background: #ffffff;
        border-color: #FFD736;
        box-shadow: 0 0 0 3px rgba(255, 215, 54, 0.2);
    }
    
    .form-group input::placeholder {
        color: rgba(19, 3, 37, 0.5);
    }
    
    .password-input-container {
        position: relative;
        display: flex;
        align-items: center;
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
    }

    .toggle-password:hover {
        opacity: 1;
    }
    
    .forgot-password {
        color: #130325;
        font-size: 11px;
        text-decoration: none;
        float: right;
        margin-top: 4px;
        opacity: 0.7;
        transition: opacity 0.3s ease;
    }
    
    .forgot-password:hover {
        opacity: 1;
        text-decoration: underline;
    }
    
    .remember-me {
        display: flex;
        align-items: center;
        font-size: 12px;
        color: #130325;
    }
    
    .remember-me input[type="checkbox"] {
        margin-right: 5px;
        accent-color: #FFD736;
        transform: scale(1.0);
    }
    
    .login-container button[type="submit"] {
        width: 100%;
        padding: 10px 16px;
        background-color: #130325;
        color: #F9F9F9;
        border: 2px solid #130325;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        text-align: center;
        transition: all 0.3s ease;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-top: 6px;
    }
    
    .login-container button[type="submit"]:hover:not(:disabled) {
        background-color: #FFD736;
        color: #130325;
        border-color: #FFD736;
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(255, 215, 54, 0.4);
    }
    
    .login-links {
        text-align: center;
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px solid rgba(19, 3, 37, 0.1);
    }
    
    .login-links p {
        font-size: 11px;
        color: #130325;
        margin: 0;
    }

    .login-links a {
        color: #130325;
        text-decoration: none;
        font-weight: 600;
    }
    
    .login-links a:hover {
        color: #FFD736;
        text-decoration: underline;
    }
    
    .other-logins {
        text-align: center;
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px solid rgba(19, 3, 37, 0.1);
    }

    .other-logins p {
        margin-bottom: 8px;
        font-size: 11px;
        color: #130325;
        opacity: 0.7;
    }
    
    .other-logins a {
        display: inline-block;
        margin: 3px 5px;
        padding: 6px 12px;
        background: #130325;
        color: #FFD736;
        text-decoration: none;
        border-radius: 5px;
        font-size: 11px;
        font-weight: 600;
        transition: all 0.3s ease;
        border: 2px solid #130325;
    }
    
    .other-logins a:hover {
        background: #FFD736;
        color: #130325;
        border-color: #FFD736;
        transform: translateY(-2px);
    }
    
    .success-message, .error-message {
        padding: 8px 12px;
        border-radius: 6px;
        margin-bottom: 12px;
        text-align: center;
        font-size: 12px;
        font-weight: 600;
    }
    
    .success-message {
        background: rgba(40, 167, 69, 0.15);
        border: 2px solid rgba(40, 167, 69, 0.4);
        color: #28a745;
    }
    
    .error-message {
        background: rgba(220, 53, 69, 0.15);
        border: 2px solid rgba(220, 53, 69, 0.4);
        color: #dc3545;
    }

    /* Responsive */
    @media (max-width: 1024px) {
        .login-page-wrapper {
            flex-direction: column;
            padding: 30px;
            gap: 30px;
        }

        .branding-section {
            order: 1;
        }

        .login-container {
            order: 2;
            max-width: 400px;
        }
    }

    @media (max-width: 768px) {
        .login-page-wrapper {
            padding: 25px 20px;
        }

        .main-logo {
            font-size: 60px;
        }

        .tagline {
            font-size: 22px;
        }

        .subtagline {
            font-size: 16px;
        }

        .login-container {
            max-width: 100%;
            padding: 18px;
        }

        .login-header h1 {
            font-size: 18px;
        }
    }

    @media (max-width: 480px) {
        .login-container button[type="submit"] {
            padding: 10px 16px;
            font-size: 13px;
        }

        .other-logins a {
            display: block;
            margin: 6px 0;
        }
    }
</style>

<div class="login-page-wrapper">
    <!-- Left Side Branding -->
    <div class="branding-section">
        <div class="main-logo">PEST-CTRL</div>
        <div class="tagline">Seller Portal</div>
        <div class="subtagline">Manage your products and grow your pest control business</div>
    </div>

    <!-- Login Container -->
    <div class="login-container">
        <div class="login-header">
            <h1><i class="fas fa-store"></i> Seller Login</h1>
            <div class="subtitle">Access your seller dashboard</div>
        </div>

        <div class="seller-requirements">
            <h4><i class="fas fa-info-circle"></i> Seller Account Requirements:</h4>
            <ul>
                <li>Account must be approved by admin</li>
                <li>Active account status needed</li>
            </ul>
        </div>

        <?php if (isset($_SESSION['success'])): ?>     
            <div class="success-message">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; ?>         
                <?php unset($_SESSION['success']); ?>     
            </div> 
        <?php endif; ?>  

        <?php if (isset($_SESSION['session_expired'])): ?>     
            <div class="error-message">         
                <i class="fas fa-clock"></i> <?php echo htmlspecialchars($_SESSION['expired_message'] ?? 'Your session has expired due to inactivity. Please log in again.'); ?>         
                <?php unset($_SESSION['session_expired'], $_SESSION['expired_message']); ?>     
            </div> 
        <?php endif; ?>  

        <?php if (isset($error)): ?>     
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>     
            </div> 
        <?php endif; ?>  

        <form method="POST" action="">     
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">          
            
            <div class="form-group">         
                <label for="username"><i class="fas fa-user-tie"></i> Username or Email</label>         
                <input type="text" 
                       id="username" 
                       name="username" 
                       placeholder="Enter your username or email"
                       value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" 
                       required>     
            </div>          
            
            <div class="form-group">
                <label for="password"><i class="fas fa-lock"></i> Password</label>
                <div class="password-input-container">
                    <input type="password" 
                           id="password" 
                           name="password" 
                           placeholder="Enter your password"
                           required>
                    <button type="button" class="toggle-password" onclick="togglePassword('password')">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>
                <a href="forgot-password-secure.php" class="forgot-password">Forgot Password?</a>
            </div>     
            
            <div class="form-group remember-me">         
                <label>             
                    <input type="checkbox" name="remember_me" <?php echo isset($_POST['remember_me']) ? 'checked' : ''; ?>>             
                    Remember me for 30 days        
                </label>     
            </div>          
            
            <button type="submit" name="login">
                <i class="fas fa-sign-in-alt"></i> Login as Seller
            </button> 
        </form>  

        <div class="login-links">
            <p>Need a seller account? <a href="register.php">Register here</a></p>
        </div>
        
        <div class="other-logins">
            <p>Login as:</p>
            <a href="login_customer.php"><i class="fas fa-shopping-cart"></i> Customer</a>
            <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login Options</a>
        </div>
    </div>
</div>

<script> 
// Toggle password visibility 
function togglePassword(fieldId) {
    const passwordField = document.getElementById(fieldId);
    const toggleButton = passwordField.nextElementSibling;
    const icon = toggleButton.querySelector("i");

    if (passwordField.type === 'password') {
        passwordField.type = 'text';
        icon.classList.remove("fa-eye");
        icon.classList.add("fa-eye-slash");
    } else {
        passwordField.type = 'password';
        icon.classList.remove("fa-eye-slash");
        icon.classList.add("fa-eye");
    }
}
</script>

<?php
// End output buffering and flush content
ob_end_flush();
?>