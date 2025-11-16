-- Add session_token columns to payment_transactions table
-- This allows session restoration after PayMongo redirects, even when cookies fail

ALTER TABLE `payment_transactions` 
ADD COLUMN `session_token` VARCHAR(64) NULL DEFAULT NULL AFTER `paymongo_session_id`,
ADD COLUMN `session_token_expiry` DATETIME NULL DEFAULT NULL AFTER `session_token`,
ADD INDEX `idx_session_token` (`session_token`),
ADD INDEX `idx_session_token_expiry` (`session_token_expiry`);

-- Note: These columns are optional - the code will work without them (falls back to remember_token)
-- But adding them provides better session restoration when ngrok URL changes

