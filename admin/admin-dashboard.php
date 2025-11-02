
<?php
require_once 'includes/admin_header.php';

// Simple admin check - ensure user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Ensure PDO connection is available
if (!isset($pdo)) {
    require_once '../config/database.php'; // Adjust path if needed
}

if (isset($_POST['update_settings'])) {
    $gracePeriod = intval($_POST['grace_period']);
    
    // Validate grace period (between 1 and 60 minutes)
    if ($gracePeriod < 1 || $gracePeriod > 60) {
        $error = "Grace period must be between 1 and 60 minutes.";
    } else {
        try {
            // Check if setting exists
            $stmt = $pdo->prepare("SELECT id FROM settings WHERE setting_key = 'order_grace_period'");
            $stmt->execute();
            $exists = $stmt->fetch();
            
            if ($exists) {
                // Update existing setting
                $stmt = $pdo->prepare("UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = 'order_grace_period'");
                $stmt->execute([$gracePeriod]);
            } else {
                // Insert new setting
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES ('order_grace_period', ?, NOW(), NOW())");
                $stmt->execute([$gracePeriod]);
            }
            
            $success = "Grace period updated successfully to {$gracePeriod} minutes.";
        } catch (PDOException $e) {
            $error = "Error updating settings: " . $e->getMessage();
        }
    }
}

// Get current grace period setting
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'order_grace_period'");
$stmt->execute();
$currentGracePeriod = $stmt->fetchColumn();

// Default to 5 minutes if not set
if (!$currentGracePeriod) {
    $currentGracePeriod = 5;
}

