-- Review moderation migration (Phase 8.5).
-- Run once on each tenant DB. Idempotent.
--
-- Adds two columns to `reviews`:
--   reply_text        — owner response, shown on tenant homepage when the
--                       review is published (and on /order-track.php to the
--                       original guest).
--   published_at      — set by the owner to mark this review as ok to appear
--                       on the tenant homepage. NULL = internal-only (visible
--                       on owner page, not on public surface).
--   replied_at        — stamped when `reply_text` is first written; lets the
--                       UI render "ответ через N часов" for trust signal.

ALTER TABLE reviews
    ADD COLUMN IF NOT EXISTS reply_text TEXT DEFAULT NULL AFTER comment;
ALTER TABLE reviews
    ADD COLUMN IF NOT EXISTS published_at DATETIME DEFAULT NULL AFTER ip_hash;
ALTER TABLE reviews
    ADD COLUMN IF NOT EXISTS replied_at DATETIME DEFAULT NULL AFTER reply_text;
ALTER TABLE reviews
    ADD INDEX IF NOT EXISTS idx_reviews_published (published_at, rating);
