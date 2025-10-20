<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login_customer.php');
    exit();
}

// Check if user is a customer (not admin or seller)
if (isset($_SESSION['user_type']) && in_array($_SESSION['user_type'], ['admin', 'seller'])) {
    header('Location: login_customer.php');
    exit();
}

$customerId = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle new return request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_return'])) {
    $orderId = (int)$_POST['order_id'];
    $productId = (int)$_POST['product_id'];
    $reason = $_POST['reason'];
    $description = $_POST['description'];
    $quantity = (int)$_POST['quantity'];
    
    try {
        $pdo->beginTransaction();
        
        // Verify the order belongs to this customer
        $stmt = $pdo->prepare("SELECT o.*, oi.*, p.name as product_name, p.price, p.seller_id 
                               FROM orders o 
                               JOIN order_items oi ON o.id = oi.order_id 
                               JOIN products p ON oi.product_id = p.id 
                               WHERE o.id = ? AND o.user_id = ? AND oi.product_id = ?");
        $stmt->execute([$orderId, $customerId, $productId]);
        $orderItem = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$orderItem) {
            throw new Exception("Order item not found or doesn't belong to you.");
        }
        
        // Check if quantity is valid
        if ($quantity > $orderItem['quantity']) {
            throw new Exception("Return quantity cannot exceed ordered quantity.");
        }
        
        // Check if order is eligible for return (delivered within 30 days)
        $deliveryDate = new DateTime($orderItem['delivery_date'] ?? $orderItem['created_at']);
        $now = new DateTime();
        $daysDiff = $now->diff($deliveryDate)->days;
        
        if ($daysDiff > 30) {
            throw new Exception("Return period has expired. Returns must be requested within 30 days of delivery.");
        }
        
        // Check if order status allows returns
        if (!in_array($orderItem['status'], ['delivered', 'shipped'])) {
            throw new Exception("This order is not eligible for returns.");
        }
        
        // Insert return request
        $stmt = $pdo->prepare("INSERT INTO return_requests (order_id, product_id, customer_id, seller_id, quantity, reason, description, status, created_at) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
        $result = $stmt->execute([$orderId, $productId, $customerId, $orderItem['seller_id'], $quantity, $reason, $description]);
        
        if ($result) {
            $pdo->commit();
            $message = "Return request submitted successfully. You will be notified once the seller reviews your request.";
        } else {
            throw new Exception("Failed to submit return request.");
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Get customer's orders eligible for returns
$stmt = $pdo->prepare("SELECT DISTINCT o.*, oi.product_id, oi.quantity as ordered_quantity, oi.price, 
                       p.name as product_name, p.image_url, u.first_name, u.last_name
                       FROM orders o 
                       JOIN order_items oi ON o.id = oi.order_id 
                       JOIN products p ON oi.product_id = p.id 
                       JOIN users u ON p.seller_id = u.id
                       WHERE o.user_id = ? 
                       AND o.status IN ('delivered', 'shipped')
                       AND (o.delivery_date IS NULL OR DATEDIFF(NOW(), o.delivery_date) <= 30)
                       ORDER BY o.created_at DESC");
$stmt->execute([$customerId]);
$eligibleOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get customer's return requests
$stmt = $pdo->prepare("SELECT rr.*, o.id as order_number, p.name as product_name, p.image_url, u.first_name, u.last_name
                       FROM return_requests rr
                       JOIN orders o ON rr.order_id = o.id
                       JOIN products p ON rr.product_id = p.id
                       JOIN users u ON rr.seller_id = u.id
                       WHERE rr.customer_id = ?
                       ORDER BY rr.created_at DESC");
$stmt->execute([$customerId]);
$returnRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<style>
.returns-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 40px 20px;
    margin-top: 100px;
}

.returns-header {
    text-align: center;
    margin-bottom: 50px;
    padding: 30px 0;
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
}

.returns-header h1 {
    color: #130325;
    font-size: 2.8rem;
    font-weight: 800;
    margin-bottom: 15px;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.returns-header p {
    color: #6c757d;
    font-size: 1.2rem;
    font-weight: 500;
    margin: 0;
}

.returns-tabs {
    display: flex;
    margin-bottom: 40px;
    border-bottom: 3px solid #e9ecef;
    background: #ffffff;
    border-radius: 12px 12px 0 0;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    overflow: hidden;
}

.returns-tab {
    padding: 20px 40px;
    background: none;
    border: none;
    font-size: 1.1rem;
    font-weight: 600;
    color: #6c757d;
    cursor: pointer;
    border-bottom: 4px solid transparent;
    transition: all 0.3s ease;
    flex: 1;
    text-align: center;
}

.returns-tab.active {
    color: #130325;
    border-bottom-color: #FFD736;
    background: rgba(255, 215, 54, 0.1);
}

.returns-tab:hover {
    color: #130325;
    background: rgba(255, 215, 54, 0.05);
}

.returns-content {
    display: none;
}

.returns-content.active {
    display: block;
}

.return-form {
    background: #ffffff;
    border: 2px solid #e9ecef;
    border-radius: 16px;
    padding: 40px;
    margin-bottom: 40px;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
    transition: all 0.3s ease;
}

.return-form:hover {
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
    transform: translateY(-2px);
}

.return-form h3 {
    color: #130325;
    font-size: 1.8rem;
    font-weight: 800;
    margin-bottom: 30px;
    text-align: center;
    border-bottom: 3px solid #FFD736;
    padding-bottom: 15px;
}

.form-group {
    margin-bottom: 25px;
}

.form-group label {
    display: block;
    color: #130325;
    font-weight: 700;
    margin-bottom: 10px;
    font-size: 1.1rem;
}

.form-group select,
.form-group input,
.form-group textarea {
    width: 100%;
    padding: 15px 20px;
    border: 2px solid #e9ecef;
    border-radius: 12px;
    font-size: 1rem;
    transition: all 0.3s ease;
    background: #ffffff;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.form-group select:focus,
.form-group input:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #FFD736;
    box-shadow: 0 4px 15px rgba(255, 215, 54, 0.2);
    transform: translateY(-1px);
}


.submit-btn {
    background: linear-gradient(135deg, #FFD736, #e6c230);
    color: #130325;
    border: none;
    padding: 18px 40px;
    border-radius: 12px;
    font-size: 1.2rem;
    font-weight: 800;
    cursor: pointer;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 1px;
    box-shadow: 0 4px 15px rgba(255, 215, 54, 0.3);
    width: 100%;
    margin-top: 20px;
}

.submit-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 30px rgba(255, 215, 54, 0.5);
    background: linear-gradient(135deg, #e6c230, #FFD736);
}

.orders-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 25px;
    margin-bottom: 40px;
}

.order-card {
    background: #ffffff;
    border: 2px solid #e9ecef;
    border-radius: 16px;
    padding: 25px;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.order-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(135deg, #FFD736, #e6c230);
}

.order-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 12px 35px rgba(0, 0, 0, 0.15);
    border-color: #FFD736;
}

.order-card h4 {
    color: #130325;
    font-size: 1.4rem;
    font-weight: 800;
    margin-bottom: 15px;
    text-align: center;
}

.order-info {
    color: #6c757d;
    font-size: 1rem;
    margin-bottom: 20px;
    background: #f8f9fa;
    padding: 15px;
    border-radius: 10px;
    border-left: 4px solid #FFD736;
}

.product-info {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 20px;
    padding: 15px;
    background: #ffffff;
    border-radius: 12px;
    border: 1px solid #e9ecef;
}

.product-info img {
    width: 80px;
    height: 80px;
    object-fit: cover;
    border-radius: 12px;
    border: 3px solid #FFD736;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.product-details h5 {
    color: #130325;
    font-weight: 700;
    margin-bottom: 8px;
    font-size: 1.1rem;
}

.product-details p {
    color: #6c757d;
    font-size: 1rem;
    margin: 4px 0;
    font-weight: 500;
}

.return-requests {
    background: #ffffff;
    border: 2px solid #e9ecef;
    border-radius: 16px;
    padding: 30px;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
    margin-top: 20px;
}

.return-requests h3 {
    color: #130325;
    font-size: 1.8rem;
    font-weight: 800;
    margin-bottom: 30px;
    text-align: center;
    border-bottom: 3px solid #FFD736;
    padding-bottom: 15px;
}

.return-request {
    border: 2px solid #e9ecef;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 25px;
    background: #f8f9fa;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.return-request::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(135deg, #FFD736, #e6c230);
}

.return-request:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    border-color: #FFD736;
}

.return-request:last-child {
    margin-bottom: 0;
}

.return-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.return-id {
    color: #130325;
    font-weight: 700;
    font-size: 1.1rem;
}

.return-status {
    padding: 5px 15px;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 600;
    text-transform: uppercase;
}

.return-status.pending {
    background: #fff3cd;
    color: #856404;
}

.return-status.approved {
    background: #d4edda;
    color: #155724;
}

.return-status.rejected {
    background: #f8d7da;
    color: #721c24;
}

.return-status.completed {
    background: #d1ecf1;
    color: #0c5460;
}

.return-details {
    color: #6c757d;
    font-size: 0.9rem;
    line-height: 1.5;
}

.alert {
    padding: 20px 25px;
    border-radius: 12px;
    margin-bottom: 30px;
    font-weight: 700;
    font-size: 1.1rem;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    border-left: 5px solid;
}

.alert-success {
    background: linear-gradient(135deg, #d4edda, #c3e6cb);
    color: #155724;
    border-left-color: #28a745;
}

.alert-danger {
    background: linear-gradient(135deg, #f8d7da, #f5c6cb);
    color: #721c24;
    border-left-color: #dc3545;
}

.alert-info {
    background: linear-gradient(135deg, #d1ecf1, #bee5eb);
    color: #0c5460;
    border-left-color: #17a2b8;
}

@media (max-width: 768px) {
    .returns-container {
        padding: 20px 15px;
        margin-top: 80px;
    }
    
    .returns-header {
        padding: 20px 0;
        margin-bottom: 30px;
    }
    
    .returns-header h1 {
        font-size: 2.2rem;
    }
    
    .returns-header p {
        font-size: 1rem;
    }
    
    .returns-tabs {
        flex-direction: column;
        border-radius: 12px;
    }
    
    .returns-tab {
        padding: 15px 20px;
        border-bottom: 1px solid #e9ecef;
        border-radius: 0;
    }
    
    .orders-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .return-form {
        padding: 25px 20px;
    }
    
    .return-form h3 {
        font-size: 1.5rem;
    }
    
    
    .product-info {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .product-info img {
        width: 100px;
        height: 100px;
    }
    
    .return-requests {
        padding: 20px 15px;
    }
    
    .return-request {
        padding: 20px 15px;
    }
    
    .return-header {
        flex-direction: column;
        gap: 10px;
        text-align: center;
    }
}
</style>

<div class="returns-container">
    <div class="returns-header">
        <h1>Returns & Refunds</h1>
        <p>Request returns for your orders or track existing return requests</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="returns-tabs">
        <button class="returns-tab active" onclick="showTab('new-return')">Request Return</button>
        <button class="returns-tab" onclick="showTab('my-returns')">My Returns</button>
    </div>

    <!-- New Return Request Tab -->
    <div id="new-return" class="returns-content active">
        <div class="return-form">
            <h3>Request a Return</h3>
            <form method="POST">
                <div class="form-group">
                    <label for="order_id">Select Order:</label>
                    <select name="order_id" id="order_id" required onchange="loadOrderItems()">
                        <option value="">Choose an order...</option>
                        <?php foreach ($eligibleOrders as $order): ?>
                            <option value="<?php echo $order['id']; ?>">
                                Order #<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?> - 
                                <?php echo htmlspecialchars($order['product_name']); ?> - 
                                ₱<?php echo number_format($order['price'], 2); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="product_id">Product:</label>
                    <select name="product_id" id="product_id" required>
                        <option value="">Select a product...</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="quantity">Quantity to Return:</label>
                    <input type="number" name="quantity" id="quantity" min="1" required>
                </div>

                <div class="form-group">
                    <label for="reason">Reason for Return:</label>
                    <select name="reason" id="reason" required>
                        <option value="">Select a reason...</option>
                        <option value="defective">Defective Product</option>
                        <option value="wrong_item">Wrong Item</option>
                        <option value="not_as_described">Not as Described</option>
                        <option value="damaged_shipping">Damaged in Shipping</option>
                        <option value="changed_mind">Changed Mind</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="description">Additional Details:</label>
                    <textarea name="description" id="description" rows="4" placeholder="Please provide additional details about your return request..."></textarea>
                </div>

                <button type="submit" name="submit_return" class="submit-btn">Submit Return Request</button>
            </form>
        </div>

        <?php if (!empty($eligibleOrders)): ?>
            <h3>Eligible Orders for Return</h3>
            <div class="orders-grid">
                <?php foreach ($eligibleOrders as $order): ?>
                    <div class="order-card">
                        <h4>Order #<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?></h4>
                        <div class="order-info">
                            <p><strong>Date:</strong> <?php echo date('M j, Y', strtotime($order['created_at'])); ?></p>
                            <p><strong>Status:</strong> <?php echo ucfirst($order['status']); ?></p>
                            <p><strong>Seller:</strong> <?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?></p>
                        </div>
                        <div class="product-info">
                            <img src="<?php echo htmlspecialchars($order['image_url']); ?>" alt="<?php echo htmlspecialchars($order['product_name']); ?>">
                            <div class="product-details">
                                <h5><?php echo htmlspecialchars($order['product_name']); ?></h5>
                                <p>Quantity: <?php echo $order['ordered_quantity']; ?></p>
                                <p>Price: ₱<?php echo number_format($order['price'], 2); ?></p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <p>No orders are currently eligible for returns. Orders must be delivered within the last 30 days to be eligible for returns.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- My Returns Tab -->
    <div id="my-returns" class="returns-content">
        <div class="return-requests">
            <h3>My Return Requests</h3>
            <?php if (!empty($returnRequests)): ?>
                <?php foreach ($returnRequests as $request): ?>
                    <div class="return-request">
                        <div class="return-header">
                            <span class="return-id">Return #<?php echo str_pad($request['id'], 6, '0', STR_PAD_LEFT); ?></span>
                            <span class="return-status <?php echo $request['status']; ?>"><?php echo ucfirst($request['status']); ?></span>
                        </div>
                        <div class="return-details">
                            <p><strong>Order:</strong> #<?php echo str_pad($request['order_id'], 6, '0', STR_PAD_LEFT); ?></p>
                            <p><strong>Product:</strong> <?php echo htmlspecialchars($request['product_name']); ?></p>
                            <p><strong>Quantity:</strong> <?php echo $request['quantity']; ?></p>
                            <p><strong>Reason:</strong> <?php echo ucfirst(str_replace('_', ' ', $request['reason'])); ?></p>
                            <p><strong>Seller:</strong> <?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></p>
                            <p><strong>Submitted:</strong> <?php echo date('M j, Y g:i A', strtotime($request['created_at'])); ?></p>
                            <?php if ($request['description']): ?>
                                <p><strong>Description:</strong> <?php echo htmlspecialchars($request['description']); ?></p>
                            <?php endif; ?>
                            <?php if ($request['rejection_reason']): ?>
                                <p><strong>Rejection Reason:</strong> <?php echo htmlspecialchars($request['rejection_reason']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="alert alert-info">
                    <p>You haven't submitted any return requests yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function showTab(tabName) {
    // Hide all content
    const contents = document.querySelectorAll('.returns-content');
    contents.forEach(content => content.classList.remove('active'));
    
    // Remove active class from all tabs
    const tabs = document.querySelectorAll('.returns-tab');
    tabs.forEach(tab => tab.classList.remove('active'));
    
    // Show selected content
    document.getElementById(tabName).classList.add('active');
    
    // Add active class to clicked tab
    event.target.classList.add('active');
}

function loadOrderItems() {
    const orderId = document.getElementById('order_id').value;
    const productSelect = document.getElementById('product_id');
    const quantityInput = document.getElementById('quantity');
    
    // Clear existing options
    productSelect.innerHTML = '<option value="">Select a product...</option>';
    quantityInput.value = '';
    
    if (!orderId) return;
    
    // Get order items via AJAX
    fetch('ajax/get-order-items.php?order_id=' + orderId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                data.items.forEach(item => {
                    const option = document.createElement('option');
                    option.value = item.product_id;
                    option.textContent = item.product_name + ' (Qty: ' + item.quantity + ')';
                    option.dataset.maxQuantity = item.quantity;
                    productSelect.appendChild(option);
                });
            }
        })
        .catch(error => {
            console.error('Error loading order items:', error);
        });
}

// Update quantity max when product is selected
document.getElementById('product_id').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const quantityInput = document.getElementById('quantity');
    
    if (selectedOption.dataset.maxQuantity) {
        quantityInput.max = selectedOption.dataset.maxQuantity;
        quantityInput.value = 1;
    }
});
</script>

<?php include 'includes/footer.php'; ?>
