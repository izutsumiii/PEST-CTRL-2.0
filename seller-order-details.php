<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

requireSeller();

$sellerId = $_SESSION['user_id'];
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($orderId <= 0) {
    header('Location: view-orders.php');
    exit();
}

// Fetch order header scoped to this seller (exists if seller sold at least one item in this order)
$stmt = $pdo->prepare("SELECT o.id, o.total_amount, o.status, o.created_at,
       COALESCE(u.username, 'Guest Customer') AS customer_name,
       u.email AS customer_email,
       u.phone AS customer_phone,
       o.shipping_address
FROM orders o
JOIN order_items oi ON o.id = oi.order_id
JOIN products p ON oi.product_id = p.id
LEFT JOIN users u ON o.user_id = u.id
WHERE o.id = ? AND p.seller_id = ?
GROUP BY o.id");
$stmt->execute([$orderId, $sellerId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    // Not accessible for this seller
    header('Location: view-orders.php');
    exit();
}

// Fetch only this seller's items for the order
$itemsStmt = $pdo->prepare("SELECT oi.product_id, oi.quantity, oi.price AS item_price, p.name AS product_name, COALESCE(p.image_url, '') AS image_url
FROM order_items oi
JOIN products p ON oi.product_id = p.id
WHERE oi.order_id = ? AND p.seller_id = ?
ORDER BY p.name");
$itemsStmt->execute([$orderId, $sellerId]);
$orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/seller_header.php';
?>

<style>
/* Page layout and centering (match seller theme) */
html, body { background: #f0f2f5 !important; }
main { 
    background: #f0f2f5 !important; 
    margin-left: 120px !important; 
    margin-top: -20px !important;
    margin-bottom: 0 !important;
    padding-top: 5px !important;
    padding-bottom: 40px !important;
    padding-left: 30px !important;
    padding-right: 30px !important;
    min-height: calc(100vh - 60px) !important; 
    transition: margin-left 0.3s ease !important;
}
main.sidebar-collapsed { margin-left: 0 !important; }

/* Content container */
.details-container { max-width: 980px; margin: 0 auto; padding: 0 16px; }
.details-card { background:#ffffff; border:1px solid rgba(0,0,0,0.1); border-radius: 10px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
.details-header { display:flex; align-items:center; justify-content:space-between; margin-bottom: 12px; }
.details-title { color:#130325; font-weight:800; font-size:18px; }
.details-meta { color:#6b7280; font-size:13px; }
/* Status badge (match seller tables) */
.order-status { display:inline-block; padding:4px 10px; border-radius:4px; font-weight:700; font-size:12px; text-transform:uppercase; }
.status-pending { background: rgba(255,193,7,0.15); color: #ffc107; }
.status-processing { background: rgba(0,123,255,0.15); color: #007bff; }
.status-shipped { background: rgba(23,162,184,0.15); color: #17a2b8; }
.status-delivered { background: rgba(40,167,69,0.15); color: #28a745; }
.status-cancelled { background: rgba(220,53,69,0.15); color: #dc3545; }
.customer-info { display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin: 10px 0 16px 0; }
.customer-info .card { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:12px; }
.customer-info .label { color:#6b7280; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; }
.customer-info .value { color:#130325; font-size:14px; font-weight:700; word-break:break-word; }
.items-table { width:100%; border-collapse:collapse; margin-top: 10px; }
.items-table th, .items-table td { padding:10px; border-bottom:1px solid #f0f0f0; }
.items-table thead th { background:#f9fafb; text-align:left; font-weight:700; color:#130325; font-size:13px; }
.total-row td { font-weight:800; }
.back-link { display:inline-flex; align-items:center; gap:8px; color:#130325; text-decoration:none; font-weight:700; margin-bottom:14px; }
.back-link:hover { text-decoration:underline; }

@media (max-width: 768px) {
    main { margin-left: 0 !important; padding-left: 20px !important; padding-right: 20px !important; }
    .details-card { padding: 16px; }
}
</style>

<main>
  <div class="details-container">
    <a href="view-orders.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Orders</a>
    <div class="details-card">
      <div class="details-header">
        <div class="details-title">Order #<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?></div>
        <span class="order-status <?php echo 'status-' . htmlspecialchars(strtolower($order['status'])); ?>">
          <?php echo strtoupper(htmlspecialchars($order['status'])); ?>
        </span>
      </div>
      <div class="details-meta">
        Customer: <?php echo htmlspecialchars($order['customer_name']); ?>
        &nbsp;•&nbsp; Placed: <?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?>
      </div>

      <div class="customer-info">
        <div class="card">
          <div class="label">Customer Name</div>
          <div class="value"><?php echo htmlspecialchars($order['customer_name']); ?></div>
        </div>
        <div class="card">
          <div class="label">Email</div>
          <div class="value"><a href="mailto:<?php echo htmlspecialchars($order['customer_email']); ?>" style="color:#130325; text-decoration:underline;"><?php echo htmlspecialchars($order['customer_email'] ?? ''); ?></a></div>
        </div>
        <div class="card">
          <div class="label">Phone</div>
          <div class="value"><?php echo htmlspecialchars($order['customer_phone'] ?? ''); ?></div>
        </div>
        <div class="card" style="grid-column: span 2;">
          <div class="label">Shipping Address</div>
          <div class="value"><?php echo nl2br(htmlspecialchars($order['shipping_address'] ?? '')); ?></div>
        </div>
      </div>

      <table class="items-table">
        <thead>
          <tr>
            <th style="width:60px;">Image</th>
            <th>Product</th>
            <th style="text-align:center;">Qty</th>
            <th style="text-align:right;">Price</th>
            <th style="text-align:right;">Total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orderItems as $item): ?>
          <tr>
            <td>
              <?php if (!empty($item['image_url'])): ?>
                <img src="<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['product_name']); ?>" style="width:56px; height:56px; object-fit:cover; border-radius:6px; border:1px solid #e5e7eb;" />
              <?php else: ?>
                <div style="width:56px; height:56px; border-radius:6px; background:#f3f4f6; border:1px solid #e5e7eb;"></div>
              <?php endif; ?>
            </td>
            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
            <td style="text-align:center;">x<?php echo (int)$item['quantity']; ?></td>
            <td style="text-align:right;">₱<?php echo number_format((float)$item['item_price'], 2); ?></td>
            <td style="text-align:right;">₱<?php echo number_format((float)$item['item_price'] * (int)$item['quantity'], 2); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="total-row">
            <td colspan="3" style="text-align:right;">Grand Total</td>
            <td style="text-align:right;">₱<?php echo number_format((float)$order['total_amount'], 2); ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</main>

