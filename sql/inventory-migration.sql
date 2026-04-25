-- Inventory MVP migration (Phase 6.2).
-- Run once on each tenant DB. All statements idempotent (IF NOT EXISTS).
--
-- Data model:
--   suppliers:        lightweight contact book. Not enforced anywhere —
--                     ingredients.supplier_id is a soft reference kept nullable
--                     so a deleted supplier doesn't cascade into ingredient loss.
--   ingredients:      stock master. `stock_qty` is authoritative, `unit` is a
--                     free-form label ("г", "мл", "шт") — the only invariant is
--                     that recipes use the SAME unit per ingredient.
--                     `reorder_threshold` drives low-stock alerts.
--                     `archived_at` is soft-delete so historical stock_movements
--                     and recipes never dangle.
--   recipes:          many-to-many menu_item ↔ ingredient with per-link quantity.
--                     `quantity` is DECIMAL(10,3) — good for whole grams, tenths
--                     of a millilitre, or fractional "шт" (half a bun).
--   stock_movements:  append-only audit log of every change to `stock_qty`.
--                     `delta` is positive (receipt/adjustment) or negative
--                     (order consumption). `reason` documents origin
--                     ('order', 'adjustment', 'receipt', 'waste', 'stocktake').
--                     `order_id` is nullable so non-order movements fit the
--                     same table. FK to orders uses ON DELETE SET NULL for the
--                     same reason reservations/kds do — the audit trail must
--                     survive order cleanup.
--
-- Deduction is transactional in Database::deductIngredientsForOrder() — either
-- the whole order's recipes apply or none do. Partial deductions would leak
-- phantom "half-cooked" state into the audit log and break reconciliation.

CREATE TABLE IF NOT EXISTS suppliers (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(255) NOT NULL,
    contact     VARCHAR(255) DEFAULT NULL,
    notes       TEXT DEFAULT NULL,
    archived_at DATETIME DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_suppliers_archived (archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ingredients (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name               VARCHAR(255) NOT NULL,
    unit               VARCHAR(16) NOT NULL DEFAULT 'шт',
    stock_qty          DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    reorder_threshold  DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    cost_per_unit      DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    supplier_id        INT UNSIGNED DEFAULT NULL,
    archived_at        DATETIME DEFAULT NULL,
    last_alerted_at    DATETIME DEFAULT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ingredients_archived (archived_at),
    KEY idx_ingredients_low_stock (archived_at, stock_qty, reorder_threshold),
    CONSTRAINT fk_ingredients_supplier
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipes (
    menu_item_id  INT NOT NULL,
    ingredient_id INT UNSIGNED NOT NULL,
    quantity      DECIMAL(10,3) NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (menu_item_id, ingredient_id),
    KEY idx_recipes_ingredient (ingredient_id),
    CONSTRAINT chk_recipes_qty CHECK (quantity > 0),
    CONSTRAINT fk_recipes_menu_item
        FOREIGN KEY (menu_item_id)  REFERENCES menu_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_recipes_ingredient
        FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_movements (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ingredient_id  INT UNSIGNED NOT NULL,
    delta          DECIMAL(12,3) NOT NULL,
    reason         VARCHAR(32) NOT NULL,
    note           VARCHAR(255) DEFAULT NULL,
    order_id       INT DEFAULT NULL,
    menu_item_id   INT DEFAULT NULL,
    created_by     INT DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_stock_movements_ingredient (ingredient_id, created_at),
    KEY idx_stock_movements_order (order_id),
    CONSTRAINT chk_stock_movements_reason
        CHECK (reason IN ('order', 'adjustment', 'receipt', 'waste', 'stocktake', 'undo')),
    CONSTRAINT fk_stock_movements_ingredient
        FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE,
    CONSTRAINT fk_stock_movements_order
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    CONSTRAINT fk_stock_movements_item
        FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
