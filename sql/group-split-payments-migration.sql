-- Group split-bill payments migration (Phase 7.5, 2026-04-28).
--
-- Adds two tables to support multi-payer settlement of a group_orders row:
--
--   group_payment_intents: one row per individual payer's chunk.
--     - Host creates a single intent with payer_label='Все' to pay
--       the full total (legacy mode).
--     - Or each guest creates an intent with payer_label='Маша'
--       for their share. status moves through 'pending' → 'paid'
--       (or 'failed' / 'cancelled') as the YooKassa webhook lands.
--
--   The group transitions to 'paid' only when the SUM of paid intents'
--     amounts >= total of group_order_items for that group. The
--     payment-webhook.php handler (extended elsewhere) is responsible
--     for that aggregation step.
--
-- All ALTER TABLE / ADD INDEX is INFORMATION_SCHEMA-guarded for re-run
-- safety (per the project's idempotent-DDL convention).

CREATE TABLE IF NOT EXISTS group_payment_intents (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_order_id  INT UNSIGNED NOT NULL,
    payer_label     VARCHAR(64) NOT NULL,
    seat_label      VARCHAR(64) DEFAULT NULL,  -- NULL = "covers everyone" / "share equally"
    amount          DECIMAL(10,2) NOT NULL,
    payment_method  VARCHAR(32) NOT NULL DEFAULT 'card',
    yk_payment_id   VARCHAR(64) DEFAULT NULL,
    status          VARCHAR(16) NOT NULL DEFAULT 'pending',
    paid_at         DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gpi_group_status (group_order_id, status),
    KEY idx_gpi_yk (yk_payment_id),
    CONSTRAINT fk_gpi_group
        FOREIGN KEY (group_order_id) REFERENCES group_orders(id) ON DELETE CASCADE,
    CONSTRAINT chk_gpi_status
        CHECK (status IN ('pending', 'paid', 'failed', 'cancelled')),
    CONSTRAINT chk_gpi_amount
        CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A "split mode" preference on the group itself. Defaults to 'host'
-- (single payer, legacy behaviour). 'per_seat' means each guest
-- creates their own intent for their seat's items; 'equal' means each
-- guest covers an equal share of the total regardless of what they
-- ordered.
SET @col := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'group_orders'
      AND COLUMN_NAME = 'split_mode'
);
SET @ddl := IF(@col = 0,
    'ALTER TABLE group_orders ADD COLUMN split_mode VARCHAR(16) NOT NULL DEFAULT ''host''',
    'SELECT 0'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
