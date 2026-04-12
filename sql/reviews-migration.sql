-- Reviews migration: create reviews table (append-only customer feedback)
-- Run once on production DB. Matches docs/reviews.md contract.
--
-- Shape notes:
--   - order_id is UNIQUE: one review per order, enforced at the DB layer
--     so duplicate submissions never silently overwrite.
--   - user_id is nullable: guest orders can still leave a review.
--   - rating is TINYINT 1..5, enforced by CHECK (MySQL 8.0.16+).
--   - comment is optional; UI may submit stars-only.
--   - ip_hash is a sha256 hex digest (64 chars) of client IP + app salt,
--     used for rate limiting / abuse review without storing raw PII.
--   - FK to orders uses ON DELETE CASCADE so cleanup scripts don't leave
--     orphan rows.
CREATE TABLE IF NOT EXISTS reviews (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id    INT UNSIGNED NOT NULL,
    user_id     INT DEFAULT NULL,
    rating      TINYINT UNSIGNED NOT NULL,
    comment     TEXT DEFAULT NULL,
    ip_hash     CHAR(64) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_reviews_order_id (order_id),
    KEY idx_reviews_created_at (created_at),
    KEY idx_reviews_rating (rating),
    CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5),
    CONSTRAINT fk_reviews_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
