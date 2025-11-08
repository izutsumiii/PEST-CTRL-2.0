<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

require_once '../config/database.php';

// Remove reading POST body for orderId or isCustom. Mark all as read for user.
try {
    $userId = $_SESSION['user_id'];

    // Cover all custom notifications (IDs in notifications) for this user
    $custom = $pdo->prepare("SELECT order_id FROM notifications WHERE user_id = ?");
    $custom->execute([$userId]);
    $customIds = $custom->fetchAll(PDO::FETCH_COLUMN);
    // Remove duplicates if any
    $customIds = array_unique($customIds);
    if ($customIds) {
        $stmt = $pdo->prepare("INSERT INTO notification_reads (user_id, order_id, notification_type, read_at) VALUES (?, ?, 'custom', CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP");
        foreach ($customIds as $oid) {
            $stmt->execute([$userId, $oid]);
        }
    }

    // Cover all order update notifications (IDs from orders table) for this user
    $orders = $pdo->prepare("SELECT id FROM orders WHERE user_id = ?");
    $orders->execute([$userId]);
    $orderIds = $orders->fetchAll(PDO::FETCH_COLUMN);
    // Remove duplicates if any
    $orderIds = array_unique($orderIds);
    if ($orderIds) {
        $stmt = $pdo->prepare("INSERT INTO notification_reads (user_id, order_id, notification_type, read_at) VALUES (?, ?, 'order_update', CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP");
        foreach ($orderIds as $oid) {
            $stmt->execute([$userId, $oid]);
        }
    }

    // (Optional safety) Remove any notification_reads rows for orders/notifications that no longer exist
    // (This keeps the table clean and ensures badge doesn't come back for deleted notifications)
    $pdo->prepare("DELETE nr FROM notification_reads nr LEFT JOIN notifications n ON nr.notification_type = 'custom' AND nr.user_id = n.user_id AND nr.order_id = n.order_id WHERE n.order_id IS NULL AND nr.notification_type = 'custom' AND nr.user_id = ?")->execute([$userId]);
    $pdo->prepare("DELETE nr FROM notification_reads nr LEFT JOIN orders o ON nr.notification_type = 'order_update' AND nr.user_id = o.user_id AND nr.order_id = o.id WHERE o.id IS NULL AND nr.notification_type = 'order_update' AND nr.user_id = ?")->execute([$userId]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
