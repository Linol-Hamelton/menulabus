-- Multi-location migration (Phase 6.5).
-- Run once on each tenant DB. All statements idempotent (IF NOT EXISTS).
--
-- Data model:
--   locations: one row per physical restaurant in the chain within a single
--              tenant DB. We deliberately NOT split into separate databases per
--              location — Phase 2's "1 client = 1 database" rule sits at the
--              TENANT boundary (a chain is one tenant); locations live inside.
--   NULL vs. value on location_id:
--     - orders.location_id NULL  = legacy pre-location order. Backfill script
--       optional; NULL rows fall through to "default location" filters.
--     - menu_items.location_id NULL = dish available in EVERY location
--       (chain-wide item). Set to a specific id only when a location carries a
--       unique variant.
--     - reservations.location_id NULL = rare pre-migration row; new booking
--       flow always picks a location up-front.
--
-- Backward-compatible: existing code paths that don't pass a location filter
-- keep working unchanged — all queries default to "across all locations" when
-- no scope is provided.

CREATE TABLE IF NOT EXISTS locations (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(255) NOT NULL,
    address     VARCHAR(500) DEFAULT NULL,
    phone       VARCHAR(32) DEFAULT NULL,
    timezone    VARCHAR(64) NOT NULL DEFAULT 'Europe/Moscow',
    active      TINYINT UNSIGNED NOT NULL DEFAULT 1,
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_locations_active (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS location_id INT UNSIGNED DEFAULT NULL AFTER delivery_details;
ALTER TABLE orders
    ADD INDEX IF NOT EXISTS idx_orders_location_created (location_id, created_at DESC);

ALTER TABLE menu_items
    ADD COLUMN IF NOT EXISTS location_id INT UNSIGNED DEFAULT NULL AFTER category;
ALTER TABLE menu_items
    ADD INDEX IF NOT EXISTS idx_menu_items_location_category (location_id, category, sort_order);

ALTER TABLE reservations
    ADD COLUMN IF NOT EXISTS location_id INT UNSIGNED DEFAULT NULL AFTER table_label;
ALTER TABLE reservations
    ADD INDEX IF NOT EXISTS idx_reservations_location_time (location_id, starts_at);
