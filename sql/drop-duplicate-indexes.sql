-- drop-duplicate-indexes.sql
-- Удаление дублирующих индексов после добавления performance-indexes.sql
-- Каждый лишний индекс замедляет INSERT/UPDATE и тратит RAM.

-- orders: idx_status_created дублируется idx_orders_status_created (наш лучше — DESC)
DROP INDEX idx_status_created ON orders;

-- orders: idx_user_created — полный дубль idx_orders_user_created
DROP INDEX idx_user_created ON orders;

-- orders: idx_updated — полный дубль idx_orders_updated_at
DROP INDEX idx_updated ON orders;

-- orders: idx_user_status — полный дубль idx_orders_user_status
DROP INDEX idx_user_status ON orders;

-- orders: idx_created_at — полный дубль idx_orders_created
DROP INDEX idx_created_at ON orders;

-- order_items: idx_order_items_order_id покрывается idx_order_items_order_item_qty
DROP INDEX idx_order_items_order_id ON order_items;
