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

// Get cancellation reason if order is cancelled





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

$cancellationReason = null;

if (strtolower($order['status']) === 'cancelled' && !empty($order['cancellation_reason'])) {

    $cancellationReason = $order['cancellation_reason'];

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



body {

    background: var(--bg-light) !important;

    margin: 0;

    padding: 0;

    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;

}



main {

    background: var(--bg-light);

    padding: 10px 0;

    min-height: calc(100vh - 60px);

}



.order-details-container {

    max-width: 1600px;

    margin: 0 auto;

    padding: 0 20px;

}



/* Ensure content fits in viewport */

.order-details {

    background: var(--bg-white);

    border: 1px solid var(--border-light);

    border-radius: 12px;

    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08), 0 0 0 1px rgba(255, 215, 54, 0.1);

    overflow: hidden;

    margin-bottom: 12px;

    display: flex;

    flex-direction: column;

    position: relative;

}





/* Compact Header with Dark Purple Accents */

.order-header {

    padding: 12px 16px;

    background: linear-gradient(135deg, var(--bg-white) 0%, #f8f7fa 100%);

    border-bottom: 2px solid var(--primary-dark);

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 12px;

    flex-wrap: wrap;

    flex-shrink: 0;

    box-shadow: 0 2px 8px rgba(19, 3, 37, 0.1);

    position: relative;

}





.order-header-left {

    display: flex;

    align-items: center;

    gap: 12px;

}



.back-arrow {

    color: var(--bg-white);

    text-decoration: none;

    font-size: 16px;

    font-weight: 600;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    transition: all 0.2s ease;

    padding: 6px 8px;

    border-radius: 8px;

    width: 32px;

    height: 32px;

    background: var(--primary-dark);

    border: 1px solid var(--primary-dark);

}



.back-arrow:hover {

    color: var(--bg-white);

    background: #0a0118;

    border-color: #0a0118;

    transform: translateX(-2px);

    box-shadow: 0 2px 6px rgba(19, 3, 37, 0.3);

}



.back-arrow i {

    margin: 0;

}



.order-header-title {

    color: var(--text-dark);

    font-size: 1.35rem;

    font-weight: 600;

    margin: 0;

    letter-spacing: -0.3px;

    display: flex;

    align-items: center;

    gap: 8px;

}



.order-header-right {

    display: flex;

    align-items: center;

    gap: 12px;

}



/* Status Badge - Matching user-dashboard.php but minimized */

.order-status {

    display: inline-block;

    padding: 4px 10px;

    border-radius: 999px;

    font-size: 0.7rem;

    font-weight: 800;

    text-transform: uppercase;

    letter-spacing: 0.5px;

    border: 1px solid transparent;

}



.order-status.pending { 

    background: rgba(255,193,7,0.2); 

    color:#ff8c00; 

    border-color:#ff8c00; 

}



.order-status.processing { 

    background: rgba(0,123,255,0.2); 

    color:#007bff; 

    border-color:#007bff; 

}



.order-status.shipped { 

    background: rgba(23,162,184,0.2); 

    color:#17a2b8; 

    border-color:#17a2b8; 

}



.order-status.delivered { 

    background: rgba(40,167,69,0.2); 

    color:#28a745; 

    border-color:#28a745; 

}



.order-status.completed { 

    background: rgba(40,167,69,0.2); 

    color:#28a745; 

    border-color:#28a745; 

}



.order-status.cancelled { 

    background: rgba(220,53,69,0.2); 

    color:#dc3545; 

    border-color:#dc3545; 

}



.order-card {

    padding: 8px 16px;

    border-bottom: 1px solid var(--border-light);

    background: var(--bg-white);

    flex-shrink: 0;

    position: relative;

}



.order-card:first-of-type {

    border-top: 1px solid rgba(19, 3, 37, 0.1);

}



.order-card:last-child {

    border-bottom: none;

}



/* Compact spacing for order items section */

.order-items-section {

    max-height: 320px;

    overflow-y: auto;

    margin-top: 4px;

}



.order-items-section::-webkit-scrollbar {

    width: 6px;

}



.order-items-section::-webkit-scrollbar-track {

    background: var(--bg-light);

    border-radius: 3px;

}



.order-items-section::-webkit-scrollbar-thumb {

    background: var(--text-light);

    border-radius: 3px;

}



.order-items-section::-webkit-scrollbar-thumb:hover {

    background: var(--text-dark);

}



.order-card h3 {

    color: var(--text-dark);

    margin: 0 0 8px 0;

    font-size: 13px;

    font-weight: 600;

    letter-spacing: -0.3px;

    padding: 6px 10px;

    background: rgba(19, 3, 37, 0.04);

    border-radius: 6px;

    display: inline-block;

}



.order-info-grid {

    display: grid;

    grid-template-columns: 1fr 1fr auto;

    gap: 16px;

    align-items: start;

}



.order-info-left {

    display: flex;

    flex-direction: column;

    gap: 6px;

}



.order-info-right {

    text-align: right;

}



.order-number-item {

    display: flex;

    align-items: center;

    gap: 6px;

    flex-wrap: wrap;

}



.order-number-item strong {

    color: var(--text-dark);

    font-weight: 600;

    font-size: 12px;

}



.order-number-value {

    color: var(--bg-white);

    font-weight: 700;

    font-size: 13px;

    background: var(--primary-dark);

    padding: 3px 10px;

    border-radius: 6px;

    border: 1px solid var(--primary-dark);

    box-shadow: 0 1px 3px rgba(19, 3, 37, 0.2);

}



.order-date-item {

    color: var(--text-light);

    font-size: 11px;

}



.payment-info-item {

    display: flex;

    align-items: center;

    gap: 6px;

    margin-top: 2px;

    flex-wrap: wrap;

}



.payment-info-item strong {

    color: var(--text-dark);

    font-weight: 600;

    font-size: 12px;

}



.payment-info-item span {

    color: var(--text-dark);

    font-size: 12px;

    font-weight: 500;

}



.customer-info-grid {

    display: grid;

    grid-template-columns: repeat(4, 1fr);

    gap: 12px;

    align-items: start;

}



.info-item {

    background: transparent;

    border: none;

    padding: 0;

    border-radius: 0;

    display: flex;

    flex-direction: column;

    gap: 3px;

}



.info-item strong {

    color: var(--text-light);

    font-weight: 600;

    display: block;

    font-size: 10px;

    text-transform: uppercase;

    letter-spacing: 0.5px;

    margin-bottom: 2px;

}



.info-item span, .info-item {

    color: var(--text-dark);

    font-size: 12px;

    font-weight: 500;

    word-break: break-word;

    overflow-wrap: anywhere;

    line-height: 1.4;

}



.customer-info-grid .info-item {

    white-space: normal;

}



.order-items-table {

    width: 100%;

    border-collapse: collapse;

    background: var(--bg-white);

    border: 1px solid var(--border-light);

    border-radius: 8px;

    overflow: hidden;

    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);

    font-size: 12px;

}



