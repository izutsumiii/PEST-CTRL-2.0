<?php
// Ensure output buffering is enabled so including this file early doesn't break redirects
if (session_status() === PHP_SESSION_NONE) {
<<<<<<< HEAD
    if (!ob_get_level()) ob_start();
}
require_once 'functions.php';
require_once __DIR__ . '/../config/database.php';

// FIXED: Better path detection
$currentFile = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

// Determine if we need to go up one directory
$pathPrefix = ($currentDir === 'paymongo') ? '../' : '';
=======
    // functions.php starts the session, but ensure buffer is started first
    if (!ob_get_level()) ob_start();
}
require_once 'functions.php';
require_once 'config/database.php';
// Keep PHP-only operations above; presentational HTML starts below
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PEST-CTRL - Professional Pest Control Solutions</title>
<<<<<<< HEAD
    <link rel="icon" type="image/x-icon" href="<?php echo $pathPrefix; ?>assets/uploads/pest_icon_216780.ico">
    <link href="<?php echo $pathPrefix; ?>assets/css/pest-ctrl.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="<?php echo $pathPrefix; ?>assets/script.js"></script>
    <style>
        /* Keep all your existing styles */
        .site-header {
            background: #130325;
=======
    <link rel="icon" type="image/x-icon" href="assets/uploads/pest_icon_216780.ico">
    <link href="assets/css/pest-ctrl.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="assets/script.js"></script>
    <style>
        /* PEST-CTRL Header Overrides - Keep Original Structure */
        .site-header {
            background: #130325; /* match dropdown header color */
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
            box-shadow: 0 6px 20px rgba(0,0,0,0.06);
        }
        
        .site-header .container {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 12px 18px;
            justify-content: space-between;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .header-center {
            flex: 1;
            display: flex;
            justify-content: center;
<<<<<<< HEAD
            max-width: 600px;
=======
            max-width: 600px; /* match index sizing */
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
        }
        
        .brand-logo {
            padding: 10px 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #F9F9F9;
            font-size: 26px;
            font-family: 'Libre Barcode 128 Text', monospace;
            font-weight: 400;
            text-decoration: none;
        }
        
        .brand-text {
            font-size: 18px;
            color: #F9F9F9;
            font-family: 'Libre Barcode 128 Text', monospace;
        }
        
        .search-box {
            flex: 1;
<<<<<<< HEAD
            max-width: 600px;
=======
            max-width: 600px; /* keep compact */
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
        }
        
        .search-box form {
            display: flex;
            gap: 8px;
            align-items: center;
            width: 100%;
        }
        
        .search-box input {
<<<<<<< HEAD
            flex: 1 1 0% !important;
=======
            flex: 1 1 0% !important; /* input takes remaining width */
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
            padding: 8px 12px !important;
            height: 36px !important;
            max-height: 36px !important;
            border-radius: 6px !important;
            border: 1px solid rgba(249, 249, 249, 0.3) !important;
            background: rgba(249, 249, 249, 0.1) !important;
            color: #F9F9F9 !important;
<<<<<<< HEAD
            min-width: 0;
        }

=======
            min-width: 0; /* prevent overflow */
        }

        /* Ensure header search sizing is consistent across all pages */
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
        .site-header .search-box { max-width: 600px; }
        .site-header .search-box form { width: 100%; }
        .site-header .search-box input { height: 36px; padding: 8px 12px; font-size: 14px; }
        .site-header .search-box button { height: 36px; padding: 0 10px; white-space: nowrap; flex: 0 0 auto !important; width: auto !important; }
<<<<<<< HEAD
=======
        }
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
        
        .search-box input::placeholder {
            color: rgba(249, 249, 249, 0.7);
        }
        
        .search-box button {
            padding: 0 10px !important;
            border-radius: 6px;
            background: #FFD736;
            color: #130325;
            border: none;
            height: 36px;
            flex: 0 0 auto !important;
            width: auto !important;
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .action-btn {
            background: transparent;
            border: 1px solid transparent;
            padding: 8px 12px;
            border-radius: 10px;
            cursor: pointer;
            color: #F9F9F9;
        }
        
<<<<<<< HEAD
        .cart-link { 
            position: relative; 
            display: inline-flex; 
            align-items: center; 
        }
=======
        .cart-link { position: relative; display: inline-flex; align-items: center; }
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
        
        .cart-notification {
            position: absolute !important;
            top: -8px !important;
            right: -8px !important;
            background: #FFD736 !important;
            color: #130325 !important;
            border-radius: 50% !important;
            width: 20px !important;
            height: 20px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 12px !important;
            font-weight: bold !important;
            min-width: 20px !important;
            opacity: 0;
            transform: scale(0);
            transition: all 0.3s ease;
            z-index: 1000 !important;
        }
        
        .cart-notification.show {
            opacity: 1;
            transform: scale(1);
        }
        
        .nav-links {
            display: flex;
            gap: 12px;
            align-items: center;
        }
<<<<<<< HEAD
        
        .nav-links .cart-link { 
            margin-right: 12px; 
        }
=======
        /* add a bit more space between cart and user profile */
        .nav-links .cart-link { margin-right: 12px; }
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
        
        .nav-links a {
            color: #F9F9F9;
            text-decoration: none;
            padding: 8px 10px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .nav-links a:hover {
            color: #FFD736;
            background: rgba(19, 3, 37, 0.8);
        }

<<<<<<< HEAD
=======
        /* Header notifications dropdown */
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
        .notif-bell {
            position: relative;
            color: #F9F9F9;
            cursor: pointer;
        }
<<<<<<< HEAD
        
=======
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
        .notif-popper {
            position: absolute;
            top: 100%;
            right: 0;
            width: 340px;
            background: #1a0a2e;
            border: 1px solid #2d1b4e;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.35);
            display: none;
            z-index: 1200;
            padding: 10px;
        }
<<<<<<< HEAD
        
=======
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
        .notif-popper.show { display: block; }
        .notif-item-ui { display:flex; gap:10px; padding:10px; border-radius:8px; border:1px solid rgba(255,215,54,0.2); background: rgba(255,215,54,0.06); margin: 6px 0; color:#F9F9F9; text-decoration:none; }
        .notif-item-ui:hover { background: rgba(255,215,54,0.12); }
        .notif-item-ui .icon { width:28px; height:28px; display:flex; align-items:center; justify-content:center; background: rgba(255,215,54,0.2); color:#FFD736; border-radius:6px; }
        .notif-header { display:flex; align-items:center; justify-content:space-between; color:#F9F9F9; margin-bottom:6px; }
        .notif-empty { color:#F9F9F9; opacity:0.9; text-align:center; padding:16px 8px; }
        
<<<<<<< HEAD
=======
        
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
        .cart-link {
            background: none !important;
        }
        
<<<<<<< HEAD
=======
        /* Dropdown Styles */
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
        .dropdown {
            position: relative;
            display: inline-block;
        }
        
        .action-btn {
            background: #FFD736;
            color: #130325;
            border: none;
            padding: 8px 16px;
<<<<<<< HEAD
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
=======
            border-radius: var(--radius-md);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition-normal);
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .action-btn:hover {
            background: #e6c230;
            transform: translateY(-1px);
        }
        
        .dropdown-menu {
            position: absolute;
            right: 0;
            top: 100%;
            min-width: 200px;
<<<<<<< HEAD
            background: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
=======
            background: var(--primary-light);
            border: 1px solid var(--border-secondary);
            border-radius: var(--radius-lg);
            box-shadow: 0 8px 25px var(--shadow-medium);
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
            z-index: 1000;
            margin-top: 8px;
            overflow: hidden;
            display: none;
        }
        
        .dropdown-menu.show {
            display: block;
        }
        
        .dropdown-item {
            display: block;
            padding: 12px 16px;
<<<<<<< HEAD
            color: #130325;
            text-decoration: none;
            transition: all 0.3s ease;
            border-bottom: 1px solid #f8f9fa;
=======
            color: var(--primary-dark);
            text-decoration: none;
            transition: var(--transition-normal);
            border-bottom: 1px solid var(--border-secondary);
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
        }
        
        .dropdown-item:last-child {
            border-bottom: none;
        }
        
        .dropdown-item:hover {
            background: #FFD736;
            color: #130325;
        }
        
        .nav-link {
            color: #F9F9F9 !important;
            text-decoration: none;
            padding: 8px 10px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .nav-link:hover {
            color: #FFD736 !important;
            background: rgba(19, 3, 37, 0.8) !important;
        }

<<<<<<< HEAD
=======
        /* Mobile */
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
        .mobile-menu {
            display: none;
        }
        
        .hamburger {
            display: none;
            border: none;
            background: transparent;
            font-size: 22px;
            color: #F9F9F9;
        }

        @media (max-width: 900px) {
            .header-center {
                display: none;
            }
            .header-right .nav-links {
                display: none;
            }
            .hamburger {
                display: block;
            }
            .brand-text {
                font-size: 16px;
            }
            .site-header .container {
                justify-content: space-between;
            }
        }

<<<<<<< HEAD
=======
        /* Mobile drawer */
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
        .mobile-drawer {
            position: fixed;
            top: 0;
            left: -100%;
            width: 280px;
            height: 100vh;
            background: #F9F9F9;
            box-shadow: 6px 0 18px rgba(0,0,0,0.08);
            transition: all 280ms ease;
            z-index: 1050;
            padding: 18px;
        }
        
        .mobile-drawer.show {
            left: 0;
        }
        
        .mobile-drawer .close-btn {
            background: transparent;
            border: none;
            font-size: 20px;
        }
        
        .mobile-drawer .links {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 12px;
        }
        
        .mobile-drawer .links a {
            color: #130325;
        }
        
<<<<<<< HEAD
=======
        /* Categories Navigation */
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
        .categories-nav {
            background: #130325;
            border-top: 1px solid #2d1b4e;
            border-bottom: 1px solid #2d1b4e;
            padding: 10px 0;
        }
        
        .categories-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: center;
            justify-content: center;
        }
        
        .category-group {
            position: relative;
        }
        
        .category-dropdown {
            position: relative;
            display: inline-block;
        }
        
        .category-btn {
            background: none;
            border: none;
            color: #ffffff;
            font-size: 14px;
            font-weight: 500;
            padding: 8px 12px;
            cursor: pointer;
            border-radius: 4px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .category-btn:hover {
            background: #2d1b4e;
            color: #FFD736;
        }
        
        .category-btn i {
            font-size: 12px;
            transition: transform 0.3s ease;
        }
        
        .category-dropdown:hover .category-btn i {
            transform: rotate(180deg);
        }
        
        .category-menu {
            position: absolute;
            top: 100%;
            left: 0;
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            min-width: 200px;
            max-width: 300px;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .category-dropdown:hover .category-menu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        
        .category-menu a {
            display: block;
            padding: 10px 15px;
            color: #130325;
            text-decoration: none;
            font-size: 13px;
            border-bottom: 1px solid #f8f9fa;
            transition: all 0.3s ease;
        }
        
        .category-menu a:last-child {
            border-bottom: none;
        }
        
        .category-menu a:hover {
            background: #130325;
            color: #FFD736;
            padding-left: 20px;
        }
        
<<<<<<< HEAD
=======
        /* Mobile categories */
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
        @media (max-width: 768px) {
            .categories-nav {
                display: none;
            }
        }
<<<<<<< HEAD
=======
        
        
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
    </style>
</head>
<body>
    <header class="site-header">
        <div class="container">
            <div class="header-left">
                <button class="hamburger" id="hamburgerBtn"><i class="fas fa-bars"></i></button>
<<<<<<< HEAD
                <a class="brand" href="<?php echo $pathPrefix; ?>index.php">
=======
                <a class="brand" href="index.php">
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
                    <div class="brand-logo">PEST-CTRL</div>
                </a>
            </div>

            <div class="header-center">
                <div class="search-box">
<<<<<<< HEAD
                    <form action="<?php echo $pathPrefix; ?>products.php" method="GET" role="search">
=======
                    <form action="products.php" method="GET" role="search">
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
                        <input name="search" type="text" placeholder="Search products...">
                        <button type="submit"><i class="fas fa-search"></i></button>
                    </form>
                </div>
            </div>

            <div class="header-right">
                <div class="nav-links">
<<<<<<< HEAD
                    <a href="<?php echo $pathPrefix; ?>index.php">Home</a>
                    <a href="<?php echo $pathPrefix; ?>products.php">Products</a>
=======
                    <a href="index.php">Home</a>
                    <a href="products.php">Products</a>
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
                    <div class="notif-bell" id="notifBell" title="Notifications">
                        <i class="fas fa-bell"></i>
                        <div class="notif-popper" id="notifPopper">
                            <div class="notif-header">
                                <strong>Notifications</strong>
<<<<<<< HEAD
                                <a href="<?php echo $pathPrefix; ?>notifications.php" style="color:#FFD736; text-decoration:none; font-size:12px;">See all</a>
=======
                                <a href="notifications.php" style="color:#FFD736; text-decoration:none; font-size:12px;">See all</a>
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
                            </div>
                            <div id="notifList"></div>
                        </div>
                    </div>
<<<<<<< HEAD
                    <a href="<?php echo $pathPrefix; ?>cart.php" class="cart-link">
=======
                    <a href="cart.php" class="cart-link">
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
                        <i class="fas fa-shopping-cart"></i>
                        <?php if (isLoggedIn()): ?>
                            <span id="cart-notification" class="cart-notification">0</span>
                        <?php endif; ?>
                    </a>
                </div>

                <?php if (isLoggedIn()): ?>
                    <div class="dropdown">
                        <?php
                            $displayName = 'Account';
                            if (!empty($_SESSION['first_name'])) $displayName = htmlspecialchars($_SESSION['first_name']);
                            elseif (!empty($_SESSION['username'])) $displayName = htmlspecialchars($_SESSION['username']);
                        ?>
                       <button class="action-btn" id="userMenuBtn"><?php echo $displayName; ?> <i class="fas fa-caret-down"></i></button>
                                    <div class="dropdown-menu" id="userMenu">
                                        <?php if (isAdmin()): ?>
<<<<<<< HEAD
                                            <a class="dropdown-item" href="<?php echo $pathPrefix; ?>admin-dashboard.php">Admin Dashboard</a>
                                        <?php elseif (isSeller()): ?>
                                            <a class="dropdown-item" href="<?php echo $pathPrefix; ?>seller-dashboard.php">Seller Dashboard</a>
                                             <a class="dropdown-item" href="<?php echo $pathPrefix; ?>seller-orders.php">View Orders</a>
                                            <a class="dropdown-item" href="<?php echo $pathPrefix; ?>sales-analytics.php">Seller Analytics</a>
                                             <a class="dropdown-item" href="<?php echo $pathPrefix; ?>manage-products.php">Manage Products</a>
                                        <?php else: ?>
                                            <a class="dropdown-item" href="<?php echo $pathPrefix; ?>user-dashboard.php">My Orders</a>
                                            <a class="dropdown-item" href="<?php echo $pathPrefix; ?>notifications.php">Notifications</a>
                                        <?php endif; ?>
                                        <a class="dropdown-item" href="<?php echo $pathPrefix; ?>edit-profile.php">My Account</a>
                                        <a class="dropdown-item" href="<?php echo $pathPrefix; ?>logout.php">Logout</a>
=======
                                            <a class="dropdown-item" href="admin-dashboard.php">Admin Dashboard</a>
                                        <?php elseif (isSeller()): ?>
                                            <a class="dropdown-item" href="seller-dashboard.php">Seller Dashboard</a>
                                             <a class="dropdown-item" href="view-orders.php">View Orders</a>
                                            <a class="dropdown-item" href="sales-analytics.php">Seller Analytics</a>
                                             <a class="dropdown-item" href="manage-products.php">Manage Products</a>
                                              <a class="dropdown-item" href="order-confirmation.php">Order-Confirmation</a>
                                        <?php else: ?>
                                            <a class="dropdown-item" href="user-dashboard.php">My Orders</a>
                                            <a class="dropdown-item" href="notifications.php">Notifications</a>
                                        <?php endif; ?>
                                        <a class="dropdown-item" href="edit-profile.php">My Account</a>
                                        <a class="dropdown-item" href="logout.php">Logout</a>
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
                                    </div>
                    </div>
                <?php else: ?>
                    <div class="nav-links">
<<<<<<< HEAD
                        <a href="<?php echo $pathPrefix; ?>login.php">Login</a>
                        <a href="<?php echo $pathPrefix; ?>register.php">Register</a>
=======
                        <a href="login.php">Login</a>
                        <a href="register.php">Register</a>
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Categories Navigation -->
        <div class="categories-nav">
            <div class="container">
                <div class="categories-container">
                    <?php
<<<<<<< HEAD
                    $stmt = $pdo->query('SELECT * FROM categories ORDER BY name');
                    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
=======
                    // Get categories from database - same as products.php
                    $stmt = $pdo->query('SELECT * FROM categories ORDER BY name');
                    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Get main categories (parent_id is null or 0)
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
                    $mainCategories = [];
                    $subCategories = [];
                    
                    foreach($categories as $category) {
<<<<<<< HEAD
=======
                        // Remove emojis and clean up names - same logic as products.php
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
                        $cleanName = preg_replace('/^[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}\s]+/u', '', (string)$category['name']);
                        $cleanName = html_entity_decode($cleanName, ENT_QUOTES, 'UTF-8');
                        $category['clean_name'] = trim($cleanName);
                        
                        if(empty($category['parent_id']) || $category['parent_id'] == 0) {
                            $mainCategories[] = $category;
                        } else {
                            $subCategories[] = $category;
                        }
                    }
                    
<<<<<<< HEAD
                    foreach($mainCategories as $mainCategory) {
=======
                    // Display main categories as dropdowns
                    foreach($mainCategories as $mainCategory) {
                        // Get subcategories for this main category
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
                        $subCats = array_filter($subCategories, function($cat) use ($mainCategory) {
                            return $cat['parent_id'] == $mainCategory['id'];
                        });
                        
                        echo '<div class="category-group">';
                        echo '<div class="category-dropdown">';
                        echo '<button class="category-btn">' . htmlspecialchars($mainCategory['clean_name']) . ' <i class="fas fa-caret-down"></i></button>';
                        echo '<div class="category-menu">';
                        
<<<<<<< HEAD
                        echo '<a href="' . $pathPrefix . 'products.php?categories[]=' . $mainCategory['id'] . '">' . htmlspecialchars($mainCategory['clean_name']) . '</a>';
                        
                        foreach($subCats as $subCat) {
                            echo '<a href="' . $pathPrefix . 'products.php?categories[]=' . $subCat['id'] . '">' . htmlspecialchars($subCat['clean_name']) . '</a>';
=======
                        // Add main category link first
                        echo '<a href="products.php?categories[]=' . $mainCategory['id'] . '">' . htmlspecialchars($mainCategory['clean_name']) . '</a>';
                        
                        // Add subcategories
                        foreach($subCats as $subCat) {
                            echo '<a href="products.php?categories[]=' . $subCat['id'] . '">' . htmlspecialchars($subCat['clean_name']) . '</a>';
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
                        }
                        
                        echo '</div>';
                        echo '</div>';
                        echo '</div>';
                    }
                    ?>
                </div>
            </div>
        </div>
    </header>

    <!-- Mobile Drawer -->
    <nav class="mobile-drawer" id="mobileDrawer">
        <div style="display:flex;align-items:center;justify-content:space-between">
<<<<<<< HEAD
            <a class="brand" href="<?php echo $pathPrefix; ?>index.php"><div class="brand-logo">PEST-CTRL</div></a>
            <button class="close-btn" id="drawerClose"><i class="fas fa-times"></i></button>
        </div>
        <div class="links">
            <a href="<?php echo $pathPrefix; ?>index.php">Home</a>
            <a href="<?php echo $pathPrefix; ?>products.php">Products</a>
            <a href="<?php echo $pathPrefix; ?>cart.php" class="cart-link">
=======
            <a class="brand" href="index.php"><div class="brand-logo">PEST-CTRL</div></a>
            <button class="close-btn" id="drawerClose"><i class="fas fa-times"></i></button>
        </div>
        <div class="links">
            <a href="index.php">Home</a>
            <a href="products.php">Products</a>
            <a href="cart.php" class="cart-link">
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
                Cart
                <?php if (isLoggedIn()): ?>
                    <span id="cart-notification-mobile" class="cart-notification">0</span>
                <?php endif; ?>
            </a>
            <?php if (isLoggedIn()): ?>
                <?php if (isSeller()): ?>
<<<<<<< HEAD
                    <a href="<?php echo $pathPrefix; ?>seller-dashboard.php">Seller Dashboard</a>
                <?php elseif (isAdmin()): ?>
                    <a href="<?php echo $pathPrefix; ?>admin-dashboard.php">Admin Dashboard</a>
                <?php else: ?>
                    <a href="<?php echo $pathPrefix; ?>user-dashboard.php">My Orders</a>
                <?php endif; ?>
                <a href="<?php echo $pathPrefix; ?>edit-profile.php">Edit Profile</a>
                <a href="<?php echo $pathPrefix; ?>notifications.php">Notifications</a>
                <a href="<?php echo $pathPrefix; ?>logout.php">Logout</a>
            <?php else: ?>
                <a href="<?php echo $pathPrefix; ?>login.php">Login</a>
                <a href="<?php echo $pathPrefix; ?>register.php">Register</a>
=======
                    <a href="seller-dashboard.php">Seller Dashboard</a>
                <?php elseif (isAdmin()): ?>
                    <a href="admin-dashboard.php">Admin Dashboard</a>
                <?php else: ?>
                    <a href="user-dashboard.php">My Orders</a>
                <?php endif; ?>
                <a href="edit-profile.php">Edit Profile</a>
                <a href="notifications.php">Notifications</a>
                <a href="logout.php">Logout</a>
            <?php else: ?>
                <a href="login.php">Login</a>
                <a href="register.php">Register</a>
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
            <?php endif; ?>
        </div>
    </nav>

    <script>
        // Mobile drawer toggle
        (function(){
            const hb = document.getElementById('hamburgerBtn');
            const drawer = document.getElementById('mobileDrawer');
            const closeBtn = document.getElementById('drawerClose');
            const userMenuBtn = document.getElementById('userMenuBtn');
            const userMenu = document.getElementById('userMenu');
            if (hb && drawer) hb.addEventListener('click', ()=> drawer.classList.add('show'));
            if (closeBtn && drawer) closeBtn.addEventListener('click', ()=> drawer.classList.remove('show'));
            if (userMenuBtn && userMenu) {
                userMenuBtn.addEventListener('click', (e)=> {
                    e.stopPropagation();
                    userMenu.classList.toggle('show');
                });
                document.addEventListener('click', (e)=>{
                    if (!userMenu.contains(e.target) && e.target !== userMenuBtn) {
                        userMenu.classList.remove('show');
                    }
                });
            }
        })();
<<<<<<< HEAD
        
=======
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
        // Notifications header popup
        (function(){
            const bell = document.getElementById('notifBell');
            const pop = document.getElementById('notifPopper');
            const list = document.getElementById('notifList');
            if (!bell || !pop || !list) return;
            function closePop(e){ if (!pop.contains(e.target) && e.target !== bell && !bell.contains(e.target)) pop.classList.remove('show'); }
            bell.addEventListener('click', function(){
                if (pop.classList.contains('show')) { pop.classList.remove('show'); return; }
<<<<<<< HEAD
                const notifUrl = '<?php echo $pathPrefix; ?>notifications.php?as=json';
                fetch(notifUrl, { credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(data => {
                        list.innerHTML = '';
                        
                        // Check if user is not logged in
                        if (data && data.success === false && data.message === 'Not logged in') {
                            list.innerHTML = '<div class="notif-empty">Please log in to view notifications.</div>';
                            pop.classList.add('show');
                            setTimeout(()=> document.addEventListener('click', closePop, { once:true }), 0);
                            return;
                        }
                        
=======
                fetch('notifications.php?as=json', { credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(data => {
                        list.innerHTML = '';
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
                        const items = (data && data.items) ? data.items.slice(0,6) : [];
                        if (!items.length) {
                            list.innerHTML = '<div class="notif-empty">No notifications yet.</div>';
                        } else {
                            items.forEach(it => {
<<<<<<< HEAD
                                const url = '<?php echo $pathPrefix; ?>user-dashboard.php#order-' + it.order_id;
                                const item = document.createElement('a');
                                item.href = url;
                                item.className = 'notif-item-ui';
                                
                                if (it.status === 'notification') {
                                    // This is a custom notification
                                    item.innerHTML = '<div class="icon"><i class="fas fa-info-circle"></i></div>'+
                                                     '<div style="flex:1;"><div style="font-weight:700; color:#F9F9F9;">' + it.message + '</div>'+
                                                     '<div style="opacity:0.9; font-size:12px; color:#F9F9F9;">' + it.updated_at_human + '</div></div>';
                                } else {
                                    // This is an order status update
                                    item.innerHTML = '<div class="icon"><i class="fas fa-bell"></i></div>'+
                                                     '<div style="flex:1;"><div style="font-weight:700; color:#F9F9F9;">Order #' + it.order_id + ' update</div>'+
                                                     '<div style="opacity:0.9; font-size:12px; color:#F9F9F9;">Status: ' + it.status + ' • ' + it.updated_at_human + '</div></div>';
                                }
=======
                                const url = 'user-dashboard.php#order-' + it.order_id;
                                const item = document.createElement('a');
                                item.href = url;
                                item.className = 'notif-item-ui';
                                item.innerHTML = '<div class="icon"><i class="fas fa-bell"></i></div>'+
                                                 '<div style="flex:1;"><div style="font-weight:700; color:#F9F9F9;">Order #' + it.order_id + ' update</div>'+
                                                 '<div style="opacity:0.9; font-size:12px; color:#F9F9F9;">Status: ' + it.status + ' • ' + it.updated_at_human + '</div></div>';
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
                                list.appendChild(item);
                            });
                        }
                        pop.classList.add('show');
                        setTimeout(()=> document.addEventListener('click', closePop, { once:true }), 0);
                    })
<<<<<<< HEAD
                    .catch((error)=>{
                        console.error('Notification fetch error:', error);
                        list.innerHTML = '<div class="notif-empty">Please log in to view notifications.</div>';
=======
                    .catch(()=>{
                        list.innerHTML = '<div class="notif-empty">Unable to load notifications.</div>';
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
                        pop.classList.add('show');
                        setTimeout(()=> document.addEventListener('click', closePop, { once:true }), 0);
                    });
            });
        })();
    </script>

<<<<<<< HEAD
    <main>
=======
    <main>
    <!-- Global Confirm Modal -->
    <div id="appConfirmModal" style="display:none; position:fixed; inset:0; z-index:100000;">
        <div class="app-confirm-backdrop" style="position:absolute; inset:0; background:rgba(0,0,0,0.6);"></div>
        <div class="app-confirm-content" style="position:relative; z-index:100001; max-width:420px; margin:20vh auto; background:#ffffff; color:#130325; border-radius:10px; border:2px solid #FFD736; box-shadow:0 10px 30px rgba(0,0,0,0.4); padding:18px;">
            <div class="app-confirm-title" style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
                <div style="width:36px;height:36px;display:flex;align-items:center;justify-content:center;background:#FFF4CC;border-radius:8px;color:#130325;"><i class="fas fa-question"></i></div>
                <h3 style="margin:0; font-size:16px; color:#130325;">Please Confirm</h3>
            </div>
            <div id="appConfirmMessage" style="font-size:14px; color:#130325; margin-bottom:16px;"></div>
            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button id="appConfirmCancel" style="background:#6c757d;color:#fff;border:none;border-radius:6px;padding:8px 14px;cursor:pointer;">Cancel</button>
                <button id="appConfirmOk" style="background:#FFD736;color:#130325;border:none;border-radius:6px;padding:8px 14px;cursor:pointer;font-weight:700;">Confirm</button>
            </div>
        </div>
    </div>
    <script>
    // Reusable confirmation modal for the whole site
    (function(){
        const modal = document.getElementById('appConfirmModal');
        if (!modal) return;
        const msg = document.getElementById('appConfirmMessage');
        const ok = document.getElementById('appConfirmOk');
        const cancel = document.getElementById('appConfirmCancel');
        let cleanup = null;
        window.openConfirm = function(message, onConfirm){
            if (msg) msg.textContent = message || 'Are you sure?';
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
            const onCancel = ()=>{ modal.style.display='none'; document.body.style.overflow=''; detach(); };
            const onOk = ()=>{ modal.style.display='none'; document.body.style.overflow=''; detach(); try{ onConfirm && onConfirm(); }catch(e){} };
            function detach(){ ok && ok.removeEventListener('click', onOk); cancel && cancel.removeEventListener('click', onCancel); modal.removeEventListener('click', backdrop); }
            function backdrop(e){ if (e.target.classList && e.target.classList.contains('app-confirm-backdrop')) onCancel(); }
            ok && ok.addEventListener('click', onOk);
            cancel && cancel.addEventListener('click', onCancel);
            modal.addEventListener('click', backdrop);
        };
    })();
    </script>
>>>>>>> 95b31e0291c2770ca3f15ca5a1084d2d62ce5d4d
