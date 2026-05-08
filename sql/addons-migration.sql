-- sql/addons-migration.sql — Phase 32B: addon purchase persistence
-- Run on the **control-plane DB only** (same as billing-migration.sql).
--
-- Idempotent — uses INFORMATION_SCHEMA + PREPARE guard pattern (per
-- C:\Users\Dmitry\.claude memory note: prod MySQL is < 8.0.29, so we
-- can't use ADD COLUMN/CREATE TABLE IF NOT EXISTS for FK columns).

SET @db := DATABASE();

-- ────────────────────────────────────────────────────────────────────────
-- Table: addon_purchases
-- One row per addon purchase attempt (one-time or recurring kickoff).
-- Recurring add-ons (priority_support) get a row at purchase + monthly
-- billing-cycle-worker entries via subscription_invoices like regular
-- subscriptions.
-- ────────────────────────────────────────────────────────────────────────
SET @exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = @db
       AND TABLE_NAME   = 'addon_purchases'
);
SET @sql := IF(@exists = 0,
    'CREATE TABLE addon_purchases (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id       INT NOT NULL,
        addon_sku       VARCHAR(64) NOT NULL,
        amount_kop      INT UNSIGNED NOT NULL,
        currency        CHAR(3) NOT NULL DEFAULT ''RUB'',
        status          ENUM(''pending'', ''paid'', ''failed'', ''refunded'', ''cancelled'') NOT NULL DEFAULT ''pending'',
        yk_payment_id   VARCHAR(64) NULL,
        purchased_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        delivered_at    TIMESTAMP NULL,
        notes           TEXT NULL,
        PRIMARY KEY (id),
        INDEX idx_addon_tenant (tenant_id, status),
        INDEX idx_addon_yk (yk_payment_id),
        INDEX idx_addon_sku (addon_sku)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
