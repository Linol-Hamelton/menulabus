-- Modifiers soft-delete migration (track 5.5).
-- Run once on each tenant DB (idempotent — re-runs are no-ops).
--
-- Before: deleteModifierGroup / deleteModifierOption issued hard DELETEs.
-- An accidental misclick in the admin modifier editor was unrecoverable
-- unless the operator had a fresh DB backup.
-- After:  both tables carry a `deleted_at DATETIME NULL` column. The app
-- writes NOW() instead of DELETEing; all modifier SELECTs filter on
-- `deleted_at IS NULL`; a 30-second undo endpoint can restore the row;
-- scripts/orders/purge-soft-deleted.php hard-deletes rows older than 7 days.
--
-- Rollback is safe: dropping the column reverts the schema to the hard-delete
-- assumption, and any un-purged soft-deleted rows become visible again.
--
-- Idempotency: ADD COLUMN IF NOT EXISTS needs MySQL 8.0.29+. Production runs
-- older 8.0.x, so guard with INFORMATION_SCHEMA + PREPARE/EXECUTE.

-- modifier_groups.deleted_at
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'modifier_groups' AND COLUMN_NAME = 'deleted_at');
SET @ddl := IF(@c = 0,
               'ALTER TABLE modifier_groups ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER sort_order',
               'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- modifier_options.deleted_at
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'modifier_options' AND COLUMN_NAME = 'deleted_at');
SET @ddl := IF(@c = 0,
               'ALTER TABLE modifier_options ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER sort_order',
               'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- modifier_groups deleted_at index
SET @i := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'modifier_groups' AND INDEX_NAME = 'idx_modifier_groups_deleted_at');
SET @ddl := IF(@i = 0,
               'ALTER TABLE modifier_groups ADD INDEX idx_modifier_groups_deleted_at (deleted_at)',
               'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- modifier_options deleted_at index
SET @i := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'modifier_options' AND INDEX_NAME = 'idx_modifier_options_deleted_at');
SET @ddl := IF(@i = 0,
               'ALTER TABLE modifier_options ADD INDEX idx_modifier_options_deleted_at (deleted_at)',
               'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
