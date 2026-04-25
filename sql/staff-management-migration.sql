-- Staff management migration (Phase 7.4).
-- Run once on each tenant DB. Idempotent.
--
-- Data model:
--   shifts: a planned time range for a user. role is copied from users.role
--           at write time so reports survive a later role change.
--   time_entries: actual clock-in / clock-out events. One per shift check-in;
--                 `clocked_out_at` NULL while the shift is active.
--   tip_splits: per-pay-period allocation of pooled tips. Owner defines a
--               period (e.g. a week), the app aggregates orders.tips in that
--               window, distributes by weighted hours per role.
--
--   All three tables reference users via ON DELETE SET NULL so offboarding
--   a user doesn't delete pay records.

CREATE TABLE IF NOT EXISTS shifts (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT DEFAULT NULL,
    role          VARCHAR(32) NOT NULL,
    location_id   INT UNSIGNED DEFAULT NULL,
    starts_at     DATETIME NOT NULL,
    ends_at       DATETIME NOT NULL,
    note          VARCHAR(255) DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_shifts_user_time (user_id, starts_at),
    KEY idx_shifts_role_time (role, starts_at),
    CONSTRAINT chk_shifts_window CHECK (ends_at > starts_at),
    CONSTRAINT fk_shifts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS time_entries (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT DEFAULT NULL,
    shift_id        INT UNSIGNED DEFAULT NULL,
    clocked_in_at   DATETIME NOT NULL,
    clocked_out_at  DATETIME DEFAULT NULL,
    minutes         INT UNSIGNED DEFAULT NULL,
    note            VARCHAR(255) DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_time_entries_user_in (user_id, clocked_in_at),
    KEY idx_time_entries_open (user_id, clocked_out_at),
    CONSTRAINT fk_time_entries_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE SET NULL,
    CONSTRAINT fk_time_entries_shift FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tip_splits (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    period_from     DATETIME NOT NULL,
    period_to       DATETIME NOT NULL,
    tips_pool       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    allocation_json JSON NOT NULL,
    created_by      INT DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tip_splits_period (period_from, period_to),
    CONSTRAINT chk_tip_splits_window CHECK (period_to > period_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
