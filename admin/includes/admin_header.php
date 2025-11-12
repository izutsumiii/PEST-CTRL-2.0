<?php
// Set timezone to Philippine Time
date_default_timezone_set('Asia/Manila');

$rootPath = dirname(__DIR__, 2);
require_once $rootPath . '/includes/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - PEST-CTRL</title>
    <link rel="icon" type="image/x-icon" href="../assets/uploads/pest_icon_216780.ico">
    <link href="../assets/css/pest-ctrl.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: var(--font-primary);
            background: linear-gradient(135deg, #130325 0%, #1a0a2e 100%);
            color: #F9F9F9;
            font-size: 13px;
        }
        
        /* Sidebar layout */
        /* Sidebar wrapper should not push content below the fold */
        .admin-layout { display: contents; }
        
        /* CSS Variables for sidebar */
        :root {
            --sidebar-width: 240px;
            --sidebar-width-collapsed: 70px;
        }
        
        .sidebar {
            position: fixed;
            top: 60px; left: 0; bottom: 0;
            width: var(--sidebar-width);
            background: #130325;
            border-right: 1px solid rgba(255, 215, 54, 0.2);
            padding: 16px 12px;
            z-index: 100;
            transition: all 0.3s ease;
            overflow-y: auto;
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
            scrollbar-width: thin;
            scrollbar-color: rgba(255, 215, 54, 0.15) transparent;
        }
        
        /* Sidebar scrollbar styling - Chrome/Edge/Safari */
        .sidebar::-webkit-scrollbar {
            width: 6px;
        }
        .sidebar::-webkit-scrollbar-track {
            background: transparent;
            border-radius: 3px;
        }
        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 215, 54, 0.15);
            border-radius: 3px;
            border: 1px solid rgba(255, 215, 54, 0.05);
            transition: background 0.2s ease;
        }
        .sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 215, 54, 0.3);
            border: 1px solid rgba(255, 215, 54, 0.1);
        }
        
        .sidebar.collapsed {
            width: var(--sidebar-width-collapsed);
        }
        .sidebar .logo { text-align: center; }
        .sidebar .logo a {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            color: #F9F9F9; text-decoration: none; font-weight: 700; padding: 6px 8px;
        }
        .sidebar .section-title { color: #9ca3af; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; margin: 14px 8px 6px; flex-shrink: 0; }
        .sidebar .nav-links { display: flex; flex-direction: column; gap: 6px; margin-top: 10px; flex-shrink: 0; }
        .sidebar .nav-links a, 
        .sidebar .nav-dropdown-toggle { 
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
        }
        .sidebar .nav-links a:hover, 
        .sidebar .nav-dropdown-toggle:hover { background: rgba(255, 215, 54, 0.1); color: #FFD736; transform: none; }
        .sidebar .nav-links a.active, 
        .sidebar .nav-dropdown-toggle.active { background: rgba(255, 215, 54, 0.15); color: #FFD736; border: 1px solid rgba(255, 215, 54, 0.25); }

        /* Admin sidebar dropdown (match seller sidebar styles) */
        .nav-dropdown { 
            display: flex; 
            flex-direction: column; 
            width: 100%; 
            position: relative;
            flex-shrink: 0;
        }
        .nav-dropdown-toggle { 
            background: transparent; 
            border: none; 
            cursor: pointer; 
            text-align: left; 
            justify-content: space-between; 
            flex-shrink: 0;
        }
        .nav-dropdown-toggle-content { display: flex; align-items: center; gap: 10px; }
        .nav-dropdown-arrow { transition: transform 0.3s ease; font-size: 12px; margin-left: auto; }
        .nav-dropdown-arrow.rotated { transform: rotate(90deg); }
        .nav-dropdown-content { 
            max-height: 0; 
            overflow: hidden; 
            transition: max-height 0.3s ease; 
            display: flex; 
            flex-direction: column; 
            gap: 2px; 
            padding: 0; 
            position: relative;
            width: 100%;
        }
        .nav-dropdown-content.show { 
            max-height: calc(100vh - 200px); 
            padding: 4px 0; 
            overflow-y: auto;
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
        }
        .nav-dropdown-content.show::-webkit-scrollbar {
            width: 6px;
        }
        .nav-dropdown-content.show::-webkit-scrollbar-track {
            background: transparent;
            border-radius: 3px;
        }
        .nav-dropdown-content.show::-webkit-scrollbar-thumb {
            background: rgba(255, 215, 54, 0.2);
            border-radius: 3px;
            border: 1px solid rgba(255, 215, 54, 0.1);
        }
        .nav-dropdown-content.show::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 215, 54, 0.4);
            border: 1px solid rgba(255, 215, 54, 0.2);
        }
        
        /* Firefox scrollbar */
        .nav-dropdown-content.show {
            scrollbar-width: thin;
            scrollbar-color: rgba(255, 215, 54, 0.2) transparent;
        }
        .nav-dropdown-content a { display: flex; align-items: center; gap: 10px; padding: 10px 12px 10px 24px; color: #F9F9F9; text-decoration: none; font-size: 13px; border-radius: 8px; transition: all 0.3s ease; background: rgba(0, 0, 0, 0.2); margin: 0; width: 100%; }
        .nav-dropdown-content a:hover { background: rgba(255, 215, 54, 0.1); color: #FFD736; }
        .nav-dropdown-content a i { font-size: 13px; width: 16px; }
        
        /* Tooltip styles for collapsed sidebar */
        .sidebar.collapsed .nav-links a::after {
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
        
        .sidebar.collapsed .nav-links a:hover::after {
            opacity: 1;
            visibility: visible;
        }
        
        /* Hamburger menu button - now in logo area */
        .hamburger-btn {
            background: transparent;
            border: none;
            border-radius: 8px;
            width: 100%;
            height: 50px;
            cursor: pointer;
            z-index: 1001;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #FFD736;
            font-size: 18px;
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }
        
        .hamburger-btn:hover {
            background: rgba(255, 215, 54, 0.1);
            transform: scale(1.02);
        }
        
        .sidebar.collapsed .hamburger-btn {
            transform: rotate(180deg);
        }
        
        /* Collapsed state styles - improved to prevent glitching */
        .sidebar.collapsed .hide-on-collapse {
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
            pointer-events: none;
        }
        
        .sidebar.collapsed .nav-links a, 
        .sidebar.collapsed .nav-dropdown-toggle {
            justify-content: center;
            padding: 10px 8px;
            position: relative;
        }
        .sidebar.collapsed .nav-dropdown-content { display: none; }
        .sidebar.collapsed .nav-links a::after,
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
        .sidebar.collapsed .nav-links a:hover::after,
        .sidebar.collapsed .nav-dropdown-toggle:hover::after { opacity: 1; visibility: visible; }
        
        .sidebar.collapsed .nav-links a span {
            position: absolute;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease;
            pointer-events: none;
        }
        
        .sidebar.collapsed .section-title {
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease;
            pointer-events: none;
        }
        
        /* Ensure smooth transitions for all elements */
        .sidebar .nav-links a span {
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        
        .sidebar .section-title {
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        /* Sidebar logo */
        .sidebar .sidebar-logo {
            flex-shrink: 0;
            padding: 20px 15px;
            border-bottom: 1px solid rgba(255, 215, 54, 0.15);
            margin-bottom: 20px;
            text-align: center;
            position: relative;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .sidebar .sidebar-logo a {
            color: #FFD736;
            text-decoration: none;
            font-family: 'Libre Barcode 128 Text', monospace;
            font-size: 24px;
            font-weight: 400;
            transition: all 0.3s ease;
            display: block;
            position: relative;
            width: 100%;
        }
        
        .sidebar .sidebar-logo a:hover {
            color: #F9F9F9;
        }
        
        /* Hide full logo when collapsed, show shortened version */
        .sidebar .sidebar-logo .full-logo {
            transition: opacity 0.4s cubic-bezier(0.4, 0, 0.2, 1), 
                       visibility 0.4s cubic-bezier(0.4, 0, 0.2, 1),
                       transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .sidebar .sidebar-logo .short-logo {
            opacity: 0;
            visibility: hidden;
            position: absolute;
            transition: opacity 0.4s cubic-bezier(0.4, 0, 0.2, 1), 
                       visibility 0.4s cubic-bezier(0.4, 0, 0.2, 1),
                       transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 18px;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.8);
            white-space: nowrap;
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
            transform: translate(-50%, -50%) scale(1);
        }
        
        /* Sidebar user profile section (non-clickable) - shows background when collapsed */
        .sidebar .user-profile-section {
            flex-shrink: 0;
            margin-top: auto;
            padding: 15px 10px;
            border-top: 1px solid rgba(255, 215, 54, 0.15);
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            margin: 20px 8px 10px 8px;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .sidebar .user-profile-section .user-profile-info {
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        
        .sidebar.collapsed .user-profile-section .user-profile-info {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
        
        .sidebar .user-profile-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .sidebar .user-name {
            color: #F9F9F9;
            font-size: 14px;
            font-weight: 700;
            margin: 0;
        }
        
        .sidebar .user-role {
            color: rgba(249, 249, 249, 0.7);
            font-size: 12px;
            margin: 0;
        }
        
        .sidebar .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #FFD736, #e6c230);
            color: #130325;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            margin-bottom: 10px;
        }

        /* Full-width header design - spans over sidebar */
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
        
        /* Left section of header (hamburger menu) */
        .header-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        /* Hamburger button in header (left side) */
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
        
        /* Right section of header (user profile) */
        .header-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        /* Admin Notifications */
        .admin-notifications {
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
        
        .clear-all {
            background: none;
            border: none;
            color: #dc3545;
            font-size: 12px;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            transition: all 0.3s ease;
        }
        .clear-all:hover { background: rgba(220,53,69,0.1); }
        
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
        
        /* Status badge inside admin notifications */
        .notification-status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            margin-left: 8px;
        }
        .notification-status-order { background: #3b82f6; color: #ffffff; }
        .notification-status-return { background: #dc3545; color: #ffffff; }
        .notification-status-lowstock { background: #f97316; color: #ffffff; }
        .notification-status-processing { background: #10b981; color: #ffffff; }
        .notification-status-new-seller { background: #8b5cf6; color: #ffffff; }
        .notification-status-info { background: #17a2b8; color: #ffffff; }
        .notification-status-warning { background: #ffc107; color: #130325; }
        .notification-status-success { background: #28a745; color: #ffffff; }
        .notification-status-error { background: #dc3545; color: #ffffff; }
        
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
        
        .header-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #F9F9F9;
            text-decoration: none;
            font-weight: 700;
            font-size: 18px;
            transition: all 0.3s ease;
        }
        
        .header-logo i {
            color: #FFD736;
            font-size: 20px;
        }
        
        .header-logo .barcode-text {
            font-family: 'Libre Barcode 128 Text', monospace;
            font-size: 24px;
            font-weight: 400;
            color: #FFD736;
            transition: all 0.3s ease;
        }
        
        /* Logo animation when sidebar toggles */
        .sidebar.collapsed ~ .invisible-header .header-logo .barcode-text {
            transform: scale(0.8);
            opacity: 0.7;
        }
        
        .header-user {
            display: flex;
            align-items: center;
            gap: 15px;
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

        /* Content offset */
        main { 
            margin-left: var(--sidebar-width); 
            margin-top: 60px; /* Account for invisible header */
            transition: all 0.3s ease;
        }

        /* Main content user bar (upper-left) */
        .main-userbar { 
            display: flex; align-items: center; gap: 10px; padding: 8px 10px; 
            position: fixed; top: 10px; right: 16px; z-index: 1500;
        }
        .main-userbar .user { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; color: #F9F9F9; }
        .main-userbar .user .avatar { width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(135deg, #FFD736, #e6c230); color: #130325; display: flex; align-items: center; justify-content: center; font-weight: 800; }
        .main-userbar .menu { position: relative; }
        .main-userbar .dropdown { position: absolute; top: 120%; left: 0; background:#fff; color:#111827; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 8px 30px rgba(0,0,0,0.15); display:none; min-width:160px; z-index:2000; }
        .main-userbar .dropdown a { display:block; padding:8px 10px; color:#374151; text-decoration:none; font-size:13px; }
        .main-userbar .dropdown a:hover { background:#f3f4f6; }
        
        .logo a {
            color: #F9F9F9;
            text-decoration: none;
            font-size: 14px;
            font-family: 'Libre Barcode 128 Text', monospace;
            font-weight: 400;
        }
        
        .logo a:hover {
            color: var(--accent-yellow);
        }
        
        .admin-info {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 12px;
            color: #F9F9F9;
        }
        
        .admin-badge {
            background: linear-gradient(135deg, #FFD736, #e6c230);
            color: #130325;
            padding: 3px 8px;
            border-radius: 16px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        
        .nav-links {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        
        .nav-links a {
            color: #F9F9F9;
            text-decoration: none;
            padding: 6px 10px;
            border-radius: 5px;
            transition: all 0.3s ease;
            font-weight: 500;
            font-size: 12px;
        }
        
        .nav-links a:hover {
            background-color: rgba(255, 215, 54, 0.2);
            color: #FFD736;
            transform: translateY(-1px);
        }
        
        .nav-links a.active {
            background-color: rgba(255, 215, 54, 0.2);
            border-bottom: 2px solid #FFD736;
        }
        
        .dropdown {
            position: relative;
            display: inline-block;
        }
        
        .dropdown-content {
            display: none;
            position: absolute;
            background-color: #ffffff;
            min-width: 180px;
            padding: 6px 0;
            box-shadow: 0 6px 18px rgba(0,0,0,0.12);
            border: 1px solid #e5e7eb;
            z-index: 1;
            border-radius: 8px;
            right: 0;
            top: 100%;
            margin-top: 8px;
        }
        
        .dropdown-content a {
            color: #374151 !important;
            padding: 6px 10px;
            text-decoration: none;
            display: block;
            font-size: 12px;
            line-height: 1.2;
            transition: background-color 0.2s ease, color 0.2s ease;
        }
        
        .dropdown-content a:hover {
            background-color: #f3f4f6;
            color: #111827 !important;
            transform: none;
        }

        /* Normalize notifications footer and See All across admin */
        .notification-footer { text-align: center; padding: 10px; border-top: 1px solid #e5e7eb; }
        .notification-footer .see-all-btn { color: #1f2937; text-decoration: none; font-size: 12px; display: inline-block; }
        .notification-footer .see-all-btn:hover { text-decoration: none; background: transparent; color: #1f2937; }
        
        .dropdown.show .dropdown-content {
            display: block;
        }
        
        .user-menu {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            color: #F9F9F9;
            transition: all 0.3s ease;
        }
        
        .user-menu:hover {
            color: #FFD736;
        }
        
        .user-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: linear-gradient(135deg, #FFD736, #e6c230);
            color: #130325;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 12px;
        }
        
        @media (max-width: 768px) {
            nav {
                flex-direction: column;
                gap: 12px;
                padding: 0.75rem;
            }
            
            .nav-links {
                flex-wrap: wrap;
                justify-content: center;
                gap: 12px;
            }
            
            .admin-info {
                order: -1;
            }
        }

        /* Admin page shared styles to mirror seller theme */
        .page-header {
            max-width: 1200px;
            margin: -40px auto 14px auto; /* top spacing knob A */
            padding: 0 14px;
        }
        .page-header h1 {
            color: #F9F9F9;
            margin-bottom: 4px;
            font-size: 18px;
        }
        .page-header p {
            color: rgba(249, 249, 249, 0.7);
            font-size: 12px;
        }

        .stats {
            max-width: 1200px;
            margin: 0 auto 16px auto;
            padding: 0 14px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }
        .stat-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 12px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            border-left: 3px solid #007bff;
            color: #F9F9F9;
        }
        .stat-card h3 { color: #F9F9F9; font-weight: 600; font-size: 12px; }
        .stat-card p { color: #FFD736; font-weight: 800; font-size: 1.1rem; }

        .admin-sections {
            max-width: 1200px;
            margin: 0 auto 24px auto;
            padding: 0 14px;
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }
        /* Generic page content container for spacing around tables */
        .admin-content { max-width: 1200px; margin: 0 auto; padding: 0 18px; }
        .admin-sections .section {
            background: #ffffff;
            padding: 12px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            color: #2c3e50;
        }
        .admin-sections .section h2 { color: #2c3e50; margin-bottom: 8px; font-size: 14px; }
        .admin-sections .section p { color: #7f8c8d; font-size: 12px; }

        /* Table styling inside admin sections - match Products page */
        .admin-sections .section table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
            color: #2c3e50;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .admin-sections .section table thead th {
            background: #34495e;
            color: #ffffff;
            text-align: left;
            padding: 15px;
            font-weight: 600;
            border-bottom: 1px solid #2e3f50;
            font-size: 14px;
        }
        .admin-sections .section table tbody td {
            padding: 15px;
            border-bottom: 1px solid #ecf0f1;
            font-size: 13px;
            vertical-align: top;
            color: #1f2937; /* darker text */
        }
        .admin-sections .section table tbody td small { color: #1f2937; }
        .admin-sections .section table tbody td strong { color: #111827; }
        .admin-sections .section table tbody tr:hover {
            background: #f8f9fa;
        }
        /* Dashboard table action buttons */
        .admin-sections .section table td:last-child a {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            margin-right: 8px;
            color: #ffffff;
        }
        .admin-sections .section table td:last-child a[href*="approve"] { background: #28a745; }
        .admin-sections .section table td:last-child a[href*="approve"]:hover { background: #218838; }
        .admin-sections .section table td:last-child a[href*="reject"] { background: #dc3545; }
        .admin-sections .section table td:last-child a[href*="reject"]:hover { background: #c82333; }
        .admin-sections .section a { color: #3498db; text-decoration: none; font-weight: 600; }
        .admin-sections .section a:hover { color: #2980b9; text-decoration: underline; }

        /* Generic admin tables used across pages */
        .table-container {
            margin: 12px 0;
            overflow-x: auto;
        }
        /* Generic admin tables - match Products page */
        table.admin-table {
            width: 100%;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-collapse: collapse;
        }
        table.admin-table thead th {
            background: #34495e;
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }
        table.admin-table td {
            padding: 15px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: top;
            color: #1f2937; /* darker text */
        }
        table.admin-table td small { color: #1f2937; }
        table.admin-table td strong { color: #111827; }
        table.admin-table tr:hover {
            background: #f8f9fa;
        }

        /* Status badges (customers, sellers) */
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.2px;
        }
        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }

        .status-pending { background: #fff3cd; color: #856404; }
        .status-approved { background: #d4edda; color: #155724; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        .status-suspended { background: #d1ecf1; color: #0c5460; }
        .status-banned { background: #f8d7da; color: #721c24; }

        .verified { color: #16a34a; font-weight: 600; }

        /* Action buttons in tables (match Products page styles) */
        .action-buttons { display: flex; flex-wrap: wrap; gap: 6px; }
        .action-buttons a, .action-buttons button, .btn-view, .btn-approve, .btn-reject,
        .btn-suspend, .btn-ban, .btn-verify, .btn-unverify, .btn-upload,
        .btn-activate, .btn-deactivate, .btn-delete, .btn-reactivate {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            border: none;
            transition: all 0.3s ease;
            cursor: pointer;
            white-space: nowrap;
            color: #ffffff;
        }
        .btn-view { background: #17a2b8; }
        .btn-approve, .btn-activate { background: #28a745; }
        .btn-reactivate { background: #28a745; }
        .btn-reject, .btn-delete, .btn-ban { background: #dc3545; }
        .btn-suspend { background: #6c757d; }
        .btn-verify { background: #007bff; }
        .btn-unverify { background: #6b7280; color: #ffffff; }
        .btn-upload { background: #6f42c1; }
        .btn-deactivate { background: #f59e0b; color: #ffffff; }

        .btn-view:hover { background: #138496; }
        .btn-approve:hover, .btn-activate:hover, .btn-reactivate:hover { background: #218838; }
        .btn-reject:hover, .btn-delete:hover, .btn-ban:hover { background: #c82333; }
        .btn-suspend:hover { background: #5a6268; }
        .btn-verify:hover { background: #0056b3; }
        .btn-unverify:hover { background: #4b5563; }
        .btn-upload:hover { background: #59359a; }
        .btn-deactivate:hover { background: #d97706; }

        /* Pagination - match Products page */
        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 20px; }
        .pagination .page-link, .pagination a { 
            padding: 8px 12px; background: #ffffff; color: #3498db; text-decoration: none; 
            border-radius: 5px; border: 1px solid #ddd; transition: all 0.3s ease; font-weight: 600;
        }
        .pagination .page-link:hover, .pagination a:hover { background: #3498db; color: #ffffff; }
        .pagination .page-link.active, .pagination a.active { background: #3498db; color: #ffffff; }
        .pagination .page-ellipsis { color: #95a5a6; }
        .pagination-info { color: rgba(249, 249, 249, 0.9); margin-top: 6px; text-align: center; }

        /* Search form - align with Products page */
        .search-section { margin: 8px 0; display: flex; justify-content: center; }
        .search-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; max-width: 720px; width: 100%; }
        .search-input { padding: 8px 15px; border: 1px solid #ddd; border-radius: 5px; width: 360px; flex: 1; }
        .btn-search { background: #3498db; color: #ffffff; border: none; padding: 8px 15px; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; }
        .btn-clear { background: #ffffff; color: #3498db; border: 1px solid #ddd; padding: 8px 12px; border-radius: 5px; text-decoration: none; font-weight: 700; font-size: 12px; }
        .btn-search:hover { background: #2980b9; }
        .btn-clear:hover { background: #3498db; color: #ffffff; border-color: #3498db; }

        /* Customers & Sellers: search container card */
        .page-admin-customers .search-section,
        .page-admin-sellers .search-section {
            background: #ffffff;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 14px;
            max-width: 1200px;
            margin: 10px auto;
        }

        /* Filters - match Products page */
        .admin-filters { max-width: 1200px; margin: 0 auto 20px auto; padding: 20px; background: #ffffff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .admin-filters h2 { color: #2c3e50; font-size: 16px; margin: 0 0 10px 0; }
        .filter-row { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }
        .status-filters { display: flex; flex-wrap: wrap; gap: 10px; }
        .status-filters a { padding: 8px 15px; background: #ecf0f1; color: #2c3e50; text-decoration: none; border-radius: 20px; transition: all 0.3s ease; font-size: 14px; }
        .status-filters a:hover { background: #d5dbdb; }
        .status-filters a.active { background: #3498db; color: #ffffff; }

        /* Admin Sellers: Upload Document modal (compact) */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1050; }
        .modal .modal-content {
            background: #ffffff;
            color: #2c3e50;
            width: 92%;
            max-width: 420px;
            margin: 10% auto;
            padding: 12px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
            position: relative;
        }
        .modal .close { position: absolute; top: 8px; right: 10px; font-size: 18px; cursor: pointer; color: #6b7280; }
        .modal h2 { font-size: 16px; margin: 0 0 10px 0; color: #111827; }
        .modal .form-group { margin-bottom: 10px; }
        .modal label { display: block; font-size: 12px; color: #374151; margin-bottom: 6px; }
        .modal input[type="file"] { width: 100%; font-size: 12px; }
        .modal button[type="submit"] {
            background: #FFD736;
            color: #130325;
            border: 1px solid #FFD736;
            padding: 6px 10px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 12px;
            cursor: pointer;
        }
        .modal button[type="submit"]:hover { filter: brightness(0.95); transform: translateY(-1px); }

        /* Compact page titles and list headers for admin pages */
        main > h1 { text-align: center; font-size: 16px; margin: 8px auto 8px auto; /* top spacing knob B */ }

        /* Ensure small, consistent top gap across all common admin pages */
        .page-admin-dashboard .page-header,
        .page-admin-dashboard main > h1,
        .page-admin-customers .page-header,
        .page-admin-customers main > h1,
        .page-admin-sellers .page-header,
        .page-admin-sellers main > h1,
        .page-admin-products .admin-header,
        .page-admin-products .page-header,
        .page-admin-products main > h1,
        .page-admin-categories .admin-header,
        .page-admin-categories .page-header,
        .page-admin-categories main > h1 {
            margin-top: 8px !important;
        }

        /* Fallback: if a page uses a different first block, keep it tight */
        main > *:first-child { margin-top: 8px; }
        .page-admin-sellers main > h1, .page-admin-customers main > h1 { font-size: 56px; line-height: 1.1; }
        .page-admin-dashboard .page-header h1 { font-size: 56px; line-height: 1.1; }
        .admin-content h2 { text-align: center; font-size: 14px; }

        /* Narrow search bar and center it */
        .search-section { display: flex; justify-content: center; }
        .search-form { width: 100%; max-width: 420px; }

        /* Logout Confirmation Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.35);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        .modal-dialog {
            width: 360px;
            max-width: 90vw;
            background: #ffffff;
            border: none;
            border-radius: 12px;
        }
        .modal-header {
            padding: 8px 12px;
            background: #130325;
            color: #F9F9F9;
            border-bottom: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-radius: 12px 12px 0 0;
        }
        .modal-title {
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .3px;
        }
        .modal-close {
            background: transparent;
            border: none;
            color: #F9F9F9;
            font-size: 16px;
            line-height: 1;
            cursor: pointer;
        }
        .modal-body {
            padding: 12px;
            color: #130325;
            font-size: 13px;
        }
        .modal-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            padding: 0 12px 12px 12px;
        }
        .btn-outline {
            background: #ffffff;
            color: #130325;
            border: none;
            border-radius: 8px;
            padding: 6px 10px;
            font-weight: 700;
            font-size: 12px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary-y {
            background: linear-gradient(135deg, #FFD736 0%, #FFC107 100%);
            color: #130325;
            border: none;
            border-radius: 8px;
            padding: 6px 10px;
            font-weight: 700;
            font-size: 12px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
    </style>
</head>
<body class="<?php echo 'page-' . strtolower(pathinfo($_SERVER['PHP_SELF'], PATHINFO_FILENAME)); ?>">
    <!-- Full-width Header -->
    <div class="invisible-header">
        <div class="header-left">
            <button class="header-hamburger" onclick="toggleAdminSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            </div>

        <?php if (isLoggedIn() && isAdmin()): ?>
        <div class="header-right">
            <!-- Admin Notifications -->
            <div class="admin-notifications">
                <div class="notification-bell" onclick="toggleAdminNotifications()">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge" id="adminNotificationBadge">0</span>
                </div>
                <div class="notification-dropdown" id="adminNotificationDropdown">
                    <div class="notification-header">
                        <h6>Notifications</h6>
                        <div>
                            <button class="clear-all" type="button" onclick="clearAllAdminNotifications()">Clear All</button>
                        </div>
                    </div>
                    <div class="notification-list" id="adminNotificationList">
                        <div class="notification-item">
                            <i class="fas fa-spinner fa-spin"></i>
                            <span style="color: #1f2937;">Loading notifications...</span>
                        </div>
                    </div>
                    <div class="notification-footer">
                        <a class="see-all-btn" href="admin-notifications.php">See All</a>
                    </div>
                </div>
            </div>
            
            <div class="header-user">
                <div class="header-user-info" onclick="toggleAdminHeaderDropdown()">
                    <div class="header-user-avatar"><?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="header-dropdown" id="adminHeaderDropdown">
                    <a href="#" onclick="openLogoutModal(); return false;"><i class="fas fa-sign-out-alt"></i>Logout</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="admin-layout">
        <aside class="sidebar" id="adminSidebar">
            <!-- Logo inside sidebar -->
            <div class="sidebar-logo">
                <a href="admin-dashboard.php">
                    <span class="full-logo barcode-text">PEST-CTRL Admin</span>
                    <span class="short-logo barcode-text">PC</span>
                </a>
            </div>

            <?php if (isLoggedIn() && isAdmin()): ?>
                <!-- User profile section without avatar -->
                <div class="user-profile-section">
                    <div class="user-profile-info">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                        <div class="user-role">Administrator</div>
                    </div>
                </div>

                <div class="section-title hide-on-collapse">Overview</div>
                <div class="nav-links">
                    <a href="admin-dashboard.php" data-tooltip="Dashboard"><i class="fas fa-tachometer-alt"></i><span class="hide-on-collapse"> Dashboard</span></a>
                </div>

                <div class="section-title hide-on-collapse">Users</div>
                <div class="nav-links">
                    <div class="nav-dropdown">
                        <button class="nav-dropdown-toggle" onclick="toggleAdminNavDropdown(event, 'manage-users')" data-tooltip="Manage Users">
                            <div class="nav-dropdown-toggle-content">
                                <i class="fas fa-users"></i>
                                <span class="hide-on-collapse">Manage Users</span>
                            </div>
                            <i class="fas fa-chevron-right nav-dropdown-arrow hide-on-collapse" id="manage-users-arrow"></i>
                        </button>
                        <div class="nav-dropdown-content" id="manage-users-dropdown">
                            <a href="admin-customers.php">
                                <i class="fas fa-user"></i>
                                <span>Customers</span>
                            </a>
                            <a href="admin-sellers.php">
                                <i class="fas fa-store"></i>
                                <span>Sellers</span>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="section-title hide-on-collapse">Catalog</div>
                <div class="nav-links">
                    <a href="admin-products.php" data-tooltip="Products"><i class="fas fa-boxes"></i><span class="hide-on-collapse"> Products</span></a>
                    <a href="admin-categories.php" data-tooltip="Categories"><i class="fas fa-tags"></i><span class="hide-on-collapse"> Categories</span></a>
                </div>

                <div class="section-title hide-on-collapse">System</div>
                <div class="nav-links">
                    <div class="nav-dropdown">
                        <button class="nav-dropdown-toggle" onclick="toggleNavDropdown(this)" data-tooltip="Settings">
                            <div class="nav-dropdown-toggle-content">
                                <i class="fas fa-cogs"></i>
                                <span class="hide-on-collapse">Settings</span>
                            </div>
                            <i class="fas fa-chevron-right nav-dropdown-arrow hide-on-collapse"></i>
                        </button>
                        <div class="nav-dropdown-content">
                            <a href="admin-settings.php?section=order"><i class="fas fa-clock"></i> Order Settings</a>
                            <a href="admin-settings.php?section=maintenance"><i class="fas fa-tools"></i> Maintenance Mode</a>
                            <a href="admin-settings.php?section=site"><i class="fas fa-cog"></i> Site Settings</a>
                            <a href="admin-settings.php?section=profile"><i class="fas fa-user-edit"></i> Edit Profile</a>
                        </div>
                    </div>
                </div>


            <?php else: ?>
                <div class="nav-links" style="margin-top: 12px;">
                    <a href="../login_admin.php"><i class="fas fa-sign-in-alt"></i> Admin Login</a>
                </div>
            <?php endif; ?>
        </aside>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Confirmation modal for actions
            const confirmModal = document.createElement('div');
            confirmModal.innerHTML = `
                <div class="modal" id="confirmModal" style="display:none;">
                    <div class="modal-content">
                        <span class="close" id="confirmClose">&times;</span>
                        <h2 id="confirmTitle">Confirm Action</h2>
                        <p id="confirmMessage" style="margin: 8px 0 14px 0; color: #374151; font-size: 13px;">Are you sure you want to proceed?</p>
                        <div style="display:flex; gap:10px; justify-content:flex-end;">
                            <button id="confirmCancel" style="background:#e5e7eb; color:#374151; border:1px solid #d1d5db; padding:6px 10px; border-radius:6px; font-weight:700; font-size:12px;">Cancel</button>
                            <button id="confirmOk" style="background:#dc3545; color:#fff; border:none; padding:6px 10px; border-radius:6px; font-weight:700; font-size:12px;">Confirm</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(confirmModal);

            function openConfirm(message, onConfirm) {
                const modal = document.getElementById('confirmModal');
                const msg = document.getElementById('confirmMessage');
                msg.textContent = message || 'Are you sure you want to proceed?';
                modal.style.display = 'block';
                const ok = document.getElementById('confirmOk');
                const cancel = document.getElementById('confirmCancel');
                const close = document.getElementById('confirmClose');
                const cleanup = () => { modal.style.display = 'none'; ok.onclick = null; cancel.onclick = null; close.onclick = null; };
                ok.onclick = () => { cleanup(); onConfirm(); };
                cancel.onclick = cleanup;
                close.onclick = cleanup;
                window.addEventListener('click', function handler(e){ if(e.target === modal){ cleanup(); window.removeEventListener('click', handler); } });
                document.addEventListener('keydown', function escHandler(e){ if(e.key==='Escape'){ cleanup(); document.removeEventListener('keydown', escHandler);} });
            }

            // Bind any link/button with data-confirm
            document.querySelectorAll('[data-confirm]')?.forEach(el => {
                el.addEventListener('click', function(e){
                    const href = this.getAttribute('href');
                    const message = this.getAttribute('data-confirm');
                    if (href) {
                        e.preventDefault();
                        openConfirm(message, () => { window.location.href = href; });
                    }
                });
            });
        });
    </script>
    
        <script>
            function toggleAdminNavDropdown(event, id) {
                event.preventDefault();
                event.stopPropagation();
                const dropdown = document.getElementById(id + '-dropdown');
                const arrow = document.getElementById(id + '-arrow');
                if (!dropdown) return;
                const isOpen = dropdown.classList.contains('show');
                document.querySelectorAll('.nav-dropdown-content.show').forEach(d => d.classList.remove('show'));
                document.querySelectorAll('.nav-dropdown-arrow.rotated').forEach(a => a.classList.remove('rotated'));
                if (!isOpen) {
                    dropdown.classList.add('show');
                    if (arrow) arrow.classList.add('rotated');
                }
            }
        </script>

        <script>
            function toggleAdminSidebar() {
                const sidebar = document.getElementById('adminSidebar');
                const main = document.querySelector('main');
                const barcodeText = document.querySelector('.barcode-text');
                
                sidebar.classList.toggle('collapsed');
                
                // Adjust main content margin
                if (sidebar.classList.contains('collapsed')) {
                    main.style.marginLeft = '70px';
                    // Animate logo when sidebar collapses
                    if (barcodeText) {
                        barcodeText.style.transform = 'scale(0.8)';
                        barcodeText.style.opacity = '0.7';
                    }
                } else {
                    main.style.marginLeft = '240px';
                    // Animate logo when sidebar expands
                    if (barcodeText) {
                        barcodeText.style.transform = 'scale(1)';
                        barcodeText.style.opacity = '1';
                    }
                }
                
                // Save state to localStorage
                const isCollapsed = sidebar.classList.contains('collapsed');
                localStorage.setItem('adminSidebarCollapsed', isCollapsed);
            }

            function toggleAdminHeaderDropdown() {
                const dropdown = document.getElementById('adminHeaderDropdown');
                dropdown.classList.toggle('show');
            }

            function toggleNavDropdown(button) {
                const dropdown = button.closest('.nav-dropdown');
                const content = dropdown.querySelector('.nav-dropdown-content');
                const arrow = dropdown.querySelector('.nav-dropdown-arrow');
                
                content.classList.toggle('show');
                if (arrow) {
                    arrow.classList.toggle('rotated');
                }
            }

            // Close dropdown when clicking outside
            document.addEventListener('click', function(event) {
                const headerUser = document.querySelector('.header-user');
                const dropdown = document.getElementById('adminHeaderDropdown');
                
                if (headerUser && !headerUser.contains(event.target)) {
                    dropdown.classList.remove('show');
                }
            });

            // Admin notification functions
            function toggleAdminNotifications() {
                const dropdown = document.getElementById('adminNotificationDropdown');
                dropdown.classList.toggle('show');
                
                if (dropdown.classList.contains('show')) {
                    loadAdminNotifications();
                }
            }

            function loadAdminNotifications() {
                fetch('../ajax/get-admin-notifications.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const badge = document.getElementById('adminNotificationBadge');
                        if (badge) {
                            badge.textContent = data.unreadCount || 0;
                            badge.classList.toggle('hidden', (data.unreadCount || 0) === 0);
                        }
                        displayAdminNotifications(data.notifications || []);
                    } else {
                        console.error('Failed to load notifications:', data.message);
                        const list = document.getElementById('adminNotificationList');
                        if (list) {
                            list.innerHTML = '<div class="notification-item"><span>Error loading notifications</span></div>';
                        }
                    }
                })
                .catch(error => {
                    console.error('Error loading admin notifications:', error);
                    const list = document.getElementById('adminNotificationList');
                    if (list) {
                        list.innerHTML = '<div class="notification-item"><span>Error loading notifications</span></div>';
                    }
                });
            }

            function clearAllAdminNotifications() {
                showMarkAllReadModal();
            }
            
            function showMarkAllReadModal() {
                const modal = document.getElementById('markAllReadModal');
                if (modal) {
                    modal.style.display = 'flex';
                    modal.setAttribute('aria-hidden', 'false');
                    document.body.style.overflow = 'hidden';
                }
            }
            
            function closeMarkAllReadModal() {
                const modal = document.getElementById('markAllReadModal');
                if (modal) {
                    modal.style.display = 'none';
                    modal.setAttribute('aria-hidden', 'true');
                    document.body.style.overflow = '';
                }
            }
            
            function confirmMarkAllRead() {
                closeMarkAllReadModal();
                fetch('../ajax/mark-all-admin-notifications-read.php', { method: 'POST' })
                    .then(r => r.json())
                    .then(data => {
                        if (data && data.success) {
                            loadAdminNotifications();
                        }
                    })
                    .catch(err => console.error('Error clearing admin notifications:', err));
            }

            function displayAdminNotifications(notifications) {
                const list = document.getElementById('adminNotificationList');
                
                // Filter to show only unread notifications
                const unreadNotifications = (notifications || []).filter(notification => {
                    return notification.is_read == 0 || notification.is_read === false || notification.is_read === null;
                });
                
                if (!unreadNotifications || unreadNotifications.length === 0) {
                    list.innerHTML = '<div class="notification-item"><span style="color: #130325;">No unread notifications</span></div>';
                    return;
                }
                
                list.innerHTML = unreadNotifications.map(notification => {
                    const notificationType = notification.type || 'info';
                    const isRead = notification.is_read == 1 || notification.is_read === true || notification.is_read === 1;
                    const badgeInfo = getNotificationBadge(notification.title || '', notificationType);
                    let actionUrl = (notification.action_url || '').replace(/'/g, "\\'");
                    
                    // Fix duplicate admin/admin- paths
                    if (actionUrl && actionUrl.indexOf('admin/admin-') !== -1) {
                        actionUrl = actionUrl.replace('admin/admin-', 'admin-');
                    }
                    
                    return `
                    <div class="notification-item ${!isRead ? 'unread' : ''}" 
                         onclick="handleAdminNotificationClick(${notification.id}, '${actionUrl}')">
                        <span class="notification-status-badge ${badgeInfo.class}">${badgeInfo.text}</span>
                        <div class="notification-content">
                            <div class="notification-title">${escapeHtml(notification.title || 'Notification')}</div>
                            <div class="notification-message">${escapeHtml(notification.message || '')}</div>
                            <div class="notification-time">${formatTime(notification.created_at || '')}</div>
                        </div>
                    </div>
                    `;
                }).join('');
            }
            
            function getNotificationBadge(title, type) {
                const titleLower = (title || '').toLowerCase();
                
                if (titleLower.includes('product') || titleLower.includes('pending approval')) {
                    return { class: 'notification-status-processing', text: 'Product' };
                }
                if (titleLower.includes('new seller') || type === 'new_seller') {
                    return { class: 'notification-status-new-seller', text: 'New Seller' };
                }
                if (titleLower.includes('order') || titleLower.includes('order received')) {
                    return { class: 'notification-status-order', text: 'Order' };
                }
                if (titleLower.includes('return') || titleLower.includes('refund')) {
                    return { class: 'notification-status-return', text: 'Return/Refund' };
                }
                if (titleLower.includes('low stock')) {
                    return { class: 'notification-status-lowstock', text: 'Low Stocks' };
                }
                
                return { class: 'notification-status-info', text: 'Info' };
            }
            
            function handleAdminNotificationClick(notificationId, actionUrl) {
                // Fix duplicate admin/admin- paths
                if (actionUrl && actionUrl.indexOf('admin/admin-') !== -1) {
                    actionUrl = actionUrl.replace('admin/admin-', 'admin-');
                }
                
                // Optimistically remove notification from DOM
                const notificationItem = document.querySelector(`.notification-item[onclick*="${notificationId}"]`);
                if (notificationItem) {
                    notificationItem.style.opacity = '0';
                    notificationItem.style.transform = 'translateX(-20px)';
                    setTimeout(() => {
                        notificationItem.remove();
                        // Update badge count
                        const badge = document.getElementById('adminNotificationBadge');
                        if (badge) {
                            const currentCount = parseInt(badge.textContent) || 0;
                            badge.textContent = Math.max(0, currentCount - 1);
                            badge.classList.toggle('hidden', (currentCount - 1) === 0);
                        }
                    }, 300);
                }
                
                // Mark as read (don't reload list - we already removed it)
                markAdminNotificationAsRead(notificationId, false);
                
                // Navigate to action URL if provided
                if (actionUrl) {
                    setTimeout(() => {
                        window.location.href = actionUrl;
                    }, 100);
                }
            }
            
            function escapeHtml(text) {
                if (!text) return '';
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            function markAdminNotificationAsRead(notificationId, reloadList = true) {
                fetch('../ajax/mark-admin-notification-read.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ notification_id: notificationId })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && reloadList) {
                        loadAdminNotifications(); // Reload to update badge and list
                    }
                })
                .catch(error => {
                    console.error('Error marking admin notification as read:', error);
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

            function renderNotificationBadge(type, title) {
                const titleLower = (title || '').toLowerCase();
                
                // Check title for specific notification types
                if (titleLower.includes('product data issues')) {
                    return `<span class="notification-status-badge notification-status-error">Product</span>`;
                }
                if (titleLower.includes('suspicious activity')) {
                    return `<span class="notification-status-badge notification-status-warning">Warning</span>`;
                }
                if (titleLower.includes('product') || titleLower.includes('pending approval')) {
                    return `<span class="notification-status-badge notification-status-processing">Product</span>`;
                }
                if (titleLower.includes('new seller') || type === 'new_seller') {
                    return `<span class="notification-status-badge notification-status-new-seller">New Seller</span>`;
                }
                if (titleLower.includes('order') || titleLower.includes('order received')) {
                    return `<span class="notification-status-badge notification-status-order">Order</span>`;
                }
                if (titleLower.includes('return') || titleLower.includes('refund')) {
                    return `<span class="notification-status-badge notification-status-return">Return/Refund</span>`;
                }
                if (titleLower.includes('low stock')) {
                    return `<span class="notification-status-badge notification-status-lowstock">Low Stocks</span>`;
                }
                
                // Fallback to type-based mapping
                const map = {
                    info: { label: 'Info', cls: 'notification-status-info' },
                    warning: { label: 'Warning', cls: 'notification-status-warning' },
                    success: { label: 'Success', cls: 'notification-status-success' },
                    error: { label: 'Error', cls: 'notification-status-error' },
                    new_seller: { label: 'New Seller', cls: 'notification-status-new-seller' },
                };
                const conf = map[type] || map.info;
                return `<span class="notification-status-badge ${conf.cls}">${conf.label}</span>`;
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

            // Close admin notification dropdown when clicking outside
            document.addEventListener('click', function(event) {
                const notifications = document.querySelector('.admin-notifications');
                const dropdown = document.getElementById('adminNotificationDropdown');
                
                if (notifications && !notifications.contains(event.target)) {
                    dropdown.classList.remove('show');
                }
            });

            // On page load, check if sidebar was collapsed
            document.addEventListener('DOMContentLoaded', () => {
                const sidebar = document.getElementById('adminSidebar');
                const main = document.querySelector('main');
                const barcodeText = document.querySelector('.barcode-text');
                const isCollapsed = localStorage.getItem('adminSidebarCollapsed') === 'true';
                
                if (isCollapsed) {
                    sidebar.classList.add('collapsed');
                    main.style.marginLeft = '70px';
                    // Set logo animation state
                    if (barcodeText) {
                        barcodeText.style.transform = 'scale(0.8)';
                        barcodeText.style.opacity = '0.7';
                    }
                }
                
                // Load admin notifications on page load
                loadAdminNotifications();
            });

            // Logout Modal Functions
            function openLogoutModal() {
                const modal = document.getElementById('logoutModal');
                if (modal) {
                    modal.style.display = 'flex';
                    modal.setAttribute('aria-hidden', 'false');
                }
            }

            function closeLogoutModal() {
                const modal = document.getElementById('logoutModal');
                if (modal) {
                    modal.style.display = 'none';
                    modal.setAttribute('aria-hidden', 'true');
                }
            }

            // Close modal on overlay click
            document.addEventListener('click', function(e) {
                const logoutModal = document.getElementById('logoutModal');
                const markAllModal = document.getElementById('markAllReadModal');
                if (logoutModal && e.target === logoutModal) {
                    closeLogoutModal();
                }
                if (markAllModal && e.target === markAllModal) {
                    closeMarkAllReadModal();
                }
            });

            // Close modal on Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const logoutModal = document.getElementById('logoutModal');
                    const markAllModal = document.getElementById('markAllReadModal');
                    if (logoutModal && logoutModal.style.display === 'flex') {
                        closeLogoutModal();
                    }
                    if (markAllModal && markAllModal.style.display === 'flex') {
                        closeMarkAllReadModal();
                    }
                }
            });
        </script>

    <!-- Logout Confirmation Modal -->
    <div id="logoutModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-header">
                <div class="modal-title">Confirm Logout</div>
                <button class="modal-close" aria-label="Close" onclick="closeLogoutModal()">×</button>
            </div>
            <div class="modal-body">
                Are you sure you want to logout? You will need to sign in again to access the admin panel.
            </div>
            <div class="modal-actions">
                <button class="btn-outline" onclick="closeLogoutModal()">Cancel</button>
                <a href="../logout.php" class="btn-primary-y">Logout</a>
            </div>
        </div>
    </div>

    <!-- Mark All Notifications as Read Modal -->
    <div id="markAllReadModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-header">
                <div class="modal-title">Confirm Action</div>
                <button class="modal-close" aria-label="Close" onclick="closeMarkAllReadModal()">×</button>
            </div>
            <div class="modal-body">
                Are you sure you want to mark all notifications as read?
            </div>
            <div class="modal-actions">
                <button class="btn-outline" onclick="closeMarkAllReadModal()">
                    <i class="fas fa-times"></i> No
                </button>
                <button class="btn-primary-y" onclick="confirmMarkAllRead()">
                    <i class="fas fa-check"></i> Yes
                </button>
            </div>
        </div>
    </div>

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
                fetch('../ajax/update-session-activity.php', {
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