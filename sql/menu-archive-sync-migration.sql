-- Menu archive/sync migration:
-- 1) add external_id and archived_at to menu_items
-- 2) backfill external_id for existing rows
-- 3) add indexes for sync and active reads
--
-- Run once on production DB after backup.

ALTER TABLE menu_items
    ADD COLUMN external_id VARCHAR(64) NULL AFTER id,
    ADD COLUMN archived_at DATETIME NULL DEFAULT NULL AFTER available;

UPDATE menu_items
SET external_id = CONCAT('legacy-', id)
WHERE external_id IS NULL OR external_id = '';

ALTER TABLE menu_items
    MODIFY external_id VARCHAR(64) NOT NULL;

ALTER TABLE menu_items
    ADD UNIQUE KEY uniq_external_id (external_id),
    ADD KEY idx_menu_active_category (archived_at, available, category);
