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
/* CSS Variables - Customer-side design system */
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

/* Base styles */
html, body {
    margin: 0;
    padding: 0;
    min-height: 100vh;
    background: var(--bg-light) !important;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    color: var(--text-dark);
}

/* Main layout with sidebar positioning */
main {
    background: transparent !important;
    margin-left: 240px;
    margin-top: 8px;
    padding: 6px;
    min-height: calc(100vh - 60px);
    transition: margin-left 0.3s ease;
}

main.sidebar-collapsed {
    margin-left: 70px;
}

@media (max-width: 1024px) {
    main {
        margin-left: 70px;
    }
}

@media (max-width: 768px) {
    main {
        margin-left: 0 !important;
        margin-top: 10px;
        padding: 6px 3px;
    }
}

@media (max-width: 480px) {
    main {
        margin-top: 8px;
        padding: 4px 2px;
    }
}

@media (max-width: 360px) {
    main {
        margin-top: 6px;
        padding: 2px 1px;
    }
}

/* Profile container - compact and modern */
.profile-container {
    max-width: 1400px;
    margin: 0;
    margin-left: -220px;
    padding: 0 16px;
}

main.sidebar-collapsed .profile-container {
    margin-left: -150px;
}

@media (max-width: 1024px) {
    .profile-container {
        margin-left: -150px;
    }
}

@media (max-width: 768px) {
    .profile-container {
        margin-left: 0;
        padding: 0 12px;
    }
}

@media (max-width: 480px) {
    .profile-container {
        padding: 0 8px;
    }
}

@media (max-width: 360px) {
    .profile-container {
        padding: 0 6px;
    }
}

/* Page title */
.page-title {
    color: var(--text-dark);
    margin: 0 0 16px 0;
    font-size: 1.35rem;
    font-weight: 600;
    letter-spacing: -0.3px;
    line-height: 1.2;
}

/* Profile header card */
.profile-header {
    background-color: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 16px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    display: flex;
    align-items: center;
    gap: 16px;
}

.profile-avatar {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    border: 2px solid var(--primary-dark);
    background: var(--bg-light);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--primary-dark);
    font-weight: 700;
    font-size: 1rem;
    flex-shrink: 0;
}

.profile-meta {
    flex: 1;
}

.profile-name {
    color: var(--text-dark);
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 4px;
    line-height: 1.2;
}

.profile-email {
    color: var(--text-light);
    font-size: 0.9rem;
}

