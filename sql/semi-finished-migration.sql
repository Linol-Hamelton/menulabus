-- sql/semi-finished-migration.sql — Phase 40 (Полуфабрикаты).
--
-- Idempotent. Tenant-DB only.
-- Apply per tenant:
--   mysql --protocol=SOCKET -u root <DB_NAME> < sql/semi-finished-migration.sql
--
-- Полуфабрикат = ingredient с is_semi_finished=1. Имеет:
--   - дочерний recipe (semi_finished_recipes): из чего готовится
--   - журнал партий (semi_finished_batches): когда и сколько приготовлено
-- При продаже блюда: обычный recipes-flow decrement'ит stock_qty полуфабриката
-- (он живёт в ingredients наравне с обычными ingredient).

-- ingredients.is_semi_finished + ingredients.yield_per_batch
SET @col_sf = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'ingredients'
       AND COLUMN_NAME = 'is_semi_finished'
);
SET @sql_sf = IF(@col_sf = 0,
    "ALTER TABLE ingredients
        ADD COLUMN is_semi_finished TINYINT(1) NOT NULL DEFAULT 0 AFTER alc_code,
        ADD COLUMN yield_per_batch DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER is_semi_finished,
        ADD KEY idx_ingredients_semi_finished (is_semi_finished)",
    "SELECT 'ingredients.is_semi_finished already exists'"
);
PREPARE stmt_sf FROM @sql_sf;
EXECUTE stmt_sf;
DEALLOCATE PREPARE stmt_sf;

-- Recipe для полуфабриката: parent_id (ingredient.is_semi_finished=1) → child_ingredient_id + quantity.
-- Отдельная таблица, чтобы не путать с recipes (menu_item_id → ingredient_id).
CREATE TABLE IF NOT EXISTS semi_finished_recipes (
    parent_ingredient_id INT UNSIGNED NOT NULL,    -- ingredients.id, is_semi_finished=1
    child_ingredient_id INT UNSIGNED NOT NULL,     -- ingredients.id (обычно is_semi_finished=0; но nesting допустим)
    quantity DECIMAL(12,3) NOT NULL,               -- расход child на одну единицу yield_per_batch
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (parent_ingredient_id, child_ingredient_id),
    KEY idx_sf_recipes_child (child_ingredient_id),
    CONSTRAINT chk_sf_recipes_qty CHECK (quantity > 0),
    CONSTRAINT chk_sf_recipes_no_self CHECK (parent_ingredient_id <> child_ingredient_id),
    CONSTRAINT fk_sf_recipes_parent
        FOREIGN KEY (parent_ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE,
    CONSTRAINT fk_sf_recipes_child
        FOREIGN KEY (child_ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Журнал приготовления партий полуфабриката.
CREATE TABLE IF NOT EXISTS semi_finished_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ingredient_id INT UNSIGNED NOT NULL,           -- ingredients.id, is_semi_finished=1
    batch_size DECIMAL(12,3) NOT NULL,             -- сколько единиц yield_per_batch приготовлено
    made_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    made_by INT NULL,                              -- users.id (кто запустил производство)
    notes TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_sf_batches_ingredient (ingredient_id, made_at),
    CONSTRAINT fk_sf_batches_ingredient
        FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
