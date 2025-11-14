<?php
// Move all logic to the top BEFORE including header.php
// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'PHPMailer/PHPMailer/src/Exception.php';
require 'PHPMailer/PHPMailer/src/PHPMailer.php';
require 'PHPMailer/PHPMailer/src/SMTP.php';

// Include database config
require_once 'config/database.php';

// Include functions file (this likely contains the isLoggedIn function)
require_once 'includes/functions.php'; // Add this line

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in BEFORE any output
if (isLoggedIn()) {
    header("Location: index.php");
    exit();
}

// Rest of your register.php code goes here...

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Function to send OTP email using PHPMailer
function sendOTPEmail($email, $name, $otp) {
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'jhongujol1299@gmail.com';
        $mail->Password = 'ljdo ohkv pehx idkv'; // App password
        $mail->SMTPSecure = "ssl";
        $mail->Port = 465;
        
        // Recipients
        $mail->setFrom('jhongujol1299@gmail.com', 'PEST-CTRL Store');
        $mail->addAddress($email, $name);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = '🔐 Email Verification - Your OTP Code | PEST-CTRL';
        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
            <div style='text-align: center; border-bottom: 2px solid #007bff; padding-bottom: 20px;'>
                <h1 style='color: #007bff; margin: 0;'>🐛 PEST-CTRL</h1>
                <p style='color: #666; font-size: 18px; margin: 5px 0;'>Email Verification</p>
            </div>
            <div style='padding: 30px 0; text-align: center;'>
                <h2 style='color: #333; margin-bottom: 20px;'>Hello $name,</h2>
                <p style='color: #666; font-size: 16px;'>Welcome to PEST-CTRL! Your OTP verification code is:</p>
                <div style='background: linear-gradient(135deg, #007bff, #0056b3); color: white; padding: 30px; border-radius: 10px; margin: 20px 0; box-shadow: 0 4px 8px rgba(0,0,0,0.1);'>
                    <h1 style='font-size: 36px; letter-spacing: 8px; margin: 0; font-family: \"Courier New\", monospace;'>$otp</h1>
                </div>
                <p style='color: #666; font-size: 16px; line-height: 1.5;'>
                    Please enter this 6-digit code to verify your email address and complete your registration.<br>
                    <strong>This code will expire in 10 minutes.</strong>
                </p>
                <div style='background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                    <p style='margin: 0; color: #856404; font-size: 14px;'>
                        <strong>Security Note:</strong> Please do not share this code with anyone. Our team will never ask for your OTP.
                    </p>
                </div>
            </div>
            <div style='text-align: center; border-top: 1px solid #eee; padding-top: 20px; color: #999; font-size: 14px;'>
                <p>If you didn't request this verification, please ignore this email.</p>
                <p style='margin: 0;'>© 2024 PEST-CTRL - Professional Pest Control Solutions</p>
            </div>
        </div>";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("OTP Email could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

// Password validation function
function validatePassword($password) {
    $errors = [];
    
    // Minimum length
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    }
    
    // Check for uppercase
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter.";
    }
    
    // Check for lowercase
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter.";
    }
    
    // Check for number
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number.";
    }
    
    return $errors;
}

// Handle OTP verification
if (isset($_POST['verify_otp'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid form submission. Please try again.";
    } else {
        $entered_otp = sanitizeInput($_POST['otp']);
        
        if (isset($_SESSION['registration_otp']) && $_SESSION['registration_otp'] === $entered_otp) {
            // Check OTP expiry (10 minutes)
            if (isset($_SESSION['otp_timestamp']) && (time() - $_SESSION['otp_timestamp']) > 600) {
                $error = "OTP has expired. Please request a new one.";
            } else {
                // OTP is correct, proceed with registration
                $userData = $_SESSION['temp_user_data'];
                
                // Hash password
                $hashedPassword = password_hash($userData['password'], PASSWORD_DEFAULT);
                
                // Insert new user (email is already verified via OTP)
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password, first_name, last_name, user_type, email_verified, agreed_to_terms, agreed_to_privacy)
                                       VALUES (?, ?, ?, ?, ?, ?, 1, 1, 1)");
                $result = $stmt->execute([
                    $userData['username'], 
                    $userData['email'], 
                    $hashedPassword, 
                    $userData['first_name'], 
                    $userData['last_name'], 
                    $userData['user_type']
                ]);
                
                if ($result) {
                    // Clear session data
                    unset($_SESSION['registration_otp']);
                    unset($_SESSION['temp_user_data']);
                    unset($_SESSION['otp_email']);
                    unset($_SESSION['otp_timestamp']);
                    
                    $_SESSION['success'] = "Registration successful! Welcome to PEST-CTRL. Your account has been created and verified.";
                    header("Location: login.php");
                    exit();
                } else {
                    $error = "Error creating account. Please try again.";
                }
            }
        } else {
            $error = "Invalid OTP. Please try again.";
        }
    }
}

