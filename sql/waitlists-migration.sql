-- Waitlists migration (Phase 8.4).
-- Run once on each tenant DB. Idempotent via IF NOT EXISTS.
--
-- Data model:
--   waitlist_entries: guests who can't book because a desired slot/day is full.
--     Not strictly "I want THIS table at THIS time" — rather "I want ANY table
--     for N guests on this date near this hour". When a reservation is
--     cancelled or a table frees up close to the requested hour, staff can see
--     who's waiting and notify them.
--     `contact` = phone, because we can't rely on in-app push for a guest that
--     hasn't logged in. We SMS or call.
--     status lifecycle: waiting -> notified -> seated | cancelled | expired.

CREATE TABLE IF NOT EXISTS waitlist_entries (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT DEFAULT NULL,
    guest_name      VARCHAR(255) DEFAULT NULL,
    guest_phone     VARCHAR(32) NOT NULL,
    guests_count    TINYINT UNSIGNED NOT NULL,
    preferred_date  DATE NOT NULL,
    preferred_time  TIME DEFAULT NULL,
    location_id     INT UNSIGNED DEFAULT NULL,
    note            TEXT DEFAULT NULL,
    status          VARCHAR(16) NOT NULL DEFAULT 'waiting',
    notified_at     DATETIME DEFAULT NULL,
    resolved_at     DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_waitlist_status_date (status, preferred_date, preferred_time),
    KEY idx_waitlist_phone (guest_phone),
    CONSTRAINT chk_waitlist_status
        CHECK (status IN ('waiting', 'notified', 'seated', 'cancelled', 'expired')),
    CONSTRAINT chk_waitlist_guests
        CHECK (guests_count BETWEEN 1 AND 50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
