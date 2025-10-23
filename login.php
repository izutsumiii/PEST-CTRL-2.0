<?php 
require_once 'includes/functions.php';

if (isLoggedIn()) {     
    $user_type = $_SESSION['user_type'];
    switch($user_type) {
        case 'admin':
            header("Location: admin/admin-dashboard.php");
            break;
        case 'seller':
            header("Location: seller-dashboard.php");
            break;
        default:
            header("Location: index.php");
    }
    exit(); 
}  
?>  

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - PEST CTRL</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Libre+Barcode+128+Text&display=swap" rel="stylesheet">
    <style>
        @import url('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css');
        @import url('https://fonts.googleapis.com/css2?family=Libre+Barcode+128+Text&display=swap');

        :root {
            --primary-dark: #130325;
            --primary-light: #F9F9F9;
            --accent-yellow: #FFD736;
            --border-secondary: rgba(249, 249, 249, 0.3);
            --shadow-dark: rgba(0, 0, 0, 0.3);
            --font-primary: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, var(--primary-dark) 0%, rgba(19, 3, 37, 0.9) 100%);
            min-height: 100vh;
            font-family: var(--font-primary);
            overflow-x: hidden;
        }

        /* Header Styling */
        .simple-header {
            background: #ffffff;
            padding: 15px 40px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 10px rgba(19, 3, 37, 0.1);
        }

        .header-left {
            display: flex;
            align-items: center;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #130325;
            font-size: 32px;
            font-family: 'Libre Barcode 128 Text', monospace;
            font-weight: 400;
            text-decoration: none;
        }

        .header-login {
            font-size: 22px;
            font-weight: 700;
            color: #130325;
        }

        /* Main wrapper */
        .main-wrapper {
            display: flex;
            justify-content: space-between;
            align-items: center;
            min-height: calc(100vh - 70px);
            padding: 40px 80px;
            gap: 60px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Left Side Branding */
        .branding-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .main-logo {
            font-size: 80px;
            font-family: 'Libre Barcode 128 Text', monospace;
            font-weight: 400;
            color: #FFD736;
            margin-bottom: 25px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
        }

        .tagline {
            font-size: 26px;
            color: #FFD736;
            font-weight: 700;
            margin-bottom: 12px;
            opacity: 0.95;
            letter-spacing: 0.5px;
        }

        .subtagline {
            font-size: 18px;
            color: #FFD736;
            opacity: 0.75;
            font-weight: 400;
            max-width: 400px;
            line-height: 1.5;
        }

        /* LOGIN CONTAINER */
        .selector-container {
            max-width: 400px;
            width: 100%;
            padding: 30px;
            border-radius: 12px;
            background: #ffffff;
            color: #130325;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.3);
            position: relative;
            overflow: hidden;
            text-align: center;
        }
        
        .selector-header h1 {
            color: #130325;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 12px;
        }
        
        .selector-header p {
            color: #130325;
            font-size: 14px;
            opacity: 0.8;
            margin-bottom: 30px;
            font-weight: 400;
            line-height: 1.6;
        }
        
        .login-options {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .login-card {
            background: #130325;
            border-radius: 8px;
            padding: 15px 20px;
            text-decoration: none;
            color: #FFD736;
            border: 2px solid #130325;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 15px;
            min-height: 60px;
        }

        .login-card:hover {
            background: #FFD736;
            color: #130325;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 215, 54, 0.4);
        }

        .login-card i {
            font-size: 24px;
            color: #FFD736;
            transition: color 0.3s ease;
        }

        .login-card:hover i {
            color: #130325;
        }
        
        .login-card h3 {
            font-size: 16px;
            margin: 0;
            font-weight: 600;
        }
        
        .login-card p {
            font-size: 12px;
            margin: 0;
            opacity: 0.8;
        }

        .login-card.admin {
            display: none;
        }

        .login-card.admin.show {
            display: flex;
            animation: slideInFromTop 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes slideInFromTop {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.8);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .register-section {
            text-align: center;
            margin-top: 20px;
        }

        .register-section h4 {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 5px;
            color: #130325;
        }

        .register-section p {
            font-size: 12px;
            margin-bottom: 10px;
            color: #130325;
            opacity: 0.8;
        }

        .register-btn {
            color: #130325;
            font-weight: 500;
            text-decoration: none;
        }

        /* BUG LOGO */
        .hidden-admin-trigger {
            position: fixed;
            bottom: 20px;
            left: 20px;
            width: 40px;
            height: 40px;
            cursor: pointer;
            opacity: 0;
            z-index: 10;
            user-select: none;
        }

        .store-logo {
            position: fixed;
            bottom: 20px;
            left: 20px;
            width: 40px;
            height: 40px;
            border-radius: 0;
            background: transparent;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #FFD736;
            font-size: 24px;
            font-weight: 700;
            box-shadow: none;
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 5;
        }


        .store-logo.clicked {
            animation: pulse 0.3s ease-in-out;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        /* MODAL */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: #ffffff;
            color: #130325;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            padding: 28px 24px;
            border-radius: 8px;
            border: none;
            width: 90%;
            max-width: 420px;
            box-shadow: 0 4px 15px rgba(19, 3, 37, 0.1);
            text-align: center;
            animation: slideIn 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes slideIn {
            from { 
                opacity: 0;
                transform: translate(-50%, -60%) scale(0.8);
            }
            to { 
                opacity: 1;
                transform: translate(-50%, -50%) scale(1);
            }
        }

        .close {
            color: #94a3b8;
            position: absolute;
            top: 20px;
            right: 25px;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        
        .close:hover {
            color: var(--accent-yellow);
            background: rgba(249, 249, 249, 0.08);
            transform: rotate(90deg);
        }

        .modal-header i {
            font-size: 56px;
            color: #130325;
            margin-bottom: 20px;
        }

        .modal-header h2 {
            font-size: 28px;
            margin-bottom: 12px;
            color: #130325;
            font-weight: 700;
        }

        .modal-header p {
            color: #130325;
            opacity: 0.85;
            margin-bottom: 12px;
        }

        .code-input {
            font-size: 26px;
            letter-spacing: 10px;
            padding: 10px 10px;
            background: var(--primary-light);
            color: var(--primary-dark);
            border: 1px solid var(--border-secondary);
            border-radius: 10px;
            max-width: 260px;
            margin: 0 auto;
            width: 100%;
        }

        .code-input:focus {
            outline: none;
            border-color: var(--accent-yellow);
            box-shadow: 0 0 0 3px rgba(255, 215, 54, 0.2);
            background: var(--primary-light);
        }

        .error-message {
            display: none;
            background: rgba(220, 53, 69, 0.2);
            color: #dc3545;
            padding: 10px;
            border-radius: 8px;
            margin: 15px 0;
            font-size: 14px;
        }

        .modal-buttons {
            flex-direction: column;
            gap: 12px;
            display: flex;
            margin-top: 16px;
        }

        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
            width: 100%;
        }

        .modal-buttons .btn-primary {
            background: var(--accent-yellow);
            color: var(--primary-dark);
            border: 1px solid var(--accent-yellow);
        }

        .modal-buttons .btn-primary:hover {
            background: #e6c230;
        }

        .modal-buttons .btn-secondary {
            background: #dc3545;
            color: #ffffff;
            border: 1px solid #dc3545;
        }

        .modal-buttons .btn-secondary:hover {
            background: #c82333;
            border-color: #c82333;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-wrapper {
                flex-direction: column;
                padding: 40px;
                gap: 40px;
            }

            .branding-section {
                order: 1;
            }

            .selector-container {
                order: 2;
                max-width: 450px;
            }
        }

        @media (max-width: 768px) {
            .simple-header {
                padding: 15px 20px;
            }

            .brand-logo {
                font-size: 26px;
            }

            .header-login {
                font-size: 18px;
            }

            .main-wrapper {
                padding: 30px 20px;
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

            .selector-container {
                max-width: 100%;
            }

            .store-logo {
                width: 35px;
                height: 35px;
                font-size: 20px;
            }

            .hidden-admin-trigger {
                width: 35px;
                height: 35px;
            }
        }
  
        /* ORIGINAL LOGIN CONTAINER - UNCHANGED */
        .selector-container {
            max-width: 380px;
            margin: 0;
            padding: 20px;
            border: none;
            border-radius: 8px;
            background: #ffffff;
            color: #130325;
            box-shadow: 0 4px 15px rgba(19, 3, 37, 0.1);
            position: relative;
            overflow: hidden;
            text-align: center;
        }
        
        .selector-header h1 {
            color: #130325;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 12px;
        }
        
        .selector-header p {
            color: #130325;
            font-size: 14px;
            opacity: 0.8;
            margin-bottom: 30px;
            font-weight: 400;
            line-height: 1.6;
        }
        
        .login-options {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .login-card {
            background: #130325;
            border-radius: 8px;
            padding: 15px 20px;
            text-decoration: none;
            color: #FFD736;
            border: 2px solid #130325;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 15px;
            min-height: 60px;
        }

        .login-card:hover {
            background: #FFD736;
            color: #130325;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 215, 54, 0.4);
        }

        .login-card i {
            font-size: 24px;
            color: #FFD736;
            transition: color 0.3s ease;
        }

        .login-card:hover i {
            color: #130325;
        }
        
        .login-card h3 {
            font-size: 16px;
            margin: 0;
            font-weight: 600;
        }
        
        .login-card p {
            font-size: 12px;
            margin: 0;
            opacity: 0.8;
        }

        .login-card.admin {
            display: none;
        }

        .login-card.admin.show {
            display: flex;
            animation: slideInFromTop 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes slideInFromTop {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.8);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .register-section {
            text-align: center;
            margin-top: 20px;
        }

        .register-section h4 {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 5px;
            color: #130325;
        }

        .register-section p {
            font-size: 12px;
            margin-bottom: 10px;
            color: #130325;
            opacity: 0.8;
        }

        .register-btn {
            color: #130325;
            font-weight: 500;
            text-decoration: none;
        }

        /* ORIGINAL BUG LOGO - UNCHANGED */
        .hidden-admin-trigger {
            position: fixed;
            bottom: 20px;
            left: 20px;
            width: 40px;
            height: 40px;
            cursor: pointer;
            opacity: 0;
            z-index: 10;
            user-select: none;
        }

        .store-logo {
            position: fixed;
            bottom: 20px;
            left: 20px;
            width: 40px;
            height: 40px;
            border-radius: 0;
            background: transparent;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #FFD736;
            font-size: 24px;
            font-weight: 700;
            box-shadow: none;
            transition: var(--transition);
            z-index: 5;
        }

        .store-logo.clicked {
            animation: pulse 0.3s ease-in-out;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        /* ORIGINAL MODAL - UNCHANGED */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: #ffffff;
            color: #130325;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            padding: 28px 24px;
            border-radius: 8px;
            border: none;
            width: 90%;
            max-width: 420px;
            box-shadow: 0 4px 15px rgba(19, 3, 37, 0.1);
            text-align: center;
            animation: slideIn 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes slideIn {
            from { 
                opacity: 0;
                transform: translate(-50%, -60%) scale(0.8);
            }
            to { 
                opacity: 1;
                transform: translate(-50%, -50%) scale(1);
            }
        }

        .close {
            color: #94a3b8;
            position: absolute;
            top: 20px;
            right: 25px;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition);
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        
        .close:hover {
            color: var(--accent-yellow);
            background: rgba(249, 249, 249, 0.08);
            transform: rotate(90deg);
        }

        .modal-header i {
            font-size: 56px;
            color: #130325;
            margin-bottom: 20px;
            animation: pulse 2s infinite;
        }

        .modal-header h2 {
            color: #130325;
            font-size: 28px;
            margin-bottom: 12px;
            font-weight: 700;
        }

        .modal-header p {
            color: #130325;
            opacity: 0.85;
        }

        .code-input {
            font-size: 26px;
            letter-spacing: 10px;
            padding: 14px 12px;
            background: var(--primary-light);
            color: var(--primary-dark);
            border: 1px solid var(--border-secondary);
            border-radius: 10px;
            max-width: 260px;
            margin: 0 auto;
            width: 100%;
        }

        .code-input:focus {
            outline: none;
            border-color: var(--accent-yellow);
            box-shadow: 0 0 0 3px rgba(255, 215, 54, 0.2);
            background: var(--primary-light);
        }

        .error-message {
            display: none;
            background: rgba(220, 53, 69, 0.2);
            color: #dc3545;
            padding: 10px;
            border-radius: 8px;
            margin: 15px 0;
            font-size: 14px;
        }

        .modal-buttons {
            flex-direction: column;
            gap: 12px;
            display: flex;
            margin-top: 16px;
        }

        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 16px;
            transition: var(--transition);
            width: 100%;
        }

        .modal-buttons .btn-primary {
            background: var(--accent-yellow);
            color: var(--primary-dark);
            border: 1px solid var(--accent-yellow);
        }

        .modal-buttons .btn-primary:hover {
            background: #e6c230;
        }

        .modal-buttons .btn-secondary {
            background: #dc3545;
            color: #ffffff;
            border: 1px solid #dc3545;
        }

        .modal-buttons .btn-secondary:hover {
            background: #c82333;
            border-color: #c82333;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .simple-header {
                padding: 15px 20px;
            }

            .header-barcode {
                font-size: 12px;
            }

            .header-logo {
                font-size: 18px;
            }

            .header-login {
                font-size: 18px;
            }

            .main-wrapper {
                justify-content: center;
                padding: 30px 20px;
            }

            .selector-container {
                max-width: 100%;
            }

            .store-logo {
                width: 35px;
                height: 35px;
                font-size: 20px;
            }

            .hidden-admin-trigger {
                width: 35px;
                height: 35px;
            }
        }
    </style>
</head>
<body>
    <!-- Header with PEST-CTRL logo -->
    <div class="simple-header">
        <div class="header-left">
            <a class="brand" href="index.php">
                <div class="brand-logo">PEST-CTRL</div>
            </a>
        </div>
        <div class="header-login">LOGIN</div>
    </div>

    <!-- Main wrapper with branding on left, login container center-right -->
    <div class="main-wrapper">
        <!-- Left Side Branding -->
        <div class="branding-section">
            <div class="main-logo">PEST-CTRL</div>
            <div class="tagline">Professional Pest Control Solutions</div>
            <div class="subtagline">Your trusted partner for effective pest management and lawn care products</div>
        </div>

        <!-- Login Container -->
        <div class="selector-container">
            <div class="selector-header">
                <h1>Welcome to PEST-CTRL</h1>
                <p>Please select your login type to access your account</p>
            </div>
            
            <div class="login-options" id="loginOptions">
                <a href="login_customer.php" class="login-card customer">
                    <i class="fas fa-shopping-cart"></i>
                    <div>
                        <h3>Customer Login</h3>
                        <p>Shop products, manage orders, and track deliveries. Access your shopping cart and order history.</p>
                    </div>
                </a>
                
                <a href="login_seller.php" class="login-card seller">
                    <i class="fas fa-store"></i>
                    <div>
                        <h3>Seller Account</h3>
                        <p>Manage your products, track sales, and handle orders. Access seller dashboard and analytics.</p>
                    </div>
                </a>
                
                <div class="login-card admin" id="adminCard" onclick="showAdminModal()">
                    <i class="fas fa-shield-alt"></i>
                    <div>
                        <h3>Admin Panel</h3>
                        <p>Administrative access to manage users, approve sellers, and oversee platform operations.</p>
                    </div>
                </div>
            </div>
            
            <div class="register-section">
                <h4>New to our platform?</h4>
                <p>Create an account to start shopping or selling on our platform</p>
                <a href="register.php">
                    <span class="register-btn">Create New Account</span>
                </a>
            </div>
        </div>
    </div>

    <!-- ORIGINAL BUG LOGO - UNCHANGED -->
    <div class="store-logo" id="storeLogo"><i class="fa fa-bug"></i></div>
    <div class="hidden-admin-trigger" id="adminTrigger"></div>

    <!-- ORIGINAL MODAL - UNCHANGED -->
    <div id="adminModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeAdminModal()">&times;</span>
            <div class="modal-header">
                <i class="fas fa-key"></i>
                <h2>Admin Security Code</h2>
                <p>Enter the 6-digit admin access code to proceed</p>
            </div>
            <input type="password" id="adminCode" class="code-input" placeholder="••••••" maxlength="6" pattern="\d{6}">
            <div class="error-message" id="errorMessage">
                <i class="fas fa-exclamation-triangle"></i> Invalid code. Access denied.
            </div>
            <div class="modal-buttons">
                <button class="btn btn-primary" onclick="verifyAdminCode()">
                    <i class="fas fa-unlock"></i> Verify
                </button>
                <button class="btn btn-secondary" onclick="goBack()">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
            </div>
        </div>
    </div>

    <script>
        let clickCount = 0;
        let clickTimer = null;
        const maxClicks = 12;
        const resetTime = 3000;

        const adminTrigger = document.getElementById('adminTrigger');
        const storeLogo = document.getElementById('storeLogo');
        const adminCard = document.getElementById('adminCard');
        const loginOptions = document.getElementById('loginOptions');

        function handleAdminTriggerClick() {
            clickCount++;
            console.log('Admin trigger clicked! Count:', clickCount);
            
            storeLogo.classList.add('clicked');
            setTimeout(() => {
                storeLogo.classList.remove('clicked');
            }, 300);
            
            if (clickTimer) {
                clearTimeout(clickTimer);
            }
            
            if (clickCount >= maxClicks) {
                showAdminEntry();
                resetClickCounter();
                return;
            }
            
            clickTimer = setTimeout(() => {
                resetClickCounter();
            }, resetTime);
        }

        function showAdminEntry() {
            adminCard.classList.add('show');
            loginOptions.classList.add('show-admin');
            showNotification('Admin access unlocked!');
        }

        function resetClickCounter() {
            clickCount = 0;
            if (clickTimer) {
                clearTimeout(clickTimer);
                clickTimer = null;
            }
        }

        function showNotification(message) {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: linear-gradient(135deg, #2c3e50, #4a6741);
                color: white;
                padding: 12px 20px;
                border-radius: 8px;
                font-size: 14px;
                font-weight: 600;
                z-index: 2000;
                opacity: 0;
                transform: translateY(-20px);
                transition: all 0.3s ease;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            `;
            notification.textContent = message;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.opacity = '1';
                notification.style.transform = 'translateY(0)';
            }, 100);
            
            setTimeout(() => {
                notification.style.opacity = '0';
                notification.style.transform = 'translateY(-20px)';
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 300);
            }, 3000);
        }

        adminTrigger.addEventListener('click', handleAdminTriggerClick);
        storeLogo.addEventListener('click', handleAdminTriggerClick);

        function showAdminModal() {
            document.getElementById('adminModal').style.display = 'block';
            document.getElementById('adminCode').focus();
            document.getElementById('errorMessage').style.display = 'none';
            document.getElementById('adminCode').value = '';
        }

        function closeAdminModal() {
            document.getElementById('adminModal').style.display = 'none';
            document.getElementById('adminCode').value = '';
            document.getElementById('errorMessage').style.display = 'none';
        }

        function verifyAdminCode() {
            const enteredCode = document.getElementById('adminCode').value;
            const correctCode = '987654';
            
            if (enteredCode === correctCode) {
                window.location.href = 'login_admin.php';
            } else {
                document.getElementById('errorMessage').style.display = 'block';
                document.getElementById('adminCode').value = '';
                document.getElementById('adminCode').focus();
            }
        }

        function goBack() {
            closeAdminModal();
            window.location.href = 'index.php';
        }

        document.getElementById('adminCode').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                verifyAdminCode();
            }
        });

        document.getElementById('adminCode').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
        });

        window.onclick = function(event) {
            const modal = document.getElementById('adminModal');
            if (event.target == modal) {
                closeAdminModal();
            }
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAdminModal();
            }
        });

        document.querySelectorAll('.login-card').forEach(card => {
            card.setAttribute('tabindex', '0');
            card.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.click();
                }
            });
        });

        window.addEventListener('beforeunload', function() {
            resetClickCounter();
            adminCard.classList.remove('show');
            loginOptions.classList.remove('show-admin');
        });
    </script>
</body>
</html>