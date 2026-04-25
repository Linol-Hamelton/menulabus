-- Menu sort order migration: enable drag-n-drop ordering within a category.
-- Run once on each tenant DB (idempotent via IF NOT EXISTS).
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

ALTER TABLE menu_items
    ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER category;

-- MySQL 8.0+ supports IF NOT EXISTS on ADD INDEX; the block is guarded so a
-- second run is a no-op. On older MySQL (5.7) remove the IF NOT EXISTS clause
-- and drop the index manually before re-running.
ALTER TABLE menu_items
    ADD INDEX IF NOT EXISTS idx_menu_items_category_sort (category, sort_order, name);
