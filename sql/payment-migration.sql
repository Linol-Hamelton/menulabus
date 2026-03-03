-- ============================================================
-- Payment migration: add payment columns to orders table
-- Run once on production: mysql -u user -p db < payment-migration.sql
-- ============================================================

ALTER TABLE `orders`
    ADD COLUMN `payment_method` ENUM('cash','online') NOT NULL DEFAULT 'cash'
        AFTER `delivery_details`,
    ADD COLUMN `payment_id` VARCHAR(100) DEFAULT NULL
        AFTER `payment_method`,
    ADD COLUMN `payment_status` ENUM('not_required','pending','paid','failed','cancelled')
        NOT NULL DEFAULT 'not_required'
        AFTER `payment_id`;

CREATE INDEX `idx_orders_payment_id` ON `orders` (`payment_id`);