// Get stats for dashboard
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM users");
$stmt->execute();
$totalUsers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM products");
$stmt->execute();
$totalProducts = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM orders");
$stmt->execute();
$totalOrders = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $pdo->prepare("SELECT SUM(total_amount) as total FROM orders WHERE status = 'delivered'");
$stmt->execute();
$totalRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Get pending sellers
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM users WHERE user_type = 'seller' AND seller_status = 'pending'");
$stmt->execute();
$pendingSellers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get pending products for approval
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM products WHERE status = 'pending'");
$stmt->execute();
$pendingProducts = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get pending products list for display
$stmt = $pdo->prepare("SELECT p.*, u.first_name, u.last_name 
                       FROM products p 
                       JOIN users u ON p.seller_id = u.id 
                       WHERE p.status = 'pending' 
                       ORDER BY p.created_at DESC 
                       LIMIT 5");
$stmt->execute();
$pendingProductsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all admin categories (seller_id IS NULL)
$stmt = $pdo->prepare("SELECT c1.*, c2.name as parent_name 
                       FROM categories c1 
                       LEFT JOIN categories c2 ON c1.parent_id = c2.id 
                       WHERE c1.seller_id IS NULL 
                       ORDER BY c1.parent_id, c1.name");
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get parent categories for dropdown (admin categories only)
$stmt = $pdo->prepare("SELECT * FROM categories WHERE parent_id IS NULL AND seller_id IS NULL ORDER BY name");
$stmt->execute();
$parentCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Analytics filter (seller/customer) - GET params: from, to, entity
$filterFrom = isset($_GET['from']) ? trim($_GET['from']) : '';
$filterTo = isset($_GET['to']) ? trim($_GET['to']) : '';
$filterEntity = isset($_GET['entity']) ? trim($_GET['entity']) : 'all'; // all|sellers|customers

// Normalize dates
if ($filterFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) { $filterFrom = ''; }
if ($filterTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) { $filterTo = ''; }

// Default to current 1-week range if no dates provided
if ($filterFrom === '' && $filterTo === '') {
    $filterFrom = date('Y-m-d', strtotime('-6 days'));
    $filterTo = date('Y-m-d');
}

// Build date range for queries (inclusive range)
$dateCondition = '';
$dateParams = [];
if ($filterFrom !== '' && $filterTo !== '') {
    $dateCondition = " AND DATE(created_at) BETWEEN ? AND ?";
    $dateParams = [$filterFrom, $filterTo];
} elseif ($filterFrom !== '') {
    $dateCondition = " AND DATE(created_at) >= ?";
    $dateParams = [$filterFrom];
} elseif ($filterTo !== '') {
    $dateCondition = " AND DATE(created_at) <= ?";
    $dateParams = [$filterTo];
}

// Compute filtered analytics
$filteredNewSellers = 0; $filteredNewCustomers = 0; $filteredOrders = 0; $filteredRevenue = 0.0; $filteredProducts = 0;

try {
    if ($filterEntity === 'all' || $filterEntity === 'sellers') {
        $q = "SELECT COUNT(*) FROM users WHERE user_type='seller'" . $dateCondition;
        $stmt = $pdo->prepare($q);
        $stmt->execute($dateParams);
        $filteredNewSellers = (int)$stmt->fetchColumn();
    }
    if ($filterEntity === 'all' || $filterEntity === 'customers') {
        $q = "SELECT COUNT(*) FROM users WHERE user_type='customer'" . $dateCondition;
        $stmt = $pdo->prepare($q);
        $stmt->execute($dateParams);
        $filteredNewCustomers = (int)$stmt->fetchColumn();
    }
    // Orders and revenue in date range
    $orderDateCond = '';
    $orderParams = [];
    if ($filterFrom !== '' && $filterTo !== '') { $orderDateCond = " WHERE DATE(created_at) BETWEEN ? AND ?"; $orderParams = [$filterFrom, $filterTo]; }
    elseif ($filterFrom !== '') { $orderDateCond = " WHERE DATE(created_at) >= ?"; $orderParams = [$filterFrom]; }
    elseif ($filterTo !== '') { $orderDateCond = " WHERE DATE(created_at) <= ?"; $orderParams = [$filterTo]; }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders" . $orderDateCond);
    $stmt->execute($orderParams);
    $filteredOrders = (int)$stmt->fetchColumn();

    $revWhere = $orderDateCond === '' ? " WHERE status='delivered'" : $orderDateCond . " AND status='delivered'";
    if ($orderDateCond === '') { $revParams = []; } else { $revParams = $orderParams; }
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM orders" . $revWhere);
    $stmt->execute($revParams);
    $filteredRevenue = (float)$stmt->fetchColumn();

    // Products created in range
    $prodDateCond = '';
    $prodParams = [];
    if ($filterFrom !== '' && $filterTo !== '') { $prodDateCond = " WHERE DATE(created_at) BETWEEN ? AND ?"; $prodParams = [$filterFrom, $filterTo]; }
    elseif ($filterFrom !== '') { $prodDateCond = " WHERE DATE(created_at) >= ?"; $prodParams = [$filterFrom]; }
    elseif ($filterTo !== '') { $prodDateCond = " WHERE DATE(created_at) <= ?"; $prodParams = [$filterTo]; }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products" . $prodDateCond);
    $stmt->execute($prodParams);
    $filteredProducts = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    // fail safe: keep zeros
}


// Add notification script function
function addNotificationScript() {
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            // Hide success messages after 4 seconds
            var successMsg = document.querySelector('.success-message');
            if (successMsg) {
                setTimeout(function() {
                    successMsg.style.display = 'none';
                }, 4000);
            }
            
            // Hide error messages after 4 seconds
            var errorMsg = document.querySelector('.error-message');
            if (errorMsg) {
                setTimeout(function() {
                    errorMsg.style.display = 'none';
                }, 4000);
            }
        });
    </script>";
}
?>

