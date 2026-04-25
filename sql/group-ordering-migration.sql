-- Group ordering migration (Phase 8.3).
-- Run once on each tenant DB. Idempotent.
--
-- Data model:
--   group_orders: one row per "shared tab" at a table. Host creates it by
--     opening a QR at the table; each subsequent guest scans the same code,
--     lands at /group/<code>, adds items tied to their seat number, and the
--     staff-facing screen aggregates per seat for split-bill or unified payout.
--
--     `code` is an 8-char url-safe token (unique). `host_user_id` nullable —
--     guests can open groups without logging in.
--
--     status lifecycle: open → submitted → paid → closed.
--     'submitted' is when the host clicks "send to kitchen" — from that point
--     items are frozen into a real order (or a set of orders, one per seat,
--     depending on the bill mode).
--
--   group_order_items: per-seat items pushed into the shared tab while it's
--     still 'open'. These are NOT yet real order lines; they become orders.items
--     at submit time. `seat_label` is free-form ("Я", "Маша", "Seat 3") so the
--     host doesn't need to coordinate numbering.

CREATE TABLE IF NOT EXISTS group_orders (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code           VARCHAR(16) NOT NULL,
    host_user_id   INT DEFAULT NULL,
    table_label    VARCHAR(64) DEFAULT NULL,
    location_id    INT UNSIGNED DEFAULT NULL,
    status         VARCHAR(16) NOT NULL DEFAULT 'open',
    submitted_at   DATETIME DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_group_orders_code (code),
    KEY idx_group_orders_status (status, created_at),
    CONSTRAINT chk_group_orders_status
        CHECK (status IN ('open', 'submitted', 'paid', 'closed', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_order_items (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_order_id INT UNSIGNED NOT NULL,
    seat_label     VARCHAR(64) NOT NULL,
    menu_item_id   INT NOT NULL,
    item_name      VARCHAR(255) DEFAULT NULL,
    quantity       INT NOT NULL DEFAULT 1,
    unit_price     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    note           VARCHAR(255) DEFAULT NULL,
    added_by       INT DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_goi_group_seat (group_order_id, seat_label),
    CONSTRAINT fk_goi_group
        FOREIGN KEY (group_order_id) REFERENCES group_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_goi_item
        FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
