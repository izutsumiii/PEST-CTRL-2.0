<?php
// Ensure output buffering is enabled so including this file early doesn't break redirects
if (session_status() === PHP_SESSION_NONE) {
    if (!ob_get_level()) ob_start();
}
require_once 'functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/maintenance_check.php';
// FIXED: Better path detection
$currentFile = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

// Determine if we need to go up one directory
$pathPrefix = ($currentDir === 'paymongo') ? '../' : '';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PEST-CTRL - Professional Pest Control Solutions</title>
    <link rel="icon" type="image/x-icon" href="<?php echo $pathPrefix; ?>assets/uploads/pest_icon_216780.ico">
    <link href="<?php echo $pathPrefix; ?>assets/css/pest-ctrl.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="<?php echo $pathPrefix; ?>assets/script.js"></script>
    <style>
        /* Keep all your existing styles */
        .site-header {
            background: #130325;
            box-shadow: 0 6px 20px rgba(0,0,0,0.06);
        }
        
        .site-header .container {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 0px 18px;
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
            max-width: 600px;
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
            font-size: 20px;
            font-family: 'Libre Barcode 128 Text', monospace;
            font-weight: 400;
            text-decoration: none;
        }
        
        .brand-text {
            font-size: 12px;
            color: #F9F9F9;
            font-family: 'Libre Barcode 128 Text', monospace;
        }
        
        .search-box {
            flex: 1;
            max-width: 600px;
        }
        
        .search-box form {
            display: flex;
            gap: 0;
            align-items: center;
            width: 100%;
        }
        
        .search-box select {
            padding: 7px 10px !important;
            height: 34px !important;
            border-radius: 6px 0 0 6px !important;
            border: 1px solid rgba(249, 249, 249, 0.3) !important;
            border-right: 1px solid rgba(249, 249, 249, 0.3) !important;
            background: rgba(249, 249, 249, 0.1) !important;
            color: #F9F9F9 !important;
            font-size: 13.5px !important;
            cursor: pointer !important;
            outline: none !important;
            margin-right: 0 !important;
            width: auto !important;
            min-width: 96px !important;
        }
        
        .search-box select option {
            background: #130325 !important;
            color: #F9F9F9 !important;
        }
        
        .search-box input {
            flex: 1 1 0% !important;
            padding: 7px 11px !important;
            height: 34px !important;
            max-height: 34px !important;
            border-radius: 0 !important;
            border: 1px solid rgba(249, 249, 249, 0.3) !important;
            border-left: none !important;
            border-right: none !important;
            background: rgba(249, 249, 249, 0.1) !important;
            color: #F9F9F9 !important;
            min-width: 0;
            margin-left: 0 !important;
            font-size: 13.5px !important;
        }

        .site-header .search-box { max-width: 600px; }
        .site-header .search-box form { width: 100%; }
        .site-header .search-box input { height: 34px; padding: 7px 11px; font-size: 13.5px; }
        .site-header .search-box button { height: 34px; padding: 0 10px; white-space: nowrap; flex: 0 0 auto !important; width: auto !important; font-size: 13.5px; }
        
        .search-box input::placeholder {
            color: rgba(249, 249, 249, 0.7);
        }
        
        .search-box button {
            padding: 0 12px !important;
            border-radius: 0 6px 6px 0 !important;
            background: #FFD736;
            color: #130325;
            border: none;
            border-left: 1px solid rgba(249, 249, 249, 0.3) !important;
            height: 34px;
            flex: 0 0 auto !important;
            width: auto !important;
            margin-left: 0 !important;
            font-size: 13.5px !important;
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
        
        .cart-link { 
            position: relative; 
            display: inline-flex; 
            align-items: center; 
        }
        
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
        
        .notif-badge {
            position: absolute !important;
            top: -10px !important;
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
            visibility: hidden;
        }
        
        .notif-badge.show {
            opacity: 1 !important;
            transform: scale(1) !important;
            visibility: visible !important;
            display: flex !important;
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
        
        .nav-links .cart-link { 
            margin-right: 12px; 
        }
        
        .nav-links a {
            color: #F9F9F9;
            text-decoration: none;
            padding: 6px 9px;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-size: 13px;
            font-weight: 500;
        }
        
        .nav-links a:hover {
            color: #FFD736;
            background: rgba(19, 3, 37, 0.8);
        }

        .notif-bell {
            position: relative;
            color: #F9F9F9;
            cursor: pointer;
        }
        
.notif-popper {
    position: absolute;
    top: 100%;
    right: 0;
    width: 320px;
    max-height: 360px;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.15);
    display: none;
    z-index: 1200;
    padding: 8px;
    overflow: hidden;
    flex-direction: column;
}

/* Custom scrollbar styles for notif-popper */
.notif-popper::-webkit-scrollbar {
    width: 8px;
}

.notif-popper::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.notif-popper::-webkit-scrollbar-thumb {
    background: #FFD736;
    border-radius: 10px;
}

.notif-popper::-webkit-scrollbar-thumb:hover {
    background: #e6c230;
}

/* For Firefox */
.notif-popper {
    scrollbar-width: thin;
    scrollbar-color: #FFD736 #f1f1f1;
}
        
        .notif-popper.show { display: flex; }
        /* Seller-style dropdown structure */
        .notification-header { display:flex; align-items:center; justify-content:space-between; color:#130325; padding: 6px 4px; border-bottom: 1px solid #e5e7eb; margin-bottom: 6px; flex-shrink: 0; }
        .notification-header .clear-all-btn { background: transparent; color: #dc2626; border: none; font-size: 12px; font-weight: 600; cursor: pointer; padding: 4px 8px; border-radius: 6px; }
        .notification-list { max-height: 240px; overflow-y: auto; padding-right: 2px; flex: 1; min-height: 0; }
        .notification-item { display:flex; gap:10px; padding:10px; border-radius:8px; border:1px solid #f3f4f6; background:#ffffff; margin: 4px 0; color:#130325; text-decoration:none; transition: background 0.2s ease; cursor: pointer; }
        .notification-item:hover { background:#f9fafb; }
        .notification-title { font-weight: 700; color:#130325; font-size: 13px; }
        .notification-message { color:#130325; opacity:0.9; font-size: 12px; }
        .notification-time { color:#9ca3af; font-size: 11px; margin-top: 2px; }
        .notification-icon { width:24px; height:24px; display:flex; align-items:center; justify-content:center; background: rgba(255,215,54,0.2); color:#FFD736; border-radius:6px; flex: 0 0 24px; }
        .notif-empty { color:#130325; opacity:0.7; text-align:center; padding:16px 8px; }
        
        .clear-all-btn {
            background: transparent;
            border: none;
            color: #dc2626;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }
        
        .notif-popper .notif-footer {
            border-top: 1px solid #e5e7eb;
            padding: 6px 0 0 0;
            margin-top: 4px;
            text-align: center !important;
            width: 100% !important;
            flex-shrink: 0;
        }
        
        .notif-popper .see-all-btn {
            display: inline-block !important;
            text-align: center !important;
            color: #130325 !important;
            text-decoration: none !important;
            font-size: 13px !important;
            font-weight: 600 !important;
            padding: 6px 8px !important;
            border-radius: 4px !important;
            background: transparent !important;
            margin: 0 auto !important;
        }
        
        .notif-popper .see-all-btn:hover {
            text-decoration: none !important;
            background: transparent !important;
            color: #130325 !important;
        }
        
        
        /* Removed per-item delete styling from dropdown */
        
        .cart-link {
            background: none !important;
        }
        
        .dropdown {
            position: relative;
            display: inline-block;
        }
        
        .action-btn {
            background: #FFD736;
            color: #130325;
            border: none;
            padding: 7px 14px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
        }
        
        .action-btn:hover {
            background: #e6c230;
            transform: translateY(-1px);
        }
        
        .dropdown-menu {
            position: absolute;
            right: 0;
            top: 100%;
            min-width: 220px;
            background: #ffffff;
            border: 1px solid rgba(0,0,0,0.1);
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
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
            color: #130325;
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            border-bottom: 1px solid #f8f9fa;
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
                display: flex !important;
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
        
        .categories-nav {
            background: #130325;
            border-top: 1px solid #2d1b4e;
            border-bottom: 1px solid #2d1b4e;
            padding: 0px 0;
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
            font-size: 13.5px;
            font-weight: 500;
            padding: 7px 11px;
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
            padding: 9px 14px;
            color: #130325;
            text-decoration: none;
            font-size: 12.5px;
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
        
        @media (max-width: 768px) {
            .categories-nav {
                display: none;
            }
        }
        #notifList {
    max-height: 240px;
    overflow-y: auto;
}

#notifList::-webkit-scrollbar {
    width: 6px;
}

#notifList::-webkit-scrollbar-track {
    background: transparent;
}

#notifList::-webkit-scrollbar-thumb {
    background: #FFD736;
    border-radius: 10px;
}

#notifList::-webkit-scrollbar-thumb:hover {
    background: #e6c230;
}
    </style>
</head>
<body>
    <header class="site-header">
        <div class="container">
            <div class="header-left">
                <button class="hamburger" id="hamburgerBtn"><i class="fas fa-bars"></i></button>
                <a class="brand" href="<?php echo $pathPrefix; ?>index.php">
                    <div class="brand-logo">PEST-CTRL</div>
                </a>
            </div>

            <div class="header-center">
                <div class="search-box">
                    <form action="<?php echo $pathPrefix; ?>products.php" method="GET" role="search" id="headerSearchForm">
                        <select name="search_type" id="searchTypeSelect">
                            <option value="products" <?php echo (isset($_GET['search_type']) && $_GET['search_type'] === 'sellers') ? '' : 'selected'; ?>>Products</option>
                            <option value="sellers" <?php echo (isset($_GET['search_type']) && $_GET['search_type'] === 'sellers') ? 'selected' : ''; ?>>Sellers</option>
                        </select>
                        <input name="search" type="text" placeholder="Search..." id="headerSearchInput" value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                        <button type="submit"><i class="fas fa-search"></i></button>
                    </form>
                </div>
            </div>

            <div class="header-right">
                <div class="nav-links">
                    <a href="<?php echo $pathPrefix; ?>index.php">Home</a>
                    <a href="<?php echo $pathPrefix; ?>products.php">Products</a>
                    <div class="notif-bell" id="notifBell" title="Notifications">
                        <i class="fas fa-bell"></i>
                        <?php if (isLoggedIn()): ?>
                            <span id="notif-badge" class="notif-badge" style="display: none;">0</span>
                        <?php endif; ?>
                        <div class="notif-popper" id="notifPopper">
                            <div class="notification-header">
                                <strong>Notifications</strong>
                                <button type="button" id="notifClearAll" class="clear-all-btn" title="Mark all as read">Clear All</button>
                            </div>
                            <div id="notifList" class="notification-list"></div>
                            <div class="notif-footer">
                                <a href="<?php echo $pathPrefix; ?>customer-notifications.php" class="see-all-btn">See All</a>
                            </div>
                        </div>
                    </div>
                    <a href="<?php echo $pathPrefix; ?>cart.php" class="cart-link">
                        <i class="fas fa-shopping-cart"></i>
                        <?php if (isLoggedIn()): ?>
                            <span id="cart-notification" class="cart-notification">0</span>
                        <?php endif; ?>
                    </a>
                </div>

                <?php if (isLoggedIn()): ?>
                    <?php
                    // Get user's actual name from database
                    $userName = 'User';
                    try {
                        $stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                        $stmt->execute([$_SESSION['user_id']]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($user) {
                            $userName = trim($user['first_name'] . ' ' . $user['last_name']);
                        }
                    } catch (Exception $e) {
                        // Fallback to default
                    }
                    ?>
                    <div class="dropdown">
                       <button class="action-btn" id="userMenuBtn"><?php echo htmlspecialchars($userName); ?> <i class="fas fa-caret-down"></i></button>
                                    <div class="dropdown-menu" id="userMenu">
                                        <?php if (isAdmin()): ?>
                                            <a class="dropdown-item" href="<?php echo $pathPrefix; ?>admin-dashboard.php">Admin Dashboard</a>
                                        <?php elseif (isSeller()): ?>
                                            <a class="dropdown-item" href="<?php echo $pathPrefix; ?>seller-dashboard.php">Seller Dashboard</a>
                                             <a class="dropdown-item" href="<?php echo $pathPrefix; ?>seller-orders.php">View Orders</a>
                                            <a class="dropdown-item" href="<?php echo $pathPrefix; ?>sales-analytics.php">Seller Analytics</a>
                                             <a class="dropdown-item" href="<?php echo $pathPrefix; ?>manage-products.php">Manage Products</a>
                                        <?php else: ?>
                                            <a class="dropdown-item" href="<?php echo $pathPrefix; ?>user-dashboard.php">My Orders</a>
                                            <a class="dropdown-item" href="<?php echo $pathPrefix; ?>customer-notifications.php">Notifications</a>
                                        <?php endif; ?>
                                        <a class="dropdown-item" href="<?php echo $pathPrefix; ?>edit-profile.php">My Account</a>
                                        <a class="dropdown-item" href="<?php echo $pathPrefix; ?>logout.php">Logout</a>
                                    </div>
                    </div>
                <?php else: ?>
                    <div class="nav-links">
                        <a href="<?php echo $pathPrefix; ?>login.php">Login</a>
                        <a href="<?php echo $pathPrefix; ?>register.php">Register</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Categories Navigation -->
        <div class="categories-nav">
            <div class="container">
                <div class="categories-container">
                    <?php
                    $stmt = $pdo->query('SELECT * FROM categories ORDER BY name');
                    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $mainCategories = [];
                    $subCategories = [];
                    
                    foreach($categories as $category) {
                        $cleanName = preg_replace('/^[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}\s]+/u', '', (string)$category['name']);
                        $cleanName = html_entity_decode($cleanName, ENT_QUOTES, 'UTF-8');
                        $category['clean_name'] = trim($cleanName);
                        
                        if(empty($category['parent_id']) || $category['parent_id'] == 0) {
                            $mainCategories[] = $category;
                        } else {
                            $subCategories[] = $category;
                        }
                    }
                    
                    foreach($mainCategories as $mainCategory) {
                        $subCats = array_filter($subCategories, function($cat) use ($mainCategory) {
                            return $cat['parent_id'] == $mainCategory['id'];
                        });
                        
                        echo '<div class="category-group">';
                        echo '<div class="category-dropdown">';
                        echo '<button class="category-btn">' . htmlspecialchars($mainCategory['clean_name']) . ' <i class="fas fa-caret-down"></i></button>';
                        echo '<div class="category-menu">';
                        
                        echo '<a href="' . $pathPrefix . 'products.php?categories[]=' . $mainCategory['id'] . '">' . htmlspecialchars($mainCategory['clean_name']) . '</a>';
                        
                        foreach($subCats as $subCat) {
                            echo '<a href="' . $pathPrefix . 'products.php?categories[]=' . $subCat['id'] . '">' . htmlspecialchars($subCat['clean_name']) . '</a>';
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
            <a class="brand" href="<?php echo $pathPrefix; ?>index.php"><div class="brand-logo">PEST-CTRL</div></a>
            <button class="close-btn" id="drawerClose"><i class="fas fa-times"></i></button>
        </div>
        <div class="links">
            <a href="<?php echo $pathPrefix; ?>index.php">Home</a>
            <a href="<?php echo $pathPrefix; ?>products.php">Products</a>
            <a href="<?php echo $pathPrefix; ?>cart.php" class="cart-link">
                Cart
                <?php if (isLoggedIn()): ?>
                    <span id="cart-notification-mobile" class="cart-notification">0</span>
                <?php endif; ?>
            </a>
            <?php if (isLoggedIn()): ?>
                <?php if (isSeller()): ?>
                    <a href="<?php echo $pathPrefix; ?>seller-dashboard.php">Seller Dashboard</a>
                <?php elseif (isAdmin()): ?>
                    <a href="<?php echo $pathPrefix; ?>admin-dashboard.php">Admin Dashboard</a>
                <?php else: ?>
                    <a href="<?php echo $pathPrefix; ?>user-dashboard.php">My Orders</a>
                <?php endif; ?>
                <a href="<?php echo $pathPrefix; ?>edit-profile.php">Edit Profile</a>
                <a href="<?php echo $pathPrefix; ?>customer-notifications.php">Notifications</a>
                <a href="<?php echo $pathPrefix; ?>logout.php">Logout</a>
            <?php else: ?>
                <a href="<?php echo $pathPrefix; ?>login.php">Login</a>
                <a href="<?php echo $pathPrefix; ?>register.php">Register</a>
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
        
       // Notifications header popup
(function(){
    const bell = document.getElementById('notifBell');
    const pop = document.getElementById('notifPopper');
    const list = document.getElementById('notifList');
    if (!bell || !pop || !list) return;
    function closePop(e){ if (!pop.contains(e.target) && e.target !== bell && !bell.contains(e.target)) pop.classList.remove('show'); }

    bell.addEventListener('click', function() {
        // If popup is already showing, just close it
        if (pop.classList.contains('show')) {
            pop.classList.remove('show');
            return;
        }
        
        // Show popup first for better UX
        pop.classList.add('show');
        setTimeout(()=> document.addEventListener('click', closePop, { once:true }), 0);
        
        // Fetch notifications to display (no auto mark-read)
        fetch('<?php echo $pathPrefix; ?>customer-notifications.php?as=json', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            list.innerHTML = '';
            if (data && data.success === false && data.message === 'Not logged in') {
                list.innerHTML = '<div class="notif-empty">Please log in to view notifications.</div>';
                return;
            }
            const items = (data && data.items) ? data.items.filter(x => !x.is_read).slice(0,10) : [];
            if (!items.length) {
                list.innerHTML = '<div class="notif-empty">No notifications yet.</div>';
            } else {
                items.forEach(it => {
                    const url = it.order_id ? '<?php echo $pathPrefix; ?>order-details.php?id=' + it.order_id : '<?php echo $pathPrefix; ?>customer-notifications.php';
                    const item = document.createElement('div');
                    item.className = 'notification-item';
                    item.style.position = 'relative';
                    item.dataset.orderId = it.order_id || '';
                    item.dataset.isCustom = (it.status === 'notification') ? '1' : '0';
                    let title = '';
                    let message = '';
                    let icon = it.status === 'notification' ? 'fa-info-circle' : 'fa-bell';
                    if (it.status === 'notification') {
                        title = 'Notification';
                        message = it.message;
                    } else {
                        let statusText = it.status;
                        if (it.return_status) { statusText += ' | Return: ' + it.return_status; }
                        title = 'Order #' + it.order_id + ' update';
                        message = 'Status: ' + statusText;
                    }
                    // Attach meta for mark-as-read
                    item.dataset.orderId = it.order_id || '';
                    item.dataset.isCustom = (it.status === 'notification') ? '1' : '0';

                    item.innerHTML =
                        '<div class="notification-icon"><i class="fas ' + icon + '"></i></div>'+
                        '<div style="flex:1; min-width:0;">'+
                          '<div class="notification-title">' + title + '</div>'+
                          '<div class="notification-message">' + message + '</div>'+
                          '<div class="notification-time">' + it.updated_at_human + '</div>'+
                        '</div>';
                    item.addEventListener('click', function() {
                        // Mark this notification as read, then remove from dropdown and navigate
                        const body = {
                            order_id: parseInt(item.dataset.orderId || '0', 10),
                            is_custom: item.dataset.isCustom === '1'
                        };
                        if (body.order_id > 0) {
                            try {
                                const payload = JSON.stringify(body);
                                if (navigator.sendBeacon) {
                                    const blob = new Blob([payload], { type: 'application/json' });
                                    navigator.sendBeacon('<?php echo $pathPrefix; ?>ajax/mark-notification-read.php', blob);
                                } else {
                                    fetch('<?php echo $pathPrefix; ?>ajax/mark-notification-read.php', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json' },
                                        credentials: 'same-origin',
                                        body: payload
                                    }).catch(()=>{});
                                }
                            } catch(e) {}
                            // Optimistically remove from list and update badge
                            item.remove();
                            fetch('<?php echo $pathPrefix; ?>customer-notifications.php?as=json')
                                .then(r=>r.json())
                                .then(d=>{ updateNotificationBadge((d && d.unread_count) ? d.unread_count : 0); })
                                .catch(()=>{});
                            setTimeout(function(){ window.location.href = url; }, 200);
                        } else {
                            window.location.href = url;
                        }
                    });
                    list.appendChild(item);
                });
            }
        })
        .catch((error)=>{
            console.error('Notification fetch error:', error);
            list.innerHTML = '<div class="notif-empty">Error loading notifications.</div>';
        });
    });

    // Clear all button handler (mark all as read and clear dropdown)
    const clearAllBtn = document.getElementById('notifClearAll');
    if (clearAllBtn) {
        clearAllBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            fetch('<?php echo $pathPrefix; ?>ajax/mark-all-notifications-read.php', { method: 'POST', credentials: 'same-origin' })
                .then(r => r.json())
                .then(res => {
                    if (res && res.success) {
                        list.innerHTML = '<div class="notif-empty">No notifications yet.</div>';
                        updateNotificationBadge(0);
                    }
                })
                .catch(() => {});
        });
    }
})();
// MOVED OUTSIDE - This function must be global
function updateNotificationBadge(count) {
    const badge = document.getElementById('notif-badge');
    if (!badge) return;
    
    console.log('Updating badge to:', count);
    
    if (count > 0) {
        badge.textContent = count;
        badge.style.display = 'flex';
        badge.style.visibility = 'visible';
        badge.style.opacity = '1';
        badge.classList.add('show');
    } else {
        badge.textContent = '';
        badge.style.display = 'none';
        badge.style.visibility = 'hidden';
        badge.style.opacity = '0';
        badge.classList.remove('show');
    }
}
        
function deleteNotification(orderId, button, isCustomNotification) {
    // Show loading state
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    button.disabled = true;
    
    // Make AJAX request to delete notification
    fetch('<?php echo $pathPrefix; ?>ajax/delete-notification.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            order_id: orderId,
            is_custom: isCustomNotification
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove the notification item from the list
            const notificationItem = button.closest('.notif-item-ui');
            if (notificationItem) {
                notificationItem.style.opacity = '0';
                notificationItem.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    notificationItem.remove();
                    
                    // Check if notification list is empty after deletion
                    const remainingNotifs = document.querySelectorAll('.notif-item-ui').length;
                    if (remainingNotifs === 0) {
                        list.innerHTML = '<div class="notif-empty">No notifications yet.</div>';
                    }
                    
                    // Refresh notification count from server
                    fetch('<?php echo $pathPrefix; ?>customer-notifications.php?as=json')
                        .then(r => r.json())
                        .then(data => {
                            if (data && data.success !== false) {
                                updateNotificationBadge(data.unread_count || 0);
                            }
                        });
                }, 300);
            }
        } else {
            // Show error and restore button
            button.innerHTML = '<i class="fas fa-times"></i>';
            button.disabled = false;
            alert('Error deleting notification: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        button.innerHTML = '<i class="fas fa-times"></i>';
        button.disabled = false;
        alert('Error deleting notification');
    });
}

function clearAllNotifications() {
    const list = document.getElementById('notifList');
    if (list) {
        list.innerHTML = '<div class="notif-empty">No notifications to show.</div>';
        updateNotificationBadge(0);
    }
}

// Load notification count on page load
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('notif-badge')) {
        fetch('<?php echo $pathPrefix; ?>customer-notifications.php?as=json')
            .then(r => r.json())
            .then(data => {
                console.log('Notification data:', data);
                if (data && data.success !== false) {
                    // Use unread_count from server
                    const count = data.unread_count || 0;
                    console.log('Badge count:', count);
                    updateNotificationBadge(count);
                }
            })
            .catch(error => {
                console.error('Error loading notification count:', error);
            });
    }
    
    // Also update badge every 30 seconds to stay in sync
    setInterval(function() {
        if (document.getElementById('notif-badge')) {
            fetch('<?php echo $pathPrefix; ?>customer-notifications.php?as=json')
                .then(r => r.json())
                .then(data => {
                    if (data && data.success !== false) {
                        updateNotificationBadge(data.unread_count || 0);
                    }
                })
                .catch(error => {
                    console.error('Error refreshing notification count:', error);
                });
        }
    }, 30000); // Every 30 seconds
});

// Handle search type dropdown - redirect to sellers.php if sellers selected
document.addEventListener('DOMContentLoaded', function() {
    const searchForm = document.getElementById('headerSearchForm');
    const searchTypeSelect = document.getElementById('searchTypeSelect');
    
    if (searchForm && searchTypeSelect) {
        // Store the selected value in localStorage to persist across page loads
        const savedSearchType = localStorage.getItem('headerSearchType') || 'products';
        if (savedSearchType) {
            searchTypeSelect.value = savedSearchType;
        }
        
        searchForm.addEventListener('submit', function(e) {
            const searchType = searchTypeSelect.value;
            const searchQuery = document.getElementById('headerSearchInput').value;
            
            // Save the selected search type
            localStorage.setItem('headerSearchType', searchType);
            
            if (searchType === 'sellers') {
                e.preventDefault();
                window.location.href = '<?php echo $pathPrefix; ?>sellers.php?search=' + encodeURIComponent(searchQuery);
                return false;
            }
            // For products, let the form submit normally; fields carry search and search_type
        });
        
        // Update localStorage when dropdown changes
        searchTypeSelect.addEventListener('change', function() {
            localStorage.setItem('headerSearchType', this.value);
        });
    }
});

    </script>

    <!-- Logout Confirmation Modal -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Create logout confirmation modal
        const logoutModal = document.createElement('div');
        logoutModal.id = 'logoutConfirmModal';
        logoutModal.style.cssText = 'display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center;';
        
        const modalContent = document.createElement('div');
        modalContent.style.cssText = 'background: #ffffff; border-radius: 12px; padding: 0; max-width: 400px; width: 90%; box-shadow: 0 10px 40px rgba(0,0,0,0.2); animation: slideDown 0.3s ease;';
        
        const modalHeader = document.createElement('div');
        modalHeader.style.cssText = 'background: #130325; color: #ffffff; padding: 16px 20px; border-radius: 12px 12px 0 0; display: flex; align-items: center; gap: 10px;';
        modalHeader.innerHTML = '<i class="fas fa-sign-out-alt" style="font-size: 16px; color: #FFD736;"></i><h3 style="margin: 0; font-size: 14px; font-weight: 700;">Confirm Logout</h3>';
        
        const modalBody = document.createElement('div');
        modalBody.style.cssText = 'padding: 20px; color: #130325;';
        modalBody.innerHTML = '<p style="margin: 0; font-size: 13px; line-height: 1.5; color: #130325;">Are you sure you want to logout? You will need to login again to access your account.</p>';
        
        const modalFooter = document.createElement('div');
        modalFooter.style.cssText = 'padding: 16px 24px; border-top: 1px solid #e5e7eb; display: flex; gap: 10px; justify-content: flex-end;';
        
        const cancelBtn = document.createElement('button');
        cancelBtn.textContent = 'Cancel';
        cancelBtn.style.cssText = 'padding: 8px 20px; background: #f3f4f6; color: #130325; border: 1px solid #e5e7eb; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.2s ease;';
        cancelBtn.onmouseover = function() { this.style.background = '#e5e7eb'; };
        cancelBtn.onmouseout = function() { this.style.background = '#f3f4f6'; };
        
        const confirmBtn = document.createElement('button');
        confirmBtn.textContent = 'Logout';
        confirmBtn.style.cssText = 'padding: 8px 20px; background: #130325; color: #ffffff; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.2s ease;';
        confirmBtn.onmouseover = function() { this.style.background = '#0a0218'; };
        confirmBtn.onmouseout = function() { this.style.background = '#130325'; };
        
        let logoutUrl = '';
        
        cancelBtn.onclick = function() {
            logoutModal.style.display = 'none';
        };
        
        confirmBtn.onclick = function() {
            window.location.href = logoutUrl;
        };
        
        modalFooter.appendChild(cancelBtn);
        modalFooter.appendChild(confirmBtn);
        
        modalContent.appendChild(modalHeader);
        modalContent.appendChild(modalBody);
        modalContent.appendChild(modalFooter);
        logoutModal.appendChild(modalContent);
        document.body.appendChild(logoutModal);
        
        logoutModal.onclick = function(e) {
            if (e.target === logoutModal) {
                logoutModal.style.display = 'none';
            }
        };
        
        // Intercept logout links
        document.querySelectorAll('a[href*="logout.php"]').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                logoutUrl = this.getAttribute('href');
                logoutModal.style.display = 'flex';
            });
        });
        
        // Add CSS animation
        if (!document.getElementById('logoutModalStyles')) {
            const style = document.createElement('style');
            style.id = 'logoutModalStyles';
            style.textContent = '@keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }';
            document.head.appendChild(style);
        }
    });
    </script>

    <!-- Session Activity Tracking - Auto-logout after 20 minutes of inactivity -->
    <script>
    (function() {
        let lastActivity = Date.now();
        let activityCheckInterval;
        let warningShown = false;
        const TIMEOUT = 20 * 60 * 1000; // 20 minutes in milliseconds
        const WARNING_TIME = 18 * 60 * 1000; // Show warning at 18 minutes
        const CHECK_INTERVAL = 30 * 1000; // Check every 30 seconds
        
        // Track user activity
        const activityEvents = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
        activityEvents.forEach(event => {
            document.addEventListener(event, updateActivity, true);
        });
        
        function updateActivity() {
            lastActivity = Date.now();
            warningShown = false;
            
            // Ping server to update session activity (every 2 minutes to reduce server load)
            if (Math.random() < 0.5) { // 50% chance to reduce frequency
                fetch('ajax/update-session-activity.php', {
                    method: 'GET',
                    credentials: 'same-origin'
                }).catch(() => {
                    // Ignore errors - server-side check will handle it
                });
            }
        }
        
        function checkInactivity() {
            const timeSinceActivity = Date.now() - lastActivity;
            
            // Show warning at 18 minutes
            if (timeSinceActivity >= WARNING_TIME && !warningShown) {
                warningShown = true;
                const remainingMinutes = Math.ceil((TIMEOUT - timeSinceActivity) / 60000);
                if (remainingMinutes > 0) {
                    showInactivityWarning(remainingMinutes);
                }
            }
            
            // Force logout check at 20 minutes (server will handle actual logout)
            if (timeSinceActivity >= TIMEOUT) {
                // Server-side check will redirect, but we can also reload to trigger it
                window.location.reload();
            }
        }
        
        function showInactivityWarning(remainingMinutes) {
            // Create modal matching logout confirmation design
            const overlay = document.createElement('div');
            overlay.id = 'inactivityWarningModal';
            overlay.style.cssText = 'position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 10001; opacity: 0; transition: opacity 0.2s ease;';
            
            const dialog = document.createElement('div');
            dialog.style.cssText = 'width: 360px; max-width: 90vw; background: #ffffff; border: none; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.2);';
            
            const header = document.createElement('div');
            header.style.cssText = 'padding: 8px 12px; background: #130325; color: #F9F9F9; border-bottom: none; display: flex; align-items: center; justify-content: space-between; border-radius: 12px 12px 0 0;';
            header.innerHTML = '<div style="font-size: 12px; font-weight: 800; letter-spacing: .3px; color: #F9F9F9;">Session Warning</div><button onclick="closeInactivityWarning()" style="background: transparent; border: none; color: #F9F9F9; font-size: 16px; line-height: 1; cursor: pointer;">×</button>';
            
            const body = document.createElement('div');
            body.style.cssText = 'padding: 12px; color: #130325; font-size: 12px;';
            body.innerHTML = '<p style="margin: 0; color: #130325; font-size: 12px;">Your session will expire in <strong>' + remainingMinutes + ' minute(s)</strong> due to inactivity. Please interact with the page to stay logged in.</p>';
            
            const actions = document.createElement('div');
            actions.style.cssText = 'display: flex; gap: 8px; justify-content: flex-end; padding: 0 12px 12px 12px;';
            
            const okBtn = document.createElement('button');
            okBtn.textContent = 'OK';
            okBtn.style.cssText = 'background: linear-gradient(135deg, #FFD736 0%, #FFC107 100%); color: #130325; border: none; border-radius: 8px; padding: 6px 10px; font-weight: 700; font-size: 12px; cursor: pointer;';
            okBtn.onclick = closeInactivityWarning;
            
            actions.appendChild(okBtn);
            
            dialog.appendChild(header);
            dialog.appendChild(body);
            dialog.appendChild(actions);
            overlay.appendChild(dialog);
            document.body.appendChild(overlay);
            
            // Animate in
            requestAnimationFrame(() => {
                overlay.style.opacity = '1';
            });
            
            // Auto-dismiss after 10 seconds
            setTimeout(() => {
                closeInactivityWarning();
            }, 10000);
        }
        
        function closeInactivityWarning() {
            const modal = document.getElementById('inactivityWarningModal');
            if (modal) {
                modal.style.opacity = '0';
                setTimeout(() => modal.remove(), 200);
            }
        }
        
        // Make function globally accessible
        window.closeInactivityWarning = closeInactivityWarning;
        
        // Check inactivity every 30 seconds
        activityCheckInterval = setInterval(checkInactivity, CHECK_INTERVAL);
        
        // Initial activity update
        updateActivity();
    })();
    </script>

    <main>
