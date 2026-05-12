-- sql/aggregator-migration.sql — Phase 36 (Yandex.Еда + Delivery Club).
--
-- Idempotent. Tenant-DB only.
-- Apply per tenant:
--   mysql --protocol=SOCKET -u root <DB_NAME> < sql/aggregator-migration.sql
--
-- Inbound: webhook receives order POST → orders row with aggregator_*.
-- Outbound: cron pushes status updates back to aggregator on lifecycle events.

CREATE TABLE IF NOT EXISTS aggregator_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider VARCHAR(32) NOT NULL,            -- 'yandex_eda' | 'delivery_club'
    api_key VARCHAR(255) NOT NULL DEFAULT '',
    webhook_secret VARCHAR(64) NOT NULL DEFAULT '',
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    last_webhook_at DATETIME NULL,
    last_push_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_aggregator_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- orders.aggregator_* — source + tracking columns.
SET @col_src = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'orders'
       AND COLUMN_NAME = 'aggregator_source'
);
SET @sql_src = IF(@col_src = 0,
    "ALTER TABLE orders
        ADD COLUMN aggregator_source VARCHAR(32) NULL AFTER shift_id,
        ADD COLUMN aggregator_order_id VARCHAR(64) NULL AFTER aggregator_source,
        ADD COLUMN aggregator_status VARCHAR(32) NULL AFTER aggregator_order_id,
        ADD COLUMN aggregator_payload JSON NULL AFTER aggregator_status,
        ADD KEY idx_orders_aggregator_src (aggregator_source, aggregator_order_id)",
    "SELECT 'orders.aggregator_source already exists'"
);
PREPARE stmt_src FROM @sql_src;
EXECUTE stmt_src;
DEALLOCATE PREPARE stmt_src;

-- orders.user_id — relax NOT NULL so aggregator-sourced orders (no local user) can be created.
SET @col_uid_nullable = (
    SELECT IS_NULLABLE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'orders'
       AND COLUMN_NAME = 'user_id'
);
SET @sql_uid = IF(@col_uid_nullable = 'NO',
    "ALTER TABLE orders MODIFY COLUMN user_id INT NULL",
    "SELECT 'orders.user_id already nullable'"
);
PREPARE stmt_uid FROM @sql_uid;
EXECUTE stmt_uid;
DEALLOCATE PREPARE stmt_uid;

-- menu_items.aggregator_*_id — mapping from external product IDs.
SET @col_y = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'menu_items'
       AND COLUMN_NAME = 'aggregator_yandex_id'
);
SET @sql_y = IF(@col_y = 0,
    "ALTER TABLE menu_items
        ADD COLUMN aggregator_yandex_id VARCHAR(64) NULL,
        ADD COLUMN aggregator_dc_id VARCHAR(64) NULL,
        ADD KEY idx_menu_aggregator_yandex (aggregator_yandex_id),
        ADD KEY idx_menu_aggregator_dc (aggregator_dc_id)",
    "SELECT 'menu_items.aggregator_yandex_id already exists'"
);
PREPARE stmt_y FROM @sql_y;
EXECUTE stmt_y;
DEALLOCATE PREPARE stmt_y;
