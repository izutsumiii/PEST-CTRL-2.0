<?php
// Early export handler (before any HTML output)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_once '../config/database.php';
    require_once '../includes/functions.php';
    requireAdmin();

    $filterEntity = isset($_GET['entity']) ? sanitizeInput($_GET['entity']) : 'all';
    $selectedPeriod = isset($_GET['period']) ? sanitizeInput($_GET['period']) : 'weekly';
    $customStartDate = isset($_GET['start_date']) ? sanitizeInput($_GET['start_date']) : '';
    $customEndDate = isset($_GET['end_date']) ? sanitizeInput($_GET['end_date']) : '';
    if ($selectedPeriod !== 'custom') { $customStartDate = date('Y-m-d', strtotime('-6 days')); $customEndDate = date('Y-m-d'); }

    $dateCondition = '';
    if ($selectedPeriod === 'custom' && $customStartDate && $customEndDate) {
        $dateCondition = "DATE(created_at) BETWEEN '" . date('Y-m-d', strtotime($customStartDate)) . "' AND '" . date('Y-m-d', strtotime($customEndDate)) . "'";
    } else {
        $dateCondition = "DATE(created_at) BETWEEN '" . date('Y-m-d', strtotime('-6 days')) . "' AND '" . date('Y-m-d') . "'";
    }

    $filteredNewSellers = 0; $filteredNewCustomers = 0; $filteredOrders = 0; $filteredRevenue = 0.0; $filteredProducts = 0;
    try {
        if ($filterEntity === 'all' || $filterEntity === 'sellers') {
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE user_type='seller' AND $dateCondition");
            $filteredNewSellers = (int)$stmt->fetchColumn();
        }
        if ($filterEntity === 'all' || $filterEntity === 'customers') {
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE user_type='customer' AND $dateCondition");
            $filteredNewCustomers = (int)$stmt->fetchColumn();
        }
        $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE $dateCondition");
        $filteredOrders = (int)$stmt->fetchColumn();
        $stmt = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status='delivered' AND $dateCondition");
        $filteredRevenue = (float)$stmt->fetchColumn();
        $stmt = $pdo->query("SELECT COUNT(*) FROM products WHERE $dateCondition");
        $filteredProducts = (int)$stmt->fetchColumn();
    } catch (Exception $e) {}

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="admin-analytics-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Entity','Period','Start Date','End Date','New Sellers','New Customers','Orders','Revenue (PHP)','Products']);
    fputcsv($out, [strtoupper($filterEntity), strtoupper($selectedPeriod), $customStartDate, $customEndDate, $filteredNewSellers, $filteredNewCustomers, $filteredOrders, number_format($filteredRevenue, 2, '.', ''), $filteredProducts]);
    fclose($out);
    exit();
}

require_once 'includes/admin_header.php';

// Simple admin check - ensure user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Ensure PDO connection is available
if (!isset($pdo)) {
    require_once '../config/database.php';
}

// Get selected time period from URL parameter
$selectedPeriod = isset($_GET['period']) ? sanitizeInput($_GET['period']) : 'weekly';
$customStartDate = isset($_GET['start_date']) ? sanitizeInput($_GET['start_date']) : '';
$customEndDate = isset($_GET['end_date']) ? sanitizeInput($_GET['end_date']) : '';

// For non-custom views, always show the current last 7 days in inputs
if ($selectedPeriod !== 'custom') {
    $customStartDate = date('Y-m-d', strtotime('-6 days'));
    $customEndDate = date('Y-m-d');
}

// Get analytics filter (seller/customer)
$filterEntity = isset($_GET['entity']) ? sanitizeInput($_GET['entity']) : 'all'; // all|sellers|customers

