-- sql/courier-migration.sql — Phase 42 (своя курьерская доставка).
--
-- Workflow:
--   1. Manager (admin/owner) assigns курьера (любой employee/admin) на delivery-заказ.
--   2. Курьер на /employee.php видит «Мои доставки» с своими заказами.
--   3. Курьер нажимает «Забрал» → picked_up_at + status='доставляем'.
--   4. Курьер нажимает «Доставил» → delivered_at + status='завершён'.

-- orders.courier_id + pickup/deliver timestamps. INFORMATION_SCHEMA guard.
SET @col_courier = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'orders'
       AND COLUMN_NAME = 'courier_id'
);
SET @sql_courier = IF(@col_courier = 0,
    "ALTER TABLE orders
        ADD COLUMN courier_id INT NULL AFTER shift_id,
        ADD COLUMN delivery_picked_up_at DATETIME NULL AFTER courier_id,
        ADD COLUMN delivery_delivered_at DATETIME NULL AFTER delivery_picked_up_at,
        ADD KEY idx_orders_courier (courier_id, status)",
    "SELECT 'orders.courier_id already exists'"
);
PREPARE stmt_courier FROM @sql_courier;
EXECUTE stmt_courier;
DEALLOCATE PREPARE stmt_courier;
