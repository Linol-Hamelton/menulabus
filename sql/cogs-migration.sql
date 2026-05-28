-- Phase L101 — full COGS lifecycle.
--
-- menu_items.cost (DECIMAL(10,2)) уже существует в bootstrap-schema и
-- используется в analytics queries как «expense per item». Но никем не
-- писался — поэтому везде стоял 0 и все profit/expense расчёты молча
-- возвращали 0.
--
-- Эта миграция добавляет одну колонку:
--
--   cost_source ENUM('manual','recipe') NOT NULL DEFAULT 'recipe'
--
-- Семантика:
--   recipe — menu_items.cost держится синхронным с getRecipeCost(id);
--            пересчитывается при изменении рецепта или ingredient.cost_per_unit.
--   manual — admin задал значение через UI или CSV; auto-recompute не трогает.
--
-- Существующим строкам default 'recipe' даст корректное поведение: при
-- первом сохранении рецепта / изменении ингредиента их cost подтянется.
-- Если рецепта нет, cost остаётся 0 — что эквивалентно текущему поведению,
-- так что миграция не ломает аналитику для блюд без recipe data.
--
-- Idempotency: тот же INFORMATION_SCHEMA + PREPARE/EXECUTE pattern что и
-- menu-sort-order-migration (production MySQL < 8.0.29 → IF NOT EXISTS не
-- поддерживается).

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME   = 'menu_items'
                      AND COLUMN_NAME  = 'cost_source');
SET @ddl := IF(@col_exists = 0,
               "ALTER TABLE menu_items ADD COLUMN cost_source ENUM('manual','recipe') NOT NULL DEFAULT 'recipe' AFTER cost",
               'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME   = 'menu_items'
                      AND INDEX_NAME   = 'idx_menu_items_cost_source');
SET @ddl := IF(@idx_exists = 0,
               'ALTER TABLE menu_items ADD INDEX idx_menu_items_cost_source (cost_source)',
               'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
