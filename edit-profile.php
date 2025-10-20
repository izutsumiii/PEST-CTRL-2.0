<?php
require_once 'includes/header.php';
require_once 'config/database.php';
?>
<?php

// spacer below fixed header
echo '<div style="height:40px"></div>';

requireLogin();

$userId = $_SESSION['user_id'];

// Get user info
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle form submission
if (isset($_POST['update_profile'])) {
    $username = sanitizeInput($_POST['username']);
    $firstName = sanitizeInput($_POST['first_name']);
    $lastName = sanitizeInput($_POST['last_name']);
    $email = sanitizeInput($_POST['email']);
    $phone = sanitizeInput($_POST['phone']);
    $address = sanitizeInput($_POST['address']);
    
    // Check if email already exists (excluding current user)
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$email, $userId]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if username already exists (excluding current user)
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND id != ?");
    $stmt->execute([$username, $userId]);
    $existingUsername = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingUser) {
        echo "<p class=\"error-message\">Error: Email already exists.</p>";
    } elseif ($existingUsername) {
        echo "<p class=\"error-message\">Error: Username already exists.</p>";
    } else {
        $stmt = $pdo->prepare("UPDATE users SET username = ?, first_name = ?, last_name = ?, email = ?, phone = ?, address = ? WHERE id = ?");
        $stmt->execute([$username, $firstName, $lastName, $email, $phone, $address, $userId]);
        
        echo "<p class=\"success-message\">Profile updated successfully!</p>";
        header("Refresh:2");
    }
}

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
            
            echo "<p class=\"success-message\">Password changed successfully!</p>";
        } else {
            echo "<p class=\"error-message\">Error: New passwords do not match.</p>";
        }
    } else {
        echo "<p class=\"error-message\">Error: Current password is incorrect.</p>";
    }
}
?>

<style>
/* Force body and html to have full background coverage */
html, body {
    margin: 0;
    padding: 0;
    min-height: 100vh;
    background: #130325 !important;
}

/* Main container styling */
main {
    background: transparent !important;
    margin: 0 !important;
    padding: 8px 0 100px 0 !important;
    min-height: calc(100vh - 80px) !important;
}

/* Edit Profile Styles - aligned with multi-seller checkout design */

.profile-editor h1,
.profile-editor h2,
.profile-editor h3 {
    color: var(--primary-light);
    text-align: center;
    margin: 20px 0;
    font-size: 2rem;
}

/* Main profile editor container */
.profile-editor {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 40px;
    max-width: 1000px;
    margin: 20px auto;
    padding: 0 20px;
}

/* Section styling */
.personal-info,
.password-change {
    background: var(--primary-dark);
    padding: 25px;
    border-radius: 8px;
    box-shadow: 0 4px 20px var(--shadow-light);
    border: 1px solid var(--accent-yellow);
}

.personal-info h2,
.password-change h2 {
    color: var(--primary-light);
    margin-bottom: 20px;
    font-size: 1.5rem;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--accent-yellow);
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

/* Label styling */
.profile-editor label {
    margin-bottom: 5px;
    color: var(--primary-light);
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
    border-color: var(--accent-yellow);
    box-shadow: 0 0 0 3px rgba(255, 215, 54, 0.3);
}

/* Textarea specific styling */
.profile-editor textarea {
    min-height: 80px;
    resize: vertical;
    font-family: inherit;
}

/* Button styling */
.profile-editor button[type="submit"] {
    background: #FFD736;
    color: #130325;
    padding: 12px 24px;
    border: none;
    border-radius: 4px;
    font-size: 1rem;
    cursor: pointer;
    transition: background-color 0.3s, transform 0.2s;
    margin-top: 10px;
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
    padding: 12px 24px;
    border: none;
    border-radius: 4px;
    font-size: 1rem;
    cursor: pointer;
    transition: background-color 0.3s;
    font-weight: 600;
}

.btn-secondary:hover { 
    background: #5a6268; 
}

.account-summary { 
    display: grid; 
    gap: 10px; 
}

