-- Menu sort order migration: enable drag-n-drop ordering within a category.
-- Run once on each tenant DB (idempotent — re-runs are no-ops).
--
-- Before: menu_items was ordered by (category, name) — the UI had no way to
-- promote a signature dish to the top of its category.
-- After:  menu_items has a sort_order INT column with a composite index on
-- (category, sort_order, name). The admin UI writes integer positions via
-- save-menu-order.php; new items default to 0 and show up alphabetically
-- under any items that have been explicitly ordered.
--
-- The migration is deliberately non-destructive: existing rows get sort_order=0
-- and keep sorting by name as before until an operator drags them into place.
--
-- Idempotency: ADD COLUMN IF NOT EXISTS / ADD INDEX IF NOT EXISTS need
-- MySQL 8.0.29+. Production runs older 8.0.x, so we guard each DDL with an
-- INFORMATION_SCHEMA lookup + PREPARE/EXECUTE — works on every 8.0.x.

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME   = 'menu_items'
                      AND COLUMN_NAME  = 'sort_order');
SET @ddl := IF(@col_exists = 0,
               'ALTER TABLE menu_items ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER category',
               'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME   = 'menu_items'
                      AND INDEX_NAME   = 'idx_menu_items_category_sort');
SET @ddl := IF(@idx_exists = 0,
               'ALTER TABLE menu_items ADD INDEX idx_menu_items_category_sort (category, sort_order, name)',
               'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
