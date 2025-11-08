-- Add 'completed' status to orders table ENUM
-- Run this SQL query in your database to add the 'completed' status option

ALTER TABLE `orders` 
MODIFY COLUMN `status` ENUM('pending','processing','shipped','delivered','completed','cancelled') DEFAULT 'pending';

