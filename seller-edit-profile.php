<?php
require_once 'includes/seller_header.php';
require_once 'config/database.php';

requireSeller();

$userId = $_SESSION['user_id'];

// Get seller info
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND user_type = 'seller'");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle profile update
$updateSuccess = false;
$updateError = '';

if (isset($_POST['update_profile'])) {
    $firstName = sanitizeInput($_POST['first_name']);
    $lastName = sanitizeInput($_POST['last_name']);
    $displayName = sanitizeInput($_POST['display_name']);
    $email = sanitizeInput($_POST['email']);
    $phone = sanitizeInput($_POST['phone']);
    $address = sanitizeInput($_POST['address']);

    // check email unique (exclude self)
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$email, $userId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $updateError = "Error: Email already exists.";
    } else {
        // Check if display_name column exists, if not, add it
        try {
            $checkCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'display_name'");
            if ($checkCol->rowCount() == 0) {
                $pdo->exec("ALTER TABLE users ADD COLUMN display_name VARCHAR(100) NULL AFTER last_name");
            }
        } catch (PDOException $e) {
            // Column might already exist or error, continue
        }
        
        $stmt = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, display_name = ?, email = ?, phone = ?, address = ? WHERE id = ?");
        if ($stmt->execute([$firstName, $lastName, $displayName, $email, $phone, $address, $userId])) {
            $updateSuccess = true;
            // Refresh user data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND user_type = 'seller'");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $updateError = "Error updating profile. Please try again.";
        }
    }
}

// Handle password change
if (isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!$user) {
        echo "<p class=\"error-message\">User not found.</p>";
    } elseif (!password_verify($currentPassword, $user['password'])) {
        echo "<p class=\"error-message\">Error: Current password is incorrect.</p>";
    } elseif ($newPassword !== $confirmPassword) {
        echo "<p class=\"error-message\">Error: New passwords do not match.</p>";
    } else {
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashed, $userId]);
        echo "<p class=\"success-message\">Password changed successfully!</p>";
    }
}
?>

<style>
/* Force body and html to have full background coverage */
html, body {
    margin: 0;
    padding: 0;
    min-height: 100vh;
    background: #f0f2f5 !important;
}

/* Main container styling */
main {
    background: transparent !important;
    margin-left: 120px !important;
    margin-top: -20px !important;
    padding: 5px 0 60px 0 !important;
    min-height: calc(100vh - 60px) !important;
    transition: margin-left 0.3s ease !important;
}

main.sidebar-collapsed {
    margin-left: 0px !important;
}

/* Edit Profile Styles - aligned with customer edit profile design */

.profile-header {
    max-width: 1000px;
    margin: 0 auto 20px auto;
    padding: 20px;
    background: #ffffff;
    border: 1px solid rgba(0, 0, 0, 0.1);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    gap: 16px;
}

.profile-avatar {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    border: 3px solid #130325;
    background: #F3F3F3;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #130325;
    font-weight: 800;
    font-size: 1.1rem;
}

.profile-meta {
    display: flex;
    flex-direction: column;
}

.profile-name {
    color: #130325;
    font-size: 1.1rem;
    font-weight: 700;
    line-height: 1.2;
}

.profile-email {
    color: #6b7280;
    font-size: 0.9rem;
}

/* Compact header action buttons */
#editAccountBtn,
#changePasswordBtn {
    padding: 6px 12px !important;
    font-size: 0.85rem !important;
    border-radius: 6px !important;
}

