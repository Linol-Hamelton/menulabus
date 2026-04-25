-- Reservations migration: table booking system.
-- Run once on each tenant DB (idempotent: CREATE TABLE IF NOT EXISTS).
--
-- Shape notes:
--   - table_label is a free-text identifier matching the convention used in
--     orders.delivery_details (no FK to a `tables` table — that table does
--     not exist in the schema; tables are addressed by label/QR slug).
--   - user_id is nullable: guest reservations are first-class.
--   - status uses English keys; the UI maps to display strings (ru/en/etc).
--     Allowed values: 'pending', 'confirmed', 'seated', 'cancelled', 'no_show'.
--   - starts_at / ends_at are stored as DATETIME in tenant local time, the
--     same convention as orders.created_at on this codebase.
--   - guests_count is bounded 1..50 to catch obvious bad input early; UI
--     should additionally constrain by the tenant's table capacities.
--   - Index idx_reservations_table_time supports the OVERLAP query used by
--     Database::checkTableAvailable() — the hottest read on this table.
CREATE TABLE IF NOT EXISTS reservations (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    table_label     VARCHAR(64) NOT NULL,
    user_id         INT DEFAULT NULL,
    guest_name      VARCHAR(255) DEFAULT NULL,
    guest_phone     VARCHAR(32) DEFAULT NULL,
    guests_count    TINYINT UNSIGNED NOT NULL,
    starts_at       DATETIME NOT NULL,
    ends_at         DATETIME NOT NULL,
    status          VARCHAR(32) NOT NULL DEFAULT 'pending',
    note            TEXT DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at    DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_reservations_table_time (table_label, starts_at, ends_at),
    KEY idx_reservations_status_time (status, starts_at),
    KEY idx_reservations_user_starts (user_id, starts_at),
    CONSTRAINT chk_reservations_status
        CHECK (status IN ('pending','confirmed','seated','cancelled','no_show')),
    CONSTRAINT chk_reservations_guests
        CHECK (guests_count BETWEEN 1 AND 50),
    CONSTRAINT chk_reservations_window
        CHECK (ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
