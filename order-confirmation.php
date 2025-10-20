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
$stmt = $pdo->prepare("SELECT oi.*, p.name, p.image_url 
                      FROM order_items oi 
                      JOIN products p ON oi.product_id = p.id 
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
/* Order Confirmation Page Styles - Matching Website Theme */
body {
    background: #130325 !important;
    margin: 0;
    padding: 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

main {
    background: #130325;
    min-height: 100vh;
    padding: 120px 20px 60px 20px;
}

h1 {
    color: #FFD736;
    text-align: center;
    margin: 0 0 40px 0;
    font-size: 2.5rem;
    font-weight: 700;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
}

.order-confirmation {
    max-width: 1200px;
    margin: 0 auto;
    background: rgba(26, 10, 46, 0.95);
    border: 2px solid #FFD736;
    border-radius: 15px;
    box-shadow: 0 10px 40px rgba(255, 215, 54, 0.2);
    overflow: hidden;
}

.order-details, .customer-details, .order-items, .order-support {
    padding: 30px 40px;
    border-bottom: 1px solid rgba(255, 215, 54, 0.3);
    background: rgba(255, 255, 255, 0.05);
}


.order-details:last-child, .customer-details:last-child, .order-items:last-child, .order-support:last-child {
    border-bottom: none;
}

.order-details h3, .customer-details h3, .order-items h3, .order-support h3 {
    color: #FFD736;
    margin: 0 0 25px 0;
    font-size: 1.5rem;
    font-weight: 600;
    border-bottom: 2px solid #FFD736;
    padding-bottom: 10px;
}

.order-info-grid, .customer-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}

.info-item {
    background: rgba(255, 215, 54, 0.1);
    border: 1px solid rgba(255, 215, 54, 0.3);
    padding: 15px 20px;
    border-radius: 8px;
    border-left: 4px solid #FFD736;
}

.info-item strong {
    color: #FFD736;
    font-weight: 600;
    display: block;
    margin-bottom: 5px;
}

.info-item {
    color: #F9F9F9;
}

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
    background: rgba(26, 10, 46, 0.8);
    border: 1px solid #FFD736;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(255, 215, 54, 0.1);
}

.order-items-table th {
    background: #130325;
    color: #FFD736;
    padding: 15px;
    text-align: left;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #FFD736;
}

.order-items-table td {
    padding: 15px;
    border-bottom: 1px solid rgba(255, 215, 54, 0.2);
    vertical-align: middle;
    color: #F9F9F9;
}

.order-items-table tbody tr:hover {
    background: rgba(255, 215, 54, 0.1);
}

.product-info {
    display: flex;
    align-items: center;
    gap: 15px;
}

.product-info img {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border-radius: 8px;
    border: 2px solid #FFD736;
}

.product-info span {
    font-weight: 600;
    color: #F9F9F9;
}

.order-items-table tfoot {
    background: rgba(255, 215, 54, 0.1);
    font-weight: 600;
}

.order-items-table tfoot td {
    border-bottom: none;
    padding: 20px 15px;
    color: #FFD736;
}

.order-actions {
    padding: 30px 40px;
    background: rgba(255, 215, 54, 0.1);
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    justify-content: center;
}

.back-button, .print-button, .contact-button {
    padding: 12px 24px;
    border: 2px solid;
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 1rem;
}

.back-button {
    background: #130325;
    color: #FFD736;
    border-color: #FFD736;
}

.back-button:hover {
    background: #FFD736;
    color: #130325;
    transform: translateY(-2px);
}

.print-button {
    background: #130325;
    color: #0dcaf0;
    border-color: #0dcaf0;
}

.print-button:hover {
    background: #0dcaf0;
    color: #130325;
    transform: translateY(-2px);
}

.contact-button {
    background: #FFD736;
    color: #130325;
    border-color: #FFD736;
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
        padding: 100px 10px 40px 10px;
    }
    
    h1 {
        font-size: 2rem;
    }
    
    .order-confirmation {
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
    
    .order-confirmation {
        box-shadow: none;
        border: 1px solid #ddd;
    }
}
</style>

<h1>Order Details</h1>

<div class="order-confirmation">
    <div class="order-details">
        <h3>Order Information</h3>
        <div class="order-info-grid">
            <div class="info-item">
                <strong>Order Number:</strong> #<?php echo $order['id']; ?>
            </div>
            <div class="info-item">
                <strong>Order Date:</strong> <?php echo date('F j, Y, g:i a', strtotime($order['created_at'])); ?>
            </div>
            <div class="info-item">
                <strong>Order Status:</strong> <span class="status-badge status-<?php echo $order['status']; ?>"><?php echo ucfirst($order['status']); ?></span>
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

    <div class="customer-details">
        <h3>Customer Information</h3>
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

    <div class="order-items">
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
                                <img src="<?php echo $item['image_url']; ?>" alt="<?php echo $item['name']; ?>" width="50">
                                <span><?php echo $item['name']; ?></span>
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
        <a href="user-dashboard.php" class="back-button">Back to Dashboard</a>
        <button onclick="window.print()" class="print-button">Print Confirmation</button>
        
        <?php if ($order['status'] === 'pending' || $order['status'] === 'processing'): ?>
            <a href="https://www.facebook.com/kyaajhon1299" target="_blank" class="contact-button">Contact Support</a>
        <?php endif; ?>
    </div>

    <div class="order-support">
        <h3>Need Help?</h3>
        <p>If you have any questions about your order, please contact our customer support team.</p>
        <p><a href="https://www.facebook.com/kyaajhon1299" target="_blank" style="color: #FFD736; text-decoration: none; font-weight: 600;">📘 Contact us on Facebook</a> | Email: support@ecommerce.example.com</p>
    </div>
</div>

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
