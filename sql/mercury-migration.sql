-- sql/mercury-migration.sql — Phase 38 (Меркурий / ФГИС Россельхознадзор).
--
-- Idempotent. Tenant-DB only.
-- Apply per tenant:
--   mysql --protocol=SOCKET -u root <DB_NAME> < sql/mercury-migration.sql
--
-- Manual MVP: storage for ВСД (ветеринарные сопроводительные документы)
-- without Vetis API integration. Auto-mode (Vetis REST API with EDS) — Phase 38.1.

CREATE TABLE IF NOT EXISTS vsd_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ingredient_id INT UNSIGNED NOT NULL,          -- references ingredients.id
    vsd_number VARCHAR(64) NOT NULL,              -- номер ВСД (Меркурий ID)
    vsd_date DATE NOT NULL,                       -- дата выдачи
    supplier_inn VARCHAR(16) NULL,                -- ИНН поставщика
    supplier_name VARCHAR(255) NULL,              -- название поставщика (free-text)
    quantity DECIMAL(12,3) NOT NULL DEFAULT 0,    -- кол-во по документу
    unit VARCHAR(16) NULL,                        -- ед. изм. (мирорит ingredients.unit)
    status ENUM('pending', 'accepted', 'rejected') NOT NULL DEFAULT 'pending',
    accepted_at DATETIME NULL,
    accepted_by INT NULL,                          -- users.id (admin/owner)
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vsd_ingredient (ingredient_id, status),
    KEY idx_vsd_status_date (status, vsd_date),
    CONSTRAINT fk_vsd_ingredient
        FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ingredients.requires_vsd — toggle "this ingredient requires ВСД on receipt"
-- (per project memory: feedback_mysql_idempotent_ddl — use INFORMATION_SCHEMA guard,
-- never ADD COLUMN IF NOT EXISTS, since prod MySQL is also tested against <8.0.29).

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'ingredients'
       AND COLUMN_NAME = 'requires_vsd'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE ingredients ADD COLUMN requires_vsd TINYINT(1) NOT NULL DEFAULT 0 AFTER cost_per_unit, ADD KEY idx_ingredients_requires_vsd (requires_vsd)',
    'SELECT "ingredients.requires_vsd already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
