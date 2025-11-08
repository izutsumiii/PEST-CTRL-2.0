<?php

require_once '../config/database.php';
require_once 'includes/admin_header.php';
requireSeller();

$userId = $_SESSION['user_id'];

if (!isset($_GET['id'])) {
    header("Location: manage-categories.php");
    exit();
}

$categoryId = intval($_GET['id']);

// Check if category belongs to the seller
$stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ? AND seller_id = ?");
$stmt->execute([$categoryId, $userId]);
$category = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$category) {
    echo "<p class='error-message'>Category not found or you don't have permission to edit it.</p>";
    require_once 'includes/footer.php';
    exit();
}

// Handle form submission

// Get parent categories for dropdown (excluding current category and its children)
$stmt = $pdo->prepare("SELECT * FROM categories 
                      WHERE parent_id IS NULL 
                      AND seller_id = ? 
                      AND id != ?
                      ORDER BY name");
$stmt->execute([$userId, $categoryId]);
$parentCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h1>Edit Category</h1>

<form method="POST" action="">
    <div>
        <label for="name">Category Name:</label>
        <input type="text" id="name" name="name" value="<?php echo $category['name']; ?>" required>
    </div>
    
    <div>
        <label for="description">Description:</label>
        <textarea id="description" name="description"><?php echo $category['description']; ?></textarea>
    </div>
    
    <div>
        <label for="parent_id">Parent Category (optional):</label>
        <select id="parent_id" name="parent_id">
            <option value="">No Parent (Top Level)</option>
            <?php foreach ($parentCategories as $parentCategory): ?>
                <option value="<?php echo $parentCategory['id']; ?>" 
                    <?php echo $parentCategory['id'] == $category['parent_id'] ? 'selected' : ''; ?>>
                    <?php echo $parentCategory['name']; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <button type="submit" name="update_category">Update Category</button>
    <a href="manage-categories.php" class="back-button">Cancel</a>
</form>

<?php require_once 'includes/footer.php'; ?>