.order-items-table th:nth-child(1) { width: 40%; }

.order-items-table th:nth-child(2) { width: 15%; }

.order-items-table th:nth-child(3) { width: 15%; }

.order-items-table th:nth-child(4) { width: 30%; }



.order-items-table th {

    background: var(--bg-light);

    color: var(--text-dark);

    padding: 8px 10px;

    text-align: left;

    font-weight: 700;

    font-size: 11px;

    letter-spacing: -0.3px;

    border-bottom: 1px solid var(--border-light);

}



.order-items-table td {

    padding: 8px 10px;

    border-bottom: 1px solid var(--border-light);

    vertical-align: middle;

    color: var(--text-dark);

    font-size: 12px;

}



.order-items-table tbody tr:hover {

    background: var(--bg-light);

    box-shadow: inset 0 0 0 1px rgba(255, 215, 54, 0.1);

}



.order-items-table tbody tr:last-child td {

    border-bottom: none;

}



.product-info {

    display: flex;

    align-items: center;

    gap: 10px;

}



.product-info img {

    width: 40px;

    height: 40px;

    object-fit: cover;

    border-radius: 6px;

    border: 1px solid var(--border-light);

    flex-shrink: 0;

}



.product-info > div {

    min-width: 0;

}



.product-info span {

    font-weight: 600;

    color: var(--text-dark);

    font-size: 12px;

    line-height: 1.4;

}



.product-seller {

    color: var(--text-light);

    font-size: 10px;

    margin-top: 2px;

}



.order-items-table tfoot {

    background: var(--bg-light);

    font-weight: 700;

}



.order-items-table tfoot td {

    border-bottom: none;

    padding: 10px;

    color: var(--text-dark);

    font-size: 12px;

}



.order-items-table tfoot td:last-child {

    color: var(--primary-dark);

    font-size: 14px;

    font-weight: 800;

    letter-spacing: -0.3px;

    position: relative;

}



.order-items-table tfoot td:last-child::after {

    content: '';

    position: absolute;

    bottom: 0;

    left: 0;

    right: 0;

    height: 2px;

    background: linear-gradient(90deg, transparent 0%, rgba(255, 215, 54, 0.3) 50%, transparent 100%);

    opacity: 0.5;

}



