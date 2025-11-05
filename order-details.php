<?php
// Move all validation and redirects BEFORE any includes
session_start();
require_once 'config/database.php';

// Check if user is logged in
function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit();
    }
}

requireLogin();

// Validate order ID parameter
if (!isset($_GET['id'])) {
    header("Location: user-dashboard.php");
    exit();
}

$orderId = intval($_GET['id']);
$userId = $_SESSION['user_id'];

// Get order details
try {
    $stmt = $pdo->prepare("SELECT o.*, u.username, u.email, u.first_name, u.last_name, u.address, u.phone 
                          FROM orders o 
                          JOIN users u ON o.user_id = u.id 
                          WHERE o.id = ? AND o.user_id = ?");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        header("Location: user-dashboard.php?error=order_not_found");
        exit();
    }

    // Get order items
    $stmt = $pdo->prepare("SELECT oi.*, p.name, p.image_url 
                          FROM order_items oi 
                          JOIN products p ON oi.product_id = p.id 
                          WHERE oi.order_id = ?");
    $stmt->execute([$orderId]);
    $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log('Order confirmation error: ' . $e->getMessage());
    header("Location: user-dashboard.php?error=database_error");
    exit();
}

// Now include header after all redirects are done
require_once 'includes/header.php';

// Get order items
$stmt = $pdo->prepare("SELECT oi.*, p.name, p.image_url, 
                              COALESCE(u.display_name, CONCAT(u.first_name, ' ', u.last_name), u.username) AS seller_name
                      FROM order_items oi 
                      JOIN products p ON oi.product_id = p.id 
                      JOIN users u ON p.seller_id = u.id
                      WHERE oi.order_id = ?");
$stmt = $pdo->prepare("SELECT oi.*, p.name, p.image_url,
                              COALESCE(u.display_name, CONCAT(u.first_name, ' ', u.last_name), u.username) AS seller_name
                      FROM order_items oi 
                      JOIN products p ON oi.product_id = p.id 
                      JOIN users u ON p.seller_id = u.id
                      WHERE oi.order_id = ?");
$stmt->execute([$orderId]);
$orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate order total from items (for verification)
$calculatedTotal = 0;
foreach ($orderItems as $item) {
    $calculatedTotal += $item['price'] * $item['quantity'];
}
?>

<style>
/* Order Confirmation Page Styles - Clean and Modern */
body {
    background: #f8f9fa !important;
    margin: 0;
    padding: 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

main {
    background: #f8f9fa;
    min-height: 100vh;
    padding: 30px 0 40px 0;
}

.page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin: 0 60px 20px 60px;
}

.header-left {
    display: flex;
    align-items: center;
    gap: 15px;
}

.header-right {
    display: flex;
    align-items: center;
}

.back-arrow {
    color: #ffffff;
    text-decoration: none;
    font-size: 1.8rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
}

.back-arrow:hover {
    color: #ffffff;
}

h1 {
    color: #130325;
    margin: 8px 0 0 0;
    font-size: 1.5rem;
    font-weight: 300;
    text-shadow: none;
}

/* Ensure header text weight applies */
.page-header h1 { font-weight: 700 !important; }

.order-details {
    max-width: 1200px;
    margin: 0 auto;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.08);
    overflow: hidden;
}

.order-card {
    padding: 22px;
    border-bottom: 1px solid #f1f5f9;
    background: #ffffff;
}

.order-card:last-child {
    border-bottom: none;
}

.order-card h3 {
    color: #130325;
    margin: 0 0 16px 0;
    font-size: 1.1rem;
    font-weight: 800;
    border-bottom: 2px solid #FFD736;
    padding-bottom: 10px;
    text-transform: none;
    letter-spacing: 0;
}

.status-card {
    background: #130325;
    padding: 18px 22px;
    border-bottom: 1px solid rgba(0,0,0,0.1);
}

.status-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 15px;
}

.status-title {
    color: #FFD736;
    font-size: 1.3rem;
    font-weight: 700;
    margin: 0;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.order-info-grid, .customer-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
}

.info-item {
    background: #f8f9fa;
    border: 1px solid rgba(0,0,0,0.1);
    padding: 12px 15px;
    border-radius: 6px;
}

.info-item strong {
    color: #130325;
    font-weight: 700;
    display: block;
    margin-bottom: 6px;
    font-size: 1rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.info-item {
    color: #130325;
    font-size: 1rem;
    font-weight: 500;
    word-break: break-word;
    overflow-wrap: anywhere;
}

/* Improve address wrapping */
.customer-info-grid .info-item { white-space: normal; }

.status-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-pending {
    background: rgba(255, 193, 7, 0.2);
    color: #FFD736;
    border: 1px solid #FFD736;
}

.status-processing {
    background: rgba(13, 202, 240, 0.2);
    color: #0dcaf0;
    border: 1px solid #0dcaf0;
}

.status-shipped {
    background: rgba(40, 167, 69, 0.2);
    color: #28a745;
    border: 1px solid #28a745;
}

.status-delivered {
    background: rgba(40, 167, 69, 0.2);
    color: #28a745;
    border: 1px solid #28a745;
}

.status-cancelled {
    background: rgba(220, 53, 69, 0.2);
    color: #dc3545;
    border: 1px solid #dc3545;
}

.order-items-table {
    width: 100%;
    border-collapse: collapse;
    background: #ffffff;
    border: 1px solid rgba(0,0,0,0.1);
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.order-items-table th {
    background: #f8fafc;
    color: #130325;
    padding: 14px 16px;
    text-align: left;
    font-weight: 700;
    text-transform: none;
    letter-spacing: 0;
    border-bottom: 1px solid #eef2f7;
}

.order-items-table td {
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    color: #130325;
    font-size: 0.95rem;
}

.order-items-table tbody tr:hover {
    background: rgba(0,0,0,0.05);
}

.product-info {
    display: flex;
    align-items: center;
    gap: 15px;
}

.product-info img {
    width: 64px;
    height: 64px;
    object-fit: cover;
    border-radius: 10px;
    border: 2px solid #FFD736;
}

.product-info span {
    font-weight: 600;
    color: #130325;
}

.order-items-table tfoot {
    background: #f9fafb;
    font-weight: 700;
}

.order-items-table tfoot td {
    border-bottom: none;
    padding: 20px 15px;
    color: #130325;
}

.order-actions {
    padding: 18px 22px;
    background: #ffffff;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: flex-end;
    border-top: 1px solid #f1f5f9;
}

.action-btn {
    padding: 10px 16px;
    border: none;
    border-radius: 8px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 0.9rem;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-cancel {
    background: #dc3545;
    color: #ffffff;
}

.btn-cancel:hover {
    background: #c82333;
    transform: translateY(-1px);
}

.btn-buy-again {
    background: #130325;
    color: #ffffff;
}

.btn-buy-again:hover {
    background: #0f0220;
    transform: translateY(-1px);
}

.btn-rate {
    background: #FFD736;
    color: #130325;
}

.btn-rate:hover {
    background: #e6c230;
    transform: translateY(-1px);
}

.btn-invoice {
    background: #6c757d;
    color: white;
}

.btn-invoice:hover {
    background: #5a6268;
    transform: translateY(-1px);
}

.btn-return {
    background: #495057;
    color: white;
}

.btn-return:hover {
    background: #343a40;
    transform: translateY(-1px);
}

.btn-return-disabled {
    background: #6c757d;
    color: #ffffff;
    opacity: 0.6;
    cursor: not-allowed;
}

.btn-return-disabled:hover {
    background: #6c757d;
    transform: none;
    cursor: not-allowed;
}

/* Return Expired Popup Styles */
.return-expired-popup {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 10000;
    backdrop-filter: blur(5px);
}

.return-expired-content {
    background: #ffffff;
    padding: 40px;
    border-radius: 15px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    max-width: 500px;
    width: 90%;
    text-align: center;
    border: 3px solid #dc3545;
    animation: popupSlideIn 0.3s ease-out;
}

@keyframes popupSlideIn {
    from {
        opacity: 0;
        transform: scale(0.8) translateY(-50px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}

.return-expired-icon {
    font-size: 4rem;
    color: #dc3545;
    margin-bottom: 20px;
    animation: shake 0.5s ease-in-out;
}

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-5px); }
    75% { transform: translateX(5px); }
}

.return-expired-title {
    color: #dc3545;
    font-size: 1.8rem;
    font-weight: 700;
    margin-bottom: 15px;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.return-expired-message {
    color: #130325;
    font-size: 1.1rem;
    line-height: 1.6;
    margin-bottom: 25px;
}

.return-expired-button {
    background: #dc3545;
    color: white;
    border: none;
    padding: 12px 30px;
    border-radius: 8px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.return-expired-button:hover {
    background: #c82333;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(220, 53, 69, 0.4);
}

.contact-button:hover {
    background: #130325;
    color: #FFD736;
    transform: translateY(-2px);
}

.order-support {
    background: rgba(255, 215, 54, 0.1);
    text-align: center;
    border-top: 2px solid #FFD736;
}

.order-support h3 {
    color: #FFD736;
    border-bottom-color: #FFD736;
    font-size: 1.1rem;
}

.order-support p {
    margin: 10px 0;
    color: #F9F9F9;
    line-height: 1.6;
    font-size: 1.1rem;
}

/* Responsive Design */
@media (max-width: 768px) {
    main {
        padding: 70px 10px 30px 10px;
    }
    
    h1 {
        font-size: 2rem;
        font-weight: 300 !important;
    }
    
    .order-details {
        margin: 0 10px;
    }
    
    .order-details, .customer-details, .order-items, .order-support {
        padding: 20px;
    }
    
    .order-info-grid, .customer-info-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .order-actions {
        flex-direction: column;
        align-items: center;
    }
    
    .back-button, .print-button, .contact-button {
        width: 100%;
        max-width: 300px;
    }
    
    .order-items-table {
        font-size: 0.9rem;
    }
    
    .order-items-table th,
    .order-items-table td {
        padding: 10px 8px;
    }
    
    .product-info {
        flex-direction: column;
        text-align: center;
        gap: 10px;
    }
    
    .product-info img {
        width: 50px;
        height: 50px;
    }
}

/* Print Styles */
@media print {
    body {
        background: white !important;
    }
    
    main {
        padding: 0;
    }
    
    .order-actions {
        display: none;
    }
    
    .order-details {
        box-shadow: none;
        border: 1px solid #ddd;
    }
}
</style>

<!-- Header removed; back button moved into status header -->

<div class="order-details">
    <!-- Status Card -->
    <div class="status-card">
        <div class="status-header">
            <div style="display:flex; align-items:center; gap:12px;">
                <a href="user-dashboard.php" class="back-arrow"><i class="fas fa-arrow-left"></i></a>
                <h3 class="status-title">Order Status</h3>
            </div>
            <span class="status-badge status-<?php echo $order['status']; ?>"><?php echo ucfirst($order['status']); ?></span>
        </div>
    </div>

    <!-- Order Details Card -->
    <div class="order-card">
        <h3>Order Information</h3>
        <div class="order-info-grid">
            <div class="info-item">
                <strong>Order Number:</strong> #<?php echo $order['id']; ?>
            </div>
            <div class="info-item">
                <strong>Order Date:</strong> <?php echo date('F j, Y, g:i a', strtotime($order['created_at'])); ?>
            </div>
            <div class="info-item">
                <strong>Payment Method:</strong> <?php echo ucfirst(str_replace('_', ' ', $order['payment_method'])); ?>
            </div>
            <div class="info-item">
                <strong>Payment Status:</strong> <?php echo ucfirst($order['payment_status']); ?>
            </div>
            <div class="info-item">
                <strong>Total Amount:</strong> ₱<?php echo number_format($order['total_amount'], 2); ?>
            </div>
        </div>
    </div>

    <!-- Customer Details Card -->
    <div class="order-card">
        <h3>Delivery Information</h3>
        <div class="customer-info-grid">
            <div class="info-item">
                <strong>Name:</strong> <?php echo $order['first_name'] . ' ' . $order['last_name']; ?>
            </div>
            <div class="info-item">
                <strong>Email:</strong> <?php echo $order['email']; ?>
            </div>
            <div class="info-item">
                <strong>Phone:</strong> <?php echo $order['phone'] ? $order['phone'] : 'N/A'; ?>
            </div>
            <div class="info-item">
                <strong>Shipping Address:</strong> <?php echo $order['shipping_address']; ?>
            </div>
        </div>
    </div>

    <!-- Order Items Card -->
    <div class="order-card">
        <h3>Order Items</h3>
        <table class="order-items-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Price</th>
                    <th>Quantity</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orderItems as $item): ?>
                    <tr>
                        <td>
                            <div class="product-info">
                                <img src="<?php echo $item['image_url']; ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" width="50">
                                <div>
                                    <div style="font-weight:700; color:#130325;"><?php echo htmlspecialchars($item['name']); ?></div>
                                    <div class="product-seller" style="color:#6b7280; font-size:12px; margin-top:2px;">Seller: <?php echo htmlspecialchars($item['seller_name'] ?? 'Unknown'); ?></div>
                                </div>
                            </div>
                        </td>
                        <td>₱<?php echo number_format($item['price'], 2); ?></td>
                        <td><?php echo $item['quantity']; ?></td>
                        <td>₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" align="right"><strong>Subtotal:</strong></td>
                    <td>₱<?php echo number_format($calculatedTotal, 2); ?></td>
                </tr>
                <tr>
                    <td colspan="3" align="right"><strong>Total:</strong></td>
                    <td>₱<?php echo number_format($order['total_amount'], 2); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="order-actions">
        <button onclick="downloadInvoice()" class="action-btn btn-invoice">
            <i class="fas fa-download"></i> Download Invoice
        </button>
        
        <?php if ($order['status'] === 'pending'): ?>
            <button onclick="cancelOrder(<?php echo $order['id']; ?>)" class="action-btn btn-cancel">
                <i class="fas fa-times"></i> Cancel Order
            </button>
        <?php elseif ($order['status'] === 'delivered'): ?>
            <button onclick="buyAgain(<?php echo $order['id']; ?>)" class="action-btn btn-buy-again">
                <i class="fas fa-shopping-cart"></i> Buy Again
            </button>
            <?php 
            // Check if 1 week has passed since delivery
            $deliveryDate = new DateTime($order['delivery_date'] ?? $order['created_at']);
            $currentDate = new DateTime();
            $daysSinceDelivery = $currentDate->diff($deliveryDate)->days;
            $canReturn = $daysSinceDelivery <= 7;
            ?>
            <?php if ($canReturn): ?>
                <a href="customer-returns.php?order_id=<?php echo $order['id']; ?>" class="action-btn btn-return">
                    <i class="fas fa-undo"></i> Return/Refund
                </a>
            <?php else: ?>
                <button onclick="showReturnExpiredPopup()" class="action-btn btn-return-disabled" disabled>
                    <i class="fas fa-undo"></i> Return/Refund
                </button>
            <?php endif; ?>
            <button onclick="rateOrder(<?php echo $order['id']; ?>)" class="action-btn btn-rate">
                <i class="fas fa-star"></i> Rate Order
            </button>
        <?php endif; ?>
    </div>

</div>

<script>
function downloadInvoice() {
    // Create a simple invoice download
    const orderData = {
        orderNumber: '<?php echo $order['id']; ?>',
        date: '<?php echo date('F j, Y, g:i a', strtotime($order['created_at'])); ?>',
        total: '<?php echo number_format($order['total_amount'], 2); ?>',
        status: '<?php echo $order['status']; ?>'
    };
    
    // Simple invoice generation (you can enhance this)
    const invoiceContent = `
        INVOICE
        Order #${orderData.orderNumber}
        Date: ${orderData.date}
        Status: ${orderData.status}
        Total: ₱${orderData.total}
    `;
    
    const blob = new Blob([invoiceContent], { type: 'text/plain' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `invoice-${orderData.orderNumber}.txt`;
    a.click();
    window.URL.revokeObjectURL(url);
}

function cancelOrder(orderId) {
    if (confirm('Are you sure you want to cancel this order?')) {
        // Redirect to cancel order page or handle via AJAX
        window.location.href = `cancel-order.php?id=${orderId}`;
    }
}

function buyAgain(orderId) {
    // Redirect to reorder functionality
    window.location.href = `reorder.php?id=${orderId}`;
}

function rateOrder(orderId) {
    // Redirect to rating page
    window.location.href = `rate-order.php?id=${orderId}`;
}

function returnRefund(orderId) {
    if (confirm('Are you sure you want to request a return/refund for this order?')) {
        // Redirect to return/refund page
        window.location.href = `return-refund.php?id=${orderId}`;
    }
}

function showReturnExpiredPopup() {
    // Create popup HTML
    const popupHTML = `
        <div class="return-expired-popup" id="returnExpiredPopup">
            <div class="return-expired-content">
                <div class="return-expired-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h2 class="return-expired-title">Return Period Expired</h2>
                <p class="return-expired-message">
                    Sorry, the return/refund period for this order has expired. 
                    Returns and refunds are only allowed within 7 days of delivery.
                </p>
                <button class="return-expired-button" onclick="closeReturnExpiredPopup()">
                    I Understand
                </button>
            </div>
        </div>
    `;
    
    // Add popup to body
    document.body.insertAdjacentHTML('beforeend', popupHTML);
    
    // Prevent body scroll
    document.body.style.overflow = 'hidden';
    
    // Close popup when clicking outside
    document.getElementById('returnExpiredPopup').addEventListener('click', function(e) {
        if (e.target === this) {
            closeReturnExpiredPopup();
        }
    });
    
    // Close popup with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeReturnExpiredPopup();
        }
    });
}

function closeReturnExpiredPopup() {
    const popup = document.getElementById('returnExpiredPopup');
    if (popup) {
        popup.remove();
        document.body.style.overflow = '';
    }
}
</script>

<?php
// Send confirmation email if this is the first time viewing the confirmation
if (!isset($_SESSION['viewed_order_' . $orderId])) {
    $_SESSION['viewed_order_' . $orderId] = true;
    sendOrderConfirmationEmail($order, $orderItems);
}

require_once 'includes/footer.php';

/**
 * Send order confirmation email
 */
function sendOrderConfirmationEmail($order, $orderItems) {
    // Email content would be implemented here
    // This is a placeholder function
    $to = $order['email'];
    $subject = "Order Confirmation #" . $order['id'];
    $message = "Thank you for your order!\n\n";
    $message .= "Order Details:\n";
    $message .= "Order ID: #" . $order['id'] . "\n";
    $message .= "Order Date: " . date('F j, Y', strtotime($order['created_at'])) . "\n";
    $message .= "Total Amount: ₱" . number_format($order['total_amount'], 2) . "\n\n";
    
    $message .= "Items:\n";
    foreach ($orderItems as $item) {
        $message .= $item['name'] . " x " . $item['quantity'] . " - ₱" . number_format($item['price'] * $item['quantity'], 2) . "\n";
    }
    
    $message .= "\nShipping Address:\n" . $order['shipping_address'] . "\n\n";
    $message .= "Thank you for shopping with us!\n";
    
    $headers = "From: no-reply@ecommerce.example.com\r\n";
    $headers .= "Reply-To: support@ecommerce.example.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    // In a real implementation, you would use mail() or a library like PHPMailer
    // mail($to, $subject, $message, $headers);
}
?>
