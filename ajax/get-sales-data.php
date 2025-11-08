<?php
// Create this file as: ajax/get-sales-data.php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Ensure user is logged in and is a seller
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'seller') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || $input['action'] !== 'get_sales_data') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    // Function to fill missing dates for daily data
    function fillMissingDates($data, $days) {
        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $found = false;
            foreach ($data as $item) {
                if ($item['date'] === $date) {
                    $result[] = $item;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $result[] = [
                    'date' => $date,
                    'revenue' => '0.00',
                    'orders' => '0'
                ];
            }
        }
        return $result;
    }

    // Function to fill missing months
    function fillMissingMonths($data, $months) {
        $result = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $month = date('Y-m', strtotime("-{$i} months"));
            $found = false;
            foreach ($data as $item) {
                if ($item['month'] === $month) {
                    $result[] = $item;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $result[] = [
                    'month' => $month,
                    'revenue' => '0.00',
                    'orders' => '0'
                ];
            }
        }
        return $result;
    }

    // Last 1 Week
    $stmt = $pdo->prepare("SELECT 
                          DATE(o.created_at) as date,
                          COALESCE(SUM(oi.price * oi.quantity), 0) as revenue,
                          COUNT(DISTINCT o.id) as orders
                          FROM orders o 
                          JOIN order_items oi ON o.id = oi.order_id 
                          JOIN products p ON oi.product_id = p.id 
                          WHERE p.seller_id = ? 
                          AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                          AND o.created_at <= NOW()
                          GROUP BY DATE(o.created_at)
                          ORDER BY date ASC");
    $stmt->execute([$userId]);
    $weeklySalesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $weeklySales = fillMissingDates($weeklySalesRaw, 7);

    // Last 1 Month
    $stmt = $pdo->prepare("SELECT 
                          DATE(o.created_at) as date,
                          COALESCE(SUM(oi.price * oi.quantity), 0) as revenue,
                          COUNT(DISTINCT o.id) as orders
                          FROM orders o 
                          JOIN order_items oi ON o.id = oi.order_id 
                          JOIN products p ON oi.product_id = p.id 
                          WHERE p.seller_id = ? 
                          AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                          AND o.created_at <= NOW()
                          GROUP BY DATE(o.created_at)
                          ORDER BY date ASC");
    $stmt->execute([$userId]);
    $monthlySalesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $monthlySalesDaily = fillMissingDates($monthlySalesRaw, 30);

    // Last 6 Months
    $stmt = $pdo->prepare("SELECT 
                          DATE_FORMAT(o.created_at, '%Y-%m') as month,
                          COALESCE(SUM(oi.price * oi.quantity), 0) as revenue,
                          COUNT(DISTINCT o.id) as orders
                          FROM orders o 
                          JOIN order_items oi ON o.id = oi.order_id 
                          JOIN products p ON oi.product_id = p.id 
                          WHERE p.seller_id = ? 
                          AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                          AND o.created_at <= NOW()
                          GROUP BY DATE_FORMAT(o.created_at, '%Y-%m')
                          ORDER BY month ASC");
    $stmt->execute([$userId]);
    $monthlySalesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $monthlySales = fillMissingMonths($monthlySalesRaw, 6);

    // Last 1 Year
    $stmt = $pdo->prepare("SELECT 
                          DATE_FORMAT(o.created_at, '%Y-%m') as month,
                          COALESCE(SUM(oi.price * oi.quantity), 0) as revenue,
                          COUNT(DISTINCT o.id) as orders
                          FROM orders o 
                          JOIN order_items oi ON o.id = oi.order_id 
                          JOIN products p ON oi.product_id = p.id 
                          WHERE p.seller_id = ? 
                          AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                          AND o.created_at <= NOW()
                          GROUP BY DATE_FORMAT(o.created_at, '%Y-%m')
                          ORDER BY month ASC");
    $stmt->execute([$userId]);
    $yearlySalesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $yearlySales = fillMissingMonths($yearlySalesRaw, 12);

    // Return the data
    $response = [
        'success' => true,
        'data' => [
            'week' => $weeklySales,
            'month' => $monthlySalesDaily,
            '6months' => $monthlySales,
            'year' => $yearlySales
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];

    header('Content-Type: application/json');
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error',
        'message' => $e->getMessage()
    ]);
}
?>