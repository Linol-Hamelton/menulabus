-- Modifiers soft-delete migration (track 5.5).
-- Run once on each tenant DB (idempotent via IF NOT EXISTS).
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

ALTER TABLE modifier_groups
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL AFTER sort_order;

ALTER TABLE modifier_options
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL AFTER sort_order;

ALTER TABLE modifier_groups
    ADD INDEX IF NOT EXISTS idx_modifier_groups_deleted_at (deleted_at);

ALTER TABLE modifier_options
    ADD INDEX IF NOT EXISTS idx_modifier_options_deleted_at (deleted_at);