.order-actions {

    padding: 10px 16px;

    background: linear-gradient(135deg, var(--bg-white) 0%, #f8f7fa 100%);

    display: flex;

    gap: 8px;

    flex-wrap: wrap;

    justify-content: flex-end;

    border-top: 2px solid rgba(19, 3, 37, 0.15);

    position: sticky;

    bottom: 0;

    z-index: 10;

    box-shadow: 0 -2px 8px rgba(0, 0, 0, 0.05);

}



.action-btn {

    padding: 8px 14px;

    border: none;

    border-radius: 8px;

    font-weight: 600;

    text-decoration: none;

    cursor: pointer;

    transition: all 0.2s ease;

    font-size: 12px;

    display: inline-flex;

    align-items: center;

    gap: 5px;

}



.btn-cancel {

    background: var(--error-red);

    color: var(--bg-white);

}



.btn-cancel:hover {

    background: #dc2626;

    transform: translateY(-1px);

}



.btn-buy-again {

    background: var(--primary-dark);

    color: var(--bg-white);

    text-decoration: none;

    display: inline-flex;

    align-items: center;

    gap: 6px;

}



.btn-buy-again:hover {

    background: #0a0118;

    transform: translateY(-1px);

    color: var(--bg-white);

    text-decoration: none;

}



.btn-rate {

    background: var(--accent-yellow);

    color: var(--primary-dark);

    font-weight: 700;

    box-shadow: 0 1px 3px rgba(255, 215, 54, 0.3);

}



.btn-rate:hover {

    background: #ffd020;

    transform: translateY(-1px);

    box-shadow: 0 2px 6px rgba(255, 215, 54, 0.4);

}



.btn-invoice {

    background: var(--text-light);

    color: var(--bg-white);

}



.btn-invoice:hover {

    background: #4b5563;

    transform: translateY(-1px);

}



.btn-return {

    background: var(--text-dark);

    color: var(--bg-white);

}



.btn-return:hover {

    background: #111827;

    transform: translateY(-1px);

}



.btn-order-received {

    background: var(--success-green);

    color: var(--bg-white);

}



.btn-order-received:hover {

    background: #059669;

    transform: translateY(-1px);

}



.btn-return-disabled {

    background: var(--text-light);

    color: var(--bg-white);

    opacity: 0.6;

    cursor: not-allowed;

}



.btn-return-disabled:hover {

    background: var(--text-light);

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

    background: rgba(0, 0, 0, 0.5);

    display: flex;

    justify-content: center;

    align-items: center;

    z-index: 10000;

}



.return-expired-content {

    background: var(--bg-white);

    padding: 24px;

    border-radius: 12px;

    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);

    border: 1px solid var(--border-light);

    max-width: 400px;

    width: 90%;

    text-align: center;

    animation: popupSlideIn 0.3s ease-out;

}



@keyframes popupSlideIn {

    from {

        opacity: 0;

        transform: translateY(-20px);

    }

    to {

        opacity: 1;

        transform: translateY(0);

    }

}



.return-expired-icon {

    font-size: 48px;

    color: var(--error-red);

    margin-bottom: 16px;

}



.return-expired-title {

    color: var(--text-dark);

    font-size: 18px;

    font-weight: 700;

    margin-bottom: 12px;

    letter-spacing: -0.3px;

}



.return-expired-message {

    color: var(--text-dark);

    font-size: 13px;

    line-height: 1.5;

    margin-bottom: 20px;

}



.return-expired-button {

    background: var(--primary-dark);

    color: var(--bg-white);

    border: none;

    padding: 10px 18px;

    border-radius: 8px;

    font-size: 14px;

    font-weight: 600;

    cursor: pointer;

    transition: all 0.2s ease;

}



.return-expired-button:hover {

    background: #0a0118;

    transform: translateY(-1px);

}



/* Responsive Design */

@media (max-width: 968px) {

    .order-details-container {

        padding: 0 10px;

    }

    

    main {

        padding: 10px 0;

    }

    

    .order-card {

        padding: 10px 12px;

    }

    

    .order-card h3 {

        font-size: 13px;

        margin-bottom: 8px;

    }

    

    .order-header {

        padding: 10px 12px;

    }

    

    .order-header-left {

        gap: 8px;

    }

    

    .back-arrow {

        width: 28px;

        height: 28px;

        font-size: 14px;

    }

    

    .order-header-title {

        font-size: 1.2rem;

    }

    

    

    .order-card[style*="grid-template-columns"] {

        grid-template-columns: 1fr !important;

        gap: 15px !important;

    }

    

    .order-info-grid {

        grid-template-columns: 1fr;

        gap: 10px;

    }

    

    .order-info-right {

        text-align: left;

    }

    

    .customer-info-grid {

        grid-template-columns: 1fr;

        gap: 8px;

    }

    

    .info-item[style*="grid-column"] {

        grid-column: 1 !important;

    }

    

    .order-actions {

        flex-direction: column;

        align-items: stretch;

        padding: 10px 12px;

    }

    

    .action-btn {

        width: 100%;

        justify-content: center;

        padding: 8px 12px;

    }

    

    .order-items-table {

        font-size: 11px;

    }

    

    .order-items-table th,

    .order-items-table td {

        padding: 8px;

    }

    

    .product-info {

        gap: 8px;

    }

    

    .product-info img {

        width: 36px;

        height: 36px;

    }

    

    .product-info span {

        font-size: 11px;

    }

    

    .product-seller {

        font-size: 9px;

    }

}



