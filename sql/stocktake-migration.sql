-- sql/stocktake-migration.sql — Phase 41 (физическая инвентаризация).
--
-- Workflow:
--   1. Manager starts a session → snapshot всех ingredients в stocktake_items.
--      expected_qty = current stock_qty, counted_qty = NULL.
--   2. Staff iteratively вводят counted_qty по факту пересчёта.
--   3. Manager closes session → для каждого item с counted_qty != expected_qty
--      делается adjustIngredientStock(delta = counted - expected, reason='stocktake').
--   4. Session.closed_at + closed_by, status=closed.
--
-- Cannot start new session if any open session exists.

CREATE TABLE IF NOT EXISTS stocktake_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(128) NOT NULL,
    status ENUM('open', 'closed', 'cancelled') NOT NULL DEFAULT 'open',
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_by INT NULL,                         -- users.id
    closed_at DATETIME NULL,
    closed_by INT NULL,
    notes TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_stocktake_status (status),
    KEY idx_stocktake_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stocktake_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    ingredient_id INT UNSIGNED NOT NULL,
    expected_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
    counted_qty DECIMAL(12,3) NULL,
    counted_at DATETIME NULL,
    counted_by INT NULL,
    notes TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_stocktake_session_ingredient (session_id, ingredient_id),
    KEY idx_stocktake_items_session (session_id),
    KEY idx_stocktake_items_ingredient (ingredient_id),
    CONSTRAINT fk_stocktake_items_session
        FOREIGN KEY (session_id) REFERENCES stocktake_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_stocktake_items_ingredient
        FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
