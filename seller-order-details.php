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

// CRITICAL FIX: Check BOTH o.seller_id (new multi-seller orders) AND p.seller_id (legacy compatibility)
// Fetch order header scoped to this seller (exists if seller sold at least one item in this order)
$stmt = $pdo->prepare("SELECT o.id, o.total_amount, o.status, o.created_at,
       COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'Guest Customer') AS customer_name,
       u.email AS customer_email,
       u.phone AS customer_phone,
       o.shipping_address
FROM orders o
LEFT JOIN order_items oi ON o.id = oi.order_id
LEFT JOIN products p ON oi.product_id = p.id
LEFT JOIN users u ON o.user_id = u.id
WHERE o.id = ? AND (o.seller_id = ? OR p.seller_id = ?)
GROUP BY o.id");
$stmt->execute([$orderId, $sellerId, $sellerId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    // Not accessible for this seller
    header('Location: view-orders.php');
    exit();
}
// CRITICAL: Fetch items with original_price to check for item-level discounts
// CRITICAL FIX: Check both o.seller_id and p.seller_id for compatibility
$itemsStmt = $pdo->prepare("SELECT oi.product_id, oi.quantity, oi.price AS item_price, oi.original_price, p.name AS product_name, COALESCE(p.image_url, '') AS image_url
FROM order_items oi
JOIN products p ON oi.product_id = p.id
JOIN orders o ON oi.order_id = o.id
WHERE oi.order_id = ? AND (o.seller_id = ? OR p.seller_id = ?)
ORDER BY p.name");
$itemsStmt->execute([$orderId, $sellerId, $sellerId]);
$orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch discount information from payment_transactions (similar to order-success.php)
$hasDiscount = false;
$originalAmount = 0;
$discountAmount = 0;
$totalAmount = 0;

$transactionStmt = $pdo->prepare("
    SELECT pt.discount_amount, pt.total_amount, pt.final_amount
    FROM payment_transactions pt
    WHERE pt.id = (SELECT payment_transaction_id FROM orders WHERE id = ?)
    LIMIT 1
");
$transactionStmt->execute([$orderId]);
$transaction = $transactionStmt->fetch(PDO::FETCH_ASSOC);

if ($transaction && isset($transaction['discount_amount']) && $transaction['discount_amount'] > 0 && isset($transaction['final_amount'])) {
    $hasDiscount = true;
    $originalAmount = (float)$transaction['total_amount'];
    $discountAmount = (float)$transaction['discount_amount'];
    $totalAmount = (float)$transaction['final_amount'];
} else {
    $hasDiscount = false;
    $totalAmount = (float)($order['total_amount'] ?? 0);
}

require_once 'includes/seller_header.php';
?>

<style>
/* Import customer-side design system */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

/* CSS Variables - Match customer side exactly */
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
    --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
    --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.07);
    --shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.1);
    --radius-sm: 0.375rem;
    --radius-md: 0.5rem;
    --radius-lg: 0.75rem;
    --radius-xl: 1rem;
    --transition: all 0.2s ease;
}

/* Base styles */
* {
    box-sizing: border-box;
}

body {
    background: var(--bg-light) !important;
    margin: 0;
    padding: 0;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    color: var(--text-dark);
    line-height: 1.6;
}

/* Main layout - Wide and centered */
main {
    background: var(--bg-light);
    padding: 12px;
    min-height: calc(100vh - 60px);
    margin-top: 10px;
    margin-left: 240px;
    transition: margin-left 0.3s ease;
}

main.sidebar-collapsed {
    margin-left: 70px;
}

/* Container - Adjusted for sidebar */
.order-details-container {
    max-width: 1400px;
    margin: 0;
    margin-left: -220px;
    padding: 0 16px;
    transition: margin-left 0.3s ease;
}

main.sidebar-collapsed .order-details-container {
    margin-left: -150px;
}

/* Main card - Clean and modern */
.order-details-card {
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    overflow: hidden;
    margin-bottom: 16px;
}

/* Header - Compact with dark purple accent */
.order-header {
    padding: 16px 20px;
    background: linear-gradient(135deg, var(--bg-white) 0%, var(--bg-light) 100%);
    border-bottom: 2px solid var(--primary-dark);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    position: relative;
}

