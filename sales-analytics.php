<?php
require_once 'includes/functions.php';
require_once 'config/database.php';

// Check seller login FIRST before any output
requireSeller();
$userId = $_SESSION['user_id'];

// Now include the header (which will output HTML)
require_once 'includes/seller_header.php';

// Apply modern minimal design theme
echo '<style>
html, body {
    background: #f0f2f5 !important;
    color: #130325 !important;
    font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif;
    font-size: 14px;
}
main {
    margin-left: 240px;
    margin-top: 0 !important;
    padding: 24px 30px 40px 30px !important;
    background: transparent !important;
    transition: margin-left 0.3s ease !important;
}

/* JavaScript will set inline style for margin-left, but this is the default */
.section {
    background: #ffffff !important;
    padding: 24px !important;
    border-radius: 8px !important;
    border: 1px solid rgba(0, 0, 0, 0.1) !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1) !important;
    color: #130325 !important;
}
.stat-card {
    background: #ffffff !important;
    border: 1px solid rgba(0, 0, 0, 0.1) !important;
    border-radius: 8px !important;
    padding: 16px !important;
    color: #130325 !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1) !important;
}
.period-badge {
    background: rgba(255, 215, 54, 0.15) !important;
    border: 1px solid #FFD736 !important;
    color: #130325 !important;
    padding: 4px 10px !important;
    border-radius: 999px !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    display: inline-block !important;
}
.orders-table-container {
    overflow-x: auto !important;
    margin-bottom: 15px !important;
    border: 1px solid rgba(0, 0, 0, 0.1) !important;
    border-radius: 8px !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1) !important;
    background: #ffffff !important;
}
.orders-table {
    width: 100% !important;
    border-collapse: collapse !important;
    font-size: 13px !important;
    color: #130325 !important;
}
.orders-table thead {
    background: #130325 !important;
    position: sticky !important;
    top: 0 !important;
    z-index: 10 !important;
    border-bottom: 2px solid #FFD736 !important;
}
.orders-table th {
    padding: 12px !important;
    text-align: left !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    color: #ffffff !important;
}
.orders-table td {
    padding: 12px !important;
    border-bottom: 1px solid #f0f0f0 !important;
    color: #130325 !important;
    background: #ffffff !important;
}
.orders-table tbody tr {
    background: #ffffff !important;
    transition: all 0.15s ease-in-out !important;
}
.orders-table tbody tr:hover {
    background: rgba(255, 215, 54, 0.05) !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05) !important;
}
h1 {
    color: #130325 !important;
    font-size: 32px !important;
    font-weight: 700 !important;
    text-align: left !important;
    margin: 0 0 28px 0 !important;
    padding: 0 !important;
    text-shadow: none !important;
}
h2 {
    color: #130325 !important;
    font-size: 20px !important;
    font-weight: 700 !important;
    text-align: left !important;
    margin: 0 0 20px 0 !important;
    text-shadow: none !important;
}
h3 {
    color: #130325 !important;
    font-size: 16px !important;
    font-weight: 600 !important;
}
p, span, div {
    color: #130325 !important;
}

/* KEEP SIDEBAR TEXT WHITE - Override dark color for sidebar */
.sidebar,
.sidebar *,
.sidebar a,
.sidebar .nav-links > a,
.sidebar .nav-dropdown-toggle,
.sidebar .section-title,
.sidebar .user-name,
.sidebar .user-role,
.sidebar .sidebar-logo a,
.sidebar .user-profile-section,
.sidebar .user-profile-info {
    color: #F9F9F9 !important;
}

.sidebar .sidebar-logo a {
    color: #FFD736 !important;
}

.sidebar .nav-links > a.active,
.sidebar .nav-links > a:hover,
.sidebar .nav-dropdown-toggle:hover {
    color: #FFD736 !important;
}

.sidebar .user-role {
    color: rgba(249, 249, 249, 0.7) !important;
}

.sidebar .section-title {
    color: #9ca3af !important;
}

/* KEEP SELLER HEADER TEXT WHITE - Override dark color for header */
.invisible-header,
.invisible-header *,
.invisible-header span,
.invisible-header .header-user-info,
.invisible-header .header-user-info span,
.invisible-header .header-user-avatar,
.invisible-header .header-user,
.invisible-header .header-hamburger,
.invisible-header .header-hamburger i,
.invisible-header .notification-bell,
.invisible-header .notification-bell i,
.invisible-header button,
.invisible-header a,
.invisible-header div {
    color: #F9F9F9 !important;
}

.invisible-header .header-hamburger,
.invisible-header .header-hamburger i {
    color: #FFD736 !important;
}

.invisible-header .header-hamburger:hover,
.invisible-header .header-hamburger:hover i {
    color: #F9F9F9 !important;
}

.invisible-header .header-user-info:hover {
    color: #FFD736 !important;
}

.invisible-header .header-user-info:hover span {
    color: #FFD736 !important;
}

.invisible-header .notification-bell i {
    color: #FFD736 !important;
}

.invisible-header .header-user-avatar {
    color: #130325 !important;
}

/* Fix user dropdown text color - should be dark, not white */
.invisible-header .header-dropdown,
.invisible-header .header-dropdown *,
.invisible-header .header-dropdown a,
.invisible-header .header-dropdown a i {
    color: #130325 !important;
}

.invisible-header .header-dropdown a:hover {
    color: #FFD736 !important;
}

/* Ensure notification dropdown uses dark text on this page (overrides broad invisible-header white) */
.invisible-header .notification-dropdown,
.invisible-header .notification-dropdown *,
.invisible-header .notification-dropdown a,
.invisible-header .notification-dropdown a i,
.invisible-header .notification-header h6,
.invisible-header .notification-title,
.invisible-header .notification-message,
.invisible-header .notification-time {
    color: #130325 !important;
}

/* Notification dropdown styles are now global in seller_header.php */

main {
    padding-top: 0 !important;
    margin-top: 0 !important;
}
.container {
    margin-top: 0 !important;
    padding-top: 0 !important;
}
</style>';



// Get selected time period from URL parameter
$selectedPeriod = isset($_GET['period']) ? sanitizeInput($_GET['period']) : 'weekly';
$customStartDate = isset($_GET['start_date']) ? sanitizeInput($_GET['start_date']) : '';
$customEndDate = isset($_GET['end_date']) ? sanitizeInput($_GET['end_date']) : '';

// Helper function to get date condition based on period
function getPeriodDateCondition($period, $startDate = '', $endDate = '') {
    // Handle custom date range
    if ($period === 'custom' && !empty($startDate) && !empty($endDate)) {
        $startDate = date('Y-m-d', strtotime($startDate));
        $endDate = date('Y-m-d', strtotime($endDate));
        return "o.created_at >= '$startDate' AND o.created_at <= '$endDate 23:59:59'";
    }
    
    switch($period) {
        case 'weekly':
            return "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        case 'monthly':
            return "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
        case 'yearly':
            return "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        case '6months':
        default:
            return "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
    }
}

// IMPORTANT: Set $dateCondition FIRST before using it
$dateCondition = getPeriodDateCondition($selectedPeriod, $customStartDate, $customEndDate);

// Create date condition for o2 alias (used in subqueries)
$dateConditionO2 = str_replace('o.created_at', 'o2.created_at', $dateCondition);