<!-- Analytics Tabs + Filter -->
<div class="container" style="max-width:1400px;margin:0 auto 30px;padding:0 20px;">
  <div class="analytics-tabs">
    <button class="tab-btn" data-target="customers" <?php echo $filterEntity==='customers'?'data-active="1"':''; ?>>CUSTOMER</button>
    <button class="tab-btn" data-target="sellers" <?php echo $filterEntity==='sellers'?'data-active="1"':''; ?>>SELLER</button>
    <a href="admin-dashboard.php" class="tab-close" title="Back to default">✕</a>
  </div>
  <div class="analytics-slider <?php echo $filterEntity==='sellers'?'slide-sellers':'slide-customers'; ?>">
    <!-- Customers Pane -->
    <div class="analytics-pane">
      <div class="analytics-filter">
        <form method="GET" action="admin-dashboard.php" class="analytics-form">
          <input type="hidden" name="entity" value="customers">
          <div class="row">
            <div class="field">
              <label>From</label>
              <input type="date" name="from" value="<?php echo htmlspecialchars($filterFrom); ?>">
            </div>
            <div class="field">
              <label>To</label>
              <input type="date" name="to" value="<?php echo htmlspecialchars($filterTo); ?>">
            </div>
            <div class="actions">
              <button type="submit" class="btn-apply"><i class="fas fa-filter"></i></button>
              <a href="admin-dashboard.php?entity=customers" class="btn-clear" title="Clear"><i class="fas fa-times"></i></a>
            </div>
          </div>
          <div class="quick-range">
            <a href="admin-dashboard.php?entity=customers&from=<?php echo date('Y-m-d'); ?>&to=<?php echo date('Y-m-d'); ?>">Today</a>
            <a href="admin-dashboard.php?entity=customers&from=<?php echo date('Y-m-d', strtotime('-6 days')); ?>&to=<?php echo date('Y-m-d'); ?>">Last 7 days</a>
            <a href="admin-dashboard.php?entity=customers&from=<?php echo date('Y-m-d', strtotime('-29 days')); ?>&to=<?php echo date('Y-m-d'); ?>">Last 30 days</a>
          </div>
        </form>

        
      </div>
    </div>

    <!-- Sellers Pane -->
    <div class="analytics-pane">
      <div class="analytics-filter">
        <form method="GET" action="admin-dashboard.php" class="analytics-form">
          <input type="hidden" name="entity" value="sellers">
          <div class="row">
            <div class="field">
              <label>From</label>
              <input type="date" name="from" value="<?php echo htmlspecialchars($filterFrom); ?>">
            </div>
            <div class="field">
              <label>To</label>
              <input type="date" name="to" value="<?php echo htmlspecialchars($filterTo); ?>">
            </div>
            <div class="actions">
              <button type="submit" class="btn-apply"><i class="fas fa-filter"></i></button>
              <a href="admin-dashboard.php?entity=sellers" class="btn-clear" title="Clear"><i class="fas fa-times"></i></a>
            </div>
          </div>
          <div class="quick-range">
            <a href="admin-dashboard.php?entity=sellers&from=<?php echo date('Y-m-d'); ?>&to=<?php echo date('Y-m-d'); ?>">Today</a>
            <a href="admin-dashboard.php?entity=sellers&from=<?php echo date('Y-m-d', strtotime('-6 days')); ?>&to=<?php echo date('Y-m-d'); ?>">Last 7 days</a>
            <a href="admin-dashboard.php?entity=sellers&from=<?php echo date('Y-m-d', strtotime('-29 days')); ?>&to=<?php echo date('Y-m-d'); ?>">Last 30 days</a>
          </div>
        </form>

        
      </div>
    </div>
  </div>
