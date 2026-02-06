<?php
/**
 * Улучшенный скрипт оптимизации индексов БД
 * Проверяет существующие индексы и добавляет только отсутствующие.
 * Также проверяет наличие столбца items_count перед добавлением.
 */

require_once __DIR__ . '/db.php';

$db = Database::getInstance();
$pdo = $db->getConnection();

// Определяем индексы, которые нужно проверить/добавить
$indexes = [
    'menu_items' => [
        'idx_category_available' => "ALTER TABLE menu_items ADD INDEX idx_category_available (category, available)",
        'idx_available'          => "ALTER TABLE menu_items ADD INDEX idx_available (available)",
    ],
    'orders' => [
        'idx_user_created'       => "ALTER TABLE orders ADD INDEX idx_user_created (user_id, created_at DESC)",
        'idx_status_created'     => "ALTER TABLE orders ADD INDEX idx_status_created (status, created_at DESC)",
        'idx_updated'            => "ALTER TABLE orders ADD INDEX idx_updated (updated_at)",
        'idx_items_count'        => "ALTER TABLE orders ADD INDEX idx_items_count (items_count)",
    ],
    'users' => [
        'idx_email'              => "ALTER TABLE users ADD INDEX idx_email (email)",
        'idx_role_active'        => "ALTER TABLE users ADD INDEX idx_role_active (role, is_active)",
    ],
    'auth_tokens' => [
        'idx_selector_expires'   => "ALTER TABLE auth_tokens ADD INDEX idx_selector_expires (selector, expires_at)",
    ],
    'order_status_history' => [
        'idx_order_changed'      => "ALTER TABLE order_status_history ADD INDEX idx_order_changed (order_id, changed_at DESC)",
    ],
];

// Проверка существования столбца items_count в orders
function columnExists($pdo, $table, $column) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM $table LIKE '$column'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Проверка существования индекса
function indexExists($pdo, $table, $indexName) {
    try {
        $stmt = $pdo->query("SHOW INDEX FROM $table WHERE Key_name = '$indexName'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

echo "Оптимизация индексов БД (проверка существующих)...\n\n";

// Добавляем generated column items_count, если ещё нет
if (!columnExists($pdo, 'orders', 'items_count')) {
    echo "Добавляем столбец items_count в таблицу orders...\n";
    try {
        $sql = "ALTER TABLE orders ADD COLUMN items_count INT GENERATED ALWAYS AS (JSON_LENGTH(items)) STORED";
        $pdo->exec($sql);
        echo "✅ Столбец items_count добавлен.\n";
    } catch (Exception $e) {
        echo "❌ Ошибка при добавлении столбца items_count: " . $e->getMessage() . "\n";
    }
} else {
    echo "✅ Столбец items_count уже существует.\n";
}

// Обрабатываем индексы
foreach ($indexes as $table => $indexList) {
    echo "\n--- Таблица: $table ---\n";
    foreach ($indexList as $indexName => $sql) {
        if (indexExists($pdo, $table, $indexName)) {
            echo "⏩ Индекс $indexName уже существует, пропускаем.\n";
        } else {
            echo "Добавляем индекс $indexName...\n";
            try {
                $pdo->exec($sql);
                echo "✅ Индекс $indexName добавлен.\n";
            } catch (Exception $e) {
                echo "❌ Ошибка при добавлении индекса $indexName: " . $e->getMessage() . "\n";
            }
        }
    }
}

// Рекомендуемые анализы
echo "\n\nРекомендуется выполнить:\n";
echo "ANALYZE TABLE menu_items, orders, users, auth_tokens, order_status_history;\n";
echo "OPTIMIZE TABLE menu_items, orders, users, auth_tokens, order_status_history;\n";

echo "\nОптимизация завершена.\n";
?>