/* Action toggle (single icon button) */
.action-toggle-btn {
    background: #FFD736;
    color: #130325;
    border: 2px solid #FFD736;
    border-radius: 8px;
    width: 36px;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

.profile-actions-menu {
    position: absolute;
    right: 0;
    top: 42px;
    background: #ffffff;
    border: 1px solid rgba(0,0,0,0.1);
    border-radius: 8px;
    box-shadow: 0 8px 16px rgba(0,0,0,0.08);
    padding: 6px;
    min-width: 180px;
    z-index: 1000;
    display: none;
}

.profile-actions-menu button {
    width: 100%;
    background: transparent;
    color: #130325;
    border: none;
    text-align: left;
    padding: 8px 10px;
    border-radius: 6px;
    font-size: 0.9rem;
}

.profile-actions-menu button:hover {
    background: #F0F2F5;
}

.profile-editor h1,
.profile-editor h2,
.profile-editor h3 {
    color: #130325;
    text-align: left;
    margin: 20px 0;
    font-size: 2rem;
}

/* Main profile editor container */
.profile-editor {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 40px;
    max-width: 1000px;
    margin: 0 auto 20px auto;
    padding: 0 20px;
}

/* Section styling */
.personal-info,
.password-change {
    background: #ffffff;
    padding: 25px;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid rgba(0,0,0,0.1);
}

.personal-info h2,
.password-change h2 {
    color: #130325;
    margin-bottom: 20px;
    font-size: 1.5rem;
    padding-bottom: 10px;
    border-bottom: 2px solid #F0F2F5;
}

/* Form styling (scoped to profile editor to avoid affecting header search) */
.profile-editor form {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.profile-editor form div {
    display: flex;
    flex-direction: column;
}

/* Button container should be side by side */
.profile-editor form .button-container {
    flex-direction: row !important;
}

/* Label styling */
.profile-editor label {
    margin-bottom: 5px;
    color: #130325;
    font-weight: 500;
    font-size: 1rem;
}

/* Input styling */
.profile-editor input[type="text"],
.profile-editor input[type="email"],
.profile-editor input[type="tel"],
.profile-editor input[type="password"],
.profile-editor textarea {
    padding: 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 1rem;
    transition: border-color 0.3s;
    background: #ffffff;
    color: #130325;
}

.profile-editor input:focus,
.profile-editor textarea:focus {
    outline: none;
    border-color: #FFD736;
    box-shadow: 0 0 0 3px rgba(255, 215, 54, 0.3);
}

/* Textarea specific styling */
.profile-editor textarea {
    min-height: 80px;
    resize: vertical;
    font-family: inherit;
}

/* Button styling - Minimized */
.profile-editor button[type="submit"] {
    background: #FFD736;
    color: #130325;
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    cursor: pointer;
    transition: background-color 0.3s, transform 0.2s;
    margin-top: 0;
    font-weight: 600;
}

.profile-editor button[type="submit"]:hover {
    background: #e6c230;
    transform: translateY(-1px);
}

.profile-editor button[type="submit"]:active {
    transform: translateY(1px);
}

.btn-secondary {
    background: #6c757d;
    color: #fff;
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    cursor: pointer;
    transition: background-color 0.3s;
    font-weight: 600;
}

.btn-secondary:hover { 
    background: #5a6268; 
}

.account-summary { 
    display: grid; 
    grid-template-columns: repeat(2, minmax(260px, 1fr));
    gap: 12px 20px; 
}

.summary-row { 
    display: flex; 
    justify-content: space-between; 
    gap: 10px; 
    color: #130325; 
    padding: 8px 0;
    border-bottom: 1px solid #F0F2F5;
    background: #ffffff;
}

.summary-row:last-child {
    border-bottom: none;
}

.summary-row strong { 
    color: #374151; 
}

.account-actions { 
    display: flex; 
    gap: 10px; 
    margin-top: 15px; 
}

.account-actions button {
    background: #FFD736;
    color: #130325;
    padding: 12px 24px;
    border: none;
    border-radius: 4px;
    font-size: 1rem;
    cursor: pointer;
    transition: background-color 0.3s, transform 0.2s;
    font-weight: 600;
    flex: 1;
}

.account-actions button:hover {
    background: #e6c230;
    transform: translateY(-1px);
}

.hint { 
    color: #130325;
    opacity: 1; 
    font-size: 0.9rem; 
    margin-top: 8px; 
    text-align: center;
    padding: 15px;
    background: rgba(19, 3, 37, 0.08);
    border-radius: 4px;
}


/* Success/Error message styling */
.profile-editor p {
    padding: 10px;
    border-radius: 5px;
    margin: 10px 0;
    text-align: center;
    font-weight: 500;
}

/* Success message (would need to be added via PHP class) */
.success-message {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

/* Error message (would need to be added via PHP class) */
.error-message {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

/* Page title styling */
h1 {
    color: #130325 !important;
    text-align: left;
    margin: 0 0 16px 0 !important;
    font-size: 20px !important;
    font-weight: 700;
    padding: 0 !important;
    text-shadow: none !important;
}

/* Responsive design */
@media (max-width: 768px) {
    main {
        margin-left: 0 !important;
        padding: 20px 15px 60px 15px !important;
    }
    
    .profile-editor {
        grid-template-columns: 1fr;
        gap: 20px;
        margin: 10px;
        padding: 0 10px;
    }
    
    .personal-info,
    .password-change {
        padding: 20px;
    }
    
    h1 {
        font-size: 18px !important;
        margin: 0 0 16px 0 !important;
    }
    
    .personal-info h2,
    .password-change h2 {
        font-size: 1.3rem;
    }

    .account-actions {
        flex-direction: column;
    }

    .account-actions button {
        flex: none;
        width: 100%;
    }
}

@media (max-width: 480px) {
    .personal-info,
    .password-change {
        padding: 15px;
    }
    
    .profile-editor input[type="text"],
    .profile-editor input[type="email"],
    .profile-editor input[type="tel"],
    .profile-editor input[type="password"],
    .profile-editor textarea {
        padding: 10px;
    }
    
    .profile-editor button[type="submit"],
    .btn-secondary {
        padding: 8px 16px;
        font-size: 12px;
    }

    h1 {
        font-size: 16px !important;
    }
}
</style>

<main>
<div style="max-width: 1200px; margin: 0 auto; padding: 0 30px 24px 30px;">
<h1>My Account</h1>

<div class="profile-header">
    <div class="profile-avatar">
        <?php 
            $initials = strtoupper(substr($user['first_name'] ?? 'U', 0, 1) . substr($user['last_name'] ?? 'S', 0, 1));
            echo htmlspecialchars($initials);
        ?>
    </div>
    <div class="profile-meta">
        <div class="profile-name"><?php echo htmlspecialchars($user['display_name'] ?? trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Seller'); ?></div>
        <div class="profile-email"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
    </div>
    <div style="margin-left:auto; position: relative;">
        <button id="actionsToggle" type="button" class="action-toggle-btn" title="Actions">
            <i class="fas fa-edit"></i>
        </button>
        <div id="actionsMenu" class="profile-actions-menu">
            <button id="editAccountBtn" type="button"><i class="fas fa-user-cog" style="margin-right:8px;"></i>Edit Account</button>
            <button id="changePasswordBtn" type="button"><i class="fas fa-key" style="margin-right:8px;"></i>Change Password</button>
        </div>
    </div>
</div>

<div class="profile-editor">
    <div class="personal-info">
        <h2>Account Details</h2>
        <div class="account-summary">
            <div class="summary-row"><strong>Display Name:</strong> <span><?php echo htmlspecialchars($user['display_name'] ?? 'Not set'); ?></span></div>
            <div class="summary-row"><strong>Full Name:</strong> <span><?php echo htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))); ?></span></div>
            <div class="summary-row"><strong>Email:</strong> <span><?php echo htmlspecialchars($user['email'] ?? ''); ?></span></div>
            <div class="summary-row"><strong>Phone:</strong> <span><?php echo htmlspecialchars($user['phone'] ?? ''); ?></span></div>
            <div class="summary-row"><strong>Address:</strong> <span><?php echo htmlspecialchars($user['address'] ?? ''); ?></span></div>
        </div>
        <div class="account-actions" style="display:none;">
            <button id="editAccountBtn2" type="button">Edit Account</button>
            <button id="changePasswordBtn2" type="button">Change Password</button>
        </div>

        <!-- Hidden Edit Form -->
        <form id="editSellerForm" method="POST" action="" style="display:none; margin-top:20px;">
            <div>
                <label for="display_name">Display Name (Brand Name) *:</label>
                <input type="text" id="display_name" name="display_name" value="<?php echo htmlspecialchars($user['display_name'] ?? ''); ?>" required placeholder="Your brand/store name">
                <small style="color: #6b7280; font-size: 12px;">This is the name customers will see on your store page</small>
            </div>
            <div>
                <label for="first_name">First Name:</label>
                <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>">
            </div>
            <div>
                <label for="last_name">Last Name:</label>
                <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>">
            </div>
            <div>
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
            </div>
            <div>
                <label for="phone">Phone:</label>
                <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
            </div>
            <div>
                <label for="address">Address:</label>
                <textarea id="address" name="address"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
            </div><br>
            <div class="button-container" style="display:flex; flex-direction:row; gap:8px; align-items:center;">
                <button type="submit" name="update_profile">Save Changes</button>
                <button type="button" id="cancelEditBtn" class="btn-secondary">Cancel</button>
            </div>
        </form>
    </div>
    
    <div class="password-change">
        <h2>Change Password</h2>
        <div id="passwordHint" class="hint">Click "Change Password" button on the left to update your password.</div>
        <form id="changeSellerPasswordForm" method="POST" action="" style="display:none; margin-top: 20px;">
            <div>
                <label for="current_password">Current Password:</label>
                <input type="password" id="current_password" name="current_password" required>
            </div>
            <div>
                <label for="new_password">New Password:</label>
                <input type="password" id="new_password" name="new_password" required>
            </div>
            <div>
                <label for="confirm_password">Confirm New Password:</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            <div class="button-container" style="display:flex; flex-direction:row; gap:8px; align-items:center;">
                <button type="submit" name="change_password">Update Password</button>
                <button type="button" id="cancelPwBtn" class="btn-secondary">Cancel</button>
            </div>
        </form>
    </div>

 </div>
</div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle sidebar state for main content positioning
    const main = document.querySelector('main');
    const sidebar = document.getElementById('sellerSidebar');
    
    function updateMainMargin() {
        if (sidebar && sidebar.classList.contains('collapsed')) {
            main.classList.add('sidebar-collapsed');
        } else {
            main.classList.remove('sidebar-collapsed');
        }
    }
    
    // Check initial state
    updateMainMargin();
    
    // Listen for sidebar changes
    const observer = new MutationObserver(updateMainMargin);
    if (sidebar) {
        observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
    }
    
    // Also listen for the hamburger button clicks as backup
    const hamburgerBtn = document.querySelector('.header-hamburger');
    if (hamburgerBtn) {
        hamburgerBtn.addEventListener('click', function() {
            setTimeout(updateMainMargin, 100);
        });
    }
    
    const editBtn = document.getElementById('editAccountBtn');
    const editBtn2 = document.getElementById('editAccountBtn2');
    const changePwBtn = document.getElementById('changePasswordBtn');
    const changePwBtn2 = document.getElementById('changePasswordBtn2');
    const editForm = document.getElementById('editSellerForm');
    const pwForm = document.getElementById('changeSellerPasswordForm');
    const cancelEdit = document.getElementById('cancelEditBtn');
    const cancelPw = document.getElementById('cancelPwBtn');
    const pwHint = document.getElementById('passwordHint');
    const actionsToggle = document.getElementById('actionsToggle');
    const actionsMenu = document.getElementById('actionsMenu');

    function showEditForm() {
        editForm.style.display = (editForm.style.display !== 'none') ? 'none' : 'block';
        pwForm.style.display = 'none';
        if (pwHint) pwHint.style.display = 'block';
        if (actionsMenu) actionsMenu.style.display = 'none';
    }
    
    function showPasswordForm() {
        const show = pwForm.style.display === 'none';
        pwForm.style.display = show ? 'block' : 'none';
        pwHint.style.display = show ? 'none' : 'block';
        editForm.style.display = 'none';
        if (actionsMenu) actionsMenu.style.display = 'none';
    }

    if (editBtn && editForm) {
        editBtn.addEventListener('click', showEditForm);
    }
    if (editBtn2 && editForm) {
        editBtn2.addEventListener('click', showEditForm);
    }
    
    if (changePwBtn && pwForm && pwHint) {
        changePwBtn.addEventListener('click', showPasswordForm);
    }
    if (changePwBtn2 && pwForm && pwHint) {
        changePwBtn2.addEventListener('click', showPasswordForm);
    }
    
    if (cancelEdit && editForm) {
        cancelEdit.addEventListener('click', function(){ 
            editForm.style.display = 'none'; 
        });
    }
    
    if (cancelPw && pwForm && pwHint) {
        cancelPw.addEventListener('click', function(){ 
            pwForm.style.display = 'none'; 
            pwHint.style.display = 'block'; 
        });
    }

    // Toggle actions menu
    if (actionsToggle && actionsMenu) {
        actionsToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            actionsMenu.style.display = (actionsMenu.style.display === 'block') ? 'none' : 'block';
        });
        document.addEventListener('click', function(e) {
            if (!actionsMenu.contains(e.target) && e.target !== actionsToggle) {
                actionsMenu.style.display = 'none';
            }
        });
    }
    
    // Show success/error notification
    <?php if ($updateSuccess): ?>
        showProfileNotification('Profile updated successfully!', 'success');
    <?php elseif (!empty($updateError)): ?>
        showProfileNotification('<?php echo htmlspecialchars($updateError); ?>', 'error');
    <?php endif; ?>
});