.summary-row { 
    display: flex; 
    justify-content: space-between; 
    gap: 10px; 
    color: var(--primary-light); 
    padding: 8px 0;
    border-bottom: 1px solid rgba(255, 215, 54, 0.2);
}

.summary-row:last-child {
    border-bottom: none;
}

.summary-row strong { 
    color: var(--accent-yellow); 
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
    color: var(--primary-light); 
    opacity: 0.8; 
    font-size: 0.9rem; 
    margin-top: 8px; 
    text-align: center;
    padding: 15px;
    background: rgba(255, 215, 54, 0.1);
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
    color: var(--primary-light) !important;
    text-align: center;
    margin: 0 auto 30px auto !important;
    font-size: 2.5rem;
    font-weight: 700;
}

/* Responsive design */
@media (max-width: 768px) {
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
        font-size: 1.8rem !important;
        margin: 15px 0 20px 0 !important;
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
        padding: 10px 20px;
    }

    h1 {
        font-size: 1.5rem !important;
    }
}
</style>

<main>
<div style="max-width: 1200px; margin: 0 auto; padding: 20px;">
<h1>My Account</h1>

<div class="profile-editor">
    <div class="personal-info">
        <h2>Account Details</h2>
        <div class="account-summary">
            <div class="summary-row"><strong>Username:</strong> <span><?php echo htmlspecialchars($user['username'] ?? ''); ?></span></div>
            <div class="summary-row"><strong>Name:</strong> <span><?php echo htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))); ?></span></div>
            <div class="summary-row"><strong>Email:</strong> <span><?php echo htmlspecialchars($user['email'] ?? ''); ?></span></div>
            <div class="summary-row"><strong>Phone:</strong> <span><?php echo htmlspecialchars($user['phone'] ?? ''); ?></span></div>
            <div class="summary-row"><strong>Address:</strong> <span><?php echo htmlspecialchars($user['address'] ?? ''); ?></span></div>
        </div>
        <div class="account-actions">
            <button id="editAccountBtn" type="button">Edit Account</button>
            <button id="changePasswordBtn" type="button">Change Password</button>
        </div>

        <!-- Hidden Edit Form -->
        <form id="editAccountForm" method="POST" action="" style="display:none; margin-top:20px;">
            <div>
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" required>
            </div>
            <div>
                <label for="first_name">First Name:</label>
                <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" required>
            </div>
            <div>
                <label for="last_name">Last Name:</label>
                <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" required>
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
            </div>
            <div style="display:flex; gap:10px;">
                <button type="submit" name="update_profile">Save Changes</button>
                <button type="button" id="cancelEditBtn" class="btn-secondary">Cancel</button>
            </div>
        </form>
    </div>
    
    <div class="password-change">
        <h2>Change Password</h2>
        <div id="passwordHint" class="hint">Click "Change Password" button on the left to update your password.</div>
        <form id="changePasswordForm" method="POST" action="" style="display:none; margin-top: 20px;">
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
            <div style="display:flex; gap:10px;">
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
    const editBtn = document.getElementById('editAccountBtn');
    const changePwBtn = document.getElementById('changePasswordBtn');
    const editForm = document.getElementById('editAccountForm');
    const pwForm = document.getElementById('changePasswordForm');
    const cancelEdit = document.getElementById('cancelEditBtn');
    const cancelPw = document.getElementById('cancelPwBtn');
    const pwHint = document.getElementById('passwordHint');

    if (editBtn && editForm) {
        editBtn.addEventListener('click', function() {
            const isVisible = editForm.style.display !== 'none';
            editForm.style.display = isVisible ? 'none' : 'block';
        });
    }
    
    if (changePwBtn && pwForm && pwHint) {
        changePwBtn.addEventListener('click', function() {
            const isVisible = pwForm.style.display !== 'none';
            pwForm.style.display = isVisible ? 'none' : 'block';
            pwHint.style.display = isVisible ? 'block' : 'none';
        });
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

});
</script>


<?php require_once 'includes/footer.php'; ?>