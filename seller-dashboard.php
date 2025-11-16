<?php
require_once 'includes/seller_header.php';
require_once 'config/database.php';
require_once 'includes/seller_notification_functions.php';
//this is seller dashboard.php
requireSeller();
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// Get seller name for welcome message
if ($userId > 0) {
    $stmt = $pdo->prepare("SELECT username, first_name, last_name, display_name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $sellerInfo = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    // Fallback: resolve by session username if id is invalid (0)
    $sessUsername = $_SESSION['username'] ?? '';
    $stmt = $pdo->prepare("SELECT username, first_name, last_name, display_name FROM users WHERE username = ? AND user_type = 'seller' LIMIT 1");
    $stmt->execute([$sessUsername]);
    $sellerInfo = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
$sellerName = '';
// Per request: use FIRST NAME for dashboard welcome; fallback to full name then username
if (!empty($sellerInfo['first_name'])) {
    $sellerName = trim($sellerInfo['first_name']);
} elseif (!empty($sellerInfo['last_name'])) {
    $sellerName = trim($sellerInfo['last_name']);
} else {
    $full = trim(($sellerInfo['first_name'] ?? '') . ' ' . ($sellerInfo['last_name'] ?? ''));
    $sellerName = $full !== '' ? $full : ($sellerInfo['username'] ?? 'Seller');
}


// 1. Total Products (unchanged)
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM products WHERE seller_id = ? AND status = 'active'");
$stmt->execute([$userId]);
$totalProducts = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// 2. Total Items Sold (unchanged)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(oi.quantity), 0) as total FROM order_items oi 
                      JOIN products p ON oi.product_id = p.id 
                      JOIN orders o ON oi.order_id = o.id
                      WHERE p.seller_id = ? AND o.status NOT IN ('cancelled', 'refunded')");
$stmt->execute([$userId]);
$totalSales = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// 3. Expected Revenue - All active orders (unchanged)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(oi.price * oi.quantity), 0) as total FROM order_items oi 
                      JOIN products p ON oi.product_id = p.id 
                      JOIN orders o ON oi.order_id = o.id
                      WHERE p.seller_id = ? AND o.status NOT IN ('cancelled', 'refunded')");
$stmt->execute([$userId]);
$expectedRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// 4. Unique Orders Count (unchanged)
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT o.id) as unique_orders FROM orders o 
                      JOIN order_items oi ON o.id = oi.order_id 
                      JOIN products p ON oi.product_id = p.id 
                      WHERE p.seller_id = ? AND o.status NOT IN ('cancelled', 'refunded')");
$stmt->execute([$userId]);
$uniqueOrders = $stmt->fetch(PDO::FETCH_ASSOC)['unique_orders'] ?? 0;

// 5. Average Order Value (unchanged)
$avgOrderValue = $uniqueOrders > 0 ? $expectedRevenue / $uniqueOrders : 0;

// 6. FIXED: Confirmed Revenue - Payment already received/secured
$stmt = $pdo->prepare("SELECT COALESCE(SUM(oi.price * oi.quantity), 0) as total FROM order_items oi 
                      JOIN products p ON oi.product_id = p.id 
                      JOIN orders o ON oi.order_id = o.id
                      WHERE p.seller_id = ? 
                      AND (
                          -- Online payments are secured immediately when not cancelled/refunded
                          (o.payment_method IN ('paypal', 'gcash', 'credit_card', 'debit_card') 
                           AND o.status NOT IN ('cancelled', 'refunded'))
                          OR 
                          -- COD payments are secured only when delivered
                          (o.payment_method = 'cod' AND o.status = 'delivered')
                      )");
$stmt->execute([$userId]);
$confirmedRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// 7. FIXED: Pending Revenue - COD orders not yet delivered
$stmt = $pdo->prepare("SELECT COALESCE(SUM(oi.price * oi.quantity), 0) as total FROM order_items oi 
                      JOIN products p ON oi.product_id = p.id 
                      JOIN orders o ON oi.order_id = o.id
                      WHERE p.seller_id = ? 
                      AND o.payment_method IN ('cod', 'cash_on_delivery') 
                      AND o.status IN ('pending', 'processing', 'shipped')
                      AND o.status NOT IN ('cancelled', 'refunded', 'delivered')");
$stmt->execute([$userId]);
$pendingRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// 8. FIXED: Online Payment Revenue (already secured - all non-cancelled online payments)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(oi.price * oi.quantity), 0) as total FROM order_items oi 
                      JOIN products p ON oi.product_id = p.id 
                      JOIN orders o ON oi.order_id = o.id
                      WHERE p.seller_id = ? 
                      AND o.payment_method IN ('paypal', 'gcash', 'credit_card', 'debit_card')
                      AND o.status NOT IN ('cancelled', 'refunded')");
$stmt->execute([$userId]);
$onlinePaymentRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// 9. FIXED: COD Delivered Revenue (payment actually received)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(oi.price * oi.quantity), 0) as total FROM order_items oi 
                      JOIN products p ON oi.product_id = p.id 
                      JOIN orders o ON oi.order_id = o.id
                      WHERE p.seller_id = ? 
                      AND o.payment_method IN ('cod', 'cash_on_delivery') 
                      AND o.status = 'delivered'");
$stmt->execute([$userId]);
$codDeliveredRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// 10. ADDITIONAL: COD Pending Revenue (for detailed breakdown)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(oi.price * oi.quantity), 0) as total FROM order_items oi 
                      JOIN products p ON oi.product_id = p.id 
                      JOIN orders o ON oi.order_id = o.id
                      WHERE p.seller_id = ? 
                      AND o.payment_method = 'cod' 
                      AND o.status IN ('pending', 'processing', 'shipped')
                      AND o.status NOT IN ('cancelled', 'refunded', 'delivered')");
$stmt->execute([$userId]);
$codPendingRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// 11. ADDITIONAL: Get order status counts for dashboard
// 11. ADDITIONAL: Get order status counts for dashboard (FIXED - includes cancelled)
$stmt = $pdo->prepare("SELECT 
                          o.status,
                          COUNT(DISTINCT o.id) as count
                      FROM orders o 
                      JOIN order_items oi ON o.id = oi.order_id 
                      JOIN products p ON oi.product_id = p.id 
                      WHERE p.seller_id = ?
                      GROUP BY o.status");
$stmt->execute([$userId]);
$statusResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Convert to associative array for easier access
$statusCounts = [
    'pending' => 0,
    'processing' => 0,
    'shipped' => 0,
    'delivered' => 0,
    'cancelled' => 0,
    'refunded' => 0
];
$stmt->execute([$userId]);



foreach ($statusResults as $status) {
    $statusCounts[$status['status']] = $status['count'];
}

// Get low stock products
$stmt = $pdo->prepare("SELECT * FROM products WHERE seller_id = ? AND stock_quantity < 10 ORDER BY stock_quantity ASC");
$stmt->execute([$userId]);
$lowStockProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Create notifications for low stock products
foreach ($lowStockProducts as $product) {
    if ($product['stock_quantity'] <= 10) {
        createLowStockNotification($userId, $product['id'], $product['name'], $product['stock_quantity']);
    }
}

// Validation: Confirm revenue calculation accuracy
$calculatedExpected = $confirmedRevenue + $pendingRevenue;
$difference = abs($expectedRevenue - $calculatedExpected);

// Log any significant discrepancies (for debugging)
if ($difference > 0.01) {
                error_log("Revenue calculation discrepancy detected: Expected: {$expectedRevenue}, Calculated: {$calculatedExpected}, Difference: {$difference}");
}

// Additional validation: Ensure confirmed revenue components add up correctly
$calculatedConfirmed = $onlinePaymentRevenue + $codDeliveredRevenue;
$confirmedDifference = abs($confirmedRevenue - $calculatedConfirmed);

if ($confirmedDifference > 0.01) {
    error_log("Confirmed revenue calculation discrepancy: Confirmed: $confirmedRevenue, Calculated: $calculatedConfirmed, Difference: $confirmedDifference");
}

// Debug information (remove this in production)
$debugInfo = [
    'expected_revenue' => $expectedRevenue,
    'confirmed_revenue' => $confirmedRevenue,
    'pending_revenue' => $pendingRevenue,
    'online_payment_revenue' => $onlinePaymentRevenue,
    'cod_delivered_revenue' => $codDeliveredRevenue,
    'cod_pending_revenue' => $codPendingRevenue,
    'calculation_check' => $calculatedExpected,
    'difference' => $difference,
    'confirmed_calculation_check' => $calculatedConfirmed,
    'confirmed_difference' => $confirmedDifference
];

// Uncomment for debugging
// echo "<!-- Debug Revenue Info: " . json_encode($debugInfo, JSON_PRETTY_PRINT) . " -->";

// Get recent orders with enhanced payment status
$stmt = $pdo->prepare("SELECT o.*, 
                      oi.quantity, 
                      oi.price as item_price,
                      p.name as product_name,
                      p.image_url as product_image,
                      p.sku as product_sku,
                      u.username as customer_name,
                      u.email as customer_email,
                      CASE 
                        -- COD Payment Status Logic
                        WHEN o.payment_method IN ('cod', 'cash_on_delivery') AND o.status = 'delivered' THEN 'Payment Received (COD)'
                        WHEN o.payment_method IN ('cod', 'cash_on_delivery') AND o.status = 'shipped' THEN 'Awaiting Delivery (COD)'
                        WHEN o.payment_method IN ('cod', 'cash_on_delivery') AND o.status IN ('pending', 'processing') THEN 'Pending Payment (COD)'
                        
                        -- Online Payment Status Logic
                        WHEN o.payment_method = 'paypal' AND o.status NOT IN ('cancelled', 'refunded') THEN 'Paid via PayPal'
                        WHEN o.payment_method = 'gcash' AND o.status NOT IN ('cancelled', 'refunded') THEN 'Paid via GCash'
                        WHEN o.payment_method = 'credit_card' AND o.status NOT IN ('cancelled', 'refunded') THEN 'Paid via Credit Card'
                        WHEN o.payment_method = 'debit_card' AND o.status NOT IN ('cancelled', 'refunded') THEN 'Paid via Debit Card'
                        
                        -- Fallback cases
                        WHEN o.status IN ('cancelled', 'refunded') THEN 'Payment Cancelled/Refunded'
                        ELSE 'Payment Status Unknown'
                      END as payment_status,
                      (oi.price * oi.quantity) as total_amount,
                      -- Add revenue status for clarity
                      CASE 
                    WHEN o.payment_method IN ('paypal', 'gcash', 'credit_card', 'debit_card') 
                        AND o.status NOT IN ('cancelled', 'refunded') THEN 'confirmed'
                    WHEN o.payment_method IN ('cod', 'cash_on_delivery') AND o.status = 'delivered' THEN 'confirmed'
                    WHEN o.payment_method IN ('cod', 'cash_on_delivery') AND o.status IN ('pending', 'processing', 'shipped') THEN 'pending'
                        ELSE 'cancelled'
                      END as revenue_status
                      FROM orders o 
                      JOIN order_items oi ON o.id = oi.order_id 
                      JOIN products p ON oi.product_id = p.id 
                      JOIN users u ON o.user_id = u.id
                      WHERE p.seller_id = ? 
                      ORDER BY o.created_at DESC 
                      LIMIT 15");
$stmt->execute([$userId]);
$recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Rest of your existing code for sales data, top products, etc...
// [Sales data queries remain the same as in your original code]

$currentDate = date('Y-m-d H:i:s');
// Get sales data for different time periods
// Last 1 Week
$stmt = $pdo->prepare("SELECT 
                      DATE(o.created_at) as date,
                      COALESCE(SUM(oi.price * oi.quantity), 0) as revenue,
                      COUNT(DISTINCT o.id) as orders
                      FROM orders o 
                      JOIN order_items oi ON o.id = oi.order_id 
                      JOIN products p ON oi.product_id = p.id 
                      WHERE p.seller_id = ? 
                      AND o.status = 'delivered'
                      AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                      AND o.created_at <= NOW()
                      GROUP BY DATE(o.created_at)
                      ORDER BY date ASC");
$stmt->execute([$userId]);
$weeklySales = $stmt->fetchAll(PDO::FETCH_ASSOC);
$weeklyData = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $found = false;
    foreach ($weeklySales as $sale) {
        if ($sale['date'] === $date) {
            $weeklyData[] = $sale;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $weeklyData[] = [
            'date' => $date,
            'revenue' => '0.00',
            'orders' => '0'
        ];
    }
}
$weeklySales = $weeklyData;

// Last 1 Month
$stmt = $pdo->prepare("SELECT 
                      DATE(o.created_at) as date,
                      COALESCE(SUM(oi.price * oi.quantity), 0) as revenue,
                      COUNT(DISTINCT o.id) as orders
                      FROM orders o 
                      JOIN order_items oi ON o.id = oi.order_id 
                      JOIN products p ON oi.product_id = p.id 
                      WHERE p.seller_id = ? 
                      AND o.status = 'delivered'
                      AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                      AND o.created_at <= NOW()
                      GROUP BY DATE(o.created_at)
                      ORDER BY date ASC");
$stmt->execute([$userId]);
$monthlySalesDaily = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fill missing dates for the month
$monthlyData = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $found = false;
    foreach ($monthlySalesDaily as $sale) {
        if ($sale['date'] === $date) {
            $monthlyData[] = $sale;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $monthlyData[] = [
            'date' => $date,
            'revenue' => '0.00',
            'orders' => '0'
        ];
    }
}
$monthlySalesDaily = $monthlyData;

// Last 6 Months - Fixed query with proper month handling
$stmt = $pdo->prepare("SELECT 
                      DATE_FORMAT(o.created_at, '%Y-%m') as month,
                      COALESCE(SUM(oi.price * oi.quantity), 0) as revenue,
                      COUNT(DISTINCT o.id) as orders
                      FROM orders o 
                      JOIN order_items oi ON o.id = oi.order_id 
                      JOIN products p ON oi.product_id = p.id 
                      WHERE p.seller_id = ? 
                      AND o.status = 'delivered'
                      AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                      AND o.created_at <= NOW()
                      GROUP BY DATE_FORMAT(o.created_at, '%Y-%m')
                      ORDER BY month ASC");
$stmt->execute([$userId]);
$monthlySales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fill missing months for 6 months
$sixMonthsData = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-{$i} months"));
    $found = false;
    foreach ($monthlySales as $sale) {
        if ($sale['month'] === $month) {
            $sixMonthsData[] = $sale;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $sixMonthsData[] = [
            'month' => $month,
            'revenue' => '0.00',
            'orders' => '0'
        ];
    }
}
$monthlySales = $sixMonthsData;

// Last 1 Year - Fixed query
$stmt = $pdo->prepare("SELECT 
                      DATE_FORMAT(o.created_at, '%Y-%m') as month,
                      COALESCE(SUM(oi.price * oi.quantity), 0) as revenue,
                      COUNT(DISTINCT o.id) as orders
                      FROM orders o 
                      JOIN order_items oi ON o.id = oi.order_id 
                      JOIN products p ON oi.product_id = p.id 
                      WHERE p.seller_id = ? 
                      AND o.status = 'delivered'
                      AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                      AND o.created_at <= NOW()
                      GROUP BY DATE_FORMAT(o.created_at, '%Y-%m')
                      ORDER BY month ASC");
$stmt->execute([$userId]);
$yearlySales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fill missing months for the year
$yearlyData = [];
for ($i = 11; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-{$i} months"));
    $found = false;
    foreach ($yearlySales as $sale) {
        if ($sale['month'] === $month) {
            $yearlyData[] = $sale;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $yearlyData[] = [
            'month' => $month,
            'revenue' => '0.00',
            'orders' => '0'
        ];
    }
}
$yearlySales = $yearlyData;

// Get top selling products

// Determine selected time period for top products
$selectedPeriod = isset($_GET['period']) ? sanitizeInput($_GET['period']) : '6months';
switch ($selectedPeriod) {
    case 'weekly':
        $productDateCondition = "o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        break;
    case 'monthly':
        $productDateCondition = "o.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
        break;
    case 'yearly':
        $productDateCondition = "o.created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
        break;
    case '6months':
    default:
        $productDateCondition = "o.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)";
        break;
}

$stmt = $pdo->prepare("SELECT p.name, p.id, SUM(oi.quantity) as total_sold, SUM(oi.price * oi.quantity) as revenue,
       GROUP_CONCAT(DISTINCT o.payment_method ORDER BY o.payment_method SEPARATOR ', ') as payment_methods
    FROM products p
    JOIN order_items oi ON p.id = oi.product_id
    JOIN orders o ON oi.order_id = o.id
    WHERE p.seller_id = ? AND o.status = 'delivered' AND $productDateCondition
    GROUP BY p.id, p.name
    ORDER BY total_sold DESC
    LIMIT 5");
$stmt->execute([$userId]);
$topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seller Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
/* Customer-Side CSS Variables for Consistency */
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

/* Base Styles */
html, body { 
    background: var(--bg-light) !important; 
    color: var(--primary-dark); 
    font-size: 14px;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}

body { 
    margin: 0;
    padding: 0;
}

main { 
    margin-top: 5px !important; 
    padding: 5px 30px 40px 30px !important;
}

.container {
    background: transparent;
}

/* Typography */
.dashboard-welcome {
    color: #130325 !important;
    font-size: 32px;
    font-weight: 700;
    margin: 0;
    text-align: left;
    text-shadow: none !important;
}

h1 {
    color: #130325;
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 24px;
    text-align: left;
    text-shadow: none !important;
}

h2 {
    color: #130325;
    font-size: 22px;
    font-weight: 700;
    margin-bottom: 20px;
    text-shadow: none !important;
}

h3 {
    color: #130325;
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 15px;
    text-shadow: none !important;
}

/* Stat Cards */
.stat-card {
    background: #ffffff;
    padding: 20px;
    border-radius: 8px;
    border: 1px solid rgba(0, 0, 0, 0.1);
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    text-align: left;
    border-left: 4px solid #FFD736;
    color: #130325;
    transition: all 0.3s ease;
}

/* New KPI Card Styles */
.kpi-card {
    background: #ffffff;
    border: 1px solid rgba(0, 0, 0, 0.1);
    border-radius: 12px;
    padding: 24px;
    text-align: center;
    color: #130325;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    min-height: 140px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.kpi-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    border-color: rgba(0, 0, 0, 0.15);
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
    font-size: 13px;
    font-weight: 600;
    color: #6b7280;
    margin: 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.kpi-value {
    font-size: 28px;
    font-weight: 700;
    margin: 12px 0;
    color: #130325;
    line-height: 1.2;
}

.kpi-subtitle {
    font-size: 13px;
    color: #6b7280;
    margin-bottom: 0;
    font-weight: 400;
}

/* Widened KPI Card */
.kpi-card-wide {
    grid-column: span 2; /* Takes up 2 columns on large screens */
}

/* Export Button Styling */
.download-icon-btn {
    background: #130325;
    color: #ffffff;
    border: 1px solid #130325;
    border-radius: 8px;
    padding: 10px 18px;
    height: auto;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
    font-weight: 600;
    font-size: 13px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    font-family: inherit;
}

.download-icon-btn:hover {
    background: #f3f4f6;
    border-color: #e5e7eb;
    color: #130325;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.download-icon-btn:active {
    transform: translateY(0);
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}

.download-icon-btn i {
    color: inherit;
    font-size: 14px;
}

/* Export Modal Styling - Matching Logout Confirmation Modal */
.modal {
    position: fixed !important;
    inset: 0 !important;
    z-index: 10000 !important;
    background: rgba(0, 0, 0, 0.5) !important;
    display: none !important;
    align-items: center !important;
    justify-content: center !important;
    opacity: 0;
    visibility: hidden;
    transition: all 0.25s ease;
}
.modal.show { 
    display: flex !important; 
    opacity: 1 !important;
    visibility: visible !important;
}
.modal-dialog { 
    max-width: 400px; 
    width: 90%; 
    margin: 0 !important; 
    position: relative; 
    z-index: 10001; 
}
.modal-content {
    background: var(--bg-white) !important;
    border: none !important;
    border-radius: 12px !important;
    padding: 0 !important;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2) !important;
    animation: slideDown 0.3s ease;
}
.modal-header {
    background: var(--primary-dark) !important;
    color: var(--bg-white) !important;
    border-bottom: none !important;
    padding: 16px 20px !important;
    border-radius: 12px 12px 0 0 !important;
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    font-weight: 600 !important;
    font-size: 16px !important;
}
.modal-title { 
    font-weight: 700 !important; 
    font-size: 16px !important; 
    color: var(--bg-white) !important; 
    margin: 0 !important; 
}
.modal-body { 
    padding: 20px !important; 
    color: var(--primary-dark) !important;
    font-size: 14px !important;
    line-height: 1.5 !important;
    background: var(--bg-white) !important;
}
.modal-footer {
    border-top: 1px solid var(--border-light) !important;
    padding: 16px 24px !important;
    background: var(--bg-white) !important;
    border-radius: 0 0 12px 12px !important;
    display: flex !important;
    gap: 10px !important;
    justify-content: flex-end !important;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
.form-label { font-weight: 500; color: #333; margin-bottom: 8px; font-size: 0.9rem; }
.form-select {
    background: rgb(230, 230, 230); /* Off-white */
    border: 1px solid #ddd; border-radius: 8px; padding: 10px 12px;
    font-size: 0.9rem; color: #333; transition: all 0.3s ease; width: 100%;
}
.form-select:focus {
    background: rgb(230, 230, 230); /* Off-white */
    border-color: #007bff; box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
    outline: none; color: #333;
}
.form-select option { background: rgb(230, 230, 230); color: #333; padding: 8px; } /* Off-white */
.btn-close {
    background: transparent !important; border: none !important; border-radius: 0 !important;
    width: auto !important; height: auto !important;
    opacity: 1 !important; color: #dc3545 !important; font-size: 24px !important; font-weight: bold !important;
    display: flex !important; align-items: center !important; justify-content: center !important;
    transition: all 0.2s ease !important; position: absolute !important; top: 15px !important; right: 15px !important;
    padding: 0 !important; cursor: pointer !important;
}
.btn-close:hover { background: transparent !important; color: #c82333 !important; transform: scale(1.15) !important; }
.btn-close:active { transform: scale(1.05) !important; }
.btn-cancel {
    background: #dc3545; color: #ffffff; border: none; border-radius: 8px;
    padding: 8px 16px; font-size: 0.9rem; font-weight: 500; cursor: pointer; transition: all 0.3s ease;
}
.btn-cancel:hover { background: #c82333; }
.btn-export {
    background: #FFD736; color: #130325; border: none; border-radius: 8px;
    padding: 8px 16px; font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;
}
.btn-export:hover { background: #FFA500; }
.error-message {
    display: flex; align-items: center; gap: 8px; color: #dc3545; font-size: 0.85rem;
    margin-top: 8px; padding: 8px 12px; background: rgba(220, 53, 69, 0.1);
    border: 1px solid rgba(220, 53, 69, 0.3); border-radius: 6px;
}
.error-message i { font-size: 0.9rem; }

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.2);
}

.stat-card.revenue { border-left-color: #28a745; }
.stat-card.paid { border-left-color: #17a2b8; }
.stat-card.pending { border-left-color: #ffc107; }
.stat-card.products { border-left-color: #6f42c1; }
.stat-card.sales { border-left-color: #fd7e14; }
.stat-card.conversion { border-left-color: #e83e8c; }

.stat-value {
    font-size: 24px;
    font-weight: 700;
    margin: 10px 0;
    color: #130325;
}

.stat-card h3,
.stat-card p,
.stat-card span,
.stat-card small,
.stat-card .label {
    color: #130325;
}

/* Section Containers */
.section {
    background: #ffffff;
    padding: 24px;
    border-radius: 8px;
    border: 1px solid rgba(0, 0, 0, 0.1);
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    color: #130325;
}

/* Status Cards */
.status-cards {
    display: flex;
    justify-content: space-around;
    flex-wrap: wrap;
    gap: 10px;
}

.status-card {
    text-align: center;
    padding: 15px;
    border-radius: 8px;
    min-width: 80px;
    transition: transform 0.3s ease;
}

.status-card:hover {
    transform: scale(1.05);
}

.status-card.pending { background-color: #dbeafe; color: #1e40af; }
.status-card.processing { background-color: #fef3c7; color: #92400e; }
.status-card.shipped { background-color: #e0e7ff; color: #5b21b6; }
.status-card.delivered { background-color: #d1fae5; color: #065f46; }
.status-card.cancelled { background-color: #fee2e2; color: #991b1b; }

.status-count {
    display: block;
    font-size: 1.5em;
    font-weight: bold;
}

/* Alert Components */
.alert-badge {
    background-color: #dc3545;
    color: white;
    border-radius: 50%;
    padding: 2px 8px;
    font-size: 0.8em;
    margin-left: 10px;
}

.alert-list {
    max-height: 200px;
    overflow-y: auto;
}

.alert-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px;
    margin-bottom: 5px;
    border-radius: 4px;
}

.alert-item.low-stock { background-color: #fff3cd; }
.alert-item.out-of-stock { background-color: #f8d7da; }

/* Payment Status */
.payment-status {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 3px 8px;
    border-radius: 4px;
    display: inline-block;
}

.payment-status.paid { 
    color: #065f46; 
    background-color: #d1fae5; 
}

.payment-status.pending { 
    color: #92400e; 
    background-color: #fef3c7; 
}

/* Order Status */
.order-status {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: capitalize;
    letter-spacing: 0.05em;
    padding: 3px 8px;
    border-radius: 4px;
    display: inline-block;
}

.order-status.pending { background-color: #dbeafe; color: #1e40af; }
.order-status.processing { background-color: #fef3c7; color: #92400e; }
.order-status.shipped { background-color: #e0e7ff; color: #5b21b6; }
.order-status.delivered { background-color: #d1fae5; color: #065f46; }
.order-status.cancelled { background-color: #fee2e2; color: #991b1b; }

/* Tables */
.orders-table-container {
    overflow-x: auto;
    margin-bottom: 15px;
}

.orders-table {
    width: 100%;
    border-collapse: collapse;
}

.orders-table th,
.orders-table td {
    padding: 8px 12px;
    text-align: left;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.orders-table th {
    background: #130325;
    font-weight: 700;
    color: #ffffff;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.orders-table tbody tr {
    transition: all 0.15s ease-in-out;
}

.orders-table tbody tr:hover {
    background-color: rgba(255, 255, 255, 0.05);
    transform: translateY(-1px);
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
}

/* Product List */
.product-list {
    max-height: 400px;
    overflow-y: auto;
    padding: 4px;
}

.product-item {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 16px;
    border-bottom: 2px solid #e5e7eb;
    margin-bottom: 8px;
    background: #ffffff;
    border-radius: 8px;
    transition: all 0.2s ease;
}

.product-item:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.product-item:hover {
    background: #f9fafb;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.product-info {
    flex-grow: 1;
}

.product-name {
    display: block;
    font-weight: 600;
    margin-bottom: 5px;
    color: #130325;
}

.product-stats {
    font-size: 14px;
    color: #6b7280;
}

.rank {
    font-weight: 700;
    color: #130325;
    min-width: 30px;
}

/* Buttons */
.btn-primary,
.restock-btn,
.view-btn {
    padding: 8px 16px;
    border-radius: 4px;
    text-decoration: none;
    display: inline-block;
    font-weight: 500;
    transition: all 0.3s ease;
}

.btn-primary {
    background-color: #007bff;
    color: white;
}

.btn-primary:hover {
    background-color: #0056b3;
}

.restock-btn {
    background-color: #28a745;
    color: white;
    padding: 4px 8px;
    font-size: 0.8em;
}

.restock-btn:hover {
    background-color: #218838;
}

.view-btn {
    background: #f3f4f6;
    color: #130325;
    border: 1px solid #e5e7eb;
    padding: 8px;
    width: 36px;
    height: 36px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    font-weight: 500;
    transition: all 0.2s ease;
    position: relative;
}

.view-btn:hover {
    background: #130325;
    border-color: #130325;
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.view-btn::after {
    content: 'View Details';
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%) translateY(-4px);
    background: #130325;
    color: #ffffff;
    padding: 6px 10px;
    border-radius: 4px;
    font-size: 11px;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease, transform 0.2s ease;
    margin-bottom: 4px;
}

.view-btn::before {
    content: '';
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%) translateY(4px);
    border: 4px solid transparent;
    border-top-color: #130325;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease, transform 0.2s ease;
}

.view-btn:hover::after,
.view-btn:hover::before {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
}

/* Loading Animation */
.product-image-loading {
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: loading 1.5s infinite;
}

@keyframes loading {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

@keyframes ripple {
    to {
        transform: scale(4);
        opacity: 0;
    }
}

/* Comprehensive Responsive Design */
@media (max-width: 1024px) {
    main {
        margin-left: 70px !important;
        padding: 5px 20px 30px 20px !important;
    }
    
    .kpi-card-wide {
        grid-column: span 1;
    }
    
    .stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)) !important;
        gap: 12px !important;
    }
    
    .dashboard-welcome {
        font-size: 28px !important;
    }
    
    h1 {
        font-size: 24px !important;
    }
    
    h2 {
        font-size: 20px !important;
    }
}

@media (max-width: 768px) {
    main {
        margin-left: 0 !important;
        padding: 5px 16px 24px 16px !important;
    }
    
    .status-cards {
        flex-direction: column;
        gap: 10px;
    }
    
    .mobile-order-card {
        transition: all 0.2s ease-in-out;
    }
    
    .mobile-order-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
    }
    
    .stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)) !important;
        gap: 10px !important;
    }
    
    .kpi-card {
        min-height: 100px !important;
        padding: 14px !important;
    }
    
    .kpi-value {
        font-size: 18px !important;
    }
    
    .kpi-header h3 {
        font-size: 11px !important;
    }
    
    .dashboard-welcome {
        font-size: 24px !important;
    }
    
    h1 {
        font-size: 20px !important;
    }
    
    h2 {
        font-size: 18px !important;
    }
    
    .section {
        padding: 16px !important;
        border-radius: 6px !important;
    }
    
    .orders-table {
        font-size: 12px !important;
    }
    
    .orders-table th,
    .orders-table td {
        padding: 8px !important;
        font-size: 11px !important;
    }
}

@media (max-width: 480px) {
    main {
        padding: 5px 12px 20px 12px !important;
    }
    
    .stats-grid {
        grid-template-columns: 1fr !important;
        gap: 8px !important;
    }
    
    .kpi-card {
        min-height: 90px !important;
        padding: 12px !important;
    }
    
    .kpi-value {
        font-size: 16px !important;
    }
    
    .kpi-header h3 {
        font-size: 10px !important;
    }
    
    .kpi-subtitle {
        font-size: 10px !important;
    }
    
    .dashboard-welcome {
        font-size: 20px !important;
    }
    
    h1 {
        font-size: 18px !important;
    }
    
    h2 {
        font-size: 16px !important;
    }
    
    .section {
        padding: 12px !important;
        border-radius: 6px !important;
    }
    
    .orders-table th,
    .orders-table td {
        padding: 6px !important;
        font-size: 10px !important;
    }
    
    .status-badge {
        font-size: 9px !important;
        padding: 2px 4px !important;
    }
}

@media (max-width: 360px) {
    main {
        padding: 5px 8px 16px 8px !important;
    }
    
    .kpi-card {
        min-height: 80px !important;
        padding: 10px !important;
    }
    
    .kpi-value {
        font-size: 14px !important;
    }
    
    .dashboard-welcome {
        font-size: 18px !important;
    }
    
    h1 {
        font-size: 16px !important;
    }
    
    h2 {
        font-size: 14px !important;
    }
    
    .section {
        padding: 10px !important;
    }
}
/* Recent Orders Section */
.section {
    background: rgba(255, 255, 255, 0.1);
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.3);
    color: #F9F9F9;
    backdrop-filter: blur(10px);
}

/* Recent Orders Table Styles */
.orders-table-container {
    overflow-x: auto;
    margin-bottom: 15px;
    border: 1px solid rgba(0, 0, 0, 0.1);
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    background: #ffffff;
}

.orders-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.orders-table thead {
    background: #130325;
    position: sticky;
    top: 0;
    z-index: 10;
    border-bottom: 2px solid #FFD736;
}

.orders-table th {
    padding: 12px 12px;
    text-align: left;
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #ffffff;
    border-bottom: 2px solid #FFD736;
}

.orders-table td {
    padding: 12px;
    border-bottom: 1px solid #f0f0f0;
    color: #130325;
    background: #ffffff;
}

.orders-table tbody tr {
    background: #ffffff;
    transition: all 0.15s ease-in-out;
}

.orders-table tbody tr:hover {
    background: rgba(255, 215, 54, 0.05) !important;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}
.orders-table tbody tr:hover td,
.orders-table tbody tr:hover span,
.orders-table tbody tr:hover a {
    color: #130325 !important;
}

/* Order ID and Product Info */
.orders-table .font-mono {
    color: #130325;
    font-weight: 700;
}

.orders-table .text-gray-400 {
    color: #6b7280 !important;
}

.orders-table .text-gray-500 {
    color: #6b7280 !important;
}

.orders-table .text-gray-900 {
    color: #130325 !important;
}

/* Product Image */
.orders-table img {
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

/* Payment Status Badges */
.payment-status {
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 4px 10px;
    border-radius: 4px;
    display: inline-block;
    white-space: nowrap;
}

.payment-status.paid { 
    color: #065f46; 
    background-color: #d1fae5;
    border: 1px solid #10b981;
}

.payment-status.pending { 
    color: #92400e; 
    background-color: #fef3c7;
    border: 1px solid #f59e0b;
}

/* Order Status Badges */
.order-status {
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: capitalize;
    letter-spacing: 0.05em;
    padding: 4px 10px;
    border-radius: 4px;
    display: inline-block;
    white-space: nowrap;
}

.order-status.pending { 
    background-color: #dbeafe; 
    color: #1e40af;
    border: 1px solid #3b82f6;
}

.order-status.processing { 
    background-color: #fef3c7; 
    color: #92400e;
    border: 1px solid #f59e0b;
}

.order-status.shipped { 
    background-color: #e0e7ff; 
    color: #5b21b6;
    border: 1px solid #8b5cf6;
}

.order-status.delivered { 
    background-color: #d1fae5; 
    color: #065f46;
    border: 1px solid #10b981;
}

.order-status.cancelled { 
    background-color: #fee2e2; 
    color: #991b1b;
    border: 1px solid #ef4444;
}

/* Empty State */
.orders-table + div .text-center {
    padding: 48px 24px;
}

.orders-table + div svg {
    color: rgba(255, 255, 255, 0.4);
}

.orders-table + div h3 {
    color: #F9F9F9;
}

.orders-table + div p {
    color: rgba(255, 255, 255, 0.6);
}

/* View All Orders Button */
.orders-table ~ .mt-4 a {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    color: white;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    box-shadow: 0 4px 6px rgba(0, 123, 255, 0.3);
}

.orders-table ~ .mt-4 a:hover {
    background: linear-gradient(135deg, #0056b3 0%, #004085 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0, 123, 255, 0.4);
}

/* Mobile Responsive - Additional table adjustments */
@media (max-width: 768px) {
    .orders-table th,
    .orders-table td {
        padding: 8px 6px !important;
        font-size: 0.75rem !important;
    }
    
    .orders-table th {
        font-size: 0.7rem;
    }
    
    .payment-status,
    .order-status {
        font-size: 0.65rem;
        padding: 3px 8px;
    }
    
    .mobile-order-card {
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        padding: 16px;
        margin-bottom: 12px;
        transition: all 0.2s ease-in-out;
    }
    
    .mobile-order-card:hover {
        background: rgba(255, 255, 255, 0.08);
        transform: translateY(-2px);
        box-shadow: 0 8px 15px rgba(0, 0, 0, 0.3);
    }
}

/* Scrollbar Styling */
.orders-table-container::-webkit-scrollbar {
    height: 8px;
}

.orders-table-container::-webkit-scrollbar-track {
    background: #f3f4f6;
    border-radius: 4px;
}

.orders-table-container::-webkit-scrollbar-thumb {
    background: #d1d5db;
    border-radius: 4px;
}

.orders-table-container::-webkit-scrollbar-thumb:hover {
    background: #9ca3af;
}
</style>

<body class="bg-gray-50">
    <div class="container mx-auto px-4 py-2">
        <!-- Main Section Header with Export Button -->
        <div class="flex justify-between items-center mb-6">
            <h1 class="dashboard-welcome">
                Welcome, <?php echo htmlspecialchars($sellerName); ?>
            </h1>
            <button onclick="showDashboardExportModal()" class="download-icon-btn" title="Export Dashboard Data">
                <i class="fas fa-file-export"></i> EXPORT
            </button>
        </div>

        <!-- Key Performance Indicators -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <!-- Confirmed Revenue - DOUBLE WIDTH -->
            <div class="kpi-card kpi-card-wide" data-aos="fade-up" data-aos-delay="100">
                <div class="kpi-header">
                    <i class="fas fa-money-bill-wave text-green-400"></i>
                    <h3>Confirmed Revenue</h3>
                </div>
                <div class="kpi-value">₱<?php echo number_format($confirmedRevenue, 2); ?></div>
                <div class="kpi-subtitle">Money received</div>
            </div>
            
            <!-- Total Orders -->
            <div class="kpi-card" data-aos="fade-up" data-aos-delay="200">
                <div class="kpi-header">
                    <i class="fas fa-shopping-cart text-blue-400"></i>
                    <h3>Total Orders</h3>
                </div>
                <div class="kpi-value"><?php echo number_format($uniqueOrders); ?></div>
                <div class="kpi-subtitle">Orders placed</div>
            </div>
            
            <!-- Items Sold -->
            <div class="kpi-card" data-aos="fade-up" data-aos-delay="300">
                <div class="kpi-header">
                    <i class="fas fa-box text-purple-400"></i>
                    <h3>Items Sold</h3>
                </div>
                <div class="kpi-value"><?php echo number_format($totalSales); ?></div>
                <div class="kpi-subtitle">Total quantity</div>
            </div>
        </div>

        <!-- Secondary Metrics -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <!-- Average Order Value (moved from first row) -->
            <div class="kpi-card" data-aos="fade-up" data-aos-delay="400">
                <div class="kpi-header">
                    <i class="fas fa-chart-line text-yellow-400"></i>
                    <h3>Avg Order Value</h3>
                </div>
                <div class="kpi-value">₱<?php echo number_format($avgOrderValue, 2); ?></div>
                <div class="kpi-subtitle">Per order</div>
            </div>
            
            <!-- Pending Revenue -->
            <div class="kpi-card" data-aos="fade-up" data-aos-delay="500">
                <div class="kpi-header">
                    <i class="fas fa-clock text-orange-400"></i>
                    <h3>Pending Revenue</h3>
                </div>
                <div class="kpi-value">₱<?php echo number_format($pendingRevenue, 2); ?></div>
                <div class="kpi-subtitle">COD awaiting delivery</div>
            </div>
            
            <!-- Return Rate -->
            <div class="kpi-card" data-aos="fade-up" data-aos-delay="600">
                <div class="kpi-header">
                    <i class="fas fa-undo text-red-400"></i>
                    <h3>Return Rate</h3>
                </div>
                <div class="kpi-value">0.0%</div>
                <div class="kpi-subtitle">0 returns</div>
            </div>
            
            <!-- Active Products -->
            <div class="kpi-card" data-aos="fade-up" data-aos-delay="700">
                <div class="kpi-header">
                    <i class="fas fa-store text-indigo-400"></i>
                    <h3>Active Products</h3>
                </div>
                <div class="kpi-value"><?php echo $totalProducts; ?></div>
                <div class="kpi-subtitle">Listings</div>
            </div>
        </div>

<!-- Optional: Add a breakdown section for more detailed revenue tracking -->
<div class="stat-card mb-8" data-aos="fade-up">
    <h2 class="text-lg font-bold mb-4">Revenue Breakdown</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="stat-card paid" style="background: #f0fdf4; border-left-color: #10b981;">
            <h3 class="text-sm font-medium mb-1" style="color: #065f46;">Online Payments (Secured)</h3>
            <p class="stat-value" style="color: #059669;">₱<?php echo number_format($onlinePaymentRevenue, 2); ?></p>
            <small style="color: #047857;">Gcash, PayPal, Credit Card, Debit Card</small>
        </div>
        <div class="stat-card paid" style="background: #eff6ff; border-left-color: #3b82f6;">
            <h3 class="text-sm font-medium mb-1" style="color: #1e40af;">COD Delivered</h3>
            <p class="stat-value" style="color: #2563eb;">₱<?php echo number_format($codDeliveredRevenue, 2); ?></p>
            <small style="color: #1e3a8a;">Cash payments received</small>
        </div>
    </div>
</div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <!-- Order Status Overview -->
            <div class="section" data-aos="fade-right">
                <h2 class="text-xl font-bold mb-4">Order Status Overview</h2>
                <div class="status-cards">
                    <div class="status-card pending">
                        <span class="status-count"><?php echo $statusCounts['pending'] ?? 0; ?></span>
                        <span>Pending</span>
                    </div>
                    <div class="status-card processing">
                        <span class="status-count"><?php echo $statusCounts['processing'] ?? 0; ?></span>
                        <span>Processing</span>
                    </div>
                    <div class="status-card shipped">
                        <span class="status-count"><?php echo $statusCounts['shipped'] ?? 0; ?></span>
                        <span>Shipped</span>
                    </div>
                    <div class="status-card delivered">
                        <span class="status-count"><?php echo $statusCounts['delivered'] ?? 0; ?></span>
                        <span>Delivered</span>
                    </div>
                    <div class="status-card cancelled">
                        <span class="status-count"><?php echo $statusCounts['cancelled'] ?? 0; ?></span>
                        <span>Cancelled</span>
                    </div>
                </div>
            </div>

            <!-- Low Stock Alert -->
            <div class="section" data-aos="fade-left">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-bold">Low Stock Alert</h2>
                    <?php if (count($lowStockProducts) > 0): ?>
                        <span class="alert-badge"><?php echo count($lowStockProducts); ?></span>
                    <?php endif; ?>
                </div>
                <?php if (empty($lowStockProducts)): ?>
                    <div style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: #f0fdf4; border: 1px solid #10b981; border-radius: 8px; color: #065f46;">
                        <i class="fas fa-check-circle" style="font-size: 18px; color: #10b981;"></i>
                        <span style="font-weight: 500; font-size: 14px;">All products have sufficient stock.</span>
                    </div>
                <?php else: ?>
                    <div class="alert-list">
                        <?php foreach ($lowStockProducts as $product): ?>
                            <div class="alert-item <?php echo $product['stock_quantity'] == 0 ? 'out-of-stock' : 'low-stock'; ?>">
                                <span class="font-medium"><?php echo htmlspecialchars($product['name']); ?></span>
                                <div class="flex items-center space-x-4">
                                    <span class="<?php echo $product['stock_quantity'] == 0 ? 'text-red-600 font-bold' : 'text-yellow-600'; ?>">
                                        <?php echo $product['stock_quantity']; ?> 
                                        <?php echo $product['stock_quantity'] == 0 ? 'OUT OF STOCK' : 'left'; ?>
                                    </span>
                                    <a href="edit-product.php?id=<?php echo $product['id']; ?>" class="restock-btn">Restock</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sales Trend Chart with Dropdown Controls -->
        <div class="section mb-8" data-aos="fade-up">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4">
                <h2 class="text-xl font-bold mb-2 sm:mb-0">Sales Trend</h2>
                <div class="flex flex-col sm:flex-row gap-2">
                    <!-- Time Period Dropdown -->
                    <div class="relative">
                        <select id="timePeriodSelect" class="appearance-none bg-white border border-gray-300 rounded-lg px-4 py-2 pr-8 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="week">Last 1 Week</option>
                            <option value="month">Last 1 Month</option>
                            <option value="6months" selected>Last 6 Months</option>
                            <option value="year">Last 1 Year</option>
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    </div>
                    
                    <!-- Chart Type Dropdown -->
                    <div class="relative">
                        <select id="chartTypeSelect" class="appearance-none bg-white border border-gray-300 rounded-lg px-4 py-2 pr-8 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="bar">Bar Chart</option>
                            <option value="line">Line Chart</option>
                            <option value="area">Area Chart</option>
                            <option value="mixed" selected>Mixed Chart</option>
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Loading indicator -->
            <div id="chartLoading" class="hidden flex items-center justify-center h-80">
                <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
            </div>
            
            <!-- Chart container -->
            <div id="chartContainer" class="h-80">
                <canvas id="salesChart"></canvas>
            </div>
            
            <!-- No data message -->
            <div id="noDataMessage" class="hidden text-center py-20">
                <svg class="mx-auto h-12 w-12 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                <p class="text-gray-500">No sales data available for the selected period.</p>
            </div>
        </div>

        <!-- Top Selling Products -->
        <div class="section mb-8" data-aos="fade-up">
            <h2 class="text-xl font-bold mb-4">Top Selling Products</h2>
            <?php if (empty($topProducts)): ?>
            <p class="text-gray-500">No sales data available.</p>
            <?php else: ?>
            <div class="product-list">
                <?php foreach ($topProducts as $index => $product): ?>
                <div class="product-item">
                    <span class="rank">#<?php echo $index + 1; ?></span>
                    <div class="product-info">
                    <span class="product-name"><?php echo htmlspecialchars($product['name']); ?></span>
                    <span class="product-stats">
                        <?php echo $product['total_sold']; ?> sold • 
                        ₱<?php echo number_format($product['revenue'], 2); ?> revenue
                    </span>
                    <?php
                    $methodLabels = [
                        'gcash' => 'GCash',
                        'paypal' => 'PayPal',
                        'credit_card' => 'Credit Card',
                        'debit_card' => 'Debit Card',
                        'cod' => 'Cash on Delivery',
                    ];
                    $methods = array_filter(array_map('trim', explode(',', $product['payment_methods'])));
                    $displayMethods = array_map(function($m) use ($methodLabels) {
                        return $methodLabels[$m] ?? ucfirst($m);
                    }, $methods);
                    ?>
                    <span class="text-xs block mt-1" style="color: #6b7280;">
                        Payment Methods Used: <?php echo implode(', ', $displayMethods); ?>
                    </span>
                    </div>
                    <a href="edit-product.php?id=<?php echo $product['id']; ?>" class="view-btn" title="View Details">
                        <i class="fas fa-eye"></i>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

       <!-- Recent Orders -->
        <div class="section mb-8" data-aos="fade-up">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-xl font-bold">Recent Orders</h2>
        <span style="background: #eff6ff; color: #1e40af; font-size: 12px; font-weight: 500; padding: 4px 10px; border-radius: 9999px; border: 1px solid #3b82f6;">
            <?php echo count($recentOrders); ?> orders
        </span>
    </div>

    <?php if (empty($recentOrders)): ?>
        <div class="text-center py-12">
            <svg class="mx-auto h-12 w-12 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
            </svg>
            <h3 class="text-lg font-medium mb-2" style="color: #130325;">No Recent Orders</h3>
            <p class="text-gray-600">You haven't received any orders yet. Start promoting your products!</p>
        </div>
    <?php else: ?>
        <!-- Responsive Recent Orders List -->
        <div class="orders-table-container overflow-auto max-h-96" style="border: 1px solid rgba(0, 0, 0, 0.1); border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <table class="orders-table min-w-full text-sm">
                <thead class="sticky top-0">
                    <tr>
                        <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider">Order</th>
                        <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider">Product</th>
                        <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider">Customer</th>
                        <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider">Qty/Price</th>
                        <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider">Total</th>
                        <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider">Payment</th>
                        <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider">Status</th>
                        <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider">Date</th>
                    </tr>
                </thead>
                <tbody class="bg-transparent">
                    <?php foreach ($recentOrders as $order): ?>
                        <tr class="transition duration-150">
                            <td class="px-3 py-3 whitespace-nowrap">
                                <div class="flex flex-col">
                                    <span class="font-mono text-xs font-bold" style="color: #130325;">#<?php echo $order['id']; ?></span>
                                    <?php if (!empty($order['product_sku'])): ?>
                                        <span class="text-xs" style="color: #6b7280;">SKU: <?php echo htmlspecialchars($order['product_sku']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex items-center space-x-2">
                                    <?php if (!empty($order['product_image'])): ?>
                                        <img class="w-8 h-8 rounded object-cover" style="border: 1px solid #e5e7eb;" 
                                             src="<?php echo htmlspecialchars($order['product_image']); ?>" 
                                             alt="<?php echo htmlspecialchars($order['product_name']); ?>"
                                             onerror="this.src='placeholder-product.png'">
                                    <?php else: ?>
                                        <div class="w-8 h-8 rounded flex items-center justify-center" style="background: #f3f4f6;">
                                            <svg class="w-4 h-4" style="color: #9ca3af;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                            </svg>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium truncate max-w-32" style="color: #130325;" title="<?php echo htmlspecialchars($order['product_name']); ?>">
                                            <?php echo htmlspecialchars($order['product_name']); ?>
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-3 whitespace-nowrap">
                                <div class="flex flex-col">
                                    <span class="text-sm font-medium" style="color: #130325;"><?php echo htmlspecialchars($order['customer_name']); ?></span>
                                    <span class="text-xs" style="color: #6b7280;"><?php echo htmlspecialchars($order['customer_email']); ?></span>
                                </div>
                            </td>
                            <td class="px-3 py-3 whitespace-nowrap">
                                <div class="flex flex-col">
                                    <span class="text-sm font-medium" style="color: #130325;"><?php echo $order['quantity']; ?> item(s)</span>
                                    <span class="text-xs" style="color: #6b7280;">₱<?php echo number_format($order['item_price'], 2); ?></span>
                                </div>
                            </td>
                            <td class="px-3 py-3 whitespace-nowrap">
                                <span class="font-bold text-sm" style="color: #130325;">
                                    ₱<?php echo number_format($order['total_amount'], 2); ?>
                                </span>
                            </td>
                            <td class="px-3 py-3 whitespace-nowrap">
                                <span class="payment-status <?php echo ($order['payment_method'] == 'cod' && $order['status'] != 'delivered') ? 'pending' : 'paid'; ?> inline-flex px-2 py-1 text-xs font-semibold rounded">
                                    <?php echo $order['payment_status']; ?>
                                </span>
                            </td>
                            <td class="px-3 py-3 whitespace-nowrap">
                                <span class="order-status <?php echo $order['status']; ?> inline-flex px-2 py-1 text-xs font-semibold rounded capitalize">
                                    <?php echo ucfirst($order['status']); ?>
                                </span>
                            </td>
                            <td class="px-3 py-3 whitespace-nowrap text-xs" style="color: #6b7280;">
                                <div class="flex flex-col">
                                    <span><?php echo date('M j, Y', strtotime($order['created_at'])); ?></span>
                                    <span class="text-xs"><?php echo date('g:i A', strtotime($order['created_at'])); ?></span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- View All Orders Button -->
    <!-- <div class="mt-4 text-center">
        <a href="seller-orders.php" 
           class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-800 hover:from-blue-700 hover:to-blue-900 text-white text-sm font-medium rounded-lg transition duration-300 space-x-2 shadow-lg">
            <span>View All Orders</span>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
        </a>
    </div> -->
</div>

    <!-- View All Orders Button -->
    <!-- <div class="mt-4 text-center">
        <a href="seller-orders.php" 
           class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition duration-300 space-x-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
            <span>View All Orders</span>
        </a>
    </div> -->


        <!-- Quick Actions -->
        
        <!-- Dashboard Export Modal -->
        <div class="modal fade" id="dashboardExportModal" tabindex="-1" aria-hidden="true" style="display: none;">
            <div class="modal-dialog">
                <div class="modal-content" style="border-radius:12px; overflow:hidden; border:none; box-shadow:0 10px 40px rgba(0,0,0,0.2);">
                    <div class="modal-header" style="background:#130325; color:#ffffff; display:flex; align-items:center; justify-content:space-between;">
                        <h5 class="modal-title" style="margin:0; font-weight:700; color:#ffffff;">Export Dashboard Data</h5>
                        <button type="button" onclick="closeDashboardExportModal()" aria-label="Close" style="background:transparent; border:none; color:#ffffff; font-size:20px; cursor:pointer; line-height:1;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="modal-body" style="padding:16px;">
                        <div class="mb-3">
                            <label for="dashboardExportFormat" class="form-label" style="color:#130325; font-weight:700;">Format</label>
                            <select class="form-select" id="dashboardExportFormat" required style="border:none; box-shadow: inset 0 0 0 1px rgba(0,0,0,0.08);">
                                <option value="">Select format...</option>
                                <option value="csv">CSV</option>
                                <option value="pdf">PDF</option>
                            </select>
                            <div id="dashboardExportError" class="error-message" style="display: none; color:#dc3545; margin-top:8px;">
                                <i class="fas fa-exclamation-circle"></i>
                                <span>Please select an export format.</span>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer" style="padding: 12px 16px; border-top:none; display:flex; gap:10px; justify-content:flex-end;">
                        <button type="button" onclick="closeDashboardExportModal()" style="padding:8px 16px; background:#f3f4f6; color:#130325; border:none; border-radius:6px; font-weight:600;">Cancel</button>
                        <button type="button" onclick="confirmDashboardExport()" style="padding:8px 16px; background:#130325; color:#ffffff; border:none; border-radius:6px; font-weight:600;">Export</button>
                    </div>
                </div>
            </div>
        </div>
    
    <script>
        // Initialize AOS animations
        AOS.init({
            duration: 800,
            easing: 'ease-in-out',
            once: true
        });

        // Sales Chart with Real Database Data
       let salesChart = null;
        let chartUpdateInterval = null;
        async function fetchLatestSalesData() {
    try {
        const response = await fetch('ajax/get-sales-data.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'get_sales_data',
                seller_id: <?php echo $userId; ?>
            })
        });
        
        if (response.ok) {
            const data = await response.json();
            return data;
        }
    } catch (error) {
        console.warn('Failed to fetch latest sales data:', error);
    }
    
    // Fallback to current data if fetch fails
    return {
        week: <?php echo json_encode($weeklySales); ?>,
        month: <?php echo json_encode($monthlySalesDaily); ?>,
        '6months': <?php echo json_encode($monthlySales); ?>,
        year: <?php echo json_encode($yearlySales); ?>
    };
}
        // Real data from database for all time periods
        const salesDataSets = {
            week: <?php echo json_encode($weeklySales); ?>,
            month: <?php echo json_encode($monthlySalesDaily); ?>,
            '6months': <?php echo json_encode($monthlySales); ?>,
            year: <?php echo json_encode($yearlySales); ?>
        };

        function formatLabels(data, period) {
    if (!data || data.length === 0) return [];
    
    return data.map(item => {
        let dateStr = item.date || item.month;
        
        switch(period) {
            case 'week':
            case 'month':
                const date = new Date(dateStr);
                const today = new Date();
                const yesterday = new Date(today);
                yesterday.setDate(yesterday.getDate() - 1);
                
                // Show "Today" and "Yesterday" for recent dates
                if (date.toDateString() === today.toDateString()) {
                    return 'Today';
                } else if (date.toDateString() === yesterday.toDateString()) {
                    return 'Yesterday';
                } else {
                    return date.toLocaleDateString('en-US', { 
                        month: 'short', 
                        day: 'numeric' 
                    });
                }
                
            case '6months':
            case 'year':
                const monthDate = new Date(dateStr + '-01');
                const currentMonth = new Date();
                
                if (monthDate.getFullYear() === currentMonth.getFullYear() && 
                    monthDate.getMonth() === currentMonth.getMonth()) {
                    return 'This Month';
                } else {
                    return monthDate.toLocaleDateString('en-US', { 
                        month: 'short', 
                        year: monthDate.getFullYear() !== currentMonth.getFullYear() ? 'numeric' : undefined 
                    });
                }
                
            default:
                return dateStr;
        }
    });
}

                                    function getChartConfig(data, labels, chartType) {
                                const baseConfig = {
                                    data: {
                                        labels: labels,
                                        datasets: []
                                    },
                                    options: {
                                        responsive: true,
                                        maintainAspectRatio: false,
                                        interaction: {
                                            intersect: false,
                                            mode: 'index'
                                        },
                                        plugins: {
                                            legend: {
                                                display: true,
                                                position: 'top',
                                                labels: {
                                                    usePointStyle: true,
                                                    padding: 20
                                                }
                                            },
                                            tooltip: {
                                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                                titleColor: 'white',
                                                bodyColor: 'white',
                                                borderColor: 'rgba(59, 130, 246, 1)',
                                                borderWidth: 1,
                                                callbacks: {
                                                    label: function(context) {
                                                        const label = context.dataset.label || '';
                                                        const value = context.parsed.y;
                                                        
                                                        if (label.includes('Revenue')) {
                                                            return label + ': $' + value.toLocaleString('en-US', {
                                                                minimumFractionDigits: 2,
                                                                maximumFractionDigits: 2
                                                            });
                                                        } else {
                                                            return label + ': ' + value.toLocaleString();
                                                        }
                                                    }
                                                }
                                            }
                                        },
                                        scales: {
                                            y: {
                                                type: 'linear',
                                                display: true,
                                                position: 'left',
                                                title: {
                                                    display: true,
                                                    text: 'Revenue ($)',
                                                    font: {
                                                        weight: 'bold'
                                                    }
                                                },
                                                grid: {
                                                    color: 'rgba(0, 0, 0, 0.1)',
                                                    drawBorder: false
                                                },
                                                ticks: {
                                                    callback: function(value) {
                                                        return '$' + value.toLocaleString();
                                                    }
                                                }
                                            },
                                            x: {
                                                grid: {
                                                    display: false
                                                },
                                                ticks: {
                                                    maxRotation: 45,
                                                    minRotation: 0
                                                }
                                            }
                                        },
                                        elements: {
                                            point: {
                                                radius: 4,
                                                hoverRadius: 8
                                            },
                                            line: {
                                                tension: 0.4
                                            }
                                        },
                                        animation: {
                                            duration: 1000,
                                            easing: 'easeInOutQuart'
                                        }
                                    }
                                };

                                const revenues = data.map(item => parseFloat(item.revenue || 0));
                                const orders = data.map(item => parseInt(item.orders || 0));

                                // Color scheme
                                const colors = {
                                    primary: {
                                        bg: 'rgba(59, 130, 246, 0.1)',
                                        border: 'rgba(59, 130, 246, 1)',
                                        fill: 'rgba(59, 130, 246, 0.3)'
                                    },
                                    secondary: {
                                        bg: 'rgba(16, 185, 129, 0.1)',
                                        border: 'rgba(16, 185, 129, 1)',
                                        fill: 'rgba(16, 185, 129, 0.3)'
                                    }
                                };

                                switch(chartType) {
                                    case 'bar':
                                        baseConfig.type = 'bar';
                                        baseConfig.data.datasets = [
                                            {
                                                label: 'Revenue ($)',
                                                data: revenues,
                                                backgroundColor: colors.primary.fill,
                                                borderColor: colors.primary.border,
                                                borderWidth: 1,
                                                borderRadius: 4,
                                                borderSkipped: false
                                            }
                                        ];
                                        break;

                                    case 'line':
                                        baseConfig.type = 'line';
                                        baseConfig.data.datasets = [
                                            {
                                                label: 'Revenue ($)',
                                                data: revenues,
                                                borderColor: colors.primary.border,
                                                backgroundColor: colors.primary.bg,
                                                borderWidth: 3,
                                                fill: false,
                                                tension: 0.4,
                                                pointBackgroundColor: colors.primary.border,
                                                pointBorderColor: '#ffffff',
                                                pointBorderWidth: 2
                                            }
                                        ];
                                        break;

                                    case 'area':
                                        baseConfig.type = 'line';
                                        baseConfig.data.datasets = [
                                            {
                                                label: 'Revenue ($)',
                                                data: revenues,
                                                borderColor: colors.primary.border,
                                                backgroundColor: colors.primary.fill,
                                                borderWidth: 3,
                                                fill: true,
                                                tension: 0.4,
                                                pointBackgroundColor: colors.primary.border,
                                                pointBorderColor: '#ffffff',
                                                pointBorderWidth: 2
                                            }
                                        ];
                                        break;

                                    case 'mixed':
                                    default:
                                        baseConfig.type = 'bar';
                                        baseConfig.data.datasets = [
                                            {
                                                label: 'Revenue ($)',
                                                data: revenues,
                                                backgroundColor: colors.primary.fill,
                                                borderColor: colors.primary.border,
                                                borderWidth: 1,
                                                borderRadius: 4,
                                                borderSkipped: false,
                                                yAxisID: 'y'
                                            },
                                            {
                                                label: 'Orders',
                                                data: orders,
                                                borderColor: colors.secondary.border,
                                                backgroundColor: colors.secondary.border,
                                                borderWidth: 3,
                                                type: 'line',
                                                yAxisID: 'y1',
                                                fill: false,
                                                tension: 0.4,
                                                pointBackgroundColor: colors.secondary.border,
                                                pointBorderColor: '#ffffff',
                                                pointBorderWidth: 2
                                            }
                                        ];
                                        
                                        // Add second y-axis for mixed chart
                                        baseConfig.options.scales.y1 = {
                                            type: 'linear',
                                            display: true,
                                            position: 'right',
                                            grid: {
                                                drawOnChartArea: false
                                            },
                                            title: {
                                                display: true,
                                                text: 'Orders',
                                                font: {
                                                    weight: 'bold'
                                                }
                                            },
                                            ticks: {
                                                callback: function(value) {
                                                    return value.toLocaleString();
                                                }
                                            }
                                        };
                                        break;
                                }

                                return baseConfig;
                            }

                                async function updateChart(useCachedData = false) {
                            const period = document.getElementById('timePeriodSelect').value;
                            const chartType = document.getElementById('chartTypeSelect').value;
                            
                            // Show loading
                            document.getElementById('chartLoading').classList.remove('hidden');
                            document.getElementById('chartContainer').classList.add('hidden');
                            document.getElementById('noDataMessage').classList.add('hidden');

                            try {
                                // Get fresh data unless using cached data
                                let salesDataSets;
                                if (useCachedData) {
                                    salesDataSets = {
                                        week: <?php echo json_encode($weeklySales); ?>,
                                        month: <?php echo json_encode($monthlySalesDaily); ?>,
                                        '6months': <?php echo json_encode($monthlySales); ?>,
                                        year: <?php echo json_encode($yearlySales); ?>
                                    };
                                } else {
                                    salesDataSets = await fetchLatestSalesData();
                                }

                                const data = salesDataSets[period];
                                
                                setTimeout(() => {
                                    if (!data || data.length === 0) {
                                        // Show no data message
                                        document.getElementById('chartLoading').classList.add('hidden');
                                        document.getElementById('noDataMessage').classList.remove('hidden');
                                        
                                        const periodNames = {
                                            'week': 'last week',
                                            'month': 'last month', 
                                            '6months': 'last 6 months',
                                            'year': 'last year'
                                        };
                                        document.querySelector('#noDataMessage p').textContent = 
                                            `No sales data available for the ${periodNames[period]}.`;
                                        return;
                                    }

                                    // Destroy existing chart
                                    if (salesChart) {
                                        salesChart.destroy();
                                    }

                                    // Format labels based on period
                                    const labels = formatLabels(data, period);
                                    
                                    // Create new chart
                                    const ctx = document.getElementById('salesChart').getContext('2d');
                                    const config = getChartConfig(data, labels, chartType);
                                    salesChart = new Chart(ctx, config);

                                    // Hide loading and show chart
                                    document.getElementById('chartLoading').classList.add('hidden');
                                    document.getElementById('chartContainer').classList.remove('hidden');
                                }, 300);

                            } catch (error) {
                                console.error('Error updating chart:', error);
                                document.getElementById('chartLoading').classList.add('hidden');
                                document.getElementById('noDataMessage').classList.remove('hidden');
                            }
                        }

                        function startAutoUpdate() {
    // Clear existing interval
    if (chartUpdateInterval) {
        clearInterval(chartUpdateInterval);
    }
    
    // Update every 2 minutes if page is visible
    chartUpdateInterval = setInterval(() => {
        if (!document.hidden) {
            updateChart(false); // Use fresh data
        }
    }, 120000); // 2 minutes
}

function stopAutoUpdate() {
    if (chartUpdateInterval) {
        clearInterval(chartUpdateInterval);
        chartUpdateInterval = null;
    }
}

        // Event listeners for dropdowns
        document.getElementById('timePeriodSelect').addEventListener('change', () => updateChart(true));
        document.getElementById('chartTypeSelect').addEventListener('change', () => updateChart(true));

        // Initialize chart with default period (6 months)
        document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        stopAutoUpdate();
    } else {
        startAutoUpdate();
        // Update chart when page becomes visible
        updateChart(false);
    }
});

        // Add hover effects to stat cards and KPI cards
        document.querySelectorAll('.stat-card, .kpi-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
                this.style.boxShadow = '0 10px 20px rgba(0,0,0,0.1)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
            });
        });

        // Add click animation to action buttons
        document.querySelectorAll('.action-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                // Create ripple effect
                const ripple = document.createElement('span');
                const rect = this.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.cssText = `
                    position: absolute;
                    width: ${size}px;
                    height: ${size}px;
                    left: ${x}px;
                    top: ${y}px;
                    background: rgba(255, 255, 255, 0.3);
                    border-radius: 50%;
                    transform: scale(0);
                    animation: ripple 0.6s linear;
                    pointer-events: none;
                `;
                
                this.style.position = 'relative';
                this.style.overflow = 'hidden';
                this.appendChild(ripple);
                
                setTimeout(() => {
                    ripple.remove();
                }, 600);
            });
        });

        // Add CSS for ripple animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes ripple {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);

        // Auto-refresh data every 5 minutes (optional)
        setInterval(() => {
            // Only refresh if the page is visible
            if (!document.hidden) {
                location.reload();
            }
        }, 300000); // 5 minutes

        // Add loading states for better UX
        document.addEventListener('DOMContentLoaded', function() {
    updateChart(true); // Use cached data for initial load
    startAutoUpdate(); // Start auto-update
});

        // Status card hover effects
        document.querySelectorAll('.status-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'scale(1.05)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'scale(1)';
            });
        });
        window.addEventListener('beforeunload', function() {
    stopAutoUpdate();
    if (salesChart) {
        salesChart.destroy();
    }
});

        // Table row hover effects
        document.querySelectorAll('.orders-table tbody tr').forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.backgroundColor = '#f9fafb';
            });
            
            row.addEventListener('mouseleave', function() {
                this.style.backgroundColor = 'transparent';
            });
        });

        // Dashboard Export Modal Functions
        function showDashboardExportModal() {
            const modalElement = document.getElementById('dashboardExportModal');
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const modal = new bootstrap.Modal(modalElement);
                modal.show();
            } else {
                modalElement.style.display = 'flex';
                modalElement.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeDashboardExportModal() {
            const modalElement = document.getElementById('dashboardExportModal');
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) {
                    modal.hide();
                }
            } else {
                modalElement.style.display = 'none';
                modalElement.classList.remove('show');
                document.body.style.overflow = '';
            }
        }

        function confirmDashboardExport() {
            const format = document.getElementById('dashboardExportFormat').value;
            const errorDiv = document.getElementById('dashboardExportError');
            
            if (!format) {
                errorDiv.style.display = 'flex';
                return;
            }
            
            errorDiv.style.display = 'none';
            closeDashboardExportModal();
            
            if (format === 'csv') {
                exportDashboardToCSV();
            } else if (format === 'pdf') {
                exportDashboardToPDF();
            }
        }

        function exportDashboardToCSV() {
            // Dashboard-specific CSV export
            const csvData = [
                ['Dashboard Metrics', 'Value', 'Description'],
                ['Confirmed Revenue', '₱<?php echo number_format($confirmedRevenue, 2); ?>', 'Money received'],
                ['Total Orders', '<?php echo number_format($uniqueOrders); ?>', 'Orders placed'],
                ['Items Sold', '<?php echo number_format($totalSales); ?>', 'Total quantity'],
                ['Avg Order Value', '₱<?php echo number_format($avgOrderValue, 2); ?>', 'Per order'],
                ['Pending Revenue', '₱<?php echo number_format($pendingRevenue, 2); ?>', 'COD awaiting delivery'],
                ['Return Rate', '0.0%', '0 returns'],
                ['Active Products', '<?php echo $totalProducts; ?>', 'Listings'],
                ['', '', ''],
                ['Recent Orders', '', ''],
                ['Order ID', 'Customer', 'Product', 'Total', 'Status', 'Date']
            ];
            
            // Add recent orders data
            <?php foreach ($recentOrders as $order): ?>
            csvData.push([
                '#<?php echo $order['id']; ?>',
                '<?php echo htmlspecialchars($order['customer_name']); ?>',
                '<?php echo htmlspecialchars($order['product_name']); ?>',
                '₱<?php echo number_format($order['total_amount'], 2); ?>',
                '<?php echo ucfirst($order['status']); ?>',
                '<?php echo date('M j, Y', strtotime($order['created_at'])); ?>'
            ]);
            <?php endforeach; ?>
            
            const csvContent = csvData.map(row => row.join(',')).join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'dashboard_data_' + new Date().toISOString().split('T')[0] + '.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function exportDashboardToPDF() {
            // Dashboard-specific PDF export
            const element = document.createElement('div');
            element.innerHTML = `
                <div style="font-family: Arial, sans-serif; padding: 20px;">
                    <h1 style="color: #333; text-align: center; margin-bottom: 30px;">Seller Dashboard Report</h1>
                    <p style="text-align: center; color: #666; margin-bottom: 30px;">Generated on ${new Date().toLocaleDateString()}</p>
                    
                    <h2 style="color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px;">Key Performance Indicators</h2>
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
                        <tr style="background-color: #f8f9fa;">
                            <th style="border: 1px solid #ddd; padding: 12px; text-align: left;">Metric</th>
                            <th style="border: 1px solid #ddd; padding: 12px; text-align: left;">Value</th>
                            <th style="border: 1px solid #ddd; padding: 12px; text-align: left;">Description</th>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #ddd; padding: 12px;">Confirmed Revenue</td>
                            <td style="border: 1px solid #ddd; padding: 12px; font-weight: bold;">₱<?php echo number_format($confirmedRevenue, 2); ?></td>
                            <td style="border: 1px solid #ddd; padding: 12px;">Money received</td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #ddd; padding: 12px;">Total Orders</td>
                            <td style="border: 1px solid #ddd; padding: 12px; font-weight: bold;"><?php echo number_format($uniqueOrders); ?></td>
                            <td style="border: 1px solid #ddd; padding: 12px;">Orders placed</td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #ddd; padding: 12px;">Items Sold</td>
                            <td style="border: 1px solid #ddd; padding: 12px; font-weight: bold;"><?php echo number_format($totalSales); ?></td>
                            <td style="border: 1px solid #ddd; padding: 12px;">Total quantity</td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #ddd; padding: 12px;">Avg Order Value</td>
                            <td style="border: 1px solid #ddd; padding: 12px; font-weight: bold;">₱<?php echo number_format($avgOrderValue, 2); ?></td>
                            <td style="border: 1px solid #ddd; padding: 12px;">Per order</td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #ddd; padding: 12px;">Pending Revenue</td>
                            <td style="border: 1px solid #ddd; padding: 12px; font-weight: bold;">₱<?php echo number_format($pendingRevenue, 2); ?></td>
                            <td style="border: 1px solid #ddd; padding: 12px;">COD awaiting delivery</td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #ddd; padding: 12px;">Active Products</td>
                            <td style="border: 1px solid #ddd; padding: 12px; font-weight: bold;"><?php echo $totalProducts; ?></td>
                            <td style="border: 1px solid #ddd; padding: 12px;">Listings</td>
                        </tr>
                    </table>
                </div>
            `;
            
            const opt = {
                margin: 1,
                filename: 'dashboard_report_' + new Date().toISOString().split('T')[0] + '.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2 },
                jsPDF: { unit: 'in', format: 'letter', orientation: 'portrait' }
            };
            
            html2pdf().set(opt).from(element).save();
        }

        // Event listener for export format dropdown
        document.getElementById('dashboardExportFormat').addEventListener('change', function() {
            document.getElementById('dashboardExportError').style.display = 'none';
        });

        // Click outside to close modal
        document.getElementById('dashboardExportModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDashboardExportModal();
            }
        });
    </script>
</body>
</html>
