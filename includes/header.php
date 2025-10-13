<?php
// Ensure output buffering is enabled so including this file early doesn't break redirects
if (session_status() === PHP_SESSION_NONE) {
    if (!ob_get_level()) ob_start();
}
require_once 'functions.php';
require_once __DIR__ . '/../config/database.php';

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
            max-width: 600px;
        }
        
        .search-box form {
            display: flex;
            gap: 8px;
            align-items: center;
            width: 100%;
        }
        
        .search-box input {
            flex: 1 1 0% !important;
            padding: 8px 12px !important;
            height: 36px !important;
            max-height: 36px !important;
            border-radius: 6px !important;
            border: 1px solid rgba(249, 249, 249, 0.3) !important;
            background: rgba(249, 249, 249, 0.1) !important;
            color: #F9F9F9 !important;
            min-width: 0;
        }

        .site-header .search-box { max-width: 600px; }
        .site-header .search-box form { width: 100%; }
        .site-header .search-box input { height: 36px; padding: 8px 12px; font-size: 14px; }
        .site-header .search-box button { height: 36px; padding: 0 10px; white-space: nowrap; flex: 0 0 auto !important; width: auto !important; }
        
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
            padding: 8px 10px;
            border-radius: 8px;
            transition: all 0.3s ease;
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
            width: 340px;
            background: #1a0a2e;
            border: 1px solid #2d1b4e;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.35);
            display: none;
            z-index: 1200;
            padding: 10px;
        }
        
        .notif-popper.show { display: block; }
        .notif-item-ui { display:flex; gap:10px; padding:10px; border-radius:8px; border:1px solid rgba(255,215,54,0.2); background: rgba(255,215,54,0.06); margin: 6px 0; color:#F9F9F9; text-decoration:none; }
        .notif-item-ui:hover { background: rgba(255,215,54,0.12); }
        .notif-item-ui .icon { width:28px; height:28px; display:flex; align-items:center; justify-content:center; background: rgba(255,215,54,0.2); color:#FFD736; border-radius:6px; }
        .notif-header { display:flex; align-items:center; justify-content:space-between; color:#F9F9F9; margin-bottom:6px; }
        .notif-empty { color:#F9F9F9; opacity:0.9; text-align:center; padding:16px 8px; }
        
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
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
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
            background: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
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
        
        @media (max-width: 768px) {
            .categories-nav {
                display: none;
            }
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
                    <form action="<?php echo $pathPrefix; ?>products.php" method="GET" role="search">
                        <input name="search" type="text" placeholder="Search products...">
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
                        <div class="notif-popper" id="notifPopper">
                            <div class="notif-header">
                                <strong>Notifications</strong>
                                <a href="<?php echo $pathPrefix; ?>notifications.php" style="color:#FFD736; text-decoration:none; font-size:12px;">See all</a>
                            </div>
                            <div id="notifList"></div>
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
                    <div class="dropdown">
                        <?php
                            $displayName = 'Account';
                            if (!empty($_SESSION['first_name'])) $displayName = htmlspecialchars($_SESSION['first_name']);
                            elseif (!empty($_SESSION['username'])) $displayName = htmlspecialchars($_SESSION['username']);
                        ?>
                       <button class="action-btn" id="userMenuBtn"><?php echo $displayName; ?> <i class="fas fa-caret-down"></i></button>
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
                                            <a class="dropdown-item" href="<?php echo $pathPrefix; ?>notifications.php">Notifications</a>
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
                <a href="<?php echo $pathPrefix; ?>notifications.php">Notifications</a>
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
            bell.addEventListener('click', function(){
                if (pop.classList.contains('show')) { pop.classList.remove('show'); return; }
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
                        
                        const items = (data && data.items) ? data.items.slice(0,6) : [];
                        if (!items.length) {
                            list.innerHTML = '<div class="notif-empty">No notifications yet.</div>';
                        } else {
                            items.forEach(it => {
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
                                list.appendChild(item);
                            });
                        }
                        pop.classList.add('show');
                        setTimeout(()=> document.addEventListener('click', closePop, { once:true }), 0);
                    })
                    .catch((error)=>{
                        console.error('Notification fetch error:', error);
                        list.innerHTML = '<div class="notif-empty">Please log in to view notifications.</div>';
                        pop.classList.add('show');
                        setTimeout(()=> document.addEventListener('click', closePop, { once:true }), 0);
                    });
            });
        })();
    </script>

    <main>