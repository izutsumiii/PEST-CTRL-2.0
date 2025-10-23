<?php
require_once 'functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seller Dashboard - PEST-CTRL</title>
    <link rel="icon" type="image/x-icon" href="assets/uploads/pest_icon_216780.ico">
    <link href="assets/css/pest-ctrl.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { font-family: var(--font-primary); background-color: var(--bg-secondary); }

        :root {
            --sidebar-width: 240px;
            --sidebar-width-collapsed: 70px;
        }
        
        .sidebar {
            position: fixed;
            top: 60px;
            left: 0;
            bottom: 0;
            width: var(--sidebar-width);
            background: #130325;
            border-right: 1px solid rgba(255, 215, 54, 0.2);
            padding: 16px 12px;
            z-index: 100;
            transition: all 0.3s ease;
            overflow-y: auto;
        }
        
        .sidebar.collapsed {
            width: var(--sidebar-width-collapsed);
        }

        .sidebar-logo {
            padding: 20px 15px;
            border-bottom: 1px solid rgba(255, 215, 54, 0.15);
            margin-bottom: 20px;
            text-align: center;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .sidebar-logo a {
            color: #FFD736;
            text-decoration: none;
            font-family: 'Libre Barcode 128 Text', monospace;
            font-size: 24px;
            font-weight: 400;
            transition: all 0.3s ease;
        }
        
        .sidebar-logo a:hover {
            color: #F9F9F9;
        }
        
        .sidebar-logo .full-logo {
            transition: opacity 0.4s ease, visibility 0.4s ease, transform 0.4s ease;
        }
        
        .sidebar-logo .short-logo {
            opacity: 0;
            visibility: hidden;
            position: absolute;
            transition: opacity 0.4s ease, visibility 0.4s ease, transform 0.4s ease;
            font-size: 18px;
            top: 5%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.8);
        }
        
        .sidebar.collapsed .sidebar-logo .full-logo {
            opacity: 0;
            visibility: hidden;
            transform: scale(0.9);
        }
        
        .sidebar.collapsed .sidebar-logo .short-logo {
            opacity: 1;
            visibility: visible;
            position: absolute;
            top: 5%;
            left: 50%;
            transform: translate(-50%, -50%) scale(1);
        }

        .user-profile-section {
            margin-top: auto;
            padding: 15px 10px;
            border-top: 1px solid rgba(255, 215, 54, 0.15);
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            margin: 20px 8px 10px 8px;
            transition: all 0.3s ease;
        }
        
        .user-profile-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        
        .sidebar.collapsed .user-profile-info {
            opacity: 0;
            visibility: hidden;
        }
        
        
        .user-name {
            color: #F9F9F9;
            font-size: 14px;
            font-weight: 700;
            margin: 0;
        }
        
        .user-role {
            color: rgba(249, 249, 249, 0.7);
            font-size: 12px;
            margin: 0;
        }

        .section-title {
            color: #9ca3af;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin: 14px 8px 6px;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        
        .sidebar.collapsed .section-title {
            opacity: 0;
            visibility: hidden;
        }

        .nav-links {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 10px;
        }
        
        .nav-links > a,
        .nav-dropdown-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #F9F9F9;
            text-decoration: none;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 13px;
            width: 100%;
            transition: all 0.3s ease;
            position: relative;
            background: transparent;
            border: none;
            cursor: pointer;
            text-align: left;
        }
        
        .nav-links > a:hover,
        .nav-dropdown-toggle:hover {
            background: rgba(255, 215, 54, 0.1);
            color: #FFD736;
        }
        
        .nav-links > a.active {
            background: rgba(255, 215, 54, 0.15);
            color: #FFD736;
            border: 1px solid rgba(255, 215, 54, 0.25);
        }

        /* Dropdown Styles - Fixed positioning and sizing */
        .nav-dropdown {
            display: flex;
            flex-direction: column;
            width: 100%;
        }
        
        .nav-dropdown-toggle {
            justify-content: space-between;
        }
        
        .nav-dropdown-toggle-content {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .nav-dropdown-arrow {
            transition: transform 0.3s ease;
            font-size: 12px;
            margin-left: auto;
        }
        
        .nav-dropdown-arrow.rotated {
            transform: rotate(90deg);
        }
        
        .nav-dropdown-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding: 0;
        }
        
        .nav-dropdown-content.show {
            max-height: 300px;
            padding: 4px 0;
        }
        
        .nav-dropdown-content a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px 10px 24px;
            color: #F9F9F9;
            text-decoration: none;
            font-size: 13px;
            border-radius: 8px;
            transition: all 0.3s ease;
            background: rgba(0, 0, 0, 0.2);
            margin: 0;
            width: 100%;
        }
        
        .nav-dropdown-content a:hover {
            background: rgba(255, 215, 54, 0.1);
            color: #FFD736;
        }
        
        .nav-dropdown-content a i {
            font-size: 13px;
            width: 16px;
        }

        /* Collapsed sidebar tooltips */
        .sidebar.collapsed .nav-links > a::after,
        .sidebar.collapsed .nav-dropdown-toggle::after {
            content: attr(data-tooltip);
            position: absolute;
            left: 100%;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(19, 3, 37, 0.95);
            color: #F9F9F9;
            padding: 12px 16px;
            border-radius: 4px;
            font-size: 12px;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 1000;
            margin-left: 15px;
            border: 1px solid rgba(255, 215, 54, 0.2);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            min-width: 80px;
            text-align: center;
        }
        
        .sidebar.collapsed .nav-links > a:hover::after,
        .sidebar.collapsed .nav-dropdown-toggle:hover::after {
            opacity: 1;
            visibility: visible;
        }
        
        .sidebar.collapsed .nav-dropdown-content {
            display: none;
        }
        
        .sidebar.collapsed .nav-links > a,
        .sidebar.collapsed .nav-dropdown-toggle {
            justify-content: center;
            padding: 10px 8px;
        }
        
        .sidebar.collapsed .hide-on-collapse {
            opacity: 0;
            visibility: hidden;
            position: absolute;
            pointer-events: none;
        }

        /* Header Styles */
        .invisible-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 60px;
            background: rgba(19, 3, 37, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 215, 54, 0.2);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .header-hamburger {
            background: transparent;
            border: none;
            color: #FFD736;
            font-size: 18px;
            cursor: pointer;
            padding: 8px;
            border-radius: 4px;
            transition: all 0.3s ease;
        }
        
        .header-hamburger:hover {
            background: rgba(255, 215, 54, 0.1);
            transform: scale(1.1);
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .header-user {
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
        }
        
        .header-user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #F9F9F9;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .header-user-info:hover {
            background: rgba(255, 215, 54, 0.1);
            color: #FFD736;
        }
        
        /* Seller Notifications */
        .seller-notifications {
            position: relative;
            margin-right: 15px;
        }
        
        .notification-bell {
            position: relative;
            background: none;
            border: none;
            padding: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .notification-bell:hover {
            transform: scale(1.1);
        }
        
        .notification-bell i {
            color: #FFD736;
            font-size: 16px;
        }
        
        .notification-badge {
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: bold;
            position: absolute;
            top: -5px;
            right: -5px;
            min-width: 18px;
        }
        
        .notification-badge.hidden {
            display: none;
        }
        
        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
            width: 350px;
            max-height: 400px;
            z-index: 1000;
            display: none;
            overflow: hidden;
        }
        
        .notification-dropdown.show {
            display: block;
        }
        
        .notification-header {
            padding: 15px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .notification-header h6 {
            margin: 0;
            color: #1f2937;
            font-weight: 600;
        }
        
        .mark-all-read {
            background: none;
            border: none;
            color: #FFD736;
            font-size: 12px;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            transition: all 0.3s ease;
        }
        
        .mark-all-read:hover {
            background: rgba(255, 215, 54, 0.1);
        }
        
        .notification-list {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .notification-item {
            padding: 12px 15px;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .notification-item:hover {
            background: #f9fafb;
        }
        
        .notification-item.unread {
            background: #fef3c7;
            border-left: 3px solid #f59e0b;
        }
        
        .notification-item i {
            color: #6b7280;
            margin-top: 2px;
            font-size: 14px;
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-title {
            font-weight: 600;
            color: #1f2937;
            font-size: 13px;
            margin-bottom: 4px;
        }
        
        .notification-message {
            color: #6b7280;
            font-size: 12px;
            line-height: 1.4;
        }
        
        .notification-time {
            color: #9ca3af;
            font-size: 11px;
            margin-top: 4px;
        }
        
        .notification-type-info i { color: #17a2b8; }
        .notification-type-warning i { color: #ffc107; }
        .notification-type-success i { color: #28a745; }
        .notification-type-error i { color: #dc3545; }
        
        .header-user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #FFD736, #e6c230);
            color: #130325;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
        }
        
        .header-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: #ffffff;
            min-width: 180px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
            border-radius: 8px;
            z-index: 1000;
            display: none;
            margin-top: 8px;
        }
        
        .header-dropdown.show {
            display: block;
        }
        
        .header-dropdown a {
            display: block;
            padding: 12px 16px;
            color: #2c3e50;
            text-decoration: none;
            transition: background-color 0.3s ease;
            font-size: 14px;
        }
        
        .header-dropdown a:hover {
            background-color: #f1f1f1;
        }
        
        .header-dropdown a i {
            margin-right: 8px;
            width: 16px;
        }

        /* Main content offset */
        main {
            margin-left: var(--sidebar-width);
            margin-top: 20px;
            transition: all 0.3s ease;
        }

        @media (max-width: 768px) {
            main { margin-left: 0; }
            .sidebar { width: 100%; position: static; border-right: none; }
        }
    </style>
</head>
<body>
    <div class="invisible-header">
        <div class="header-left">
            <button class="header-hamburger" onclick="toggleSellerSidebar()">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        
        <?php if (isLoggedIn() && isSeller()): ?>
        <div class="header-right">
            <!-- Seller Notifications -->
            <div class="seller-notifications">
                <div class="notification-bell" onclick="toggleSellerNotifications()">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge" id="sellerNotificationBadge">0</span>
                </div>
                <div class="notification-dropdown" id="sellerNotificationDropdown">
                    <div class="notification-header">
                        <h6>Notifications</h6>
                    </div>
                    <div class="notification-list" id="sellerNotificationList">
                        <div class="notification-item">
                            <i class="fas fa-spinner fa-spin"></i>
                            <span style="color: #1f2937;">Loading notifications...</span>
                        </div>
                    </div>
                    <div class="notification-footer" style="text-align: center; padding: 10px; border-top: 1px solid #e5e7eb;">
                        <a href="seller-notifications.php" style="color: #1f2937; text-decoration: none; font-size: 12px;">See All</a>
                    </div>
                </div>
            </div>
            
            <div class="header-user">
                <div class="header-user-info" onclick="toggleHeaderDropdown()">
                    <div class="header-user-avatar"><?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="header-dropdown" id="headerDropdown">
                    <a href="seller-edit-profile.php"><i class="fas fa-user"></i>My Account</a>
                    <a href="logout.php"><i class="fas fa-sign-out-alt"></i>Logout</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="seller-layout">
        <aside class="sidebar" id="sellerSidebar">
            <div class="sidebar-logo">
                <a href="seller-dashboard.php">
                    <span class="full-logo barcode-text">PEST-CTRL</span>
                    <span class="short-logo barcode-text">PC</span>
                </a>
            </div>

            <?php if (isLoggedIn() && isSeller()): ?>
                <div class="user-profile-section">
                    <div class="user-profile-info">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                        <div class="user-role">Seller</div>
                    </div>
                </div>

                <div class="section-title hide-on-collapse">Overview</div>
                <div class="nav-links">
                    <a href="seller-dashboard.php" data-tooltip="Dashboard">
                        <i class="fas fa-tachometer-alt"></i>
                        <span class="hide-on-collapse">Dashboard</span>
                    </a>
                    <a href="sales-analytics.php" data-tooltip="Sales Analytics">
                        <i class="fas fa-chart-bar"></i>
                        <span class="hide-on-collapse">Sales Analytics</span>
                    </a>
                </div>

                <div class="section-title hide-on-collapse">Orders</div>
                <div class="nav-links">
                    <a href="view-orders.php" data-tooltip="View Orders">
                        <i class="fas fa-clipboard-list"></i>
                        <span class="hide-on-collapse">View Orders</span>
                    </a>
                </div>

                <div class="section-title hide-on-collapse">Return/Refund Request</div>
                <div class="nav-links">
                    <a href="seller-returns.php" data-tooltip="View Return Request">
                        <i class="fas fa-undo-alt"></i>
                        <span class="hide-on-collapse">View Return/Refund</span>
                    </a>
                </div>

                <div class="section-title hide-on-collapse">Catalog</div>
                <div class="nav-links">
                    <div class="nav-dropdown">
                        <button class="nav-dropdown-toggle" onclick="toggleNavDropdown(event, 'manage-products')" data-tooltip="Manage Products">
                            <div class="nav-dropdown-toggle-content">
                                <i class="fas fa-boxes"></i>
                                <span class="hide-on-collapse">Manage Products</span>
                            </div>
                            <i class="fas fa-chevron-right nav-dropdown-arrow hide-on-collapse" id="manage-products-arrow"></i>
                        </button>
                        <div class="nav-dropdown-content" id="manage-products-dropdown">
                            <a href="manage-products.php">
                                <i class="fas fa-plus"></i>
                                <span>Add Product</span>
                            </a>
                            <a href="view-products.php">
                                <i class="fas fa-list"></i>
                                <span>View Products</span>
                            </a>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <div class="nav-links">
                    <a href="login_seller.php">
                        <i class="fas fa-sign-in-alt"></i>
                        <span class="hide-on-collapse">Seller Login</span>
                    </a>
                </div>
            <?php endif; ?>
        </aside>
    </div>
    
    <script>
        function toggleSellerSidebar() {
            const sidebar = document.getElementById('sellerSidebar');
            const main = document.querySelector('main');
            
            sidebar.classList.toggle('collapsed');
            
            if (sidebar.classList.contains('collapsed')) {
                main.style.marginLeft = '70px';
            } else {
                main.style.marginLeft = '240px';
            }
            
            localStorage.setItem('sellerSidebarCollapsed', sidebar.classList.contains('collapsed'));
        }

        function toggleHeaderDropdown() {
            const dropdown = document.getElementById('headerDropdown');
            dropdown.classList.toggle('show');
        }

        function toggleNavDropdown(event, dropdownId) {
            event.preventDefault();
            event.stopPropagation();
            
            const dropdown = document.getElementById(dropdownId + '-dropdown');
            const arrow = document.getElementById(dropdownId + '-arrow');
            
            if (dropdown.classList.contains('show')) {
                dropdown.classList.remove('show');
                if (arrow) arrow.classList.remove('rotated');
            } else {
                document.querySelectorAll('.nav-dropdown-content.show').forEach(d => {
                    d.classList.remove('show');
                });
                document.querySelectorAll('.nav-dropdown-arrow.rotated').forEach(a => {
                    a.classList.remove('rotated');
                });
                
                dropdown.classList.add('show');
                if (arrow) arrow.classList.add('rotated');
            }
        }

        document.addEventListener('click', function(event) {
            const headerUser = document.querySelector('.header-user');
            const dropdown = document.getElementById('headerDropdown');
            
            if (headerUser && !headerUser.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });

        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('sellerSidebar');
            const main = document.querySelector('main');
            const isCollapsed = localStorage.getItem('sellerSidebarCollapsed') === 'true';
            
            if (isCollapsed) {
                sidebar.classList.add('collapsed');
                main.style.marginLeft = '70px';
            }
            
            // Load seller notifications on page load
            loadSellerNotifications();
        });

        // Seller Notification Functions
        function toggleSellerNotifications() {
            const dropdown = document.getElementById('sellerNotificationDropdown');
            const isVisible = dropdown.classList.contains('show');
            
            // Close other dropdowns
            document.getElementById('headerDropdown').classList.remove('show');
            
            if (isVisible) {
                dropdown.classList.remove('show');
            } else {
                dropdown.classList.add('show');
                loadSellerNotifications();
            }
        }

        function loadSellerNotifications() {
            fetch('ajax/get-seller-notifications.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateSellerNotificationBadge(data.unreadCount);
                        displaySellerNotifications(data.notifications);
                    }
                })
                .catch(error => {
                    console.error('Error loading notifications:', error);
                });
        }

        function updateSellerNotificationBadge(count) {
            const badge = document.getElementById('sellerNotificationBadge');
            if (count > 0) {
                badge.textContent = count;
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        }

        function displaySellerNotifications(notifications) {
            const list = document.getElementById('sellerNotificationList');
            
            if (notifications.length === 0) {
                list.innerHTML = '<div class="notification-item"><span>No notifications</span></div>';
                return;
            }
            
            list.innerHTML = notifications.map(notification => `
                <div class="notification-item ${!notification.is_read ? 'unread' : ''}" 
                     onclick="markSellerNotificationAsRead(${notification.id})">
                    <i class="fas fa-${getNotificationIcon(notification.type)} notification-type-${notification.type}"></i>
                    <div class="notification-content">
                        <div class="notification-title">${notification.title}</div>
                        <div class="notification-message">${notification.message}</div>
                        <div class="notification-time">${formatTime(notification.created_at)}</div>
                    </div>
                </div>
            `).join('');
        }

        function markSellerNotificationAsRead(notificationId) {
            fetch('ajax/mark-seller-notification-read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ notification_id: notificationId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadSellerNotifications(); // Reload to update badge
                }
            })
            .catch(error => {
                console.error('Error marking notification as read:', error);
            });
        }


        function getNotificationIcon(type) {
            switch(type) {
                case 'warning': return 'exclamation-triangle';
                case 'success': return 'check-circle';
                case 'error': return 'times-circle';
                default: return 'info-circle';
            }
        }

        function formatTime(timestamp) {
            const date = new Date(timestamp);
            const now = new Date();
            const diff = now - date;
            
            if (diff < 60000) return 'Just now';
            if (diff < 3600000) return Math.floor(diff / 60000) + 'm ago';
            if (diff < 86400000) return Math.floor(diff / 3600000) + 'h ago';
            return Math.floor(diff / 86400000) + 'd ago';
        }

        // Close notification dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const notifications = document.querySelector('.seller-notifications');
            const dropdown = document.getElementById('sellerNotificationDropdown');
            
            if (notifications && !notifications.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });
    </script>
    
    <!-- Bootstrap JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <main>