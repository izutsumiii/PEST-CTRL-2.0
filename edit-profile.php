<?php
require_once 'includes/header.php';
require_once 'config/database.php';

requireLogin();

$userId = $_SESSION['user_id'];

// Get user info
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if user_addresses table exists, if not create it
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_addresses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        address_name VARCHAR(100) NOT NULL COMMENT 'e.g., Home, Work, Office',
        full_name VARCHAR(255) NOT NULL,
        phone VARCHAR(20) NOT NULL,
        address TEXT NOT NULL,
        is_default TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_is_default (is_default)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
} catch (PDOException $e) {
    // Table might already exist, continue
}

// Get user addresses
$addressesStmt = $pdo->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, created_at DESC");
$addressesStmt->execute([$userId]);
$addresses = $addressesStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle address operations
$message = '';
$messageType = '';

if (isset($_POST['add_address'])) {
    $addressName = sanitizeInput($_POST['address_name']);
    $fullName = sanitizeInput($_POST['full_name']);
    $phone = sanitizeInput($_POST['phone']);
    $address = sanitizeInput($_POST['address']);
    $isDefault = isset($_POST['is_default']) ? 1 : 0;
    
    // If setting as default, unset other defaults
    if ($isDefault) {
        $pdo->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?")->execute([$userId]);
    }
    
    $stmt = $pdo->prepare("INSERT INTO user_addresses (user_id, address_name, full_name, phone, address, is_default) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $addressName, $fullName, $phone, $address, $isDefault]);
    
    $message = 'Address added successfully!';
    $messageType = 'success';
    header("Location: edit-profile.php?msg=address_added");
    exit();
}

if (isset($_POST['update_address'])) {
    $addressId = (int)$_POST['address_id'];
    $addressName = sanitizeInput($_POST['address_name']);
    $fullName = sanitizeInput($_POST['full_name']);
    $phone = sanitizeInput($_POST['phone']);
    $address = sanitizeInput($_POST['address']);
    $isDefault = isset($_POST['is_default']) ? 1 : 0;
    
    // Verify address belongs to user
    $checkStmt = $pdo->prepare("SELECT user_id FROM user_addresses WHERE id = ?");
    $checkStmt->execute([$addressId]);
    $addr = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($addr && $addr['user_id'] == $userId) {
        // If setting as default, unset other defaults
        if ($isDefault) {
            $pdo->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ? AND id != ?")->execute([$userId, $addressId]);
        }
        
        $stmt = $pdo->prepare("UPDATE user_addresses SET address_name = ?, full_name = ?, phone = ?, address = ?, is_default = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$addressName, $fullName, $phone, $address, $isDefault, $addressId, $userId]);
        
        $message = 'Address updated successfully!';
        $messageType = 'success';
        header("Location: edit-profile.php?msg=address_updated");
        exit();
    }
}

if (isset($_POST['delete_address'])) {
    $addressId = (int)$_POST['address_id'];
    
    // Verify address belongs to user
    $checkStmt = $pdo->prepare("SELECT user_id FROM user_addresses WHERE id = ?");
    $checkStmt->execute([$addressId]);
    $addr = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($addr && $addr['user_id'] == $userId) {
        $stmt = $pdo->prepare("DELETE FROM user_addresses WHERE id = ? AND user_id = ?");
        $stmt->execute([$addressId, $userId]);
        
        $message = 'Address deleted successfully!';
        $messageType = 'success';
        header("Location: edit-profile.php?msg=address_deleted");
        exit();
    }
}

if (isset($_POST['set_default_address'])) {
    $addressId = (int)$_POST['address_id'];
    
    // Verify address belongs to user
    $checkStmt = $pdo->prepare("SELECT user_id FROM user_addresses WHERE id = ?");
    $checkStmt->execute([$addressId]);
    $addr = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($addr && $addr['user_id'] == $userId) {
        // Unset all defaults
        $pdo->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?")->execute([$userId]);
        // Set this as default
        $pdo->prepare("UPDATE user_addresses SET is_default = 1 WHERE id = ? AND user_id = ?")->execute([$addressId, $userId]);
        
        $message = 'Default address updated!';
        $messageType = 'success';
        header("Location: edit-profile.php?msg=default_updated");
        exit();
    }
}

// Handle profile update
if (isset($_POST['update_profile'])) {
    $username = sanitizeInput($_POST['username']);
    $firstName = sanitizeInput($_POST['first_name']);
    $lastName = sanitizeInput($_POST['last_name']);
    $email = sanitizeInput($_POST['email']);
    $phone = sanitizeInput($_POST['phone']);
    
    // Check if email already exists (excluding current user)
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$email, $userId]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if username already exists (excluding current user)
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND id != ?");
    $stmt->execute([$username, $userId]);
    $existingUsername = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingUser) {
        $message = 'Error: Email already exists.';
        $messageType = 'error';
    } elseif ($existingUsername) {
        $message = 'Error: Username already exists.';
        $messageType = 'error';
    } else {
        $stmt = $pdo->prepare("UPDATE users SET username = ?, first_name = ?, last_name = ?, email = ?, phone = ? WHERE id = ?");
        $stmt->execute([$username, $firstName, $lastName, $email, $phone, $userId]);
        
        $message = 'Profile updated successfully!';
        $messageType = 'success';
        header("Location: edit-profile.php?msg=profile_updated");
        exit();
    }
}

