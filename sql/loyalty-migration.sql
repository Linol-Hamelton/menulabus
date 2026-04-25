-- Loyalty Program migration (Phase 6.3).
-- Run once on each tenant DB. All statements idempotent (IF NOT EXISTS).
--
-- Data model:
--   loyalty_tiers:        per-tenant tier rules. `min_spent` is the lifetime
--                         amount (in ₽, or whatever the tenant's display currency
--                         is) at which the tier activates; `cashback_pct` is the
--                         fraction of the order total that becomes points on
--                         every paid order. Seeded in code, not forced here.
--   loyalty_accounts:     one row per user who has ever earned a point.
--                         `points_balance` is authoritative; `total_spent` is
--                         the lifetime amount used to recompute the current tier.
--                         `tier_id` is snapshot-cached to avoid repeated
--                         tier-math on every cart view.
--   loyalty_transactions: append-only ledger; positive = earn, negative = redeem.
--                         `order_id` links back to the triggering order.
--   promo_codes:          per-tenant codes. Either `discount_pct` or
--                         `discount_amount` is populated — never both (app-layer
--                         invariant, not a CHECK constraint, so the server can
--                         validate with a clearer error message).
--                         `usage_limit` = 0 means unlimited.

CREATE TABLE IF NOT EXISTS loyalty_tiers (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(64) NOT NULL,
    min_spent       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cashback_pct    DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    sort_order      INT NOT NULL DEFAULT 0,
    archived_at     DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_loyalty_tiers_sort (archived_at, sort_order, min_spent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loyalty_accounts (
    user_id         INT NOT NULL,
    points_balance  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_spent     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tier_id         INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    KEY idx_loyalty_accounts_tier (tier_id),
    CONSTRAINT fk_loyalty_accounts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_loyalty_accounts_tier FOREIGN KEY (tier_id) REFERENCES loyalty_tiers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loyalty_transactions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT NOT NULL,
    points_delta    DECIMAL(12,2) NOT NULL,
    reason          VARCHAR(32) NOT NULL,
    order_id        INT DEFAULT NULL,
    note            VARCHAR(255) DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_loyalty_tx_user_created (user_id, created_at),
    KEY idx_loyalty_tx_order (order_id),
    CONSTRAINT chk_loyalty_tx_reason CHECK (reason IN ('accrual', 'redeem', 'manual', 'expire', 'birthday', 'refund')),
    CONSTRAINT fk_loyalty_tx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_loyalty_tx_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promo_codes (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code              VARCHAR(64) NOT NULL,
    discount_pct      DECIMAL(5,2) DEFAULT NULL,
    discount_amount   DECIMAL(12,2) DEFAULT NULL,
    min_order_total   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    valid_from        DATETIME DEFAULT NULL,
    valid_to          DATETIME DEFAULT NULL,
    usage_limit       INT UNSIGNED NOT NULL DEFAULT 0,
    used_count        INT UNSIGNED NOT NULL DEFAULT 0,
    description       VARCHAR(255) DEFAULT NULL,
    archived_at       DATETIME DEFAULT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_promo_codes_code (code),
    KEY idx_promo_codes_window (archived_at, valid_from, valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