// Handle resend OTP
if (isset($_POST['resend_otp'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid form submission. Please try again.";
    } else {
        if (isset($_SESSION['temp_user_data']) && isset($_SESSION['otp_email'])) {
            // Generate new OTP
            $_SESSION['registration_otp'] = sprintf('%06d', mt_rand(100000, 999999));
            $_SESSION['otp_timestamp'] = time();
            
            $userData = $_SESSION['temp_user_data'];
            $email = $_SESSION['otp_email'];
            $name = $userData['first_name'] . ' ' . $userData['last_name'];
            
            if (sendOTPEmail($email, $name, $_SESSION['registration_otp'])) {
                $success = "New OTP has been sent to your email address.";
            } else {
                $error = "Failed to send OTP. Please try again.";
            }
        } else {
            $error = "Session expired. Please start registration again.";
            header("Location: register.php");
            exit();
        }
    }
}

// Handle initial registration form
if (isset($_POST['register'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid form submission. Please try again.";
    } else {
        $username = sanitizeInput($_POST['username']);
        $email = sanitizeInput($_POST['email']);
        $password = $_POST['password'];
        $firstName = sanitizeInput($_POST['first_name']);
        $lastName = sanitizeInput($_POST['last_name']);
        $userType = isset($_POST['user_type']) ? sanitizeInput($_POST['user_type']) : 'customer';
        
        // Validate inputs
        $errors = [];
        
        // Check username
        if (empty($username)) {
            $errors[] = "Username is required.";
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
            $errors[] = "Username must be 3-20 characters and can only contain letters, numbers, and underscores.";
        }
        
        // Check email
        if (empty($email)) {
            $errors[] = "Email is required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please enter a valid email address.";
        }
        
        // Check password strength
        if (empty($password)) {
            $errors[] = "Password is required.";
        } else {
            $passwordErrors = validatePassword($password);
            if (!empty($passwordErrors)) {
                $errors = array_merge($errors, $passwordErrors);
            }
        }
        
        // Check Terms & Conditions agreement
        if (!isset($_POST['agree_terms']) || $_POST['agree_terms'] !== '1') {
            $errors[] = "You must agree to the Terms & Conditions to create an account.";
        }
        
        // Check Privacy Policy agreement
        if (!isset($_POST['agree_privacy']) || $_POST['agree_privacy'] !== '1') {
            $errors[] = "You must agree to the Privacy Policy to create an account.";
        }
        
        // Check if username or email already exists
        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingUser) {
                if ($existingUser['username'] === $username) {
                    $errors[] = "Username already exists.";
                }
                if ($existingUser['email'] === $email) {
                    $errors[] = "Email already exists.";
                }
            }
        }
        
        if (empty($errors)) {
            // Store user data temporarily in session
            $_SESSION['temp_user_data'] = [
                'username' => $username,
                'email' => $email,
                'password' => $password,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'user_type' => $userType
            ];
            
            // Generate OTP and store in session
            $_SESSION['registration_otp'] = sprintf('%06d', mt_rand(100000, 999999));
            $_SESSION['otp_email'] = $email;
            $_SESSION['otp_timestamp'] = time();
            
            // Send OTP email
            $name = $firstName . ' ' . $lastName;
            if (sendOTPEmail($email, $name, $_SESSION['registration_otp'])) {
                $showOtpForm = true;
                $success = "Registration form submitted! Please check your email for the OTP code to complete your PEST-CTRL account setup.";
            } else {
                $error = "Failed to send verification email. Please try again.";
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
}

// Check if we should show OTP form
$showOtpForm = isset($_SESSION['temp_user_data']) && isset($_SESSION['registration_otp']);

// NOW include the header - AFTER all processing is complete
require_once 'includes/header.php';
?>

<div class="register-container">
    <div class="register-header">
        <h1><i class="fas fa-user-plus"></i> Create Account</h1>
        <div class="subtitle">Join PEST-CTRL today - Professional Pest Control Solutions</div>
    </div>

    <?php if (isset($error)): ?>
        <div class="error-message">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($success)): ?>
        <div class="success-message">
            <?php echo $success; ?>
        </div>
    <?php endif; ?>

<?php if (!$showOtpForm): ?>
<!-- Registration Form -->
<form method="POST" action="" id="registerForm">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    
    <!-- First Row: Username and Email -->
    <div class="form-row">
        <div class="form-group">
            <label for="username">Username:</label>
            <input type="text" id="username" name="username" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" required>
            <small>3-20 characters, letters, numbers, and underscores only</small>
        </div>
        <div class="form-group">
            <label for="email">Email:</label>
            <input type="email" id="email" name="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
        </div>
    </div>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Second Row: Password (full width) -->
    <div class="form-row">
        <div class="form-group full-width">
            <label for="password">Password:</label>
            <div class="password-input-container">
                <input type="password" id="password" name="password" required>
                <button type="button" class="toggle-password" onclick="togglePassword('password')">
                    <i class="fa-solid fa-eye"></i>
                </button>
            </div>
            
            <div class="password-strength-meter">
                <div class="password-strength-bar"></div>
            </div>
            <small id="password-strength-text">Password strength: None</small>
            <small class="password-requirements">Must be at least 8 characters with uppercase, lowercase, and numbers</small>
        </div>
    </div>
    
    <!-- Third Row: First Name and Last Name -->
    <div class="form-row">
        <div class="form-group">
            <label for="first_name">First Name:</label>
            <input type="text" id="first_name" name="first_name" value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" required>
        </div>
        <div class="form-group">
            <label for="last_name">Last Name:</label>
            <input type="text" id="last_name" name="last_name" value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>" required>
        </div>
    </div>
    
    <!-- Fourth Row: Account Type (full width) -->
    <div class="form-row">
        <div class="form-group full-width">
            <label for="user_type">Account Type:</label>
            <select id="user_type" name="user_type">
                <option value="customer" <?php echo (isset($_POST['user_type']) && $_POST['user_type'] == 'customer') ? 'selected' : ''; ?>>Customer</option>
                <option value="seller" <?php echo (isset($_POST['user_type']) && $_POST['user_type'] == 'seller') ? 'selected' : ''; ?>>Seller/Supplier</option>
            </select>
        </div>
    </div>
    
    <!-- Terms & Conditions and Privacy Policy Section -->
    <div class="legal-agreements">
        <h3><i class="fas fa-file-contract"></i> Legal Agreements</h3>
        <p class="legal-notice">Please read and agree to the following before creating your account:</p>
        
        <div class="checkbox-group">
            <label class="checkbox-label">
                <input type="checkbox" id="agree_terms" name="agree_terms" value="1" required disabled>
                <span class="checkmark"></span>
                <span class="checkbox-text">
                    I agree to the <a href="#" class="legal-link" onclick="event.preventDefault(); openTermsModal();">Terms & Conditions</a>
                </span>
            </label>
        </div>
        
        <div class="checkbox-group">
            <label class="checkbox-label">
                <input type="checkbox" id="agree_privacy" name="agree_privacy" value="1" required disabled>
                <span class="checkmark"></span>
                <span class="checkbox-text">
                    I agree to the <a href="#" class="legal-link" onclick="event.preventDefault(); openPrivacyModal();">Privacy Policy</a>
                </span>
            </label>
        </div>
        
        <div class="legal-info">
            <p><i class="fas fa-info-circle"></i> <strong>Important:</strong> By creating an account, you acknowledge that:</p>
            <ul>
                <li>You are at least 18 years old or have parental consent</li>
                <li>You will use pesticide products responsibly and according to label instructions</li>
                <li>You understand the safety requirements for handling pest control products</li>
                <li>You agree to comply with local regulations regarding pesticide use</li>
            </ul>
        </div>
    </div>
    
    <button type="submit" name="register" id="registerButton" disabled>
        <i class="fas fa-user-plus"></i> Create Account
    </button>
</form>

<!-- Terms & Conditions Modal -->
<div id="termsModal" class="legal-modal">
    <div class="legal-modal-content">
        <div class="legal-modal-header">
            <h2><i class="fas fa-file-contract"></i> Terms & Conditions</h2>
            <span class="legal-modal-close" onclick="closeTermsModal()">&times;</span>
        </div>
        <div class="legal-modal-body" id="termsModalBody">
            <div class="legal-scroll-content">
                <div class="important-notice">
                    <h3><i class="fas fa-exclamation-triangle"></i> Important Notice</h3>
                    <p>These Terms & Conditions govern your use of PEST-CTRL and the purchase of pest control products. By accessing our website and making purchases, you agree to comply with these terms and all applicable laws and regulations regarding pesticide use.</p>
                </div>

                <section class="terms-section">
                    <h2>1. Acceptance of Terms</h2>
                    <p>By accessing and using the PEST-CTRL website ("Service"), you accept and agree to be bound by the terms and provision of this agreement. If you do not agree to abide by the above, please do not use this service.</p>
                    
                    <h3>1.1 Age Requirement</h3>
                    <ul>
                        <li>You must be at least 18 years old to create an account and purchase products</li>
                        <li>If you are under 18, you must have explicit parental or guardian consent</li>
                        <li>Some products may have additional age restrictions as required by law</li>
                    </ul>
                </section>

                <section class="terms-section">
                    <h2>2. Product Information & Restrictions</h2>
                    
                    <h3>2.1 Pesticide Products</h3>
                    <ul>
                        <li>All pesticide products sold are for professional or licensed applicator use unless otherwise specified</li>
                        <li>Customers must verify local licensing requirements before purchasing restricted-use pesticides</li>
                        <li>Product availability may be restricted based on your geographic location and local regulations</li>
                        <li>We reserve the right to request proof of licensing or certification before processing orders</li>
                    </ul>

                    <h3>2.2 Product Safety & Liability</h3>
                    <ul>
                        <li>All products must be used strictly according to manufacturer label instructions</li>
                        <li>PEST-CTRL is not responsible for improper use of products purchased through our platform</li>
                        <li>Customers assume full responsibility for safe storage, handling, and application of all products</li>
                        <li>Environmental and health impacts from product misuse are the sole responsibility of the purchaser</li>
                    </ul>
                </section>

                <section class="terms-section">
                    <h2>3. Account Registration & Responsibilities</h2>
                    
                    <h3>3.1 Account Creation</h3>
                    <ul>
                        <li>You must provide accurate and complete information during registration</li>
                        <li>You are responsible for maintaining the confidentiality of your account credentials</li>
                        <li>You must notify us immediately of any unauthorized use of your account</li>
                        <li>One person may not maintain multiple accounts</li>
                    </ul>

                    <h3>3.2 Account Types</h3>
                    <ul>
                        <li><strong>Customer Accounts:</strong> For end-users purchasing pest control products</li>
                        <li><strong>Seller/Supplier Accounts:</strong> For businesses selling products through our platform</li>
                        <li>Different account types have different privileges and responsibilities</li>
                    </ul>
                </section>

                <section class="terms-section">
                    <h2>4. Orders & Payments</h2>
                    
                    <h3>4.1 Order Processing</h3>
                    <ul>
                        <li>All orders are subject to availability and acceptance</li>
                        <li>We reserve the right to refuse or cancel any order at our discretion</li>
                        <li>Orders for restricted products may require additional verification</li>
                        <li>Pricing is subject to change without notice</li>
                    </ul>

                    <h3>4.2 Payment Terms</h3>
                    <ul>
                        <li>Payment is due at the time of order placement</li>
                        <li>We accept major credit cards, PayPal, and other approved payment methods</li>
                        <li>All transactions are processed in USD unless otherwise specified</li>
                        <li>Sales tax will be applied where required by law</li>
                    </ul>
                </section>

                <section class="terms-section">
                    <h2>5. Shipping & Delivery</h2>
                    
                    <h3>5.1 Shipping Restrictions</h3>
                    <ul>
                        <li>Certain products may have shipping restrictions due to hazardous material classifications</li>
                        <li>Some products can only be shipped to licensed facilities</li>
                        <li>International shipping may be restricted for certain pesticide products</li>
                        <li>Additional fees may apply for hazardous material shipping</li>
                    </ul>

                    <h3>5.2 Delivery & Risk of Loss</h3>
                    <ul>
                        <li>Risk of loss passes to the buyer upon delivery to the shipping carrier</li>
                        <li>Delivery times are estimates and not guarantees</li>
                        <li>Signature confirmation may be required for certain products</li>
                    </ul>
                </section>

                <section class="terms-section">
                    <h2>6. Returns & Refunds</h2>
                    
                    <h3>6.1 Return Policy</h3>
                    <ul>
                        <li>Pesticide products cannot be returned once opened due to safety and regulatory concerns</li>
                        <li>Unopened products may be returned within 30 days in original packaging</li>
                        <li>Custom or special-order items are non-returnable</li>
                        <li>Return shipping costs are the responsibility of the customer unless the return is due to our error</li>
                    </ul>

                    <h3>6.2 Damaged or Defective Products</h3>
                    <ul>
                        <li>Report damaged or defective products within 48 hours of delivery</li>
                        <li>Photo documentation may be required for damage claims</li>
                        <li>We will replace or refund defective products at our discretion</li>
                    </ul>
                </section>

                <section class="terms-section">
                    <h2>7. Intellectual Property</h2>
                    <ul>
                        <li>All content on PEST-CTRL is protected by copyright and other intellectual property laws</li>
                        <li>You may not reproduce, distribute, or create derivative works without written permission</li>
                        <li>Product names and trademarks belong to their respective owners</li>
                        <li>User-generated content may be used by PEST-CTRL for promotional purposes</li>
                    </ul>
                </section>

                <section class="terms-section">
                    <h2>8. Prohibited Uses</h2>
                    <p>You agree not to use our service:</p>
                    <ul>
                        <li>For any unlawful purpose or to solicit others to perform unlawful acts</li>
                        <li>To violate any international, federal, provincial, or state regulations or laws</li>
                        <li>To transmit or procure harmful computer code, viruses, or other malicious software</li>
                        <li>To purchase products for illegal pest control activities</li>
                        <li>To resell restricted-use pesticides without proper licensing</li>
                        <li>To interfere with the security or proper functioning of the website</li>
                    </ul>
                </section>

                <section class="terms-section">
                    <h2>9. Disclaimers & Limitation of Liability</h2>
                    
                    <h3>9.1 Service Disclaimer</h3>
                    <ul>
                        <li>Our service is provided "as is" without warranties of any kind</li>
                        <li>We do not warrant that the service will be uninterrupted or error-free</li>
                        <li>Product information is provided by manufacturers and suppliers</li>
                        <li>We make no guarantees about the effectiveness of pest control products</li>
                    </ul>

                    <h3>9.2 Limitation of Liability</h3>
                    <ul>
                        <li>PEST-CTRL shall not be liable for any indirect, incidental, or consequential damages</li>
                        <li>Our total liability shall not exceed the amount paid for the product or service</li>
                        <li>We are not responsible for crop damage, environmental impact, or health issues resulting from product use</li>
                        <li>Users assume all risks associated with pesticide use</li>
                    </ul>
                </section>

                <section class="terms-section">
                    <h2>10. Regulatory Compliance</h2>
                    <ul>
                        <li>Customers must comply with all federal, state, and local pesticide regulations</li>
                        <li>EPA registration numbers must be verified before use</li>
                        <li>Proper disposal of containers and unused products is the customer's responsibility</li>
                        <li>Record-keeping requirements for pesticide use must be maintained by the customer</li>
                        <li>We may report suspicious purchases to appropriate regulatory authorities</li>
                    </ul>
                </section>

                <section class="terms-section">
                    <h2>11. Privacy & Data Protection</h2>
                    <ul>
                        <li>Our Privacy Policy governs the collection and use of your personal information</li>
                        <li>We may share information with regulatory authorities as required by law</li>
                        <li>Purchase records may be maintained for regulatory compliance</li>
                        <li>Marketing communications can be opted out of at any time</li>
                    </ul>
                </section>

                <section class="terms-section">
                    <h2>12. Termination</h2>
                    <ul>
                        <li>We may terminate or suspend accounts that violate these terms</li>
                        <li>You may close your account at any time</li>
                        <li>Termination does not affect pending orders or obligations</li>
                        <li>Certain provisions of these terms survive termination</li>
                    </ul>
                </section>

                <section class="terms-section">
                    <h2>13. Modifications to Terms</h2>
                    <ul>
                        <li>We reserve the right to modify these terms at any time</li>
                        <li>Changes will be posted on this page with an updated date</li>
                        <li>Continued use of the service constitutes acceptance of modified terms</li>
                        <li>Major changes may be communicated via email</li>
                    </ul>
                </section>

                <section class="terms-section">
                    <h2>14. Governing Law & Dispute Resolution</h2>
                    <ul>
                        <li>These terms are governed by the laws of [Your State/Country]</li>
                        <li>Disputes will be resolved through binding arbitration</li>
                        <li>Legal actions must be brought within one year of the cause of action</li>
                        <li>If any provision is found unenforceable, the remainder shall remain in effect</li>
                    </ul>
                </section>

                <section class="terms-section">
                    <h2>15. Contact Information</h2>
                    <p>For questions about these Terms & Conditions, contact us:</p>
                    <div class="contact-info">
                        <p><i class="fas fa-envelope"></i> <strong>Email:</strong> legal@pest-ctrl.com</p>
                        <p><i class="fas fa-phone"></i> <strong>Phone:</strong> 1-800-PEST-CTRL</p>
                        <p><i class="fas fa-map-marker-alt"></i> <strong>Address:</strong> [Your Business Address]</p>
                    </div>
                </section>

                <div class="acknowledgment-box">
                    <h3><i class="fas fa-handshake"></i> Acknowledgment</h3>
                    <p>By creating an account and using our services, you acknowledge that you have read, understood, and agree to be bound by these Terms & Conditions. You also acknowledge the serious nature of pest control products and your responsibility to use them safely and legally.</p>
                </div>
            </div>
        </div>
        <div class="legal-modal-footer">
            <div class="scroll-indicator" id="termsScrollIndicator">
                <i class="fas fa-arrow-down"></i> Please scroll to the bottom to continue
            </div>
            <button type="button" class="btn-agree-terms" id="btnAgreeTerms" onclick="agreeToTerms()" disabled>
                <i class="fas fa-check"></i> I Agree to Terms & Conditions
            </button>
            <button type="button" class="btn-close-modal" onclick="closeTermsModal()">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>

<!-- Privacy Policy Modal -->
<div id="privacyModal" class="legal-modal">
    <div class="legal-modal-content">
        <div class="legal-modal-header">
            <h2><i class="fas fa-shield-alt"></i> Privacy Policy</h2>
            <span class="legal-modal-close" onclick="closePrivacyModal()">&times;</span>
        </div>
        <div class="legal-modal-body" id="privacyModalBody">
            <div class="legal-scroll-content">
                <div class="important-notice">
                    <h3><i class="fas fa-user-shield"></i> Your Privacy Matters</h3>
                    <p>At PEST-CTRL, we are committed to protecting your privacy and personal information. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you visit our website and use our services. Due to the regulated nature of pest control products, some information collection is required by law.</p>
                </div>

                <section class="privacy-section">
                    <h2>1. Information We Collect</h2>
                    
                    <h3>1.1 Personal Information</h3>
                    <p>We collect personal information that you voluntarily provide when:</p>
                    <ul>
                        <li><strong>Creating an Account:</strong> Name, email, username, password, address, phone number</li>
                        <li><strong>Making Purchases:</strong> Billing information, shipping address, payment details</li>
                        <li><strong>Professional Verification:</strong> License numbers, certification details, business information</li>
                        <li><strong>Customer Support:</strong> Communication records, support tickets, feedback</li>
                        <li><strong>Marketing:</strong> Subscription preferences, interests, product preferences</li>
                    </ul>

                    <h3>1.2 Automatically Collected Information</h3>
                    <ul>
                        <li><strong>Usage Data:</strong> IP address, browser type, device information, operating system</li>
                        <li><strong>Website Activity:</strong> Pages visited, time spent, clicks, search queries</li>
                        <li><strong>Cookies & Tracking:</strong> Session data, preferences, shopping cart contents</li>
                        <li><strong>Location Data:</strong> General geographic location for shipping and regulatory compliance</li>
                    </ul>

                    <h3>1.3 Third-Party Information</h3>
                    <ul>
                        <li><strong>Payment Processors:</strong> Transaction verification and fraud prevention data</li>
                        <li><strong>Shipping Partners:</strong> Delivery confirmation and tracking information</li>
                        <li><strong>Regulatory Databases:</strong> License verification and compliance checks</li>
                        <li><strong>Social Media:</strong> Information from connected social accounts (if applicable)</li>
                    </ul>
                </section>

                <section class="privacy-section">
                    <h2>2. How We Use Your Information</h2>
                    
                    <h3>2.1 Primary Uses</h3>
                    <ul>
                        <li><strong>Account Management:</strong> Creating and maintaining your account</li>
                        <li><strong>Order Processing:</strong> Processing payments, fulfilling orders, shipping products</li>
                        <li><strong>Customer Service:</strong> Responding to inquiries, resolving issues, providing support</li>
                        <li><strong>Product Delivery:</strong> Coordinating shipping and delivery of purchased products</li>
                    </ul>

                    <h3>2.2 Legal & Regulatory Compliance</h3>
                    <ul>
                        <li><strong>License Verification:</strong> Confirming authorization to purchase restricted products</li>
                        <li><strong>Age Verification:</strong> Ensuring compliance with age requirements</li>
                        <li><strong>Regulatory Reporting:</strong> Reporting to EPA, state agencies as required by law</li>
                        <li><strong>Record Keeping:</strong> Maintaining purchase records for regulatory audits</li>
                        <li><strong>Safety Monitoring:</strong> Tracking product usage for safety compliance</li>
                    </ul>

                    <h3>2.3 Business Operations</h3>
                    <ul>
                        <li><strong>Website Improvement:</strong> Analyzing usage to enhance user experience</li>
                        <li><strong>Product Development:</strong> Understanding customer needs and preferences</li>
                        <li><strong>Marketing:</strong> Sending promotional emails, product recommendations</li>
                        <li><strong>Security:</strong> Protecting against fraud, unauthorized access, and abuse</li>
                    </ul>
                </section>

                <section class="privacy-section">
                    <h2>3. Information Sharing & Disclosure</h2>
                    
                    <h3>3.1 We Share Information With:</h3>
                    <ul>
                        <li><strong>Service Providers:</strong> Payment processors, shipping companies, IT services</li>
                        <li><strong>Business Partners:</strong> Manufacturers, suppliers, authorized distributors</li>
                        <li><strong>Regulatory Agencies:</strong> EPA, state pesticide agencies, law enforcement (when required)</li>
                        <li><strong>Legal Compliance:</strong> Courts, attorneys, regulatory bodies (as legally required)</li>
                    </ul>

                    <h3>3.2 We Do NOT Share Information For:</h3>
                    <ul>
                        <li>Sale to marketing companies or data brokers</li>
                        <li>Unsolicited commercial purposes</li>
                        <li>Sharing with competitors without consent</li>
                        <li>Personal use by employees or contractors</li>
                    </ul>

                    <h3>3.3 Business Transfers</h3>
                    <p>In the event of a merger, acquisition, or sale of assets, your information may be transferred to the acquiring entity, subject to the same privacy protections.</p>
                </section>

                <section class="privacy-section">
                    <h2>4. Data Security & Protection</h2>
                    
                    <h3>4.1 Security Measures</h3>
                    <ul>
                        <li><strong>Encryption:</strong> SSL/TLS encryption for data transmission</li>
                        <li><strong>Secure Storage:</strong> Encrypted databases with restricted access</li>
                        <li><strong>Access Controls:</strong> Role-based permissions and multi-factor authentication</li>
                        <li><strong>Regular Audits:</strong> Security assessments and vulnerability testing</li>
                        <li><strong>Employee Training:</strong> Privacy and security awareness programs</li>
                    </ul>

                    <h3>4.2 Payment Security</h3>
                    <ul>
                        <li>PCI DSS compliant payment processing</li>
                        <li>No storage of full credit card numbers</li>
                        <li>Tokenization for recurring payments</li>
                        <li>Fraud detection and prevention systems</li>
                    </ul>

                    <h3>4.3 Data Breach Response</h3>
                    <ul>
                        <li>Immediate containment and assessment procedures</li>
                        <li>Notification to affected users within 72 hours</li>
                        <li>Cooperation with law enforcement and regulatory agencies</li>
                        <li>Remediation and prevention measures</li>
                    </ul>
                </section>

                <section class="privacy-section">
                    <h2>5. Your Privacy Rights</h2>
                    
                    <h3>5.1 Access & Control</h3>
                    <ul>
                        <li><strong>Access:</strong> Request copies of your personal information</li>
                        <li><strong>Correction:</strong> Update or correct inaccurate information</li>
                        <li><strong>Deletion:</strong> Request deletion of your data (subject to legal requirements)</li>
                        <li><strong>Portability:</strong> Receive your data in a structured format</li>
                        <li><strong>Restriction:</strong> Limit processing of your information</li>
                    </ul>

                    <h3>5.2 Marketing Preferences</h3>
                    <ul>
                        <li>Opt-out of marketing emails at any time</li>
                        <li>Customize communication preferences</li>
                        <li>Unsubscribe from promotional materials</li>
                        <li>Control cookie and tracking preferences</li>
                    </ul>

                    <h3>5.3 Account Management</h3>
                    <ul>
                        <li>Update personal information in your account settings</li>
                        <li>Change password and security settings</li>
                        <li>View order history and purchase records</li>
                        <li>Close your account (subject to regulatory retention requirements)</li>
                    </ul>
                </section>

                <section class="privacy-section">
                    <h2>6. Cookies & Tracking Technologies</h2>
                    
                    <h3>6.1 Types of Cookies We Use</h3>
                    <ul>
                        <li><strong>Essential Cookies:</strong> Required for website functionality and security</li>
                        <li><strong>Performance Cookies:</strong> Analytics to improve website performance</li>
                        <li><strong>Functional Cookies:</strong> Remember your preferences and settings</li>
                        <li><strong>Marketing Cookies:</strong> Deliver relevant advertisements and content</li>
                    </ul>

                    <h3>6.2 Cookie Management</h3>
                    <ul>
                        <li>Configure cookie preferences in your browser settings</li>
                        <li>Use our cookie consent manager to customize preferences</li>
                        <li>Note: Disabling essential cookies may affect website functionality</li>
                        <li>Third-party cookies from service providers may be present</li>
                    </ul>
                </section>

                <section class="privacy-section">
                    <h2>7. Data Retention</h2>
                    
                    <h3>7.1 Retention Periods</h3>
                    <ul>
                        <li><strong>Account Information:</strong> Retained while account is active plus 3 years</li>
                        <li><strong>Purchase Records:</strong> 7 years (regulatory requirement for pesticide sales)</li>
                        <li><strong>License Information:</strong> 5 years after license expiration</li>
                        <li><strong>Marketing Data:</strong> Until you unsubscribe or request deletion</li>
                        <li><strong>Website Analytics:</strong> 2 years for performance optimization</li>
                    </ul>

                    <h3>7.2 Legal Requirements</h3>
                    <ul>
                        <li>EPA requires pesticide sale records for 2 years minimum</li>
                        <li>State regulations may require longer retention periods</li>
                        <li>Tax and accounting records retained for 7 years</li>
                        <li>Legal disputes may extend retention periods</li>
                    </ul>
                </section>

                <section class="privacy-section">
                    <h2>8. International Data Transfers</h2>
                    <ul>
                        <li>Data may be processed in countries outside your residence</li>
                        <li>We ensure adequate protection through appropriate safeguards</li>
                        <li>Standard Contractual Clauses or adequacy decisions govern transfers</li>
                        <li>You consent to international transfers by using our services</li>
                    </ul>
                </section>

                <section class="privacy-section">
                    <h2>9. Children's Privacy</h2>
                    <ul>
                        <li>Our services are not intended for children under 18 years of age</li>
                        <li>We do not knowingly collect information from children</li>
                        <li>Parents should monitor their children's online activities</li>
                        <li>If we discover we have collected information from a child, we will delete it immediately</li>
                        <li>Contact us if you believe your child has provided information to us</li>
                    </ul>
                </section>

                <section class="privacy-section">
                    <h2>10. State-Specific Privacy Rights</h2>
                    
                    <h3>10.1 California Residents (CCPA/CPRA)</h3>
                    <ul>
                        <li><strong>Right to Know:</strong> Categories and specific pieces of personal information collected</li>
                        <li><strong>Right to Delete:</strong> Request deletion of personal information</li>
                        <li><strong>Right to Correct:</strong> Correct inaccurate personal information</li>
                        <li><strong>Right to Opt-Out:</strong> Sale or sharing of personal information</li>
                        <li><strong>Right to Limit:</strong> Use of sensitive personal information</li>
                        <li><strong>Non-Discrimination:</strong> Equal service regardless of privacy choices</li>
                    </ul>

                    <h3>10.2 European Union (GDPR)</h3>
                    <ul>
                        <li>Legal basis for processing: Consent, contract performance, legal compliance</li>
                        <li>Right to withdraw consent at any time</li>
                        <li>Right to object to processing for marketing purposes</li>
                        <li>Right to lodge complaints with supervisory authorities</li>
                        <li>Data Protection Officer contact: dpo@pest-ctrl.com</li>
                    </ul>

                    <h3>10.3 Other State Laws</h3>
                    <ul>
                        <li>Virginia Consumer Data Protection Act (VCDPA)</li>
                        <li>Colorado Privacy Act (CPA)</li>
                        <li>Connecticut Data Privacy Act (CTDPA)</li>
                        <li>Additional state laws may apply based on your location</li>
                    </ul>
                </section>

                <section class="privacy-section">
                    <h2>11. Third-Party Services & Links</h2>
                    
                    <h3>11.1 Integrated Services</h3>
                    <ul>
                        <li><strong>Payment Processors:</strong> PayPal, Stripe, credit card companies</li>
                        <li><strong>Shipping Partners:</strong> FedEx, UPS, USPS tracking and delivery</li>
                        <li><strong>Analytics:</strong> Google Analytics, website performance tools</li>
                        <li><strong>Customer Support:</strong> Live chat, helpdesk platforms</li>
                        <li><strong>Email Services:</strong> Marketing automation and transactional emails</li>
                    </ul>

                    <h3>11.2 External Links</h3>
                    <ul>
                        <li>Our website may contain links to third-party websites</li>
                        <li>We are not responsible for the privacy practices of other sites</li>
                        <li>Review privacy policies of linked websites before providing information</li>
                        <li>Third-party services have their own terms and privacy policies</li>
                    </ul>
                </section>

                <section class="privacy-section">
                    <h2>12. Marketing & Communications</h2>
                    
                    <h3>12.1 Email Marketing</h3>
                    <ul>
                        <li>Product announcements and new arrivals</li>
                        <li>Special offers and promotional discounts</li>
                        <li>Educational content about pest control</li>
                        <li>Safety alerts and product recalls</li>
                        <li>Industry news and regulatory updates</li>
                    </ul>

                    <h3>12.2 Communication Preferences</h3>
                    <ul>
                        <li>Choose frequency and types of communications</li>
                        <li>Separate preferences for promotional vs. transactional emails</li>
                        <li>SMS/text message opt-in for order updates</li>
                        <li>Push notifications for mobile app users</li>
                    </ul>

                    <h3>12.3 Opt-Out Options</h3>
                    <ul>
                        <li>Unsubscribe links in all marketing emails</li>
                        <li>Account settings to manage preferences</li>
                        <li>Contact customer service for assistance</li>
                        <li>Note: You cannot opt-out of transactional/service emails</li>
                    </ul>
                </section>

                <section class="privacy-section">
                    <h2>13. Regulatory Compliance & Reporting</h2>
                    
                    <h3>13.1 Pesticide Regulations</h3>
                    <ul>
                        <li>Federal Insecticide, Fungicide, and Rodenticide Act (FIFRA) compliance</li>
                        <li>EPA pesticide registration and usage reporting</li>
                        <li>State pesticide licensing and certification verification</li>
                        <li>Restricted Use Pesticide (RUP) purchase tracking</li>
                        <li>Hazardous material shipping documentation</li>
                    </ul>

                    <h3>13.2 Required Disclosures</h3>
                    <ul>
                        <li>Suspicious purchase patterns to regulatory authorities</li>
                        <li>Large quantity purchases requiring additional scrutiny</li>
                        <li>License violations or expired certifications</li>
                        <li>Safety incidents or product misuse reports</li>
                        <li>Law enforcement requests with valid legal process</li>
                    </ul>
                </section>

                <section class="privacy-section">
                    <h2>14. Data Subject Requests</h2>
                    
                    <h3>14.1 How to Submit Requests</h3>
                    <ul>
                        <li><strong>Online:</strong> Privacy request form on our website</li>
                        <li><strong>Email:</strong> privacy@pest-ctrl.com</li>
                        <li><strong>Phone:</strong> 1-800-PEST-CTRL (privacy department)</li>
                        <li><strong>Mail:</strong> PEST-CTRL Privacy Team, [Business Address]</li>
                    </ul>

                    <h3>14.2 Request Processing</h3>
                    <ul>
                        <li>Identity verification required for all requests</li>
                        <li>Response within 30 days (may extend to 60 days if complex)</li>
                        <li>No charge for reasonable requests</li>
                        <li>Excessive or repetitive requests may incur fees</li>
                        <li>Some information may be retained for legal compliance</li>
                    </ul>
                </section>

                <section class="privacy-section">
                    <h2>15. Privacy Policy Updates</h2>
                    <ul>
                        <li>We may update this policy to reflect changes in practices or regulations</li>
                        <li>Material changes will be prominently posted on our website</li>
                        <li>Email notification for significant changes affecting your rights</li>
                        <li>Continued use of services constitutes acceptance of updates</li>
                        <li>Previous versions available upon request</li>
                    </ul>
                </section>

                <section class="privacy-section">
                    <h2>16. Contact Information</h2>
                    <p>For privacy-related questions, concerns, or requests:</p>
                    <div class="contact-info">
                        <p><i class="fas fa-envelope"></i> <strong>Privacy Team:</strong> privacy@pest-ctrl.com</p>
                        <p><i class="fas fa-shield-alt"></i> <strong>Data Protection Officer:</strong> dpo@pest-ctrl.com</p>
                        <p><i class="fas fa-phone"></i> <strong>Privacy Hotline:</strong> 1-800-PEST-CTRL (ext. 777)</p>
                        <p><i class="fas fa-map-marker-alt"></i> <strong>Mailing Address:</strong></p>
                        <div class="address-block">
                            PEST-CTRL Privacy Team<br>
                            [Your Business Address]<br>
                            [City, State ZIP Code]<br>
                            [Country]
                        </div>
                    </div>
                </section>

                <div class="acknowledgment-box">
                    <h3><i class="fas fa-user-check"></i> Your Consent</h3>
                    <p>By creating an account and using PEST-CTRL services, you acknowledge that you have read, understood, and consent to the collection, use, and disclosure of your personal information as described in this Privacy Policy. You understand that some information collection and sharing is required by law due to the regulated nature of pest control products.</p>
                    <p><strong>Special Note:</strong> Due to the hazardous nature of pesticide products, certain information must be retained for regulatory compliance even if you request deletion. We will clearly explain any limitations when processing your privacy requests.</p>
                </div>
            </div>
        </div>
        <div class="legal-modal-footer">
            <div class="scroll-indicator" id="privacyScrollIndicator">
                <i class="fas fa-arrow-down"></i> Please scroll to the bottom to continue
            </div>
            <button type="button" class="btn-agree-privacy" id="btnAgreePrivacy" onclick="agreeToPrivacy()" disabled>
                <i class="fas fa-check"></i> I Agree to Privacy Policy
            </button>
            <button type="button" class="btn-close-modal" onclick="closePrivacyModal()">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>

<?php else: ?>
<!-- OTP Verification Form -->
<div id="otpverify" class="otp-container">
    <h2><i class="fas fa-envelope-open-text"></i> Email Verification</h2>
    <p>We've sent a 6-digit OTP code to <strong><?php echo htmlspecialchars($_SESSION['otp_email']); ?></strong></p>
    <p>Please enter the code below to verify your email address and complete your PEST-CTRL registration:</p>
    
    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        
        <div class="form-group">
            <label for="otp_inp">Enter OTP:</label>
            <input type="text" id="otp_inp" name="otp" maxlength="6" pattern="[0-9]{6}" required>
        </div>
        
        <button type="submit" name="verify_otp" id="otp_btn">
            <i class="fas fa-check-circle"></i> Verify OTP
        </button>
        <div class="button-group">
            <button type="submit" name="resend_otp" id="resend_otp">
                <i class="fas fa-redo"></i> Resend OTP
            </button>
            <button type="button" id="cancel_otp" onclick="cancelRegistration()">
                <i class="fas fa-times"></i> Cancel
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

    <p class="login-link">Already have an account? <a href="login.php">Login here</a></p>
</div>

<style>
/* External CSS Library */
@import url('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css');

/* CSS Variables (from modern-style.css) */
:root {
    --primary-dark: #130325;
    --primary-light: #F9F9F9;
    --accent-yellow: #FFD736;
    --accent-green: #28a745;
    --accent-red: #dc3545;
    --border-secondary: rgba(249, 249, 249, 0.3);
    --shadow-dark: rgba(0, 0, 0, 0.3);
    --font-primary: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

/* Register Page Styles */
body {
    background: linear-gradient(135deg, var(--primary-dark) 0%, rgba(19, 3, 37, 0.9) 100%);
    min-height: 100vh;
    margin: 0;
    font-family: var(--font-primary);
}

/* Override header background to match */
.site-header {
    background: linear-gradient(135deg, var(--primary-dark) 0%, rgba(19, 3, 37, 0.9) 100%) !important;
}

/* Fix header link hover effects to match main header */
.nav-links a:hover {
    color: #FFD736 !important;
    background: rgba(19, 3, 37, 0.8) !important;
}

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

body {
    background: linear-gradient(135deg, var(--primary-dark) 0%, rgba(19, 3, 37, 0.95) 50%, #0a0118 100%);
}

.register-container {
    max-width: 900px;
    margin: 30px auto 20px auto;
    padding: 20px;
    border-radius: 12px;
    background: var(--bg-white);
    color: var(--text-dark);
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    border: 1px solid var(--border-light);
    position: relative;
    overflow: hidden;
}

.register-header {
    text-align: center;
    margin-bottom: 16px;
}

.register-header h1 {
    margin: 0;
    font-size: 1.35rem;
    font-weight: 600;
    color: var(--text-dark);
    margin-bottom: 6px;
    letter-spacing: -0.3px;
}

.register-header .subtitle {
    font-size: 12px;
    color: var(--text-light);
}

.form-group {
    margin-bottom: 10px;
}

/* Horizontal form rows */
.form-row {
    display: flex;
    gap: 15px;
    margin-bottom: 10px;
}

.form-row .form-group {
    flex: 1;
    margin-bottom: 0;
}

.form-row .form-group.full-width {
    flex: 1 1 100%;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #130325;
    font-size: 12px;
}

.form-group input[type="text"],
.form-group input[type="email"],
.form-group input[type="password"],
.form-group select {
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

.form-group input:focus,
.form-group select:focus {
    outline: none;
    background: var(--bg-white);
    border-color: var(--primary-dark);
    box-shadow: 0 0 0 3px rgba(19, 3, 37, 0.1);
    border-width: 2px;
}

.form-group input::placeholder {
    color: rgba(19, 3, 37, 0.5);
}

.otp-container {
    max-width: 420px;
    margin: 20px auto;
    padding: 25px;
    border: 1px solid var(--border-secondary);
    border-radius: 15px;
    background: linear-gradient(135deg, var(--primary-dark) 0%, rgba(19, 3, 37, 0.95) 100%);
    color: var(--primary-light);
    box-shadow: 0 10px 40px var(--shadow-dark);
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

.password-strength-meter {
    width: 100%;
    height: 4px;
    background-color: #e0e0e0;
    border-radius: 2px;
    margin-top: 5px;
    overflow: hidden;
}

.password-strength-bar {
    height: 100%;
    width: 0%;
    transition: all 0.3s ease;
    border-radius: 2px;
}

.password-strength-bar.weak { background-color: #ff4444; }
.password-strength-bar.medium { background-color: #ffaa00; }
.password-strength-bar.strong { background-color: #00aa00; }

/* Legal Agreements Section */
.legal-agreements {
    background: rgba(19, 3, 37, 0.04);
    border: 1px solid var(--border-light);
    border-radius: 8px;
    padding: 12px 14px;
    margin: 12px 0;
}

.legal-agreements h3 {
    color: var(--text-dark);
    font-size: 13px;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
}

.legal-notice {
    color: #130325;
    font-size: 11px;
    margin-bottom: 12px;
    opacity: 1;
}

.checkbox-group {
    margin-bottom: 10px;
}

.checkbox-label {
    display: flex;
    align-items: flex-start;
    cursor: pointer;
    font-size: 11px;
    line-height: 1.4;
    color: #130325;
}

.checkbox-label input[type="checkbox"] {
    position: absolute;
    opacity: 0;
    cursor: pointer;
}

.checkbox-label input[type="checkbox"]:disabled {
    cursor: not-allowed;
}

.checkmark {
    display: inline-block;
    width: 16px;
    height: 16px;
    background-color: #ffffff;
    border: 2px solid rgba(19, 3, 37, 0.3);
    border-radius: 3px;
    margin-right: 8px;
    flex-shrink: 0;
    position: relative;
    transition: all 0.3s ease;
}

.checkbox-label:hover .checkmark {
    background-color: rgba(255, 215, 54, 0.1);
}

.checkbox-label input[type="checkbox"]:disabled + .checkmark {
    opacity: 0.5;
    cursor: not-allowed;
    background: var(--bg-light);
    border-color: var(--border-light);
    pointer-events: none;
}

.checkbox-label:hover input[type="checkbox"]:disabled + .checkmark {
    background-color: var(--bg-light);
}

.checkbox-label input[type="checkbox"]:disabled ~ .checkbox-text {
    opacity: 0.6;
    cursor: not-allowed;
}

.checkbox-label input[type="checkbox"]:checked ~ .checkmark {
    background-color: #28a745;
    border-color: #28a745;
}

.checkbox-label input[type="checkbox"]:checked ~ .checkmark:after {
    content: '✓';
    position: absolute;
    top: -2px;
    left: 1px;
    color: white;
    font-size: 12px;
    font-weight: bold;
}

.checkbox-text {
    flex: 1;
}

.legal-link {
    color: #130325;
    text-decoration: underline;
    font-weight: 600;
}

.legal-link:hover {
    color: #FFD736;
}

.legal-info {
    background: rgba(255, 215, 54, 0.1);
    border: 1px solid rgba(255, 215, 54, 0.3);
    border-radius: 6px;
    padding: 10px;
    margin-top: 10px;
}

.legal-info p {
    margin: 0 0 8px 0;
    font-size: 11px;
    color: #130325;
    font-weight: 600;
}

.legal-info ul {
    margin: 0;
    padding-left: 12px;
    font-size: 10px;
    color: #130325;
    line-height: 1.3;
    opacity: 0.8;
}

.legal-info li {
    margin-bottom: 3px;
}

.success-message {
    background: rgba(40, 167, 69, 0.2);
    border: 1px solid rgba(40, 167, 69, 0.5);
    color: #28a745;
    padding: 12px;
    border-radius: 5px;
    margin-bottom: 20px;
    text-align: center;
}

.error-message {
    background: rgba(220, 53, 69, 0.2);
    border: 1px solid rgba(220, 53, 69, 0.5);
    color: #dc3545;
    padding: 12px;
    border-radius: 5px;
    margin-bottom: 20px;
    text-align: center;
}

#otp_inp {
    text-align: center;
    font-size: 18px;
    letter-spacing: 2px;
    width: 150px;
    margin: 0 auto 15px auto;
    display: block;
}

.button-group {
    display: flex;
    gap: 10px;
    justify-content: center;
    margin-top: 10px;
}

#resend_otp {
    background-color: #6c757d;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    flex: 1;
    max-width: 120px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
}

#resend_otp:hover {
    background-color: #545b62;
}

#cancel_otp {
    background-color: #dc3545;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    flex: 1;
    max-width: 120px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
}

#cancel_otp:hover {
    background-color: #c82333;
}

/* Main form buttons */
button[type="submit"] {
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
    margin-top: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

button[type="submit"]:hover:not(:disabled) {
    background-color: #0a0118;
    color: var(--bg-white);
    border-color: #0a0118;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(19, 3, 37, 0.15);
}

button[type="submit"]:disabled {
    background-color: #666;
    color: #999;
    border-color: #666;
    cursor: not-allowed;
    opacity: 0.6;
}

.login-link {
    text-align: center;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid rgba(19, 3, 37, 0.1);
    font-size: 11px;
    color: #130325;
}

.login-link a {
    color: #130325;
    text-decoration: none;
    font-weight: 600;
}

.login-link a:hover {
    color: #FFD736;
    text-decoration: underline;
}

/* Responsive Design */
@media (max-width: 1024px) {
    .register-container {
        margin: 30px auto 20px auto;
        max-width: 90%;
    }
}

@media (max-width: 768px) {
    .register-container {
        max-width: 600px;
        margin: 40px auto 15px auto;
        padding: 20px;
    }
    
    .form-row {
        flex-direction: column;
        gap: 0;
    }
    
    .form-row .form-group {
        margin-bottom: 12px;
    }
    
    .register-header {
        margin-bottom: 15px;
    }
}

@media (max-width: 480px) {
    .register-container {
        margin: 30px auto 10px auto;
        padding: 15px;
        max-width: none;
    }
    
    .legal-info ul {
        padding-left: 10px;
    }
    
    .button-group {
        flex-direction: column;
    }
    
    #resend_otp, #cancel_otp {
        max-width: none;
    }
    
    .register-header h1 {
        font-size: 16px;
    }
}

/* Legal Modal Styles */
.legal-modal {
    display: none;
    position: fixed;
    z-index: 10000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(4px);
    animation: fadeIn 0.3s ease;
}

.legal-modal.show {
    display: flex;
    align-items: center;
    justify-content: center;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.legal-modal-content {
    background: var(--bg-white);
    border-radius: 12px;
    width: 90%;
    max-width: 900px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
    animation: slideUp 0.3s ease;
    overflow: hidden;
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.legal-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-light);
    background: var(--bg-white);
    position: sticky;
    top: 0;
    z-index: 10;
}

.legal-modal-header h2 {
    margin: 0;
    font-size: 1.35rem;
    font-weight: 600;
    color: var(--text-dark);
    letter-spacing: -0.3px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.legal-modal-close {
    color: var(--text-light);
    font-size: 28px;
    font-weight: 300;
    cursor: pointer;
    line-height: 1;
    transition: all 0.2s ease;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
}

.legal-modal-close:hover {
    color: var(--error-red);
    background: rgba(239, 68, 68, 0.1);
}

.legal-modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 0;
    background: var(--bg-white);
}

.legal-scroll-content {
    padding: 20px;
    line-height: 1.6;
}

.legal-scroll-content .important-notice {
    background: rgba(19, 3, 37, 0.04);
    border: 1px solid var(--border-light);
    border-radius: 8px;
    padding: 14px 16px;
    margin-bottom: 20px;
}

.legal-scroll-content .important-notice h3 {
    color: var(--text-dark);
    font-size: 1.1rem;
    font-weight: 600;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.legal-scroll-content .important-notice p {
    color: var(--text-light);
    font-size: 13px;
    margin: 0;
    line-height: 1.5;
}

.legal-scroll-content .terms-section,
.legal-scroll-content .privacy-section {
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border-light);
}

.legal-scroll-content .terms-section:last-child,
.legal-scroll-content .privacy-section:last-child {
    border-bottom: none;
}

.legal-scroll-content .terms-section h2,
.legal-scroll-content .privacy-section h2 {
    color: var(--primary-dark);
    font-size: 1.1rem;
    font-weight: 600;
    margin: 0 0 12px 0;
    padding: 6px 10px;
    background: rgba(19, 3, 37, 0.04);
    border-radius: 6px;
    display: inline-block;
}

.legal-scroll-content .terms-section h3,
.legal-scroll-content .privacy-section h3 {
    color: var(--text-dark);
    font-size: 0.95rem;
    font-weight: 600;
    margin: 16px 0 8px 0;
}

.legal-scroll-content .terms-section p,
.legal-scroll-content .privacy-section p {
    color: var(--text-light);
    font-size: 13px;
    margin-bottom: 12px;
    line-height: 1.6;
}

.legal-scroll-content .terms-section ul,
.legal-scroll-content .privacy-section ul {
    padding-left: 20px;
    margin-bottom: 12px;
}

.legal-scroll-content .terms-section li,
.legal-scroll-content .privacy-section li {
    color: var(--text-light);
    font-size: 13px;
    margin-bottom: 6px;
    line-height: 1.5;
}

.legal-scroll-content .terms-section li strong,
.legal-scroll-content .privacy-section li strong {
    color: var(--text-dark);
    font-weight: 600;
}

.legal-scroll-content .contact-info {
    background: var(--bg-light);
    border: 1px solid var(--border-light);
    border-radius: 8px;
    padding: 12px 14px;
    margin-top: 12px;
}

.legal-scroll-content .contact-info p {
    margin: 6px 0;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
}

.legal-scroll-content .contact-info i {
    color: var(--primary-dark);
    width: 18px;
    font-size: 14px;
}

.legal-scroll-content .address-block {
    margin-left: 26px;
    line-height: 1.5;
    color: var(--text-light);
    font-size: 12px;
}

.legal-scroll-content .acknowledgment-box {
    background: rgba(19, 3, 37, 0.04);
    border: 1px solid var(--border-light);
    border-radius: 8px;
    padding: 14px 16px;
    margin-top: 24px;
}

.legal-scroll-content .acknowledgment-box h3 {
    color: var(--text-dark);
    font-size: 1rem;
    font-weight: 600;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.legal-scroll-content .acknowledgment-box p {
    color: var(--text-light);
    font-size: 13px;
    margin-bottom: 8px;
}

.legal-scroll-content .acknowledgment-box p:last-child {
    margin-bottom: 0;
}

/* Custom Scrollbar for Modal */
.legal-modal-body::-webkit-scrollbar {
    width: 8px;
}

.legal-modal-body::-webkit-scrollbar-track {
    background: var(--bg-light);
}

.legal-modal-body::-webkit-scrollbar-thumb {
    background: var(--primary-dark);
    border-radius: 4px;
}

.legal-modal-body::-webkit-scrollbar-thumb:hover {
    background: #0a0118;
}

.legal-modal-footer {
    padding: 14px 20px;
    border-top: 1px solid var(--border-light);
    background: var(--bg-white);
    display: flex;
    flex-direction: column;
    gap: 10px;
    position: sticky;
    bottom: 0;
    z-index: 10;
}

.scroll-indicator {
    text-align: center;
    color: var(--text-light);
    font-size: 12px;
    padding: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.scroll-indicator.hidden {
    display: none;
}

.scroll-indicator i {
    animation: bounce 1s infinite;
}

@keyframes bounce {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-4px); }
}

.btn-agree-terms,
.btn-agree-privacy {
    background: var(--primary-dark);
    color: var(--bg-white);
    border: 1.5px solid var(--primary-dark);
    padding: 10px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.btn-agree-terms:hover:not(:disabled),
.btn-agree-privacy:hover:not(:disabled) {
    background: #0a0118;
    border-color: #0a0118;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(19, 3, 37, 0.15);
}

.btn-agree-terms:disabled,
.btn-agree-privacy:disabled {
    background: var(--bg-light);
    color: var(--text-light);
    border-color: var(--border-light);
    cursor: not-allowed;
    opacity: 0.6;
}

.btn-close-modal {
    background: var(--bg-light);
    color: var(--text-dark);
    border: 1.5px solid var(--border-light);
    padding: 8px 14px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.btn-close-modal:hover {
    background: var(--border-light);
    border-color: var(--text-light);
}

@media (max-width: 768px) {
    .legal-modal-content {
        width: 95%;
        max-height: 95vh;
    }

    .legal-modal-header {
        padding: 12px 16px;
    }

    .legal-modal-header h2 {
        font-size: 1.2rem;
    }

    .legal-scroll-content {
        padding: 16px;
    }

    .legal-modal-footer {
        padding: 12px 16px;
    }
}
</style>

<script>
// Password strength calculator
function calculatePasswordStrength(password) {
    let strength = 0;
    
    // Length
    if (password.length >= 8) strength += 1;
    if (password.length >= 12) strength += 1;
    
    // Character variety
    if (/[A-Z]/.test(password)) strength += 1;
    if (/[a-z]/.test(password)) strength += 1;
    if (/[0-9]/.test(password)) strength += 1;
    
    return strength;
}

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

// Update password strength meter
function updatePasswordStrength() {
    const password = document.getElementById('password').value;
    const strength = calculatePasswordStrength(password);
    const strengthBar = document.querySelector('.password-strength-bar');
    const strengthText = document.getElementById('password-strength-text');
    
    let strengthClass = 'weak';
    let strengthMessage = 'Weak';
    
    if (strength >= 4) {
        strengthClass = 'strong';
        strengthMessage = 'Strong';
    } else if (strength >= 2) {
        strengthClass = 'medium';
        strengthMessage = 'Medium';
    }
    
    strengthBar.className = 'password-strength-bar ' + strengthClass;
    strengthBar.style.width = (strength / 4 * 100) + '%';
    strengthText.textContent = 'Password strength: ' + strengthMessage;
}

// Check if both checkboxes are checked and enable/disable register button
function checkAgreements() {
    const termsCheckbox = document.getElementById('agree_terms');
    const privacyCheckbox = document.getElementById('agree_privacy');
    const registerButton = document.getElementById('registerButton');
    
    if (termsCheckbox && privacyCheckbox && registerButton) {
        const bothChecked = termsCheckbox.checked && privacyCheckbox.checked;
        registerButton.disabled = !bothChecked;
        
        if (bothChecked) {
            registerButton.innerHTML = '<i class="fas fa-user-plus"></i> Create Account';
        } else {
            registerButton.innerHTML = '<i class="fas fa-lock"></i> Please agree to terms';
        }
    }
}

// Cancel registration function
function cancelRegistration() {
    if (confirm('Are you sure you want to cancel registration? You will need to fill out the form again.')) {
        window.location.href = 'register.php';
    }
}

// Modal Functions for Terms & Privacy Policy
function openTermsModal() {
    const modal = document.getElementById('termsModal');
    if (modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        // Reset scroll position
        const modalBody = document.getElementById('termsModalBody');
        if (modalBody) {
            modalBody.scrollTop = 0;
        }
        // Reset agree button
        const agreeBtn = document.getElementById('btnAgreeTerms');
        if (agreeBtn) {
            agreeBtn.disabled = true;
        }
        // Show scroll indicator
        const indicator = document.getElementById('termsScrollIndicator');
        if (indicator) {
            indicator.classList.remove('hidden');
        }
    }
}

function closeTermsModal() {
    const modal = document.getElementById('termsModal');
    if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
}

function openPrivacyModal() {
    const modal = document.getElementById('privacyModal');
    if (modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        // Reset scroll position
        const modalBody = document.getElementById('privacyModalBody');
        if (modalBody) {
            modalBody.scrollTop = 0;
        }
        // Reset agree button
        const agreeBtn = document.getElementById('btnAgreePrivacy');
        if (agreeBtn) {
            agreeBtn.disabled = true;
        }
        // Show scroll indicator
        const indicator = document.getElementById('privacyScrollIndicator');
        if (indicator) {
            indicator.classList.remove('hidden');
        }
    }
}

function closePrivacyModal() {
    const modal = document.getElementById('privacyModal');
    if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
}

function agreeToTerms() {
    const checkbox = document.getElementById('agree_terms');
    if (checkbox) {
        checkbox.disabled = false;
        checkbox.checked = true;
        closeTermsModal();
        checkAgreements();
    }
}

function agreeToPrivacy() {
    const checkbox = document.getElementById('agree_privacy');
    if (checkbox) {
        checkbox.disabled = false;
        checkbox.checked = true;
        closePrivacyModal();
        checkAgreements();
    }
}

// Scroll tracking for modals
document.addEventListener('DOMContentLoaded', function() {
    // Terms Modal Scroll Tracking
    const termsModalBody = document.getElementById('termsModalBody');
    const termsAgreeBtn = document.getElementById('btnAgreeTerms');
    const termsScrollIndicator = document.getElementById('termsScrollIndicator');
    
    if (termsModalBody && termsAgreeBtn) {
        termsModalBody.addEventListener('scroll', function() {
            const scrollTop = termsModalBody.scrollTop;
            const scrollHeight = termsModalBody.scrollHeight;
            const clientHeight = termsModalBody.clientHeight;
            const scrolledToBottom = scrollTop + clientHeight >= scrollHeight - 10; // 10px threshold
            
            if (scrolledToBottom) {
                termsAgreeBtn.disabled = false;
                if (termsScrollIndicator) {
                    termsScrollIndicator.classList.add('hidden');
                }
            } else {
                termsAgreeBtn.disabled = true;
                if (termsScrollIndicator) {
                    termsScrollIndicator.classList.remove('hidden');
                }
            }
        });
    }
    
    // Privacy Modal Scroll Tracking
    const privacyModalBody = document.getElementById('privacyModalBody');
    const privacyAgreeBtn = document.getElementById('btnAgreePrivacy');
    const privacyScrollIndicator = document.getElementById('privacyScrollIndicator');
    
    if (privacyModalBody && privacyAgreeBtn) {
        privacyModalBody.addEventListener('scroll', function() {
            const scrollTop = privacyModalBody.scrollTop;
            const scrollHeight = privacyModalBody.scrollHeight;
            const clientHeight = privacyModalBody.clientHeight;
            const scrolledToBottom = scrollTop + clientHeight >= scrollHeight - 10; // 10px threshold
            
            if (scrolledToBottom) {
                privacyAgreeBtn.disabled = false;
                if (privacyScrollIndicator) {
                    privacyScrollIndicator.classList.add('hidden');
                }
            } else {
                privacyAgreeBtn.disabled = true;
                if (privacyScrollIndicator) {
                    privacyScrollIndicator.classList.remove('hidden');
                }
            }
        });
    }
    
    // Close modals when clicking outside
    window.addEventListener('click', function(event) {
        const termsModal = document.getElementById('termsModal');
        const privacyModal = document.getElementById('privacyModal');
        
        if (event.target === termsModal) {
            closeTermsModal();
        }
        if (event.target === privacyModal) {
            closePrivacyModal();
        }
    });
    
    // Close modals with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeTermsModal();
            closePrivacyModal();
        }
    });
});

// Form validation for registration
document.addEventListener('DOMContentLoaded', function() {
    const registerForm = document.getElementById('registerForm');
    if (registerForm) {
        registerForm.addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const strength = calculatePasswordStrength(password);
            const termsChecked = document.getElementById('agree_terms').checked;
            const privacyChecked = document.getElementById('agree_privacy').checked;
            
            // Check password strength
            if (strength < 2) {
                e.preventDefault();
                alert('Please choose a stronger password. Your password should include uppercase letters, lowercase letters, and numbers.');
                return false;
            }
            
            // Check agreements
            if (!termsChecked) {
                e.preventDefault();
                alert('Please agree to the Terms & Conditions to create your account.');
                return false;
            }
            
            if (!privacyChecked) {
                e.preventDefault();
                alert('Please agree to the Privacy Policy to create your account.');
                return false;
            }
        });
        
        // Add event listener for password strength
        const passwordField = document.getElementById('password');
        if (passwordField) {
            passwordField.addEventListener('input', updatePasswordStrength);
        }
        
        // Add event listeners for checkbox changes
        const termsCheckbox = document.getElementById('agree_terms');
        const privacyCheckbox = document.getElementById('agree_privacy');
        
        if (termsCheckbox) {
            termsCheckbox.addEventListener('change', checkAgreements);
        }
        
        if (privacyCheckbox) {
            privacyCheckbox.addEventListener('change', checkAgreements);
        }
        
        // Initial check
        checkAgreements();
    }
    
    // Focus on OTP input if available
    const otpInput = document.getElementById('otp_inp');
    if (otpInput) {
        otpInput.focus();
    }
    
    // Auto-format OTP input (numbers only)
    if (otpInput) {
        otpInput.addEventListener('input', function(e) {
            // Remove any non-numeric characters
            e.target.value = e.target.value.replace(/[^0-9]/g, '');
            
            // Limit to 6 characters
            if (e.target.value.length > 6) {
                e.target.value = e.target.value.slice(0, 6);
            }
        });
        
        // Handle paste events
        otpInput.addEventListener('paste', function(e) {
            e.preventDefault();
            const paste = (e.clipboardData || window.clipboardData).getData('text');
            const numericPaste = paste.replace(/[^0-9]/g, '').slice(0, 6);
            e.target.value = numericPaste;
        });
    }
});

// Add smooth animations for form interactions
document.addEventListener('DOMContentLoaded', function() {
    // Animate form elements on load
    const formElements = document.querySelectorAll('.form-group, .legal-agreements');
    formElements.forEach((element, index) => {
        element.style.opacity = '0';
        element.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            element.style.transition = 'all 0.5s ease';
            element.style.opacity = '1';
            element.style.transform = 'translateY(0)';
        }, index * 100);
    });
});
</script>