</div>
<!-- Edit Category Modal -->
<div id="editCategoryModal" class="modal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.3);z-index:9999;align-items:center;justify-content:center;">
  <div class="modal-content" style="background:#fff;padding:32px 28px;border-radius:14px;max-width:400px;margin:auto;box-shadow:0 4px 16px rgba(44,62,80,0.18);position:relative;">
    <span class="close" onclick="closeEditModal()" style="position:absolute;top:12px;right:18px;font-size:22px;cursor:pointer;">&times;</span>
    <h3>Edit Category</h3>
    <form method="POST" action="">
      <input type="hidden" id="edit_category_id" name="category_id">
      
      <div class="form-group">
        <label for="edit_name">Category Name: <span style="color: #d32f2f;">*</span></label>
        <input type="text" id="edit_name" name="name" required placeholder="Enter category name">
      </div>
      
      <div class="form-group">
        <label for="edit_parent_id">Parent Category (Optional):</label>
        <select id="edit_parent_id" name="parent_id">
          <option value="">-- None (Top-Level Category) --</option>
          <?php foreach ($parentCategories as $parent): ?>
            <option value="<?php echo $parent['id']; ?>">
              <?php echo htmlspecialchars($parent['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <small class="form-help">Leave as "None" to make it a main category</small>
      </div>
      
      <button type="submit" name="update_category" class="btn-primary">Update Category</button>
    </form>
  </div>
</div>

<!-- Delete Category Modal -->
<div id="deleteCategoryModal" class="modal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.3);z-index:9999;align-items:center;justify-content:center;">
  <div class="modal-content" style="background:#fff;padding:32px 28px;border-radius:14px;max-width:400px;margin:auto;box-shadow:0 4px 16px rgba(44,62,80,0.18);position:relative;">
    <span class="close" onclick="closeDeleteModal()" style="position:absolute;top:12px;right:18px;font-size:22px;cursor:pointer;">&times;</span>
    <h3>Delete Category</h3>
    <form id="deleteCategoryForm" method="GET" action="admin-dashboard.php">
      <input type="hidden" name="delete_category" id="delete_category_id">
      <p id="deleteCategoryName" style="margin-bottom:18px;"></p>
      <button type="submit" class="btn" style="background:#d32f2f;">Delete</button>
      <button type="button" class="btn" onclick="closeDeleteModal()" style="margin-left:10px;background:#888;">Cancel</button>
    </form>
  </div>
</div>

<?php if (isset($success)): ?>
    <div class="success-message"><?php echo $success; ?></div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="error-message"><?php echo $error; ?></div>
<?php endif; ?>

<div class="stats">
    <div class="stat-card">
        <h3><?php echo $filterEntity==='customers' ? 'New Customers' : ($filterEntity==='sellers' ? 'New Sellers' : 'New Users'); ?></h3>
        <p><?php echo $filterEntity==='customers' ? $filteredNewCustomers : ($filterEntity==='sellers' ? $filteredNewSellers : $filteredNewSellers + $filteredNewCustomers); ?></p>
    </div>
    <?php if ($filterEntity !== 'customers'): ?>
    <div class="stat-card">
        <h3>Products</h3>
        <p><?php echo $filteredProducts; ?></p>
    </div>
    <?php endif; ?>
    <div class="stat-card">
        <h3>Orders</h3>
        <p><?php echo $filteredOrders; ?></p>
    </div>
    <?php if ($filterEntity !== 'customers'): ?>
    <div class="stat-card">
        <h3>Revenue</h3>
        <p>₱<?php echo number_format($filteredRevenue, 2); ?></p>
    </div>
    <div class="stat-card">
        <h3>Pending Sellers</h3>
        <p><?php echo $pendingSellers; ?></p>
    </div>
    <div class="stat-card">
        <h3>Pending Products</h3>
        <p><?php echo $pendingProducts; ?></p>
    </div>
    <div class="stat-card">
        <h3>Active Sellers</h3>
        <p><?php 
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM users WHERE user_type = 'seller' AND seller_status = 'approved'");
        $stmt->execute();
        $activeSellers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        echo $activeSellers; 
        ?></p>
    </div>
    <div class="stat-card">
        <h3>Active Products</h3>
        <p><?php 
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM products WHERE status = 'active'");
        $stmt->execute();
        $activeProducts = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        echo $activeProducts; 
        ?></p>
    </div>
    <?php endif; ?>
    <div class="stat-card">
        <h3>Completed Orders</h3>
        <p><?php 
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM orders WHERE status = 'delivered'");
        $stmt->execute();
        $completedOrders = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        echo $completedOrders; 
        ?></p>
    </div>
</div>



<div class="admin-sections">
    <!-- CATEGORY MANAGEMENT SECTION -->
    <div class="section category-management-section">
        <!-- <h2>Category Management</h2> -->
        <!-- <p class="section-description">Manage global product categories available to all sellers</p>
         -->

<!-- <div class="add-category-form">
    <h3>Add New Category</h3>
    <form method="POST" action="">
        <div class="form-group">
            <label for="name">Category Name: <span style="color: #d32f2f;">*</span></label>
            <input type="text" id="name" name="name" required placeholder="Enter category name">
        </div>
        
        <div class="form-group">
            <label for="parent_id">Parent Category (Optional):</label>
            <select id="parent_id" name="parent_id">
                <option value="">-- None (Top-Level Category) --</option>
                <?php foreach ($parentCategories as $parent): ?>
                    <option value="<?php echo $parent['id']; ?>">
                        <?php echo htmlspecialchars($parent['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="form-help">Leave as "None" to create a main category, or select a parent to create a subcategory</small>
        </div>
        
        <button type="submit" name="add_category" class="btn-primary">Add Category</button>
    </form>
</div> -->

<!-- <div class="categories-list">
    <h3>All Platform Categories</h3>
    <?php if (empty($categories)): ?>
        <p>No categories found. Add your first category above.</p>
    <?php else: ?>
        <div class="categories-table-wrapper">
            <table class="categories-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Parent Category</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $groupedCategories = [];
                foreach ($categories as $category) {
                    $parentId = $category['parent_id'] ? $category['parent_id'] : 0;
                    $groupedCategories[$parentId][] = $category;
                }
                
             function displayCategories($categories, $parentId = 0, $level = 0) {
                if (!isset($categories[$parentId])) return;
                foreach ($categories[$parentId] as $category) {
                    $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level);
                    $hasChildren = isset($categories[$category['id']]) && !empty($categories[$category['id']]);
                    
                    echo '<tr>';
                    echo '<td>' . $indent . htmlspecialchars($category['name']) . '</td>';
                    echo '<td>' . htmlspecialchars($category['parent_name'] ?: 'None') . '</td>';
                    echo '<td class="action-links">';

                echo '<a href="#" class="edit-link" style="color:#1976d2;font-weight:600;padding:4px 10px;border-radius:5px;background:#e3eafc;margin-right:6px;transition:background 0.2s;" onclick="openEditModal(' . $category['id'] . ', \'' . addslashes($category['name']) . '\', ' . ($category['parent_id'] ? $category['parent_id'] : 'null') . '); return false;">Edit</a>';
                        if ($hasChildren) {
                            echo '<a href="#" class="delete-link disabled" style="color:#999;font-weight:600;padding:4px 10px;border-radius:5px;background:#f5f5f5;cursor:not-allowed;" title="Cannot delete category with subcategories">Delete</a>';
                        } else {
                            echo '<a href="#" class="delete-link" style="color:#d32f2f;font-weight:600;padding:4px 10px;border-radius:5px;background:#fdeaea;transition:background 0.2s;" onclick="openDeleteModal(' . $category['id'] . ', \'' . addslashes($category['name']) . '\'); return false;">Delete</a>';
                        }
                    
                    echo '</td>';
                    echo '</tr>';
                    displayCategories($categories, $category['id'], $level + 1);
                }
            }
                
                displayCategories($groupedCategories);
                ?>
            </tbody>
        </table>
    <?php endif; ?>
</div> -->
    </div>
    

   

    <?php if (empty($pendingProductsList)): ?>
        <!-- <p>No pending product approvals.</p> -->
    <?php else: ?>
        <div class="section">
            <h2>Pending Product Approvals</h2>
            <p>Products waiting for admin approval</p>
        </div>
        <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Seller</th>
                        <th>Price</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingProductsList as $product): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($product['name']); ?></td>
                            <td><?php echo htmlspecialchars($product['first_name'] . ' ' . $product['last_name']); ?></td>
                            <td>₱<?php echo number_format($product['price'], 2); ?></td>
                            <td>
                                <a href="admin-products.php?action=approve&id=<?php echo $product['id']; ?>">Approve</a>
                                <a href="admin-products.php?action=reject&id=<?php echo $product['id']; ?>">Reject</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <a href="admin-products.php">View All Pending Products</a>
        <?php endif; ?>
    </div>
</div>

<!-- SETTINGS SECTION -->
<div class="page-header" style="margin-top: 50px;">
    <h1>System Settings</h1>
</div>

<div class="settings-container">
    <form method="POST" action="">
        <div class="setting-group">
            <h3>Order Grace Period</h3>
            <label for="grace_period">Customer Cancellation Grace Period:</label>
            
            <div class="input-group">
                <input type="number" 
                       id="grace_period" 
                       name="grace_period" 
                       value="<?php echo $currentGracePeriod; ?>" 
                       min="1" 
                       max="60" 
                       required>
                <span class="input-suffix">minutes</span>
            </div>
            
            <div class="current-value">
                <strong>Current Setting:</strong> <?php echo $currentGracePeriod; ?> minutes
            </div>
            
            <div class="setting-description">
                This is the time period after an order is placed during which:
                <ul>
                    <li>Customers have priority to cancel their orders</li>
                    <li>Sellers cannot process orders (orders remain in "pending" status)</li>
                    <li>This protects customers from immediate processing and allows cancellation flexibility</li>
                </ul>
            </div>
            
            <div class="warning-box">
                <strong>Important:</strong> Changing this setting will affect all new orders going forward. 
                Existing orders will continue to use the grace period that was active when they were placed.
            </div>
        </div>
        
        <button type="submit" name="update_settings" class="btn">Update Settings</button>
    </form>
    
    <div class="setting-group" style="margin-top: 30px;">
        <h3>How Grace Period Works</h3>
        <div class="setting-description">
            <ol>
                <li><strong>Order Placed:</strong> Customer places an order (status: "pending")</li>
                <li><strong>Grace Period Active:</strong> For the set duration, sellers cannot process the order</li>
                <li><strong>Customer Priority:</strong> During this time, customers can cancel without seller intervention</li>
                <li><strong>Grace Period Ends:</strong> Sellers can now process the order (change to "processing")</li>
                <li><strong>Order Locked:</strong> Once processing starts, customer cancellation requires seller approval</li>
            </ol>
        </div>
    </div>
</div>

<style>
/* ===== IMPROVED ADMIN DASHBOARD - WHITE THEME ===== */

/* Analytics Filter Container */
.analytics-tabs {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 80px;
    max-width: 800px;
    margin: 0 auto 20px;
    position: relative;
    background: #ffffff;
    padding: 16px 24px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 2px solid #f0f2f5;
}

.tab-btn {
    background: transparent;
    border: none;
    color: #130325;
    padding: 8px 20px;
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
    box-shadow: 0 2px 8px rgba(255, 215, 54, 0.3);
}

.tab-close {
    position: absolute;
    right: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    height: 32px;
    width: 32px;
    border-radius: 8px;
    background: #ffffff;
    color: #dc3545;
    border: 2px solid #dc3545;
    font-size: 16px;
    font-weight: 700;
    transition: all 0.2s ease;
}

.tab-close:hover {
    background: #dc3545;
    color: #ffffff;
    transform: scale(1.05);
}

/* Analytics Filter - COMPACT WHITE DESIGN */
.analytics-filter {
    background: #ffffff;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    color: #130325;
    width: 100%;
    max-width: none;
    margin: 0 auto;
}

.analytics-filter::before {
    content: 'FILTER BY DATE RANGE';
    display: block;
    font-size: 11px;
    font-weight: 700;
    color: #6b7280;
    text-align: center;
    margin-bottom: 16px;
    letter-spacing: 1px;
    text-transform: uppercase;
}

.analytics-form .row {
    display: flex;
    align-items: flex-end;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: center;
}

.analytics-form .field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.analytics-form label {
    font-size: 11px;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.analytics-form input[type="date"],
.analytics-form select {
    background: #f9fafb;
    color: #130325;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 13px;
    height: 36px;
    min-width: 140px;
    font-weight: 500;
    transition: all 0.2s ease;
}

.analytics-form input[type="date"]:focus,
.analytics-form select:focus {
    outline: none;
    border-color: #FFD736;
    box-shadow: 0 0 0 3px rgba(255, 215, 54, 0.1);
    background: #ffffff;
}

.analytics-form .actions {
    display: flex;
    align-items: center;
    gap: 6px;
}

.btn-apply {
    background: linear-gradient(135deg, #FFD736 0%, #FFC107 100%);
    color: #130325;
    border: 2px solid #FFD736;
    height: 36px;
    width: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.2s ease;
    box-shadow: 0 2px 6px rgba(255, 215, 54, 0.2);
}

.btn-apply:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(255, 215, 54, 0.3);
}

.btn-clear {
    background: #ffffff;
    color: #6b7280;
    border: 2px solid #e5e7eb;
    height: 36px;
    width: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 700;
    font-size: 14px;
    transition: all 0.2s ease;
}

.btn-clear:hover {
    background: #f3f4f6;
    border-color: #d1d5db;
    transform: translateY(-2px);
}

/* Quick Range Links - MINIMALIST */
.quick-range {
    display: flex;
    gap: 8px;
    margin-top: 14px;
    justify-content: center;
    padding-top: 14px;
    border-top: 1px solid #f3f4f6;
}

.quick-range a {
    color: #130325;
    text-decoration: none;
    font-weight: 600;
    border: 1px solid #e5e7eb;
    padding: 6px 12px;
    border-radius: 6px;
    background: #f9fafb;
    font-size: 11px;
    transition: all 0.2s ease;
}

.quick-range a:hover {
    background: #FFD736;
    border-color: #FFD736;
    color: #130325;
    transform: translateY(-1px);
}

/* Slider Container */
.analytics-slider {
    display: grid;
    grid-template-columns: 100% 100%;
    transition: transform 0.35s ease;
}

.analytics-slider.slide-customers {
    transform: translateX(0);
}

.analytics-slider.slide-sellers {
    transform: translateX(-100%);
}

.analytics-pane {
    padding: 0 6px;
    display: flex;
    justify-content: center;
}

/* Stats Cards - WHITE THEME */
.stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    grid-template-rows: repeat(3, 1fr);
    gap: 16px;
    margin: 24px auto;
    padding: 0 20px;
    max-width: 1400px;
}

.stat-card {
    background: #ffffff !important;
    border: 2px solid #e5e7eb !important;
    border-radius: 12px !important;
    padding: 20px 18px !important;
    text-align: center !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06) !important;
    transition: all 0.3s ease !important;
    min-height: 100px !important;
    display: flex !important;
    flex-direction: column !important;
    justify-content: center !important;
    align-items: center !important;
}

.stat-card:hover {
    transform: translateY(-4px) !important;
    box-shadow: 0 6px 16px rgba(0,0,0,0.1) !important;
    border-color: #FFD736 !important;
}

.stat-card h3 {
    color: #6b7280 !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    margin-bottom: 8px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
}

.stat-card p {
    color: #130325 !important;
    font-size: 24px !important;
    font-weight: 800 !important;
    margin: 0 !important;
}
/* Section Containers - WHITE THEME */
.admin-sections .section,
.category-management-section,
.categories-list,
.add-category-form,
.settings-container,
.setting-group {
    background: #ffffff !important;
    border: 2px solid #e5e7eb !important;
    border-radius: 12px !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06) !important;
    padding: 28px 24px !important;
    margin-bottom: 24px !important;
    color: #130325 !important;
}

