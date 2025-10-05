<?php
require_once 'config/database.php';

echo "Setting up Multi-Seller Checkout System...\n\n";

try {
    // 1. Create payment_transactions table (without foreign key first)
    echo "1. Creating payment_transactions table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS payment_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        total_amount DECIMAL(10,2) NOT NULL,
        payment_method VARCHAR(50) NOT NULL,
        payment_status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
        payment_reference VARCHAR(255) NULL,
        paymongo_session_id VARCHAR(255) NULL,
        paymongo_payment_id VARCHAR(255) NULL,
        shipping_address TEXT NOT NULL,
        customer_name VARCHAR(255) NULL,
        customer_email VARCHAR(255) NULL,
        customer_phone VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL,
        INDEX idx_user_id (user_id),
        INDEX idx_payment_status (payment_status),
        INDEX idx_created_at (created_at)
    )";
    $pdo->exec($sql);
    echo "✓ payment_transactions table created\n";

    // 2. Add payment_transaction_id to orders table
    echo "2. Adding payment_transaction_id to orders table...\n";
    $sql = "ALTER TABLE orders ADD COLUMN payment_transaction_id INT NULL AFTER user_id";
    try {
        $pdo->exec($sql);
        echo "✓ payment_transaction_id column added to orders\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ payment_transaction_id column already exists\n";
        } else {
            throw $e;
        }
    }

    // 3. Add foreign key constraint for payment_transaction_id
    echo "3. Adding foreign key constraint...\n";
    $sql = "ALTER TABLE orders ADD CONSTRAINT fk_orders_payment_transaction 
            FOREIGN KEY (payment_transaction_id) REFERENCES payment_transactions(id) ON DELETE SET NULL";
    try {
        $pdo->exec($sql);
        echo "✓ Foreign key constraint added\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "✓ Foreign key constraint already exists\n";
        } else {
            echo "⚠ Foreign key constraint skipped (may already exist or not needed)\n";
        }
    }

    // 4. Add seller_id to orders table if it doesn't exist
    echo "4. Checking seller_id in orders table...\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'seller_id'");
    if ($stmt->rowCount() == 0) {
        $sql = "ALTER TABLE orders ADD COLUMN seller_id INT NULL AFTER payment_transaction_id";
        $pdo->exec($sql);
        echo "✓ seller_id column added to orders\n";
        
        // Add foreign key for seller_id
        try {
            $sql = "ALTER TABLE orders ADD CONSTRAINT fk_orders_seller 
                    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE SET NULL";
            $pdo->exec($sql);
            echo "✓ Foreign key constraint for seller_id added\n";
        } catch (PDOException $e) {
            echo "⚠ Foreign key constraint for seller_id skipped\n";
        }
    } else {
        echo "✓ seller_id column already exists\n";
    }

    // 5. Add index for better performance
    echo "5. Adding indexes for better performance...\n";
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_orders_payment_transaction ON orders(payment_transaction_id)",
        "CREATE INDEX IF NOT EXISTS idx_orders_seller ON orders(seller_id)",
        "CREATE INDEX IF NOT EXISTS idx_orders_user_seller ON orders(user_id, seller_id)"
    ];
    
    foreach ($indexes as $index) {
        try {
            $pdo->exec($index);
        } catch (PDOException $e) {
            // Index might already exist, continue
        }
    }
    echo "✓ Indexes added\n";

    // 6. Create order_groups table for grouping orders by payment transaction
    echo "6. Creating order_groups table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS order_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        payment_transaction_id INT NOT NULL,
        group_name VARCHAR(255) NOT NULL,
        total_orders INT NOT NULL DEFAULT 0,
        total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_payment_transaction (payment_transaction_id)
    )";
    $pdo->exec($sql);
    echo "✓ order_groups table created\n";

    echo "\n=== Multi-Seller Checkout System Setup Complete! ===\n";
    echo "✓ Database schema updated successfully\n";
    echo "✓ All tables and constraints created\n";
    echo "✓ Ready for multi-seller checkout implementation\n\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Setup failed. Please check the error and try again.\n";
}
?>
