-- sql/preorder-kitchen-migration.sql — Phase 43 (предзаказы + kitchen routing).
--
-- 1. orders.scheduled_for — клиент выбрал «забрать к 19:00». NULL = ASAP.
--    Employee dashboard sorts so scheduled orders bubble up when due.
--
-- 2. menu_items.kitchen_station — какая станция готовит. Используется в KDS
--    с ?station=hot|cold|bar для разделения экранов.

SET @col_sched = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'orders'
       AND COLUMN_NAME = 'scheduled_for'
);
-- Note: no AFTER <col> clause — это создавало hard-dep на courier-migration
-- (delivery_delivered_at). Расположение колонки косметика, не функциональность.
SET @sql_sched = IF(@col_sched = 0,
    "ALTER TABLE orders
        ADD COLUMN scheduled_for DATETIME NULL,
        ADD KEY idx_orders_scheduled (scheduled_for)",
    "SELECT 'orders.scheduled_for already exists'"
);
PREPARE stmt_sched FROM @sql_sched;
EXECUTE stmt_sched;
DEALLOCATE PREPARE stmt_sched;

SET @col_ks = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'menu_items'
       AND COLUMN_NAME = 'kitchen_station'
);
SET @sql_ks = IF(@col_ks = 0,
    "ALTER TABLE menu_items
        ADD COLUMN kitchen_station VARCHAR(32) NULL,
        ADD KEY idx_menu_kitchen_station (kitchen_station)",
    "SELECT 'menu_items.kitchen_station already exists'"
);
PREPARE stmt_ks FROM @sql_ks;
EXECUTE stmt_ks;
DEALLOCATE PREPARE stmt_ks;
