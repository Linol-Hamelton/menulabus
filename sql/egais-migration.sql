-- sql/egais-migration.sql — Phase 39 (ЕГАИС / 171-ФЗ алкоголь).
--
-- Idempotent. Tenant-DB only.
-- Apply per tenant:
--   mysql --protocol=SOCKET -u root <DB_NAME> < sql/egais-migration.sql
--
-- Manual MVP: storage for алкогольные ТТН + акты вскрытия тары.
-- Auto-mode (УТМ-прокси или Контур.ЕГАИС API) — Phase 39.1.

CREATE TABLE IF NOT EXISTS alc_invoices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ttn_number VARCHAR(64) NOT NULL,                -- номер ТТН
    ttn_date DATE NOT NULL,                         -- дата ТТН
    supplier_inn VARCHAR(16) NOT NULL,              -- ИНН поставщика
    supplier_name VARCHAR(255) NULL,                -- название поставщика
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('pending', 'accepted', 'rejected') NOT NULL DEFAULT 'pending',
    accepted_at DATETIME NULL,
    accepted_by INT NULL,                            -- users.id
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_alc_invoices_status_date (status, ttn_date),
    KEY idx_alc_invoices_supplier (supplier_inn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alc_invoice_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id BIGINT UNSIGNED NOT NULL,
    ingredient_id INT UNSIGNED NULL,                -- references ingredients.id (NULL if unmatched)
    alc_code VARCHAR(64) NOT NULL,                  -- код продукции (АСНА / 19/20-significant)
    name VARCHAR(255) NULL,                         -- как указано в ТТН (на случай если ingredient_id NULL)
    quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
    unit VARCHAR(16) NULL,
    price_per_unit DECIMAL(10,4) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_alc_items_invoice (invoice_id),
    KEY idx_alc_items_ingredient (ingredient_id),
    KEY idx_alc_items_code (alc_code),
    CONSTRAINT fk_alc_items_invoice
        FOREIGN KEY (invoice_id) REFERENCES alc_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alc_openings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ingredient_id INT UNSIGNED NOT NULL,            -- ingredients.id
    bottle_volume_ml INT NOT NULL DEFAULT 750,      -- объём вскрываемой тары
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opened_by INT NOT NULL,                          -- users.id
    shift_id BIGINT UNSIGNED NULL,                   -- cashier_shifts.id если в смене
    notes TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_alc_openings_ingredient (ingredient_id, opened_at),
    KEY idx_alc_openings_shift (shift_id),
    CONSTRAINT fk_alc_openings_ingredient
        FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ingredients.is_alcohol + alc_code — toggle and reference for ЕГАИС.
-- INFORMATION_SCHEMA guard (project memory: feedback_mysql_idempotent_ddl).

SET @col1_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'ingredients'
       AND COLUMN_NAME = 'is_alcohol'
);
SET @sql1 = IF(@col1_exists = 0,
    'ALTER TABLE ingredients ADD COLUMN is_alcohol TINYINT(1) NOT NULL DEFAULT 0 AFTER requires_vsd, ADD KEY idx_ingredients_is_alcohol (is_alcohol)',
    'SELECT "ingredients.is_alcohol already exists"'
);
PREPARE stmt1 FROM @sql1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

SET @col2_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'ingredients'
       AND COLUMN_NAME = 'alc_code'
);
SET @sql2 = IF(@col2_exists = 0,
    'ALTER TABLE ingredients ADD COLUMN alc_code VARCHAR(64) NULL AFTER is_alcohol, ADD KEY idx_ingredients_alc_code (alc_code)',
    'SELECT "ingredients.alc_code already exists"'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