// NOW we can use $dateCondition in queries
// Get return statistics for the selected period
$stmt = $pdo->prepare("SELECT 
                          COUNT(*) as total_returns,
                          SUM(CASE WHEN rr.status = 'pending' THEN 1 ELSE 0 END) as pending_returns,
                          SUM(CASE WHEN rr.status = 'approved' THEN 1 ELSE 0 END) as approved_returns,
                          SUM(CASE WHEN rr.status = 'rejected' THEN 1 ELSE 0 END) as rejected_returns
                      FROM return_requests rr
                      JOIN products p ON rr.product_id = p.id
                      JOIN orders o ON rr.order_id = o.id
                      WHERE p.seller_id = ? AND $dateCondition");
$stmt->execute([$userId]);
$returnStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Calculate refund amounts
$stmt = $pdo->prepare("SELECT COALESCE(SUM(oi.price * oi.quantity), 0) as total_refund_amount
                      FROM return_requests rr
                      JOIN order_items oi ON rr.order_id = oi.order_id AND rr.product_id = oi.product_id
                      JOIN products p ON rr.product_id = p.id
                      JOIN orders o ON rr.order_id = o.id
                      WHERE p.seller_id = ? AND rr.status = 'approved' AND $dateCondition");
$stmt->execute([$userId]);
$periodRefundAmount = $stmt->fetch(PDO::FETCH_ASSOC)['total_refund_amount'] ?? 0;
// Get top 5 selling products for the selected period
        $stmt = $pdo->prepare("SELECT 
                                  p.name,
                                  SUM(oi.quantity) as total_sold
                              FROM order_items oi 
                              JOIN products p ON oi.product_id = p.id 
                              JOIN orders o ON oi.order_id = o.id
                              WHERE p.seller_id = ? 
                              AND o.status NOT IN ('cancelled', 'refunded')
                              AND $dateCondition
                              GROUP BY p.id, p.name
                              ORDER BY total_sold DESC
                              LIMIT 5");
        $stmt->execute([$userId]);
        $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $productNames = [];
        $productSales = [];
        
        if (count($topProducts) > 0) {
            foreach ($topProducts as $product) {
                $productNames[] = $product['name'];
                $productSales[] = $product['total_sold'];
            }
        } else {
            // Default data if no products sold
            $productNames = ['No Sales Yet'];
            $productSales = [0];
        }
// 1. Total Products (unchanged)
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM products WHERE seller_id = ? AND status = 'active'");
$stmt->execute([$userId]);
$totalProducts = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// 2. Total Items Sold (based on selected period)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(oi.quantity), 0) as total FROM order_items oi 
                      JOIN products p ON oi.product_id = p.id 
                      JOIN orders o ON oi.order_id = o.id
                      WHERE p.seller_id = ? AND o.status NOT IN ('cancelled', 'refunded') 
                      AND $dateCondition");
$stmt->execute([$userId]);
$totalSales = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// 3. Expected Revenue - Based on selected period
$stmt = $pdo->prepare("SELECT COALESCE(SUM(oi.price * oi.quantity), 0) as total FROM order_items oi 
                      JOIN products p ON oi.product_id = p.id 
                      JOIN orders o ON oi.order_id = o.id
                      WHERE p.seller_id = ? AND o.status NOT IN ('cancelled', 'refunded')
                      AND $dateCondition");
$stmt->execute([$userId]);
$expectedRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// 4. Unique Orders Count (based on selected period)
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT o.id) as unique_orders FROM orders o 
                      JOIN order_items oi ON o.id = oi.order_id 
                      JOIN products p ON oi.product_id = p.id 
                      WHERE p.seller_id = ? AND o.status NOT IN ('cancelled', 'refunded')
                      AND $dateCondition");
$stmt->execute([$userId]);
$uniqueOrders = $stmt->fetch(PDO::FETCH_ASSOC)['unique_orders'] ?? 0;

// 5. Average Order Value
$avgOrderValue = $uniqueOrders > 0 ? $expectedRevenue / $uniqueOrders : 0;

// Calculate return rate (must be after $uniqueOrders and $returnStats are defined)
$returnRate = $uniqueOrders > 0 ? (($returnStats['total_returns'] ?? 0) / $uniqueOrders) * 100 : 0;

// 6. Confirmed Revenue - Based on selected period
$stmt = $pdo->prepare("SELECT COALESCE(SUM(oi.price * oi.quantity), 0) as total FROM order_items oi 
                      JOIN products p ON oi.product_id = p.id 
                      JOIN orders o ON oi.order_id = o.id
                      WHERE p.seller_id = ? 
                      AND (
                          (o.payment_method IN ('paypal', 'gcash', 'credit_card', 'debit_card') 
                           AND o.status NOT IN ('cancelled', 'refunded'))
                          OR 
                          (o.payment_method = 'cod' AND o.status = 'delivered')
                      )
                      AND $dateCondition");
$stmt->execute([$userId]);
$confirmedRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Calculate Net Confirmed Revenue (after refunds)
$netConfirmedRevenue = $confirmedRevenue - $periodRefundAmount;

// GROWTH CALCULATIONS - Compare with previous period
// Get previous period data for comparison
$previousPeriodCondition = '';
$previousPeriodConditionO1 = ''; // For queries using o1 alias
switch($selectedPeriod) {
    case 'weekly':
        $previousPeriodCondition = "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND o.created_at < DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $previousPeriodConditionO1 = "o1.created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND o1.created_at < DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        break;
    case 'monthly':
        $previousPeriodCondition = "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH) AND o.created_at < DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
        $previousPeriodConditionO1 = "o1.created_at >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH) AND o1.created_at < DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
        break;
    case '6months':
        $previousPeriodCondition = "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND o.created_at < DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
        $previousPeriodConditionO1 = "o1.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND o1.created_at < DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
        break;
    case 'yearly':
        $previousPeriodCondition = "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 2 YEAR) AND o.created_at < DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        $previousPeriodConditionO1 = "o1.created_at >= DATE_SUB(CURDATE(), INTERVAL 2 YEAR) AND o1.created_at < DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        break;
    default:
        $previousPeriodCondition = "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND o.created_at < DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
        $previousPeriodConditionO1 = "o1.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND o1.created_at < DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
}

// Previous period revenue
$stmt = $pdo->prepare("SELECT COALESCE(SUM(oi.price * oi.quantity), 0) as total FROM order_items oi 
                      JOIN products p ON oi.product_id = p.id 
                      JOIN orders o ON oi.order_id = o.id
                      WHERE p.seller_id = ? 
                      AND (
                          (o.payment_method IN ('paypal', 'gcash', 'credit_card', 'debit_card') 
                           AND o.status NOT IN ('cancelled', 'refunded'))
                          OR 
                          (o.payment_method = 'cod' AND o.status = 'delivered')
                      )
                      AND $previousPeriodCondition");
$stmt->execute([$userId]);
$previousConfirmedRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Previous period orders
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT o.id) as unique_orders FROM orders o 
                      JOIN order_items oi ON o.id = oi.order_id 
                      JOIN products p ON oi.product_id = p.id 
                      WHERE p.seller_id = ? AND o.status NOT IN ('cancelled', 'refunded')
                      AND $previousPeriodCondition");
$stmt->execute([$userId]);
$previousUniqueOrders = $stmt->fetch(PDO::FETCH_ASSOC)['unique_orders'] ?? 0;

// Previous period items sold
$stmt = $pdo->prepare("SELECT COALESCE(SUM(oi.quantity), 0) as total FROM order_items oi 
                      JOIN products p ON oi.product_id = p.id 
                      JOIN orders o ON oi.order_id = o.id
                      WHERE p.seller_id = ? AND o.status NOT IN ('cancelled', 'refunded') 
                      AND $previousPeriodCondition");
$stmt->execute([$userId]);
$previousTotalSales = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Calculate growth rates
$revenueGrowthRate = $previousConfirmedRevenue > 0 ? (($confirmedRevenue - $previousConfirmedRevenue) / $previousConfirmedRevenue) * 100 : 0;
$orderGrowthRate = $previousUniqueOrders > 0 ? (($uniqueOrders - $previousUniqueOrders) / $previousUniqueOrders) * 100 : 0;
$salesGrowthRate = $previousTotalSales > 0 ? (($totalSales - $previousTotalSales) / $previousTotalSales) * 100 : 0;

// Customer retention rate (simplified - customers who ordered in both periods)
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT o1.user_id) as repeat_customers FROM orders o1
                      JOIN order_items oi1 ON o1.id = oi1.order_id 
                      JOIN products p1 ON oi1.product_id = p1.id 
                      WHERE p1.seller_id = ? AND o1.status NOT IN ('cancelled', 'refunded')
                      AND $previousPeriodConditionO1
                      AND o1.user_id IN (
                          SELECT DISTINCT o2.user_id FROM orders o2
                          JOIN order_items oi2 ON o2.id = oi2.order_id 
                          JOIN products p2 ON oi2.product_id = p2.id 
                          WHERE p2.seller_id = ? AND o2.status NOT IN ('cancelled', 'refunded')
                          AND $dateConditionO2
                      )");
$stmt->execute([$userId, $userId]);
$repeatCustomers = $stmt->fetch(PDO::FETCH_ASSOC)['repeat_customers'] ?? 0;

$customerRetentionRate = $uniqueOrders > 0 ? ($repeatCustomers / $uniqueOrders) * 100 : 0;

// Get order status counts for the selected period
$stmt = $pdo->prepare("SELECT o.status, COUNT(*) as count FROM orders o
                      JOIN order_items oi ON o.id = oi.order_id 
                      JOIN products p ON oi.product_id = p.id 
                      WHERE p.seller_id = ? 
                      AND $dateCondition
                      GROUP BY o.status");
$stmt->execute([$userId]);
$statusResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statusCounts = [
    'pending' => 0,
    'processing' => 0,
    'shipped' => 0,
    'delivered' => 0,
    'cancelled' => 0,
    'refunded' => 0
];

foreach ($statusResults as $status) {
    if (isset($statusCounts[$status['status']])) {
        $statusCounts[$status['status']] = $status['count'];
    }
}

// Order completion rate (delivered vs total)
$totalOrdersAll = array_sum($statusCounts);
$orderCompletionRate = $totalOrdersAll > 0 ? ($statusCounts['delivered'] / $totalOrdersAll) * 100 : 0;

// 7. Pending Revenue - Based on selected period
$stmt = $pdo->prepare("SELECT COALESCE(SUM(oi.price * oi.quantity), 0) as total FROM order_items oi 
                      JOIN products p ON oi.product_id = p.id 
                      JOIN orders o ON oi.order_id = o.id
                      WHERE p.seller_id = ? 
                      AND o.payment_method IN ('cod', 'cash_on_delivery') 
                      AND o.status IN ('pending', 'processing', 'shipped')
                      AND o.status NOT IN ('cancelled', 'refunded', 'delivered')
                      AND $dateCondition");
$stmt->execute([$userId]);
$pendingRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Get status counts for selected period
$periodLabels = [
    'weekly' => 'Last 7 Days',
    'monthly' => 'Last Month',
    '6months' => 'Last 6 Months',
    'yearly' => 'Last Year',
    'custom' => !empty($customStartDate) && !empty($customEndDate) ? 
        date('M j', strtotime($customStartDate)) . ' - ' . date('M j, Y', strtotime($customEndDate)) : 
        'Custom Range'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Analytics</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        body {
            background: #f0f2f5 !important;
            min-height: 100vh;
            color: #130325 !important;
            font-size: 14px;
        }
        
        .time-period-selector {
            background: #ffffff !important;
            border: 1px solid rgba(0, 0, 0, 0.1) !important;
            border-radius: 8px;
            padding: 20px !important;
            margin-bottom: 24px !important;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1) !important;
        }
        
        .period-dropdown {
            padding: 10px 14px !important;
            background: #ffffff !important;
            border: 1px solid #ddd !important;
            border-radius: 8px;
            color: #130325 !important;
            font-size: 13px !important;
            cursor: pointer;
            transition: all 0.2s ease;
            min-width: 160px;
            font-weight: 500;
        }
        
        .period-dropdown:hover {
            border-color: #FFD736 !important;
            box-shadow: 0 0 0 3px rgba(255,215,54,0.1) !important;
        }
        
        .period-dropdown:focus {
            outline: none;
            border-color: #FFD736 !important;
            box-shadow: 0 0 0 3px rgba(255,215,54,0.1) !important;
        }
        
        .period-dropdown option {
            background: #ffffff !important;
            color: #130325 !important;
        }
        
        /* Custom Date Range Picker Styles */
        .custom-date-range {
            background: #ffffff !important;
            border: 1px solid rgba(0, 0, 0, 0.1) !important;
            border-radius: 8px;
            padding: 8px;
            transition: all 0.2s ease;
        }
        
        .date-inputs {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .date-input {
            padding: 8px 12px !important;
            background: #ffffff !important;
            border: 1px solid #ddd !important;
            border-radius: 6px;
            color: #130325 !important;
            font-size: 13px;
            transition: all 0.2s ease;
            min-width: 100px;
            height: 36px;
        }
        
        /* Custom date input styling */
        .date-input::-webkit-calendar-picker-indicator {
            filter: none !important;
            opacity: 0.7;
        }
        
        .date-input::-webkit-datetime-edit-text {
            color: #130325 !important;
        }
        
        .date-input::-webkit-datetime-edit-month-field,
        .date-input::-webkit-datetime-edit-day-field,
        .date-input::-webkit-datetime-edit-year-field {
            color: #130325 !important;
        }
        
        .date-input:focus {
            outline: none;
            border-color: #FFD736 !important;
            box-shadow: 0 0 0 3px rgba(255,215,54,0.1) !important;
            background: #ffffff !important;
        }
        
        .date-input::placeholder {
            color: #9ca3af !important;
        }
        
        .date-separator {
            color: #130325 !important;
            font-weight: 600;
            font-size: 13px;
        }
        
        .apply-date-btn {
            background: #FFD736;
            color: #130325;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 4px;
            height: 36px;
        }
        
        .apply-date-btn:hover {
            background: #e6c230;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(255,215,54,0.4);
        }
        
        .apply-date-btn:active {
            transform: translateY(0);
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .date-inputs {
                flex-direction: column;
                align-items: stretch;
            }
            
            .date-input {
                min-width: auto;
                width: 100%;
            }
            
            .date-separator {
                text-align: center;
            }
        }
        
        .period-info {
            margin-top: 10px;
            font-size: 13px;
            color: #6b7280 !important;
        }
        
        .stat-card {
            background: #ffffff !important;
            padding: 16px !important;
            border-radius: 8px !important;
            border: 1px solid rgba(0, 0, 0, 0.1) !important;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1) !important;
            text-align: center;
            border-left: 4px solid #FFD736;
            color: #130325 !important;
            transition: all 0.2s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        /* New KPI Card Styles */
        .kpi-card {
            background: #ffffff !important;
            border: 1px solid rgba(0, 0, 0, 0.1) !important;
            border-radius: 8px;
            padding: 18px !important;
            text-align: center;
            color: #130325 !important;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1) !important;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
            min-height: 110px !important;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-color: rgba(0, 0, 0, 0.15);
        }
        
        .kpi-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-bottom: 8px;
        }
        
        .kpi-header i {
            font-size: 16px;
        }
        
        .kpi-header h3 {
            font-size: 12px !important;
            font-weight: 600;
            color: #6b7280 !important;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .kpi-value {
            font-size: 22px !important;
            font-weight: 700 !important;
            margin: 8px 0 !important;
            color: #130325 !important;
            line-height: 1.2;
        }
        
        .kpi-subtitle {
            font-size: 12px !important;
            color: #6b7280 !important;
            margin-bottom: 4px !important;
            font-weight: 400;
        }
        
        /* Widened KPI Card */
        .kpi-card-wide {
            grid-column: span 2; /* Takes up 2 columns on large screens */
        }
        
        @media (max-width: 1024px) {
            .kpi-card-wide {
                grid-column: span 1; /* Back to single column on smaller screens */
            }
        }
        
        .stat-value {
            font-size: 20px !important;
            font-weight: 700 !important;
            margin: 10px 0 !important;
            color: #130325 !important;
        }

        /* Export Section Styling */
        .export-section {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 20px;
            backdrop-filter: blur(10px);
        }

        .export-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .export-csv {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .export-csv:hover {
            background: linear-gradient(135deg, #059669, #047857);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
        }

        .export-pdf {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .export-pdf:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(239, 68, 68, 0.3);
        }

        .export-print {
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: white;
        }

        .export-print:hover {
            background: linear-gradient(135deg, #4f46e5, #4338ca);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.3);
        }

        /* Download Icon Button */
        .download-icon-btn {
            background: #FFD736 !important;
            border: none !important;
            border-radius: 8px !important;
            padding: 10px 18px !important;
            height: auto !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 8px !important;
            cursor: pointer !important;
            transition: all 0.2s ease !important;
            font-weight: 600 !important;
            font-size: 13px !important;
            color: #130325 !important;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05) !important;
        }

        .download-icon-btn:hover {
            background: #f5d026 !important;
            transform: translateY(-1px) !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1) !important;
        }

        .download-icon-btn:active {
            transform: translateY(0) !important;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05) !important;
        }

        .download-icon-btn i {
            color: #130325 !important;
            font-size: 14px !important;
        }

        /* Export Modal Styling - Matching seller-dashboard.php */
        .modal {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
            z-index: 9999 !important;
            background-color: rgba(0, 0, 0, 0.5) !important;
            display: none !important;
            align-items: center !important;
            justify-content: center !important;
        }
        
        .modal.show {
            display: flex !important;
        }
        
        .modal-dialog {
            max-width: 400px;
            width: 90%;
            margin: 0 !important;
            position: relative;
            z-index: 10000;
        }
        
        .modal-content {
            background: rgb(230, 230, 230) !important; /* Off-white */
            border: none !important;
            border-radius: 12px !important;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15) !important;
            position: relative;
            z-index: 10001;
        }
        
        .modal-header {
            background: rgb(230, 230, 230) !important; /* Off-white */
            color: #333 !important;
            border-bottom: 1px solid #e5e5e5 !important;
            padding: 20px 20px 15px 20px !important;
            border-radius: 12px 12px 0 0 !important;
            position: relative !important;
        }
        
        .modal-title {
            font-weight: 600 !important;
            font-size: 1.1rem !important;
            color: #333 !important;
            margin: 0 !important;
        }
        
        .modal-body {
            padding: 20px !important;
            background: rgb(230, 230, 230) !important; /* Off-white */
        }
        
        .modal-footer {
            border-top: 1px solid #e5e5e5 !important;
            padding: 15px 20px !important;
            background: rgb(230, 230, 230) !important; /* Off-white */
            border-radius: 0 0 12px 12px !important;
        }
        
        .form-label {
            font-weight: 500 !important;
            color: #333 !important;
            margin-bottom: 8px !important;
            font-size: 0.9rem !important;
        }
        
        .form-select {
            background: rgb(230, 230, 230) !important; /* Off-white */
            border: 1px solid #ddd !important;
            border-radius: 8px !important;
            padding: 10px 12px !important;
            font-size: 0.9rem !important;
            color: #333 !important;
            transition: all 0.3s ease !important;
            width: 100% !important;
        }
        
        .form-select:focus {
            background: rgb(230, 230, 230) !important; /* Off-white */
            border-color: #007bff !important;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25) !important;
            outline: none !important;
            color: #333 !important;
        }
        
        .form-select option {
            background: rgb(230, 230, 230) !important; /* Off-white */
            color: #333 !important;
            padding: 8px !important;
        }
        
        .btn-close {
            background: transparent !important;
            border: none !important;
            border-radius: 0 !important;
            width: auto !important;
            height: auto !important;
            opacity: 1 !important;
            color: #dc3545 !important;
            font-size: 24px !important;
            font-weight: bold !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            transition: all 0.2s ease !important;
            position: absolute !important;
            top: 15px !important;
            right: 15px !important;
            padding: 0 !important;
            cursor: pointer !important;
        }
        
        .btn-close:hover {
            background: transparent !important;
            color: #c82333 !important;
            transform: scale(1.15) !important;
        }
        
        .btn-close:active {
            transform: scale(1.05) !important;
        }

        .btn-cancel {
            background: #dc3545 !important;
            color: #ffffff !important;
            border: none !important;
            border-radius: 8px !important;
            padding: 8px 16px !important;
            font-size: 0.9rem !important;
            font-weight: 500 !important;
            cursor: pointer !important;
            transition: all 0.3s ease !important;
        }
        
        .btn-cancel:hover {
            background: #c82333 !important;
        }
        
        .btn-export {
            background: #FFD736 !important;
            color: #130325 !important;
            border: none !important;
            border-radius: 8px !important;
            padding: 8px 16px !important;
            font-size: 0.9rem !important;
            font-weight: 600 !important;
            cursor: pointer !important;
            transition: all 0.3s ease !important;
        }
        
        .btn-export:hover {
            background: #FFA500 !important;
        }
        
        .error-message {
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            color: #dc3545 !important;
            font-size: 0.85rem !important;
            margin-top: 8px !important;
            padding: 8px 12px !important;
            background: rgba(220, 53, 69, 0.1) !important;
            border: 1px solid rgba(220, 53, 69, 0.3) !important;
            border-radius: 6px !important;
        }
        
        .error-message i {
            font-size: 0.9rem !important;
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .loading-overlay.show {
            opacity: 1;
            visibility: visible;
        }
        
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-top: 4px solid #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>

<body>
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>

    <div class="container mx-auto px-4 py-8">
        <h1 style="color: #130325; font-size: 32px; font-weight: 700; margin: 0 0 24px 0; text-align: left; text-shadow: none !important;">
            Sales Analytics
        </h1>


        <!-- Time Period Selector -->
        <div class="time-period-selector" data-aos="fade-down">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-xl font-bold mb-2">Select Time Period</h2>
                </div>
                <div class="flex flex-col md:flex-row md:items-center gap-4">
                    <!-- Custom Date Range Picker -->
                    <div class="custom-date-range">
                        <div class="date-inputs">
                            <input type="date" id="startDate" class="date-input" 
                                   value="<?php echo $customStartDate; ?>" 
                                   placeholder="Start Date"
                                   title="Start Date">
                            <span class="date-separator">to</span>
                            <input type="date" id="endDate" class="date-input" 
                                   value="<?php echo $customEndDate; ?>" 
                                   placeholder="End Date"
                                   title="End Date">
                            <button type="button" class="apply-date-btn" onclick="applyCustomDateRange()">
                                <i class="fas fa-check"></i> Apply
                            </button>
                        </div>
                    </div>
                    
                    <select id="timePeriodSelector" class="period-dropdown" onchange="changePeriod(this.value)">
                        <option value="weekly" <?php echo $selectedPeriod === 'weekly' ? 'selected' : ''; ?>>Last 7 Days</option>
                        <option value="monthly" <?php echo $selectedPeriod === 'monthly' ? 'selected' : ''; ?>>Last Month</option>
                        <option value="6months" <?php echo $selectedPeriod === '6months' ? 'selected' : ''; ?>>Last 6 Months</option>
                        <option value="yearly" <?php echo $selectedPeriod === 'yearly' ? 'selected' : ''; ?>>Last Year</option>
                    </select>
                    <div class="period-badge">
                        <?php echo $periodLabels[$selectedPeriod]; ?>
                    </div>
                </div>
            </div>
            <div class="period-info">
                Showing data from 
                <?php
                $startDate = '';
                $endDate = date('M j, Y');
                
                switch($selectedPeriod) {
                    case 'weekly':
                        $startDate = date('M j, Y', strtotime('-7 days'));
                        break;
                    case 'monthly':
                        $startDate = date('M j, Y', strtotime('-1 month'));
                        break;
                    case '6months':
                        $startDate = date('M j, Y', strtotime('-6 months'));
                        break;
                    case 'yearly':
                        $startDate = date('M j, Y', strtotime('-1 year'));
                        break;
                    case 'custom':
                        if (!empty($customStartDate) && !empty($customEndDate)) {
                            $startDate = date('M j, Y', strtotime($customStartDate));
                            $endDate = date('M j, Y', strtotime($customEndDate));
                        } else {
                            $startDate = 'Select dates';
                            $endDate = '';
                        }
                        break;
                }
                
                if ($selectedPeriod === 'custom' && empty($customStartDate)) {
                    echo 'Select custom date range';
                } else {
                    echo $startDate . ($endDate ? ' to ' . $endDate : '');
                }
                ?>
            </div>
        </div>

        <!-- Growth Analytics -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <!-- Revenue Growth Rate - DOUBLE WIDTH -->
            <div class="kpi-card kpi-card-wide" data-aos="fade-up" data-aos-delay="100">
                <div class="kpi-header">
                    <i class="fas fa-chart-line text-green-400"></i>
                    <h3>Revenue Growth</h3>
                </div>
                <div class="kpi-value <?php echo $revenueGrowthRate >= 0 ? 'text-green-400' : 'text-red-400'; ?>">
                    <?php echo $revenueGrowthRate >= 0 ? '+' : ''; ?><?php echo number_format($revenueGrowthRate, 1); ?>%
                </div>
                <div class="kpi-subtitle">
                    ₱<?php echo number_format($confirmedRevenue, 2); ?> vs ₱<?php echo number_format($previousConfirmedRevenue, 2); ?>
                </div>
                <div class="period-badge"><?php echo $periodLabels[$selectedPeriod]; ?></div>
            </div>
            
            <!-- Order Growth Rate -->
            <div class="kpi-card" data-aos="fade-up" data-aos-delay="200">
                <div class="kpi-header">
                    <i class="fas fa-shopping-cart text-blue-400"></i>
                    <h3>Order Growth</h3>
                </div>
                <div class="kpi-value <?php echo $orderGrowthRate >= 0 ? 'text-green-400' : 'text-red-400'; ?>">
                    <?php echo $orderGrowthRate >= 0 ? '+' : ''; ?><?php echo number_format($orderGrowthRate, 1); ?>%
                </div>
                <div class="kpi-subtitle">
                    <?php echo number_format($uniqueOrders); ?> vs <?php echo number_format($previousUniqueOrders); ?> orders
                </div>
                <div class="period-badge"><?php echo $periodLabels[$selectedPeriod]; ?></div>
            </div>
            
            <!-- Sales Growth Rate -->
            <div class="kpi-card" data-aos="fade-up" data-aos-delay="300">
                <div class="kpi-header">
                    <i class="fas fa-box text-purple-400"></i>
                    <h3>Sales Growth</h3>
                </div>
                <div class="kpi-value <?php echo $salesGrowthRate >= 0 ? 'text-green-400' : 'text-red-400'; ?>">
                    <?php echo $salesGrowthRate >= 0 ? '+' : ''; ?><?php echo number_format($salesGrowthRate, 1); ?>%
                </div>
                <div class="kpi-subtitle">
                    <?php echo number_format($totalSales); ?> vs <?php echo number_format($previousTotalSales); ?> items
                </div>
                <div class="period-badge"><?php echo $periodLabels[$selectedPeriod]; ?></div>
            </div>
        </div>

        <!-- Performance Metrics -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <!-- Customer Retention Rate -->
            <div class="kpi-card" data-aos="fade-up" data-aos-delay="400">
                <div class="kpi-header">
                    <i class="fas fa-users text-yellow-400"></i>
                    <h3>Customer Retention</h3>
                </div>
                <div class="kpi-value"><?php echo number_format($customerRetentionRate, 1); ?>%</div>
                <div class="kpi-subtitle"><?php echo $repeatCustomers; ?> repeat customers</div>
                <div class="period-badge"><?php echo $periodLabels[$selectedPeriod]; ?></div>
            </div>
            
            <!-- Order Completion Rate -->
            <div class="kpi-card" data-aos="fade-up" data-aos-delay="500">
                <div class="kpi-header">
                    <i class="fas fa-check-circle text-green-400"></i>
                    <h3>Completion Rate</h3>
                </div>
                <div class="kpi-value"><?php echo number_format($orderCompletionRate, 1); ?>%</div>
                <div class="kpi-subtitle"><?php echo $statusCounts['delivered']; ?> delivered</div>
                <div class="period-badge"><?php echo $periodLabels[$selectedPeriod]; ?></div>
            </div>
            
            <!-- Return Rate -->
            <div class="kpi-card" data-aos="fade-up" data-aos-delay="600">
                <div class="kpi-header">
                    <i class="fas fa-undo text-red-400"></i>
                    <h3>Return Rate</h3>
                </div>
                <div class="kpi-value"><?php echo number_format($returnRate, 1); ?>%</div>
                <div class="kpi-subtitle"><?php echo $returnStats['total_returns'] ?? 0; ?> returns</div>
                <div class="period-badge"><?php echo $periodLabels[$selectedPeriod]; ?></div>
            </div>
            
            <!-- Avg Order Value -->
            <div class="kpi-card" data-aos="fade-up" data-aos-delay="700">
                <div class="kpi-header">
                    <i class="fas fa-dollar-sign text-indigo-400"></i>
                    <h3>Avg Order Value</h3>
                </div>
                <div class="kpi-value">₱<?php echo number_format($avgOrderValue, 2); ?></div>
                <div class="kpi-subtitle">Per order</div>
                <div class="period-badge"><?php echo $periodLabels[$selectedPeriod]; ?></div>
            </div>
        </div>

        <!-- Order Status Overview -->
        <div class="bg-white rounded-lg shadow-sm p-4 mb-6" style="border: 1px solid rgba(0,0,0,0.1);" data-aos="fade-up">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold" style="color: #130325;">
                    Order Status Overview
                    <span class="period-badge ml-3"><?php echo $periodLabels[$selectedPeriod]; ?></span>
                </h2>
                <button onclick="showExportModal()" class="download-icon-btn" title="Export Analytics Data">
                    <i class="fas fa-download"></i> Export Data
                </button>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                <div class="status-card bg-blue-50 rounded-lg p-3 text-center" style="border: 1px solid rgba(0,0,0,0.1);">
                    <span class="text-xl font-bold block" style="color: #1e40af;"><?php echo $statusCounts['pending']; ?></span>
                    <span class="text-xs" style="color: #6b7280;">Pending</span>
                </div>
                <div class="status-card bg-yellow-50 rounded-lg p-3 text-center" style="border: 1px solid rgba(0,0,0,0.1);">
                    <span class="text-xl font-bold block" style="color: #d97706;"><?php echo $statusCounts['processing']; ?></span>
                    <span class="text-xs" style="color: #6b7280;">Processing</span>
                </div>
                <div class="status-card bg-purple-50 rounded-lg p-3 text-center" style="border: 1px solid rgba(0,0,0,0.1);">
                    <span class="text-xl font-bold block" style="color: #7c3aed;"><?php echo $statusCounts['shipped']; ?></span>
                    <span class="text-xs" style="color: #6b7280;">Shipped</span>
                </div>
                <div class="status-card bg-green-50 rounded-lg p-3 text-center" style="border: 1px solid rgba(0,0,0,0.1);">
                    <span class="text-xl font-bold block" style="color: #059669;"><?php echo $statusCounts['delivered']; ?></span>
                    <span class="text-xs" style="color: #6b7280;">Delivered</span>
                </div>
                <div class="status-card bg-red-50 rounded-lg p-3 text-center" style="border: 1px solid rgba(0,0,0,0.1);">
                    <span class="text-xl font-bold block" style="color: #dc2626;"><?php echo $statusCounts['cancelled']; ?></span>
                    <span class="text-xs" style="color: #6b7280;">Cancelled</span>
                </div>
                <div class="status-card bg-orange-50 rounded-lg p-3 text-center" style="border: 1px solid rgba(0,0,0,0.1);">
                    <span class="text-xl font-bold block" style="color: #ea580c;"><?php echo $statusCounts['refunded']; ?></span>
                    <span class="text-xs" style="color: #6b7280;">Refunded</span>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="bg-white rounded-lg shadow-sm p-4" style="border: 1px solid rgba(0,0,0,0.1);" data-aos="fade-up" data-aos-delay="100">
                <h2 class="text-lg font-bold mb-3" style="color: #130325;">
                    Revenue Trend
                    <span class="period-badge ml-3"><?php echo $periodLabels[$selectedPeriod]; ?></span>
                </h2>
                <div style="height: 280px; position: relative;">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm p-4" style="border: 1px solid rgba(0,0,0,0.1);" data-aos="fade-up" data-aos-delay="200">
                <h2 class="text-lg font-bold mb-3" style="color: #130325;">
                    Top Products
                    <span class="period-badge ml-3"><?php echo $periodLabels[$selectedPeriod]; ?></span>
                </h2>
                <div style="height: 280px; position: relative;">
                    <canvas id="productsChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Detailed Analytics Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="bg-white rounded-lg shadow-sm p-4" style="border: 1px solid rgba(0,0,0,0.1);" data-aos="fade-up" data-aos-delay="300">
                <h2 class="text-base font-bold mb-3" style="color: #130325;">Payment Methods</h2>
                <div style="height: 240px; position: relative;">
                    <canvas id="paymentMethodsChart"></canvas>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm p-4" style="border: 1px solid rgba(0,0,0,0.1);" data-aos="fade-up" data-aos-delay="400">
                <h2 class="text-base font-bold mb-3" style="color: #130325;">Performance Metrics</h2>
                <div class="space-y-3">
                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg" style="border: 1px solid rgba(0,0,0,0.05);">
                        <span style="color: #6b7280; font-size: 13px;">Conversion Rate</span>
                        <span class="text-base font-bold" style="color: #059669;">
                            <?php echo $totalProducts > 0 ? number_format(($totalSales / $totalProducts) * 100, 1) : 0; ?>%
                        </span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg" style="border: 1px solid rgba(0,0,0,0.05);">
                        <span style="color: #6b7280; font-size: 13px;">Revenue per Product</span>
                        <span class="text-base font-bold" style="color: #1e40af;">
                            ₱<?php echo $totalProducts > 0 ? number_format($expectedRevenue / $totalProducts, 2) : 0; ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg" style="border: 1px solid rgba(0,0,0,0.05);">
                        <span style="color: #6b7280; font-size: 13px;">Items per Order</span>
                        <span class="text-base font-bold" style="color: #7c3aed;">
                            <?php echo $uniqueOrders > 0 ? number_format($totalSales / $uniqueOrders, 1) : 0; ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg" style="border: 1px solid rgba(0,0,0,0.05);">
                        <span style="color: #6b7280; font-size: 13px;">Fulfillment Rate</span>
                        <span class="text-base font-bold" style="color: #d97706;">
                            <?php 
                            $totalOrders = array_sum($statusCounts);
                            echo $totalOrders > 0 ? number_format(($statusCounts['delivered'] / $totalOrders) * 100, 1) : 0; 
                            ?>%
                        </span>
                    </div>
                </div>
            </div>

            <!-- Returns Analysis - SEPARATE CARD -->
            <div class="bg-white rounded-lg shadow-sm p-4" style="border: 1px solid rgba(0,0,0,0.1);" data-aos="fade-up" data-aos-delay="600">
                <h2 class="text-base font-bold mb-3" style="color: #130325;">Returns Analysis</h2>
                <div class="space-y-3">
                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg" style="border: 1px solid rgba(0,0,0,0.05);">
                        <span style="color: #6b7280; font-size: 13px;">Return Rate</span>
                        <span class="text-base font-bold" style="color: #ea580c;">
                            <?php echo number_format($returnRate, 1); ?>%
                        </span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg" style="border: 1px solid rgba(0,0,0,0.05);">
                        <span style="color: #6b7280; font-size: 13px;">Pending Returns</span>
                        <span class="text-base font-bold" style="color: #d97706;">
                            <?php echo $returnStats['pending_returns'] ?? 0; ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg" style="border: 1px solid rgba(0,0,0,0.05);">
                        <span style="color: #6b7280; font-size: 13px;">Approved Refunds</span>
                        <span class="text-base font-bold" style="color: #059669;">
                            ₱<?php echo number_format($periodRefundAmount, 2); ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg" style="border: 1px solid rgba(0,0,0,0.05);">
                        <span style="color: #6b7280; font-size: 13px;">Approval Rate</span>
                        <span class="text-base font-bold" style="color: #1e40af;">
                            <?php 
                            $totalProcessed = ($returnStats['approved_returns'] ?? 0) + ($returnStats['rejected_returns'] ?? 0);
                            $approvalRate = $totalProcessed > 0 ? (($returnStats['approved_returns'] ?? 0) / $totalProcessed) * 100 : 0;
                            echo number_format($approvalRate, 1); 
                            ?>%
                        </span>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="seller-returns.php" class="w-full inline-flex justify-center items-center px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white text-sm font-medium rounded-lg transition duration-300">
                        Manage Returns →
                    </a>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow-sm p-4" style="border: 1px solid rgba(0,0,0,0.1);" data-aos="fade-up" data-aos-delay="500">
                <h2 class="text-base font-bold mb-3" style="color: #130325;">Order Status Flow</h2>
                <div style="height: 240px; position: relative;">
                    <canvas id="statusFlowChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Sales Summary Table -->
        <div class="bg-white rounded-lg shadow p-6 mb-8" style="border: 1px solid rgba(0,0,0,0.1);" data-aos="fade-up" data-aos-delay="600">
            <h2 class="text-2xl font-bold mb-6" style="color: #130325 !important;">
                Sales Summary
                <span class="period-badge ml-3"><?php echo $periodLabels[$selectedPeriod]; ?></span>
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b" style="border-color: #e5e7eb;">
                            <th class="pb-3 font-semibold" style="color: #130325 !important;">Metric</th>
                            <th class="pb-3 font-semibold text-right" style="color: #130325 !important;">Value</th>
                            <th class="pb-3 font-semibold text-right" style="color: #130325 !important;">Percentage</th>
                        </tr>
                    </thead>
                    <tbody style="color: #130325 !important;">
                        <tr class="border-b hover:bg-gray-50 transition-colors" style="border-color: #e5e7eb;">
                            <td class="py-3" style="color: #130325 !important;">Expected Revenue</td>
                            <td class="py-3 text-right font-bold" style="color: #1e40af !important;">₱<?php echo number_format($expectedRevenue, 2); ?></td>
                            <td class="py-3 text-right" style="color: #130325 !important;">100%</td>
                        </tr>
                        <tr class="border-b hover:bg-gray-50 transition-colors" style="border-color: #e5e7eb;">
                            <td class="py-3 pl-4" style="color: #130325 !important;">↳ Confirmed</td>
                            <td class="py-3 text-right font-bold" style="color: #059669 !important;">₱<?php echo number_format($confirmedRevenue, 2); ?></td>
                            <td class="py-3 text-right" style="color: #130325 !important;">
                                <?php echo $expectedRevenue > 0 ? number_format(($confirmedRevenue / $expectedRevenue) * 100, 1) : 0; ?>%
                            </td>
                        </tr>
                        <tr class="border-b hover:bg-gray-50 transition-colors" style="border-color: #e5e7eb;">
                            <td class="py-3 pl-4" style="color: #130325 !important;">↳ Pending</td>
                            <td class="py-3 text-right font-bold" style="color: #d97706 !important;">₱<?php echo number_format($pendingRevenue, 2); ?></td>
                            <td class="py-3 text-right" style="color: #130325 !important;">
                                <?php echo $expectedRevenue > 0 ? number_format(($pendingRevenue / $expectedRevenue) * 100, 1) : 0; ?>%
                            </td>
                        </tr>
                        <tr class="border-b hover:bg-gray-50 transition-colors" style="border-color: #e5e7eb;">
                            <td class="py-3" style="color: #130325 !important;">Total Orders</td>
                            <td class="py-3 text-right font-bold" style="color: #130325 !important;"><?php echo number_format($uniqueOrders); ?></td>
                            <td class="py-3 text-right" style="color: #130325 !important;">-</td>
                        </tr>
                        <tr class="border-b hover:bg-gray-50 transition-colors" style="border-color: #e5e7eb;">
                            <td class="py-3" style="color: #130325 !important;">Average Order Value</td>
                            <td class="py-3 text-right font-bold" style="color: #7c3aed !important;">₱<?php echo number_format($avgOrderValue, 2); ?></td>
                            <td class="py-3 text-right" style="color: #130325 !important;">-</td>
                        </tr>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="py-3" style="color: #130325 !important;">Total Items Sold</td>
                            <td class="py-3 text-right font-bold" style="color: #dc2626 !important;"><?php echo number_format($totalSales); ?></td>
                            <td class="py-3 text-right" style="color: #130325 !important;">-</td>
                        </tr>
                        <tr class="border-b hover:bg-gray-50 transition-colors" style="border-color: #e5e7eb;">
                            <td class="py-3" style="color: #130325 !important;">Refunded Amount</td>
                            <td class="py-3 text-right font-bold" style="color: #ea580c !important;">₱<?php echo number_format($periodRefundAmount, 2); ?></td>
                            <td class="py-3 text-right" style="color: #130325 !important;">
                                <?php echo $expectedRevenue > 0 ? number_format(($periodRefundAmount / $expectedRevenue) * 100, 1) : 0; ?>%
                            </td>
                        </tr>
                        <tr class="border-b hover:bg-gray-50 transition-colors" style="border-color: #e5e7eb;">
                            <td class="py-3" style="color: #130325 !important;">Net Revenue (After Returns)</td>
                            <td class="py-3 text-right font-bold" style="color: #7c3aed !important;">₱<?php echo number_format($confirmedRevenue - $periodRefundAmount, 2); ?></td>
                            <td class="py-3 text-right" style="color: #130325 !important;">
                                <?php echo $expectedRevenue > 0 ? number_format((($confirmedRevenue - $periodRefundAmount) / $expectedRevenue) * 100, 1) : 0; ?>%
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Export Modal -->
    <div class="modal fade" id="exportModal" tabindex="-1" aria-hidden="true" style="display: none;">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius:12px; overflow:hidden; border:none; box-shadow:0 10px 40px rgba(0,0,0,0.2);">
                <div class="modal-header" style="background:#130325; color:#ffffff; display:flex; align-items:center; justify-content:space-between;">
                    <h5 class="modal-title" style="margin:0; font-weight:700; color:#ffffff;">Export Data</h5>
                    <button type="button" onclick="closeExportModal()" aria-label="Close" style="background:transparent; border:none; color:#ffffff; font-size:20px; cursor:pointer; line-height:1;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body" style="padding:16px;">
                    <div class="mb-3">
                        <label for="exportFormat" class="form-label" style="color:#130325; font-weight:700;">Format</label>
                        <select class="form-select" id="exportFormat" required style="border:none; box-shadow: inset 0 0 0 1px rgba(0,0,0,0.08);">
                            <option value="">Select format...</option>
                            <option value="csv">CSV</option>
                            <option value="pdf">PDF</option>
                        </select>
                        <div id="exportError" class="error-message" style="display: none; color:#dc3545; margin-top:8px;">
                            <i class="fas fa-exclamation-circle"></i>
                            <span>Please select an export format.</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="padding: 12px 16px; border-top:none; display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" onclick="closeExportModal()" style="padding:8px 16px; background:#f3f4f6; color:#130325; border:none; border-radius:6px; font-weight:600;">Cancel</button>
                    <button type="button" onclick="confirmExport()" style="padding:8px 16px; background:#130325; color:#ffffff; border:none; border-radius:6px; font-weight:600;">Export</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize AOS
        AOS.init({
            duration: 800,
            easing: 'ease-in-out',
            once: true
        });

        // Chart.js default configuration
        Chart.defaults.color = '#130325';
        Chart.defaults.borderColor = 'rgba(0, 0, 0, 0.1)';

          <?php
     $chartLabels = [];
        $dateFormat = '';
        $groupBy = '';
        
        switch($selectedPeriod) {
            case 'weekly':
                $chartLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                $dateFormat = '%w'; // Day of week (0=Sunday, 1=Monday, etc.)
                $groupBy = 'DAYOFWEEK(o.created_at)';
                break;
            case 'monthly':
                $chartLabels = ['Week 1', 'Week 2', 'Week 3', 'Week 4'];
                $dateFormat = 'WEEK';
                $groupBy = 'WEEK(o.created_at, 1)';
                break;
            case '6months':
                $chartLabels = ['Month 1', 'Month 2', 'Month 3', 'Month 4', 'Month 5', 'Month 6'];
                $dateFormat = '%Y-%m';
                $groupBy = 'DATE_FORMAT(o.created_at, "%Y-%m")';
                break;
            case 'yearly':
                $chartLabels = ['Jan-Feb', 'Mar-Apr', 'May-Jun', 'Jul-Aug', 'Sep-Oct', 'Nov-Dec'];
                $dateFormat = 'BIMONTH';
                $groupBy = 'FLOOR((MONTH(o.created_at) - 1) / 2)';
                break;
            default:
                $chartLabels = ['Month 1', 'Month 2', 'Month 3', 'Month 4', 'Month 5', 'Month 6'];
                $dateFormat = '%Y-%m';
                $groupBy = 'DATE_FORMAT(o.created_at, "%Y-%m")';
        }
        
        // Get REAL Expected Revenue data grouped by time period
        $stmt = $pdo->prepare("SELECT 
                                  $groupBy as time_group,
                                  COALESCE(SUM(oi.price * oi.quantity), 0) as revenue
                              FROM order_items oi 
                              JOIN products p ON oi.product_id = p.id 
                              JOIN orders o ON oi.order_id = o.id
                              WHERE p.seller_id = ? 
                              AND o.status NOT IN ('cancelled', 'refunded')
                              AND $dateCondition
                              GROUP BY time_group
                              ORDER BY time_group ASC");
        $stmt->execute([$userId]);
        $expectedRevenueData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get REAL Confirmed Revenue data grouped by time period
        $stmt = $pdo->prepare("SELECT 
                                  $groupBy as time_group,
                                  COALESCE(SUM(oi.price * oi.quantity), 0) as revenue
                              FROM order_items oi 
                              JOIN products p ON oi.product_id = p.id 
                              JOIN orders o ON oi.order_id = o.id
                              WHERE p.seller_id = ? 
                              AND (
                                  (o.payment_method IN ('paypal', 'gcash', 'credit_card', 'debit_card') 
                                   AND o.status NOT IN ('cancelled', 'refunded'))
                                  OR 
                                  (o.payment_method = 'cod' AND o.status = 'delivered')
                              )
                              AND $dateCondition
                              GROUP BY time_group
                              ORDER BY time_group ASC");
        $stmt->execute([$userId]);
        $confirmedRevenueData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create arrays for chart data
        $expectedDataPoints = array_fill(0, count($chartLabels), 0);
        $confirmedDataPoints = array_fill(0, count($chartLabels), 0);
        
        // Map database results to chart data points
        if ($selectedPeriod === 'weekly') {
            foreach ($expectedRevenueData as $data) {
                $dayOfWeek = $data['time_group'];
                // Convert MySQL day (1=Sunday) to our array index (0=Monday)
                $index = ($dayOfWeek == 1) ? 6 : $dayOfWeek - 2;
                if ($index >= 0 && $index < 7) {
                    $expectedDataPoints[$index] = round($data['revenue'], 2);
                }
            }
            foreach ($confirmedRevenueData as $data) {
                $dayOfWeek = $data['time_group'];
                $index = ($dayOfWeek == 1) ? 6 : $dayOfWeek - 2;
                if ($index >= 0 && $index < 7) {
                    $confirmedDataPoints[$index] = round($data['revenue'], 2);
                }
            }
        } elseif ($selectedPeriod === 'monthly') {
            // For monthly, map weeks to Week 1-4
            $weekMapping = [];
            $weekIndex = 0;
            foreach ($expectedRevenueData as $data) {
                if ($weekIndex < 4) {
                    $expectedDataPoints[$weekIndex] = round($data['revenue'], 2);
                    $weekIndex++;
                }
            }
            $weekIndex = 0;
            foreach ($confirmedRevenueData as $data) {
                if ($weekIndex < 4) {
                    $confirmedDataPoints[$weekIndex] = round($data['revenue'], 2);
                    $weekIndex++;
                }
            }
        } elseif ($selectedPeriod === 'yearly') {
            // For yearly, map to bi-monthly periods
            foreach ($expectedRevenueData as $data) {
                $bimonth = $data['time_group'];
                if ($bimonth >= 0 && $bimonth < 6) {
                    $expectedDataPoints[$bimonth] += round($data['revenue'], 2);
                }
            }
            foreach ($confirmedRevenueData as $data) {
                $bimonth = $data['time_group'];
                if ($bimonth >= 0 && $bimonth < 6) {
                    $confirmedDataPoints[$bimonth] += round($data['revenue'], 2);
                }
            }
        } else {
            // For 6 months, map each month
            $monthIndex = 0;
            foreach ($expectedRevenueData as $data) {
                if ($monthIndex < 6) {
                    $expectedDataPoints[$monthIndex] = round($data['revenue'], 2);
                    $monthIndex++;
                }
            }
            $monthIndex = 0;
            foreach ($confirmedRevenueData as $data) {
                if ($monthIndex < 6) {
                    $confirmedDataPoints[$monthIndex] = round($data['revenue'], 2);
                    $monthIndex++;
                }
            }
        }
        ?>
        
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        const revenueChart = new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [{
                    label: 'Expected Revenue',
                    data: [<?php echo implode(',', $expectedDataPoints); ?>],
                    borderColor: 'rgb(59, 130, 246)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Confirmed Revenue',
                    data: [<?php echo implode(',', $confirmedDataPoints); ?>],
                    borderColor: 'rgb(34, 197, 94)',
                    backgroundColor: 'rgba(34, 197, 94, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            font: {
                                size: 11
                            }
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += '₱' + context.parsed.y.toFixed(2);
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            font: {
                                size: 10
                            },
                            callback: function(value) {
                                return '₱' + value.toFixed(0);
                            }
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            font: {
                                size: 10
                            }
                        }
                    }
                }
            }
        });
        

         const productsCtx = document.getElementById('productsChart').getContext('2d');
        const productsChart = new Chart(productsCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($productNames); ?>,
                datasets: [{
                    label: 'Units Sold',
                    data: <?php echo json_encode($productSales); ?>,
                    backgroundColor: [
                        'rgba(59, 130, 246, 0.8)',
                        'rgba(168, 85, 247, 0.8)',
                        'rgba(236, 72, 153, 0.8)',
                        'rgba(251, 146, 60, 0.8)',
                        'rgba(34, 197, 94, 0.8)'
                    ],
                    borderColor: [
                        'rgb(59, 130, 246)',
                        'rgb(168, 85, 247)',
                        'rgb(236, 72, 153)',
                        'rgb(251, 146, 60)',
                        'rgb(34, 197, 94)'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Units Sold: ' + context.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            font: {
                                size: 10
                            },
                            stepSize: 1
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            font: {
                                size: 10
                            },
                            maxRotation: 45,
                            minRotation: 45
                        }
                    }
                }
            }
        });

        // Payment Methods Chart
       // Payment Methods Chart - REAL DATA
        <?php
        $stmt = $pdo->prepare("SELECT 
                                  o.payment_method,
                                  COUNT(DISTINCT o.id) as order_count
                              FROM orders o 
                              JOIN order_items oi ON o.id = oi.order_id 
                              JOIN products p ON oi.product_id = p.id 
                              WHERE p.seller_id = ? 
                              AND o.status NOT IN ('cancelled', 'refunded')
                              AND $dateCondition
                              GROUP BY o.payment_method
                              ORDER BY order_count DESC");
        $stmt->execute([$userId]);
        $paymentMethodsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $paymentLabels = [];
        $paymentCounts = [];
        $paymentColors = [
            'cod' => 'rgba(251, 191, 36, 0.8)',
            'cash_on_delivery' => 'rgba(251, 191, 36, 0.8)',
            'paypal' => 'rgba(59, 130, 246, 0.8)',
            'gcash' => 'rgba(34, 197, 94, 0.8)',
            'credit_card' => 'rgba(168, 85, 247, 0.8)',
            'debit_card' => 'rgba(236, 72, 153, 0.8)'
        ];
        
        $paymentBorderColors = [
            'cod' => 'rgb(251, 191, 36)',
            'cash_on_delivery' => 'rgb(251, 191, 36)',
            'paypal' => 'rgb(59, 130, 246)',
            'gcash' => 'rgb(34, 197, 94)',
            'credit_card' => 'rgb(168, 85, 247)',
            'debit_card' => 'rgb(236, 72, 153)'
        ];
        
        $bgColors = [];
        $borderColors = [];
        
        if (count($paymentMethodsData) > 0) {
            foreach ($paymentMethodsData as $payment) {
                $method = $payment['payment_method'];
                $displayName = ucwords(str_replace('_', ' ', $method));
                if ($method == 'cod' || $method == 'cash_on_delivery') {
                    $displayName = 'COD';
                }
                $paymentLabels[] = $displayName;
                $paymentCounts[] = $payment['order_count'];
                $bgColors[] = isset($paymentColors[$method]) ? $paymentColors[$method] : 'rgba(156, 163, 175, 0.8)';
                $borderColors[] = isset($paymentBorderColors[$method]) ? $paymentBorderColors[$method] : 'rgb(156, 163, 175)';
            }
        } else {
            $paymentLabels = ['No Data'];
            $paymentCounts = [1];
            $bgColors = ['rgba(156, 163, 175, 0.8)'];
            $borderColors = ['rgb(156, 163, 175)'];
        }
        ?>
        
        const paymentCtx = document.getElementById('paymentMethodsChart').getContext('2d');
        const paymentChart = new Chart(paymentCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($paymentLabels); ?>,
                datasets: [{
                    data: <?php echo json_encode($paymentCounts); ?>,
                    backgroundColor: <?php echo json_encode($bgColors); ?>,
                    borderColor: <?php echo json_encode($borderColors); ?>,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: {
                                size: 11
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                let value = context.parsed || 0;
                                let total = context.dataset.data.reduce((a, b) => a + b, 0);
                                let percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return label + ': ' + value + ' orders (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });

        // Status Flow Chart - REAL DATA (already using real data from $statusCounts)
        const statusFlowCtx = document.getElementById('statusFlowChart').getContext('2d');
        const statusFlowChart = new Chart(statusFlowCtx, {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled', 'Refunded'],
                datasets: [{
                    data: [
                        <?php echo $statusCounts['pending']; ?>,
                        <?php echo $statusCounts['processing']; ?>,
                        <?php echo $statusCounts['shipped']; ?>,
                        <?php echo $statusCounts['delivered']; ?>,
                        <?php echo $statusCounts['cancelled']; ?>,
                        <?php echo $statusCounts['refunded']; ?>
                    ],
                    backgroundColor: [
                        'rgba(59, 130, 246, 0.8)',
                        'rgba(251, 191, 36, 0.8)',
                        'rgba(168, 85, 247, 0.8)',
                        'rgba(34, 197, 94, 0.8)',
                        'rgba(239, 68, 68, 0.8)',
                        'rgba(251, 146, 60, 0.8)'
                    ],
                    borderColor: [
                        'rgb(59, 130, 246)',
                        'rgb(251, 191, 36)',
                        'rgb(168, 85, 247)',
                        'rgb(34, 197, 94)',
                        'rgb(239, 68, 68)',
                        'rgb(251, 146, 60)'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: {
                                size: 10
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                let value = context.parsed || 0;
                                let total = context.dataset.data.reduce((a, b) => a + b, 0);
                                let percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return label + ': ' + value + ' orders (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });

        // Function to change time period
        function changePeriod(period) {
            document.getElementById('loadingOverlay').classList.add('show');
            document.body.style.opacity = '0.7';
            
            setTimeout(() => {
                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('period', period);
                // Remove custom date parameters when switching to predefined periods
                currentUrl.searchParams.delete('start_date');
                currentUrl.searchParams.delete('end_date');
                window.location.href = currentUrl.toString();
            }, 500);
        }
        
        // Function to apply custom date range
        function applyCustomDateRange() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            
            if (!startDate || !endDate) {
                alert('Please select both start and end dates.');
                return;
            }
            
            if (new Date(startDate) > new Date(endDate)) {
                alert('Start date cannot be later than end date.');
                return;
            }
            
            document.getElementById('loadingOverlay').classList.add('show');
            document.body.style.opacity = '0.7';
            
            setTimeout(() => {
                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('period', 'custom');
                currentUrl.searchParams.set('start_date', startDate);
                currentUrl.searchParams.set('end_date', endDate);
                window.location.href = currentUrl.toString();
            }, 500);
        }
        
        // Function to format date display
        function formatDateDisplay(dateString) {
            if (!dateString) return '';
            const date = new Date(dateString);
            const day = date.getDate();
            const weekNumber = Math.ceil((date.getDate() - date.getDay() + 1) / 7);
            return `${day} W${weekNumber}`;
        }
        
        // Update date inputs on page load and ensure sidebar main content adjustment
        document.addEventListener('DOMContentLoaded', function() {
            // Ensure main content adjusts to sidebar state
            const sidebar = document.getElementById('sellerSidebar');
            const main = document.querySelector('main');
            if (sidebar && main) {
                const isCollapsed = localStorage.getItem('sellerSidebarCollapsed') === 'true';
                if (isCollapsed) {
                    main.style.marginLeft = '70px';
                } else {
                    main.style.marginLeft = '240px';
                }
                
                // Override toggleSellerSidebar to ensure it works
                const originalToggle = window.toggleSellerSidebar;
                if (originalToggle) {
                    window.toggleSellerSidebar = function() {
                        originalToggle();
                        // Ensure it's set after toggle
                        setTimeout(() => {
                            if (sidebar.classList.contains('collapsed')) {
                                main.style.marginLeft = '70px';
                            } else {
                                main.style.marginLeft = '240px';
                            }
                        }, 10);
                    };
                }
            }
            
            const startDateInput = document.getElementById('startDate');
            const endDateInput = document.getElementById('endDate');
            
            // Set default dates if not already set
            if (startDateInput && !startDateInput.value) {
                const today = new Date();
                const sixMonthsAgo = new Date(today.getFullYear(), today.getMonth() - 6, today.getDate());
                startDateInput.value = sixMonthsAgo.toISOString().split('T')[0];
            }
            
            if (endDateInput && !endDateInput.value) {
                const today = new Date();
                endDateInput.value = today.toISOString().split('T')[0];
            }
        });
        

        // Enhanced hover effects for stat cards and KPI cards
        document.querySelectorAll('.stat-card, .kpi-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-8px) scale(1.02)';
                this.style.boxShadow = '0 20px 40px rgba(0,0,0,0.2)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
                this.style.boxShadow = '0 4px 6px rgba(0,0,0,0.1)';
            });
        });

        // Status card hover effects
        document.querySelectorAll('.status-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'scale(1.1)';
                this.style.zIndex = '10';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'scale(1)';
                this.style.zIndex = '1';
            });
        });

        // Smooth dropdown animation
        const dropdown = document.getElementById('timePeriodSelector');
        dropdown.addEventListener('focus', function() {
            this.style.transform = 'scale(1.05)';
        });
        
        dropdown.addEventListener('blur', function() {
            this.style.transform = 'scale(1)';
        });

        // Hide loading overlay when page loads
        window.addEventListener('load', function() {
            document.getElementById('loadingOverlay').classList.remove('show');
            document.body.style.opacity = '1';
        });

        // Add keyboard navigation for dropdown
        dropdown.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                this.click();
            }
        });

        // Add click outside to close functionality for export modal
        document.addEventListener('DOMContentLoaded', function() {
            const exportModal = document.getElementById('exportModal');
            const exportFormat = document.getElementById('exportFormat');
            const errorDiv = document.getElementById('exportError');
            
            if (exportModal) {
                exportModal.addEventListener('click', function(e) {
                    if (e.target === exportModal) {
                        closeExportModal();
                    }
                });
            }
            
            // Hide error message when format is selected
            if (exportFormat && errorDiv) {
                exportFormat.addEventListener('change', function() {
                    if (this.value) {
                        errorDiv.style.display = 'none';
                    }
                });
            }
        });

        // Export Modal Functions
        function showExportModal() {
            const modalElement = document.getElementById('exportModal');
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const modal = new bootstrap.Modal(modalElement);
                modal.show();
            } else {
                // Fallback: show modal manually
                modalElement.style.display = 'flex';
                modalElement.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeExportModal() {
            const modalElement = document.getElementById('exportModal');
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) {
                    modal.hide();
                }
            } else {
                // Fallback: hide modal manually
                modalElement.style.display = 'none';
                modalElement.classList.remove('show');
                document.body.style.overflow = '';
            }
        }

        function confirmExport() {
            const format = document.getElementById('exportFormat').value;
            const errorDiv = document.getElementById('exportError');
            
            if (!format) {
                errorDiv.style.display = 'flex';
                return;
            }
            
            // Hide error message if format is selected
            errorDiv.style.display = 'none';
            
            closeExportModal();
            
            if (format === 'csv') {
                exportToCSV();
            } else if (format === 'pdf') {
                exportToPDF();
            }
        }

        // Export to CSV Function
        function exportToCSV() {
            const period = '<?php echo $periodLabels[$selectedPeriod]; ?>';
            const filename = `Sales_Analytics_${period.replace(/\s+/g, '_')}_${new Date().toISOString().split('T')[0]}.csv`;
            const generatedDate = new Date().toLocaleString('en-US', { 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric', 
                hour: '2-digit', 
                minute: '2-digit' 
            });
            
            // Prepare CSV data with professional formatting
            let csv = '';
            
            // Header Section
            csv += 'PEST-CTRL SALES ANALYTICS REPORT\n';
            csv += '===========================================\n';
            csv += `Report Period: ${period}\n`;
            csv += `Generated On: ${generatedDate}\n`;
            csv += `Report Type: Sales Performance Analysis\n`;
            csv += '\n';
            csv += '===========================================\n\n';
            
            // Growth Analytics Section
            csv += 'SECTION 1: GROWTH ANALYTICS\n';
            csv += '-------------------------------------------\n';
            csv += 'Metric,Current Period,Previous Period,Growth Rate\n';
            csv += `Revenue,₱<?php echo number_format($confirmedRevenue, 2); ?>,₱<?php echo number_format($previousConfirmedRevenue, 2); ?>,<?php echo $revenueGrowthRate >= 0 ? '+' : ''; ?><?php echo number_format($revenueGrowthRate, 1); ?>%\n`;
            csv += `Total Orders,<?php echo number_format($uniqueOrders); ?>,<?php echo number_format($previousUniqueOrders); ?>,<?php echo $orderGrowthRate >= 0 ? '+' : ''; ?><?php echo number_format($orderGrowthRate, 1); ?>%\n`;
            csv += `Items Sold,<?php echo number_format($totalSales); ?>,<?php echo number_format($previousTotalSales); ?>,<?php echo $salesGrowthRate >= 0 ? '+' : ''; ?><?php echo number_format($salesGrowthRate, 1); ?>%\n`;
            csv += '\n';
            
            // Performance Metrics Section
            csv += 'SECTION 2: PERFORMANCE METRICS\n';
            csv += '-------------------------------------------\n';
            csv += 'Metric,Value,Description\n';
            csv += `Customer Retention Rate,<?php echo number_format($customerRetentionRate, 1); ?>%,<?php echo $repeatCustomers; ?> repeat customers identified\n`;
            csv += `Order Completion Rate,<?php echo number_format($orderCompletionRate, 1); ?>%,<?php echo $statusCounts['delivered']; ?> orders successfully delivered\n`;
            csv += `Return Rate,<?php echo number_format($returnRate, 1); ?>%,<?php echo $returnStats['total_returns'] ?? 0; ?> total return requests\n`;
            csv += `Average Order Value,₱<?php echo number_format($avgOrderValue, 2); ?>,Average value per completed order\n`;
            csv += '\n';
            
            // Revenue Breakdown Section
            csv += 'SECTION 3: REVENUE BREAKDOWN\n';
            csv += '-------------------------------------------\n';
            csv += 'Revenue Type,Amount (PHP),Percentage of Total\n';
            csv += `Confirmed Revenue,₱<?php echo number_format($confirmedRevenue, 2); ?>,<?php echo $expectedRevenue > 0 ? number_format(($confirmedRevenue / $expectedRevenue) * 100, 1) : 0; ?>%\n`;
            csv += `Pending Revenue,₱<?php echo number_format($pendingRevenue, 2); ?>,<?php echo $expectedRevenue > 0 ? number_format(($pendingRevenue / $expectedRevenue) * 100, 1) : 0; ?>%\n`;
            csv += `Refunded Amount,₱<?php echo number_format($periodRefundAmount, 2); ?>,<?php echo $expectedRevenue > 0 ? number_format(($periodRefundAmount / $expectedRevenue) * 100, 1) : 0; ?>%\n`;
            csv += `Net Revenue,₱<?php echo number_format($netConfirmedRevenue, 2); ?>,<?php echo $expectedRevenue > 0 ? number_format(($netConfirmedRevenue / $expectedRevenue) * 100, 1) : 0; ?>%\n`;
            csv += `Total Expected Revenue,₱<?php echo number_format($expectedRevenue, 2); ?>,100.0%\n`;
            csv += '\n';
            
            // Order Status Section
            csv += 'SECTION 4: ORDER STATUS DISTRIBUTION\n';
            csv += '-------------------------------------------\n';
            csv += 'Order Status,Count,Percentage\n';
            const totalOrders = <?php echo array_sum($statusCounts); ?>;
            <?php 
            $totalOrdersForCSV = array_sum($statusCounts);
            foreach ($statusCounts as $status => $count): 
                $percentage = $totalOrdersForCSV > 0 ? ($count / $totalOrdersForCSV) * 100 : 0;
            ?>
            csv += `<?php echo ucfirst($status); ?>,<?php echo $count; ?>,<?php echo number_format($percentage, 1); ?>%\n`;
            <?php endforeach; ?>
            csv += `Total Orders,<?php echo $totalOrdersForCSV; ?>,100.0%\n`;
            csv += '\n';
            
            // Top Products Section
            csv += 'SECTION 5: TOP SELLING PRODUCTS\n';
            csv += '-------------------------------------------\n';
            csv += 'Rank,Product Name,Units Sold\n';
            <?php foreach ($topProducts as $index => $product): ?>
            csv += `<?php echo $index + 1; ?>,<?php echo addslashes($product['name']); ?>,<?php echo $product['total_sold']; ?>\n`;
            <?php endforeach; ?>
            csv += '\n';
            
            // Return Statistics Section
            csv += 'SECTION 6: RETURN & REFUND STATISTICS\n';
            csv += '-------------------------------------------\n';
            csv += 'Metric,Value\n';
            csv += `Total Return Requests,<?php echo $returnStats['total_returns'] ?? 0; ?>\n`;
            csv += `Pending Returns,<?php echo $returnStats['pending_returns'] ?? 0; ?>\n`;
            csv += `Approved Returns,<?php echo $returnStats['approved_returns'] ?? 0; ?>\n`;
            csv += `Rejected Returns,<?php echo $returnStats['rejected_returns'] ?? 0; ?>\n`;
            csv += `Return Rate,<?php echo number_format($returnRate, 1); ?>%\n`;
            csv += `Total Refunded Amount,₱<?php echo number_format($periodRefundAmount, 2); ?>\n`;
            csv += '\n';
            
            // Footer
            csv += '===========================================\n';
            csv += 'END OF REPORT\n';
            csv += `This report was automatically generated by PEST-CTRL Sales Analytics System.\n`;
            csv += `For inquiries, please contact support.\n`;
            
            // Download CSV
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = filename;
            link.click();
        }

        function exportToPDF() {
            // Check if html2pdf is loaded
            if (typeof html2pdf === 'undefined') {
                alert('PDF library not loaded. Please refresh the page and try again.');
                return;
            }
            
            // Show loading
            document.getElementById('loadingOverlay').classList.add('show');
            
            const period = '<?php echo $periodLabels[$selectedPeriod]; ?>';
            
            // Create the content using actual DOM element with professional styling
            const content = document.createElement('div');
            content.style.cssText = 'font-family: "Segoe UI", Arial, sans-serif; padding: 0; color: #130325; background: white; line-height: 1.6;';
            
            const generatedDate = new Date().toLocaleString('en-US', { 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric', 
                hour: '2-digit', 
                minute: '2-digit' 
            });
            
            content.innerHTML = `
                <!-- Header Section -->
                <div style="background: linear-gradient(135deg, #130325 0%, #1a0a2e 100%); padding: 40px 30px; margin-bottom: 40px; border-bottom: 4px solid #FFD736;">
                    <div style="text-align: center; color: white;">
                        <h1 style="color: #FFD736; margin: 0 0 15px 0; font-size: 32px; font-weight: 700; letter-spacing: 1px;">PEST-CTRL</h1>
                        <h2 style="color: #ffffff; margin: 0 0 20px 0; font-size: 24px; font-weight: 600;">SALES ANALYTICS REPORT</h2>
                        <div style="border-top: 1px solid rgba(255, 215, 54, 0.3); padding-top: 20px; margin-top: 20px;">
                            <p style="color: #F9F9F9; font-size: 14px; margin: 5px 0; font-weight: 500;">Report Period: <span style="color: #FFD736;">${period}</span></p>
                            <p style="color: #F9F9F9; font-size: 14px; margin: 5px 0; font-weight: 500;">Generated: <span style="color: #FFD736;">${generatedDate}</span></p>
                            <p style="color: #F9F9F9; font-size: 14px; margin: 5px 0; font-weight: 500;">Report Type: <span style="color: #FFD736;">Sales Performance Analysis</span></p>
                        </div>
                    </div>
                </div>
                
                <!-- Document Body -->
                <div style="padding: 0 40px 40px 40px;">
                
                <!-- Section 1: Growth Analytics -->
                <div style="margin-bottom: 35px; page-break-inside: avoid;">
                    <div style="background: #130325; padding: 12px 20px; margin-bottom: 20px; border-left: 4px solid #FFD736;">
                        <h2 style="color: #FFD736; margin: 0; font-size: 20px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Section 1: Growth Analytics</h2>
                    </div>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <thead>
                            <tr style="background: #130325;">
                                <th style="padding: 14px 16px; border: 1px solid #e5e7eb; color: #FFD736; font-size: 13px; font-weight: 700; text-align: left; text-transform: uppercase; letter-spacing: 0.5px;">Metric</th>
                                <th style="padding: 14px 16px; border: 1px solid #e5e7eb; color: #FFD736; font-size: 13px; font-weight: 700; text-align: right; text-transform: uppercase; letter-spacing: 0.5px;">Current Period</th>
                                <th style="padding: 14px 16px; border: 1px solid #e5e7eb; color: #FFD736; font-size: 13px; font-weight: 700; text-align: right; text-transform: uppercase; letter-spacing: 0.5px;">Previous Period</th>
                                <th style="padding: 14px 16px; border: 1px solid #e5e7eb; color: #FFD736; font-size: 13px; font-weight: 700; text-align: right; text-transform: uppercase; letter-spacing: 0.5px;">Growth Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="background: #ffffff;">
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; font-weight: 600; color: #130325;">Revenue</td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #130325; font-weight: 600;">₱<?php echo number_format($confirmedRevenue, 2); ?></td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #6b7280;">₱<?php echo number_format($previousConfirmedRevenue, 2); ?></td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: <?php echo $revenueGrowthRate >= 0 ? '#059669' : '#dc2626'; ?>; font-weight: 700;"><?php echo $revenueGrowthRate >= 0 ? '+' : ''; ?><?php echo number_format($revenueGrowthRate, 1); ?>%</td>
                            </tr>
                            <tr style="background: #f9fafb;">
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; font-weight: 600; color: #130325;">Total Orders</td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #130325; font-weight: 600;"><?php echo number_format($uniqueOrders); ?></td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #6b7280;"><?php echo number_format($previousUniqueOrders); ?></td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: <?php echo $orderGrowthRate >= 0 ? '#059669' : '#dc2626'; ?>; font-weight: 700;"><?php echo $orderGrowthRate >= 0 ? '+' : ''; ?><?php echo number_format($orderGrowthRate, 1); ?>%</td>
                            </tr>
                            <tr style="background: #ffffff;">
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; font-weight: 600; color: #130325;">Items Sold</td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #130325; font-weight: 600;"><?php echo number_format($totalSales); ?></td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #6b7280;"><?php echo number_format($previousTotalSales); ?></td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: <?php echo $salesGrowthRate >= 0 ? '#059669' : '#dc2626'; ?>; font-weight: 700;"><?php echo $salesGrowthRate >= 0 ? '+' : ''; ?><?php echo number_format($salesGrowthRate, 1); ?>%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Section 2: Performance Metrics -->
                <div style="margin-bottom: 35px; page-break-inside: avoid;">
                    <div style="background: #130325; padding: 12px 20px; margin-bottom: 20px; border-left: 4px solid #FFD736;">
                        <h2 style="color: #FFD736; margin: 0; font-size: 20px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Section 2: Performance Metrics</h2>
                    </div>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <thead>
                            <tr style="background: #130325;">
                                <th style="padding: 14px 16px; border: 1px solid #e5e7eb; color: #FFD736; font-size: 13px; font-weight: 700; text-align: left; text-transform: uppercase; letter-spacing: 0.5px;">Metric</th>
                                <th style="padding: 14px 16px; border: 1px solid #e5e7eb; color: #FFD736; font-size: 13px; font-weight: 700; text-align: right; text-transform: uppercase; letter-spacing: 0.5px;">Value</th>
                                <th style="padding: 14px 16px; border: 1px solid #e5e7eb; color: #FFD736; font-size: 13px; font-weight: 700; text-align: left; text-transform: uppercase; letter-spacing: 0.5px;">Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="background: #ffffff;">
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; font-weight: 600; color: #130325;">Customer Retention Rate</td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #130325; font-weight: 600;"><?php echo number_format($customerRetentionRate, 1); ?>%</td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 13px; color: #6b7280;"><?php echo $repeatCustomers; ?> repeat customers identified</td>
                            </tr>
                            <tr style="background: #f9fafb;">
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; font-weight: 600; color: #130325;">Order Completion Rate</td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #130325; font-weight: 600;"><?php echo number_format($orderCompletionRate, 1); ?>%</td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 13px; color: #6b7280;"><?php echo $statusCounts['delivered']; ?> orders successfully delivered</td>
                            </tr>
                            <tr style="background: #ffffff;">
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; font-weight: 600; color: #130325;">Return Rate</td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #130325; font-weight: 600;"><?php echo number_format($returnRate, 1); ?>%</td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 13px; color: #6b7280;"><?php echo $returnStats['total_returns'] ?? 0; ?> total return requests</td>
                            </tr>
                            <tr style="background: #f9fafb;">
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; font-weight: 600; color: #130325;">Average Order Value</td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #130325; font-weight: 600;">₱<?php echo number_format($avgOrderValue, 2); ?></td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 13px; color: #6b7280;">Average value per completed order</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Section 3: Revenue Breakdown -->
                <div style="margin-bottom: 35px; page-break-inside: avoid;">
                    <div style="background: #130325; padding: 12px 20px; margin-bottom: 20px; border-left: 4px solid #FFD736;">
                        <h2 style="color: #FFD736; margin: 0; font-size: 20px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Section 3: Revenue Breakdown</h2>
                    </div>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <thead>
                            <tr style="background: #130325;">
                                <th style="padding: 14px 16px; border: 1px solid #e5e7eb; color: #FFD736; font-size: 13px; font-weight: 700; text-align: left; text-transform: uppercase; letter-spacing: 0.5px;">Revenue Type</th>
                                <th style="padding: 14px 16px; border: 1px solid #e5e7eb; color: #FFD736; font-size: 13px; font-weight: 700; text-align: right; text-transform: uppercase; letter-spacing: 0.5px;">Amount (PHP)</th>
                                <th style="padding: 14px 16px; border: 1px solid #e5e7eb; color: #FFD736; font-size: 13px; font-weight: 700; text-align: right; text-transform: uppercase; letter-spacing: 0.5px;">Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="background: #ffffff;">
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; font-weight: 600; color: #130325;">Confirmed Revenue</td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #130325; font-weight: 600;">₱<?php echo number_format($confirmedRevenue, 2); ?></td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #6b7280;"><?php echo $expectedRevenue > 0 ? number_format(($confirmedRevenue / $expectedRevenue) * 100, 1) : 0; ?>%</td>
                            </tr>
                            <tr style="background: #f9fafb;">
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; font-weight: 600; color: #130325;">Net Confirmed Revenue</td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #130325; font-weight: 600;">₱<?php echo number_format($netConfirmedRevenue, 2); ?></td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #6b7280;"><?php echo $expectedRevenue > 0 ? number_format(($netConfirmedRevenue / $expectedRevenue) * 100, 1) : 0; ?>%</td>
                            </tr>
                            <tr style="background: #ffffff;">
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; font-weight: 600; color: #130325;">Pending Revenue</td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #130325; font-weight: 600;">₱<?php echo number_format($pendingRevenue, 2); ?></td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #6b7280;"><?php echo $expectedRevenue > 0 ? number_format(($pendingRevenue / $expectedRevenue) * 100, 1) : 0; ?>%</td>
                            </tr>
                            <tr style="background: #f9fafb;">
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; font-weight: 600; color: #130325;">Total Expected Revenue</td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #130325; font-weight: 700;">₱<?php echo number_format($expectedRevenue, 2); ?></td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #130325; font-weight: 700;">100.0%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Section 4: Order Status Distribution -->
                <div style="margin-bottom: 35px; page-break-inside: avoid;">
                    <div style="background: #130325; padding: 12px 20px; margin-bottom: 20px; border-left: 4px solid #FFD736;">
                        <h2 style="color: #FFD736; margin: 0; font-size: 20px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Section 4: Order Status Distribution</h2>
                    </div>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <thead>
                            <tr style="background: #130325;">
                                <th style="padding: 14px 16px; border: 1px solid #e5e7eb; color: #FFD736; font-size: 13px; font-weight: 700; text-align: left; text-transform: uppercase; letter-spacing: 0.5px;">Order Status</th>
                                <th style="padding: 14px 16px; border: 1px solid #e5e7eb; color: #FFD736; font-size: 13px; font-weight: 700; text-align: right; text-transform: uppercase; letter-spacing: 0.5px;">Count</th>
                                <th style="padding: 14px 16px; border: 1px solid #e5e7eb; color: #FFD736; font-size: 13px; font-weight: 700; text-align: right; text-transform: uppercase; letter-spacing: 0.5px;">Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $totalOrdersForPDF = array_sum($statusCounts);
                            $rowIndex = 0;
                            foreach ($statusCounts as $status => $count): 
                                $percentage = $totalOrdersForPDF > 0 ? ($count / $totalOrdersForPDF) * 100 : 0;
                                $bgColor = $rowIndex % 2 == 0 ? '#ffffff' : '#f9fafb';
                            ?>
                            <tr style="background: <?php echo $bgColor; ?>;">
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; font-weight: 600; color: #130325; text-transform: capitalize;"><?php echo ucfirst($status); ?></td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #130325; font-weight: 600;"><?php echo $count; ?></td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #6b7280;"><?php echo number_format($percentage, 1); ?>%</td>
                            </tr>
                            <?php 
                            $rowIndex++;
                            endforeach; 
                            ?>
                            <tr style="background: #f0f2f5; border-top: 2px solid #130325;">
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; font-weight: 700; color: #130325;">TOTAL ORDERS</td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #130325; font-weight: 700;"><?php echo $totalOrdersForPDF; ?></td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #130325; font-weight: 700;">100.0%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Section 5: Top Selling Products -->
                <div style="margin-bottom: 35px; page-break-inside: avoid;">
                    <div style="background: #130325; padding: 12px 20px; margin-bottom: 20px; border-left: 4px solid #FFD736;">
                        <h2 style="color: #FFD736; margin: 0; font-size: 20px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Section 5: Top Selling Products</h2>
                    </div>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <thead>
                            <tr style="background: #130325;">
                                <th style="padding: 14px 16px; border: 1px solid #e5e7eb; color: #FFD736; font-size: 13px; font-weight: 700; text-align: center; text-transform: uppercase; letter-spacing: 0.5px;">Rank</th>
                                <th style="padding: 14px 16px; border: 1px solid #e5e7eb; color: #FFD736; font-size: 13px; font-weight: 700; text-align: left; text-transform: uppercase; letter-spacing: 0.5px;">Product Name</th>
                                <th style="padding: 14px 16px; border: 1px solid #e5e7eb; color: #FFD736; font-size: 13px; font-weight: 700; text-align: right; text-transform: uppercase; letter-spacing: 0.5px;">Units Sold</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topProducts as $index => $product): ?>
                            <tr style="background: <?php echo $index % 2 == 0 ? '#ffffff' : '#f9fafb'; ?>;">
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: center; color: #130325; font-weight: 700;"><?php echo $index + 1; ?></td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; color: #130325; font-weight: 500;"><?php echo htmlspecialchars($product['name']); ?></td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #130325; font-weight: 600;"><?php echo $product['total_sold']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Section 6: Return & Refund Statistics -->
                <div style="margin-bottom: 35px; page-break-inside: avoid;">
                    <div style="background: #130325; padding: 12px 20px; margin-bottom: 20px; border-left: 4px solid #FFD736;">
                        <h2 style="color: #FFD736; margin: 0; font-size: 20px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Section 6: Return & Refund Statistics</h2>
                    </div>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <thead>
                            <tr style="background: #130325;">
                                <th style="padding: 14px 16px; border: 1px solid #e5e7eb; color: #FFD736; font-size: 13px; font-weight: 700; text-align: left; text-transform: uppercase; letter-spacing: 0.5px;">Metric</th>
                                <th style="padding: 14px 16px; border: 1px solid #e5e7eb; color: #FFD736; font-size: 13px; font-weight: 700; text-align: right; text-transform: uppercase; letter-spacing: 0.5px;">Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="background: #ffffff;">
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; font-weight: 600; color: #130325;">Total Return Requests</td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #130325; font-weight: 600;"><?php echo $returnStats['total_returns'] ?? 0; ?></td>
                            </tr>
                            <tr style="background: #f9fafb;">
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; font-weight: 600; color: #130325;">Pending Returns</td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #130325; font-weight: 600;"><?php echo $returnStats['pending_returns'] ?? 0; ?></td>
                            </tr>
                            <tr style="background: #ffffff;">
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; font-weight: 600; color: #130325;">Approved Returns</td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #059669; font-weight: 600;"><?php echo $returnStats['approved_returns'] ?? 0; ?></td>
                            </tr>
                            <tr style="background: #f9fafb;">
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; font-weight: 600; color: #130325;">Rejected Returns</td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #dc2626; font-weight: 600;"><?php echo $returnStats['rejected_returns'] ?? 0; ?></td>
                            </tr>
                            <tr style="background: #ffffff;">
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; font-weight: 600; color: #130325;">Return Rate</td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #130325; font-weight: 600;"><?php echo number_format($returnRate, 1); ?>%</td>
                            </tr>
                            <tr style="background: #f0f2f5; border-top: 2px solid #130325;">
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; font-weight: 700; color: #130325;">Total Refunded Amount</td>
                                <td style="padding: 12px 16px; border: 1px solid #e5e7eb; font-size: 14px; text-align: right; color: #130325; font-weight: 700;">₱<?php echo number_format($periodRefundAmount, 2); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Footer -->
                <div style="margin-top: 50px; padding-top: 30px; border-top: 2px solid #e5e7eb; text-align: center; color: #6b7280; font-size: 12px;">
                    <p style="margin: 5px 0;">This report was automatically generated by PEST-CTRL Sales Analytics System</p>
                    <p style="margin: 5px 0;">For inquiries, please contact support</p>
                    <p style="margin: 10px 0 0 0; color: #130325; font-weight: 600;">© <?php echo date('Y'); ?> PEST-CTRL. All rights reserved.</p>
                </div>
                </div>
            `;
            
            // PDF options
            const opt = {
                margin: 10,
                filename: `Sales_Analytics_${period.replace(/\s+/g, '_')}_${new Date().toISOString().split('T')[0]}.pdf`,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { 
                    scale: 2,
                    useCORS: true,
                    logging: false,
                    backgroundColor: '#ffffff'
                },
                jsPDF: { 
                    unit: 'mm', 
                    format: 'a4', 
                    orientation: 'portrait' 
                }
            };
            
            // Generate PDF
            html2pdf().set(opt).from(content).save().then(() => {
                document.getElementById('loadingOverlay').classList.remove('show');
            }).catch(err => {
                console.error('PDF Error:', err);
                document.getElementById('loadingOverlay').classList.remove('show');
                alert('Error generating PDF. Please try again.');
            });
        }
    </script>
</body>
</html>