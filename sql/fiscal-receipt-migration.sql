-- Fiscal receipt migration (Phase 7.2, 2026-04-27).
--
-- Adds two nullable columns on `orders`:
--   * fiscal_receipt_url — link to the OFD-published receipt once
--     the fiscal provider returns a confirmation. Customer-visible
--     ("показать чек") and oncall-visible (audit trail).
--   * fiscal_receipt_uuid — provider-side opaque id; used to poll
--     receipt status before the URL becomes available, and to
--     short-circuit duplicate fiscalisation if the order webhook
--     fires twice.
--
-- Idempotent via INFORMATION_SCHEMA guard (MySQL 8.0 < 8.0.29 lacks
-- ADD COLUMN IF NOT EXISTS — see memory feedback_mysql_idempotent_ddl).

SET @c1 := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'fiscal_receipt_url'
);
SET @ddl := IF(@c1 = 0,
    'ALTER TABLE orders ADD COLUMN fiscal_receipt_url VARCHAR(512) NULL DEFAULT NULL',
    'SELECT 0'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c2 := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'fiscal_receipt_uuid'
);
SET @ddl := IF(@c2 = 0,
    'ALTER TABLE orders ADD COLUMN fiscal_receipt_uuid VARCHAR(64) NULL DEFAULT NULL',
    'SELECT 0'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index supports the "find unfiscalized paid orders" query the worker
-- runs (status IN ('paid') AND fiscal_receipt_uuid IS NULL).
SET @i1 := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND INDEX_NAME = 'idx_orders_fiscal_pending'
);
SET @ddl := IF(@i1 = 0,
    'ALTER TABLE orders ADD INDEX idx_orders_fiscal_pending (fiscal_receipt_uuid, status)',
    'SELECT 0'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