// Helper function to get date condition based on period
function getPeriodDateCondition($period, $startDate = '', $endDate = '') {
    if ($period === 'custom' && !empty($startDate) && !empty($endDate)) {
        $startDate = date('Y-m-d', strtotime($startDate));
        $endDate = date('Y-m-d', strtotime($endDate));
        return "created_at >= '$startDate' AND created_at <= '$endDate 23:59:59'";
    }
    
    switch($period) {
        case 'weekly':
            return "created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        case 'monthly':
            return "created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
        case 'yearly':
            return "created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        case '6months':
        default:
            return "created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
    }
}

$dateCondition = getPeriodDateCondition($selectedPeriod, $customStartDate, $customEndDate);

// Compute filtered analytics based on entity
$filteredNewSellers = 0;
$filteredNewCustomers = 0;
$filteredOrders = 0;
$filteredRevenue = 0.0;
$filteredProducts = 0;

try {
    if ($filterEntity === 'all' || $filterEntity === 'sellers') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE user_type='seller' AND $dateCondition");
        $stmt->execute();
        $filteredNewSellers = (int)$stmt->fetchColumn();
    }
    
    if ($filterEntity === 'all' || $filterEntity === 'customers') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE user_type='customer' AND $dateCondition");
        $stmt->execute();
        $filteredNewCustomers = (int)$stmt->fetchColumn();
    }
    
    // Orders
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE $dateCondition");
    $stmt->execute();
    $filteredOrders = (int)$stmt->fetchColumn();
    
    // Revenue
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status='delivered' AND $dateCondition");
    $stmt->execute();
    $filteredRevenue = (float)$stmt->fetchColumn();
    
    // Products
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE $dateCondition");
    $stmt->execute();
    $filteredProducts = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    // fail safe
}

// Get pending sellers
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM users WHERE user_type = 'seller' AND seller_status = 'pending'");
$stmt->execute();
$pendingSellers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get pending products
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM products WHERE status = 'pending'");
$stmt->execute();
$pendingProducts = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get active sellers
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM users WHERE user_type = 'seller' AND seller_status = 'approved'");
$stmt->execute();
$activeSellers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get active products
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM products WHERE status = 'active'");
$stmt->execute();
$activeProducts = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get completed orders
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM orders WHERE status = 'delivered'");
$stmt->execute();
$completedOrders = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Period labels
$periodLabels = [
    'weekly' => 'Last 7 Days',
    'monthly' => 'Last Month',
    '6months' => 'Last 6 Months',
    'yearly' => 'Last Year',
    'custom' => !empty($customStartDate) && !empty($customEndDate) ? 
        date('M j', strtotime($customStartDate)) . ' - ' . date('M j, Y', strtotime($customEndDate)) : 
        'Custom Range'
];

// Chart labels
$chartLabels = [];
$groupBy = '';

// Orders by status (in date range)
$ordersByStatus = [
    'pending' => 0,
    'processing' => 0,
    'delivered' => 0,
    'cancelled' => 0
];
try {
    $q = "SELECT status, COUNT(*) c FROM orders WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY status";
    $stmt = $pdo->prepare($q);
    $stmt->execute([ $customStartDate, $customEndDate ]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $st = strtolower($row['status']);
        if (isset($ordersByStatus[$st])) { $ordersByStatus[$st] = (int)$row['c']; }
    }
} catch (Exception $e) {}

// New users per day (customers vs sellers) for the last 7 days/current range
$days = [];
for ($i = 6; $i >= 0; $i--) { $days[] = date('Y-m-d', strtotime("-$i days")); }
$newSellersByDay = array_fill(0, 7, 0);
$newCustomersByDay = array_fill(0, 7, 0);
try {
    $q = "SELECT DATE(created_at) d, user_type, COUNT(*) c FROM users WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY d, user_type";
    $stmt = $pdo->prepare($q);
    $stmt->execute([ $customStartDate, $customEndDate ]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $d = $row['d'];
        $idx = array_search($d, $days);
        if ($idx !== false) {
            if ($row['user_type'] === 'seller') { $newSellersByDay[$idx] = (int)$row['c']; }
            if ($row['user_type'] === 'customer') { $newCustomersByDay[$idx] = (int)$row['c']; }
        }
    }
} catch (Exception $e) {}