/* Action toggle button */
.action-toggle-btn {
    background: transparent;
    color: var(--primary-dark);
    border: 2px solid var(--primary-dark);
    border-radius: 8px;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.action-toggle-btn:hover {
    background: var(--primary-dark);
    color: var(--bg-white);
    transform: translateY(-1px);
}

.action-toggle-btn:active {
    transform: translateY(0);
}

/* Actions dropdown menu */
.profile-actions-menu {
    position: absolute;
    right: 0;
    top: 44px;
    background: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 8px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
    padding: 8px;
    min-width: 180px;
    z-index: 1000;
    display: none;
}

.profile-actions-menu button {
    width: 100%;
    background: transparent;
    color: var(--text-dark);
    border: none;
    text-align: left;
    padding: 10px 12px;
    border-radius: 6px;
    font-size: 0.9rem;
    cursor: pointer;
    transition: background-color 0.2s ease;
}

.profile-actions-menu button:hover {
    background: var(--bg-light);
}

/* Profile editor layout */
.profile-editor {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-top: 0;
}

/* Profile sections */
.personal-info,
.password-change {
    background-color: var(--bg-white);
    border: 1px solid var(--border-light);
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}

.personal-info h2,
.password-change h2 {
    color: var(--text-dark);
    margin: 0 0 16px 0;
    font-size: 1.1rem;
    font-weight: 600;
    border-bottom: 1px solid var(--border-light);
    padding-bottom: 12px;
}

/* Form elements */
.profile-editor form {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.profile-editor form div {
    display: flex;
    flex-direction: column;
}

/* Form labels */
.profile-editor label {
    margin-bottom: 6px;
    color: var(--text-dark);
    font-weight: 500;
    font-size: 0.9rem;
}

/* Form inputs */
.profile-editor input[type="text"],
.profile-editor input[type="email"],
.profile-editor input[type="tel"],
.profile-editor input[type="password"],
.profile-editor textarea {
    padding: 10px 12px;
    border: 1.5px solid var(--border-light);
    border-radius: 8px;
    font-size: 0.9rem;
    transition: all 0.2s ease;
    background: var(--bg-white);
    color: var(--text-dark);
    font-family: inherit;
}

.profile-editor input:focus,
.profile-editor textarea:focus {
    outline: none;
    border-color: var(--accent-yellow);
    box-shadow: 0 0 0 3px rgba(255, 215, 54, 0.2);
}

/* Textarea specific */
.profile-editor textarea {
    min-height: 80px;
    resize: vertical;
    font-family: inherit;
}

/* Button container */
.button-container {
    display: flex;
    gap: 12px;
    margin-top: 8px;
    flex-direction: row;
    align-items: center;
}

/* Form buttons */
.form-actions button[type="submit"],
.btn-secondary {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    font-family: inherit;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.form-actions button[type="submit"] {
    background: var(--primary-dark);
    color: var(--bg-white);
    border: 1px solid var(--primary-dark);
}

.form-actions button[type="submit"]:hover:not(:disabled) {
    background: var(--accent-yellow);
    color: var(--primary-dark);
    border-color: var(--accent-yellow);
    transform: translateY(-1px);
}

.form-actions button[type="submit"]:active:not(:disabled) {
    transform: translateY(0);
}

.btn-secondary {
    background: var(--error-red);
    color: var(--bg-white);
    border: 1px solid var(--error-red);
}

.btn-secondary:hover {
    background: #dc2626;
    border-color: #dc2626;
    transform: translateY(-1px);
}

.btn-secondary:active {
    transform: translateY(0);
}

/* Account summary grid */
.account-summary {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    color: var(--text-dark);
    padding: 10px 0;
    border-bottom: 1px solid var(--border-light);
}

.summary-row:last-child {
    border-bottom: none;
}

.summary-row strong {
    color: var(--text-dark);
    font-weight: 600;
    font-size: 0.85rem;
}

.summary-row span {
    color: var(--text-light);
    font-size: 0.85rem;
    text-align: right;
}

/* Account actions - hidden by default */
.account-actions {
    display: flex;
    gap: 10px;
    margin-top: 16px;
}

.account-actions button {
    background: var(--accent-yellow);
    color: var(--primary-dark);
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all 0.2s ease;
    font-weight: 600;
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.account-actions button:hover {
    background: #e6c230;
    transform: translateY(-1px);
}

.account-actions button:active {
    transform: translateY(0);
}

/* Password hint */
.hint {
    color: var(--text-light);
    font-size: 0.9rem;
    margin-top: 16px;
    text-align: center;
    padding: 16px;
    background: rgba(19, 3, 37, 0.04);
    border: 1px solid var(--border-light);
    border-radius: 8px;
}

/* Success/Error messages */
.success-message,
.error-message {
    padding: 12px 16px;
    border-radius: 8px;
    margin: 12px 0;
    text-align: center;
    font-weight: 500;
    font-size: 0.9rem;
    border: 1px solid;
}

.success-message {
    background: rgba(16, 185, 129, 0.1);
    color: var(--success-green);
    border-color: rgba(16, 185, 129, 0.2);
}

.error-message {
    background: rgba(239, 68, 68, 0.1);
    color: var(--error-red);
    border-color: rgba(239, 68, 68, 0.2);
}

/* Page title */
.page-title {
    color: var(--text-dark) !important;
    text-align: left;
    margin: 0 0 16px 0 !important;
    font-size: 1.35rem !important;
    font-weight: 600;
    padding: 0 !important;
    letter-spacing: -0.3px;
    line-height: 1.2;
}

/* Responsive design */
@media (max-width: 1024px) {
    .profile-editor {
        gap: 16px;
    }

    .personal-info,
    .password-change {
        padding: 18px;
    }
}

@media (max-width: 768px) {
    .profile-editor {
        grid-template-columns: 1fr;
        gap: 16px;
    }

    .personal-info,
    .password-change {
        padding: 16px;
    }

    .account-summary {
        grid-template-columns: 1fr;
        gap: 8px;
    }

    .summary-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
    }

    .summary-row span {
        text-align: left;
    }

    .button-container {
        flex-direction: column;
        gap: 8px;
    }

    .form-actions button[type="submit"],
    .btn-secondary {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .profile-container {
        padding: 0 8px;
    }

    .profile-header {
        padding: 14px 16px;
        flex-wrap: wrap;
        gap: 12px;
    }

    .profile-avatar {
        width: 48px;
        height: 48px;
    }

    .profile-name {
        font-size: 1rem;
    }

    .personal-info,
    .password-change {
        padding: 14px;
    }

    .profile-editor form {
        gap: 14px;
    }

    .form-actions button[type="submit"],
    .btn-secondary {
        padding: 10px 16px;
        font-size: 0.9rem;
    }
}

@media (max-width: 360px) {
    .profile-container {
        padding: 0 6px;
    }

    .profile-header {
        padding: 12px 14px;
    }

    .personal-info,
    .password-change {
        padding: 12px;
    }

    .profile-editor input[type="text"],
    .profile-editor input[type="email"],
    .profile-editor input[type="tel"],
    .profile-editor input[type="password"],
    .profile-editor textarea {
        padding: 8px 10px;
        font-size: 0.85rem;
    }

    .form-actions button[type="submit"],
    .btn-secondary {
        padding: 8px 14px;
        font-size: 0.8rem;
    }
}

/* Custom Confirmation Modal - Matching Logout Modal Design */
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
    transition: all 0.3s ease;
}

.custom-confirm-overlay.show {
    opacity: 1;
    visibility: visible;
}

.custom-confirm-dialog {
    background: var(--bg-white);
    border-radius: 12px;
    padding: 24px;
    max-width: 400px;
    width: 90%;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    transform: translateY(20px);
    transition: transform 0.3s ease;
}

.custom-confirm-overlay.show .custom-confirm-dialog {
    transform: translateY(0);
}

.custom-confirm-header {
    text-align: center;
    margin-bottom: 16px;
}

.custom-confirm-header h3 {
    color: var(--text-dark);
    font-size: 1.1rem;
    font-weight: 600;
    margin: 0;
}

.custom-confirm-body {
    text-align: center;
    margin-bottom: 20px;
    color: var(--text-light);
    font-size: 0.9rem;
    line-height: 1.5;
}

.custom-confirm-footer {
    display: flex;
    gap: 12px;
    justify-content: center;
}

.custom-confirm-btn {
    padding: 8px 20px;
    border: none;
    border-radius: 6px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    font-family: inherit;
}

.custom-confirm-btn.cancel {
    background: var(--error-red);
    color: var(--bg-white);
    border: 1px solid var(--error-red);
}

.custom-confirm-btn.cancel:hover {
    background: #dc2626;
    border-color: #dc2626;
    transform: translateY(-1px);
}

.custom-confirm-btn.confirm {
    background: var(--primary-dark);
    color: var(--bg-white);
    border: 1px solid var(--primary-dark);
}

.custom-confirm-btn.confirm:hover {
    background: var(--accent-yellow);
    color: var(--primary-dark);
    border-color: var(--accent-yellow);
    transform: translateY(-1px);
}
</style>

<main>
<div class="profile-container">
<h1 class="page-title">My Account</h1>

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
            <div class="button-container">
                <button type="submit" name="update_profile">
                    <i class="fas fa-save"></i>
                    Save Changes
                </button>
                <button type="button" id="cancelEditBtn" class="btn-secondary">
                    <i class="fas fa-times"></i>
                    Cancel
                </button>
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
            <div class="button-container">
                <button type="submit" name="change_password">
                    <i class="fas fa-key"></i>
                    Update Password
                </button>
                <button type="button" id="cancelPwBtn" class="btn-secondary">
                    <i class="fas fa-times"></i>
                    Cancel
                </button>
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
        cancelEdit.addEventListener('click', function(e){
            e.preventDefault();
            showConfirmModal('Are you sure you want to cancel? Any unsaved changes will be lost.', function(confirmed) {
                if (confirmed) {
                    editForm.style.display = 'none';
                }
            });
        });
    }

    if (cancelPw && pwForm && pwHint) {
        cancelPw.addEventListener('click', function(e){
            e.preventDefault();
            showConfirmModal('Are you sure you want to cancel password change?', function(confirmed) {
                if (confirmed) {
                    pwForm.style.display = 'none';
                    pwHint.style.display = 'block';
                }
            });
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

// Custom confirmation modal function
function showConfirmModal(message, callback) {
    // Remove existing modal if any
    const existing = document.querySelector('.custom-confirm-overlay');
    if (existing) {
        existing.remove();
    }

    // Create modal overlay
    const overlay = document.createElement('div');
    overlay.className = 'custom-confirm-overlay';

    // Create modal dialog
    const dialog = document.createElement('div');
    dialog.className = 'custom-confirm-dialog';

    // Create modal header
    const header = document.createElement('div');
    header.className = 'custom-confirm-header';
    const title = document.createElement('h3');
    title.textContent = 'Confirm Action';
    header.appendChild(title);

    // Create modal body
    const body = document.createElement('div');
    body.className = 'custom-confirm-body';
    body.textContent = message;

    // Create modal footer
    const footer = document.createElement('div');
    footer.className = 'custom-confirm-footer';

    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'custom-confirm-btn cancel';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.onclick = function() {
        overlay.classList.remove('show');
        setTimeout(() => overlay.remove(), 300);
        callback(false);
    };

    const confirmBtn = document.createElement('button');
    confirmBtn.className = 'custom-confirm-btn confirm';
    confirmBtn.textContent = 'Confirm';
    confirmBtn.onclick = function() {
        overlay.classList.remove('show');
        setTimeout(() => overlay.remove(), 300);
        callback(true);
    };

    footer.appendChild(cancelBtn);
    footer.appendChild(confirmBtn);

    // Assemble modal
    dialog.appendChild(header);
    dialog.appendChild(body);
    dialog.appendChild(footer);
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);

    // Show modal
    setTimeout(() => overlay.classList.add('show'), 10);

    // Close on overlay click
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) {
            overlay.classList.remove('show');
            setTimeout(() => overlay.remove(), 300);
            callback(false);
        }
    });
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

