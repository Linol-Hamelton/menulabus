-- performance-indexes.sql
-- Составные индексы для ускорения частых запросов в db.php.
-- Безопасно выполнять на production — InnoDB создаёт индексы онлайн.
--
-- Перед выполнением убедитесь, что индекс ещё не существует:
--   SHOW INDEX FROM orders;
--   SHOW INDEX FROM order_items;
--   SHOW INDEX FROM users;
--   SHOW INDEX FROM auth_tokens;

-- =====================================================================
-- orders: getSalesReport, getProfitReport, getEfficiencyReport, etc.
-- Покрывает WHERE status + created_at + фильтры по дате
-- =====================================================================

CREATE INDEX idx_orders_status_created
    ON orders (status, created_at DESC)
;

-- orders: getUserOrders (user_id + created_at DESC)
CREATE INDEX idx_orders_user_created
    ON orders (user_id, created_at DESC)
;

-- orders: getOrderUpdatesSince (updated_at для SSE polling)
CREATE INDEX idx_orders_updated_at
    ON orders (updated_at)
;

-- orders: getEmployeeStats (last_updated_by + status + created_at)
CREATE INDEX idx_orders_updater_status_created
    ON orders (last_updated_by, status, created_at DESC)
;

-- =====================================================================
-- order_items: JOIN в отчётах (oi_agg подзапрос)
-- Покрывающий индекс: order_id + item_id + quantity (+ price через INCLUDE)
-- =====================================================================

CREATE INDEX idx_order_items_order_item_qty
    ON order_items (order_id, item_id, quantity)
;

-- =====================================================================
-- users: getUserByEmail для логина
-- =====================================================================

CREATE INDEX idx_users_email_active
    ON users (email, is_active)
;

-- =====================================================================
-- auth_tokens: Remember Me lookup по selector
-- =====================================================================

CREATE INDEX idx_auth_tokens_selector_expires
    ON auth_tokens (selector, expires_at)
;

-- =====================================================================
-- menu_items: getMenuItems (available + category)
-- =====================================================================

CREATE INDEX idx_menu_items_available_category
    ON menu_items (available, category, name)
;

-- =====================================================================
-- Проверка: посмотреть все индексы после выполнения
-- =====================================================================
-- SHOW INDEX FROM orders;
-- SHOW INDEX FROM order_items;
-- SHOW INDEX FROM users;
-- SHOW INDEX FROM auth_tokens;
-- SHOW INDEX FROM menu_items;