// Handle password change
if (isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    // Verify current password
    if (password_verify($currentPassword, $user['password'])) {
        if ($newPassword === $confirmPassword) {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashedPassword, $userId]);
            
            $message = 'Password changed successfully!';
            $messageType = 'success';
            header("Location: edit-profile.php?msg=password_updated");
            exit();
        } else {
            $message = 'Error: New passwords do not match.';
            $messageType = 'error';
        }
    } else {
        $message = 'Error: Current password is incorrect.';
        $messageType = 'error';
    }
}

// Get updated addresses after operations
$addressesStmt = $pdo->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, created_at DESC");
$addressesStmt->execute([$userId]);
$addresses = $addressesStmt->fetchAll(PDO::FETCH_ASSOC);

// Check for URL messages
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'address_added':
        case 'address_updated':
        case 'address_deleted':
        case 'default_updated':
        case 'profile_updated':
        case 'password_updated':
            $message = 'Operation completed successfully!';
            $messageType = 'success';
            break;
    }
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
    color: var(--text-dark); 
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}

.profile-container {
    max-width: 1600px;
    margin: 0 auto;
    padding: 12px 20px;
}

.page-title,
h1.page-title {
    color: var(--text-dark);
    margin: 0 0 16px 0;
    font-size: 1.35rem;
    font-weight: 600;
    letter-spacing: -0.3px;
    line-height: 1.2;
}

.alert {
    padding: 10px 14px;
    border-radius: 8px;
    margin-bottom: 14px;
    border-left: 4px solid;
    font-size: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.alert-success {
    background-color: #d1fae5;
    border-left-color: var(--success-green);
    border: 1px solid #a7f3d0;
    color: #065f46;
}

.alert-error {
    background-color: #fef2f2;
    border-left-color: var(--error-red);
    border: 1px solid #fecaca;
    color: #991b1b;
}

.profile-content {
    display: grid;
    grid-template-columns: 1fr 1.2fr;
    gap: 16px;
    margin-top: 12px;
}

.profile-section {
    background-color: var(--bg-white);
    border: 1px solid var(--border-light);
    padding: 14px 16px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.profile-section h2 {
    color: var(--text-dark);
    margin-bottom: 12px;
    font-weight: 600;
    font-size: 1.1rem;
    letter-spacing: -0.3px;
}

.profile-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 10px;
    margin-bottom: 14px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.profile-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    border: 2px solid var(--primary-dark);
    background: rgba(19, 3, 37, 0.05);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--primary-dark);
    font-weight: 700;
    font-size: 0.9rem;
}

.profile-meta {
    flex: 1;
}

.profile-name {
    color: var(--text-dark);
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.3;
}

.profile-email {
    color: var(--text-light);
    font-size: 0.85rem;
}

.form-group {
    margin-bottom: 14px;
}

.form-group label {
    color: var(--text-dark);
    font-weight: 600;
    margin-bottom: 5px;
    display: block;
    font-size: 12px;
}

.form-group input,
.form-group textarea,
.form-group select {
    width: 100%;
    padding: 8px 10px;
    border: 1.5px solid var(--border-light);
    border-radius: 8px;
    font-size: 13px;
    background: var(--bg-white);
    color: var(--text-dark);
    transition: all 0.2s ease;
    font-family: inherit;
    box-sizing: border-box;
}

.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {
    outline: none;
    border-color: var(--primary-dark);
    box-shadow: 0 0 0 3px rgba(19, 3, 37, 0.1);
}

.form-group textarea {
    min-height: 80px;
    resize: vertical;
}

.btn {
    padding: 8px 14px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 12px;
    text-decoration: none;
    text-align: center;
    transition: all 0.2s ease;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    font-family: inherit;
}

.btn-primary {
    background-color: var(--primary-dark);
    color: white;
}

.btn-primary:hover {
    background-color: #0a0118;
    transform: translateY(-1px);
}