// Profile update notification popup (bottom center)
function showProfileNotification(message, type) {
    // Remove existing notification if any
    const existing = document.getElementById('profileUpdateNotification');
    if (existing) {
        existing.remove();
    }
    
    const notification = document.createElement('div');
    notification.id = 'profileUpdateNotification';
    notification.style.cssText = `
        position: fixed;
        bottom: 40px;
        left: 50%;
        transform: translateX(-50%);
        background: #ffffff;
        color: ${type === 'success' ? '#166534' : '#991b1b'};
        border: none;
        border-radius: 12px;
        padding: 16px 24px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        z-index: 10000;
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 14px;
        font-weight: 600;
        animation: slideUpNotification 0.3s ease;
        max-width: 400px;
        min-width: 300px;
    `;
    
    const icon = document.createElement('i');
    icon.className = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle';
    icon.style.cssText = `font-size: 20px; color: ${type === 'success' ? '#10b981' : '#dc3545'};`;
    
    const text = document.createElement('span');
    text.textContent = message;
    
    notification.appendChild(icon);
    notification.appendChild(text);
    document.body.appendChild(notification);
    
    // Auto remove after 3 seconds
    setTimeout(() => {
        notification.style.animation = 'slideDownNotification 0.3s ease';
        setTimeout(() => {
            notification.remove();
        }, 300);
    }, 3000);
}

// Add CSS animations
if (!document.getElementById('profileNotificationStyles')) {
    const style = document.createElement('style');
    style.id = 'profileNotificationStyles';
    style.textContent = `
        @keyframes slideUpNotification {
            from {
                opacity: 0;
                transform: translateX(-50%) translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateX(-50%) translateY(0);
            }
        }
        @keyframes slideDownNotification {
            from {
                opacity: 1;
                transform: translateX(-50%) translateY(0);
            }
            to {
                opacity: 0;
                transform: translateX(-50%) translateY(20px);
            }
        }
    `;
    document.head.appendChild(style);
}
</script>

