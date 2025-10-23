<?php
require_once 'includes/functions.php';
require_once 'config/database.php';

// Check seller login FIRST before any output
requireSeller();
$userId = $_SESSION['user_id'];

// Now include the header (which will output HTML)
require_once 'includes/seller_header.php';

// Apply seller dashboard theme to analytics
echo '<style>
body{background:#130325 !important;color:#FFFFFF !important;}
main{margin-left:240px;color:#FFFFFF !important;}
.section{background:rgba(255,255,255,0.1);padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.3);color:#FFFFFF !important;backdrop-filter:blur(10px)}
.stat-card{background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;padding:16px;color:#FFFFFF !important;}
.period-badge{background:rgba(255,215,54,0.12);border:1px solid #FFD736;color:#FFD736;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600}
.orders-table-container{overflow-x:auto;margin-bottom:15px;border:1px solid rgba(255,255,255,0.2);border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.3);background:rgba(255,255,255,0.05)}
.orders-table{width:100%;border-collapse:collapse;font-size:.875rem;color:#FFFFFF !important;}
.orders-table thead{background:rgba(255,255,255,0.1);position:sticky;top:0;z-index:10}
.orders-table th{padding:12px 12px;text-align:left;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#FFD736;border-bottom:2px solid rgba(255,255,255,0.2)}
.orders-table td{padding:12px;border-bottom:1px solid rgba(255,255,255,0.1);color:#FFFFFF !important;}
.orders-table tbody tr{background:rgba(255,255,255,0.03);transition:all .15s ease-in-out}
.orders-table tbody tr:hover{background:#1a0a2e !important;transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,0.3)}
/* Override header styling */
h1{color:#FFFFFF !important;font-family:var(--font-primary) !important;font-size:24px !important;font-weight:700 !important;text-align:left !important;margin:0 0 15px 0 !important;padding-left:20px !important;background:none !important;text-shadow:none !important;}
h2{color:#FFFFFF !important;font-family:var(--font-primary) !important;font-size:24px !important;font-weight:700 !important;text-align:left !important;margin:0 0 15px 0 !important;padding-left:20px !important;}
h3{color:#FFFFFF !important;}
h4{color:#FFFFFF !important;}
h5{color:#FFFFFF !important;}
h6{color:#FFFFFF !important;}
p{color:#FFFFFF !important;}
span{color:#FFFFFF !important;}
div{color:#FFFFFF !important;}
/* Much more aggressive reduction in top spacing for main content */
main{padding-top:0 !important;margin-top:0px !important;}
.container{margin-top:-20px !important;padding-top:0 !important;}
.py-8{padding-top:0 !important;padding-bottom:2rem !important;}
.mb-8{margin-top:-20px !important;}
/* Ensure all text is white */
*{color:#FFFFFF !important;}
/* Exception for specific elements that should keep their colors */
.period-badge{color:#FFD736 !important;}
.orders-table th{color:#FFD736 !important;}
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
            background: linear-gradient(135deg, #130325 0%, #1a0a2e 100%);
            min-height: 100vh;
            color: #F9F9F9;
            font-size: 0.9em;
        }
        
        .time-period-selector {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 45px;
        }
        
        .period-dropdown {
            padding: 12px 16px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,215,54,0.3);
            border-radius: 8px;
            color: #F9F9F9;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 160px;
        }
        
        .period-dropdown:hover {
            border-color: #FFD736;
            box-shadow: 0 0 0 3px rgba(255,215,54,0.2);
        }
        
        .period-dropdown:focus {
            outline: none;
            border-color: #FFD736;
            box-shadow: 0 0 0 3px rgba(255,215,54,0.2);
        }
        
        .period-dropdown option {
            background: #1a0a2e;
            color: #F9F9F9;
        }
        
        /* Custom Date Range Picker Styles */
        .custom-date-range {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,215,54,0.3);
            border-radius: 8px;
            padding: 8px;
            transition: all 0.3s ease;
        }
        
        .date-inputs {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .date-input {
            padding: 8px 12px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,215,54,0.3);
            border-radius: 6px;
            color: #F9F9F9;
            font-size: 13px;
            transition: all 0.3s ease;
            min-width: 100px;
            height: 36px;
        }
        
        /* Custom date input styling */
        .date-input::-webkit-calendar-picker-indicator {
            filter: invert(1);
            opacity: 0.7;
        }
        
        .date-input::-webkit-datetime-edit-text {
            color: #FFD736;
        }
        
        .date-input::-webkit-datetime-edit-month-field,
        .date-input::-webkit-datetime-edit-day-field,
        .date-input::-webkit-datetime-edit-year-field {
            color: #F9F9F9;
        }
        
        .date-input:focus {
            outline: none;
            border-color: #FFD736;
            box-shadow: 0 0 0 3px rgba(255,215,54,0.2);
            background: rgba(255,255,255,0.15);
        }
        
        .date-input::placeholder {
            color: rgba(249,249,249,0.5);
        }
        
        .date-separator {
            color: #FFD736;
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
            font-size: 14px;
            color: rgba(255, 255, 255, 0.7);
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            text-align: center;
            border-left: 4px solid #007bff;
            color: #F9F9F9;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            transform: translateX(-100%);
            transition: transform 0.8s ease;
        }
        
        .stat-card:hover::before {
            transform: translateX(100%);
        }
        
        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.15);
        }
        
        /* New KPI Card Styles */
        .kpi-card {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            color: #F9F9F9;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            min-height: 140px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        
        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            transform: translateX(-100%);
            transition: transform 0.8s ease;
        }
        
        .kpi-card:hover::before {
            transform: translateX(100%);
        }
        
        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.2);
            border-color: rgba(255, 255, 255, 0.25);
        }
        
        .kpi-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 12px;
        }
        
        .kpi-header i {
            font-size: 1.2em;
        }
        
        .kpi-header h3 {
            font-size: 0.9em;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.8);
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .kpi-value {
            font-size: 1.8em;
            font-weight: bold;
            margin: 8px 0;
            background: linear-gradient(135deg, #fff, #e0e7ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1.2;
        }
        
        .kpi-subtitle {
            font-size: 0.75em;
            color: rgba(255, 255, 255, 0.6);
            margin-bottom: 8px;
            font-weight: 500;
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
            font-size: 2.2em;
            font-weight: bold;
            margin: 15px 0;
            background: linear-gradient(135deg, #fff, #e0e7ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .period-badge {
            display: inline-block;
            background: rgba(0, 123, 255, 0.2);
            color: #60a5fa;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 8px;
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
            background: linear-gradient(135deg, #FFD736, #FFA500);
            border: none;
            border-radius: 6px;
            padding: 6px 12px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            font-size: 0.8rem;
        }

        .download-icon-btn:hover {
            background: linear-gradient(135deg, #FFA500, #FF8C00);
            transform: translateY(-2px);
        }

        .download-icon-btn i {
            color: #130325;
            font-size: 1rem;
        }

        .download-icon-btn {
            color: #130325;
        }

        /* Export Modal Styling */
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
            background:rgb(230, 230, 230);
            border: none;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
            position: relative;
            z-index: 10001;
        }

        .modal-header {
            background: rgb(230, 230, 230);
            color: #333;
            border-bottom: 1px solid #e5e5e5;
            padding: 20px 20px 15px 20px;
            border-radius: 12px 12px 0 0;
            position: relative;
        }

        .modal-title {
            font-weight: 600;
            font-size: 1.1rem;
            color: #333;
            margin: 0;
        }

        .modal-body {
            padding: 20px;
            background: rgb(230, 230, 230);
        }

        .modal-footer {
            border-top: 1px solid #e5e5e5;
            padding: 15px 20px;
            background: rgb(230, 230, 230);
            border-radius: 0 0 12px 12px;
        }

        .form-label {
            font-weight: 500;
            color: #333;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .form-select {
            background: #ffffff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 0.9rem;
            color: #333;
            transition: all 0.3s ease;
            width: 100%;
        }

        .form-select:focus {
            background: #ffffff;
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
            outline: none;
            color: #333;
        }

        .form-select option {
            background: #ffffff;
            color: #333;
            padding: 8px;
        }

        .btn-close {
            background: #dc3545;
            border: none;
            border-radius: 6px;
            width: 28px;
            height: 28px;
            opacity: 1;
            color: #fff;
            font-size: 0.9rem;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            position: absolute;
            top: 15px;
            right: 15px;
        }

        .btn-close:hover {
            background: #c82333;
            color: #fff;
        }

        .btn-cancel {
            background: #dc3545;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-cancel:hover {
            background: #c82333;
        }

        .btn-export {
            background: #FFD736;
            color: #130325;
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-export:hover {
            background: #FFA500;
        }

        .error-message {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #dc3545;
            font-size: 0.85rem;
            margin-top: 8px;
            padding: 8px 12px;
            background: rgba(220, 53, 69, 0.1);
            border: 1px solid rgba(220, 53, 69, 0.3);
            border-radius: 6px;
        }

        .error-message i {
            font-size: 0.9rem;
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
        <h1 class="text-4xl font-bold text-center mb-8 bg-gradient-to-r from-blue-400 to-purple-600 bg-clip-text text-transparent">
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
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
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
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
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
        <div class="bg-white bg-opacity-10 backdrop-blur-lg rounded-lg shadow p-6 mb-8" data-aos="fade-up">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-gray-100">
                    Order Status Overview
                    <span class="period-badge ml-3"><?php echo $periodLabels[$selectedPeriod]; ?></span>
                </h2>
                <button onclick="showExportModal()" class="download-icon-btn" title="Export Analytics Data">
                    <i class="fas fa-download"></i> Export Data
                </button>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                <div class="status-card bg-blue-50 bg-opacity-20 rounded-lg p-4 text-center">
                    <span class="text-3xl font-bold text-blue-400 block"><?php echo $statusCounts['pending']; ?></span>
                    <span class="text-gray-300 text-sm">Pending</span>
                </div>
                <div class="status-card bg-yellow-50 bg-opacity-20 rounded-lg p-4 text-center">
                    <span class="text-3xl font-bold text-yellow-400 block"><?php echo $statusCounts['processing']; ?></span>
                    <span class="text-gray-300 text-sm">Processing</span>
                </div>
                <div class="status-card bg-purple-50 bg-opacity-20 rounded-lg p-4 text-center">
                    <span class="text-3xl font-bold text-purple-400 block"><?php echo $statusCounts['shipped']; ?></span>
                    <span class="text-gray-300 text-sm">Shipped</span>
                </div>
                <div class="status-card bg-green-50 bg-opacity-20 rounded-lg p-4 text-center">
                    <span class="text-3xl font-bold text-green-400 block"><?php echo $statusCounts['delivered']; ?></span>
                    <span class="text-gray-300 text-sm">Delivered</span>
                </div>
                <div class="status-card bg-red-50 bg-opacity-20 rounded-lg p-4 text-center">
                    <span class="text-3xl font-bold text-red-400 block"><?php echo $statusCounts['cancelled']; ?></span>
                    <span class="text-gray-300 text-sm">Cancelled</span>
                </div>
                <div class="status-card bg-orange-50 bg-opacity-20 rounded-lg p-4 text-center">
                    <span class="text-3xl font-bold text-orange-400 block"><?php echo $statusCounts['refunded']; ?></span>
                    <span class="text-gray-300 text-sm">Refunded</span>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <div class="bg-white bg-opacity-10 backdrop-blur-lg rounded-lg shadow p-6" data-aos="fade-up" data-aos-delay="100">
                <h2 class="text-2xl font-bold text-gray-100 mb-4">
                    Revenue Trend
                    <span class="period-badge ml-3"><?php echo $periodLabels[$selectedPeriod]; ?></span>
                </h2>
                <canvas id="revenueChart"></canvas>
            </div>

            <div class="bg-white bg-opacity-10 backdrop-blur-lg rounded-lg shadow p-6" data-aos="fade-up" data-aos-delay="200">
                <h2 class="text-2xl font-bold text-gray-100 mb-4">
                    Top Products
                    <span class="period-badge ml-3"><?php echo $periodLabels[$selectedPeriod]; ?></span>
                </h2>
                <canvas id="productsChart"></canvas>
            </div>
        </div>

        <!-- Detailed Analytics Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <div class="bg-white bg-opacity-10 backdrop-blur-lg rounded-lg shadow p-6" data-aos="fade-up" data-aos-delay="300">
                <h2 class="text-xl font-bold text-gray-100 mb-4">Payment Methods</h2>
                <canvas id="paymentMethodsChart"></canvas>
            </div>

            <div class="bg-white bg-opacity-10 backdrop-blur-lg rounded-lg shadow p-6" data-aos="fade-up" data-aos-delay="400">
                <h2 class="text-xl font-bold text-gray-100 mb-4">Performance Metrics</h2>
                <div class="space-y-4">
                    <div class="flex justify-between items-center p-3 bg-white bg-opacity-5 rounded-lg">
                        <span class="text-gray-300">Conversion Rate</span>
                        <span class="text-xl font-bold text-green-400">
                            <?php echo $totalProducts > 0 ? number_format(($totalSales / $totalProducts) * 100, 1) : 0; ?>%
                        </span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-white bg-opacity-5 rounded-lg">
                        <span class="text-gray-300">Revenue per Product</span>
                        <span class="text-xl font-bold text-blue-400">
                            ₱<?php echo $totalProducts > 0 ? number_format($expectedRevenue / $totalProducts, 2) : 0; ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-white bg-opacity-5 rounded-lg">
                        <span class="text-gray-300">Items per Order</span>
                        <span class="text-xl font-bold text-purple-400">
                            <?php echo $uniqueOrders > 0 ? number_format($totalSales / $uniqueOrders, 1) : 0; ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-white bg-opacity-5 rounded-lg">
                        <span class="text-gray-300">Fulfillment Rate</span>
                        <span class="text-xl font-bold text-yellow-400">
                            <?php 
                            $totalOrders = array_sum($statusCounts);
                            echo $totalOrders > 0 ? number_format(($statusCounts['delivered'] / $totalOrders) * 100, 1) : 0; 
                            ?>%
                        </span>
                    </div>
                </div>
            </div>

            <!-- Returns Analysis - SEPARATE CARD -->
            <div class="bg-white bg-opacity-10 backdrop-blur-lg rounded-lg shadow p-6" data-aos="fade-up" data-aos-delay="600">
                <h2 class="text-xl font-bold text-gray-100 mb-4">Returns Analysis</h2>
                <div class="space-y-4">
                    <div class="flex justify-between items-center p-3 bg-white bg-opacity-5 rounded-lg">
                        <span class="text-gray-300">Return Rate</span>
                        <span class="text-xl font-bold text-orange-400">
                            <?php echo number_format($returnRate, 1); ?>%
                        </span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-white bg-opacity-5 rounded-lg">
                        <span class="text-gray-300">Pending Returns</span>
                        <span class="text-xl font-bold text-yellow-400">
                            <?php echo $returnStats['pending_returns'] ?? 0; ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-white bg-opacity-5 rounded-lg">
                        <span class="text-gray-300">Approved Refunds</span>
                        <span class="text-xl font-bold text-green-400">
                            ₱<?php echo number_format($periodRefundAmount, 2); ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-white bg-opacity-5 rounded-lg">
                        <span class="text-gray-300">Approval Rate</span>
                        <span class="text-xl font-bold text-blue-400">
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
            
            <div class="bg-white bg-opacity-10 backdrop-blur-lg rounded-lg shadow p-6" data-aos="fade-up" data-aos-delay="500">
                <h2 class="text-xl font-bold text-gray-100 mb-4">Order Status Flow</h2>
                <canvas id="statusFlowChart"></canvas>
            </div>
        </div>

        <!-- Sales Summary Table -->
        <div class="bg-white bg-opacity-10 backdrop-blur-lg rounded-lg shadow p-6 mb-8" data-aos="fade-up" data-aos-delay="600">
            <h2 class="text-2xl font-bold text-gray-100 mb-6">
                Sales Summary
                <span class="period-badge ml-3"><?php echo $periodLabels[$selectedPeriod]; ?></span>
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-gray-600">
                            <th class="pb-3 text-gray-300 font-semibold">Metric</th>
                            <th class="pb-3 text-gray-300 font-semibold text-right">Value</th>
                            <th class="pb-3 text-gray-300 font-semibold text-right">Percentage</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-200">
                        <tr class="border-b border-gray-700 hover:bg-white hover:bg-opacity-5 transition-colors">
                            <td class="py-3">Expected Revenue</td>
                            <td class="py-3 text-right font-bold text-blue-400">₱<?php echo number_format($expectedRevenue, 2); ?></td>
                            <td class="py-3 text-right">100%</td>
                        </tr>
                        <tr class="border-b border-gray-700 hover:bg-white hover:bg-opacity-5 transition-colors">
                            <td class="py-3 pl-4">↳ Confirmed</td>
                            <td class="py-3 text-right font-bold text-green-400">₱<?php echo number_format($confirmedRevenue, 2); ?></td>
                            <td class="py-3 text-right">
                                <?php echo $expectedRevenue > 0 ? number_format(($confirmedRevenue / $expectedRevenue) * 100, 1) : 0; ?>%
                            </td>
                        </tr>
                        <tr class="border-b border-gray-700 hover:bg-white hover:bg-opacity-5 transition-colors">
                            <td class="py-3 pl-4">↳ Pending</td>
                            <td class="py-3 text-right font-bold text-yellow-400">₱<?php echo number_format($pendingRevenue, 2); ?></td>
                            <td class="py-3 text-right">
                                <?php echo $expectedRevenue > 0 ? number_format(($pendingRevenue / $expectedRevenue) * 100, 1) : 0; ?>%
                            </td>
                        </tr>
                        <tr class="border-b border-gray-700 hover:bg-white hover:bg-opacity-5 transition-colors">
                            <td class="py-3">Total Orders</td>
                            <td class="py-3 text-right font-bold"><?php echo number_format($uniqueOrders); ?></td>
                            <td class="py-3 text-right">-</td>
                        </tr>
                        <tr class="border-b border-gray-700 hover:bg-white hover:bg-opacity-5 transition-colors">
                            <td class="py-3">Average Order Value</td>
                            <td class="py-3 text-right font-bold text-purple-400">₱<?php echo number_format($avgOrderValue, 2); ?></td>
                            <td class="py-3 text-right">-</td>
                        </tr>
                        <tr class="hover:bg-white hover:bg-opacity-5 transition-colors">
                            <td class="py-3">Total Items Sold</td>
                            <td class="py-3 text-right font-bold text-red-400"><?php echo number_format($totalSales); ?></td>
                            <td class="py-3 text-right">-</td>
                        </tr>
                        <tr class="border-b border-gray-700 hover:bg-white hover:bg-opacity-5 transition-colors">
                            <td class="py-3">Refunded Amount</td>
                            <td class="py-3 text-right font-bold text-orange-400">₱<?php echo number_format($periodRefundAmount, 2); ?></td>
                            <td class="py-3 text-right">
                                <?php echo $expectedRevenue > 0 ? number_format(($periodRefundAmount / $expectedRevenue) * 100, 1) : 0; ?>%
                            </td>
                        </tr>
                        <tr class="border-b border-gray-700 hover:bg-white hover:bg-opacity-5 transition-colors">
                            <td class="py-3">Net Revenue (After Returns)</td>
                            <td class="py-3 text-right font-bold text-purple-400">₱<?php echo number_format($confirmedRevenue - $periodRefundAmount, 2); ?></td>
                            <td class="py-3 text-right">
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
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Export Data</h5>
                    <button type="button" class="btn-close" onclick="closeExportModal()" aria-label="Close">×</button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="exportFormat" class="form-label">Format</label>
                        <select class="form-select" id="exportFormat" required>
                            <option value="">Select format...</option>
                            <option value="csv">CSV</option>
                            <option value="pdf">PDF</option>
                        </select>
                        <div id="exportError" class="error-message" style="display: none;">
                            <i class="fas fa-exclamation-circle"></i>
                            <span>Please select an export format.</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeExportModal()">Cancel</button>
                    <button type="button" class="btn-export" onclick="confirmExport()">Export</button>
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
        Chart.defaults.color = '#F9F9F9';
        Chart.defaults.borderColor = 'rgba(255, 255, 255, 0.1)';

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
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
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
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toFixed(0);
                            }
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
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
                maintainAspectRatio: true,
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
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            stepSize: 1
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
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
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom'
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
                maintainAspectRatio: true,
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
        
        // Update date inputs on page load
        document.addEventListener('DOMContentLoaded', function() {
            const startDateInput = document.getElementById('startDate');
            const endDateInput = document.getElementById('endDate');
            
            // Set default dates if not already set
            if (!startDateInput.value) {
                const today = new Date();
                const sixMonthsAgo = new Date(today.getFullYear(), today.getMonth() - 6, today.getDate());
                startDateInput.value = sixMonthsAgo.toISOString().split('T')[0];
            }
            
            if (!endDateInput.value) {
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
            
            // Prepare CSV data
            let csv = 'Sales Analytics Report - Growth Analysis\n';
            csv += `Period: ${period}\n`;
            csv += `Generated: ${new Date().toLocaleString()}\n\n`;
            
            // Growth Analytics
            csv += 'GROWTH ANALYTICS\n';
            csv += 'Metric,Current Period,Previous Period,Growth Rate\n';
            csv += `Revenue,₱<?php echo number_format($confirmedRevenue, 2); ?>,₱<?php echo number_format($previousConfirmedRevenue, 2); ?>,<?php echo $revenueGrowthRate >= 0 ? '+' : ''; ?><?php echo number_format($revenueGrowthRate, 1); ?>%\n`;
            csv += `Orders,<?php echo number_format($uniqueOrders); ?>,<?php echo number_format($previousUniqueOrders); ?>,<?php echo $orderGrowthRate >= 0 ? '+' : ''; ?><?php echo number_format($orderGrowthRate, 1); ?>%\n`;
            csv += `Items Sold,<?php echo number_format($totalSales); ?>,<?php echo number_format($previousTotalSales); ?>,<?php echo $salesGrowthRate >= 0 ? '+' : ''; ?><?php echo number_format($salesGrowthRate, 1); ?>%\n\n`;
            
            // Performance Metrics
            csv += 'PERFORMANCE METRICS\n';
            csv += 'Metric,Value,Description\n';
            csv += `Customer Retention Rate,<?php echo number_format($customerRetentionRate, 1); ?>%,<?php echo $repeatCustomers; ?> repeat customers\n`;
            csv += `Order Completion Rate,<?php echo number_format($orderCompletionRate, 1); ?>%,<?php echo $statusCounts['delivered']; ?> delivered\n`;
            csv += `Return Rate,<?php echo number_format($returnRate, 1); ?>%,<?php echo $returnStats['total_returns'] ?? 0; ?> returns\n`;
            csv += `Average Order Value,₱<?php echo number_format($avgOrderValue, 2); ?>,Per order\n\n`;
            
            // Revenue Breakdown
            csv += 'REVENUE BREAKDOWN\n';
            csv += 'Type,Amount,Percentage\n';
            csv += `Confirmed Revenue,₱<?php echo number_format($confirmedRevenue, 2); ?>,<?php echo $expectedRevenue > 0 ? number_format(($confirmedRevenue / $expectedRevenue) * 100, 1) : 0; ?>%\n`;
            csv += `Pending Revenue,₱<?php echo number_format($pendingRevenue, 2); ?>,<?php echo $expectedRevenue > 0 ? number_format(($pendingRevenue / $expectedRevenue) * 100, 1) : 0; ?>%\n`;
            csv += `Refunded Amount,₱<?php echo number_format($periodRefundAmount, 2); ?>,<?php echo $expectedRevenue > 0 ? number_format(($periodRefundAmount / $expectedRevenue) * 100, 1) : 0; ?>%\n`;
            csv += `Net Revenue,₱<?php echo number_format($netConfirmedRevenue, 2); ?>,<?php echo $expectedRevenue > 0 ? number_format(($netConfirmedRevenue / $expectedRevenue) * 100, 1) : 0; ?>%\n\n`;
            
            // Order Status
            csv += 'ORDER STATUS\n';
            csv += 'Status,Count\n';
            csv += `Pending,<?php echo $statusCounts['pending']; ?>\n`;
            csv += `Processing,<?php echo $statusCounts['processing']; ?>\n`;
            csv += `Shipped,<?php echo $statusCounts['shipped']; ?>\n`;
            csv += `Delivered,<?php echo $statusCounts['delivered']; ?>\n`;
            csv += `Cancelled,<?php echo $statusCounts['cancelled']; ?>\n`;
            csv += `Refunded,<?php echo $statusCounts['refunded']; ?>\n\n`;
            
            // Top Products
            csv += 'TOP SELLING PRODUCTS\n';
            csv += 'Product Name,Units Sold\n';
            <?php foreach ($topProducts as $product): ?>
            csv += '<?php echo addslashes($product['name']); ?>,<?php echo $product['total_sold']; ?>\n';
            <?php endforeach; ?>
            
            // Return Statistics
            csv += '\nRETURN STATISTICS\n';
            csv += 'Metric,Value\n';
            csv += `Total Returns,<?php echo $returnStats['total_returns'] ?? 0; ?>\n`;
            csv += `Pending Returns,<?php echo $returnStats['pending_returns'] ?? 0; ?>\n`;
            csv += `Approved Returns,<?php echo $returnStats['approved_returns'] ?? 0; ?>\n`;
            csv += `Rejected Returns,<?php echo $returnStats['rejected_returns'] ?? 0; ?>\n`;
            csv += `Return Rate,<?php echo number_format($returnRate, 1); ?>%\n`;
            
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
            
            // Create the content using actual DOM element
            const content = document.createElement('div');
            content.style.cssText = 'font-family: Arial, sans-serif; padding: 40px; color: #1a0a2e; background: white;';
            
            content.innerHTML = `
                <div style="text-align: center; margin-bottom: 30px;">
                    <h1 style="color: #130325; margin-bottom: 10px; font-size: 28px;">Sales Analytics Report - Growth Analysis</h1>
                    <p style="color: #666; font-size: 14px;">Period: ${period}</p>
                    <p style="color: #666; font-size: 14px;">Generated: ${new Date().toLocaleString()}</p>
                </div>
                
                <div style="margin-bottom: 30px; page-break-inside: avoid;">
                    <h2 style="color: #130325; border-bottom: 2px solid #FFD736; padding-bottom: 10px; font-size: 20px;">Growth Analytics</h2>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
                        <tr style="background: #f3f4f6;">
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Metric</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: center;"><strong>Current Period</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: center;"><strong>Previous Period</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: center;"><strong>Growth Rate</strong></td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Revenue</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;">₱<?php echo number_format($confirmedRevenue, 2); ?></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;">₱<?php echo number_format($previousConfirmedRevenue, 2); ?></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right; color: <?php echo $revenueGrowthRate >= 0 ? '#10b981' : '#ef4444'; ?>;"><?php echo $revenueGrowthRate >= 0 ? '+' : ''; ?><?php echo number_format($revenueGrowthRate, 1); ?>%</td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Orders</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;"><?php echo number_format($uniqueOrders); ?></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;"><?php echo number_format($previousUniqueOrders); ?></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right; color: <?php echo $orderGrowthRate >= 0 ? '#10b981' : '#ef4444'; ?>;"><?php echo $orderGrowthRate >= 0 ? '+' : ''; ?><?php echo number_format($orderGrowthRate, 1); ?>%</td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Items Sold</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;"><?php echo number_format($totalSales); ?></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;"><?php echo number_format($previousTotalSales); ?></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right; color: <?php echo $salesGrowthRate >= 0 ? '#10b981' : '#ef4444'; ?>;"><?php echo $salesGrowthRate >= 0 ? '+' : ''; ?><?php echo number_format($salesGrowthRate, 1); ?>%</td>
                        </tr>
                    </table>
                </div>
                
                <div style="margin-bottom: 30px; page-break-inside: avoid;">
                    <h2 style="color: #130325; border-bottom: 2px solid #FFD736; padding-bottom: 10px; font-size: 20px;">Performance Metrics</h2>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
                        <tr style="background: #f3f4f6;">
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Metric</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;"><strong>Value</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: center;"><strong>Description</strong></td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Customer Retention Rate</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;"><?php echo number_format($customerRetentionRate, 1); ?>%</td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: center;"><?php echo $repeatCustomers; ?> repeat customers</td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Order Completion Rate</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;"><?php echo number_format($orderCompletionRate, 1); ?>%</td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: center;"><?php echo $statusCounts['delivered']; ?> delivered</td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Return Rate</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;"><?php echo number_format($returnRate, 1); ?>%</td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: center;"><?php echo $returnStats['total_returns'] ?? 0; ?> returns</td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Average Order Value</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;">₱<?php echo number_format($avgOrderValue, 2); ?></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: center;">Per order</td>
                        </tr>
                    </table>
                </div>
                
                <div style="margin-bottom: 30px; page-break-inside: avoid;">
                    <h2 style="color: #130325; border-bottom: 2px solid #FFD736; padding-bottom: 10px; font-size: 20px;">Revenue Breakdown</h2>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
                        <tr style="background: #f3f4f6;">
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Type</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;"><strong>Amount</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;"><strong>Percentage</strong></td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Confirmed Revenue</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;">₱<?php echo number_format($confirmedRevenue, 2); ?></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;"><?php echo $expectedRevenue > 0 ? number_format(($confirmedRevenue / $expectedRevenue) * 100, 1) : 0; ?>%</td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Net Confirmed Revenue</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;">₱<?php echo number_format($netConfirmedRevenue, 2); ?></td>
                        </tr>
                        <tr style="background: #f3f4f6;">
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Pending Revenue</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;">₱<?php echo number_format($pendingRevenue, 2); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Total Orders</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;"><?php echo $uniqueOrders; ?></td>
                        </tr>
                        <tr style="background: #f3f4f6;">
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Items Sold</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;"><?php echo $totalSales; ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Average Order Value</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;">₱<?php echo number_format($avgOrderValue, 2); ?></td>
                        </tr>
                    </table>
                </div>
                
                <div style="margin-bottom: 30px; page-break-inside: avoid;">
                    <h2 style="color: #130325; border-bottom: 2px solid #FFD736; padding-bottom: 10px; font-size: 20px;">Order Status</h2>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
                        <tr style="background: #f3f4f6;">
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Pending</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;"><?php echo $statusCounts['pending']; ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Processing</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;"><?php echo $statusCounts['processing']; ?></td>
                        </tr>
                        <tr style="background: #f3f4f6;">
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Shipped</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;"><?php echo $statusCounts['shipped']; ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Delivered</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;"><?php echo $statusCounts['delivered']; ?></td>
                        </tr>
                        <tr style="background: #f3f4f6;">
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Cancelled</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;"><?php echo $statusCounts['cancelled']; ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Refunded</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;"><?php echo $statusCounts['refunded']; ?></td>
                        </tr>
                    </table>
                </div>
                
                <div style="page-break-inside: avoid;">
                    <h2 style="color: #130325; border-bottom: 2px solid #FFD736; padding-bottom: 10px; font-size: 20px;">Top Products</h2>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
                        <thead>
                            <tr style="background: #130325;">
                                <th style="padding: 10px; border: 1px solid #ddd; text-align: left; color: white; font-size: 13px;">Product</th>
                                <th style="padding: 10px; border: 1px solid #ddd; text-align: right; color: white; font-size: 13px;">Units Sold</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topProducts as $index => $product): ?>
                            <tr style="<?php echo $index % 2 == 0 ? 'background: #f3f4f6;' : ''; ?>">
                                <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><?php echo htmlspecialchars($product['name']); ?></td>
                                <td style="padding: 10px; border: 1px solid #ddd; text-align: right; font-size: 13px;"><?php echo $product['total_sold']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="margin-top: 30px; page-break-inside: avoid;">
                    <h2 style="color: #130325; border-bottom: 2px solid #FFD736; padding-bottom: 10px; font-size: 20px;">Return Statistics</h2>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
                        <tr style="background: #f3f4f6;">
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Total Returns</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;"><?php echo $returnStats['total_returns'] ?? 0; ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Return Rate</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;"><?php echo number_format($returnRate, 1); ?>%</td>
                        </tr>
                        <tr style="background: #f3f4f6;">
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px;"><strong>Refunded Amount</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; font-size: 13px; text-align: right;">₱<?php echo number_format($periodRefundAmount, 2); ?></td>
                        </tr>
                    </table>
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