.admin-sections .section h2,
.categories-list h3,
.setting-group h3 {
    color: #130325 !important;
    font-size: 18px !important;
    font-weight: 700 !important;
    margin-top: 0 !important;
    margin-bottom: 16px !important;
    border-bottom: 2px solid #f3f4f6 !important;
    padding-bottom: 12px !important;
}

.admin-sections .section p,
.setting-description {
    color: #6b7280 !important;
    font-size: 14px !important;
    line-height: 1.6 !important;
}

/* Tables - WHITE THEME */
.admin-sections table,
.categories-table {
    background: #ffffff !important;
    border: 2px solid #e5e7eb !important;
    border-radius: 10px !important;
    overflow: hidden !important;
    box-shadow: 0 2px 6px rgba(0,0,0,0.04) !important;
}

.admin-sections table thead,
.categories-table thead {
    background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%) !important;
}

.admin-sections table th,
.categories-table th {
    color: #130325 !important;
    font-weight: 700 !important;
    font-size: 12px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    padding: 12px 14px !important;
    border-bottom: 2px solid #e5e7eb !important;
}

.admin-sections table td,
.categories-table td {
    color: #130325 !important;
    font-size: 13px !important;
    padding: 12px 14px !important;
    border-bottom: 1px solid #f3f4f6 !important;
}