@media (max-width: 640px) {

    .order-details-container {

        padding: 0 8px;

    }

    

    main {

        padding: 8px 0;

    }

    

    .order-header {

        flex-direction: column;

        align-items: flex-start;

        gap: 8px;

        padding: 10px;

    }

    

    .order-header-left {

        width: 100%;

        justify-content: space-between;

    }

    

    .order-header-right {

        width: 100%;

        justify-content: flex-end;

    }

    

    .back-arrow {

        width: 28px;

        height: 28px;

        font-size: 14px;

    }

    

    .order-header-title {

        font-size: 1.2rem;

    }

    

    .order-status {

        font-size: 0.65rem;

        padding: 3px 8px;

    }

    

    .order-number-value {

        font-size: 12px;

        padding: 2px 6px;

    }

    

    .order-items-table {

        font-size: 10px;

    }

    

    .order-items-table th,

    .order-items-table td {

        padding: 6px 4px;

    }

    

    .product-info {

        flex-direction: row;

        gap: 6px;

    }

    

    .product-info img {

        width: 32px;

        height: 32px;

    }

    

    .order-items-table tfoot td {

        padding: 8px 4px;

        font-size: 11px;

    }

    

    .order-items-table tfoot td:last-child {

        font-size: 12px;

    }

    

    .action-btn {

        font-size: 11px;

        padding: 7px 10px;

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



<div class="order-details-container">

    <div class="order-details">

    <!-- Compact Header with Yellow Accents -->

    <div class="order-header">

        <div class="order-header-left">

            <a href="user-dashboard.php" class="back-arrow" title="Back to Dashboard">

                <i class="fas fa-arrow-left"></i>

            </a>

            <span class="order-header-title">Order Details</span>

        </div>

        <div class="order-header-right">

            <span class="order-status <?php echo strtolower($order['status']); ?>"><?php echo ucfirst($order['status']); ?></span>

        </div>

    </div>



    <!-- Order Details and Delivery Info in Two Columns -->

    <div class="order-card" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">

        <div>

            <h3>Order Information</h3>

            <div class="order-info-left">

                <div class="order-number-item">

                    <strong>Order Number:</strong>

                    <span class="order-number-value">#<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?></span>

                </div>

                <div class="payment-info-item">

                    <strong>Payment Method:</strong>

                    <span><?php echo ucfirst(str_replace('_', ' ', $order['payment_method'])); ?></span>

                </div>

                <div class="payment-info-item">

                    <strong>Payment Status:</strong>

                    <span><?php echo ucfirst($order['payment_status']); ?></span>

                </div>

                <div class="order-date-item" style="margin-top: 8px;">

                    <strong>Order Date:</strong> <?php echo date('F j, Y, g:i a', strtotime($order['created_at'])); ?>

                </div>

            </div>

            <?php if ($cancellationReason): ?>

        <div class="cancellation-reason-box" style="margin-top: 12px; padding: 12px; background: rgba(220, 53, 69, 0.08); border: 1px solid rgba(220, 53, 69, 0.3); border-radius: 8px;">

            <div style="display: flex; align-items: start; gap: 8px;">

                <i class="fas fa-info-circle" style="color: #dc3545; font-size: 14px; margin-top: 2px;"></i>

                <div style="flex: 1;">

                    <strong style="color: #dc3545; font-size: 12px; font-weight: 700; display: block; margin-bottom: 4px;">Cancellation Reason:</strong>

                    <p style="margin: 0; color: #dc3545; font-size: 12px; line-height: 1.5; font-weight: 500;"><?php echo htmlspecialchars($cancellationReason); ?></p>

                    <?php if (!empty($order['cancelled_at'])): ?>

                    <div style="margin-top: 6px; color: #dc3545; font-size: 10px; opacity: 0.8;">

                        <i class="far fa-clock"></i> Cancelled on <?php echo date('M j, Y, g:i A', strtotime($order['cancelled_at'])); ?>

                    </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

        <?php endif; ?>

        </div>

        <div>

            <h3>Delivery Information</h3>

            <div class="customer-info-grid" style="grid-template-columns: 1fr;">

            <div class="info-item">

                <strong>Name</strong>

                <span><?php echo $order['first_name'] . ' ' . $order['last_name']; ?></span>

            </div>

            <div class="info-item">

                <strong>Email</strong>

                <span><?php echo $order['email']; ?></span>

            </div>

            <div class="info-item">

                <strong>Phone</strong>

                <span><?php echo $order['phone'] ? $order['phone'] : 'N/A'; ?></span>

            </div>

            <div class="info-item" style="grid-column: 1 / -1;">

                <strong>Shipping Address</strong>

                <span><?php echo $order['shipping_address']; ?></span>

            </div>

            <?php if (strtolower($order['status']) === 'completed' && !empty($order['delivery_date'])): ?>

            <div class="info-item">

                <strong>Delivery Date</strong>

                <span><?php echo date('F j, Y, g:i a', strtotime($order['delivery_date'])); ?></span>

            </div>

            <?php endif; ?>

            </div>

        </div>

    </div>



    <!-- Order Items Card -->

    <div class="order-card">

        <h3>Order Items</h3>

        <div class="order-items-section">

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

    </div>



    <div class="order-actions">

        <button onclick="downloadInvoice()" class="action-btn btn-invoice">

            <i class="fas fa-download"></i> Download Invoice

        </button>

        

        <?php if ($order['status'] === 'pending'): ?>

            <button onclick="cancelOrder(<?php echo $order['id']; ?>)" class="action-btn btn-cancel">

                <i class="fas fa-times"></i> Cancel Order

            </button>

        <?php elseif (strtolower($order['status']) === 'delivered'): ?>

            <!-- DELIVERED: Order Received button -->

            <button type="button" onclick="confirmOrderReceived(<?php echo $order['id']; ?>, <?php echo !empty($orderItems) && isset($orderItems[0]['product_id']) ? $orderItems[0]['product_id'] : 'null'; ?>)" class="action-btn btn-order-received">

                <i class="fas fa-check-circle"></i> Order Received

            </button>

        <?php elseif (strtolower($order['status']) === 'completed'): ?>

            <?php if (!empty($orderItems) && isset($orderItems[0]['product_id'])): ?>

                <a href="product-detail.php?id=<?php echo $orderItems[0]['product_id']; ?>" class="action-btn btn-buy-again">

                    <i class="fas fa-shopping-cart"></i> Buy Again

                </a>

            <?php endif; ?>

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

</div>



<!-- Invoice Template (Hidden) -->

<div id="invoiceTemplate" style="display: none; width: 800px; background: white; padding: 40px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">

    <div style="text-align: center; margin-bottom: 40px; border-bottom: 3px solid #130325; padding-bottom: 20px;">

        <h1 style="color: #130325; margin: 0; font-size: 36px; font-weight: 800; letter-spacing: -1px;">INVOICE</h1>

        <p style="color: #6b7280; margin: 8px 0 0 0; font-size: 14px;">Order Receipt</p>

    </div>

    

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 40px;">

        <div>

            <h3 style="color: #130325; font-size: 16px; font-weight: 700; margin: 0 0 12px 0; border-bottom: 2px solid #FFD736; padding-bottom: 8px; display: inline-block;">Order Details</h3>

            <p style="margin: 6px 0; color: #1a1a1a; font-size: 13px;"><strong>Order Number:</strong> <span style="color: #130325; font-weight: 700;">#<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?></span></p>

            <p style="margin: 6px 0; color: #1a1a1a; font-size: 13px;"><strong>Date:</strong> <?php echo date('F j, Y, g:i a', strtotime($order['created_at'])); ?></p>

            <p style="margin: 6px 0; color: #1a1a1a; font-size: 13px;"><strong>Status:</strong> <span style="padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; display: inline-block; <?php 

                $status = strtolower($order['status']);

                if ($status === 'pending') echo 'background: rgba(255,193,7,0.2); color: #ff8c00; border: 1px solid #ff8c00;';

                elseif ($status === 'processing') echo 'background: rgba(0,123,255,0.2); color: #007bff; border: 1px solid #007bff;';

                elseif ($status === 'shipped') echo 'background: rgba(23,162,184,0.2); color: #17a2b8; border: 1px solid #17a2b8;';

                elseif ($status === 'delivered') echo 'background: rgba(40,167,69,0.2); color: #28a745; border: 1px solid #28a745;';

                elseif ($status === 'completed') echo 'background: rgba(40,167,69,0.2); color: #28a745; border: 1px solid #28a745;';

                elseif ($status === 'cancelled') echo 'background: rgba(220,53,69,0.2); color: #dc3545; border: 1px solid #dc3545;';

                else echo 'background: rgba(40,167,69,0.2); color: #28a745; border: 1px solid #28a745;';

            ?>"><?php echo ucfirst($order['status']); ?></span></p>

            <p style="margin: 6px 0; color: #1a1a1a; font-size: 13px;"><strong>Payment Method:</strong> <?php echo ucfirst(str_replace('_', ' ', $order['payment_method'])); ?></p>

            <?php if ($cancellationReason): ?>

            <div style="margin-top: 12px; padding: 12px; background: rgba(220, 53, 69, 0.08); border: 1px solid rgba(220, 53, 69, 0.3); border-radius: 6px;">

                <p style="margin: 0 0 4px 0; color: #dc3545; font-size: 12px; font-weight: 700;">⚠️ Cancellation Reason:</p>

                <p style="margin: 0; color: #dc3545; font-size: 12px; line-height: 1.5;"><?php echo htmlspecialchars($cancellationReason); ?></p>

                <?php if (!empty($order['cancelled_at'])): ?>

                <p style="margin: 6px 0 0 0; color: #dc3545; font-size: 10px; opacity: 0.8;">Cancelled on <?php echo date('M j, Y, g:i A', strtotime($order['cancelled_at'])); ?></p>

                <?php endif; ?>

            </div>

            <?php endif; ?>

        </div>

        <div>

            <h3 style="color: #130325; font-size: 16px; font-weight: 700; margin: 0 0 12px 0; border-bottom: 2px solid #FFD736; padding-bottom: 8px; display: inline-block;">Billing Information</h3>

            <p style="margin: 6px 0; color: #1a1a1a; font-size: 13px;"><strong>Name:</strong> <?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?></p>

            <p style="margin: 6px 0; color: #1a1a1a; font-size: 13px;"><strong>Email:</strong> <?php echo htmlspecialchars($order['email']); ?></p>

            <p style="margin: 6px 0; color: #1a1a1a; font-size: 13px;"><strong>Phone:</strong> <?php echo htmlspecialchars($order['phone'] ? $order['phone'] : 'N/A'); ?></p>

            <p style="margin: 6px 0; color: #1a1a1a; font-size: 13px;"><strong>Address:</strong> <?php echo htmlspecialchars($order['shipping_address']); ?></p>

        </div>

    </div>

    

    <div style="margin-bottom: 30px;">

        <h3 style="color: #130325; font-size: 16px; font-weight: 700; margin: 0 0 15px 0; border-bottom: 2px solid #FFD736; padding-bottom: 8px; display: inline-block;">Order Items</h3>

        <table style="width: 100%; border-collapse: collapse;">

            <thead>

                <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">

                    <th style="padding: 12px; text-align: left; color: #130325; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Product</th>

                    <th style="padding: 12px; text-align: center; color: #130325; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Quantity</th>

                    <th style="padding: 12px; text-align: right; color: #130325; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Price</th>

                    <th style="padding: 12px; text-align: right; color: #130325; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Total</th>

                </tr>

            </thead>

            <tbody>

                <?php foreach ($orderItems as $item): ?>

                <tr style="border-bottom: 1px solid #e5e7eb;">

                    <td style="padding: 12px; color: #1a1a1a; font-size: 13px;">

                        <div style="font-weight: 600; margin-bottom: 4px;"><?php echo htmlspecialchars($item['name']); ?></div>

                        <div style="color: #6b7280; font-size: 11px;">Seller: <?php echo htmlspecialchars($item['seller_name'] ?? 'Unknown'); ?></div>

                    </td>

                    <td style="padding: 12px; text-align: center; color: #1a1a1a; font-size: 13px;"><?php echo $item['quantity']; ?></td>

                    <td style="padding: 12px; text-align: right; color: #1a1a1a; font-size: 13px;">₱<?php echo number_format($item['price'], 2); ?></td>

                    <td style="padding: 12px; text-align: right; color: #1a1a1a; font-size: 13px; font-weight: 600;">₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?></td>

                </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

    

    <div style="border-top: 3px solid #130325; padding-top: 20px; margin-top: 30px;">

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">

            <span style="color: #1a1a1a; font-size: 14px; font-weight: 600;">Subtotal:</span>

            <span style="color: #1a1a1a; font-size: 14px; font-weight: 600;">₱<?php echo number_format($calculatedTotal, 2); ?></span>

        </div>

        <div style="display: flex; justify-content: space-between; align-items: center; background: #130325; padding: 15px 20px; border-radius: 8px; margin-top: 15px;">

            <span style="color: #FFD736; font-size: 20px; font-weight: 800; letter-spacing: -0.5px;">TOTAL AMOUNT:</span>

            <span style="color: #FFD736; font-size: 24px; font-weight: 800; letter-spacing: -0.5px;">₱<?php echo number_format($order['total_amount'], 2); ?></span>

        </div>

    </div>

    

    <div style="text-align: center; margin-top: 40px; padding-top: 20px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 11px;">

        <p style="margin: 5px 0;">Thank you for your purchase!</p>

        <p style="margin: 5px 0;">This is an automated invoice. Please keep this for your records.</p>

    </div>

</div>



<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<script>

async function downloadInvoice() {

    const invoiceElement = document.getElementById('invoiceTemplate');

    const button = event.target.closest('button');

    const originalText = button.innerHTML;

    

    // Show loading state

    button.disabled = true;

    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';

    

    try {

        // Temporarily show the invoice template

        invoiceElement.style.display = 'block';

        invoiceElement.style.position = 'absolute';

        invoiceElement.style.left = '-9999px';

        

        // Generate canvas from HTML

        const canvas = await html2canvas(invoiceElement, {

            scale: 2,

            backgroundColor: '#ffffff',

            logging: false,

            useCORS: true,

            width: 800,

            height: invoiceElement.scrollHeight

        });

        

        // Convert canvas to PNG blob

        canvas.toBlob(function(blob) {

            const url = URL.createObjectURL(blob);

            const a = document.createElement('a');

            a.href = url;

            a.download = `invoice-<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?>.png`;

            document.body.appendChild(a);

            a.click();

            document.body.removeChild(a);

            URL.revokeObjectURL(url);

            

            // Hide invoice template again

            invoiceElement.style.display = 'none';

            invoiceElement.style.position = '';

            invoiceElement.style.left = '';

            

            // Restore button

            button.disabled = false;

            button.innerHTML = originalText;

        }, 'image/png');

    } catch (error) {

        console.error('Error generating invoice:', error);

        alert('Error generating invoice. Please try again.');

        invoiceElement.style.display = 'none';

        invoiceElement.style.position = '';

        invoiceElement.style.left = '';

        button.disabled = false;

        button.innerHTML = originalText;

    }

}



function cancelOrder(orderId) {

    if (confirm('Are you sure you want to cancel this order?')) {

        // Redirect to cancel order page or handle via AJAX

        window.location.href = `cancel-order.php?id=${orderId}`;

    }

}



// Order Received Confirmation with Rating Modal

function confirmOrderReceived(orderId, productId) {

    // Create rating modal first

    const modal = document.createElement('div');

    modal.id = 'orderReceivedModal';

    modal.style.cssText = 'display: flex; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center;';

    

    const modalContent = document.createElement('div');

    modalContent.style.cssText = 'background: var(--bg-white); border-radius: 12px; padding: 0; max-width: 450px; width: 90%; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); border: 1px solid var(--border-light); animation: slideDown 0.3s ease;';

    

    const modalHeader = document.createElement('div');

    modalHeader.style.cssText = 'background: var(--primary-dark); color: var(--bg-white); padding: 14px 18px; border-radius: 12px 12px 0 0; display: flex; align-items: center; gap: 10px;';

    modalHeader.innerHTML = '<i class="fas fa-star" style="font-size: 16px; color: var(--accent-yellow);"></i><h3 style="margin: 0; font-size: 14px; font-weight: 700;">Rate Your Product</h3>';

    

    const modalBody = document.createElement('div');

    modalBody.style.cssText = 'padding: 20px; color: var(--text-dark);';

    

    // Star rating HTML

    let ratingHTML = '<p style="margin: 0 0 16px; font-size: 13px; line-height: 1.5; color: var(--text-dark);">How would you rate your experience with this product?</p>';

    ratingHTML += '<div style="display: flex; justify-content: center; gap: 8px; margin-bottom: 20px; flex-wrap: wrap;">';

    for (let i = 1; i <= 5; i++) {

        ratingHTML += `<input type="radio" id="modalStar${i}" name="modalRating" value="${i}" style="display: none;">

        <label for="modalStar${i}" class="modal-star-label" data-rating="${i}" style="font-size: 32px; color: #ddd; cursor: pointer; transition: all 0.2s ease; user-select: none;">★</label>`;

    }

    ratingHTML += '</div>';

    ratingHTML += '<div id="ratingText" style="text-align: center; font-size: 12px; color: var(--text-light); min-height: 20px; margin-bottom: 10px;"></div>';

    

    modalBody.innerHTML = ratingHTML;

    

    const modalFooter = document.createElement('div');

    modalFooter.style.cssText = 'padding: 14px 18px; border-top: 1px solid var(--border-light); display: flex; gap: 10px; justify-content: flex-end;';

    

    const cancelBtn = document.createElement('button');

    cancelBtn.textContent = 'Skip';

    cancelBtn.style.cssText = 'padding: 8px 18px; background: var(--bg-white); color: var(--text-dark); border: 1px solid var(--border-light); border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.2s ease;';

    cancelBtn.onmouseover = function() { this.style.background = 'var(--bg-light)'; };

    cancelBtn.onmouseout = function() { this.style.background = 'var(--bg-white)'; };

    

    const confirmBtn = document.createElement('button');

    confirmBtn.textContent = 'Confirm Review';

    confirmBtn.id = 'confirmReviewBtn';

    confirmBtn.style.cssText = 'padding: 8px 18px; background: var(--primary-dark); color: var(--bg-white); border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.2s ease; opacity: 0.6; cursor: not-allowed;';

    confirmBtn.disabled = true;

    

    let selectedRating = 0;

    

    // Star rating interaction

    const starLabels = modalBody.querySelectorAll('.modal-star-label');

    const ratingText = modalBody.querySelector('#ratingText');

    const ratingMessages = {

        1: 'Poor',

        2: 'Fair',

        3: 'Good',

        4: 'Very Good',

        5: 'Excellent'

    };

    

    starLabels.forEach(label => {

        label.addEventListener('mouseenter', function() {

            const rating = parseInt(this.getAttribute('data-rating'));

            highlightStars(rating);

        });

        

        label.addEventListener('click', function() {

            selectedRating = parseInt(this.getAttribute('data-rating'));

            highlightStars(selectedRating);

            ratingText.textContent = ratingMessages[selectedRating];

            confirmBtn.disabled = false;

            confirmBtn.style.opacity = '1';

            confirmBtn.style.cursor = 'pointer';

        });

    });

    

    modalBody.addEventListener('mouseleave', function() {

        if (selectedRating > 0) {

            highlightStars(selectedRating);

        } else {

            highlightStars(0);

        }

    });

    

    function highlightStars(rating) {

        starLabels.forEach((label, index) => {

            const starRating = parseInt(label.getAttribute('data-rating'));

            if (starRating <= rating) {

                label.style.color = 'var(--accent-yellow)';

            } else {

                label.style.color = '#ddd';

            }

        });

    }

    

    cancelBtn.onclick = function() {

        // Skip rating, just confirm order received

        confirmOrderReceivedDirect(orderId);

        document.body.removeChild(modal);

        document.body.style.overflow = '';

    };

    

    confirmBtn.onclick = function() {

        if (selectedRating === 0) {

            alert('Please select a rating before confirming.');

            return;

        }

        

        confirmBtn.disabled = true;

        confirmBtn.textContent = 'Processing...';

        

        // First confirm order received

        fetch('ajax/confirm-order-received.php', {

            method: 'POST',

            headers: { 'Content-Type': 'application/json' },

            body: JSON.stringify({ order_id: orderId })

        })

        .then(response => response.json())

        .then(data => {

            if (data.success) {

                document.body.removeChild(modal);

                document.body.style.overflow = '';

                

                // Redirect to rate product page

                if (productId) {

                    window.location.href = 'rate-order.php?id=' + orderId + '&rating=' + selectedRating;

                } else {

                    window.location.href = window.location.href.split('?')[0] + '?id=' + orderId;

                }

            } else {

                alert('Error: ' + (data.message || 'Failed to confirm order'));

                confirmBtn.disabled = false;

                confirmBtn.textContent = 'Confirm Review';

            }

        })

        .catch(error => {

            console.error('Error:', error);

            alert('Error confirming order. Please try again.');

            confirmBtn.disabled = false;

            confirmBtn.textContent = 'Confirm Review';

        });

    };

    

    modalFooter.appendChild(cancelBtn);

    modalFooter.appendChild(confirmBtn);

    modalContent.appendChild(modalHeader);

    modalContent.appendChild(modalBody);

    modalContent.appendChild(modalFooter);

    modal.appendChild(modalContent);

    

    document.body.appendChild(modal);

    document.body.style.overflow = 'hidden';

    

    modal.onclick = function(e) {

        if (e.target === modal) {

            document.body.removeChild(modal);

            document.body.style.overflow = '';

        }

    };

    

    if (!document.getElementById('orderReceivedModalStyles')) {

        const style = document.createElement('style');

        style.id = 'orderReceivedModalStyles';

        style.textContent = `

            @keyframes slideDown { 

                from { opacity: 0; transform: translateY(-20px); } 

                to { opacity: 1; transform: translateY(0); } 

            }

            .modal-star-label:hover {

                transform: scale(1.2);

            }

        `;

        document.head.appendChild(style);

    }

}



// Direct order received confirmation (without rating)

function confirmOrderReceivedDirect(orderId) {

    fetch('ajax/confirm-order-received.php', {

        method: 'POST',

        headers: { 'Content-Type': 'application/json' },

        body: JSON.stringify({ order_id: orderId })

    })

    .then(response => response.json())

    .then(data => {

        if (data.success) {

            const notification = document.createElement('div');

            notification.style.cssText = 'position: fixed; left: 50%; bottom: 24px; transform: translateX(-50%); background: var(--bg-white); color: var(--text-dark); border: 1px solid var(--border-light); border-radius: 12px; padding: 14px 18px; z-index: 10001; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); max-width: 90%; width: 400px; text-align: center;';

            notification.innerHTML = '<div style="font-weight: 600; font-size: 14px;">' + data.message + '</div>';

            document.body.appendChild(notification);

            

            setTimeout(() => {

                window.location.href = window.location.href.split('?')[0] + '?id=' + orderId;

            }, 1500);

        } else {

            alert('Error: ' + (data.message || 'Failed to confirm order'));

        }

    })

    .catch(error => {

        console.error('Error:', error);

        alert('Error confirming order. Please try again.');

    });

}



// Buy Again function removed - now using direct link to product-detail.php



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
