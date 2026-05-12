-- sql/cashier-shifts-migration.sql — Phase 35 (Кассовая смена + Refund / чек коррекции).
--
-- Idempotent. Tenant-DB only (not control-plane).
-- Apply per tenant:
--   mysql --defaults-extra-file=/root/.my.cnf <DB_NAME> < sql/cashier-shifts-migration.sql
--
-- Three new tables + one nullable column on `orders` for shift binding.
-- No FKs on the orders.shift_id column to keep migration cheap on existing
-- large tables; integrity is enforced at write time in db.php.

CREATE TABLE IF NOT EXISTS cashier_shifts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cashier_id INT NOT NULL,                        -- users.id (employee / admin)
    location_id INT NULL,                           -- multi-location scope (nullable)
    opened_at DATETIME NOT NULL,
    closed_at DATETIME NULL,
    opening_cash DECIMAL(10,2) NOT NULL DEFAULT 0,  -- размен с которым открыли
    closing_cash DECIMAL(10,2) NULL,                -- факт по закрытию
    encashment_total DECIMAL(10,2) NOT NULL DEFAULT 0, -- инкассация total
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cashier_shifts_cashier (cashier_id, opened_at),
    KEY idx_cashier_shifts_open (closed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shift_encashments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shift_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    reason VARCHAR(64) NOT NULL DEFAULT 'other',    -- bank_deposit / safe / other
    noted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_shift_encashments_shift (shift_id),
    CONSTRAINT fk_shift_encashments_shift
        FOREIGN KEY (shift_id) REFERENCES cashier_shifts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_refunds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id INT NOT NULL,                          -- orders.id is INT signed
    refunded_by INT NOT NULL,                       -- users.id
    amount DECIMAL(10,2) NOT NULL,
    is_partial TINYINT(1) NOT NULL DEFAULT 0,
    reason VARCHAR(255) NULL,
    fiscal_receipt_uuid VARCHAR(64) NULL,           -- АТОЛ correction-receipt id
    fiscal_receipt_status VARCHAR(16) NULL,         -- wait / done / fail
    fiscal_receipt_url TEXT NULL,
    shift_id BIGINT UNSIGNED NULL,                  -- если возврат внутри смены
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_order_refunds_order (order_id),
    KEY idx_order_refunds_shift (shift_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- orders.shift_id — bind orders to a cashier shift when one is open.
-- Pre-existing prod MySQL is 8.0.45 (≥8.0.29) — `IF NOT EXISTS` is supported,
-- but per project memory (memory/feedback_mysql_idempotent_ddl.md) the host
-- prod is also tested against MySQL <8.0.29, so wrap in INFORMATION_SCHEMA
-- guard to stay portable.

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'orders'
       AND COLUMN_NAME = 'shift_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE orders ADD COLUMN shift_id BIGINT UNSIGNED NULL AFTER status, ADD KEY idx_orders_shift (shift_id)',
    'SELECT "orders.shift_id already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
