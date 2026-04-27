-- Review moderation migration (Phase 8.5).
-- Run once on each tenant DB. Idempotent (re-runs are no-ops).
--
-- Adds three columns + an index to `reviews`:
--   reply_text        — owner response, shown on tenant homepage when the
--                       review is published (and on /order-track.php to the
--                       original guest).
--   published_at      — set by the owner to mark this review as ok to appear
--                       on the tenant homepage. NULL = internal-only (visible
--                       on owner page, not on public surface).
--   replied_at        — stamped when `reply_text` is first written; lets the
--                       UI render "ответ через N часов" for trust signal.
--
-- Idempotency: ADD COLUMN/INDEX IF NOT EXISTS need MySQL 8.0.29+; production
-- runs older 8.0.x. Use INFORMATION_SCHEMA + PREPARE/EXECUTE — works on every
-- 8.0.x.

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' AND COLUMN_NAME = 'reply_text');
SET @ddl := IF(@c = 0,
               'ALTER TABLE reviews ADD COLUMN reply_text TEXT DEFAULT NULL AFTER comment',
               'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' AND COLUMN_NAME = 'published_at');
SET @ddl := IF(@c = 0,
               'ALTER TABLE reviews ADD COLUMN published_at DATETIME DEFAULT NULL AFTER ip_hash',
               'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' AND COLUMN_NAME = 'replied_at');
SET @ddl := IF(@c = 0,
               'ALTER TABLE reviews ADD COLUMN replied_at DATETIME DEFAULT NULL AFTER reply_text',
               'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @i := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' AND INDEX_NAME = 'idx_reviews_published');
SET @ddl := IF(@i = 0,
               'ALTER TABLE reviews ADD INDEX idx_reviews_published (published_at, rating)',
               'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