/* Back button - Icon only */
.back-btn {
    color: var(--bg-white);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 8px;
    border-radius: var(--radius-md);
    background: var(--primary-dark);
    border: 1px solid var(--primary-dark);
    transition: var(--transition);
    width: 36px;
    height: 36px;
}

.back-btn:hover {
    background: #0a0118;
    border-color: #0a0118;
    transform: translateX(-2px);
    box-shadow: 0 4px 8px rgba(19, 3, 37, 0.3);
}

.back-btn i {
    font-size: 14px;
}

/* Header right section */
.header-right {
    display: flex;
    align-items: center;
    gap: 16px;
}

/* Order info header (inside container) */
.order-info-header {
    padding: 16px 20px 8px 20px;
    background: var(--bg-white);
}

/* Title and status */
.order-title {
    color: var(--text-dark);
    font-size: 1.25rem;
    font-weight: 700;
    margin: 0;
}

.order-status {
    display: inline-flex;
    align-items: center;
    padding: 6px 12px;
    border-radius: var(--radius-full);
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-pending { background: rgba(255,193,7,0.15); color: #f59e0b; }
.status-processing { background: rgba(59,130,246,0.15); color: #3b82f6; }
.status-shipped { background: rgba(23,162,184,0.15); color: #17a2b8; }
.status-delivered { background: rgba(34,197,94,0.15); color: #22c55e; }
.status-completed { background: rgba(34,197,94,0.15); color: #22c55e; }
.status-cancelled { background: rgba(239,68,68,0.15); color: #ef4444; }

/* Content sections */
.order-content {
    padding: 20px;
}

.order-info-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.info-card {
    background: var(--bg-light);
    border: 1px solid var(--border-light);
    border-radius: var(--radius-md);
    padding: 16px;
}

.info-label {
    color: var(--text-light);
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
    display: block;
}

.info-value {
    color: var(--text-dark);
    font-size: 14px;
    font-weight: 600;
    word-break: break-word;
    line-height: 1.4;
}

/* Products table */
.products-table {
    width: 100%;
    border-collapse: collapse;
    border-radius: var(--radius-md);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

.products-table thead {
    background: linear-gradient(135deg, var(--primary-dark), #0a0118);
}

.products-table th {
    padding: 12px 16px;
    text-align: left;
    color: white;
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.products-table tbody tr {
    border-bottom: 1px solid var(--border-light);
    transition: background-color 0.15s ease;
}

.products-table tbody tr:hover {
    background: rgba(19, 3, 37, 0.02);
}

.products-table td {
    padding: 12px 16px;
    vertical-align: middle;
}

/* Product image */
.product-image {
    width: 48px;
    height: 48px;
    object-fit: cover;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-light);
}

/* Product info */
.product-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.product-name {
    font-weight: 600;
    color: var(--text-dark);
    font-size: 14px;
}

.product-meta {
    color: var(--text-light);
    font-size: 12px;
}

/* Price display */
.price-original {
    text-decoration: line-through;
    color: var(--text-light);
    font-size: 13px;
}

.price-current {
    color: var(--success-green);
    font-weight: 700;
    font-size: 14px;
}

.price-regular {
    color: var(--text-dark);
    font-weight: 600;
    font-size: 14px;
}

/* Totals section */
.totals-section {
    margin-top: 20px;
    padding: 16px;
    background: var(--bg-light);
    border-radius: var(--radius-md);
    border: 1px solid var(--border-light);
}

.totals-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
}

.totals-row.subtotal {
    border-bottom: 1px solid var(--border-light);
    padding-bottom: 12px;
    margin-bottom: 8px;
}

.totals-row.discount {
    color: var(--success-green);
    font-weight: 600;
}

.totals-row.total {
    border-top: 2px solid var(--primary-dark);
    padding-top: 12px;
    margin-top: 8px;
    font-size: 16px;
    font-weight: 800;
    color: var(--success-green);
}

.totals-label {
    color: var(--text-dark);
    font-weight: 600;
}

.totals-value {
    font-weight: 700;
}

/* Discount indicator */
.discount-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: rgba(16, 185, 129, 0.1);
    color: var(--success-green);
    padding: 2px 6px;
    border-radius: var(--radius-sm);
    font-size: 11px;
    font-weight: 600;
    margin-top: 4px;
}

/* Responsive design */
@media (max-width: 1024px) {
    main {
        margin-left: 70px;
        padding: 6px;
        margin-top: 6px;
    }

    .order-details-container {
        margin-left: -150px;
        padding: 0 12px;
        max-width: 1200px;
    }

    .order-header {
        padding: 12px 16px;
    }

    .order-info-header {
        padding: 12px 16px 6px 16px;
    }

    .order-title {
        font-size: 1.1rem;
    }
}

@media (max-width: 768px) {
    main {
        margin-left: 0 !important;
        padding: 6px 3px;
        margin-top: 10px;
    }

    .order-details-container {
        margin-left: 0;
        padding: 0 12px;
        max-width: 100%;
    }

    .order-header {
        padding: 10px 12px;
    }

    .order-info-header {
        padding: 10px 12px 6px 12px;
    }

    .order-title {
        font-size: 1rem;
    }

    .order-content {
        padding: 14px;
    }

    .order-info-section {
        grid-template-columns: 1fr;
        gap: 10px;
    }

    .products-table th,
    .products-table td {
        padding: 6px 8px;
        font-size: 12px;
    }

    .product-image {
        width: 36px;
        height: 36px;
    }

    .product-name {
        font-size: 12px;
    }

    .product-meta {
        font-size: 10px;
    }

    .totals-section {
        padding: 10px;
    }

    .totals-row.total {
        font-size: 14px;
    }
}

@media (max-width: 480px) {
    main {
        margin-left: 0 !important;
        padding: 4px 2px;
        margin-top: 8px;
    }

    .order-details-container {
        margin-left: 0;
        padding: 0 8px;
        max-width: 100%;
    }

    .order-header {
        padding: 8px 10px;
    }

    .order-info-header {
        padding: 8px 10px 4px 10px;
    }

    .order-title {
        font-size: 0.95rem;
    }

    .order-status {
        padding: 3px 6px;
        font-size: 10px;
    }

    .order-content {
        padding: 10px;
    }

    .info-card {
        padding: 10px;
    }

    .products-table {
        font-size: 11px;
    }

    .products-table th,
    .products-table td {
        padding: 4px 6px;
    }

    .product-image {
        width: 32px;
        height: 32px;
    }

    .product-name {
        font-size: 11px;
    }

    .totals-row {
        padding: 4px 0;
    }

    .totals-row.total {
        font-size: 13px;
    }
}

@media (max-width: 360px) {
    main {
        margin-left: 0 !important;
        padding: 2px 1px;
        margin-top: 6px;
    }

    .order-details-container {
        margin-left: 0;
        padding: 0 6px;
        max-width: 100%;
    }

    .order-header {
        padding: 6px 8px;
    }

    .order-info-header {
        padding: 6px 8px 4px 8px;
    }

    .order-content {
        padding: 8px;
    }

    .products-table th,
    .products-table td {
        padding: 3px 4px;
    }

    .info-card {
        padding: 8px;
    }

    .back-btn {
        width: 32px;
        height: 32px;
        padding: 6px;
    }

    .back-btn i {
        font-size: 12px;
    }

    .order-title {
        font-size: 0.9rem;
    }
}
</style>

<main>
  <div class="order-details-container">
    <div class="order-details-card">
      <!-- Header Section -->
      <div class="order-header">
        <a href="view-orders.php" class="back-btn" title="Back to Orders">
          <i class="fas fa-arrow-left"></i>
        </a>
        <div class="header-right">
          <span class="order-status <?php echo 'status-' . htmlspecialchars(strtolower($order['status'])); ?>">
            <?php echo strtoupper(htmlspecialchars($order['status'])); ?>
          </span>
        </div>
      </div>

      <!-- Order Info Section (inside container) -->
      <div class="order-info-header">
        <div style="display: flex; align-items: center; gap: 8px;">
          <h1 class="order-title">Order #<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?></h1>
          <span style="color: var(--text-light); font-size: 14px; font-weight: 500;">
            <?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?>
          </span>
        </div>
      </div>

      <!-- Content Section -->
      <div class="order-content">
        <!-- Customer Information -->
        <div class="order-info-section">
          <div class="info-card">
            <span class="info-label">Customer Name</span>
            <span class="info-value"><?php echo htmlspecialchars($order['customer_name']); ?></span>
          </div>
          <div class="info-card">
            <span class="info-label">Email</span>
            <span class="info-value">
              <a href="mailto:<?php echo htmlspecialchars($order['customer_email']); ?>" style="color: var(--primary-dark); text-decoration: none;">
                <?php echo htmlspecialchars($order['customer_email'] ?? 'No email provided'); ?>
              </a>
            </span>
          </div>
          <div class="info-card">
            <span class="info-label">Phone</span>
            <span class="info-value"><?php echo htmlspecialchars($order['customer_phone'] ?? 'No phone provided'); ?></span>
          </div>
          <div class="info-card" style="grid-column: span 2;">
            <span class="info-label">Shipping Address</span>
            <span class="info-value"><?php echo nl2br(htmlspecialchars($order['shipping_address'] ?? 'No address provided')); ?></span>
          </div>
        </div>

        <!-- Products Table -->
        <table class="products-table">
          <thead>
            <tr>
              <th style="width: 80px;">Image</th>
              <th>Product Details</th>
              <th style="width: 80px; text-align: center;">Qty</th>
              <th style="width: 120px; text-align: right;">Price</th>
              <th style="width: 120px; text-align: right;">Total</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orderItems as $item):
              // Check if item has original_price (discount applied)
              $hasItemDiscount = !empty($item['original_price']) && $item['original_price'] != $item['item_price'];
              $originalPrice = $hasItemDiscount ? (float)$item['original_price'] : (float)$item['item_price'];
              $finalPrice = (float)$item['item_price'];
            ?>
            <tr>
              <td>
                <?php if (!empty($item['image_url'])): ?>
                  <img src="<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['product_name']); ?>" class="product-image" />
                <?php else: ?>
                  <div style="width: 48px; height: 48px; border-radius: var(--radius-sm); background: var(--bg-light); border: 1px solid var(--border-light); display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-image" style="color: var(--text-light); font-size: 16px;"></i>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <div class="product-info">
                  <div class="product-name"><?php echo htmlspecialchars($item['product_name']); ?></div>
                  <?php if ($hasItemDiscount): ?>
                    <div class="discount-badge">
                      <i class="fas fa-tag"></i>
                      Discount Applied
                    </div>
                  <?php endif; ?>
                </div>
              </td>
              <td style="text-align: center; font-weight: 600;">
                <?php echo (int)$item['quantity']; ?>
              </td>
              <td style="text-align: right;">
                <?php if ($hasItemDiscount): ?>
                  <div class="price-original">₱<?php echo number_format($originalPrice, 2); ?></div>
                  <div class="price-current">₱<?php echo number_format($finalPrice, 2); ?></div>
                <?php else: ?>
                  <div class="price-regular">₱<?php echo number_format($finalPrice, 2); ?></div>
                <?php endif; ?>
              </td>
              <td style="text-align: right;">
                <?php if ($hasItemDiscount): ?>
                  <div class="price-original">₱<?php echo number_format($originalPrice * (int)$item['quantity'], 2); ?></div>
                  <div class="price-current">₱<?php echo number_format($finalPrice * (int)$item['quantity'], 2); ?></div>
                <?php else: ?>
                  <div class="price-regular">₱<?php echo number_format($finalPrice * (int)$item['quantity'], 2); ?></div>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <!-- Totals Section -->
        <div class="totals-section">
          <?php
            // Calculate seller's subtotal and check for item-level discounts
            $sellerOriginalSubtotal = 0;
            $sellerFinalSubtotal = 0;

            foreach ($orderItems as $item) {
                // Check if item has original_price (indicates discount was applied)
                $itemOriginalPrice = !empty($item['original_price']) && $item['original_price'] != $item['item_price']
                    ? $item['original_price']
                    : $item['item_price'];

                $sellerOriginalSubtotal += $itemOriginalPrice * (int)$item['quantity'];
                $sellerFinalSubtotal += (float)$item['item_price'] * (int)$item['quantity'];
            }

            // Calculate actual discount from item price differences
            $sellerDiscountAmount = $sellerOriginalSubtotal - $sellerFinalSubtotal;
            $hasSellerDiscount = $sellerDiscountAmount > 0;
          ?>

          <div class="totals-row subtotal">
            <span class="totals-label">Subtotal</span>
            <span class="totals-value">₱<?php echo number_format($sellerOriginalSubtotal, 2); ?></span>
          </div>

          <?php if ($hasSellerDiscount): ?>
          <div class="totals-row discount">
            <span class="totals-label">
              <i class="fas fa-tag" style="margin-right: 6px;"></i>
              Discount Applied
            </span>
            <span class="totals-value">-₱<?php echo number_format($sellerDiscountAmount, 2); ?></span>
          </div>
          <?php endif; ?>

          <div class="totals-row total">
            <span class="totals-label">Seller Total</span>
            <span class="totals-value">₱<?php echo number_format($sellerFinalSubtotal, 2); ?></span>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<!-- Logout Confirmation Modal - Matching Design -->
<style>
/* Custom Confirmation Modal - Matching Logout Modal */
.custom-confirm-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    opacity: 0;
    visibility: hidden;
    transition: all 0.25s ease;
}

.custom-confirm-overlay.show {
    opacity: 1;
    visibility: visible;
}

.custom-confirm-dialog {
    background: #ffffff;
    border-radius: 12px;
    padding: 0;
    max-width: 400px;
    width: 90%;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    animation: slideDown 0.3s ease;
}

.custom-confirm-header {
    background: #130325;
    color: #ffffff;
    padding: 16px 20px;
    border-radius: 12px 12px 0 0;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    font-size: 16px;
}

.custom-confirm-body {
    padding: 20px;
    color: #130325;
    font-size: 14px;
    line-height: 1.5;
}

.custom-confirm-footer {
    padding: 16px 24px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.custom-confirm-btn {
    padding: 8px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.2s ease;
}

.custom-confirm-btn.cancel {
    background: #f3f4f6;
    color: #130325;
    border: 1px solid #e5e7eb;
}

.custom-confirm-btn.cancel:hover {
    background: #e5e7eb;
}

.custom-confirm-btn.confirm {
    background: #130325;
    color: #ffffff;
}

.custom-confirm-btn.confirm:hover {
    background: #0a0218;
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
</style>

<script>
// Custom confirmation modal functionality
function showConfirmModal(title, message, confirmText = 'Confirm', cancelText = 'Cancel') {
    return new Promise((resolve) => {
        // Remove any existing modal
        const existingModal = document.querySelector('.custom-confirm-overlay');
        if (existingModal) {
            existingModal.remove();
        }

        // Create modal
        const overlay = document.createElement('div');
        overlay.className = 'custom-confirm-overlay';
        overlay.innerHTML = `
            <div class="custom-confirm-dialog">
                <div class="custom-confirm-header">
                    <i class="fas fa-exclamation-triangle"></i>
                    ${title}
                </div>
                <div class="custom-confirm-body">
                    <p style="margin: 0; font-size: 14px; line-height: 1.5; color: #130325;">${message}</p>
                </div>
                <div class="custom-confirm-footer">
                    <button type="button" class="custom-confirm-btn cancel">${cancelText}</button>
                    <button type="button" class="custom-confirm-btn confirm">${confirmText}</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);

        // Show modal with animation
        requestAnimationFrame(() => overlay.classList.add('show'));

        // Event handlers
        overlay.querySelector('.cancel').addEventListener('click', () => {
            overlay.classList.remove('show');
            setTimeout(() => overlay.remove(), 250);
            resolve(false);
        });

        overlay.querySelector('.confirm').addEventListener('click', () => {
            overlay.classList.remove('show');
            setTimeout(() => overlay.remove(), 250);
            resolve(true);
        });

        // Click outside to close
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                overlay.classList.remove('show');
                setTimeout(() => overlay.remove(), 250);
                resolve(false);
            }
        });
    });
}

// Make functions globally available
window.showConfirmModal = showConfirmModal;
</script>
