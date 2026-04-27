-- Staff management v2 migration (Phase 7.4 v2, 2026-04-28).
--
-- Adds two follow-up tables on top of the Phase 7.4 baseline (shifts,
-- time_entries, tip_splits):
--
--   shift_swap_requests: an employee asks "I can't make my shift —
--     who'll take it?". Manager (owner/admin) approves or denies via
--     the staff dashboard. On approval the requesting employee's
--     shifts row is reassigned to the volunteer.
--
--   tips_distribution_rules: per-period rule overriding the default
--     equal-by-hours split. Rules: equal | by_hours | by_orders | manual.
--     Manual rules also write a tips_manual_overrides row keyed by
--     (period, user_id, amount).
--
-- All idempotent (CREATE TABLE IF NOT EXISTS); cross-references existing
-- shifts.id via FK with ON DELETE CASCADE so swap requests vanish if
-- their target shift is removed.

CREATE TABLE IF NOT EXISTS shift_swap_requests (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shift_id        INT UNSIGNED NOT NULL,
    requester_id    INT NOT NULL,
    volunteer_id    INT DEFAULT NULL,
    status          VARCHAR(16) NOT NULL DEFAULT 'open',
    note            VARCHAR(255) DEFAULT NULL,
    requested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at      DATETIME DEFAULT NULL,
    decided_by      INT DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_ssr_shift (shift_id),
    KEY idx_ssr_status (status, requested_at),
    CONSTRAINT chk_ssr_status
        CHECK (status IN ('open', 'volunteer_offered', 'approved', 'denied', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tips_distribution_rules (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    period_start DATE NOT NULL,
    period_end   DATE NOT NULL,
    rule_type    VARCHAR(16) NOT NULL DEFAULT 'by_hours',
    notes        TEXT DEFAULT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_tdr_period (period_start, period_end),
    CONSTRAINT chk_tdr_rule
        CHECK (rule_type IN ('equal', 'by_hours', 'by_orders', 'manual')),
    CONSTRAINT chk_tdr_window
        CHECK (period_end >= period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tips_manual_overrides (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_id     BIGINT UNSIGNED NOT NULL,
    user_id     INT NOT NULL,
    amount      DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_tmo_rule (rule_id),
    CONSTRAINT fk_tmo_rule
        FOREIGN KEY (rule_id) REFERENCES tips_distribution_rules(id) ON DELETE CASCADE,
    CONSTRAINT chk_tmo_amount
        CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