.btn-yellow {
    background-color: var(--primary-dark);
    color: var(--bg-white);
}

.btn-yellow:hover {
    background-color: #0a0118;
    transform: translateY(-1px);
}

.btn-secondary {
    background-color: var(--text-light);
    color: white;
}

.btn-secondary:hover {
    background-color: #4b5563;
    transform: translateY(-1px);
}

.btn-danger {
    background-color: var(--error-red);
    color: white;
}

.btn-danger:hover {
    background-color: #dc2626;
    transform: translateY(-1px);
}

.btn-sm {
    padding: 6px 12px;
    font-size: 11px;
}

.form-actions {
    display: flex;
    gap: 8px;
    margin-top: 14px;
}

/* Address Cards */
.addresses-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 16px;
}

.address-card {
    border: 1.5px solid var(--border-light);
    border-radius: 10px;
    padding: 12px;
    background: var(--bg-white);
    transition: all 0.2s ease;
    position: relative;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.address-card:hover {
    border-color: var(--primary-dark);
    background: rgba(19, 3, 37, 0.02);
    box-shadow: 0 2px 6px rgba(19, 3, 37, 0.1);
}

.address-card.default {
    border-color: var(--primary-dark);
    background: rgba(19, 3, 37, 0.03);
}

.address-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}

.address-name {
    font-weight: 700;
    font-size: 14px;
    color: var(--text-dark);
    display: flex;
    align-items: center;
    gap: 6px;
}

.default-badge {
    background: var(--primary-dark);
    color: var(--bg-white);
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
}

.address-details {
    color: var(--text-light);
    font-size: 13px;
    line-height: 1.5;
    margin-bottom: 10px;
}

.address-actions {
    display: flex;
    gap: 8px;
    margin-top: 12px;
    flex-wrap: wrap;
}

.add-address-btn {
    width: 100%;
    padding: 10px;
    border: 2px dashed var(--border-light);
    border-radius: 10px;
    background: var(--bg-white);
    color: var(--text-dark);
    font-weight: 600;
    font-size: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    margin-top: 12px;
}

.add-address-btn:hover {
    border-color: var(--primary-dark);
    background: rgba(19, 3, 37, 0.02);
}

/* Modal */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 10000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal-content {
    background: white;
    border-radius: 12px;
    width: 100%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
    border-top: 3px solid var(--primary-dark);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 18px;
    border-bottom: 1px solid var(--border-light);
    background: var(--bg-white);
}

.modal-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: var(--text-dark);
}

.modal-close {
    background: var(--bg-light);
    border: none;
    font-size: 24px;
    color: var(--text-dark);
    cursor: pointer;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    transition: all 0.2s ease;
}

.modal-close:hover {
    background: var(--border-light);
}

.modal-body {
    padding: 16px 18px;
}

.checkbox-group {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 8px;
}

.checkbox-group input[type="checkbox"] {
    width: auto;
    accent-color: var(--primary-dark);
}

.checkbox-group label {
    margin: 0;
    font-weight: 500;
    cursor: pointer;
}

