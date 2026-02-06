<?php
/**
 * Скрипт оптимизации индексов БД для Фазы 2 roadmap
 * Выполнить один раз на проде после бэкапа БД
 * Требует прав SUPER или ALTER на таблицах
 */

require_once __DIR__ . '/db.php';

$db = Database::getInstance();

$indexes = [
    // menu_items
    "ALTER TABLE menu_items ADD INDEX idx_category_available (category, available)",
    "ALTER TABLE menu_items ADD INDEX idx_available (available)",
    
    // orders
    "ALTER TABLE orders ADD INDEX idx_user_created (user_id, created_at DESC)",
    "ALTER TABLE orders ADD INDEX idx_status_created (status, created_at DESC)",
    "ALTER TABLE orders ADD INDEX idx_updated (updated_at)",
    
    // users
    "ALTER TABLE users ADD INDEX idx_email (email)",
    "ALTER TABLE users ADD INDEX idx_role_active (role, is_active)",
    
    // auth_tokens
    "ALTER TABLE auth_tokens ADD INDEX idx_selector_expires (selector, expires_at)",
    
    // order_status_history
    "ALTER TABLE order_status_history ADD INDEX idx_order_changed (order_id, changed_at DESC)",
    
    // JSON optimization for orders
    "ALTER TABLE orders ADD COLUMN items_count INT GENERATED ALWAYS AS (JSON_LENGTH(items)) STORED",
    "ALTER TABLE orders ADD INDEX idx_items_count (items_count)"
];

echo "Оптимизация индексов БД...\n\n";

foreach ($indexes as $sql) {
    try {
        echo "Выполняем: " . substr($sql, 0, 80) . " ...\n";
        $db->getConnection()->exec($sql);
        echo "✅ OK\n";
    } catch (Exception $e) {
        echo "❌ Ошибка: " . $e->getMessage() . "\n";
    }
}

echo "\nОптимизация завершена.\n";
echo "Выполните ANALYZE TABLE и OPTIMIZE TABLE для всех таблиц.\n";

?>