.admin-sections table tbody tr:hover,
.categories-table tbody tr:hover {
    background: #f9fafb !important;
}

/* Forms and Inputs - WHITE THEME */
.settings-container input,
.settings-container select,
.settings-container textarea,
.form-group input,
.form-group select,
.form-group textarea {
    background: #ffffff !important;
    border: 2px solid #e5e7eb !important;
    color: #130325 !important;
    padding: 10px 14px !important;
    border-radius: 8px !important;
    font-size: 14px !important;
    transition: all 0.2s ease !important;
}

.settings-container input:focus,
.settings-container select:focus,
.settings-container textarea:focus,
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    border-color: #FFD736 !important;
    box-shadow: 0 0 0 3px rgba(255, 215, 54, 0.1) !important;
    outline: none !important;
}

.form-group label {
    font-size: 13px !important;
    font-weight: 600 !important;
    color: #130325 !important;
    margin-bottom: 8px !important;
}

.input-suffix {
    color: #6b7280 !important;
    font-weight: 600 !important;
    font-size: 14px !important;
}

.current-value {
    background: #f9fafb !important;
    border: 2px solid #e5e7eb !important;
    border-left: 4px solid #FFD736 !important;
    padding: 12px 16px !important;
    border-radius: 8px !important;
    margin-top: 12px !important;
    color: #130325 !important;
    font-size: 14px !important;
}

