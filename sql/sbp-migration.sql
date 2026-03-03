-- ============================================================
-- SBP migration: add 'sbp' to payment_method ENUM
-- Run after payment-migration.sql
-- ============================================================

ALTER TABLE `orders`
    MODIFY COLUMN `payment_method`
        ENUM('cash','online','sbp') NOT NULL DEFAULT 'cash';
