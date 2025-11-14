<?php
require_once 'config/database.php';

echo "Setting up User Addresses System...\n\n";

try {
    // Create user_addresses table
    echo "1. Creating user_addresses table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS user_addresses (
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
        INDEX idx_is_default (is_default),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    try {
        $pdo->exec($sql);
        echo "✓ user_addresses table created\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "✓ user_addresses table already exists\n";
        } else {
            throw $e;
        }
    }
    
    // Migrate existing addresses from users table to user_addresses
    echo "2. Migrating existing addresses...\n";
    $migrateSql = "INSERT INTO user_addresses (user_id, address_name, full_name, phone, address, is_default)
                    SELECT id, 'Home', 
                           CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')),
                           COALESCE(phone, ''),
                           COALESCE(address, ''),
                           1
                    FROM users
                    WHERE (address IS NOT NULL AND address != '') 
                       OR (phone IS NOT NULL AND phone != '')
                    AND NOT EXISTS (
                        SELECT 1 FROM user_addresses WHERE user_addresses.user_id = users.id
                    )";
    
    try {
        $pdo->exec($migrateSql);
        $affected = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
        echo "✓ Migrated existing addresses\n";
    } catch (PDOException $e) {
        echo "⚠ Migration note: " . $e->getMessage() . "\n";
    }
    
    echo "\n✓ User Addresses System setup complete!\n";
    
} catch (PDOException $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>