.warning-box {
    background: #fffbeb !important;
    border: 2px solid #fde68a !important;
    color: #92400e !important;
    padding: 14px 16px !important;
    border-radius: 8px !important;
    margin-top: 16px !important;
    font-size: 13px !important;
}

/* Buttons - WHITE THEME */
.btn,
.btn-primary {
    background: linear-gradient(135deg, #FFD736 0%, #FFC107 100%) !important;
    color: #130325 !important;
    padding: 12px 24px !important;
    border: 2px solid #FFD736 !important;
    border-radius: 8px !important;
    font-size: 14px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
    box-shadow: 0 2px 6px rgba(255, 215, 54, 0.2) !important;
}

.btn:hover,
.btn-primary:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 4px 12px rgba(255, 215, 54, 0.3) !important;
}

/* Alerts - WHITE THEME */
.success-message {
    background: #f0fdf4 !important;
    color: #166534 !important;
    border: 2px solid #86efac !important;
    border-radius: 8px !important;
    padding: 14px 18px !important;
    margin-bottom: 20px !important;
    font-size: 14px !important;
    font-weight: 600 !important;
}

.error-message {
    background: #fef2f2 !important;
    color: #991b1b !important;
    border: 2px solid #fca5a5 !important;
    border-radius: 8px !important;
    padding: 14px 18px !important;
    margin-bottom: 20px !important;
    font-size: 14px !important;
    font-weight: 600 !important;
}

