-- Kitchen Display System migration (Phase 6.1).
-- Run once on each tenant DB. All statements idempotent (IF NOT EXISTS).
--
-- Data model:
--   kitchen_stations:  per-tenant stations (hot / cold / bar / pizza / ...).
--                      `sort_order` controls left-to-right order on the admin
--                      station picker; `active` is a soft on/off so retired
--                      stations don't pollute the routing table without losing
--                      historical order_item_status rows.
--   menu_item_stations: many-to-many. A dish can plate on multiple stations
--                       (e.g. pizza + drink) — each station gets its own
--                       order_item_status row and own "ready" flip.
--   order_item_status: one row per (order, item slot, station). The "item slot"
--                       is the index into orders.items JSON array, because the
--                       project does not normalize order lines into their own
--                       primary key. If `orders.items` contains the same dish
--                       twice, we get two slots and two independent status rows
--                       per station.
--                       `station_id` is nullable to preserve history when a
--                       station is later deleted (ON DELETE SET NULL), so the
--                       KDS audit trail survives reorgs.
--
-- Status machine (VARCHAR, validated by CHECK):
--   queued  -> cooking  -> ready
--   queued  -> cancelled (only when the whole order is cancelled)
--   cooking -> ready
--   A row in `ready` is terminal for routing purposes; `cancelled` is terminal
--   across the board and excluded from all active SELECTs.
--
-- When every station row for an order has status='ready', the order itself
-- is considered cooked — that's the trigger for the `order.ready` webhook and
-- a Telegram ping to the waiter. Detection lives in db.php, not in SQL, so the
-- check stays transactional with the last status flip.

CREATE TABLE IF NOT EXISTS kitchen_stations (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    label       VARCHAR(64) NOT NULL,
    slug        VARCHAR(32) NOT NULL,
    active      TINYINT UNSIGNED NOT NULL DEFAULT 1,
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_kitchen_stations_slug (slug),
    KEY idx_kitchen_stations_active_sort (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_item_stations (
    menu_item_id INT NOT NULL,
    station_id   INT UNSIGNED NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (menu_item_id, station_id),
    KEY idx_menu_item_stations_station (station_id),
    CONSTRAINT fk_menu_item_stations_item
        FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_menu_item_stations_station
        FOREIGN KEY (station_id)   REFERENCES kitchen_stations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_item_status (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id      INT NOT NULL,
    item_index    SMALLINT UNSIGNED NOT NULL,
    menu_item_id  INT DEFAULT NULL,
    item_name     VARCHAR(255) DEFAULT NULL,
    quantity      INT NOT NULL DEFAULT 1,
    station_id    INT UNSIGNED DEFAULT NULL,
    status        VARCHAR(16) NOT NULL DEFAULT 'queued',
    started_at    DATETIME DEFAULT NULL,
    ready_at      DATETIME DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ois_station_status (station_id, status, created_at),
    KEY idx_ois_order (order_id),
    CONSTRAINT chk_ois_status
        CHECK (status IN ('queued', 'cooking', 'ready', 'cancelled')),
    CONSTRAINT fk_ois_order
        FOREIGN KEY (order_id)   REFERENCES orders(id)             ON DELETE CASCADE,
    CONSTRAINT fk_ois_station
        FOREIGN KEY (station_id) REFERENCES kitchen_stations(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