switch($selectedPeriod) {
    case 'weekly':
        $chartLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $groupBy = 'DAYOFWEEK(created_at)';
        break;
    case 'monthly':
        $chartLabels = ['Week 1', 'Week 2', 'Week 3', 'Week 4'];
        $groupBy = 'WEEK(created_at, 1)';
        break;
    case '6months':
        $chartLabels = ['Month 1', 'Month 2', 'Month 3', 'Month 4', 'Month 5', 'Month 6'];
        $groupBy = 'DATE_FORMAT(created_at, "%Y-%m")';
        break;
    case 'yearly':
        $chartLabels = ['Jan-Feb', 'Mar-Apr', 'May-Jun', 'Jul-Aug', 'Sep-Oct', 'Nov-Dec'];
        $groupBy = 'FLOOR((MONTH(created_at) - 1) / 2)';
        break;
    default:
        $chartLabels = ['Month 1', 'Month 2', 'Month 3', 'Month 4', 'Month 5', 'Month 6'];
        $groupBy = 'DATE_FORMAT(created_at, "%Y-%m")';
}

// Get users data for chart
$stmt = $pdo->prepare("SELECT 
                          $groupBy as time_group,
                          COUNT(*) as count
                      FROM users 
                      WHERE " . ($filterEntity === 'sellers' ? "user_type='seller'" : ($filterEntity === 'customers' ? "user_type='customer'" : "1=1")) . "
                      AND $dateCondition
                      GROUP BY time_group
                      ORDER BY time_group ASC");
$stmt->execute();
$usersData = $stmt->fetchAll(PDO::FETCH_ASSOC);

$usersDataPoints = array_fill(0, count($chartLabels), 0);
if ($selectedPeriod === 'weekly') {
    foreach ($usersData as $data) {
        $index = ($data['time_group'] == 1) ? 6 : $data['time_group'] - 2;
        if ($index >= 0 && $index < 7) {
            $usersDataPoints[$index] = $data['count'];
        }
    }
} else {
    $idx = 0;
    foreach ($usersData as $data) {
        if ($idx < count($chartLabels)) {
            $usersDataPoints[$idx] = $data['count'];
            $idx++;
        }
    }
}

// Get revenue data for chart
$stmt = $pdo->prepare("SELECT 
                          $groupBy as time_group,
                          COALESCE(SUM(total_amount), 0) as revenue
                      FROM orders 
                      WHERE status='delivered'
                      AND $dateCondition
                      GROUP BY time_group
                      ORDER BY time_group ASC");
$stmt->execute();
$revenueData = $stmt->fetchAll(PDO::FETCH_ASSOC);

$revenueDataPoints = array_fill(0, count($chartLabels), 0);
if ($selectedPeriod === 'weekly') {
    foreach ($revenueData as $data) {
        $index = ($data['time_group'] == 1) ? 6 : $data['time_group'] - 2;
        if ($index >= 0 && $index < 7) {
            $revenueDataPoints[$index] = round($data['revenue'], 2);
        }
    }
} else {
    $idx = 0;
    foreach ($revenueData as $data) {
        if ($idx < count($chartLabels)) {
            $revenueDataPoints[$idx] = round($data['revenue'], 2);
            $idx++;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Analytics</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script>
        function openFormatModal() {
            var m = document.getElementById('formatModal');
            if (m) { m.style.display = 'flex'; }
            // set excel link to current filters
            var excel = document.getElementById('excelLink');
            if (excel) {
                var params = new URLSearchParams(window.location.search);
                if (!params.get('period')) { params.set('period', '<?php echo $selectedPeriod; ?>'); }
                if (!params.get('entity')) { params.set('entity', '<?php echo $filterEntity; ?>'); }
                params.set('start_date', '<?php echo $customStartDate; ?>');
                params.set('end_date', '<?php echo $customEndDate; ?>');
                params.set('export', 'csv');
                excel.href = 'admin-dashboard.php?' + params.toString();
            }
        }
        function closeFormatModal() {
            var m = document.getElementById('formatModal');
            if (m) { m.style.display = 'none'; }
        }
        function exportPDF() {
            alert('PDF export will be added next. For now, use Excel.');
        }
    </script>
    
    <style>
        body {
            background: #f8f9fa !important;
            min-height: 100vh;
            color: #130325 !important;
            font-size: 14px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }

        /* Page Header (match other admin pages) */
        .page-heading {
            font-size: 32px;
            font-weight: 700;
            color: #130325;
            margin: 0 0 8px 0;
            padding: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            text-shadow: none !important;
        }
        .page-heading-title {
            font-size: 32px;
            font-weight: 700;
            color: #130325;
            margin-left: 0;
            margin-bottom: 0;
            text-shadow: none !important;
        }
        
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        /* Analytics Tabs */
        .analytics-tabs {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 40px;
            max-width: 700px;
            margin: 0 auto 12px;
            position: relative;
            background: #ffffff;
            padding: 12px 18px;
            border-radius: 12px;
            box-shadow: none;
            border: 2px solid #f0f2f5;
        }
        
        .tab-btn {
            background: transparent;
            border: none;
            color: #130325;
            padding: 8px 16px;
            cursor: pointer;
            font-weight: 700;
            letter-spacing: 0.5px;
            font-size: 14px;
            line-height: 1;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        
        .tab-btn:hover {
            background: rgba(255, 215, 54, 0.1);
            color: #FFD736;
        }
        
        .tab-btn[data-active="1"] {
            background: linear-gradient(135deg, #FFD736 0%, #FFC107 100%);
            color: #130325;
            box-shadow: none;
        }
        
        .tab-close {
            position: absolute;
            right: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            height: 28px;
            width: 28px;
            border-radius: 6px;
            background: #ffffff;
            color: #dc3545;
            border: 2px solid #dc3545;
            font-size: 14px;
            font-weight: 700;
            transition: all 0.2s ease;
        }
        
        .tab-close:hover {
            background: #dc3545;
            color: #ffffff;
            transform: scale(1.05);
        }
        
        /* Filter Container */
        .analytics-filter {
            background: #ffffff;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 6px 10px;
            box-shadow: none;
            margin-bottom: 14px;
            position: relative;
            z-index: 1;
        }
        .csv-export-btn, .import-btn {
            position: absolute;
            right: 10px;
            top: 8px;
            color: #130325;
            text-decoration: none;
            background: transparent;
            border: none;
            font-size: 18px;
            line-height: 1;
        }
        .csv-export-btn:hover, .import-btn:hover { color: #FFD736; }

        /* Import/Export Modal */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.35); display: none; align-items: center; justify-content: center; z-index: 9999; }
        .modal-dialog { width: 360px; max-width: 90vw; background: #ffffff; border: none; border-radius: 12px; }
        .modal-header { padding: 8px 12px; background: #130325; color: #F9F9F9; border-bottom: none; display: flex; align-items: center; justify-content: space-between; border-radius: 12px 12px 0 0; }
        .modal-title { font-size: 12px; font-weight: 800; letter-spacing: .3px; }
        .modal-close { background: transparent; border: none; color: #F9F9F9; font-size: 16px; line-height: 1; cursor: pointer; }
        .modal-body { padding: 12px; color: #130325; font-size: 13px; }
        .modal-actions { display: flex; gap: 8px; justify-content: flex-end; padding: 0 12px 12px 12px; }
        .btn-outline { background: #ffffff; color: #130325; border: none; border-radius: 8px; padding: 6px 10px; font-weight: 700; font-size: 12px; }
        .btn-primary-y { background: linear-gradient(135deg, #FFD736 0%, #FFC107 100%); color: #130325; border: none; border-radius: 8px; padding: 6px 10px; font-weight: 700; font-size: 12px; }
        
        .analytics-filter::before { content: none; }
        
        .filter-row {
            display: flex;
            align-items: flex-end;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .filter-field {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .filter-field label {
            font-size: 10px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .filter-field input[type="date"],
        .filter-field select {
            background: #f9fafb;
            color: #130325;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 4px 8px;
            font-size: 12px;
            height: 28px;
            min-width: 116px;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .filter-field input:focus,
        .filter-field select:focus {
            outline: none;
            border-color: #FFD736;
            box-shadow: 0 0 0 3px rgba(255, 215, 54, 0.1);
            background: #ffffff;
        }
        
        .filter-actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-apply {
            background: linear-gradient(135deg, #FFD736 0%, #FFC107 100%);
            color: #130325;
            border: 2px solid #FFD736;
            height: 28px;
            width: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s ease;
            box-shadow: none;
        }
        
        .btn-apply:hover { transform: none; box-shadow: none; }
        
        .btn-clear {
            background: #ffffff;
            color: #6b7280;
            border: 2px solid #e5e7eb;
            height: 28px;
            width: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            text-decoration: none;
            font-size: 12px;
            transition: all 0.2s ease;
            box-shadow: none;
        }
        .btn-clear:hover { background: #f3f4f6; border-color: #d1d5db; box-shadow: none; transform: none; }
        
        .quick-range {
            display: flex;
            gap: 6px;
            margin-top: 6px;
            justify-content: center;
            padding-top: 6px;
            border-top: none;
        }
        
        .quick-range a {
            color: #130325;
            text-decoration: none;
            font-weight: 600;
            border: 1px solid #e5e7eb;
            padding: 4px 9px;
            border-radius: 6px;
            background: #f9fafb;
            font-size: 10px;
            transition: all 0.2s ease;
        }
        
        .quick-range a:hover {
            background: #FFD736;
            border-color: #FFD736;
            color: #130325;
            transform: translateY(-1px);
        }
        
        .period-badge {
            background: rgba(255, 215, 54, 0.15);
            border: 1px solid #FFD736;
            color: #130325;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 600;
            display: inline-block;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: #ffffff;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 16px 14px;
            text-align: center;
            box-shadow: none;
            transition: none;
            min-height: 100px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        .stat-icon { font-size: 18px; }
        .icon-green { color: #22c55e; }
        .icon-blue { color: #3b82f6; }
        .icon-purple { color: #8b5cf6; }
        .icon-orange { color: #f59e0b; }
        .icon-yellow { color: #eab308; }
        
        .stat-card:hover { border-color: #FFD736; }
        
        .stat-card h3 {
            color: #6b7280;
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .stat-card .value {
            color: #130325;
            font-size: 22px;
            font-weight: 800;
            margin: 0;
        }
        
        /* Charts */
        .chart-container {
            background: #ffffff;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 18px;
            box-shadow: none;
            margin-bottom: 20px;
        }
        
        .chart-container h2 {
            font-size: 16px;
            font-weight: 700;
            margin: 0 0 16px 0;
            color: #130325;
        }
        
        .chart-wrapper {
            height: 300px;
            position: relative;
        }
        
        @media (max-width: 768px) {
            .analytics-tabs {
                gap: 30px;
                padding: 12px 16px;
            }
            
            .tab-btn {
                font-size: 11px;
                padding: 6px 14px;
            }
            
            .analytics-filter {
                padding: 14px 16px;
            }
            
            .filter-row {
                gap: 8px;
            }
            
            .filter-field input,
            .filter-field select {
                min-width: 110px;
                font-size: 11px;
                padding: 6px 9px;
                height: 30px;
            }
            
            .btn-apply,
            .btn-clear {
                height: 30px;
                width: 30px;
                font-size: 11px;
            }
            
            .quick-range a {
                font-size: 9px;
                padding: 4px 9px;
            }
            
            .stats-grid { grid-template-columns: 1fr; gap: 12px; }
            
            .stat-card {
                min-height: 80px;
                padding: 14px 12px;
            }
            
            .stat-card h3 {
                font-size: 10px;
            }
            
            .stat-card .value {
                font-size: 20px;
            }
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <h1 class="page-heading">
            <span class="page-heading-title">Admin Dashboard</span>
        </h1>

        <!-- Filter Container -->
        <div class="analytics-filter" data-aos="fade-up">
            <button type="button" class="import-btn" title="Export/Import Options" onclick="openFormatModal()">
                <i class="fas fa-file-export" aria-hidden="true"></i>
            </button>
            <div class="analytics-tabs" style="margin: 0 auto; border: none; box-shadow: none; padding: 8px 40px 8px 0; gap: 24px; justify-content: center; width: 100%;">
                <button class="tab-btn" data-target="all" <?php echo $filterEntity==='all'?'data-active="1"':''; ?>>ALL</button>
                <button class="tab-btn" data-target="customers" <?php echo $filterEntity==='customers'?'data-active="1"':''; ?>>CUSTOMERS</button>
                <button class="tab-btn" data-target="sellers" <?php echo $filterEntity==='sellers'?'data-active="1"':''; ?>>SELLERS</button>
            </div>
            <form method="GET" action="admin-dashboard.php">
                <input type="hidden" name="entity" value="<?php echo $filterEntity; ?>">
                <div class="filter-row">
                    <div class="filter-field">
                        <label>From</label>
                        <input type="date" name="start_date" value="<?php echo $customStartDate; ?>">
                    </div>
                    <div class="filter-field">
                        <label>To</label>
                        <input type="date" name="end_date" value="<?php echo $customEndDate; ?>">
                    </div>
                    <div class="filter-field">
                        <label>Period</label>
                        <select name="period" onchange="if(this.value!='custom') this.form.submit();">
                            <option value="weekly" <?php echo $selectedPeriod==='weekly'?'selected':''; ?>>Last 7 Days</option>
                            <option value="monthly" <?php echo $selectedPeriod==='monthly'?'selected':''; ?>>Last Month</option>
                            <option value="6months" <?php echo $selectedPeriod==='6months'?'selected':''; ?>>Last 6 Months</option>
                            <option value="yearly" <?php echo $selectedPeriod==='yearly'?'selected':''; ?>>Last Year</option>
                            <option value="custom" <?php echo $selectedPeriod==='custom'?'selected':''; ?>>Custom</option>
                        </select>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn-apply"><i class="fas fa-filter"></i></button>
                        <a href="admin-dashboard.php?entity=<?php echo $filterEntity; ?>" class="btn-clear" title="Clear"><i class="fas fa-times"></i></a>
                    </div>
                </div>
                <div class="quick-range">
                    <a href="?entity=<?php echo $filterEntity; ?>&period=weekly">Today</a>
                    <a href="?entity=<?php echo $filterEntity; ?>&period=weekly">Last 7 days</a>
                    <a href="?entity=<?php echo $filterEntity; ?>&period=monthly">Last 30 days</a>
                </div>
            </form>
            <div style="text-align: center; margin-top: 8px;">
                <span class="period-badge"><?php echo $periodLabels[$selectedPeriod]; ?></span>
            </div>
        </div>

        <!-- Format Modal -->
        <div id="formatModal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-header">
                    <div class="modal-title">Choose Format</div>
                    <button class="modal-close" aria-label="Close" onclick="closeFormatModal()">×</button>
                </div>
                <div class="modal-body">
                    Select how you want to export the current analytics.
                </div>
                <div class="modal-actions">
                    <button class="btn-outline" onclick="exportPDF()"><i class="fas fa-file-pdf"></i>&nbsp; PDF</button>
                    <a id="excelLink" class="btn-primary-y" href="#"><i class="fas fa-file-excel"></i>&nbsp; Excel</a>
                </div>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid" data-aos="fade-up" data-aos-delay="100">
            <div class="stat-card">
                <h3><i class="fas fa-user-plus stat-icon icon-blue"></i><?php echo $filterEntity==='customers' ? 'New Customers' : ($filterEntity==='sellers' ? 'New Sellers' : 'New Users'); ?></h3>
                <div class="value"><?php echo $filterEntity==='customers' ? $filteredNewCustomers : ($filterEntity==='sellers' ? $filteredNewSellers : $filteredNewSellers + $filteredNewCustomers); ?></div>
            </div>
            
            <?php if ($filterEntity !== 'customers'): ?>
            <div class="stat-card">
                <h3><i class="fas fa-box stat-icon icon-purple"></i>Products</h3>
                <div class="value"><?php echo $filteredProducts; ?></div>
            </div>
            <?php endif; ?>
            
            <div class="stat-card">
                <h3><i class="fas fa-shopping-cart stat-icon icon-blue"></i>Orders</h3>
                <div class="value"><?php echo $filteredOrders; ?></div>
            </div>
            
            <?php if ($filterEntity !== 'customers'): ?>
            <div class="stat-card">
                <h3><i class="fas fa-money-bill-wave stat-icon icon-green"></i>Revenue</h3>
                <div class="value">₱<?php echo number_format($filteredRevenue, 2); ?></div>
            </div>
            
            <div class="stat-card">
                <h3><i class="fas fa-user-clock stat-icon icon-orange"></i>Pending Sellers</h3>
                <div class="value"><?php echo $pendingSellers; ?></div>
            </div>
            
            <div class="stat-card">
                <h3><i class="fas fa-box-open stat-icon icon-yellow"></i>Pending Products</h3>
                <div class="value"><?php echo $pendingProducts; ?></div>
            </div>
            
            <div class="stat-card">
                <h3><i class="fas fa-store stat-icon icon-green"></i>Active Sellers</h3>
                <div class="value"><?php echo $activeSellers; ?></div>
            </div>
            
            <div class="stat-card">
                <h3><i class="fas fa-boxes-stacked stat-icon icon-purple"></i>Active Products</h3>
                <div class="value"><?php echo $activeProducts; ?></div>
            </div>
            <?php endif; ?>
            
            <div class="stat-card">
                <h3><i class="fas fa-check stat-icon icon-blue"></i>Completed Orders</h3>
                <div class="value"><?php echo $completedOrders; ?></div>
            </div>
        </div>

        <!-- Charts Section -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(500px, 1fr)); gap: 24px;">
            <div class="chart-container" data-aos="fade-up" data-aos-delay="200">
                <h2>
                    <?php echo $filterEntity==='customers' ? 'Customers' : ($filterEntity==='sellers' ? 'Sellers' : 'Users'); ?> Growth
                    <span class="period-badge" style="margin-left: 10px;"><?php echo $periodLabels[$selectedPeriod]; ?></span>
                </h2>
                <div class="chart-wrapper">
                    <canvas id="usersChart"></canvas>
                </div>
            </div>
            
            <div class="chart-container" data-aos="fade-up" data-aos-delay="300">
                <h2>
                    Revenue Trend
                    <span class="period-badge" style="margin-left: 10px;"><?php echo $periodLabels[$selectedPeriod]; ?></span>
                </h2>
                <div class="chart-wrapper">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
            <div class="chart-container" data-aos="fade-up" data-aos-delay="320">
                <h2>Orders by Status</h2>
                <div class="chart-wrapper" style="height: 260px;">
                    <canvas id="ordersStatusChart"></canvas>
                </div>
            </div>
            <div class="chart-container" data-aos="fade-up" data-aos-delay="340">
                <h2>New Users (7-day)</h2>
                <div class="chart-wrapper" style="height: 260px;">
                    <canvas id="newUsersChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Initialize AOS
        AOS.init({
            duration: 600,
            easing: 'ease-in-out',
            once: true
        });

        // Chart.js configuration
        Chart.defaults.color = '#130325';
        Chart.defaults.borderColor = 'rgba(0, 0, 0, 0.1)';

        // Users Chart
        const usersCtx = document.getElementById('usersChart').getContext('2d');
        const usersChart = new Chart(usersCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [{
                    label: '<?php echo $filterEntity==='customers' ? 'Customers' : ($filterEntity==='sellers' ? 'Sellers' : 'Users'); ?>',
                    data: <?php echo json_encode($usersDataPoints); ?>,
                    borderColor: 'rgb(59, 130, 246)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4,
                    fill: true,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { font: { size: 11 } }
                    },
                    x: {
                        ticks: { font: { size: 11 } }
                    }
                }
            }
        });

        // Revenue Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        const revenueChart = new Chart(revenueCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [{
                    label: 'Revenue (₱)',
                    data: <?php echo json_encode($revenueDataPoints); ?>,
                    backgroundColor: 'rgba(34, 197, 94, 0.8)',
                    borderColor: 'rgb(34, 197, 94)',
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
                                return '₱' + context.parsed.y.toFixed(2);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            font: { size: 11 },
                            callback: function(value) {
                                return '₱' + value.toFixed(0);
                            }
                        }
                    },
                    x: {
                        ticks: { font: { size: 11 } }
                    }
                }
            }
        });

        // Orders by Status (Doughnut)
        const ordersStatusCanvas = document.getElementById('ordersStatusChart');
        if (ordersStatusCanvas) {
            const ordersStatusCtx = ordersStatusCanvas.getContext('2d');
            const ordersStatusChart = new Chart(ordersStatusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Pending', 'Processing', 'Delivered', 'Cancelled'],
                    datasets: [{
                        data: <?php echo json_encode(array_values($ordersByStatus)); ?>,
                        backgroundColor: ['#f59e0b', '#3b82f6', '#22c55e', '#ef4444'],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });
        }

        // New Users (7-day) stacked line
        const newUsersCanvas = document.getElementById('newUsersChart');
        if (newUsersCanvas) {
            const newUsersCtx = newUsersCanvas.getContext('2d');
            const newUsersChart = new Chart(newUsersCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_map(function($d){ return date('M j', strtotime($d)); }, $days)); ?>,
                    datasets: [
                        { label: 'Sellers', data: <?php echo json_encode($newSellersByDay); ?>, borderColor: '#8b5cf6', backgroundColor: 'rgba(139, 92, 246, 0.15)', tension: 0.35, fill: true, borderWidth: 2 },
                        { label: 'Customers', data: <?php echo json_encode($newCustomersByDay); ?>, borderColor: '#3b82f6', backgroundColor: 'rgba(59, 130, 246, 0.15)', tension: 0.35, fill: true, borderWidth: 2 }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } }
            });
        }

        // Tab switching functionality
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const target = btn.getAttribute('data-target');
                const url = new URL(window.location.href);
                url.searchParams.set('entity', target);
                // Keep current period
                const currentPeriod = '<?php echo $selectedPeriod; ?>';
                url.searchParams.set('period', currentPeriod);
                
                <?php if (!empty($customStartDate) && !empty($customEndDate)): ?>
                url.searchParams.set('start_date', '<?php echo $customStartDate; ?>');
                url.searchParams.set('end_date', '<?php echo $customEndDate; ?>');
                <?php endif; ?>
                
                window.location.href = url.toString();
            });
        });

        // Period selector change
        const periodSelect = document.querySelector('select[name="period"]');
        if (periodSelect) {
            periodSelect.addEventListener('change', function() {
                if (this.value !== 'custom') {
                    this.form.submit();
                }
            });
        }

        // Hover effects for stat cards
        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-6px) scale(1.02)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });
    </script>
</body>
</html>