@media (max-width: 968px) {
    .profile-container {
        padding: 10px 12px;
        max-width: 100%;
    }
    
    .profile-content {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    
    .profile-section {
        padding: 12px 14px;
    }
    
    .form-actions {
        flex-direction: column;
        gap: 6px;
    }
    
    .form-actions .btn {
        width: 100%;
    }
    
    .page-title,
    h1.page-title {
        font-size: 1.2rem;
        margin-bottom: 12px;
    }
    
    .profile-section h2 {
        font-size: 1rem;
        margin-bottom: 10px;
    }
    
    .profile-header {
        padding: 10px 12px;
    }
    
    .profile-avatar {
        width: 40px;
        height: 40px;
        font-size: 0.85rem;
    }
    
    .profile-name {
        font-size: 0.95rem;
    }
    
    .profile-email {
        font-size: 0.8rem;
    }
}
</style>

<main style="background: var(--bg-light); min-height: 100vh; padding: 0;">
<div class="profile-container">
    <h1 class="page-title">My Profile</h1>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="profile-header">
        <div class="profile-avatar">
            <?php 
                $initials = strtoupper(substr($user['first_name'] ?? 'U', 0, 1) . substr($user['last_name'] ?? 'S', 0, 1));
                echo htmlspecialchars($initials);
            ?>
        </div>
        <div class="profile-meta">
            <div class="profile-name"><?php echo htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: ($user['username'] ?? 'User')); ?></div>
            <div class="profile-email"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
        </div>
    </div>

    <div class="profile-content">
        <!-- Personal Information -->
        <div class="profile-section">
            <h2>Personal Information</h2>
            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="phone">Phone</label>
                    <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                </div>
                <div class="form-actions">
                    <button type="submit" name="update_profile" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>

            <h2 style="margin-top: 40px;">Change Password</h2>
            <form method="POST" action="">
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
                <div class="form-actions">
                    <button type="submit" name="change_password" class="btn btn-primary">
                        <i class="fas fa-key"></i> Update Password
                    </button>
                </div>
            </form>
        </div>

        <!-- Addresses -->
        <div class="profile-section">
            <h2>My Addresses</h2>
            <div class="addresses-list">
                <?php if (empty($addresses)): ?>
                    <p style="color: var(--text-light); text-align: center; padding: 40px 0;">No addresses saved yet. Add your first address below.</p>
                <?php else: ?>
                    <?php foreach ($addresses as $addr): ?>
                        <div class="address-card <?php echo $addr['is_default'] ? 'default' : ''; ?>">
                            <div class="address-header">
                                <div class="address-name">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($addr['address_name']); ?>
                                    <?php if ($addr['is_default']): ?>
                                        <span class="default-badge">Default</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="address-details">
                                <div><strong><?php echo htmlspecialchars($addr['full_name']); ?></strong></div>
                                <div><?php echo htmlspecialchars($addr['phone']); ?></div>
                                <div style="margin-top: 8px;"><?php echo nl2br(htmlspecialchars($addr['address'])); ?></div>
                            </div>
                            <div class="address-actions">
                                <?php if (!$addr['is_default']): ?>
                                    <form method="POST" action="" style="display: inline;">
                                        <input type="hidden" name="address_id" value="<?php echo $addr['id']; ?>">
                                        <button type="submit" name="set_default_address" class="btn btn-yellow btn-sm">
                                            <i class="fas fa-star"></i> Set as Default
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <button type="button" onclick="editAddress(<?php echo htmlspecialchars(json_encode($addr)); ?>)" class="btn btn-primary btn-sm">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this address?');">
                                    <input type="hidden" name="address_id" value="<?php echo $addr['id']; ?>">
                                    <button type="submit" name="delete_address" class="btn btn-danger btn-sm">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <button type="button" onclick="showAddAddressModal()" class="add-address-btn">
                    <i class="fas fa-plus"></i> Add New Address
                </button>
            </div>
        </div>
    </div>
</div>
</main>

<!-- Add/Edit Address Modal -->
<div id="addressModal" class="modal-overlay" onclick="if(event.target === this) closeAddressModal()">
    <div class="modal-content" onclick="event.stopPropagation()">
        <div class="modal-header">
            <h3 id="modalTitle">Add New Address</h3>
            <button type="button" class="modal-close" onclick="closeAddressModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="" id="addressForm">
                <input type="hidden" name="address_id" id="address_id">
                <div class="form-group">
                    <label for="address_name">Address Name *</label>
                    <select id="address_name" name="address_name" required>
                        <option value="">Select...</option>
                        <option value="Home">Home</option>
                        <option value="Work">Work</option>
                        <option value="Office">Office</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="full_name">Full Name *</label>
                    <input type="text" id="full_name" name="full_name" required>
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number *</label>
                    <input type="tel" id="phone" name="phone" required>
                </div>
                <div class="form-group">
                    <label for="address">Address *</label>
                    <textarea id="address" name="address" required></textarea>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" id="is_default" name="is_default">
                    <label for="is_default">Set as default address</label>
                </div>
                <div class="form-actions">
                    <button type="submit" name="add_address" id="submitBtn" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Address
                    </button>
                    <button type="button" onclick="closeAddressModal()" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showAddAddressModal() {
    document.getElementById('modalTitle').textContent = 'Add New Address';
    document.getElementById('addressForm').reset();
    document.getElementById('address_id').value = '';
    document.getElementById('submitBtn').name = 'add_address';
    document.getElementById('addressModal').style.display = 'flex';
}

function editAddress(address) {
    document.getElementById('modalTitle').textContent = 'Edit Address';
    document.getElementById('address_id').value = address.id;
    document.getElementById('address_name').value = address.address_name;
    document.getElementById('full_name').value = address.full_name;
    document.getElementById('phone').value = address.phone;
    document.getElementById('address').value = address.address;
    document.getElementById('is_default').checked = address.is_default == 1;
    document.getElementById('submitBtn').name = 'update_address';
    document.getElementById('addressModal').style.display = 'flex';
}

function closeAddressModal() {
    document.getElementById('addressModal').style.display = 'none';
    document.getElementById('addressForm').reset();
}
</script>

<?php require_once 'includes/footer.php'; ?>