/* Settings Container */
.settings-container {
    max-width: 900px !important;
    margin: 32px auto !important;
    padding: 32px 28px !important;
}

.setting-group {
    margin-bottom: 28px !important;
    padding: 24px 20px !important;
}

.input-group {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 12px;
}

/* Responsive */
@media (max-width: 1200px) {
    .stats {
        grid-template-columns: repeat(2, 1fr);
        grid-template-rows: auto;
    }
}

@media (max-width: 768px) {
    .stats {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    
    .stat-card {
        min-height: 80px !important;
        padding: 16px 14px !important;
    }
    
    .stat-card h3 {
        font-size: 11px !important;
    }
    
    .stat-card p {
        font-size: 20px !important;
    }
    
    .analytics-tabs {
        gap: 40px;
        padding: 12px 16px;
    }
    
    .tab-btn {
        font-size: 12px;
        padding: 6px 14px;
    }
    
    .analytics-filter {
        padding: 16px 18px;
    }
    
    .analytics-form .row {
        gap: 8px;
    }
    
    .analytics-form input[type="date"],
    .analytics-form select {
        min-width: 120px;
        font-size: 12px;
        padding: 6px 10px;
        height: 32px;
    }
    
    .btn-apply,
    .btn-clear {
        height: 32px;
        width: 32px;
        font-size: 12px;
    }
    
    .quick-range {
        gap: 6px;
    }
    
    .quick-range a {
        font-size: 10px;
        padding: 5px 10px;
    }
}

</style>

<script>
// Tabs toggle
document.addEventListener('DOMContentLoaded', function() {
  const tabs = document.querySelectorAll('.tab-btn');
  const slider = document.querySelector('.analytics-slider');
  tabs.forEach(btn => {
    btn.addEventListener('click', () => {
      tabs.forEach(b => b.removeAttribute('data-active'));
      btn.setAttribute('data-active','1');
      const target = btn.getAttribute('data-target');
      if (slider) {
        slider.classList.toggle('slide-sellers', target === 'sellers');
        slider.classList.toggle('slide-customers', target === 'customers');
      }
      const url = new URL(window.location.href);
      url.searchParams.set('entity', target === 'sellers' ? 'sellers' : 'customers');
      window.location.href = url.toString();
    });
  });
});

function openEditModal(id, name, parentId) {
  document.getElementById('edit_category_id').value = id;
  document.getElementById('edit_name').value = name;
  document.getElementById('edit_parent_id').value = parentId;
  document.getElementById('editCategoryModal').style.display = 'flex';
}
function closeEditModal() {
  document.getElementById('editCategoryModal').style.display = 'none';
}
function openDeleteModal(id, name) {
  document.getElementById('delete_category_id').value = id;
  document.getElementById('deleteCategoryName').innerHTML = 'Are you sure you want to delete <strong>' + name + '</strong>?';
  document.getElementById('deleteCategoryModal').style.display = 'flex';
}
function closeDeleteModal() {
  document.getElementById('deleteCategoryModal').style.display = 'none';
}

</script>
<?php addNotificationScript(); ?>
