<?php
require_once __DIR__ . '/tenant_runtime.php';
require_once __DIR__ . '/lib/orders/lifecycle.php';

if (file_exists(__DIR__ . '/RedisCache.php')) {
    require_once __DIR__ . '/RedisCache.php';
}

class Database
{
    private const PRODUCT_CACHE_TTL = 1800;
    private const MENU_CACHE_TTL = 1800;
    private const CATEGORIES_CACHE_TTL = 3600;
    private $connection;
    private static $instance = null;
    private $preparedStatements = [];
    private $redisCache = null;
    private $productCacheTtl;
    private $menuCacheTtl;
    private $categoriesCacheTtl;
    private $tenantContext = [];
    private $tenantCacheNamespace = 'tenant:legacy';

    private function __construct()
    {
        $this->connect();
        $this->productCacheTtl = $this->resolveCacheTtl('PRODUCT_CACHE_TTL', self::PRODUCT_CACHE_TTL);
        $this->menuCacheTtl = $this->resolveCacheTtl('MENU_CACHE_TTL', self::MENU_CACHE_TTL);
        $this->categoriesCacheTtl = $this->resolveCacheTtl('CATEGORIES_CACHE_TTL', self::CATEGORIES_CACHE_TTL);
        
        if (class_exists('RedisCache')) {
            $this->redisCache = RedisCache::getInstance();
        }
    }

    private function resolveCacheTtl(string $key, int $default): int
    {
        if (defined($key)) {
            $definedValue = constant($key);
            if (is_numeric($definedValue) && (int) $definedValue > 0) {
                return (int) $definedValue;
            }
        }

        $envValue = getenv($key);
        if ($envValue !== false && is_numeric($envValue) && (int) $envValue > 0) {
            return (int) $envValue;
        }

        return $default;
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function scalar(string $sql, array $params = [])
    {
        try {
            $stmt = $this->prepareCached($sql);
            $stmt->execute($params);
            $result = $stmt->fetchColumn();

            return $result;
        } catch (PDOException $e) {
            error_log("scalar() PDO error: " . $e->getMessage());
            return null;
        }
    }

    private function connect()
    {
        try {
            $this->tenantContext = tenant_runtime_require_resolved();
            $this->tenantCacheNamespace = $this->resolveTenantCacheNamespace();
            $persistentEnv = getenv('DB_PDO_PERSISTENT');
            // Default is ON for performance (saves ~5-15ms connection overhead per request).
            // Singleton pattern + PHP-FPM pm.max_requests ensure no state leaks.
            // Set DB_PDO_PERSISTENT=0 to disable if issues arise.
            $usePersistent = filter_var($persistentEnv !== false ? $persistentEnv : '1', FILTER_VALIDATE_BOOLEAN);

            $dbHost = (string)($this->tenantContext['tenant_db_host'] ?? DB_HOST);
            $dbName = (string)($this->tenantContext['tenant_db_name'] ?? DB_NAME);
            $dbUser = (string)($this->tenantContext['tenant_db_user'] ?? DB_USER);
            $dbPass = (string)($this->tenantContext['tenant_db_pass'] ?? DB_PASS);

            $this->connection = new PDO(
                "mysql:host=" . $dbHost . ";dbname=" . $dbName . ";charset=utf8mb4",
                $dbUser,
                $dbPass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => $usePersistent,
                    PDO::ATTR_TIMEOUT => 5,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                    PDO::MYSQL_ATTR_COMPRESS => true
                ]
            );
            
            $this->connection->exec("SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
            $this->connection->exec("SET time_zone='+03:00'");
            
        } catch (PDOException $e) {
            error_log("DB Connection Error: " . $e->getMessage());
            header('HTTP/1.1 503 Service Unavailable');
            die("Ошибка подключения к базе данных. Пожалуйста, попробуйте позже.");
        }
    }

    private function prepareCached($sql)
    {
        if (!isset($this->preparedStatements[$sql])) {
            $this->preparedStatements[$sql] = $this->connection->prepare($sql);
        }
        return $this->preparedStatements[$sql];
    }

    private function getInitialOrderStatus(): string
    {
        static $initialStatus = null;
        if ($initialStatus !== null) {
            return $initialStatus;
        }

        try {
            $stmt = $this->connection->query(
                "SELECT COLUMN_TYPE
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'orders'
                   AND COLUMN_NAME = 'status'
                 LIMIT 1"
            );
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            $columnType = (string)($row['COLUMN_TYPE'] ?? '');
            if ($columnType !== '' && preg_match("/^enum\\('((?:[^'\\\\]|\\\\.)*)'/u", $columnType, $m)) {
                $candidate = stripcslashes((string)$m[1]);
                if ($candidate !== '') {
                    $initialStatus = $candidate;
                    return $initialStatus;
                }
            }
        } catch (Throwable $e) {
            error_log("getInitialOrderStatus metadata lookup failed: " . $e->getMessage());
        }

        try {
            $stmt = $this->connection->query(
                "SELECT status
                 FROM orders
                 WHERE status IS NOT NULL
                 ORDER BY id ASC
                 LIMIT 1"
            );
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            $candidate = trim((string)($row['status'] ?? ''));
            if ($candidate !== '') {
                $initialStatus = $candidate;
                return $initialStatus;
            }
        } catch (Throwable $e) {
            error_log("getInitialOrderStatus fallback lookup failed: " . $e->getMessage());
        }

        $initialStatus = 'Приём';
        return $initialStatus;
    }

    private function invalidateMenuCache()
    {
        if ($this->redisCache) {
            $this->redisCache->invalidate($this->tenantCachePattern('menu_items_*'));
            $this->redisCache->invalidate($this->tenantCachePattern('product_*'));
            $this->redisCache->invalidate($this->tenantCachePattern('categories_*'));
        }
    }

    private function resolveTenantCacheNamespace(): string
    {
        $tenantId = (int)($this->tenantContext['tenant_id'] ?? 0);
        if ($tenantId > 0) {
            return 'tenant:' . $tenantId;
        }

        foreach (['brand_slug', 'tenant_db_name', 'primary_host', 'current_host'] as $field) {
            $value = $this->sanitizeTenantCachePart((string)($this->tenantContext[$field] ?? ''));
            if ($value !== '') {
                return 'tenant:' . $value;
            }
        }

        return 'tenant:legacy';
    }

    private function sanitizeTenantCachePart(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^a-z0-9._-]+/i', '_', $value);
        if (!is_string($value)) {
            return '';
        }

        return trim($value, '._-');
    }

    private function tenantCacheKey(string $key): string
    {
        return $this->tenantCacheNamespace . ':' . ltrim($key, ':');
    }

    private function tenantCachePattern(string $pattern): string
    {
        return $this->tenantCacheNamespace . ':' . ltrim($pattern, ':');
    }

    private function invalidateOrderCache($orderId = null)
    {
    }

    private function generateMenuExternalId(string $prefix = 'manual'): string
    {
        try {
            return $prefix . '-' . bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            return $prefix . '-' . str_replace('.', '', uniqid('', true));
        }
    }

    private function ensureOrderItemsTable(): bool
    {
        static $checked = null;
        if ($checked !== null) {
            return $checked;
        }

        try {
            // Important: no DDL here, otherwise MySQL can implicitly commit
            // and break createOrder/createGuestOrder transaction flow.
            $stmt = $this->connection->query("SHOW TABLES LIKE 'order_items'");
            $checked = (bool)($stmt && $stmt->fetchColumn());
        } catch (Throwable $e) {
            error_log("ensureOrderItemsTable check failed: " . $e->getMessage());
            $checked = false;
        }

        if (!$checked) {
            error_log("order_items table is missing; run sql/mobile-api-tables.sql");
        }

        return $checked;
    }

    private function ensureOrderStatusHistoryTable(): bool
    {
        static $checked = null;
        if ($checked !== null) {
            return $checked;
        }

        try {
            $stmt = $this->connection->query("SHOW TABLES LIKE 'order_status_history'");
            $checked = (bool)($stmt && $stmt->fetchColumn());
        } catch (Throwable $e) {
            error_log("ensureOrderStatusHistoryTable check failed: " . $e->getMessage());
            $checked = false;
        }

        return $checked;
    }

    private function ensureCartItemsTable(): bool
    {
        static $checked = null;
        if ($checked !== null) {
            return $checked;
        }

        try {
            $stmt = $this->connection->query("SHOW TABLES LIKE 'cart_items'");
            $checked = (bool)($stmt && $stmt->fetchColumn());
        } catch (Throwable $e) {
            error_log("ensureCartItemsTable check failed: " . $e->getMessage());
            $checked = false;
        }

        return $checked;
    }

    public function getCartTotalCountForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        if (!$this->ensureCartItemsTable()) {
            // Cart is stored client-side (localStorage) in current stack; server table may not exist.
            return 0;
        }

        $val = $this->scalar(
            "SELECT COALESCE(SUM(quantity),0) FROM cart_items WHERE user_id = ?",
            [$userId]
        );
        return is_numeric($val) ? (int)$val : 0;
    }

    private function persistOrderItems(int $orderId, array $items): void
    {
        if (!$orderId || !$items) {
            return;
        }

        if (!$this->ensureOrderItemsTable()) {
            return;
        }

        // Collect valid items for batch insert
        $values = [];
        $params = [];
        $i = 0;

        foreach ($items as $item) {
            $itemId = isset($item['id']) ? (int)$item['id'] : 0;
            if ($itemId <= 0) {
                continue;
            }
            $quantity = max(1, (int)($item['quantity'] ?? 1));
            $price = (float)($item['price'] ?? 0);
            $itemName = isset($item['name']) ? (string)$item['name'] : null;

            $values[] = "(:oid_{$i}, :iid_{$i}, :nm_{$i}, :qty_{$i}, :prc_{$i}, NOW())";
            $params[":oid_{$i}"] = $orderId;
            $params[":iid_{$i}"] = $itemId;
            $params[":nm_{$i}"]  = $itemName;
            $params[":qty_{$i}"] = $quantity;
            $params[":prc_{$i}"] = $price;
            $i++;
        }

        if (empty($values)) {
            return;
        }

        // Single multi-row INSERT instead of N separate queries
        $sql = "INSERT INTO order_items
                (order_id, item_id, item_name, quantity, price, created_at)
                VALUES " . implode(', ', $values);

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
    }

    private function touchOrdersLastUpdate(): void
    {
        if ($this->redisCache) {
            $this->redisCache->set($this->tenantCacheKey('orders_last_update_ts'), time(), 86400);
        }
    }

    public function getOrdersLastUpdateTs(): int
    {
        if ($this->redisCache) {
            $cached = $this->redisCache->get($this->tenantCacheKey('orders_last_update_ts'));
            if (is_numeric($cached)) {
                return (int)$cached;
            }
        }

        $timestamp = (int)$this->scalar("SELECT UNIX_TIMESTAMP(MAX(updated_at)) FROM orders WHERE 1");
        if ($timestamp > 0 && $this->redisCache) {
            $this->redisCache->set($this->tenantCacheKey('orders_last_update_ts'), $timestamp, 86400);
        }
        return $timestamp;
    }

    private function invalidateUserCache($userId = null)
    {
    }

    public function getProductById($id)
    {
        $cacheKey = $this->tenantCacheKey('product_' . $id);

        if ($this->redisCache) {
            $cached = $this->redisCache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        try {
            $stmt = $this->prepareCached(
                "SELECT id, external_id, name, description, composition, price, image,
                 calories, protein, fat, carbs, category, available, archived_at
                 FROM menu_items WHERE id = :id"
            );
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch();

            if ($result !== false) {
                if ($this->redisCache) {
                    $this->redisCache->set($cacheKey, $result, $this->productCacheTtl);
                }
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("getProductById Error: " . $e->getMessage());
            return false;
        }
    }

    public function getOrderById($orderId)
    {
        try {
            $stmt = $this->prepareCached("
                SELECT o.id, o.user_id, o.items, o.total, o.status,
                       o.delivery_type, o.delivery_details, o.created_at,
                       o.payment_method, o.payment_id, o.payment_status
                FROM orders o
                WHERE o.id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $orderId]);
            $order = $stmt->fetch();
            
            if ($order) {
                $order['items'] = json_decode($order['items'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log("JSON decode error for order items: " . json_last_error_msg());
                    return false;
                }
            }
            
            return $order;
        } catch (PDOException $e) {
            error_log("getOrderById Error: " . $e->getMessage());
            return false;
        }
    }

    public function getMenuItems($category = null, bool $availableOnly = true)
    {
        $cacheKey = $this->tenantCacheKey(($availableOnly ? 'menu_items_' : 'menu_items_all_') . ($category ?: 'all'));

        if ($this->redisCache) {
            $cached = $this->redisCache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $sql = "SELECT id, external_id, name, description, composition, price, image,
                   calories, protein, fat, carbs, category, available, archived_at
                   FROM menu_items
                   WHERE archived_at IS NULL";

            if ($availableOnly) {
                $sql .= " AND available = 1";
            }

            if ($category) {
                $sql .= " AND category = :category";
            }
            $sql .= " ORDER BY category, sort_order, name";

            $stmt = $this->prepareCached($sql);
            if ($category) {
                $stmt->bindValue(':category', $category, PDO::PARAM_STR);
            }
            $stmt->execute();
            $result = $stmt->fetchAll();
            
            if ($this->redisCache) {
                $this->redisCache->set($cacheKey, $result, $this->menuCacheTtl);
            }

            return $result;
        } catch (PDOException $e) {
            error_log("getMenuItems Error: " . $e->getMessage());
            return [];
        }
    }

    public function getArchivedMenuItems($category = null): array
    {
        $cacheKey = $this->tenantCacheKey('menu_items_archived_' . ($category ?: 'all'));

        if ($this->redisCache) {
            $cached = $this->redisCache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $sql = "SELECT id, external_id, name, description, composition, price, image,
                   calories, protein, fat, carbs, category, available, archived_at
                   FROM menu_items
                   WHERE archived_at IS NOT NULL";

            if ($category) {
                $sql .= " AND category = :category";
            }
            $sql .= " ORDER BY category, sort_order, name";

            $stmt = $this->prepareCached($sql);
            if ($category) {
                $stmt->bindValue(':category', $category, PDO::PARAM_STR);
            }
            $stmt->execute();
            $result = $stmt->fetchAll();

            if ($this->redisCache) {
                $this->redisCache->set($cacheKey, $result, $this->menuCacheTtl);
            }

            return $result;
        } catch (PDOException $e) {
            error_log("getArchivedMenuItems Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Bulk update sort_order for a list of menu items.
     * Input: map of [id => position]. Positions are integers; callers
     * (admin drag-n-drop) typically pass 0,1,2,... in visual order.
     *
     * Runs inside a transaction so a mid-batch failure rolls the whole
     * rearrangement back — partial reorderings are worse than no-op.
     * Invalidates the Redis menu cache on success.
     */
    public function updateMenuItemsOrder(array $idToPosition): bool
    {
        if (empty($idToPosition)) {
            return true;
        }
        try {
            $this->connection->beginTransaction();
            $stmt = $this->connection->prepare("UPDATE menu_items SET sort_order = :pos WHERE id = :id");
            foreach ($idToPosition as $id => $position) {
                $id = (int)$id;
                $position = (int)$position;
                if ($id <= 0) {
                    continue;
                }
                $stmt->execute([':pos' => $position, ':id' => $id]);
            }
            $this->connection->commit();

            $this->invalidateMenuCache();
            return true;
        } catch (PDOException $e) {
            try { $this->connection->rollBack(); } catch (Throwable $ignored) {}
            error_log('updateMenuItemsOrder error: ' . $e->getMessage());
            return false;
        }
    }

    public function updateMenuItems(
        int $id,
        string $name,
        string $description,
        ?string $composition,
        float $price,
        ?string $image,
        ?int $calories,
        ?int $protein,
        ?int $fat,
        ?int $carbs,
        string $category,
        int $available = 1
    ): bool {
        try {
            $sql = "
                UPDATE menu_items
                SET name = :name,
                    description = :description,
                    composition = :composition,
                    price = :price,
                    image = :image,
                    calories = :calories,
                    protein = :protein,
                    fat = :fat,
                    carbs = :carbs,
                    category = :category,
                    available = :available
                WHERE id = :id
            ";

            $stmt = $this->prepareCached($sql);

            $result = $stmt->execute([
                ':name' => $name,
                ':description' => $description,
                ':composition' => $composition,
                ':price' => $price,
                ':image' => $image,
                ':calories' => $calories,
                ':protein' => $protein,
                ':fat' => $fat,
                ':carbs' => $carbs,
                ':category' => $category,
                ':available' => $available,
                ':id' => $id
            ]);
            
            if ($result) {
                $this->invalidateMenuCache();
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("updateMenuItems Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Toggle available flag for a single menu item.
     * Returns the NEW available value (0 or 1) on success, or null on failure.
     */
    public function toggleItemAvailable(int $id): ?int
    {
        try {
            $stmt = $this->prepareCached(
                "UPDATE menu_items
                 SET available = NOT available
                 WHERE id = :id
                   AND archived_at IS NULL"
            );
            $stmt->execute([':id' => $id]);

            if ($stmt->rowCount() === 0) {
                return null; // item not found
            }

            $this->invalidateMenuCache();

            $row = $this->prepareCached("SELECT available FROM menu_items WHERE id = :id");
            $row->execute([':id' => $id]);
            $result = $row->fetch();
            return $result ? (int)$result['available'] : null;
        } catch (PDOException $e) {
            error_log("toggleItemAvailable Error: " . $e->getMessage());
            return null;
        }
    }

    public function getMenuItemName(int $id): ?string
    {
        try {
            $stmt = $this->prepareCached("SELECT name FROM menu_items WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            return $row ? $row['name'] : null;
        } catch (PDOException $e) {
            error_log("getMenuItemName Error: " . $e->getMessage());
            return null;
        }
    }

    public function restoreArchivedMenuItem(int $id): bool
    {
        try {
            $stmt = $this->prepareCached("
                UPDATE menu_items
                SET archived_at = NULL,
                    available = 1
                WHERE id = :id
                  AND archived_at IS NOT NULL
            ");
            $stmt->execute([':id' => $id]);
            $ok = $stmt->rowCount() > 0;
            if ($ok) {
                $this->invalidateMenuCache();
            }
            return $ok;
        } catch (PDOException $e) {
            error_log("restoreArchivedMenuItem Error: " . $e->getMessage());
            return false;
        }
    }

    public function archiveMenuItem(int $id): bool
    {
        try {
            $stmt = $this->prepareCached("
                UPDATE menu_items
                SET archived_at = NOW(),
                    available = 0
                WHERE id = :id
                  AND archived_at IS NULL
            ");
            $stmt->execute([':id' => $id]);
            $ok = $stmt->rowCount() > 0;
            if ($ok) {
                $this->invalidateMenuCache();
            }
            return $ok;
        } catch (PDOException $e) {
            error_log("archiveMenuItem Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Bulk helpers for admin-menu multi-select actions (track 5.2).
     * All three run under a single transaction so partial failures don't leave
     * a half-applied UI. Filter `archived_at IS NULL` so an already-archived
     * row can't be flipped through the bulk channel — archive state is
     * managed by archive/restore only.
     */
    public function bulkSetMenuItemsAvailable(array $ids, bool $available): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($v) => $v > 0)));
        if (empty($ids)) {
            return 0;
        }
        try {
            $this->connection->beginTransaction();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->connection->prepare("
                UPDATE menu_items
                SET available = ?
                WHERE id IN ({$placeholders})
                  AND archived_at IS NULL
            ");
            $stmt->execute(array_merge([$available ? 1 : 0], $ids));
            $affected = $stmt->rowCount();
            $this->connection->commit();
            if ($affected > 0) {
                $this->invalidateMenuCache();
            }
            return $affected;
        } catch (PDOException $e) {
            try { $this->connection->rollBack(); } catch (Throwable $ignored) {}
            error_log('bulkSetMenuItemsAvailable error: ' . $e->getMessage());
            return 0;
        }
    }

    public function bulkMoveMenuItemsToCategory(array $ids, string $category): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($v) => $v > 0)));
        $category = trim($category);
        if (empty($ids) || $category === '') {
            return 0;
        }
        try {
            $this->connection->beginTransaction();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->connection->prepare("
                UPDATE menu_items
                SET category = ?, sort_order = 0
                WHERE id IN ({$placeholders})
                  AND archived_at IS NULL
            ");
            $stmt->execute(array_merge([$category], $ids));
            $affected = $stmt->rowCount();
            $this->connection->commit();
            if ($affected > 0) {
                $this->invalidateMenuCache();
            }
            return $affected;
        } catch (PDOException $e) {
            try { $this->connection->rollBack(); } catch (Throwable $ignored) {}
            error_log('bulkMoveMenuItemsToCategory error: ' . $e->getMessage());
            return 0;
        }
    }

    public function bulkArchiveMenuItems(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($v) => $v > 0)));
        if (empty($ids)) {
            return 0;
        }
        try {
            $this->connection->beginTransaction();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->connection->prepare("
                UPDATE menu_items
                SET archived_at = NOW(), available = 0
                WHERE id IN ({$placeholders})
                  AND archived_at IS NULL
            ");
            $stmt->execute($ids);
            $affected = $stmt->rowCount();
            $this->connection->commit();
            if ($affected > 0) {
                $this->invalidateMenuCache();
            }
            return $affected;
        } catch (PDOException $e) {
            try { $this->connection->rollBack(); } catch (Throwable $ignored) {}
            error_log('bulkArchiveMenuItems error: ' . $e->getMessage());
            return 0;
        }
    }

    public function sanitizeOrderItemsForCheckout(array $items): array
    {
        $removedItems = [];
        $inputItems = [];
        $itemIds = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                $removedItems[] = [
                    'id' => 0,
                    'name' => null,
                    'reason' => 'invalid_item',
                ];
                continue;
            }

            $itemId = (int)($item['id'] ?? 0);
            if ($itemId <= 0) {
                $removedItems[] = [
                    'id' => $itemId,
                    'name' => isset($item['name']) ? (string)$item['name'] : null,
                    'reason' => 'invalid_id',
                ];
                continue;
            }

            $quantity = max(1, (int)($item['quantity'] ?? 1));
            $inputItems[] = [
                'raw' => $item,
                'id' => $itemId,
                'quantity' => $quantity,
            ];
            $itemIds[$itemId] = true;
        }

        if (empty($inputItems)) {
            return [
                'items' => [],
                'removed_items' => $removedItems,
                'server_total' => 0.0,
                'cart_adjusted' => !empty($removedItems),
            ];
        }

        $ids = array_keys($itemIds);
        $params = [];
        $placeholders = [];
        foreach ($ids as $idx => $id) {
            $key = ':id_' . $idx;
            $placeholders[] = $key;
            $params[$key] = (int)$id;
        }

        $menuItemsById = [];
        try {
            $sql = "
                SELECT id, name, price, image, calories, protein, fat, carbs, available, archived_at
                FROM menu_items
                WHERE id IN (" . implode(',', $placeholders) . ")
            ";
            $stmt = $this->prepareCached($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            foreach ($rows as $row) {
                $menuItemsById[(int)$row['id']] = $row;
            }
        } catch (PDOException $e) {
            error_log("sanitizeOrderItemsForCheckout Error: " . $e->getMessage());
            return [
                'items' => [],
                'removed_items' => array_merge($removedItems, [[
                    'id' => 0,
                    'name' => null,
                    'reason' => 'db_error',
                ]]),
                'server_total' => 0.0,
                'cart_adjusted' => true,
            ];
        }

        $cleanItems = [];
        $serverTotal = 0.0;

        foreach ($inputItems as $entry) {
            $itemId = $entry['id'];
            $quantity = $entry['quantity'];
            $raw = $entry['raw'];
            $menuItem = $menuItemsById[$itemId] ?? null;

            if (!$menuItem) {
                $removedItems[] = [
                    'id' => $itemId,
                    'name' => isset($raw['name']) ? (string)$raw['name'] : null,
                    'reason' => 'not_found',
                ];
                continue;
            }

            if (!empty($menuItem['archived_at'])) {
                $removedItems[] = [
                    'id' => $itemId,
                    'name' => (string)$menuItem['name'],
                    'reason' => 'archived',
                ];
                continue;
            }

            if ((int)$menuItem['available'] !== 1) {
                $removedItems[] = [
                    'id' => $itemId,
                    'name' => (string)$menuItem['name'],
                    'reason' => 'unavailable',
                ];
                continue;
            }

            $price = (float)$menuItem['price'];
            $clean = $raw;
            $clean['id'] = $itemId;
            $clean['name'] = (string)$menuItem['name'];
            $clean['price'] = $price;
            $clean['image'] = (string)($menuItem['image'] ?? '');
            $clean['quantity'] = $quantity;
            $clean['calories'] = isset($menuItem['calories']) ? (int)$menuItem['calories'] : 0;
            $clean['protein'] = isset($menuItem['protein']) ? (int)$menuItem['protein'] : 0;
            $clean['fat'] = isset($menuItem['fat']) ? (int)$menuItem['fat'] : 0;
            $clean['carbs'] = isset($menuItem['carbs']) ? (int)$menuItem['carbs'] : 0;

            $cleanItems[] = $clean;
            $serverTotal += $price * $quantity;
        }

        return [
            'items' => $cleanItems,
            'removed_items' => $removedItems,
            'server_total' => round($serverTotal, 2),
            'cart_adjusted' => !empty($removedItems),
        ];
    }

    public function getActiveTableOrders(): array
    {
        try {
            $stmt = $this->prepareCached("
                SELECT delivery_details AS table_num,
                       COUNT(*) AS order_count,
                       SUM(total) AS total_sum
                FROM orders
                WHERE delivery_type = 'table'
                  AND status NOT IN ('завершён', 'отказ')
                GROUP BY delivery_details
                ORDER BY CAST(delivery_details AS UNSIGNED) ASC
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("getActiveTableOrders Error: " . $e->getMessage());
            return [];
        }
    }

    public function getModifiersByItemId(int $itemId): array
    {
        try {
            $stmt = $this->prepareCached("
                SELECT g.id AS group_id, g.name AS group_name, g.type, g.required,
                       o.id AS opt_id, o.name AS opt_name, o.price_delta
                FROM modifier_groups g
                JOIN modifier_options o ON o.group_id = g.id AND o.deleted_at IS NULL
                WHERE g.item_id = :item_id
                  AND g.deleted_at IS NULL
                ORDER BY g.sort_order, o.sort_order
            ");
            $stmt->execute([':item_id' => $itemId]);
            $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $groups = [];
            foreach ($rows as $row) {
                $gid = $row['group_id'];
                if (!isset($groups[$gid])) {
                    $groups[$gid] = [
                        'id'       => $gid,
                        'name'     => $row['group_name'],
                        'type'     => $row['type'],
                        'required' => (bool)$row['required'],
                        'options'  => [],
                    ];
                }
                $groups[$gid]['options'][] = [
                    'id'          => $row['opt_id'],
                    'name'        => $row['opt_name'],
                    'price_delta' => (float)$row['price_delta'],
                ];
            }
            return array_values($groups);
        } catch (PDOException $e) {
            error_log("getModifiersByItemId Error: " . $e->getMessage());
            return [];
        }
    }

    public function saveModifierGroup(int $itemId, ?int $groupId, string $name, string $type, bool $required, int $sortOrder): int|false
    {
        try {
            if ($groupId) {
                $stmt = $this->prepareCached("UPDATE modifier_groups SET name=:name, type=:type, required=:req, sort_order=:so WHERE id=:id AND item_id=:iid");
                $stmt->execute([':name'=>$name,':type'=>$type,':req'=>(int)$required,':so'=>$sortOrder,':id'=>$groupId,':iid'=>$itemId]);
                return $groupId;
            }
            $stmt = $this->prepareCached("INSERT INTO modifier_groups (item_id, name, type, required, sort_order) VALUES (:iid,:name,:type,:req,:so)");
            $stmt->execute([':iid'=>$itemId,':name'=>$name,':type'=>$type,':req'=>(int)$required,':so'=>$sortOrder]);
            return (int)$this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log("saveModifierGroup Error: " . $e->getMessage());
            return false;
        }
    }

    public function saveModifierOption(int $groupId, ?int $optionId, string $name, float $priceDelta, int $sortOrder): int|false
    {
        try {
            if ($optionId) {
                $stmt = $this->prepareCached("UPDATE modifier_options SET name=:name, price_delta=:pd, sort_order=:so WHERE id=:id AND group_id=:gid");
                $stmt->execute([':name'=>$name,':pd'=>$priceDelta,':so'=>$sortOrder,':id'=>$optionId,':gid'=>$groupId]);
                return $optionId;
            }
            $stmt = $this->prepareCached("INSERT INTO modifier_options (group_id, name, price_delta, sort_order) VALUES (:gid,:name,:pd,:so)");
            $stmt->execute([':gid'=>$groupId,':name'=>$name,':pd'=>$priceDelta,':so'=>$sortOrder]);
            return (int)$this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log("saveModifierOption Error: " . $e->getMessage());
            return false;
        }
    }

    public function deleteModifierGroup(int $groupId): bool
    {
        try {
            return $this->prepareCached("
                UPDATE modifier_groups SET deleted_at = NOW()
                WHERE id = :id AND deleted_at IS NULL
            ")->execute([':id' => $groupId]);
        } catch (PDOException $e) {
            error_log("deleteModifierGroup Error: " . $e->getMessage());
            return false;
        }
    }

    public function deleteModifierOption(int $optionId): bool
    {
        try {
            return $this->prepareCached("
                UPDATE modifier_options SET deleted_at = NOW()
                WHERE id = :id AND deleted_at IS NULL
            ")->execute([':id' => $optionId]);
        } catch (PDOException $e) {
            error_log("deleteModifierOption Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Undo a soft-delete within the 30-second forgiveness window.
     * Called by undo-delete.php. Returns true if a row was actually restored.
     * The 30-second cap keeps "undo" from accidentally resurrecting items
     * that an operator deleted hours ago and doesn't remember.
     */
    public function undoModifierDelete(string $table, int $id): bool
    {
        if (!in_array($table, ['modifier_groups', 'modifier_options'], true)) {
            return false;
        }
        try {
            $stmt = $this->connection->prepare("
                UPDATE `{$table}`
                SET deleted_at = NULL
                WHERE id = :id
                  AND deleted_at IS NOT NULL
                  AND deleted_at > (NOW() - INTERVAL 30 SECOND)
            ");
            $stmt->execute([':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('undoModifierDelete error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Purge rows that were soft-deleted more than $daysOld days ago.
     * Called from scripts/orders/purge-soft-deleted.php (cron).
     * Returns [modifier_groups_deleted, modifier_options_deleted].
     */
    public function purgeSoftDeletedModifiers(int $daysOld = 7): array
    {
        $daysOld = max(1, min(365, $daysOld));
        $result = ['modifier_groups' => 0, 'modifier_options' => 0];
        foreach (array_keys($result) as $table) {
            try {
                $stmt = $this->connection->prepare("
                    DELETE FROM `{$table}`
                    WHERE deleted_at IS NOT NULL
                      AND deleted_at < (NOW() - INTERVAL :days DAY)
                ");
                $stmt->execute([':days' => $daysOld]);
                $result[$table] = $stmt->rowCount();
            } catch (PDOException $e) {
                error_log("purgeSoftDeletedModifiers ({$table}) error: " . $e->getMessage());
            }
        }
        return $result;
    }

    public function getUniqueCategories()
    {
        $cacheKey = $this->tenantCacheKey('categories_unique');

        if ($this->redisCache) {
            $cached = $this->redisCache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        try {
            $stmt = $this->prepareCached(
                "SELECT DISTINCT category
                 FROM menu_items
                 WHERE available = 1
                   AND archived_at IS NULL
                 ORDER BY category"
            );
            $stmt->execute();
            $result = $stmt->fetchAll();
            
            if ($this->redisCache) {
                $this->redisCache->set($cacheKey, $result, $this->categoriesCacheTtl);
            }

            return $result;
        } catch (PDOException $e) {
            error_log("getUniqueCategories Error: " . $e->getMessage());
            return [];
        }
    }

    public function createUser($email, $passwordHash, $name, $phone, $verificationToken)
    {
        try {
            $stmt = $this->prepareCached("
                INSERT INTO users 
                (email, password_hash, name, phone, verification_token, 
                 verification_token_expires_at, role, created_at) 
                VALUES (:email, :password_hash, :name, :phone, :verification_token, 
                        DATE_ADD(NOW(), INTERVAL 1 DAY), 'customer', NOW())
            ");
            $stmt->execute([
                ':email' => $email,
                ':password_hash' => $passwordHash,
                ':name' => $name,
                ':phone' => $phone ?: null,
                ':verification_token' => $verificationToken
            ]);
            $userId = $this->connection->lastInsertId();
            
            $this->invalidateUserCache($userId);
            
            return $userId;
        } catch (PDOException $e) {
            error_log("createUser Error: " . $e->getMessage());
            return false;
        }
    }

    public function updateMenuView($userId, $view) 
    {
        try {
            $stmt = $this->prepareCached("UPDATE users SET menu_view = ? WHERE id = ?");
            $result = $stmt->execute([$view, $userId]);
            
            if ($result) {
                $this->invalidateUserCache($userId);
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("updateMenuView Error: " . $e->getMessage());
            return false;
        }
    }

    public function getUniqueOrderStatuses()
    {
        try {
            $stmt = $this->prepareCached(
                "SELECT DISTINCT status FROM orders ORDER BY 
                 CASE 
                     WHEN status = 'Приём' THEN 1
                     WHEN status = 'готовим' THEN 2
                     WHEN status = 'доставляем' THEN 3
                     WHEN status = 'завершён' THEN 4
                     WHEN status = 'отказ' THEN 5
                     ELSE 6
                 END"
            );
            $stmt->execute();
            $result = $stmt->fetchAll();

            return $result;
        } catch (PDOException $e) {
            error_log("getUniqueOrderStatuses Error: " . $e->getMessage());
            return [];
        }
    }

    public function getAllOrders()
    {
        try {
            $stmt = $this->prepareCached("
                SELECT o.id, o.items, o.total, o.status, o.delivery_type, o.delivery_details,
                       o.created_at, o.last_updated_by,
                       o.payment_method, o.payment_id, o.payment_status,
                       u.name as user_name, u.phone as user_phone,
                       updater.name as updater_name
                FROM orders o
                JOIN users u ON o.user_id = u.id
                LEFT JOIN users updater ON o.last_updated_by = updater.id
                ORDER BY o.created_at DESC
            ");
            $stmt->execute();
 
            $orders = $stmt->fetchAll();
 
            foreach ($orders as &$order) {
                $order['items'] = json_decode($order['items'], true);
            }
 
            return $orders;
        } catch (PDOException $e) {
            error_log("getAllOrders Error: " . $e->getMessage());
            return [];
        }
    }

    public function getStaleOrders(int $thresholdMinutes = 45): array
    {
        try {
            $openStatuses = cleanmenu_order_open_statuses();
            $placeholders = implode(',', array_fill(0, count($openStatuses), '?'));
            $sql = "
                SELECT o.id, o.items, o.total, o.status, o.delivery_type, o.delivery_details,
                       o.created_at, o.last_updated_by,
                       o.payment_method, o.payment_id, o.payment_status,
                       u.name as user_name, u.phone as user_phone,
                       updater.name as updater_name
                FROM orders o
                JOIN users u ON o.user_id = u.id
                LEFT JOIN users updater ON o.last_updated_by = updater.id
                WHERE o.status IN ($placeholders)
                  AND o.created_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE)
                ORDER BY o.created_at ASC
            ";
            $params = array_merge($openStatuses, [$thresholdMinutes]);
            $stmt = $this->prepareCached($sql);
            $stmt->execute($params);
            $orders = $stmt->fetchAll();
            foreach ($orders as &$order) {
                $order['items'] = json_decode($order['items'], true);
            }
            return $orders;
        } catch (PDOException $e) {
            error_log("getStaleOrders Error: " . $e->getMessage());
            return [];
        }
    }

    public function getOrderUpdatesSince($timestamp)
    {
        try {
            $stmt = $this->prepareCached("
                SELECT id, status, updated_at 
                FROM orders 
                WHERE updated_at >= FROM_UNIXTIME(:timestamp)
                ORDER BY updated_at DESC
            ");
            $stmt->execute([':timestamp' => $timestamp]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getOrderUpdatesSince Error: " . $e->getMessage());
            return [];
        }
    }

    public function getUserOrderUpdatesSince(int $userId, int $timestamp): array
    {
        try {
            $stmt = $this->prepareCached("
                SELECT id, status, updated_at
                FROM orders
                WHERE user_id = :user_id
                  AND updated_at >= FROM_UNIXTIME(:timestamp)
                ORDER BY updated_at DESC
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':timestamp' => $timestamp,
            ]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getUserOrderUpdatesSince Error: " . $e->getMessage());
            return [];
        }
    }

    public function getOrderStatus($orderId)
    {
        try {
            $stmt = $this->prepareCached("SELECT status FROM orders WHERE id = :id");
            $stmt->bindValue(':id', $orderId, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetchColumn();

            return $result;
        } catch (PDOException $e) {
            error_log("getOrderStatus Error: " . $e->getMessage());
            return false;
        }
    }

    public function updateOrderStatus($orderId, $newStatus, $userId = null)
    {
        $this->connection->beginTransaction();

        try {
            $stmt = $this->prepareCached("
                UPDATE orders 
                SET status = :status,
                    last_updated_by = :user_id,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':status' => $newStatus,
                ':user_id' => $userId,
                ':id' => $orderId
            ]);

            $stmt = $this->prepareCached("
                INSERT INTO order_status_history (order_id, status, changed_by, changed_at)
                VALUES (:order_id, :status, :user_id, NOW())
            ");
            $stmt->execute([
                ':order_id' => $orderId,
                ':status' => $newStatus,
                ':user_id' => $userId
            ]);

            $this->connection->commit();
            
            $this->invalidateOrderCache($orderId);
            $this->touchOrdersLastUpdate();
            
            return true;
        } catch (Throwable $e) {
            $this->connection->rollBack();
            error_log("updateOrderStatus Error: " . $e->getMessage());
            return false;
        }
    }

    public function cleanupStaleOrders(int $thresholdMinutes = 45, ?int $userId = null): array
    {
        $staleOrders = $this->getStaleOrders($thresholdMinutes);
        if (empty($staleOrders)) {
            return [
                'updated' => 0,
                'threshold_minutes' => $thresholdMinutes,
                'orders' => [],
            ];
        }

        $updateStmt = $this->prepareCached("
            UPDATE orders
            SET status = :status,
                last_updated_by = :user_id,
                updated_at = NOW()
            WHERE id = :id
        ");
        $historyStmt = $this->prepareCached("
            INSERT INTO order_status_history (order_id, status, changed_by, changed_at)
            VALUES (:order_id, :status, :user_id, NOW())
        ");

        $updatedIds = [];
        $this->connection->beginTransaction();
        try {
            foreach ($staleOrders as $order) {
                $orderId = (int)($order['id'] ?? 0);
                if ($orderId <= 0) {
                    continue;
                }

                $updateStmt->execute([
                    ':status' => 'отказ',
                    ':user_id' => $userId,
                    ':id' => $orderId,
                ]);
                $historyStmt->execute([
                    ':order_id' => $orderId,
                    ':status' => 'отказ',
                    ':user_id' => $userId,
                ]);
                $updatedIds[] = $orderId;
                $this->invalidateOrderCache($orderId);
            }

            $this->connection->commit();
            $this->touchOrdersLastUpdate();

            return [
                'updated' => count($updatedIds),
                'threshold_minutes' => $thresholdMinutes,
                'orders' => $updatedIds,
            ];
        } catch (Throwable $e) {
            $this->connection->rollBack();
            error_log("cleanupStaleOrders Error: " . $e->getMessage());
            return [
                'updated' => 0,
                'threshold_minutes' => $thresholdMinutes,
                'orders' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getUserByEmail($email)
    {
        try {
            $stmt = $this->prepareCached(
                "SELECT * FROM users WHERE email = :email LIMIT 1"
            );
            $stmt->execute([':email' => $email]);
            $result = $stmt->fetch();

            return $result;
        } catch (PDOException $e) {
            error_log("getUserByEmail Error: " . $e->getMessage());
            return false;
        }
    }

    public function getUserById($id)
    {
        try {
            $stmt = $this->prepareCached(
                "SELECT id, email, name, phone, is_active, role, menu_view
                 FROM users WHERE id = :id LIMIT 1"
            );
            $stmt->execute([':id' => $id]);
            $result = $stmt->fetch();

            return $result;
        } catch (PDOException $e) {
            error_log("getUserById Error: " . $e->getMessage());
            return false;
        }
    }

    public function getAllUsers()
    {
        try {
            $stmt = $this->prepareCached(
                "SELECT id, email, name, phone, is_active, role, menu_view
                 FROM users WHERE id != 999999 ORDER BY id"
            );
            $stmt->execute();
            $result = $stmt->fetchAll();

            return $result;
        } catch (PDOException $e) {
            error_log("getAllUsers Error: " . $e->getMessage());
            return false;
        }
    }

    public function saveRememberToken($userId, $selector, $hashedValidator, $expires)
    {
        try {
            $stmt = $this->prepareCached(
                "INSERT INTO auth_tokens (user_id, selector, hashed_validator, expires_at) 
                 VALUES (?, ?, ?, ?)"
            );
            return $stmt->execute([$userId, $selector, $hashedValidator, $expires]);
        } catch (PDOException $e) {
            error_log("saveRememberToken Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function getRememberToken($selector) 
    {
        try {
            $stmt = $this->prepareCached(
                "SELECT user_id, hashed_validator, expires_at 
                 FROM auth_tokens 
                 WHERE selector = ? AND expires_at > ?"
            );
            $stmt->execute([$selector, time()]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("getRememberToken Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function updateRememberToken($selector, $hashedValidator, $expires) 
    {
        try {
            $stmt = $this->prepareCached(
                "UPDATE auth_tokens 
                 SET hashed_validator = ?,
                     expires_at = ?
                 WHERE selector = ?"
            );
            return $stmt->execute([$hashedValidator, $expires, $selector]);
        } catch (PDOException $e) {
            error_log("updateRememberToken Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function deleteRememberToken($selector) 
    {
        try {
            $stmt = $this->prepareCached(
                "DELETE FROM auth_tokens WHERE selector = ?"
            );
            return $stmt->execute([$selector]);
        } catch (PDOException $e) {
            error_log("deleteRememberToken Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function deleteExpiredTokens() 
    {
        try {
            $stmt = $this->prepareCached(
                "DELETE FROM auth_tokens WHERE expires_at <= ?"
            );
            return $stmt->execute([time()]);
        } catch (PDOException $e) {
            error_log("deleteExpiredTokens Error: " . $e->getMessage());
            return false;
        }
    }

    public function verifyUser($token)
    {
        try {
            $this->connection->beginTransaction();

            $stmt = $this->prepareCached(
                "SELECT id, is_active FROM users 
                 WHERE verification_token = :token 
                 AND (verification_token_expires_at > NOW() OR is_active = 0)
                 LIMIT 1"
            );
            $stmt->execute([':token' => $token]);
            $user = $stmt->fetch();

            if (!$user) {
                $this->connection->rollBack();
                return 'invalid_token';
            }

            if ($user['is_active']) {
                $this->connection->rollBack();
                return 'already_verified';
            }

            $stmt = $this->prepareCached("
                UPDATE users 
                SET is_active = 1, 
                    verification_token = NULL, 
                    verification_token_expires_at = NULL,
                    email_verified_at = NOW()
                WHERE verification_token = :token
            ");
            $stmt->execute([':token' => $token]);

            $this->connection->commit();
            
            $this->invalidateUserCache($user['id']);
            
            return 'success';
        } catch (PDOException $e) {
            $this->connection->rollBack();
            error_log("verifyUser Error: " . $e->getMessage());
            return 'error';
        }
    }

    public function setPasswordResetToken($email, $token)
    {
        try {
            $stmt = $this->prepareCached("
                UPDATE users 
                SET reset_token = :token, 
                    reset_token_expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)
                WHERE email = :email
            ");
            $result = $stmt->execute([':token' => $token, ':email' => $email]);
            
            if ($result) {
                $this->invalidateUserCache();
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("setPasswordResetToken Error: " . $e->getMessage());
            return false;
        }
    }

    public function addMenuItem($name, $description, $composition, $price, $image, $calories, $protein, $fat, $carbs, $category, $available = 1)
    {
        try {
            $sql = "INSERT INTO menu_items (external_id, name, description, composition, price, image, 
                    calories, protein, fat, carbs, category, available, archived_at) 
                    VALUES (:eid, :n, :d, :cmp, :p, :i, :cal, :prot, :fat, :carb, :c, :a, NULL)";
            $stmt = $this->prepareCached($sql);
            $result = $stmt->execute([
                ':eid' => $this->generateMenuExternalId(),
                ':n' => $name,
                ':d' => $description,
                ':cmp' => $composition,
                ':p' => $price,
                ':i' => $image,
                ':cal' => $calories,
                ':prot' => $protein,
                ':fat' => $fat,
                ':carb' => $carbs,
                ':c' => $category,
                ':a' => $available
            ]);
            
            if ($result) {
                $this->invalidateMenuCache();
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("addMenuItem Error: " . $e->getMessage());
            return false;
        }
    }

    public function resetPassword($token, $passwordHash)
    {
        $this->connection->beginTransaction();

        try {
            $stmt = $this->prepareCached(
                "SELECT id FROM users WHERE reset_token = :token AND reset_token_expires_at > NOW() LIMIT 1"
            );
            $stmt->execute([':token' => $token]);
            $user = $stmt->fetch();

            if (!$user) {
                $this->connection->rollBack();
                return false;
            }

            $stmt = $this->prepareCached("
                UPDATE users 
                SET password_hash = :password_hash, 
                    reset_token = NULL, 
                    reset_token_expires_at = NULL
                WHERE reset_token = :token
            ");
            $result = $stmt->execute([
                ':password_hash' => $passwordHash,
                ':token' => $token
            ]);

            $this->connection->commit();
            
            $this->invalidateUserCache($user['id']);
            
            return $result;
        } catch (PDOException $e) {
            $this->connection->rollBack();
            error_log("resetPassword Error: " . $e->getMessage());
            return false;
        }
    }

    public function bulkUpdateMenu($csvHandle, string $delimiter = ';'): bool
    {
        // Legacy entrypoint kept for backward compatibility with old callers.
        $stats = $this->bulkSyncMenuFromCsv($csvHandle, $delimiter);
        return is_array($stats);

        $this->connection->beginTransaction();
        try {
            $stmt = $this->prepareCached("
                INSERT INTO menu_items
                (name, description, composition, price, image,
                 calories, protein, fat, carbs, category, available)
                VALUES
                (:n, :d, :cmp, :p, :i, :cal, :prot, :fat, :carb, :c, :a)
                ON DUPLICATE KEY UPDATE
                    description = VALUES(description),
                    composition = VALUES(composition),
                    price = VALUES(price),
                    image = VALUES(image),
                    calories = VALUES(calories),
                    protein = VALUES(protein),
                    fat = VALUES(fat),
                    carbs = VALUES(carbs),
                    category = VALUES(category),
                    available = VALUES(available)
            ");

            rewind($csvHandle);

            $header = fgetcsv($csvHandle, 0, $delimiter, '"');
            if ($header === false || count($header) < 11) {
                throw new Exception("Неверный формат CSV-файла");
            }

            $count = 0;
            while (($row = fgetcsv($csvHandle, 0, $delimiter, '"')) !== false) {
                if (count($row) < 11) {
                    error_log("Пропуск строки: недостаточно данных");
                    continue;
                }

                error_log("Обработка строки: " . implode(',', $row));

                $composition = trim($row[2]);
                $composition = preg_replace('/([^\s])\s+([^\s])/', '$1, $2', $composition);
                $composition = preg_replace('/,{2,}/', ',', $composition);
                $composition = trim($composition, ', ');

                $params = [
                    ':n' => trim($row[0]),
                    ':d' => trim($row[1]),
                    ':cmp' => $composition,
                    ':p' => (float)str_replace(',', '.', $row[3]),
                    ':i' => trim($row[4]),
                    ':cal' => (int)$row[5],
                    ':prot' => (int)$row[6],
                    ':fat' => (int)$row[7],
                    ':carb' => (int)$row[8],
                    ':c' => trim($row[9]),
                    ':a' => (int)$row[10]
                ];

                if (!$stmt->execute($params)) {
                    error_log("Ошибка выполнения запроса для строки: " . implode(',', $row));
                }
                $count++;
            }

            if ($count === 0) {
                throw new Exception("CSV-файл не содержит данных для импорта");
            }

            $this->connection->commit();
            
            $this->invalidateMenuCache();
            
            return true;
        } catch (Throwable $e) {
            $this->connection->rollBack();
            error_log("bulkUpdateMenu Error: " . $e->getMessage());
            $_SESSION['error'] = "Ошибка загрузки CSV: " . $e->getMessage();
            return false;
        }
    }

    public function bulkSyncMenuFromCsv($csvHandle, string $delimiter = ';')
    {
        try {
            rewind($csvHandle);

            $header = fgetcsv($csvHandle, 0, $delimiter, '"');
            if ($header === false) {
                throw new Exception('CSV файл пустой');
            }

            $normalizeHeader = static function ($value): string {
                $value = (string)$value;
                // Strip UTF-8 BOM robustly (both byte sequence and Unicode FEFF).
                $value = ltrim($value, "\xEF\xBB\xBF");
                $value = preg_replace('/^\x{FEFF}/u', '', $value);
                return strtolower(trim($value));
            };
            $normalizedHeader = array_map($normalizeHeader, $header);
            $expectedHeader = [
                'external_id',
                'name',
                'description',
                'composition',
                'price',
                'image',
                'calories',
                'protein',
                'fat',
                'carbs',
                'category',
                'available',
            ];
            if (function_exists('mb_check_encoding')) {
                foreach ($header as $headCell) {
                    if (!mb_check_encoding((string)$headCell, 'UTF-8')) {
                        throw new Exception('CSV должен быть в UTF-8');
                    }
                }
            }
            if ($normalizedHeader !== $expectedHeader) {
                throw new Exception('Неверный заголовок CSV. Используйте новый шаблон с колонкой external_id');
            }

            $this->connection->exec("
                CREATE TEMPORARY TABLE IF NOT EXISTS tmp_menu_sync (
                    external_id VARCHAR(64) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    description TEXT NULL,
                    composition TEXT NULL,
                    price DECIMAL(10,2) NOT NULL,
                    image VARCHAR(255) NULL,
                    calories INT NULL,
                    protein INT NULL,
                    fat INT NULL,
                    carbs INT NULL,
                    category VARCHAR(50) NOT NULL,
                    available TINYINT(1) NOT NULL DEFAULT 1,
                    PRIMARY KEY (external_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            // Do not use TRUNCATE in transactional flow: it can implicitly commit in MySQL.
            $this->connection->exec("DELETE FROM tmp_menu_sync");

            // Start atomic section only after temp table prep.
            $this->connection->beginTransaction();

            $insertTmpStmt = $this->prepareCached("
                INSERT INTO tmp_menu_sync (
                    external_id, name, description, composition, price, image,
                    calories, protein, fat, carbs, category, available
                ) VALUES (
                    :external_id, :name, :description, :composition, :price, :image,
                    :calories, :protein, :fat, :carbs, :category, :available
                )
            ");

            $seenExternalIds = [];
            $lineNo = 1;
            $count = 0;
            $errors = 0;

            $toNullableInt = static function ($value): ?int {
                $value = trim((string)$value);
                if ($value === '') {
                    return null;
                }
                if (!is_numeric($value)) {
                    throw new Exception("Значение \"{$value}\" должно быть числом");
                }
                return (int)$value;
            };

            while (($row = fgetcsv($csvHandle, 0, $delimiter, '"')) !== false) {
                $lineNo++;
                if (count($row) === 1 && trim((string)$row[0]) === '') {
                    continue;
                }
                if (count($row) !== 12) {
                    throw new Exception("Строка {$lineNo}: ожидается 12 колонок");
                }
                if (function_exists('mb_check_encoding')) {
                    foreach ($row as $cell) {
                        if (!mb_check_encoding((string)$cell, 'UTF-8')) {
                            throw new Exception("Строка {$lineNo}: CSV должен быть в UTF-8");
                        }
                    }
                }

                $externalId = trim((string)$row[0]);
                if ($externalId === '') {
                    throw new Exception("Строка {$lineNo}: external_id обязателен");
                }
                if (strlen($externalId) > 64) {
                    throw new Exception("Строка {$lineNo}: external_id длиннее 64 символов");
                }
                if (isset($seenExternalIds[$externalId])) {
                    throw new Exception("Строка {$lineNo}: duplicate external_id {$externalId}");
                }
                $seenExternalIds[$externalId] = true;

                $name = trim((string)$row[1]);
                if ($name === '') {
                    throw new Exception("Строка {$lineNo}: name обязателен");
                }

                $composition = trim((string)$row[3]);
                $composition = preg_replace('/([^\s])\s+([^\s])/', '$1, $2', $composition);
                $composition = preg_replace('/,{2,}/', ',', (string)$composition);
                $composition = trim((string)$composition, ', ');

                $priceRaw = str_replace(',', '.', trim((string)$row[4]));
                if ($priceRaw === '' || !is_numeric($priceRaw)) {
                    throw new Exception("Строка {$lineNo}: price должен быть числом");
                }
                $price = (float)$priceRaw;

                $category = trim((string)$row[10]);
                if ($category === '') {
                    throw new Exception("Строка {$lineNo}: category обязателен");
                }

                $availableRaw = strtolower(trim((string)$row[11]));
                if ($availableRaw === '' || $availableRaw === '1' || $availableRaw === 'true' || $availableRaw === 'yes' || $availableRaw === 'да') {
                    $available = 1;
                } elseif ($availableRaw === '0' || $availableRaw === 'false' || $availableRaw === 'no' || $availableRaw === 'нет') {
                    $available = 0;
                } else {
                    throw new Exception("Строка {$lineNo}: available должен быть 0 или 1");
                }

                $insertTmpStmt->execute([
                    ':external_id' => $externalId,
                    ':name' => $name,
                    ':description' => trim((string)$row[2]),
                    ':composition' => $composition,
                    ':price' => $price,
                    ':image' => trim((string)$row[5]),
                    ':calories' => $toNullableInt($row[6]),
                    ':protein' => $toNullableInt($row[7]),
                    ':fat' => $toNullableInt($row[8]),
                    ':carbs' => $toNullableInt($row[9]),
                    ':category' => $category,
                    ':available' => $available,
                ]);
                $count++;
            }

            if ($count === 0) {
                throw new Exception('CSV файл не содержит данных для импорта');
            }

            $statsStmt = $this->connection->query("
                SELECT
                    SUM(CASE WHEN mi.id IS NULL THEN 1 ELSE 0 END) AS inserted,
                    SUM(CASE WHEN mi.id IS NOT NULL THEN 1 ELSE 0 END) AS updated,
                    SUM(CASE WHEN mi.id IS NOT NULL AND mi.archived_at IS NOT NULL THEN 1 ELSE 0 END) AS restored_from_archive
                FROM tmp_menu_sync t
                LEFT JOIN menu_items mi ON mi.external_id = t.external_id
            ");
            $statsRow = $statsStmt ? $statsStmt->fetch() : [];

            $upsertStmt = $this->prepareCached("
                INSERT INTO menu_items (
                    external_id, name, description, composition, price, image,
                    calories, protein, fat, carbs, category, available, archived_at
                )
                SELECT
                    external_id, name, description, composition, price, image,
                    calories, protein, fat, carbs, category, available, NULL
                FROM tmp_menu_sync
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    description = VALUES(description),
                    composition = VALUES(composition),
                    price = VALUES(price),
                    image = VALUES(image),
                    calories = VALUES(calories),
                    protein = VALUES(protein),
                    fat = VALUES(fat),
                    carbs = VALUES(carbs),
                    category = VALUES(category),
                    available = VALUES(available),
                    archived_at = NULL
            ");
            $upsertStmt->execute();

            $archiveMissingStmt = $this->prepareCached("
                UPDATE menu_items mi
                LEFT JOIN tmp_menu_sync t ON t.external_id = mi.external_id
                SET mi.archived_at = NOW(),
                    mi.available = 0
                WHERE t.external_id IS NULL
                  AND mi.archived_at IS NULL
            ");
            $archiveMissingStmt->execute();

            $this->connection->commit();
            $this->invalidateMenuCache();

            return [
                'inserted' => (int)($statsRow['inserted'] ?? 0),
                'updated' => (int)($statsRow['updated'] ?? 0),
                'restored_from_archive' => (int)($statsRow['restored_from_archive'] ?? 0),
                'archived_missing' => (int)$archiveMissingStmt->rowCount(),
                'errors' => $errors,
            ];
        } catch (Throwable $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            error_log("bulkSyncMenuFromCsv Error: " . $e->getMessage());
            $_SESSION['error'] = "Ошибка загрузки CSV: " . $e->getMessage();
            return false;
        }
    }

    public function updateUser($userId, $name, $phone)
    {
        try {
            $stmt = $this->prepareCached("
                UPDATE users 
                SET name = :name, phone = :phone 
                WHERE id = :id
            ");
            $result = $stmt->execute([
                ':name' => $name,
                ':phone' => $phone ?: null,
                ':id' => $userId
            ]);
            
            if ($result) {
                $this->invalidateUserCache($userId);
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("updateUser Error: " . $e->getMessage());
            return false;
        }
    }

    public function updateUserPhone(int $userId, ?string $phone): bool
    {
        try {
            $stmt = $this->prepareCached("
                UPDATE users
                SET phone = :phone
                WHERE id = :id
            ");
            $result = $stmt->execute([
                ':phone' => $phone ?: null,
                ':id' => $userId,
            ]);

            if ($result) {
                $this->invalidateUserCache($userId);
            }

            return (bool)$result;
        } catch (PDOException $e) {
            error_log("updateUserPhone Error: " . $e->getMessage());
            return false;
        }
    }

    public function updatePassword($userId, $newPasswordHash)
    {
        try {
            $stmt = $this->prepareCached("
                UPDATE users 
                SET password_hash = :password_hash 
                WHERE id = :id
            ");
            $result = $stmt->execute([
                ':password_hash' => $newPasswordHash,
                ':id' => $userId
            ]);
            
            if ($result) {
                $this->invalidateUserCache($userId);
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("updatePassword Error: " . $e->getMessage());
            return false;
        }
    }

    private function ensureGuestUserExists()
    {
        try {
            $guestId = 999999;
            $stmt = $this->prepareCached("SELECT id FROM users WHERE id = ?");
            $stmt->execute([$guestId]);
            $existing = $stmt->fetchColumn();
            if ($existing !== false) {
                error_log("Guest user found with id: " . $existing);
                return (int)$existing;
            }

            error_log("Creating guest user with id: " . $guestId);
            $stmt = $this->prepareCached("
                INSERT INTO users (id, email, password_hash, name, phone, role, created_at)
                VALUES (?, 'guest@system.local', '', 'Гость', '', 'guest', NOW())
            ");
            $stmt->execute([$guestId]);
            error_log("Guest user created successfully");
            return $guestId;
        } catch (PDOException $e) {
            error_log("ensureGuestUserExists error: " . $e->getMessage());
            return false;
        }
    }

    public function getUserOrders($userId)
    {
        try {
            $stmt = $this->prepareCached("
                SELECT o.id, o.items, o.total, o.status, o.delivery_type, o.delivery_details, 
                       o.created_at, o.updated_at, o.last_updated_by,
                       u.name as user_name, u.phone as user_phone,
                       updater.name as updater_name
                FROM orders o
                JOIN users u ON o.user_id = u.id
                LEFT JOIN users updater ON o.last_updated_by = updater.id
                WHERE o.user_id = :user_id
                ORDER BY o.created_at DESC
            ");
            $stmt->execute([':user_id' => $userId]);

            $orders = $stmt->fetchAll();

            foreach ($orders as &$order) {
                $order['items'] = json_decode($order['items'], true);
            }

            return $orders;
        } catch (PDOException $e) {
            error_log("getUserOrders Error: " . $e->getMessage());
            return [];
        }
    }

    public function getOwnerKpiSnapshot(): array
    {
        $snapshot = [
            'orders_today' => 0,
            'paid_today' => 0,
            'cancelled_today' => 0,
            'aov_today' => 0.0,
        ];

        try {
            $sql = "
                SELECT
                    COUNT(*) AS orders_today,
                    SUM(
                        CASE
                            WHEN LOWER(COALESCE(payment_status, '')) REGEXP 'paid|succeed|success|complete|оплачен|заверш' THEN 1
                            WHEN LOWER(COALESCE(payment_status, '')) REGEXP '^$|not_required'
                                 AND LOWER(COALESCE(status, '')) REGEXP 'заверш|complete|done' THEN 1
                            ELSE 0
                        END
                    ) AS paid_today,
                    SUM(
                        CASE
                            WHEN LOWER(COALESCE(status, '')) REGEXP 'cancel|cancell|отказ|отмен' THEN 1
                            ELSE 0
                        END
                    ) AS cancelled_today,
                    AVG(
                        CASE
                            WHEN LOWER(COALESCE(status, '')) REGEXP 'cancel|cancell|отказ|отмен' THEN NULL
                            WHEN total > 0 THEN total
                            ELSE NULL
                        END
                    ) AS aov_today
                FROM orders
                WHERE created_at >= CURDATE()
                  AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
            ";

            $stmt = $this->prepareCached($sql);
            $stmt->execute();
            $row = $stmt->fetch();

            if (is_array($row)) {
                $snapshot['orders_today'] = (int)($row['orders_today'] ?? 0);
                $snapshot['paid_today'] = (int)($row['paid_today'] ?? 0);
                $snapshot['cancelled_today'] = (int)($row['cancelled_today'] ?? 0);
                $snapshot['aov_today'] = round((float)($row['aov_today'] ?? 0), 2);
            }
        } catch (PDOException $e) {
            error_log("getOwnerKpiSnapshot Error: " . $e->getMessage());
        }

        return $snapshot;
    }

    public function getTopItemsSnapshot(string $period = 'day', int $limit = 5): array
    {
        if (!$this->ensureOrderItemsTable()) {
            return [];
        }

        $limit = max(1, min(20, $limit));
        $startExpr = ($period === 'week') ? 'DATE_SUB(CURDATE(), INTERVAL 6 DAY)' : 'CURDATE()';

        $sql = "
            SELECT
                COALESCE(NULLIF(TRIM(oi.item_name), ''), CONCAT('Item #', oi.item_id)) AS item_name,
                SUM(oi.quantity) AS total_qty,
                ROUND(SUM(oi.quantity * oi.price), 2) AS total_revenue
            FROM orders o
            INNER JOIN order_items oi ON oi.order_id = o.id
            WHERE o.created_at >= {$startExpr}
              AND NOT (LOWER(COALESCE(o.status, '')) REGEXP 'cancel|cancell|отказ|отмен')
            GROUP BY oi.item_id, oi.item_name
            ORDER BY total_qty DESC, total_revenue DESC
            LIMIT :limit
        ";

        try {
            $stmt = $this->prepareCached($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log("getTopItemsSnapshot Error: " . $e->getMessage());
            return [];
        }
    }

    public function getSalesReport($period = 'day')
    {
        $intervals = [
            'day' => '1 DAY',
            'week' => '1 WEEK',
            'month' => '1 MONTH',
            'year' => '1 YEAR'
        ];
        
        $interval = $intervals[$period] ?? '1 DAY';
        
        if ($period === 'day') {
            $sql = "SELECT
                        o.id as order_id,
                        TIME(o.created_at) as time,
                        o.total as total_revenue,
                        COALESCE(oi_agg.item_count, 0) as item_count
                    FROM orders o
                    LEFT JOIN (
                        SELECT order_id, COUNT(*) as item_count
                        FROM order_items
                        GROUP BY order_id
                    ) oi_agg ON oi_agg.order_id = o.id
                    WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL $interval)
                    AND o.status = 'завершён'
                    AND DAY(o.created_at) = DAY(NOW())
                    ORDER BY o.created_at DESC";
        } else {
            if ($period === 'year') {
                $dateFormat = '%m.%Y';
                $groupBy = 'YEAR(created_at), MONTH(created_at)';
                $orderBy = 'YEAR(created_at) DESC, MONTH(created_at) DESC';
                $selectDate = "DATE_FORMAT(created_at, '$dateFormat') as date";
                $whereClause = "created_at >= DATE_SUB(NOW(), INTERVAL $interval)
                                AND YEAR(created_at) = YEAR(NOW())";
            } elseif ($period === 'month') {
                $groupBy = 'YEAR(created_at), WEEK(created_at, 1)';
                $orderBy = 'YEAR(created_at) DESC, WEEK(created_at, 1) DESC';
                $selectDate = "CONCAT('Неделя ', WEEK(created_at, 1), ' (', 
                                  DATE_FORMAT(DATE_ADD(created_at, INTERVAL -WEEKDAY(created_at) DAY), '%d.%m'), ' - ',
                                  DATE_FORMAT(DATE_ADD(created_at, INTERVAL 6-WEEKDAY(created_at) DAY), '%d.%m'), ')') as date";
                $whereClause = "created_at >= DATE_SUB(NOW(), INTERVAL $interval) 
                                AND MONTH(created_at) = MONTH(NOW()) 
                                AND YEAR(created_at) = YEAR(NOW())";
            } else {
                $dateFormat = '%d.%m';
                $groupBy = 'DATE(created_at)';
                $orderBy = 'date DESC';
                $selectDate = "DATE_FORMAT(created_at, '$dateFormat') as date";
                $whereClause = "created_at >= DATE_SUB(NOW(), INTERVAL $interval)
                                AND MONTH(created_at) = MONTH(NOW()) 
                                AND YEAR(created_at) = YEAR(NOW())";
            }
            
            $sql = "SELECT 
                        $selectDate,
                        COUNT(*) as order_count,
                        SUM(total) as total_revenue,
                        AVG(total) as avg_order_value
                    FROM orders
                    WHERE $whereClause
                    AND status = 'завершён'
                    GROUP BY $groupBy
                    ORDER BY $orderBy";
        }

        try {
            $stmt = $this->prepareCached($sql);
            $stmt->execute();

            $result = $stmt->fetchAll();
            if ($period === 'day' && !empty($result)) {
                foreach ($result as &$row) {
                    if (isset($row['time'])) {
                        $row['Время'] = $row['time'];
                        unset($row['time']);
                    }
                }
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("getSalesReport Error: " . $e->getMessage());
            return [];
        }
    }

    public function getProfitReport($period = 'day')
    {
        $intervals = [
            'day' => '1 DAY',
            'week' => '1 WEEK', 
            'month' => '1 MONTH',
            'year' => '1 YEAR'
        ];
        
        $interval = $intervals[$period] ?? '1 DAY';
        
        if ($period === 'day') {
            $sql = "SELECT
                        o.id as order_id,
                        TIME(o.created_at) as time,
                        o.total as total_revenue,
                        COALESCE(oi_agg.expenses, 0) as total_expenses,
                        o.total - COALESCE(oi_agg.expenses, 0) as total_profit,
                        ROUND(((o.total - COALESCE(oi_agg.expenses, 0)) / o.total) * 100, 2) as profitability_percent
                    FROM orders o
                    LEFT JOIN (
                        SELECT oi.order_id, SUM(mi.cost * oi.quantity) as expenses
                        FROM order_items oi
                        JOIN menu_items mi ON mi.id = oi.item_id
                        GROUP BY oi.order_id
                    ) oi_agg ON oi_agg.order_id = o.id
                    WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL $interval)
                    AND DAY(o.created_at) = DAY(NOW())
                    AND o.status = 'завершён'
                    ORDER BY o.created_at DESC";
        } else {
            if ($period === 'year') {
                $dateFormat = '%m.%Y';
                $groupBy = 'YEAR(o.created_at), MONTH(o.created_at)';
                $orderBy = 'YEAR(o.created_at) DESC, MONTH(o.created_at) DESC';
                $selectDate = "DATE_FORMAT(o.created_at, '$dateFormat') as date";
                $whereClause = "o.created_at >= DATE_SUB(NOW(), INTERVAL $interval)
                                AND YEAR(o.created_at) = YEAR(NOW())";
            } elseif ($period === 'month') {
                $groupBy = 'YEAR(o.created_at), WEEK(o.created_at, 1)';
                $orderBy = 'YEAR(o.created_at) DESC, WEEK(o.created_at, 1) DESC';
                $selectDate = "CONCAT('Неделя ', WEEK(o.created_at, 1), ' (',
                                  DATE_FORMAT(DATE_ADD(o.created_at, INTERVAL -WEEKDAY(o.created_at) DAY), '%d.%m'), ' - ',
                                  DATE_FORMAT(DATE_ADD(o.created_at, INTERVAL 6-WEEKDAY(o.created_at) DAY), '%d.%m'), ')') as date";
                $whereClause = "o.created_at >= DATE_SUB(NOW(), INTERVAL $interval)
                                AND MONTH(o.created_at) = MONTH(NOW())
                                AND YEAR(o.created_at) = YEAR(NOW())";
            } else {
                $dateFormat = '%d.%m';
                $groupBy = 'DATE(o.created_at)';
                $orderBy = 'date DESC';
                $selectDate = "DATE_FORMAT(o.created_at, '$dateFormat') as date";
                $whereClause = "o.created_at >= DATE_SUB(NOW(), INTERVAL $interval)
                                AND MONTH(o.created_at) = MONTH(NOW())
                                AND YEAR(o.created_at) = YEAR(NOW())";
            }

            $sql = "SELECT
                        $selectDate,
                        COUNT(*) as order_count,
                        SUM(o.total) as total_revenue,
                        SUM(COALESCE(oi_agg.expenses, 0)) as total_expenses,
                        SUM(o.total - COALESCE(oi_agg.expenses, 0)) as total_profit,
                        ROUND((SUM(o.total - COALESCE(oi_agg.expenses, 0)) / SUM(o.total)) * 100, 2) as profitability_percent
                    FROM orders o
                    LEFT JOIN (
                        SELECT oi.order_id, SUM(mi.cost * oi.quantity) as expenses
                        FROM order_items oi
                        JOIN menu_items mi ON mi.id = oi.item_id
                        GROUP BY oi.order_id
                    ) oi_agg ON oi_agg.order_id = o.id
                    WHERE $whereClause
                    AND o.status = 'завершён'
                    GROUP BY $groupBy
                    ORDER BY $orderBy";
        }

        try {
            $stmt = $this->prepareCached($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getProfitReport Error: " . $e->getMessage());
            return [];
        }
    }

    // Additional analytics reports follow the same pattern.
    public function getEfficiencyReport($period = 'day')
    {
        $intervals = [
            'day' => '1 DAY',
            'week' => '1 WEEK',
            'month' => '1 MONTH', 
            'year' => '1 YEAR'
        ];
        
        $interval = $intervals[$period] ?? '1 DAY';
        
        if ($period === 'day') {
            $sql = "SELECT
                        o.id as order_id,
                        o.delivery_type,
                        TIMESTAMPDIFF(MINUTE, o.created_at, o.updated_at) as time_minutes,
                        o.total as total_revenue,
                        COALESCE(oi_agg.expenses, 0) as total_expenses,
                        o.total - COALESCE(oi_agg.expenses, 0) as total_profit
                    FROM orders o
                    LEFT JOIN (
                        SELECT oi.order_id, SUM(mi.cost * oi.quantity) as expenses
                        FROM order_items oi
                        JOIN menu_items mi ON mi.id = oi.item_id
                        GROUP BY oi.order_id
                    ) oi_agg ON oi_agg.order_id = o.id
                    WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL $interval)
                    AND o.status = 'завершён'
                    ORDER BY o.created_at DESC";
        } else {
            $sql = "SELECT
                        o.delivery_type,
                        FLOOR(AVG(TIMESTAMPDIFF(MINUTE, o.created_at, o.updated_at))) as avg_time_minutes,
                        COUNT(*) as order_count,
                        SUM(o.total) as total_revenue,
                        SUM(COALESCE(oi_agg.expenses, 0)) as total_expenses,
                        SUM(o.total - COALESCE(oi_agg.expenses, 0)) as total_profit
                    FROM orders o
                    LEFT JOIN (
                        SELECT oi.order_id, SUM(mi.cost * oi.quantity) as expenses
                        FROM order_items oi
                        JOIN menu_items mi ON mi.id = oi.item_id
                        GROUP BY oi.order_id
                    ) oi_agg ON oi_agg.order_id = o.id
                    WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL $interval)
                    AND o.status = 'завершён'
                    GROUP BY o.delivery_type
                    ORDER BY order_count DESC";
        }
        
        try {
            $stmt = $this->prepareCached($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getEfficiencyReport Error: " . $e->getMessage());
            return [];
        }
    }

    public function getAvgCompletionMinutes(string $deliveryType = ''): int
    {
        try {
            if ($deliveryType !== '') {
                $stmt = $this->prepareCached(
                    "SELECT FLOOR(AVG(TIMESTAMPDIFF(MINUTE, created_at, updated_at)))
                     FROM orders
                     WHERE status = 'завершён'
                       AND delivery_type = :dt
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
                );
                $stmt->execute([':dt' => $deliveryType]);
            } else {
                $stmt = $this->prepareCached(
                    "SELECT FLOOR(AVG(TIMESTAMPDIFF(MINUTE, created_at, updated_at)))
                     FROM orders
                     WHERE status = 'завершён'
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
                );
                $stmt->execute();
            }
            $val = $stmt->fetchColumn();
            return ($val !== null && $val > 0) ? (int)$val : 20;
        } catch (PDOException $e) {
            error_log("getAvgCompletionMinutes Error: " . $e->getMessage());
            return 20;
        }
    }

    public function getTopCustomers($period = 'day', $limit = 20)
    {
        $intervals = [
            'day' => '1 DAY',
            'week' => '1 WEEK',
            'month' => '1 MONTH',
            'year' => '1 YEAR'
        ];
        
        $interval = $intervals[$period] ?? '1 DAY';
        
        if ($period === 'day') {
            $sql = "SELECT
                        o.id as order_id,
                        u.name,
                        u.phone,
                        TIME(o.created_at) as time,
                        o.total as order_total,
                        COALESCE(oi_agg.item_count, 0) as item_count,
                        COALESCE(oi_agg.expenses, 0) as order_expenses,
                        o.total - COALESCE(oi_agg.expenses, 0) as order_profit
                    FROM orders o
                    JOIN users u ON o.user_id = u.id
                    LEFT JOIN (
                        SELECT oi.order_id,
                               COUNT(*) as item_count,
                               SUM(mi.cost * oi.quantity) as expenses
                        FROM order_items oi
                        JOIN menu_items mi ON mi.id = oi.item_id
                        GROUP BY oi.order_id
                    ) oi_agg ON oi_agg.order_id = o.id
                    WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL $interval)
                    AND o.status = 'завершён'
                    ORDER BY o.created_at DESC
                    LIMIT ?";
        } else {
            $sql = "SELECT 
                        u.id,
                        u.name,
                        u.phone,
                        COUNT(o.id) as order_count,
                        SUM(o.total) as total_spent,
                        AVG(o.total) as avg_order_value
                    FROM orders o
                    JOIN users u ON o.user_id = u.id
                    WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL $interval)
                    AND o.status = 'завершён'
                    GROUP BY u.id
                    ORDER BY total_spent DESC
                    LIMIT ?";
        }
        
        try {
            $stmt = $this->prepareCached($sql);
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getTopCustomers Error: " . $e->getMessage());
            return [];
        }
    }

    public function getTopDishes($period = 'day', $limit = 20)
    {
        $intervals = [
            'day' => '1 DAY',
            'week' => '1 WEEK',
            'month' => '1 MONTH',
            'year' => '1 YEAR'
        ];
        
        $interval = $intervals[$period] ?? '1 DAY';
        
        $sql = "SELECT 
                    mi.id,
                    mi.name,
                    mi.category,
                    SUM(oi.quantity) as total_quantity,
                    SUM(oi.quantity * oi.price) as total_revenue,
                    SUM(oi.quantity * (oi.price - mi.cost)) as total_profit,
                    SUM(oi.quantity * mi.cost) as total_expenses
                FROM orders o
                JOIN order_items oi ON oi.order_id = o.id
                JOIN menu_items mi ON mi.id = oi.item_id
                WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL $interval)
                AND o.status = 'завершён'
                GROUP BY mi.id
                ORDER BY total_profit DESC
                LIMIT ?";
        
        try {
            $stmt = $this->prepareCached($sql);
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getTopDishes Error: " . $e->getMessage());
            return [];
        }
    }

    public function getEmployeeStats($period = 'day', $limit = 20)
    {
        $intervals = [
            'day' => '1 DAY',
            'week' => '1 WEEK',
            'month' => '1 MONTH',
            'year' => '1 YEAR'
        ];
        
        $interval = $intervals[$period] ?? '1 DAY';
        
        if ($period === 'day') {
            $sql = "SELECT
                        o.id as order_id,
                        u.name,
                        u.phone,
                        TIMESTAMPDIFF(MINUTE, o.created_at, o.updated_at) as processing_time,
                        o.total as total_revenue,
                        COALESCE(oi_agg.expenses, 0) as total_expenses,
                        o.total - COALESCE(oi_agg.expenses, 0) as total_profit
                    FROM orders o
                    JOIN users u ON o.last_updated_by = u.id
                    LEFT JOIN (
                        SELECT oi.order_id, SUM(mi.cost * oi.quantity) as expenses
                        FROM order_items oi
                        JOIN menu_items mi ON mi.id = oi.item_id
                        GROUP BY oi.order_id
                    ) oi_agg ON oi_agg.order_id = o.id
                    WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL $interval)
                    AND o.status = 'завершён'
                    AND u.role IN ('owner', 'employee', 'admin')
                    ORDER BY o.created_at DESC
                    LIMIT ?";
        } else {
            $sql = "SELECT
                        u.id,
                        u.name,
                        u.phone,
                        COUNT(o.id) as order_count,
                        SUM(o.total) as total_revenue,
                        FLOOR(AVG(TIMESTAMPDIFF(MINUTE, o.created_at, o.updated_at))) as avg_processing_time,
                        SUM(COALESCE(oi_agg.expenses, 0)) as total_expenses,
                        SUM(o.total - COALESCE(oi_agg.expenses, 0)) as total_profit
                    FROM orders o
                    JOIN users u ON o.last_updated_by = u.id
                    LEFT JOIN (
                        SELECT oi.order_id, SUM(mi.cost * oi.quantity) as expenses
                        FROM order_items oi
                        JOIN menu_items mi ON mi.id = oi.item_id
                        GROUP BY oi.order_id
                    ) oi_agg ON oi_agg.order_id = o.id
                    WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL $interval)
                    AND o.status = 'завершён'
                    AND u.role IN ('owner', 'employee', 'admin')
                    GROUP BY u.id
                    ORDER BY total_revenue DESC
                    LIMIT ?";
        }
        
        try {
            $stmt = $this->prepareCached($sql);
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getEmployeeStats Error: " . $e->getMessage());
            return [];
        }
    }

    public function getHourlyLoad($period = 'day')
    {
        $intervals = [
            'day' => '1 DAY',
            'week' => '1 WEEK',
            'month' => '1 MONTH',
            'year' => '1 YEAR'
        ];
        
        $interval = $intervals[$period] ?? '1 DAY';
        
        $sql = "SELECT
                    HOUR(o.created_at) as hour,
                    COUNT(*) as order_count,
                    AVG(o.total) as avg_order_value,
                    SUM(o.total) as total_revenue,
                    SUM(o.total - COALESCE(oi_agg.expenses, 0)) as total_profit,
                    SUM(COALESCE(oi_agg.expenses, 0)) as total_expenses
                FROM orders o
                LEFT JOIN (
                    SELECT oi.order_id, SUM(mi.cost * oi.quantity) as expenses
                    FROM order_items oi
                    JOIN menu_items mi ON mi.id = oi.item_id
                    GROUP BY oi.order_id
                ) oi_agg ON oi_agg.order_id = o.id
                WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL $interval)
                AND o.status = 'завершён'
                GROUP BY HOUR(o.created_at)
                ORDER BY hour ASC";
        
        try {
            $stmt = $this->prepareCached($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getHourlyLoad Error: " . $e->getMessage());
            return [];
        }
    }

    public function getOrderFlowBottleneckReport(string $period = 'day'): array
    {
        if (!$this->ensureOrderStatusHistoryTable()) {
            return [];
        }

        $period = in_array($period, ['day', 'week', 'month', 'year'], true) ? $period : 'day';

        switch ($period) {
            case 'week':
                $whereClause = "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)";
                break;
            case 'month':
                $whereClause = "MONTH(o.created_at) = MONTH(NOW()) AND YEAR(o.created_at) = YEAR(NOW())";
                break;
            case 'year':
                $whereClause = "YEAR(o.created_at) = YEAR(NOW())";
                break;
            case 'day':
            default:
                $whereClause = "o.created_at >= CURDATE() AND o.created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
                break;
        }

        $stageTimesSql = "
            SELECT
                o.id AS order_id,
                o.created_at,
                MIN(CASE
                    WHEN LOWER(COALESCE(h.status, '')) LIKE '%при%'
                      OR LOWER(COALESCE(h.status, '')) LIKE '%accept%'
                    THEN h.changed_at END) AS accepted_at,
                MIN(CASE
                    WHEN LOWER(COALESCE(h.status, '')) LIKE '%готов%'
                      OR LOWER(COALESCE(h.status, '')) LIKE '%cook%'
                      OR LOWER(COALESCE(h.status, '')) LIKE '%prepar%'
                    THEN h.changed_at END) AS cooking_at,
                MIN(CASE
                    WHEN LOWER(COALESCE(h.status, '')) LIKE '%достав%'
                      OR LOWER(COALESCE(h.status, '')) LIKE '%deliver%'
                      OR LOWER(COALESCE(h.status, '')) LIKE '%ready%'
                    THEN h.changed_at END) AS delivering_at,
                MIN(CASE
                    WHEN LOWER(COALESCE(h.status, '')) LIKE '%заверш%'
                      OR LOWER(COALESCE(h.status, '')) LIKE '%complete%'
                      OR LOWER(COALESCE(h.status, '')) LIKE '%done%'
                    THEN h.changed_at END) AS completed_at
            FROM orders o
            LEFT JOIN order_status_history h ON h.order_id = o.id
            WHERE {$whereClause}
            GROUP BY o.id, o.created_at
        ";

        $sql = "
            SELECT
                stage,
                ROUND(AVG(minutes), 1) AS avg_minutes,
                MAX(minutes) AS max_minutes,
                COUNT(*) AS orders_count
            FROM (
                SELECT 1 AS stage_sort, 'Создан -> Приём' AS stage,
                       TIMESTAMPDIFF(MINUTE, created_at, accepted_at) AS minutes
                FROM ({$stageTimesSql}) st
                WHERE accepted_at IS NOT NULL

                UNION ALL

                SELECT 2 AS stage_sort, 'Приём -> Готовим' AS stage,
                       TIMESTAMPDIFF(MINUTE, accepted_at, cooking_at) AS minutes
                FROM ({$stageTimesSql}) st
                WHERE accepted_at IS NOT NULL AND cooking_at IS NOT NULL

                UNION ALL

                SELECT 3 AS stage_sort, 'Готовим -> Доставляем' AS stage,
                       TIMESTAMPDIFF(MINUTE, cooking_at, delivering_at) AS minutes
                FROM ({$stageTimesSql}) st
                WHERE cooking_at IS NOT NULL AND delivering_at IS NOT NULL

                UNION ALL

                SELECT 4 AS stage_sort, 'Доставляем -> Завершён' AS stage,
                       TIMESTAMPDIFF(MINUTE, delivering_at, completed_at) AS minutes
                FROM ({$stageTimesSql}) st
                WHERE delivering_at IS NOT NULL AND completed_at IS NOT NULL

                UNION ALL

                SELECT 5 AS stage_sort, 'Создан -> Завершён (итого)' AS stage,
                       TIMESTAMPDIFF(MINUTE, created_at, completed_at) AS minutes
                FROM ({$stageTimesSql}) st
                WHERE completed_at IS NOT NULL
            ) metrics
            WHERE minutes IS NOT NULL AND minutes >= 0
            GROUP BY stage_sort, stage
            ORDER BY stage_sort ASC
        ";

        try {
            $stmt = $this->prepareCached($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log("getOrderFlowBottleneckReport Error: " . $e->getMessage());
            return [];
        }
    }

    public function getSetting($key)
    {
        try {
            $stmt = $this->prepareCached(
                "SELECT value FROM settings WHERE `key` = :key LIMIT 1"
            );
            $stmt->execute([':key' => $key]);
            $result = $stmt->fetch();
            return $result ? $result['value'] : null;
        } catch (PDOException $e) {
            error_log("getSetting Error: " . $e->getMessage());
            return null;
        }
    }

    public function setSetting($key, $value, $updatedBy = null)
    {
        try {
            $stmt = $this->prepareCached("
                INSERT INTO settings (`key`, value, updated_by)
                VALUES (:key, :value, :updated_by)
                ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)
            ");
            return $stmt->execute([
                ':key' => $key,
                ':value' => $value,
                ':updated_by' => $updatedBy
            ]);
        } catch (PDOException $e) {
            error_log("setSetting Error: " . $e->getMessage());
            return false;
        }
    }

    public function getAllSettings()
    {
        try {
            $stmt = $this->prepareCached(
                "SELECT `key`, value FROM settings"
            );
            $stmt->execute();
            $settings = $stmt->fetchAll();
            $result = [];
            foreach ($settings as $setting) {
                $result[$setting['key']] = $setting['value'];
            }
            return $result;
        } catch (PDOException $e) {
            error_log("getAllSettings Error: " . $e->getMessage());
            return [];
        }
    }

    public function updateUserRole($userId, $role)
    {
        try {
            $stmt = $this->prepareCached(
                "UPDATE users SET role = :role WHERE id = :id"
            );
            $success = $stmt->execute([':role' => $role, ':id' => $userId]);
            
            if ($success) {
                $this->invalidateUserCache($userId);
            }
            
            return $success;
        } catch (PDOException $e) {
            error_log("updateUserRole error: " . $e->getMessage());
            return false;
        }
    }

    public function prepare($sql)
    {
        return $this->prepareCached($sql);
    }

    public function createGuestOrder($items, $total, $deliveryType = 'bar', $deliveryDetail = '', string $paymentMethod = 'cash', string $paymentStatus = 'pending')
    {
        try {
            $this->connection->beginTransaction();
            $initialStatus = $this->getInitialOrderStatus();

            $guestUserId = $this->ensureGuestUserExists();
            if ($guestUserId === false) {
                throw new Exception('Failed to ensure guest user exists');
            }

            $stmt = $this->prepareCached("
                INSERT INTO orders
                (user_id, items, total, status, delivery_type, delivery_details, payment_method, payment_status, created_at, updated_at)
                VALUES (:user_id, :items, :total, :initial_status, :delivery_type, :delivery_details, :payment_method, :payment_status, NOW(), NOW())
            ");

            $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($itemsJson === false) {
                throw new Exception('Failed to encode order items to JSON');
            }

            $params = [
                ':user_id' => $guestUserId,
                ':items' => $itemsJson,
                ':total' => $total,
                ':initial_status' => $initialStatus,
                ':delivery_type' => $deliveryType,
                ':delivery_details' => $deliveryDetail,
                ':payment_method' => $paymentMethod,
                ':payment_status' => $paymentStatus,
            ];

            $stmt->execute($params);
            $orderId = $this->connection->lastInsertId();

            $historyStmt = $this->prepareCached("
                INSERT INTO order_status_history
                (order_id, status, changed_by, changed_at)
                VALUES (:order_id, :initial_status, :changed_by, NOW())
            ");
            $historyStmt->execute([
                ':order_id' => $orderId,
                ':initial_status' => $initialStatus,
                ':changed_by' => $guestUserId,
            ]);

            $this->persistOrderItems((int)$orderId, $items);

            $this->connection->commit();

            $this->invalidateOrderCache($orderId);
            $this->touchOrdersLastUpdate();

            return $orderId;
        } catch (Throwable $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            error_log("createGuestOrder error: " . $e->getMessage());
            return false;
        }
    }

    public function createOrder($userId, $items, $total, $deliveryType = 'bar', $deliveryDetail = '', $tips = 0.0, string $paymentMethod = 'cash', string $paymentStatus = 'pending')
    {
        try {
            $this->connection->beginTransaction();
            $initialStatus = $this->getInitialOrderStatus();

            $stmt = $this->prepareCached("
                INSERT INTO orders
                (user_id, items, total, tips, status, delivery_type, delivery_details, payment_method, payment_status, created_at, updated_at)
                VALUES (:user_id, :items, :total, :tips, :initial_status, :delivery_type, :delivery_details, :payment_method, :payment_status, NOW(), NOW())
            ");

            $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($itemsJson === false) {
                throw new Exception('Failed to encode order items to JSON');
            }

            $params = [
                ':user_id' => $userId,
                ':items' => $itemsJson,
                ':total' => $total,
                ':tips' => max(0.0, (float)$tips),
                ':initial_status' => $initialStatus,
                ':delivery_type' => $deliveryType,
                ':delivery_details' => $deliveryDetail,
                ':payment_method' => $paymentMethod,
                ':payment_status' => $paymentStatus,
            ];

            $stmt->execute($params);
            $orderId = $this->connection->lastInsertId();

            $historyStmt = $this->prepareCached("
                INSERT INTO order_status_history
                (order_id, status, changed_by, changed_at)
                VALUES (:order_id, :initial_status, :user_id, NOW())
            ");
            $historyStmt->execute([
                ':order_id' => $orderId,
                ':initial_status' => $initialStatus,
                ':user_id' => $userId,
            ]);

            $this->persistOrderItems((int)$orderId, $items);

            $this->connection->commit();

            $this->invalidateOrderCache($orderId);
            $this->touchOrdersLastUpdate();

            return $orderId;
        } catch (Throwable $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            error_log("createOrder error: " . $e->getMessage());
            return false;
        }
    }

    public function updateOrderPayment(int $orderId, string $paymentId, string $paymentStatus, string $paymentMethod = ''): bool
    {
        try {
            if ($paymentMethod !== '') {
                $stmt = $this->prepareCached(
                    "UPDATE orders SET payment_id = :pid, payment_status = :pstatus, payment_method = :pmethod, updated_at = NOW() WHERE id = :id"
                );
                $success = $stmt->execute([':pid' => $paymentId, ':pstatus' => $paymentStatus, ':pmethod' => $paymentMethod, ':id' => $orderId]);
                if ($success) {
                    $this->invalidateOrderCache($orderId);
                    $this->touchOrdersLastUpdate();
                }
                return $success;
            }
            $stmt = $this->prepareCached(
                "UPDATE orders SET payment_id = :pid, payment_status = :pstatus, updated_at = NOW() WHERE id = :id"
            );
            $success = $stmt->execute([':pid' => $paymentId, ':pstatus' => $paymentStatus, ':id' => $orderId]);
            if ($success) {
                $this->invalidateOrderCache($orderId);
                $this->touchOrdersLastUpdate();
            }
            return $success;
        } catch (PDOException $e) {
            error_log("updateOrderPayment error: " . $e->getMessage());
            return false;
        }
    }

    public function setOrderPaymentStatus(int $orderId, string $paymentStatus): bool
    {
        try {
            $stmt = $this->prepareCached(
                "UPDATE orders SET payment_status = :pstatus, updated_at = NOW() WHERE id = :id"
            );
            $success = $stmt->execute([':pstatus' => $paymentStatus, ':id' => $orderId]);
            if ($success) {
                $this->invalidateOrderCache($orderId);
                $this->touchOrdersLastUpdate();
            }
            return $success;
        } catch (PDOException $e) {
            error_log("setOrderPaymentStatus error: " . $e->getMessage());
            return false;
        }
    }

    public function confirmCashPayment(int $orderId): bool
    {
        try {
            $stmt = $this->prepareCached(
                "UPDATE orders
                 SET payment_status = 'paid', updated_at = NOW()
                 WHERE id = :id
                   AND payment_method = 'cash'
                   AND payment_status <> 'paid'"
            );
            $stmt->execute([':id' => $orderId]);
            $success = $stmt->rowCount() > 0;
            if ($success) {
                $this->invalidateOrderCache($orderId);
                $this->touchOrdersLastUpdate();
            }
            return $success;
        } catch (PDOException $e) {
            error_log("confirmCashPayment error: " . $e->getMessage());
            return false;
        }
    }

    public function getOrderByPaymentId(string $paymentId): ?array
    {
        try {
            $stmt = $this->prepareCached(
                "SELECT id, user_id, total, status, payment_method, payment_id, payment_status
                 FROM orders WHERE payment_id = :pid LIMIT 1"
            );
            $stmt->execute([':pid' => $paymentId]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            error_log("getOrderByPaymentId error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Store a customer review for a completed order.
     *
     * - order_id has a UNIQUE index at the DB layer; duplicate submissions
     *   raise 23000 / Integrity constraint violation and are caught here so
     *   the endpoint can report "already reviewed" without a fatal.
     * - rating is clamped to 1..5 defensively even though reviews-migration
     *   also enforces a CHECK constraint.
     * - comment is hard-trimmed to 2000 chars; longer submissions are
     *   truncated rather than rejected — this matches the append-only spirit
     *   (we do not want the user to lose text mid-submit).
     */
    public function createReview(int $orderId, int $rating, ?string $comment, ?int $userId, ?string $ipHash): ?int
    {
        $rating = max(1, min(5, $rating));
        if ($comment !== null) {
            $comment = trim($comment);
            if ($comment === '') {
                $comment = null;
            } elseif (mb_strlen($comment) > 2000) {
                $comment = mb_substr($comment, 0, 2000);
            }
        }
        try {
            $stmt = $this->prepareCached("
                INSERT INTO reviews (order_id, user_id, rating, comment, ip_hash, created_at)
                VALUES (:order_id, :user_id, :rating, :comment, :ip_hash, NOW())
            ");
            $stmt->execute([
                ':order_id' => $orderId,
                ':user_id'  => $userId,
                ':rating'   => $rating,
                ':comment'  => $comment,
                ':ip_hash'  => $ipHash,
            ]);
            return (int)$this->connection->lastInsertId();
        } catch (PDOException $e) {
            // SQLSTATE 23000 = duplicate (unique key violation). Swallow so
            // the endpoint can report "already reviewed" cleanly.
            if ($e->getCode() === '23000') {
                return null;
            }
            error_log("createReview error: " . $e->getMessage());
            return null;
        }
    }

    public function getReviewByOrderId(int $orderId): ?array
    {
        try {
            $stmt = $this->prepareCached("
                SELECT id, order_id, user_id, rating, comment, created_at
                FROM reviews
                WHERE order_id = :order_id
                LIMIT 1
            ");
            $stmt->execute([':order_id' => $orderId]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            error_log("getReviewByOrderId error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Owner-facing list: latest N reviews with a minimal join to orders so
     * the admin surface can show the order total alongside the stars. The
     * LEFT JOIN is intentional — orders deleted by a cleanup script would
     * already have cascaded their reviews out, but a LEFT JOIN keeps the
     * query robust against schema skew on old tenants.
     */
    public function getRecentReviews(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        try {
            $stmt = $this->connection->prepare("
                SELECT r.id, r.order_id, r.user_id, r.rating, r.comment, r.reply_text,
                       r.replied_at, r.published_at, r.created_at,
                       o.total AS order_total, o.status AS order_status
                FROM reviews r
                LEFT JOIN orders o ON o.id = r.order_id
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT {$limit}
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log("getRecentReviews error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Review moderation (Phase 8.5). Owner writes a reply and/or toggles
     * public visibility on the tenant homepage.
     */
    public function setReviewReply(int $reviewId, ?string $replyText): bool
    {
        $replyText = $replyText !== null ? trim($replyText) : null;
        if ($replyText === '') $replyText = null;
        if ($replyText !== null && mb_strlen($replyText) > 2000) {
            $replyText = mb_substr($replyText, 0, 2000);
        }
        try {
            $stmt = $this->prepareCached("
                UPDATE reviews
                SET reply_text = :reply,
                    replied_at = CASE WHEN :reply IS NULL THEN NULL ELSE COALESCE(replied_at, NOW()) END
                WHERE id = :id
            ");
            return $stmt->execute([':reply' => $replyText, ':id' => $reviewId]);
        } catch (PDOException $e) {
            error_log('setReviewReply error: ' . $e->getMessage());
            return false;
        }
    }

    public function setReviewPublished(int $reviewId, bool $published): bool
    {
        try {
            $stmt = $this->prepareCached("
                UPDATE reviews
                SET published_at = CASE WHEN :pub = 1 THEN COALESCE(published_at, NOW()) ELSE NULL END
                WHERE id = :id
            ");
            return $stmt->execute([':pub' => $published ? 1 : 0, ':id' => $reviewId]);
        } catch (PDOException $e) {
            error_log('setReviewPublished error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Public-facing review feed for the tenant homepage. Only published
     * entries with rating >= $minRating; sorted newest-first.
     */
    public function getPublishedReviews(int $minRating = 4, int $limit = 20): array
    {
        $minRating = max(1, min(5, $minRating));
        $limit = max(1, min(100, $limit));
        try {
            $stmt = $this->connection->prepare("
                SELECT id, rating, comment, reply_text, replied_at, published_at, created_at
                FROM reviews
                WHERE published_at IS NOT NULL
                  AND rating >= :minR
                ORDER BY published_at DESC, id DESC
                LIMIT {$limit}
            ");
            $stmt->execute([':minR' => $minRating]);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('getPublishedReviews error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Reservations — table booking system. See sql/reservations-migration.sql.
     * Status keys are English ('pending','confirmed','seated','cancelled','no_show');
     * UI layer is responsible for display localization.
     */
    public function createReservation(
        string $tableLabel,
        ?int $userId,
        ?string $guestName,
        ?string $guestPhone,
        int $guestsCount,
        string $startsAt,
        string $endsAt,
        ?string $note
    ): ?int {
        $tableLabel = trim($tableLabel);
        $guestsCount = max(1, min(50, $guestsCount));
        if ($guestName !== null) {
            $guestName = trim($guestName);
            if ($guestName === '') { $guestName = null; }
        }
        if ($guestPhone !== null) {
            $guestPhone = trim($guestPhone);
            if ($guestPhone === '') { $guestPhone = null; }
        }
        if ($note !== null) {
            $note = trim($note);
            if ($note === '') {
                $note = null;
            } elseif (mb_strlen($note) > 1000) {
                $note = mb_substr($note, 0, 1000);
            }
        }
        if ($tableLabel === '' || $startsAt === '' || $endsAt === '') {
            return null;
        }
        if (strtotime($endsAt) <= strtotime($startsAt)) {
            return null;
        }
        if (!$this->checkTableAvailable($tableLabel, $startsAt, $endsAt, null)) {
            return null;
        }
        try {
            $stmt = $this->prepareCached("
                INSERT INTO reservations
                    (table_label, user_id, guest_name, guest_phone, guests_count,
                     starts_at, ends_at, status, note, created_at)
                VALUES
                    (:label, :user_id, :name, :phone, :guests,
                     :starts, :ends, 'pending', :note, NOW())
            ");
            $stmt->execute([
                ':label'   => $tableLabel,
                ':user_id' => $userId,
                ':name'    => $guestName,
                ':phone'   => $guestPhone,
                ':guests'  => $guestsCount,
                ':starts'  => $startsAt,
                ':ends'    => $endsAt,
                ':note'    => $note,
            ]);
            return (int)$this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log("createReservation error: " . $e->getMessage());
            return null;
        }
    }

    public function getReservationById(int $id): ?array
    {
        try {
            $stmt = $this->prepareCached("
                SELECT id, table_label, user_id, guest_name, guest_phone,
                       guests_count, starts_at, ends_at, status, note,
                       created_at, confirmed_at
                FROM reservations
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            error_log("getReservationById error: " . $e->getMessage());
            return null;
        }
    }

    public function getReservationsByRange(string $fromDate, string $toDate): array
    {
        try {
            $stmt = $this->prepareCached("
                SELECT id, table_label, user_id, guest_name, guest_phone,
                       guests_count, starts_at, ends_at, status, note,
                       created_at, confirmed_at
                FROM reservations
                WHERE starts_at >= :from AND starts_at < :to
                ORDER BY starts_at ASC, table_label ASC
            ");
            $stmt->execute([':from' => $fromDate, ':to' => $toDate]);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log("getReservationsByRange error: " . $e->getMessage());
            return [];
        }
    }

    public function getUpcomingReservationsByUser(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        try {
            $stmt = $this->connection->prepare("
                SELECT id, table_label, guests_count, starts_at, ends_at, status, note, created_at
                FROM reservations
                WHERE user_id = :uid
                  AND ends_at >= NOW()
                  AND status IN ('pending','confirmed','seated')
                ORDER BY starts_at ASC
                LIMIT {$limit}
            ");
            $stmt->execute([':uid' => $userId]);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log("getUpcomingReservationsByUser error: " . $e->getMessage());
            return [];
        }
    }

    public function updateReservationStatus(int $id, string $status): bool
    {
        $allowed = ['pending','confirmed','seated','cancelled','no_show'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }
        $setConfirmed = $status === 'confirmed' ? ', confirmed_at = NOW()' : '';
        try {
            $stmt = $this->connection->prepare("
                UPDATE reservations
                SET status = :status{$setConfirmed}
                WHERE id = :id
            ");
            return $stmt->execute([':status' => $status, ':id' => $id]);
        } catch (PDOException $e) {
            error_log("updateReservationStatus error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Reservation reminder worker support (Polish 12.2.3).
     *
     * Returns reservations that:
     *   - start in the next $minutesAhead minutes (default 120 = 2h),
     *     within a $windowMinutes-wide window so each cron tick has slack
     *     to catch rows even if the worker missed a previous run;
     *   - are still active (pending/confirmed/seated);
     *   - have not yet had reminder_sent_at stamped.
     *
     * The worker calls markReservationReminderSent($id) after a successful
     * Telegram dispatch.
     */
    public function getReservationsDueForReminder(int $minutesAhead = 120, int $windowMinutes = 10): array
    {
        $minutesAhead  = max(1, min(1440, $minutesAhead));
        $windowMinutes = max(1, min(60, $windowMinutes));
        try {
            $stmt = $this->connection->prepare("
                SELECT id, table_label, user_id, guest_name, guest_phone,
                       guests_count, starts_at, ends_at, status, note
                FROM reservations
                WHERE status IN ('pending','confirmed','seated')
                  AND reminder_sent_at IS NULL
                  AND starts_at BETWEEN
                      DATE_ADD(NOW(), INTERVAL :ahead_low MINUTE)
                  AND DATE_ADD(NOW(), INTERVAL :ahead_high MINUTE)
                ORDER BY starts_at ASC
                LIMIT 200
            ");
            $stmt->execute([
                ':ahead_low'  => $minutesAhead - $windowMinutes,
                ':ahead_high' => $minutesAhead + $windowMinutes,
            ]);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log("getReservationsDueForReminder error: " . $e->getMessage());
            return [];
        }
    }

    public function markReservationReminderSent(int $id): bool
    {
        try {
            $stmt = $this->connection->prepare("
                UPDATE reservations
                SET reminder_sent_at = NOW()
                WHERE id = :id AND reminder_sent_at IS NULL
            ");
            $stmt->execute([':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("markReservationReminderSent error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Conflict check for a (table_label, starts_at, ends_at) triple.
     * Returns true when the slot is free, false when an active reservation
     * overlaps. Cancelled/no_show rows are ignored. Pass $excludeId to skip
     * a specific row (used when editing an existing reservation).
     */
    public function checkTableAvailable(
        string $tableLabel,
        string $startsAt,
        string $endsAt,
        ?int $excludeId = null
    ): bool {
        try {
            $sql = "
                SELECT 1
                FROM reservations
                WHERE table_label = :label
                  AND status IN ('pending','confirmed','seated')
                  AND starts_at < :ends
                  AND ends_at > :starts
            ";
            $params = [
                ':label'  => $tableLabel,
                ':starts' => $startsAt,
                ':ends'   => $endsAt,
            ];
            if ($excludeId !== null) {
                $sql .= " AND id <> :exclude";
                $params[':exclude'] = $excludeId;
            }
            $sql .= " LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchColumn() === false;
        } catch (PDOException $e) {
            error_log("checkTableAvailable error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Kitchen Display System — see sql/kds-migration.sql and docs/kds.md.
     * Status machine: queued → cooking → ready (or → cancelled at any point).
     */
    public function listKitchenStations(bool $activeOnly = false): array
    {
        try {
            $sql = "SELECT id, label, slug, active, sort_order, created_at, updated_at
                    FROM kitchen_stations";
            if ($activeOnly) {
                $sql .= " WHERE active = 1";
            }
            $sql .= " ORDER BY sort_order ASC, id ASC";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('listKitchenStations error: ' . $e->getMessage());
            return [];
        }
    }

    public function getKitchenStationById(int $id): ?array
    {
        try {
            $stmt = $this->prepareCached("
                SELECT id, label, slug, active, sort_order, created_at, updated_at
                FROM kitchen_stations
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('getKitchenStationById error: ' . $e->getMessage());
            return null;
        }
    }

    public function saveKitchenStation(?int $id, string $label, string $slug, bool $active, int $sortOrder): ?int
    {
        $label = trim($label);
        $slug  = trim(strtolower($slug));
        if ($label === '' || $slug === '' || !preg_match('/^[a-z0-9_-]{1,32}$/', $slug)) {
            return null;
        }
        try {
            if ($id !== null && $id > 0) {
                $stmt = $this->prepareCached("
                    UPDATE kitchen_stations
                    SET label = :label, slug = :slug, active = :active, sort_order = :sort
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':label'  => $label,
                    ':slug'   => $slug,
                    ':active' => $active ? 1 : 0,
                    ':sort'   => $sortOrder,
                    ':id'     => $id,
                ]);
                return $id;
            }
            $stmt = $this->prepareCached("
                INSERT INTO kitchen_stations (label, slug, active, sort_order)
                VALUES (:label, :slug, :active, :sort)
            ");
            $stmt->execute([
                ':label'  => $label,
                ':slug'   => $slug,
                ':active' => $active ? 1 : 0,
                ':sort'   => $sortOrder,
            ]);
            return (int)$this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log('saveKitchenStation error: ' . $e->getMessage());
            return null;
        }
    }

    public function deleteKitchenStation(int $id): bool
    {
        try {
            $stmt = $this->prepareCached("DELETE FROM kitchen_stations WHERE id = :id");
            return $stmt->execute([':id' => $id]);
        } catch (PDOException $e) {
            error_log('deleteKitchenStation error: ' . $e->getMessage());
            return false;
        }
    }

    public function getMenuItemStations(int $menuItemId): array
    {
        try {
            $stmt = $this->prepareCached("
                SELECT s.id, s.label, s.slug
                FROM menu_item_stations mis
                JOIN kitchen_stations s ON s.id = mis.station_id
                WHERE mis.menu_item_id = :id AND s.active = 1
                ORDER BY s.sort_order ASC, s.id ASC
            ");
            $stmt->execute([':id' => $menuItemId]);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('getMenuItemStations error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Replace the set of stations a menu item routes to.
     * Passing an empty array detaches the item from all stations — it will
     * then land in the "unrouted" queue that every KDS board surfaces.
     */
    public function setMenuItemStations(int $menuItemId, array $stationIds): bool
    {
        $stationIds = array_values(array_unique(array_filter(
            array_map('intval', $stationIds),
            static fn($v) => $v > 0
        )));
        try {
            $this->connection->beginTransaction();
            $del = $this->prepareCached("DELETE FROM menu_item_stations WHERE menu_item_id = :id");
            $del->execute([':id' => $menuItemId]);
            if (!empty($stationIds)) {
                $ins = $this->connection->prepare("
                    INSERT INTO menu_item_stations (menu_item_id, station_id)
                    VALUES (:item, :station)
                ");
                foreach ($stationIds as $stationId) {
                    $ins->execute([':item' => $menuItemId, ':station' => $stationId]);
                }
            }
            $this->connection->commit();
            return true;
        } catch (PDOException $e) {
            try { $this->connection->rollBack(); } catch (Throwable $ignored) {}
            error_log('setMenuItemStations error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Called once per new order after the orders row is inserted.
     * For each item slot, resolve its stations via menu_item_stations and
     * write one order_item_status row per (slot, station). Items whose menu
     * row has no station mapping still get one row with station_id=NULL so
     * they show up on the "unrouted" tab and aren't silently lost.
     *
     * Returns the number of status rows written. Safe to call more than once:
     * existing rows for (order_id, item_index, station_id) are left alone —
     * a unique-by-hand dedup is done at the application level because MySQL
     * cannot UNIQUE over a nullable column reliably across engines.
     */
    public function routeOrderItemsToStations(int $orderId, array $items): int
    {
        if ($orderId <= 0 || empty($items)) {
            return 0;
        }
        try {
            $existing = $this->connection->prepare("
                SELECT item_index, COALESCE(station_id, 0) AS station_id
                FROM order_item_status
                WHERE order_id = :id
            ");
            $existing->execute([':id' => $orderId]);
            $already = [];
            foreach ($existing->fetchAll() as $row) {
                $already[(int)$row['item_index']][(int)$row['station_id']] = true;
            }

            $ins = $this->connection->prepare("
                INSERT INTO order_item_status
                    (order_id, item_index, menu_item_id, item_name, quantity, station_id, status, created_at)
                VALUES
                    (:oid, :idx, :mid, :name, :qty, :station, 'queued', NOW())
            ");

            $written = 0;
            foreach (array_values($items) as $i => $item) {
                $menuItemId = isset($item['id']) ? (int)$item['id'] : 0;
                $itemName   = isset($item['name']) ? (string)$item['name'] : null;
                $qty        = isset($item['quantity']) ? max(1, (int)$item['quantity']) : 1;

                $stations = $menuItemId > 0 ? $this->getMenuItemStations($menuItemId) : [];

                if (empty($stations)) {
                    if (!empty($already[$i][0])) continue;
                    $ins->execute([
                        ':oid'     => $orderId,
                        ':idx'     => $i,
                        ':mid'     => $menuItemId ?: null,
                        ':name'    => $itemName,
                        ':qty'     => $qty,
                        ':station' => null,
                    ]);
                    $written++;
                    continue;
                }

                foreach ($stations as $station) {
                    $sid = (int)$station['id'];
                    if (!empty($already[$i][$sid])) continue;
                    $ins->execute([
                        ':oid'     => $orderId,
                        ':idx'     => $i,
                        ':mid'     => $menuItemId ?: null,
                        ':name'    => $itemName,
                        ':qty'     => $qty,
                        ':station' => $sid,
                    ]);
                    $written++;
                }
            }
            return $written;
        } catch (PDOException $e) {
            error_log('routeOrderItemsToStations error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Active queue for a station (what the kitchen tablet shows).
     * $stationId === null means the "unrouted" tab — items without a station.
     */
    public function getKdsBoardForStation(?int $stationId): array
    {
        try {
            $where = $stationId === null
                ? "ois.station_id IS NULL"
                : "ois.station_id = :sid";
            $stmt = $this->connection->prepare("
                SELECT ois.id, ois.order_id, ois.item_index, ois.menu_item_id,
                       ois.item_name, ois.quantity, ois.status,
                       ois.started_at, ois.ready_at, ois.created_at,
                       o.status AS order_status,
                       o.delivery_type, o.delivery_details,
                       o.created_at AS order_created_at
                FROM order_item_status ois
                JOIN orders o ON o.id = ois.order_id
                WHERE {$where}
                  AND ois.status IN ('queued', 'cooking')
                  AND o.status NOT IN ('завершён', 'отказ')
                ORDER BY o.created_at ASC, ois.item_index ASC
            ");
            if ($stationId !== null) {
                $stmt->execute([':sid' => $stationId]);
            } else {
                $stmt->execute();
            }
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('getKdsBoardForStation error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Move a single slot forward. Returns true when the row was actually
     * flipped (helps callers distinguish "already there" from "invalid").
     * Stamps started_at on first cooking transition and ready_at on ready.
     */
    public function advanceKdsItemStatus(int $statusRowId, string $newStatus): bool
    {
        if (!in_array($newStatus, ['queued', 'cooking', 'ready', 'cancelled'], true)) {
            return false;
        }
        $extra = '';
        if ($newStatus === 'cooking') {
            $extra = ", started_at = COALESCE(started_at, NOW())";
        } elseif ($newStatus === 'ready') {
            $extra = ", ready_at = NOW(), started_at = COALESCE(started_at, NOW())";
        }
        try {
            $stmt = $this->connection->prepare("
                UPDATE order_item_status
                SET status = :status{$extra}
                WHERE id = :id AND status <> :status
            ");
            $stmt->execute([':status' => $newStatus, ':id' => $statusRowId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('advanceKdsItemStatus error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * True when every non-cancelled order_item_status row for this order is
     * `ready`. Called right after a successful advanceKdsItemStatus($x, 'ready')
     * so we can fire the `order.ready` webhook / Telegram ping exactly once.
     * Returns false on DB error rather than a misleading true.
     */
    public function isOrderFullyReady(int $orderId): bool
    {
        try {
            $stmt = $this->prepareCached("
                SELECT
                    SUM(CASE WHEN status <> 'cancelled' THEN 1 ELSE 0 END) AS active_rows,
                    SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) AS ready_rows
                FROM order_item_status
                WHERE order_id = :id
            ");
            $stmt->execute([':id' => $orderId]);
            $row = $stmt->fetch();
            $active = (int)($row['active_rows'] ?? 0);
            $ready  = (int)($row['ready_rows'] ?? 0);
            return $active > 0 && $active === $ready;
        } catch (PDOException $e) {
            error_log('isOrderFullyReady error: ' . $e->getMessage());
            return false;
        }
    }

    public function getKdsLastUpdateTs(?int $stationId): int
    {
        try {
            $where = $stationId === null ? "station_id IS NULL" : "station_id = :sid";
            $stmt = $this->connection->prepare("
                SELECT UNIX_TIMESTAMP(MAX(updated_at)) FROM order_item_status WHERE {$where}
            ");
            if ($stationId !== null) {
                $stmt->execute([':sid' => $stationId]);
            } else {
                $stmt->execute();
            }
            return (int)($stmt->fetchColumn() ?: 0);
        } catch (PDOException $e) {
            error_log('getKdsLastUpdateTs error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Inventory — see sql/inventory-migration.sql and docs/inventory.md.
     * Deduction is transactional; partial application would corrupt the audit log.
     */
    public function listIngredients(bool $includeArchived = false): array
    {
        try {
            $sql = "SELECT i.id, i.name, i.unit, i.stock_qty, i.reorder_threshold,
                           i.cost_per_unit, i.supplier_id, i.archived_at,
                           i.last_alerted_at, i.created_at, i.updated_at,
                           s.name AS supplier_name
                    FROM ingredients i
                    LEFT JOIN suppliers s ON s.id = i.supplier_id";
            if (!$includeArchived) {
                $sql .= " WHERE i.archived_at IS NULL";
            }
            $sql .= " ORDER BY i.name ASC";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('listIngredients error: ' . $e->getMessage());
            return [];
        }
    }

    public function getIngredientById(int $id): ?array
    {
        try {
            $stmt = $this->prepareCached("
                SELECT id, name, unit, stock_qty, reorder_threshold, cost_per_unit,
                       supplier_id, archived_at, last_alerted_at, created_at, updated_at
                FROM ingredients
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('getIngredientById error: ' . $e->getMessage());
            return null;
        }
    }

    public function saveIngredient(
        ?int $id,
        string $name,
        string $unit,
        float $stockQty,
        float $reorderThreshold,
        float $costPerUnit,
        ?int $supplierId
    ): ?int {
        $name = trim($name);
        $unit = trim($unit);
        if ($name === '' || $unit === '' || mb_strlen($name) > 255 || mb_strlen($unit) > 16) {
            return null;
        }
        if ($stockQty < 0 || $reorderThreshold < 0 || $costPerUnit < 0) {
            return null;
        }
        try {
            if ($id !== null && $id > 0) {
                $stmt = $this->prepareCached("
                    UPDATE ingredients
                    SET name = :name, unit = :unit, stock_qty = :qty,
                        reorder_threshold = :threshold, cost_per_unit = :cost,
                        supplier_id = :supplier
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':name'     => $name,
                    ':unit'     => $unit,
                    ':qty'      => $stockQty,
                    ':threshold'=> $reorderThreshold,
                    ':cost'     => $costPerUnit,
                    ':supplier' => $supplierId,
                    ':id'       => $id,
                ]);
                return $id;
            }
            $stmt = $this->prepareCached("
                INSERT INTO ingredients
                    (name, unit, stock_qty, reorder_threshold, cost_per_unit, supplier_id)
                VALUES
                    (:name, :unit, :qty, :threshold, :cost, :supplier)
            ");
            $stmt->execute([
                ':name'     => $name,
                ':unit'     => $unit,
                ':qty'      => $stockQty,
                ':threshold'=> $reorderThreshold,
                ':cost'     => $costPerUnit,
                ':supplier' => $supplierId,
            ]);
            return (int)$this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log('saveIngredient error: ' . $e->getMessage());
            return null;
        }
    }

    public function archiveIngredient(int $id): bool
    {
        try {
            $stmt = $this->prepareCached("
                UPDATE ingredients SET archived_at = NOW()
                WHERE id = :id AND archived_at IS NULL
            ");
            $stmt->execute([':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('archiveIngredient error: ' . $e->getMessage());
            return false;
        }
    }

    public function restoreIngredient(int $id): bool
    {
        try {
            $stmt = $this->prepareCached("
                UPDATE ingredients SET archived_at = NULL
                WHERE id = :id AND archived_at IS NOT NULL
            ");
            $stmt->execute([':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('restoreIngredient error: ' . $e->getMessage());
            return false;
        }
    }

    public function adjustIngredientStock(int $id, float $delta, string $reason, ?string $note = null, ?int $userId = null): bool
    {
        if (!in_array($reason, ['adjustment', 'receipt', 'waste', 'stocktake', 'undo'], true)) {
            return false;
        }
        try {
            $this->connection->beginTransaction();
            $upd = $this->prepareCached("
                UPDATE ingredients SET stock_qty = stock_qty + :delta WHERE id = :id AND archived_at IS NULL
            ");
            $upd->execute([':delta' => $delta, ':id' => $id]);
            if ($upd->rowCount() === 0) {
                $this->connection->rollBack();
                return false;
            }
            $log = $this->prepareCached("
                INSERT INTO stock_movements (ingredient_id, delta, reason, note, created_by, created_at)
                VALUES (:iid, :delta, :reason, :note, :uid, NOW())
            ");
            $log->execute([
                ':iid'    => $id,
                ':delta'  => $delta,
                ':reason' => $reason,
                ':note'   => $note !== null && $note !== '' ? $note : null,
                ':uid'    => $userId,
            ]);
            $this->connection->commit();
            return true;
        } catch (PDOException $e) {
            try { $this->connection->rollBack(); } catch (Throwable $ignored) {}
            error_log('adjustIngredientStock error: ' . $e->getMessage());
            return false;
        }
    }

    public function listSuppliers(bool $includeArchived = false): array
    {
        try {
            $sql = "SELECT id, name, contact, notes, archived_at, created_at, updated_at FROM suppliers";
            if (!$includeArchived) {
                $sql .= " WHERE archived_at IS NULL";
            }
            $sql .= " ORDER BY name ASC";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('listSuppliers error: ' . $e->getMessage());
            return [];
        }
    }

    public function saveSupplier(?int $id, string $name, ?string $contact, ?string $notes): ?int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            return null;
        }
        $contact = $contact !== null ? trim($contact) : null;
        if ($contact === '') { $contact = null; }
        $notes = $notes !== null ? trim($notes) : null;
        if ($notes === '') { $notes = null; }
        try {
            if ($id !== null && $id > 0) {
                $stmt = $this->prepareCached("
                    UPDATE suppliers SET name = :name, contact = :contact, notes = :notes
                    WHERE id = :id
                ");
                $stmt->execute([':name' => $name, ':contact' => $contact, ':notes' => $notes, ':id' => $id]);
                return $id;
            }
            $stmt = $this->prepareCached("
                INSERT INTO suppliers (name, contact, notes) VALUES (:name, :contact, :notes)
            ");
            $stmt->execute([':name' => $name, ':contact' => $contact, ':notes' => $notes]);
            return (int)$this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log('saveSupplier error: ' . $e->getMessage());
            return null;
        }
    }

    public function getRecipeForMenuItem(int $menuItemId): array
    {
        try {
            $stmt = $this->prepareCached("
                SELECT r.ingredient_id, r.quantity,
                       i.name AS ingredient_name, i.unit, i.stock_qty, i.cost_per_unit
                FROM recipes r
                JOIN ingredients i ON i.id = r.ingredient_id
                WHERE r.menu_item_id = :id AND i.archived_at IS NULL
                ORDER BY i.name ASC
            ");
            $stmt->execute([':id' => $menuItemId]);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('getRecipeForMenuItem error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Replace the entire recipe for a menu item.
     * Input: map of [ingredient_id => quantity]; quantities must be > 0.
     * Any ingredient_id not in the map is removed from the recipe.
     */
    public function setRecipeForMenuItem(int $menuItemId, array $ingredientToQty): bool
    {
        try {
            $this->connection->beginTransaction();
            $del = $this->prepareCached("DELETE FROM recipes WHERE menu_item_id = :id");
            $del->execute([':id' => $menuItemId]);

            if (!empty($ingredientToQty)) {
                $ins = $this->connection->prepare("
                    INSERT INTO recipes (menu_item_id, ingredient_id, quantity)
                    VALUES (:mid, :iid, :qty)
                ");
                foreach ($ingredientToQty as $ingredientId => $qty) {
                    $ingredientId = (int)$ingredientId;
                    $qty = (float)$qty;
                    if ($ingredientId <= 0 || $qty <= 0) continue;
                    $ins->execute([':mid' => $menuItemId, ':iid' => $ingredientId, ':qty' => $qty]);
                }
            }
            $this->connection->commit();
            return true;
        } catch (PDOException $e) {
            try { $this->connection->rollBack(); } catch (Throwable $ignored) {}
            error_log('setRecipeForMenuItem error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * For each item slot in the order, look up its recipe and subtract
     * (recipe.quantity × slot.quantity) from each ingredient. Writes one
     * stock_movements row per ingredient change so the audit log stays
     * per-ingredient (not per-order).
     *
     * Fully transactional: either the whole order's recipes apply or nothing.
     * Returns the list of newly-low-stock ingredient ids — caller can fan
     * those out as Telegram / webhook alerts without reopening a transaction.
     */
    public function deductIngredientsForOrder(int $orderId, array $items): array
    {
        if ($orderId <= 0 || empty($items)) {
            return [];
        }
        // First: build a per-ingredient aggregate so a dish that appears
        // twice in the same order only hits one UPDATE per ingredient.
        $aggregate = []; // ingredient_id => total_delta (negative)
        $perIngredientItemId = []; // ingredient_id => menu_item_id that pushed it last
        foreach ($items as $item) {
            $menuItemId = isset($item['id']) ? (int)$item['id'] : 0;
            $qty        = isset($item['quantity']) ? max(1, (int)$item['quantity']) : 1;
            if ($menuItemId <= 0) continue;
            $recipe = $this->getRecipeForMenuItem($menuItemId);
            foreach ($recipe as $row) {
                $iid = (int)$row['ingredient_id'];
                $delta = -1 * ((float)$row['quantity']) * $qty;
                $aggregate[$iid] = ($aggregate[$iid] ?? 0) + $delta;
                $perIngredientItemId[$iid] = $menuItemId;
            }
        }

        if (empty($aggregate)) {
            return [];
        }

        $nowLow = [];

        try {
            $this->connection->beginTransaction();
            $upd = $this->prepareCached("
                UPDATE ingredients
                SET stock_qty = stock_qty + :delta
                WHERE id = :id AND archived_at IS NULL
            ");
            $log = $this->prepareCached("
                INSERT INTO stock_movements
                    (ingredient_id, delta, reason, order_id, menu_item_id, created_at)
                VALUES
                    (:iid, :delta, 'order', :oid, :mid, NOW())
            ");
            $probe = $this->prepareCached("
                SELECT stock_qty, reorder_threshold
                FROM ingredients
                WHERE id = :id AND archived_at IS NULL
            ");

            foreach ($aggregate as $iid => $delta) {
                $upd->execute([':delta' => $delta, ':id' => $iid]);
                $log->execute([
                    ':iid'   => $iid,
                    ':delta' => $delta,
                    ':oid'   => $orderId,
                    ':mid'   => $perIngredientItemId[$iid] ?? null,
                ]);

                $probe->execute([':id' => $iid]);
                $snap = $probe->fetch();
                if ($snap && (float)$snap['stock_qty'] <= (float)$snap['reorder_threshold']
                    && (float)$snap['reorder_threshold'] > 0) {
                    $nowLow[] = (int)$iid;
                }
            }
            $this->connection->commit();
        } catch (PDOException $e) {
            try { $this->connection->rollBack(); } catch (Throwable $ignored) {}
            error_log('deductIngredientsForOrder error: ' . $e->getMessage());
            return [];
        }

        return $nowLow;
    }

    /**
     * Low-stock list with throttling awareness: callers that send alerts
     * should check `last_alerted_at` so Telegram is not spammed every minute.
     */
    public function listLowStockIngredients(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        try {
            $stmt = $this->connection->prepare("
                SELECT id, name, unit, stock_qty, reorder_threshold, last_alerted_at
                FROM ingredients
                WHERE archived_at IS NULL
                  AND reorder_threshold > 0
                  AND stock_qty <= reorder_threshold
                ORDER BY (stock_qty / NULLIF(reorder_threshold, 0)) ASC, name ASC
                LIMIT {$limit}
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('listLowStockIngredients error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Throttle helper: set last_alerted_at=NOW() for the given ingredients
     * so the next deduct does not re-fire the alert within the cooldown.
     * Returns the subset of ids that were actually stamped (i.e. whose
     * last_alerted_at was older than $cooldownMin or NULL).
     */
    public function markIngredientsAlerted(array $ingredientIds, int $cooldownMin = 60): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ingredientIds), static fn($v) => $v > 0)));
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $sel = $this->connection->prepare("
                SELECT id FROM ingredients
                WHERE id IN ({$placeholders})
                  AND (last_alerted_at IS NULL OR last_alerted_at < (NOW() - INTERVAL ? MINUTE))
            ");
            $sel->execute(array_merge($ids, [$cooldownMin]));
            $ready = array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN, 0));
            if (empty($ready)) {
                return [];
            }
            $upPh = implode(',', array_fill(0, count($ready), '?'));
            $upd = $this->connection->prepare("
                UPDATE ingredients SET last_alerted_at = NOW() WHERE id IN ({$upPh})
            ");
            $upd->execute($ready);
            return $ready;
        } catch (PDOException $e) {
            error_log('markIngredientsAlerted error: ' . $e->getMessage());
            return [];
        }
    }

    public function getStockMovementsForIngredient(int $ingredientId, int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        try {
            $stmt = $this->connection->prepare("
                SELECT id, delta, reason, note, order_id, menu_item_id, created_by, created_at
                FROM stock_movements
                WHERE ingredient_id = :id
                ORDER BY id DESC
                LIMIT {$limit}
            ");
            $stmt->execute([':id' => $ingredientId]);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('getStockMovementsForIngredient error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Loyalty program — see sql/loyalty-migration.sql and docs/loyalty.md.
     * All points / promo math runs in decimal to keep rounding transparent.
     */
    public function listLoyaltyTiers(bool $includeArchived = false): array
    {
        try {
            $sql = "SELECT id, name, min_spent, cashback_pct, sort_order, archived_at, created_at, updated_at
                    FROM loyalty_tiers";
            if (!$includeArchived) {
                $sql .= " WHERE archived_at IS NULL";
            }
            $sql .= " ORDER BY min_spent ASC, sort_order ASC, id ASC";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('listLoyaltyTiers error: ' . $e->getMessage());
            return [];
        }
    }

    public function saveLoyaltyTier(?int $id, string $name, float $minSpent, float $cashbackPct, int $sortOrder): ?int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 64) return null;
        if ($minSpent < 0 || $cashbackPct < 0 || $cashbackPct > 100) return null;
        try {
            if ($id !== null && $id > 0) {
                $stmt = $this->prepareCached("
                    UPDATE loyalty_tiers
                    SET name = :name, min_spent = :mspent, cashback_pct = :cb, sort_order = :so
                    WHERE id = :id
                ");
                $stmt->execute([':name' => $name, ':mspent' => $minSpent, ':cb' => $cashbackPct, ':so' => $sortOrder, ':id' => $id]);
                return $id;
            }
            $stmt = $this->prepareCached("
                INSERT INTO loyalty_tiers (name, min_spent, cashback_pct, sort_order)
                VALUES (:name, :mspent, :cb, :so)
            ");
            $stmt->execute([':name' => $name, ':mspent' => $minSpent, ':cb' => $cashbackPct, ':so' => $sortOrder]);
            return (int)$this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log('saveLoyaltyTier error: ' . $e->getMessage());
            return null;
        }
    }

    public function archiveLoyaltyTier(int $id): bool
    {
        try {
            $stmt = $this->prepareCached("UPDATE loyalty_tiers SET archived_at = NOW() WHERE id = :id AND archived_at IS NULL");
            $stmt->execute([':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('archiveLoyaltyTier error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Resolve the tier a user has earned based on lifetime total_spent.
     * Picks the highest tier whose min_spent ≤ spent. Returns null if no tiers defined.
     */
    public function resolveTierForSpent(float $spent): ?array
    {
        try {
            $stmt = $this->prepareCached("
                SELECT id, name, min_spent, cashback_pct, sort_order
                FROM loyalty_tiers
                WHERE archived_at IS NULL AND min_spent <= :spent
                ORDER BY min_spent DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([':spent' => $spent]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('resolveTierForSpent error: ' . $e->getMessage());
            return null;
        }
    }

    public function getOrCreateLoyaltyAccount(int $userId): ?array
    {
        if ($userId <= 0) return null;
        try {
            $stmt = $this->prepareCached("
                SELECT user_id, points_balance, total_spent, tier_id, created_at, updated_at
                FROM loyalty_accounts WHERE user_id = :uid
            ");
            $stmt->execute([':uid' => $userId]);
            $row = $stmt->fetch();
            if ($row) return $row;

            $ins = $this->prepareCached("
                INSERT INTO loyalty_accounts (user_id) VALUES (:uid)
            ");
            $ins->execute([':uid' => $userId]);
            $stmt->execute([':uid' => $userId]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('getOrCreateLoyaltyAccount error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Accrue points for an order. Called from order.paid hooks. Transactional:
     *   - Update total_spent by order total.
     *   - Resolve new tier; store tier_id on the account.
     *   - Compute points = order_total × tier.cashback_pct / 100.
     *   - Insert loyalty_transactions row with reason='accrual'.
     *   - Update points_balance.
     *
     * Returns the points awarded (may be 0 if no tier is defined). Idempotent
     * by (user_id, order_id) — a second call with the same order is a no-op.
     */
    public function accrueLoyaltyPoints(int $userId, int $orderId, float $orderTotal): float
    {
        if ($userId <= 0 || $orderId <= 0 || $orderTotal <= 0) return 0.0;
        try {
            $this->connection->beginTransaction();

            // Idempotency: accrual row already exists for this order.
            $probe = $this->prepareCached("
                SELECT id FROM loyalty_transactions
                WHERE user_id = :uid AND order_id = :oid AND reason = 'accrual'
                LIMIT 1
            ");
            $probe->execute([':uid' => $userId, ':oid' => $orderId]);
            if ($probe->fetchColumn() !== false) {
                $this->connection->commit();
                return 0.0;
            }

            $this->prepareCached("INSERT IGNORE INTO loyalty_accounts (user_id) VALUES (:uid)")
                ->execute([':uid' => $userId]);

            $acc = $this->prepareCached("SELECT total_spent FROM loyalty_accounts WHERE user_id = :uid FOR UPDATE");
            $acc->execute([':uid' => $userId]);
            $prevSpent = (float)($acc->fetchColumn() ?: 0);

            $newSpent = $prevSpent + $orderTotal;
            $tier = $this->resolveTierForSpent($newSpent);
            $cashbackPct = $tier ? (float)$tier['cashback_pct'] : 0.0;
            $tierId = $tier ? (int)$tier['id'] : null;
            $points = round($orderTotal * $cashbackPct / 100.0, 2);

            $upd = $this->prepareCached("
                UPDATE loyalty_accounts
                SET total_spent = :spent,
                    tier_id = :tid,
                    points_balance = points_balance + :pts
                WHERE user_id = :uid
            ");
            $upd->execute([':spent' => $newSpent, ':tid' => $tierId, ':pts' => $points, ':uid' => $userId]);

            if ($points > 0) {
                $log = $this->prepareCached("
                    INSERT INTO loyalty_transactions (user_id, points_delta, reason, order_id, created_at)
                    VALUES (:uid, :pts, 'accrual', :oid, NOW())
                ");
                $log->execute([':uid' => $userId, ':pts' => $points, ':oid' => $orderId]);
            }

            $this->connection->commit();
            return $points;
        } catch (PDOException $e) {
            try { $this->connection->rollBack(); } catch (Throwable $ignored) {}
            error_log('accrueLoyaltyPoints error: ' . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * Redeem points. Transactional + locks the row with FOR UPDATE to avoid
     * double-spend under concurrent cart checkouts. Returns true when the
     * points were actually subtracted. 'amount' is in points (which equal ₽
     * 1:1 in the default config; tenants can re-interpret later if they want
     * a different ratio).
     */
    public function redeemLoyaltyPoints(int $userId, float $amount, ?int $orderId = null, ?string $note = null): bool
    {
        if ($userId <= 0 || $amount <= 0) return false;
        try {
            $this->connection->beginTransaction();
            $sel = $this->prepareCached("SELECT points_balance FROM loyalty_accounts WHERE user_id = :uid FOR UPDATE");
            $sel->execute([':uid' => $userId]);
            $balance = (float)($sel->fetchColumn() ?: 0);
            if ($balance < $amount) {
                $this->connection->rollBack();
                return false;
            }
            $upd = $this->prepareCached("
                UPDATE loyalty_accounts SET points_balance = points_balance - :amt
                WHERE user_id = :uid
            ");
            $upd->execute([':amt' => $amount, ':uid' => $userId]);
            $log = $this->prepareCached("
                INSERT INTO loyalty_transactions (user_id, points_delta, reason, order_id, note, created_at)
                VALUES (:uid, :delta, 'redeem', :oid, :note, NOW())
            ");
            $log->execute([':uid' => $userId, ':delta' => -$amount, ':oid' => $orderId, ':note' => $note]);
            $this->connection->commit();
            return true;
        } catch (PDOException $e) {
            try { $this->connection->rollBack(); } catch (Throwable $ignored) {}
            error_log('redeemLoyaltyPoints error: ' . $e->getMessage());
            return false;
        }
    }

    public function getUserLoyaltyState(int $userId): array
    {
        $empty = [
            'points_balance' => 0.0,
            'total_spent'    => 0.0,
            'tier_name'      => null,
            'tier_cashback_pct' => 0.0,
            'next_tier_name' => null,
            'next_tier_at'   => null,
        ];
        if ($userId <= 0) return $empty;
        try {
            $stmt = $this->prepareCached("
                SELECT la.points_balance, la.total_spent, t.id AS tier_id, t.name AS tier_name, t.cashback_pct
                FROM loyalty_accounts la
                LEFT JOIN loyalty_tiers t ON t.id = la.tier_id
                WHERE la.user_id = :uid
                LIMIT 1
            ");
            $stmt->execute([':uid' => $userId]);
            $row = $stmt->fetch();
            if (!$row) return $empty;

            $state = [
                'points_balance'    => (float)$row['points_balance'],
                'total_spent'       => (float)$row['total_spent'],
                'tier_name'         => $row['tier_name'],
                'tier_cashback_pct' => (float)($row['cashback_pct'] ?? 0),
                'next_tier_name'    => null,
                'next_tier_at'      => null,
            ];

            // Next tier = smallest min_spent strictly greater than current total_spent.
            $next = $this->prepareCached("
                SELECT name, min_spent FROM loyalty_tiers
                WHERE archived_at IS NULL AND min_spent > :spent
                ORDER BY min_spent ASC LIMIT 1
            ");
            $next->execute([':spent' => $state['total_spent']]);
            $nextRow = $next->fetch();
            if ($nextRow) {
                $state['next_tier_name'] = (string)$nextRow['name'];
                $state['next_tier_at']   = (float)$nextRow['min_spent'];
            }
            return $state;
        } catch (PDOException $e) {
            error_log('getUserLoyaltyState error: ' . $e->getMessage());
            return $empty;
        }
    }

    public function getUserLoyaltyHistory(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        try {
            $stmt = $this->connection->prepare("
                SELECT id, points_delta, reason, order_id, note, created_at
                FROM loyalty_transactions
                WHERE user_id = :uid
                ORDER BY id DESC
                LIMIT {$limit}
            ");
            $stmt->execute([':uid' => $userId]);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('getUserLoyaltyHistory error: ' . $e->getMessage());
            return [];
        }
    }

    public function listPromoCodes(bool $includeArchived = false): array
    {
        try {
            $sql = "SELECT id, code, discount_pct, discount_amount, min_order_total,
                           valid_from, valid_to, usage_limit, used_count, description,
                           archived_at, created_at, updated_at
                    FROM promo_codes";
            if (!$includeArchived) {
                $sql .= " WHERE archived_at IS NULL";
            }
            $sql .= " ORDER BY created_at DESC, id DESC";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('listPromoCodes error: ' . $e->getMessage());
            return [];
        }
    }

    public function savePromoCode(
        ?int $id,
        string $code,
        ?float $discountPct,
        ?float $discountAmount,
        float $minOrderTotal,
        ?string $validFrom,
        ?string $validTo,
        int $usageLimit,
        ?string $description
    ): ?int {
        $code = trim(strtoupper($code));
        if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,64}$/', $code)) return null;
        if (($discountPct === null || $discountPct <= 0) && ($discountAmount === null || $discountAmount <= 0)) return null;
        if ($discountPct !== null && $discountAmount !== null && $discountPct > 0 && $discountAmount > 0) return null;
        if ($discountPct !== null && ($discountPct < 0 || $discountPct > 100)) return null;
        if ($discountAmount !== null && $discountAmount < 0) return null;
        if ($minOrderTotal < 0 || $usageLimit < 0) return null;

        try {
            if ($id !== null && $id > 0) {
                $stmt = $this->prepareCached("
                    UPDATE promo_codes
                    SET code = :code, discount_pct = :pct, discount_amount = :amt,
                        min_order_total = :minTotal, valid_from = :vf, valid_to = :vt,
                        usage_limit = :ul, description = :desc
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':code' => $code, ':pct' => $discountPct, ':amt' => $discountAmount,
                    ':minTotal' => $minOrderTotal,
                    ':vf' => $validFrom !== '' ? $validFrom : null,
                    ':vt' => $validTo !== '' ? $validTo : null,
                    ':ul' => $usageLimit, ':desc' => $description,
                    ':id' => $id,
                ]);
                return $id;
            }
            $stmt = $this->prepareCached("
                INSERT INTO promo_codes
                    (code, discount_pct, discount_amount, min_order_total, valid_from, valid_to, usage_limit, description)
                VALUES
                    (:code, :pct, :amt, :minTotal, :vf, :vt, :ul, :desc)
            ");
            $stmt->execute([
                ':code' => $code, ':pct' => $discountPct, ':amt' => $discountAmount,
                ':minTotal' => $minOrderTotal,
                ':vf' => $validFrom !== '' ? $validFrom : null,
                ':vt' => $validTo !== '' ? $validTo : null,
                ':ul' => $usageLimit, ':desc' => $description,
            ]);
            return (int)$this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log('savePromoCode error: ' . $e->getMessage());
            return null;
        }
    }

    public function archivePromoCode(int $id): bool
    {
        try {
            $stmt = $this->prepareCached("UPDATE promo_codes SET archived_at = NOW() WHERE id = :id AND archived_at IS NULL");
            $stmt->execute([':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('archivePromoCode error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate and compute a promo discount for the given cart total.
     * Returns either
     *   ['ok' => true, 'discount' => <rub>, 'new_total' => <rub>, 'promo_id' => int, 'code' => string]
     * or
     *   ['ok' => false, 'error' => '<slug>']
     *
     * This is a PURE read-plus-math call — it does NOT increment used_count.
     * Commit the usage via incrementPromoCodeUsage() once the order is created.
     */
    public function evaluatePromoCode(string $code, float $orderTotal): array
    {
        $code = trim(strtoupper($code));
        if ($code === '') return ['ok' => false, 'error' => 'empty'];
        try {
            $stmt = $this->prepareCached("
                SELECT id, code, discount_pct, discount_amount, min_order_total,
                       valid_from, valid_to, usage_limit, used_count
                FROM promo_codes
                WHERE code = :code AND archived_at IS NULL
                LIMIT 1
            ");
            $stmt->execute([':code' => $code]);
            $row = $stmt->fetch();
            if (!$row) return ['ok' => false, 'error' => 'not_found'];

            $now = time();
            if (!empty($row['valid_from']) && strtotime((string)$row['valid_from']) > $now) {
                return ['ok' => false, 'error' => 'not_yet_valid'];
            }
            if (!empty($row['valid_to']) && strtotime((string)$row['valid_to']) < $now) {
                return ['ok' => false, 'error' => 'expired'];
            }
            if ((int)$row['usage_limit'] > 0 && (int)$row['used_count'] >= (int)$row['usage_limit']) {
                return ['ok' => false, 'error' => 'limit_reached'];
            }
            if ($orderTotal < (float)$row['min_order_total']) {
                return ['ok' => false, 'error' => 'below_min_total', 'min' => (float)$row['min_order_total']];
            }

            if ($row['discount_pct'] !== null && (float)$row['discount_pct'] > 0) {
                $discount = round($orderTotal * ((float)$row['discount_pct']) / 100.0, 2);
            } else {
                $discount = round((float)($row['discount_amount'] ?? 0), 2);
            }
            $discount = max(0.0, min($discount, $orderTotal));
            $newTotal = round($orderTotal - $discount, 2);
            return [
                'ok'        => true,
                'promo_id'  => (int)$row['id'],
                'code'      => (string)$row['code'],
                'discount'  => $discount,
                'new_total' => $newTotal,
            ];
        } catch (PDOException $e) {
            error_log('evaluatePromoCode error: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    public function incrementPromoCodeUsage(int $promoId): bool
    {
        if ($promoId <= 0) return false;
        try {
            $stmt = $this->prepareCached("
                UPDATE promo_codes
                SET used_count = used_count + 1
                WHERE id = :id
                  AND archived_at IS NULL
                  AND (usage_limit = 0 OR used_count < usage_limit)
            ");
            $stmt->execute([':id' => $promoId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('incrementPromoCodeUsage error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Enhanced analytics (Phase 6.4). Read-only — safe for owner surface.
     * No implicit "last 30 days" default so the owner can drill any window.
     *
     * Per-dish margin: revenue = sum(price × qty from orders.items JSON blob),
     * cogs = sum(current menu_items.cost × qty). History-accurate for price
     * (snapshotted in the blob) but uses current cost — freezing per-order
     * cogs is a follow-up.
     */
    public function getDishMargins(string $fromDt, string $toDt, int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        try {
            $stmt = $this->connection->prepare("
                SELECT
                    mi.id,
                    mi.name,
                    mi.category,
                    mi.cost AS current_cost,
                    agg.units_sold,
                    agg.revenue,
                    ROUND(agg.units_sold * mi.cost, 2) AS cogs,
                    ROUND(agg.revenue - agg.units_sold * mi.cost, 2) AS gross_margin,
                    CASE WHEN agg.revenue > 0
                         THEN ROUND((agg.revenue - agg.units_sold * mi.cost) / agg.revenue * 100, 2)
                         ELSE 0 END AS gross_margin_pct
                FROM menu_items mi
                JOIN (
                    SELECT
                        CAST(JSON_UNQUOTE(JSON_EXTRACT(i.item, '$.id')) AS UNSIGNED) AS item_id,
                        SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(i.item, '$.quantity')) AS UNSIGNED)) AS units_sold,
                        SUM(
                            CAST(JSON_UNQUOTE(JSON_EXTRACT(i.item, '$.price')) AS DECIMAL(10,2))
                            * CAST(JSON_UNQUOTE(JSON_EXTRACT(i.item, '$.quantity')) AS UNSIGNED)
                        ) AS revenue
                    FROM orders o
                    JOIN JSON_TABLE(o.items, '$[*]' COLUMNS (item JSON PATH '$')) i
                    WHERE o.created_at >= :fromDt
                      AND o.created_at <  :toDt
                      AND o.status NOT IN ('отказ')
                    GROUP BY item_id
                ) agg ON agg.item_id = mi.id
                ORDER BY gross_margin DESC
                LIMIT {$limit}
            ");
            $stmt->execute([':fromDt' => $fromDt, ':toDt' => $toDt]);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('getDishMargins error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Cohort retention matrix: rows = month of first order, columns = months-since-cohort.
     * Each cell = distinct active users from that cohort in that month. Capped at
     * `$cohortsLimit` rows × 13 columns (0..12 months).
     */
    public function getCustomerCohorts(int $cohortsLimit = 12): array
    {
        $cohortsLimit = max(1, min(24, $cohortsLimit));
        try {
            $stmt = $this->connection->prepare("
                WITH first_orders AS (
                    SELECT user_id, DATE_FORMAT(MIN(created_at), '%Y-%m') AS cohort
                    FROM orders
                    WHERE user_id IS NOT NULL AND status NOT IN ('отказ')
                    GROUP BY user_id
                ),
                activity AS (
                    SELECT fo.cohort,
                           PERIOD_DIFF(DATE_FORMAT(o.created_at, '%Y%m'),
                                       DATE_FORMAT(STR_TO_DATE(CONCAT(fo.cohort, '-01'), '%Y-%m-%d'), '%Y%m')) AS months_out,
                           o.user_id
                    FROM orders o
                    JOIN first_orders fo ON fo.user_id = o.user_id
                    WHERE o.status NOT IN ('отказ')
                )
                SELECT cohort, months_out, COUNT(DISTINCT user_id) AS active_users
                FROM activity
                GROUP BY cohort, months_out
                ORDER BY cohort DESC, months_out ASC
            ");
            $stmt->execute();
            $raw = $stmt->fetchAll();

            $byCohort = [];
            foreach ($raw as $r) {
                $c = (string)$r['cohort'];
                $byCohort[$c] = $byCohort[$c] ?? ['cohort' => $c, 'size' => 0, 'retention' => []];
                $mo = max(0, min(12, (int)$r['months_out']));
                $byCohort[$c]['retention'][$mo] = (int)$r['active_users'];
                if ($mo === 0) {
                    $byCohort[$c]['size'] = (int)$r['active_users'];
                }
            }

            $normalized = [];
            foreach ($byCohort as $row) {
                $retention = [];
                for ($mo = 0; $mo <= 12; $mo++) {
                    $retention[] = (int)($row['retention'][$mo] ?? 0);
                }
                $row['retention'] = $retention;
                $normalized[] = $row;
            }
            return array_slice($normalized, 0, $cohortsLimit);
        } catch (PDOException $e) {
            error_log('getCustomerCohorts error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Day-of-week × hour heatmap. Values = order count. DOW uses WEEKDAY()
     * convention (0=Monday, 6=Sunday).
     */
    public function getHourlyHeatmap(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        try {
            $stmt = $this->connection->prepare("
                SELECT WEEKDAY(created_at) AS dow,
                       HOUR(created_at) AS hour,
                       COUNT(*) AS orders
                FROM orders
                WHERE created_at >= (NOW() - INTERVAL :days DAY)
                  AND status NOT IN ('отказ')
                GROUP BY WEEKDAY(created_at), HOUR(created_at)
            ");
            $stmt->execute([':days' => $days]);
            $rows = $stmt->fetchAll();

            $grid = array_fill(0, 7, array_fill(0, 24, 0));
            $max = 0;
            foreach ($rows as $r) {
                $d = (int)$r['dow']; $h = (int)$r['hour']; $c = (int)$r['orders'];
                if ($d >= 0 && $d <= 6 && $h >= 0 && $h <= 23) {
                    $grid[$d][$h] = $c;
                    if ($c > $max) $max = $c;
                }
            }
            return ['grid' => $grid, 'max' => $max, 'days' => $days];
        } catch (PDOException $e) {
            error_log('getHourlyHeatmap error: ' . $e->getMessage());
            return ['grid' => array_fill(0, 7, array_fill(0, 24, 0)), 'max' => 0, 'days' => $days];
        }
    }

    /**
     * EWMA forecast (alpha=0.5) of next-week revenue from the last `$weeksBack`
     * completed weeks. Directional signal, not a serious forecast.
     */
    public function forecastNextWeekRevenue(int $weeksBack = 8): array
    {
        $weeksBack = max(4, min(24, $weeksBack));
        try {
            $stmt = $this->connection->prepare("
                SELECT YEARWEEK(created_at, 3) AS yw, SUM(total) AS revenue
                FROM orders
                WHERE created_at >= (NOW() - INTERVAL :weeks WEEK)
                  AND status NOT IN ('отказ')
                GROUP BY YEARWEEK(created_at, 3)
                ORDER BY yw ASC
            ");
            $stmt->execute([':weeks' => $weeksBack]);
            $rows = $stmt->fetchAll();

            $alpha = 0.5;
            $ewma = 0.0;
            $series = [];
            foreach ($rows as $r) {
                $rev = (float)$r['revenue'];
                $ewma = count($series) === 0 ? $rev : ($alpha * $rev + (1 - $alpha) * $ewma);
                $series[] = ['week' => (string)$r['yw'], 'revenue' => $rev];
            }

            return ['weekly' => $series, 'forecast' => round($ewma, 2), 'alpha' => $alpha];
        } catch (PDOException $e) {
            error_log('forecastNextWeekRevenue error: ' . $e->getMessage());
            return ['weekly' => [], 'forecast' => 0.0, 'alpha' => 0.5];
        }
    }

    /**
     * Marketing automation (Phase 8.1). Owner builds a tiny segment + email,
     * hits Send. Materialization happens at queue time; the actual send loop
     * lives in scripts/marketing-worker.php so the admin call stays fast.
     */
    public function listMarketingCampaigns(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        try {
            $stmt = $this->connection->prepare("
                SELECT id, name, channel, subject, status, scheduled_at, started_at, finished_at, created_at,
                       (SELECT COUNT(*) FROM marketing_sends ms WHERE ms.campaign_id = mc.id) AS sends_count,
                       (SELECT COUNT(*) FROM marketing_sends ms WHERE ms.campaign_id = mc.id AND ms.status = 'sent') AS sent_count
                FROM marketing_campaigns mc
                ORDER BY id DESC
                LIMIT {$limit}
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('listMarketingCampaigns error: ' . $e->getMessage());
            return [];
        }
    }

    public function getMarketingCampaign(int $id): ?array
    {
        try {
            $stmt = $this->prepareCached("
                SELECT id, name, channel, subject, body_text, body_html, segment_json, status,
                       scheduled_at, started_at, finished_at, created_by, created_at, updated_at
                FROM marketing_campaigns WHERE id = :id LIMIT 1
            ");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('getMarketingCampaign error: ' . $e->getMessage());
            return null;
        }
    }

    public function saveMarketingCampaign(?int $id, string $name, string $channel, ?string $subject, string $bodyText, ?string $bodyHtml, array $segment, ?string $scheduledAt, ?int $createdBy): ?int
    {
        $name = trim($name);
        $channel = trim($channel);
        $subject = $subject !== null ? trim($subject) : null;
        if ($subject === '') $subject = null;
        $bodyText = trim($bodyText);
        $bodyHtml = $bodyHtml !== null ? trim($bodyHtml) : null;
        if ($bodyHtml === '') $bodyHtml = null;
        if ($name === '' || $bodyText === '') return null;
        if (!in_array($channel, ['email', 'push', 'telegram'], true)) return null;
        $segmentJson = json_encode($segment, JSON_UNESCAPED_UNICODE);

        try {
            if ($id !== null && $id > 0) {
                $stmt = $this->prepareCached("
                    UPDATE marketing_campaigns
                    SET name = :name, channel = :ch, subject = :subj,
                        body_text = :bt, body_html = :bh, segment_json = :seg,
                        scheduled_at = :sched
                    WHERE id = :id AND status IN ('draft', 'queued')
                ");
                $stmt->execute([
                    ':name' => $name, ':ch' => $channel, ':subj' => $subject,
                    ':bt' => $bodyText, ':bh' => $bodyHtml, ':seg' => $segmentJson,
                    ':sched' => $scheduledAt !== '' ? $scheduledAt : null,
                    ':id' => $id,
                ]);
                return $id;
            }
            $stmt = $this->prepareCached("
                INSERT INTO marketing_campaigns
                    (name, channel, subject, body_text, body_html, segment_json, status, scheduled_at, created_by)
                VALUES
                    (:name, :ch, :subj, :bt, :bh, :seg, 'draft', :sched, :by)
            ");
            $stmt->execute([
                ':name' => $name, ':ch' => $channel, ':subj' => $subject,
                ':bt' => $bodyText, ':bh' => $bodyHtml, ':seg' => $segmentJson,
                ':sched' => $scheduledAt !== '' ? $scheduledAt : null,
                ':by' => $createdBy,
            ]);
            return (int)$this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log('saveMarketingCampaign error: ' . $e->getMessage());
            return null;
        }
    }

    public function updateMarketingCampaignStatus(int $id, string $newStatus): bool
    {
        if (!in_array($newStatus, ['draft', 'queued', 'sending', 'sent', 'failed', 'cancelled'], true)) return false;
        $sets = ['status = :status'];
        if ($newStatus === 'sending') $sets[] = 'started_at = COALESCE(started_at, NOW())';
        if (in_array($newStatus, ['sent', 'failed', 'cancelled'], true)) {
            $sets[] = 'finished_at = COALESCE(finished_at, NOW())';
        }
        try {
            $stmt = $this->connection->prepare("UPDATE marketing_campaigns SET " . implode(', ', $sets) . " WHERE id = :id");
            return $stmt->execute([':status' => $newStatus, ':id' => $id]);
        } catch (PDOException $e) {
            error_log('updateMarketingCampaignStatus error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Resolve segment_json into a list of user_id targets.
     * Supported types: all / min_orders / loyalty_tier / birthday_today / manual.
     */
    public function resolveMarketingSegment(array $segment): array
    {
        $type = (string)($segment['type'] ?? 'all');
        try {
            switch ($type) {
                case 'manual':
                    $ids = array_values(array_filter(array_map('intval', $segment['user_ids'] ?? []), static fn($v) => $v > 0));
                    return array_values(array_unique($ids));
                case 'min_orders':
                    $threshold = max(1, (int)($segment['threshold'] ?? 1));
                    $stmt = $this->connection->prepare("
                        SELECT u.id FROM users u
                        JOIN (
                            SELECT user_id, COUNT(*) AS cnt
                            FROM orders WHERE status NOT IN ('отказ') AND user_id IS NOT NULL
                            GROUP BY user_id
                        ) o ON o.user_id = u.id AND o.cnt >= :t
                        WHERE u.is_active = 1 AND u.email IS NOT NULL
                    ");
                    $stmt->execute([':t' => $threshold]);
                    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
                case 'loyalty_tier':
                    $tierId = (int)($segment['tier_id'] ?? 0);
                    if ($tierId <= 0) return [];
                    $stmt = $this->connection->prepare("
                        SELECT u.id FROM users u
                        JOIN loyalty_accounts la ON la.user_id = u.id
                        WHERE la.tier_id = :tid AND u.is_active = 1 AND u.email IS NOT NULL
                    ");
                    $stmt->execute([':tid' => $tierId]);
                    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
                case 'birthday_today':
                    try {
                        $stmt = $this->connection->prepare("
                            SELECT id FROM users
                            WHERE is_active = 1 AND email IS NOT NULL
                              AND DATE_FORMAT(birth_date, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')
                        ");
                        $stmt->execute();
                        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
                    } catch (PDOException $e) {
                        return [];
                    }
                case 'all':
                default:
                    $stmt = $this->connection->prepare("SELECT id FROM users WHERE is_active = 1 AND email IS NOT NULL");
                    $stmt->execute();
                    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
            }
        } catch (PDOException $e) {
            error_log('resolveMarketingSegment error: ' . $e->getMessage());
            return [];
        }
    }

    public function queueMarketingCampaign(int $campaignId): int
    {
        $campaign = $this->getMarketingCampaign($campaignId);
        if (!$campaign || !in_array($campaign['status'], ['draft', 'queued'], true)) return 0;
        $segment = json_decode((string)$campaign['segment_json'], true) ?: ['type' => 'all'];
        $userIds = $this->resolveMarketingSegment($segment);
        if (empty($userIds)) {
            $this->updateMarketingCampaignStatus($campaignId, 'queued');
            return 0;
        }
        try {
            $stmt = $this->connection->prepare("
                INSERT IGNORE INTO marketing_sends (campaign_id, user_id, channel, status, queued_at)
                VALUES (:cid, :uid, :ch, 'queued', NOW())
            ");
            $count = 0;
            foreach ($userIds as $uid) {
                $ok = $stmt->execute([':cid' => $campaignId, ':uid' => $uid, ':ch' => $campaign['channel']]);
                if ($ok && $stmt->rowCount() > 0) $count++;
            }
            $this->updateMarketingCampaignStatus($campaignId, 'queued');
            return $count;
        } catch (PDOException $e) {
            error_log('queueMarketingCampaign error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Atomic claim — flips a batch of 'queued' sends to 'sending' inside one
     * transaction (SELECT ... FOR UPDATE) so two workers can't pick the same
     * row twice. Worker then calls markMarketingSendDelivered or
     * markMarketingSendFailed for each. If a worker crashes between claim
     * and outcome, the row is left in 'sending' and a follow-up reaper
     * (markStuckSendingMarketingFailed) recovers it.
     */
    public function claimDueMarketingSends(int $batch = 50): array
    {
        $batch = max(1, min(500, $batch));
        try {
            $this->connection->beginTransaction();
            $sel = $this->connection->prepare("
                SELECT id FROM marketing_sends
                WHERE status = 'queued'
                ORDER BY id ASC
                LIMIT {$batch}
                FOR UPDATE
            ");
            $sel->execute();
            $ids = array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN, 0));
            if (empty($ids)) {
                $this->connection->commit();
                return [];
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $upd = $this->connection->prepare("UPDATE marketing_sends SET status = 'sending' WHERE id IN ({$ph})");
            $upd->execute($ids);

            $load = $this->connection->prepare("
                SELECT ms.id, ms.campaign_id, ms.user_id, ms.channel,
                       u.email, u.name AS user_name,
                       mc.subject, mc.body_text, mc.body_html
                FROM marketing_sends ms
                JOIN users u ON u.id = ms.user_id
                JOIN marketing_campaigns mc ON mc.id = ms.campaign_id
                WHERE ms.id IN ({$ph})
                ORDER BY ms.id ASC
            ");
            $load->execute($ids);
            $rows = $load->fetchAll(PDO::FETCH_ASSOC);

            $this->connection->commit();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            try { $this->connection->rollBack(); } catch (Throwable $ignored) {}
            error_log('claimDueMarketingSends error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Recovery for rows stuck in 'sending' for more than $minutes (worker
     * crash between claim and outcome). Flips them back to 'queued' so the
     * next worker run picks them up.
     */
    public function reapStuckMarketingSends(int $minutes = 10): int
    {
        $minutes = max(1, min(1440, $minutes));
        try {
            $stmt = $this->connection->prepare("
                UPDATE marketing_sends
                SET status = 'queued'
                WHERE status = 'sending'
                  AND queued_at < (NOW() - INTERVAL :m MINUTE)
            ");
            $stmt->execute([':m' => $minutes]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('reapStuckMarketingSends error: ' . $e->getMessage());
            return 0;
        }
    }

    /** @deprecated use claimDueMarketingSends() — kept for backward compat. */
    public function getNextQueuedMarketingSends(int $batch = 50): array
    {
        return $this->claimDueMarketingSends($batch);
    }

    public function markMarketingSendDelivered(int $sendId): bool
    {
        try {
            $stmt = $this->prepareCached("UPDATE marketing_sends SET status = 'sent', sent_at = NOW() WHERE id = :id");
            return $stmt->execute([':id' => $sendId]);
        } catch (PDOException $e) {
            error_log('markMarketingSendDelivered error: ' . $e->getMessage());
            return false;
        }
    }

    public function markMarketingSendFailed(int $sendId, string $excerpt): bool
    {
        try {
            $stmt = $this->prepareCached("UPDATE marketing_sends SET status = 'failed', error_excerpt = :e WHERE id = :id");
            return $stmt->execute([':e' => mb_substr($excerpt, 0, 500), ':id' => $sendId]);
        } catch (PDOException $e) {
            error_log('markMarketingSendFailed error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Two-factor auth (Phase 9.3). One row per user that has set up TOTP.
     * `enabled = 0` while the user is in the setup wizard but hasn't yet
     * confirmed by entering a valid code; flips to 1 on first successful
     * verify. `secret` is a base32 string (Totp::generateSecret() result).
     */
    public function getUser2FA(int $userId): ?array
    {
        if ($userId <= 0) return null;
        try {
            $stmt = $this->prepareCached("
                SELECT user_id, secret, enabled, backup_codes_json, last_used_at, created_at, updated_at
                FROM user_2fa WHERE user_id = :uid LIMIT 1
            ");
            $stmt->execute([':uid' => $userId]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('getUser2FA error: ' . $e->getMessage());
            return null;
        }
    }

    public function saveUser2FA(int $userId, string $secret, bool $enabled, ?array $backupCodesHashed = null): bool
    {
        if ($userId <= 0 || $secret === '') return false;
        try {
            $existing = $this->getUser2FA($userId);
            $codesJson = $backupCodesHashed !== null ? json_encode($backupCodesHashed, JSON_UNESCAPED_UNICODE) : null;
            if ($existing) {
                $sql = "UPDATE user_2fa SET secret = :s, enabled = :en";
                $params = [':s' => $secret, ':en' => $enabled ? 1 : 0, ':uid' => $userId];
                if ($backupCodesHashed !== null) {
                    $sql .= ", backup_codes_json = :codes";
                    $params[':codes'] = $codesJson;
                }
                $sql .= " WHERE user_id = :uid";
                $stmt = $this->connection->prepare($sql);
                return $stmt->execute($params);
            }
            $stmt = $this->prepareCached("
                INSERT INTO user_2fa (user_id, secret, enabled, backup_codes_json)
                VALUES (:uid, :s, :en, :codes)
            ");
            return $stmt->execute([
                ':uid' => $userId, ':s' => $secret,
                ':en' => $enabled ? 1 : 0, ':codes' => $codesJson,
            ]);
        } catch (PDOException $e) {
            error_log('saveUser2FA error: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteUser2FA(int $userId): bool
    {
        if ($userId <= 0) return false;
        try {
            $stmt = $this->prepareCached("DELETE FROM user_2fa WHERE user_id = :uid");
            return $stmt->execute([':uid' => $userId]);
        } catch (PDOException $e) {
            error_log('deleteUser2FA error: ' . $e->getMessage());
            return false;
        }
    }

    public function markUser2FAUsed(int $userId, ?array $newBackupCodesHashed = null): bool
    {
        if ($userId <= 0) return false;
        try {
            $sql = "UPDATE user_2fa SET last_used_at = NOW()";
            $params = [':uid' => $userId];
            if ($newBackupCodesHashed !== null) {
                $sql .= ", backup_codes_json = :codes";
                $params[':codes'] = json_encode($newBackupCodesHashed, JSON_UNESCAPED_UNICODE);
            }
            $sql .= " WHERE user_id = :uid";
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log('markUser2FAUsed error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Staff management (Phase 7.4). Shifts + clock-in/out + tip splits.
     * All per-user references use ON DELETE SET NULL — offboarding does not
     * erase pay history.
     */
    /**
     * Staff Management v2 (Phase 7.4) — shift-swap requests.
     * Workflow:
     *   1. employee createShiftSwapRequest($shiftId, $note) → status 'open'
     *   2. another employee offerToTakeShift($swapId) → 'volunteer_offered'
     *   3. manager approveShiftSwap($swapId, $managerId) reassigns the
     *      shift to the volunteer and stamps decided_at + decided_by.
     *      denyShiftSwap rejects without reassigning.
     *   4. requester can cancelShiftSwap before any decision.
     */
    public function createShiftSwapRequest(int $shiftId, int $requesterId, ?string $note): ?int
    {
        try {
            $stmt = $this->prepareCached("
                INSERT INTO shift_swap_requests (shift_id, requester_id, note)
                VALUES (:sid, :rid, :note)
            ");
            $stmt->execute([
                ':sid' => $shiftId, ':rid' => $requesterId,
                ':note' => $note !== null ? mb_substr($note, 0, 255) : null,
            ]);
            return (int)$this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log('createShiftSwapRequest error: ' . $e->getMessage());
            return null;
        }
    }

    public function listShiftSwapRequests(?string $status = null, ?int $userId = null): array
    {
        $clauses = ['1=1'];
        $params  = [];
        if ($status !== null) { $clauses[] = 'ssr.status = :s'; $params[':s'] = $status; }
        if ($userId !== null) {
            $clauses[] = '(ssr.requester_id = :u OR ssr.volunteer_id = :u)';
            $params[':u'] = $userId;
        }
        $where = implode(' AND ', $clauses);
        try {
            $stmt = $this->connection->prepare("
                SELECT ssr.id, ssr.shift_id, ssr.requester_id, ssr.volunteer_id,
                       ssr.status, ssr.note, ssr.requested_at, ssr.decided_at, ssr.decided_by,
                       s.starts_at, s.ends_at, s.role, s.location_id,
                       ur.name AS requester_name,
                       uv.name AS volunteer_name
                FROM shift_swap_requests ssr
                JOIN shifts s ON s.id = ssr.shift_id
                LEFT JOIN users ur ON ur.id = ssr.requester_id
                LEFT JOIN users uv ON uv.id = ssr.volunteer_id
                WHERE {$where}
                ORDER BY ssr.requested_at DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('listShiftSwapRequests error: ' . $e->getMessage());
            return [];
        }
    }

    public function offerToTakeShift(int $swapId, int $volunteerId): bool
    {
        try {
            $stmt = $this->prepareCached("
                UPDATE shift_swap_requests
                SET volunteer_id = :v, status = 'volunteer_offered'
                WHERE id = :id AND status = 'open' AND requester_id <> :v
            ");
            $stmt->execute([':v' => $volunteerId, ':id' => $swapId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('offerToTakeShift error: ' . $e->getMessage());
            return false;
        }
    }

    public function approveShiftSwap(int $swapId, int $managerId): bool
    {
        try {
            $this->connection->beginTransaction();

            $sel = $this->prepareCached(
                "SELECT shift_id, volunteer_id FROM shift_swap_requests
                 WHERE id = :id AND status = 'volunteer_offered'
                 FOR UPDATE"
            );
            $sel->execute([':id' => $swapId]);
            $swap = $sel->fetch();
            if (!$swap || empty($swap['volunteer_id'])) {
                $this->connection->rollBack();
                return false;
            }

            $up = $this->prepareCached(
                "UPDATE shifts SET user_id = :u WHERE id = :sid"
            );
            $up->execute([':u' => $swap['volunteer_id'], ':sid' => $swap['shift_id']]);

            $upS = $this->prepareCached(
                "UPDATE shift_swap_requests
                 SET status = 'approved', decided_at = NOW(), decided_by = :m
                 WHERE id = :id"
            );
            $upS->execute([':m' => $managerId, ':id' => $swapId]);

            $this->connection->commit();
            return true;
        } catch (PDOException $e) {
            try { $this->connection->rollBack(); } catch (Throwable $_) {}
            error_log('approveShiftSwap error: ' . $e->getMessage());
            return false;
        }
    }

    public function denyShiftSwap(int $swapId, int $managerId): bool
    {
        try {
            $stmt = $this->prepareCached("
                UPDATE shift_swap_requests
                SET status = 'denied', decided_at = NOW(), decided_by = :m
                WHERE id = :id AND status IN ('open', 'volunteer_offered')
            ");
            $stmt->execute([':m' => $managerId, ':id' => $swapId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('denyShiftSwap error: ' . $e->getMessage());
            return false;
        }
    }

    public function cancelShiftSwap(int $swapId, int $requesterId): bool
    {
        try {
            $stmt = $this->prepareCached("
                UPDATE shift_swap_requests
                SET status = 'cancelled', decided_at = NOW()
                WHERE id = :id AND requester_id = :r
                  AND status IN ('open', 'volunteer_offered')
            ");
            $stmt->execute([':id' => $swapId, ':r' => $requesterId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('cancelShiftSwap error: ' . $e->getMessage());
            return false;
        }
    }

    public function listShifts(?string $fromDt = null, ?string $toDt = null, ?int $userId = null): array
    {
        $clauses = ['1=1'];
        $params  = [];
        if ($fromDt !== null) { $clauses[] = 's.starts_at >= :from'; $params[':from'] = $fromDt; }
        if ($toDt   !== null) { $clauses[] = 's.starts_at <  :to';   $params[':to']   = $toDt;   }
        if ($userId !== null) { $clauses[] = 's.user_id = :uid';     $params[':uid']  = $userId; }
        $where = implode(' AND ', $clauses);
        try {
            $stmt = $this->connection->prepare("
                SELECT s.id, s.user_id, u.name AS user_name, s.role, s.location_id,
                       s.starts_at, s.ends_at, s.note, s.created_at
                FROM shifts s
                LEFT JOIN users u ON u.id = s.user_id
                WHERE {$where}
                ORDER BY s.starts_at ASC, s.id ASC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('listShifts error: ' . $e->getMessage());
            return [];
        }
    }

    public function saveShift(?int $id, ?int $userId, string $role, ?int $locationId, string $startsAt, string $endsAt, ?string $note): ?int
    {
        $role = trim($role);
        if ($role === '' || mb_strlen($role) > 32) return null;
        if (strtotime($endsAt) <= strtotime($startsAt)) return null;
        $note = $note !== null ? trim($note) : null;
        if ($note === '') $note = null;
        try {
            if ($id !== null && $id > 0) {
                $stmt = $this->prepareCached("
                    UPDATE shifts
                    SET user_id = :uid, role = :role, location_id = :loc,
                        starts_at = :start, ends_at = :end, note = :note
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':uid' => $userId, ':role' => $role, ':loc' => $locationId,
                    ':start' => $startsAt, ':end' => $endsAt, ':note' => $note,
                    ':id' => $id,
                ]);
                return $id;
            }
            $stmt = $this->prepareCached("
                INSERT INTO shifts (user_id, role, location_id, starts_at, ends_at, note)
                VALUES (:uid, :role, :loc, :start, :end, :note)
            ");
            $stmt->execute([
                ':uid' => $userId, ':role' => $role, ':loc' => $locationId,
                ':start' => $startsAt, ':end' => $endsAt, ':note' => $note,
            ]);
            return (int)$this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log('saveShift error: ' . $e->getMessage());
            return null;
        }
    }

    public function deleteShift(int $id): bool
    {
        try {
            $stmt = $this->prepareCached("DELETE FROM shifts WHERE id = :id");
            return $stmt->execute([':id' => $id]);
        } catch (PDOException $e) {
            error_log('deleteShift error: ' . $e->getMessage());
            return false;
        }
    }

    public function clockIn(int $userId, ?int $shiftId = null): ?int
    {
        try {
            $probe = $this->prepareCached("
                SELECT id FROM time_entries WHERE user_id = :uid AND clocked_out_at IS NULL LIMIT 1
            ");
            $probe->execute([':uid' => $userId]);
            $openId = $probe->fetchColumn();
            if ($openId !== false) return (int)$openId;

            $stmt = $this->prepareCached("
                INSERT INTO time_entries (user_id, shift_id, clocked_in_at)
                VALUES (:uid, :sid, NOW())
            ");
            $stmt->execute([':uid' => $userId, ':sid' => $shiftId]);
            return (int)$this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log('clockIn error: ' . $e->getMessage());
            return null;
        }
    }

    public function clockOut(int $userId, ?string $note = null): bool
    {
        try {
            $stmt = $this->prepareCached("
                UPDATE time_entries
                SET clocked_out_at = NOW(),
                    minutes = TIMESTAMPDIFF(MINUTE, clocked_in_at, NOW()),
                    note = COALESCE(:note, note)
                WHERE user_id = :uid AND clocked_out_at IS NULL
                ORDER BY clocked_in_at DESC
                LIMIT 1
            ");
            $stmt->execute([':uid' => $userId, ':note' => $note]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('clockOut error: ' . $e->getMessage());
            return false;
        }
    }

    public function getOpenTimeEntry(int $userId): ?array
    {
        try {
            $stmt = $this->prepareCached("
                SELECT id, user_id, shift_id, clocked_in_at
                FROM time_entries WHERE user_id = :uid AND clocked_out_at IS NULL LIMIT 1
            ");
            $stmt->execute([':uid' => $userId]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('getOpenTimeEntry error: ' . $e->getMessage());
            return null;
        }
    }

    public function getTimeWorkedByUser(string $fromDt, string $toDt): array
    {
        try {
            $stmt = $this->connection->prepare("
                SELECT user_id,
                       SUM(
                           COALESCE(
                               minutes,
                               TIMESTAMPDIFF(MINUTE, clocked_in_at, COALESCE(clocked_out_at, NOW()))
                           )
                       ) AS minutes_worked
                FROM time_entries
                WHERE clocked_in_at >= :from AND clocked_in_at < :to
                GROUP BY user_id
            ");
            $stmt->execute([':from' => $fromDt, ':to' => $toDt]);
            $rows = $stmt->fetchAll();
            $result = [];
            foreach ($rows as $r) {
                $result[(int)$r['user_id']] = (int)$r['minutes_worked'];
            }
            return $result;
        } catch (PDOException $e) {
            error_log('getTimeWorkedByUser error: ' . $e->getMessage());
            return [];
        }
    }

    public function getTipsPoolForPeriod(string $fromDt, string $toDt): float
    {
        try {
            $stmt = $this->connection->prepare("
                SELECT COALESCE(SUM(tips), 0) FROM orders
                WHERE created_at >= :from AND created_at < :to
                  AND payment_status = 'paid'
            ");
            $stmt->execute([':from' => $fromDt, ':to' => $toDt]);
            return (float)($stmt->fetchColumn() ?: 0);
        } catch (PDOException $e) {
            error_log('getTipsPoolForPeriod error: ' . $e->getMessage());
            return 0.0;
        }
    }

    public function saveTipSplit(string $fromDt, string $toDt, float $pool, array $allocation, ?int $createdBy = null): ?int
    {
        if (strtotime($toDt) <= strtotime($fromDt) || $pool < 0) return null;
        try {
            $stmt = $this->prepareCached("
                INSERT INTO tip_splits (period_from, period_to, tips_pool, allocation_json, created_by)
                VALUES (:from, :to, :pool, :alloc, :by)
            ");
            $stmt->execute([
                ':from'  => $fromDt,
                ':to'    => $toDt,
                ':pool'  => $pool,
                ':alloc' => json_encode($allocation, JSON_UNESCAPED_UNICODE),
                ':by'    => $createdBy,
            ]);
            return (int)$this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log('saveTipSplit error: ' . $e->getMessage());
            return null;
        }
    }

    public function listTipSplits(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        try {
            $stmt = $this->connection->prepare("
                SELECT id, period_from, period_to, tips_pool, allocation_json, created_by, created_at
                FROM tip_splits ORDER BY id DESC LIMIT {$limit}
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('listTipSplits error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Group ordering (Phase 8.3). Shared tab at a physical table — a host
     * scans a QR, gets a group code, other guests join via /group/<code>
     * and add items tied to their seat label. Items sit in a staging table
     * until the host "submits", at which point they become real orders.
     */
    public function createGroupOrder(?int $hostUserId, ?string $tableLabel, ?int $locationId): ?array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = strtolower(rtrim(strtr(base64_encode(random_bytes(6)), '+/', '-_'), '='));
            $code = substr($code, 0, 8);
            try {
                $stmt = $this->prepareCached("
                    INSERT INTO group_orders (code, host_user_id, table_label, location_id, status)
                    VALUES (:code, :uid, :tbl, :loc, 'open')
                ");
                $stmt->execute([
                    ':code' => $code,
                    ':uid'  => $hostUserId,
                    ':tbl'  => $tableLabel !== null && $tableLabel !== '' ? $tableLabel : null,
                    ':loc'  => $locationId,
                ]);
                return ['id' => (int)$this->connection->lastInsertId(), 'code' => $code];
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'uniq_group_orders_code') !== false) continue;
                error_log('createGroupOrder error: ' . $e->getMessage());
                return null;
            }
        }
        return null;
    }

    public function getGroupOrderByCode(string $code): ?array
    {
        $code = trim(strtolower($code));
        if ($code === '' || !preg_match('/^[a-z0-9_-]{3,16}$/', $code)) return null;
        try {
            $stmt = $this->prepareCached("
                SELECT id, code, host_user_id, table_label, location_id, status,
                       submitted_at, created_at, updated_at
                FROM group_orders WHERE code = :code LIMIT 1
            ");
            $stmt->execute([':code' => $code]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('getGroupOrderByCode error: ' . $e->getMessage());
            return null;
        }
    }

    public function addGroupOrderItem(int $groupOrderId, string $seatLabel, int $menuItemId, int $qty, ?string $note, ?int $addedBy): ?int
    {
        $seatLabel = trim($seatLabel);
        $note = $note !== null ? trim($note) : null;
        if ($note === '') $note = null;
        if ($seatLabel === '' || mb_strlen($seatLabel) > 64) return null;
        if ($menuItemId <= 0 || $qty < 1 || $qty > 99) return null;

        $lookup = $this->prepareCached("SELECT name, price FROM menu_items WHERE id = :id AND archived_at IS NULL LIMIT 1");
        $lookup->execute([':id' => $menuItemId]);
        $mi = $lookup->fetch();
        if (!$mi) return null;

        try {
            $stmt = $this->prepareCached("
                INSERT INTO group_order_items (group_order_id, seat_label, menu_item_id, item_name, quantity, unit_price, note, added_by)
                VALUES (:gid, :seat, :mid, :name, :qty, :price, :note, :uid)
            ");
            $stmt->execute([
                ':gid'   => $groupOrderId,
                ':seat'  => $seatLabel,
                ':mid'   => $menuItemId,
                ':name'  => (string)$mi['name'],
                ':qty'   => $qty,
                ':price' => (float)$mi['price'],
                ':note'  => $note,
                ':uid'   => $addedBy,
            ]);
            return (int)$this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log('addGroupOrderItem error: ' . $e->getMessage());
            return null;
        }
    }

    public function removeGroupOrderItem(int $itemRowId, int $groupOrderId): bool
    {
        try {
            $stmt = $this->prepareCached("DELETE FROM group_order_items WHERE id = :id AND group_order_id = :gid");
            return $stmt->execute([':id' => $itemRowId, ':gid' => $groupOrderId]);
        } catch (PDOException $e) {
            error_log('removeGroupOrderItem error: ' . $e->getMessage());
            return false;
        }
    }

    public function getGroupOrderItems(int $groupOrderId): array
    {
        try {
            $stmt = $this->prepareCached("
                SELECT id, seat_label, menu_item_id, item_name, quantity, unit_price, note, added_by, created_at
                FROM group_order_items
                WHERE group_order_id = :gid
                ORDER BY seat_label ASC, created_at ASC
            ");
            $stmt->execute([':gid' => $groupOrderId]);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('getGroupOrderItems error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Group split-bill payments (Phase 7.5).
     *
     * Each payer creates one payment intent for their share of a group_order.
     * The group transitions to 'paid' when SUM(intents.amount WHERE status='paid')
     * >= SUM(group_order_items.unit_price * quantity).
     */
    public function getGroupOrderTotal(int $groupOrderId): float
    {
        try {
            $stmt = $this->prepareCached("
                SELECT COALESCE(SUM(unit_price * quantity), 0) AS total
                FROM group_order_items
                WHERE group_order_id = :gid
            ");
            $stmt->execute([':gid' => $groupOrderId]);
            return (float)($stmt->fetchColumn() ?: 0);
        } catch (PDOException $e) {
            error_log('getGroupOrderTotal error: ' . $e->getMessage());
            return 0.0;
        }
    }

    public function getGroupSeatTotal(int $groupOrderId, string $seatLabel): float
    {
        try {
            $stmt = $this->prepareCached("
                SELECT COALESCE(SUM(unit_price * quantity), 0) AS total
                FROM group_order_items
                WHERE group_order_id = :gid AND seat_label = :seat
            ");
            $stmt->execute([':gid' => $groupOrderId, ':seat' => $seatLabel]);
            return (float)($stmt->fetchColumn() ?: 0);
        } catch (PDOException $e) {
            error_log('getGroupSeatTotal error: ' . $e->getMessage());
            return 0.0;
        }
    }

    public function listGroupPaymentIntents(int $groupOrderId): array
    {
        try {
            $stmt = $this->prepareCached("
                SELECT id, payer_label, seat_label, amount, payment_method,
                       yk_payment_id, status, paid_at, created_at
                FROM group_payment_intents
                WHERE group_order_id = :gid
                ORDER BY created_at ASC
            ");
            $stmt->execute([':gid' => $groupOrderId]);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('listGroupPaymentIntents error: ' . $e->getMessage());
            return [];
        }
    }

    public function createGroupPaymentIntent(
        int $groupOrderId,
        string $payerLabel,
        ?string $seatLabel,
        float $amount,
        string $paymentMethod = 'card'
    ): ?int {
        if ($amount <= 0) return null;
        try {
            $stmt = $this->prepareCached("
                INSERT INTO group_payment_intents
                    (group_order_id, payer_label, seat_label, amount, payment_method)
                VALUES (:gid, :pl, :sl, :a, :pm)
            ");
            $stmt->execute([
                ':gid' => $groupOrderId,
                ':pl'  => mb_substr($payerLabel, 0, 64),
                ':sl'  => $seatLabel !== null ? mb_substr($seatLabel, 0, 64) : null,
                ':a'   => round($amount, 2),
                ':pm'  => $paymentMethod,
            ]);
            return (int)$this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log('createGroupPaymentIntent error: ' . $e->getMessage());
            return null;
        }
    }

    public function attachYkPaymentIdToGroupIntent(int $intentId, string $ykPaymentId): bool
    {
        try {
            $stmt = $this->prepareCached(
                "UPDATE group_payment_intents SET yk_payment_id = :yk WHERE id = :id"
            );
            return $stmt->execute([':yk' => $ykPaymentId, ':id' => $intentId]);
        } catch (PDOException $e) {
            error_log('attachYkPaymentIdToGroupIntent error: ' . $e->getMessage());
            return false;
        }
    }

    public function markGroupPaymentIntentPaid(string $ykPaymentId): ?array
    {
        try {
            $stmt = $this->prepareCached(
                "UPDATE group_payment_intents
                 SET status = 'paid', paid_at = NOW()
                 WHERE yk_payment_id = :yk AND status = 'pending'"
            );
            $stmt->execute([':yk' => $ykPaymentId]);
            if ($stmt->rowCount() === 0) return null;

            $sel = $this->prepareCached(
                "SELECT id, group_order_id, payer_label, seat_label, amount
                 FROM group_payment_intents
                 WHERE yk_payment_id = :yk LIMIT 1"
            );
            $sel->execute([':yk' => $ykPaymentId]);
            $row = $sel->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('markGroupPaymentIntentPaid error: ' . $e->getMessage());
            return null;
        }
    }

    public function getGroupPaidTotal(int $groupOrderId): float
    {
        try {
            $stmt = $this->prepareCached("
                SELECT COALESCE(SUM(amount), 0)
                FROM group_payment_intents
                WHERE group_order_id = :gid AND status = 'paid'
            ");
            $stmt->execute([':gid' => $groupOrderId]);
            return (float)($stmt->fetchColumn() ?: 0);
        } catch (PDOException $e) {
            error_log('getGroupPaidTotal error: ' . $e->getMessage());
            return 0.0;
        }
    }

    public function setGroupOrderSplitMode(int $groupOrderId, string $mode): bool
    {
        if (!in_array($mode, ['host', 'per_seat', 'equal'], true)) return false;
        try {
            $stmt = $this->prepareCached(
                "UPDATE group_orders SET split_mode = :m WHERE id = :id"
            );
            return $stmt->execute([':m' => $mode, ':id' => $groupOrderId]);
        } catch (PDOException $e) {
            error_log('setGroupOrderSplitMode error: ' . $e->getMessage());
            return false;
        }
    }

    public function markGroupOrderPaid(int $groupOrderId): bool
    {
        try {
            $stmt = $this->prepareCached(
                "UPDATE group_orders SET status = 'paid' WHERE id = :id AND status IN ('open','submitted')"
            );
            return $stmt->execute([':id' => $groupOrderId]);
        } catch (PDOException $e) {
            error_log('markGroupOrderPaid error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Freeze the group into real orders. Modes:
     *   'single'   = one Order with all items.
     *   'per_seat' = one Order per seat_label (split bill).
     */
    public function submitGroupOrder(int $groupOrderId, string $mode = 'single'): ?array
    {
        if (!in_array($mode, ['single', 'per_seat'], true)) return null;
        try {
            $group = $this->prepareCached("SELECT * FROM group_orders WHERE id = :id LIMIT 1");
            $group->execute([':id' => $groupOrderId]);
            $groupRow = $group->fetch();
            if (!$groupRow || $groupRow['status'] !== 'open') return null;

            $items = $this->getGroupOrderItems($groupOrderId);
            if (empty($items)) return null;

            $tableLabel = (string)($groupRow['table_label'] ?? '');
            $hostUserId = $groupRow['host_user_id'] !== null ? (int)$groupRow['host_user_id'] : null;

            $this->connection->beginTransaction();
            $createdOrderIds = [];

            $createOrder = function (array $lines) use (&$createdOrderIds, $hostUserId, $tableLabel) {
                $itemsForOrder = [];
                $total = 0.0;
                foreach ($lines as $row) {
                    $qty = (int)$row['quantity'];
                    $price = (float)$row['unit_price'];
                    $total += $qty * $price;
                    $itemsForOrder[] = [
                        'id'       => (int)$row['menu_item_id'],
                        'name'     => (string)$row['item_name'],
                        'price'    => $price,
                        'quantity' => $qty,
                    ];
                }
                $ins = $this->connection->prepare("
                    INSERT INTO orders (user_id, items, total, status, delivery_type, delivery_details, created_at)
                    VALUES (:uid, :items, :total, 'Приём', 'table', :tbl, NOW())
                ");
                $ins->execute([
                    ':uid'   => $hostUserId,
                    ':items' => json_encode($itemsForOrder, JSON_UNESCAPED_UNICODE),
                    ':total' => $total,
                    ':tbl'   => $tableLabel,
                ]);
                $createdOrderIds[] = (int)$this->connection->lastInsertId();
            };

            if ($mode === 'single') {
                $createOrder($items);
            } else {
                $bySeat = [];
                foreach ($items as $row) {
                    $bySeat[(string)$row['seat_label']][] = $row;
                }
                foreach ($bySeat as $lines) {
                    $createOrder($lines);
                }
            }

            $this->prepareCached("UPDATE group_orders SET status = 'submitted', submitted_at = NOW() WHERE id = :id")
                ->execute([':id' => $groupOrderId]);

            $this->connection->commit();
            return $createdOrderIds;
        } catch (PDOException $e) {
            try { $this->connection->rollBack(); } catch (Throwable $ignored) {}
            error_log('submitGroupOrder error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Waitlist entries (Phase 8.4). Guests who couldn't find an open slot in
     * Reservations land here; staff sees the queue and notifies guests as
     * tables free up.
     */
    public function createWaitlistEntry(
        ?int $userId,
        ?string $guestName,
        string $guestPhone,
        int $guestsCount,
        string $preferredDate,
        ?string $preferredTime,
        ?int $locationId,
        ?string $note
    ): ?int {
        $guestName  = $guestName !== null ? trim($guestName) : null;
        $guestPhone = trim($guestPhone);
        $note       = $note !== null ? trim($note) : null;
        if ($guestName === '') $guestName = null;
        if ($note === '')      $note = null;
        if ($guestPhone === '' || mb_strlen($guestPhone) > 32) return null;
        if ($guestsCount < 1 || $guestsCount > 50) return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $preferredDate)) return null;
        if ($preferredTime !== null && $preferredTime !== ''
            && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $preferredTime)) return null;
        if ($preferredTime === '') $preferredTime = null;

        try {
            $stmt = $this->prepareCached("
                INSERT INTO waitlist_entries
                    (user_id, guest_name, guest_phone, guests_count,
                     preferred_date, preferred_time, location_id, note, status, created_at)
                VALUES
                    (:uid, :name, :phone, :guests, :date, :time, :loc, :note, 'waiting', NOW())
            ");
            $stmt->execute([
                ':uid'    => $userId,
                ':name'   => $guestName,
                ':phone'  => $guestPhone,
                ':guests' => $guestsCount,
                ':date'   => $preferredDate,
                ':time'   => $preferredTime,
                ':loc'    => $locationId,
                ':note'   => $note,
            ]);
            return (int)$this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log('createWaitlistEntry error: ' . $e->getMessage());
            return null;
        }
    }

    public function getWaitlistEntry(int $id): ?array
    {
        try {
            $stmt = $this->prepareCached("
                SELECT id, user_id, guest_name, guest_phone, guests_count,
                       preferred_date, preferred_time, location_id, note, status,
                       notified_at, resolved_at, created_at, updated_at
                FROM waitlist_entries
                WHERE id = :id LIMIT 1
            ");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('getWaitlistEntry error: ' . $e->getMessage());
            return null;
        }
    }

    public function listActiveWaitlist(?string $date = null, ?int $locationId = null): array
    {
        $clauses = ["status IN ('waiting', 'notified')"];
        $params  = [];
        if ($date !== null && $date !== '') {
            $clauses[] = "preferred_date = :date";
            $params[':date'] = $date;
        }
        if ($locationId !== null) {
            $clauses[] = "location_id = :loc";
            $params[':loc'] = $locationId;
        }
        $where = implode(' AND ', $clauses);
        try {
            $stmt = $this->connection->prepare("
                SELECT id, user_id, guest_name, guest_phone, guests_count,
                       preferred_date, preferred_time, location_id, note, status,
                       notified_at, created_at
                FROM waitlist_entries
                WHERE {$where}
                ORDER BY preferred_date ASC, preferred_time ASC, created_at ASC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('listActiveWaitlist error: ' . $e->getMessage());
            return [];
        }
    }

    public function updateWaitlistStatus(int $id, string $newStatus): bool
    {
        $allowed = ['waiting', 'notified', 'seated', 'cancelled', 'expired'];
        if (!in_array($newStatus, $allowed, true)) return false;

        $sets = ['status = :status'];
        $params = [':status' => $newStatus, ':id' => $id];
        if ($newStatus === 'notified') {
            $sets[] = 'notified_at = COALESCE(notified_at, NOW())';
        }
        if (in_array($newStatus, ['seated', 'cancelled', 'expired'], true)) {
            $sets[] = 'resolved_at = COALESCE(resolved_at, NOW())';
        }
        $sql = "UPDATE waitlist_entries SET " . implode(', ', $sets) . " WHERE id = :id";
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('updateWaitlistStatus error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Multi-location (Phase 6.5). Physical restaurants within a single tenant DB.
     * Scope filtering on orders/menu/reservations stays opt-in — callers that
     * don't pass a location continue to see everything, preserving backward
     * compatibility with all pre-6.5 code paths.
     */
    public function listLocations(bool $activeOnly = false): array
    {
        try {
            $sql = "SELECT id, name, address, phone, timezone, active, sort_order, created_at, updated_at
                    FROM locations";
            if ($activeOnly) {
                $sql .= " WHERE active = 1";
            }
            $sql .= " ORDER BY sort_order ASC, id ASC";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('listLocations error: ' . $e->getMessage());
            return [];
        }
    }

    public function getLocationById(int $id): ?array
    {
        try {
            $stmt = $this->prepareCached("
                SELECT id, name, address, phone, timezone, active, sort_order, created_at, updated_at
                FROM locations WHERE id = :id LIMIT 1
            ");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('getLocationById error: ' . $e->getMessage());
            return null;
        }
    }

    public function saveLocation(?int $id, string $name, ?string $address, ?string $phone, string $timezone, bool $active, int $sortOrder): ?int
    {
        $name = trim($name);
        $timezone = trim($timezone);
        if ($name === '' || mb_strlen($name) > 255) return null;
        if ($timezone === '' || mb_strlen($timezone) > 64) return null;
        $address = $address !== null ? trim($address) : null;
        if ($address === '') $address = null;
        $phone = $phone !== null ? trim($phone) : null;
        if ($phone === '') $phone = null;
        try {
            if ($id !== null && $id > 0) {
                $stmt = $this->prepareCached("
                    UPDATE locations
                    SET name = :name, address = :addr, phone = :phone,
                        timezone = :tz, active = :active, sort_order = :so
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':name' => $name, ':addr' => $address, ':phone' => $phone,
                    ':tz' => $timezone, ':active' => $active ? 1 : 0, ':so' => $sortOrder,
                    ':id' => $id,
                ]);
                return $id;
            }
            $stmt = $this->prepareCached("
                INSERT INTO locations (name, address, phone, timezone, active, sort_order)
                VALUES (:name, :addr, :phone, :tz, :active, :so)
            ");
            $stmt->execute([
                ':name' => $name, ':addr' => $address, ':phone' => $phone,
                ':tz' => $timezone, ':active' => $active ? 1 : 0, ':so' => $sortOrder,
            ]);
            return (int)$this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log('saveLocation error: ' . $e->getMessage());
            return null;
        }
    }

    public function deleteLocation(int $id): bool
    {
        // Soft handling: if the location has orders/reservations/menu_items, we
        // flip active=0 instead of hard delete so existing history stays intact.
        try {
            $stmt = $this->prepareCached("UPDATE locations SET active = 0 WHERE id = :id");
            return $stmt->execute([':id' => $id]);
        } catch (PDOException $e) {
            error_log('deleteLocation error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Per-location revenue summary for a window. Used by the chain-wide report
     * in /owner.php. Orders with NULL location_id are grouped under
     * pseudo-id 0 ("Без локации") so pre-migration history stays visible.
     */
    public function getOrdersByLocationSummary(string $fromDt, string $toDt): array
    {
        try {
            $stmt = $this->connection->prepare("
                SELECT COALESCE(o.location_id, 0) AS location_id,
                       l.name AS location_name,
                       COUNT(*) AS orders_count,
                       SUM(o.total) AS revenue
                FROM orders o
                LEFT JOIN locations l ON l.id = o.location_id
                WHERE o.created_at >= :fromDt
                  AND o.created_at <  :toDt
                  AND o.status NOT IN ('отказ')
                GROUP BY COALESCE(o.location_id, 0), l.name
                ORDER BY revenue DESC
            ");
            $stmt->execute([':fromDt' => $fromDt, ':toDt' => $toDt]);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('getOrdersByLocationSummary error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Outgoing webhooks — see sql/webhooks-migration.sql and
     * docs/webhook-integration.md.
     */
    public function listWebhooks(?bool $activeOnly = null): array
    {
        try {
            $sql = "SELECT id, event_type, target_url, secret, active, description,
                           created_at, updated_at
                    FROM outgoing_webhooks";
            $params = [];
            if ($activeOnly !== null) {
                $sql .= " WHERE active = :active";
                $params[':active'] = $activeOnly ? 1 : 0;
            }
            $sql .= " ORDER BY event_type ASC, id ASC";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('listWebhooks error: ' . $e->getMessage());
            return [];
        }
    }

    public function getActiveWebhooksForEvent(string $eventType): array
    {
        try {
            $stmt = $this->prepareCached("
                SELECT id, event_type, target_url, secret
                FROM outgoing_webhooks
                WHERE event_type = :event AND active = 1
            ");
            $stmt->execute([':event' => $eventType]);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('getActiveWebhooksForEvent error: ' . $e->getMessage());
            return [];
        }
    }

    public function createWebhook(string $eventType, string $targetUrl, string $secret, ?string $description = null, bool $active = true): ?int
    {
        $eventType = trim($eventType);
        $targetUrl = trim($targetUrl);
        if ($eventType === '' || $targetUrl === '' || $secret === '') {
            return null;
        }
        try {
            $stmt = $this->prepareCached("
                INSERT INTO outgoing_webhooks (event_type, target_url, secret, description, active)
                VALUES (:event, :url, :secret, :description, :active)
            ");
            $stmt->execute([
                ':event'       => $eventType,
                ':url'         => $targetUrl,
                ':secret'      => $secret,
                ':description' => $description !== null && $description !== '' ? $description : null,
                ':active'      => $active ? 1 : 0,
            ]);
            return (int)$this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log('createWebhook error: ' . $e->getMessage());
            return null;
        }
    }

    public function updateWebhook(int $id, ?string $eventType = null, ?string $targetUrl = null, ?string $description = null, ?bool $active = null): bool
    {
        $sets = [];
        $params = [':id' => $id];
        if ($eventType !== null) { $sets[] = 'event_type = :event'; $params[':event'] = $eventType; }
        if ($targetUrl !== null) { $sets[] = 'target_url = :url';   $params[':url']   = $targetUrl; }
        if ($description !== null) {
            $sets[] = 'description = :description';
            $params[':description'] = $description !== '' ? $description : null;
        }
        if ($active !== null) { $sets[] = 'active = :active'; $params[':active'] = $active ? 1 : 0; }
        if (empty($sets)) {
            return true;
        }
        try {
            $stmt = $this->connection->prepare("UPDATE outgoing_webhooks SET " . implode(', ', $sets) . " WHERE id = :id");
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log('updateWebhook error: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteWebhook(int $id): bool
    {
        try {
            $stmt = $this->prepareCached("DELETE FROM outgoing_webhooks WHERE id = :id");
            return $stmt->execute([':id' => $id]);
        } catch (PDOException $e) {
            error_log('deleteWebhook error: ' . $e->getMessage());
            return false;
        }
    }

    public function enqueueWebhookDelivery(int $webhookId, string $eventType, array $payload): ?int
    {
        try {
            $stmt = $this->prepareCached("
                INSERT INTO webhook_deliveries (webhook_id, event_type, payload_json, status, created_at)
                VALUES (:webhook_id, :event, :payload, 'queued', NOW())
            ");
            $stmt->execute([
                ':webhook_id' => $webhookId,
                ':event'      => $eventType,
                ':payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
            return (int)$this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log('enqueueWebhookDelivery error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Pop-style fetch for the worker: returns up to $limit rows that are
     * eligible for sending (queued, or failed past their next_retry_at and
     * still under the attempt cap). Marks them 'sending' atomically so two
     * concurrent workers don't race on the same row.
     */
    public function claimDueWebhookDeliveries(int $limit = 20, int $maxAttempts = 5): array
    {
        $limit = max(1, min(100, $limit));
        try {
            $this->connection->beginTransaction();

            $sel = $this->connection->prepare("
                SELECT id FROM webhook_deliveries
                WHERE attempts < :max
                  AND (
                        status = 'queued'
                        OR (status = 'failed' AND (next_retry_at IS NULL OR next_retry_at <= NOW()))
                      )
                ORDER BY id ASC
                LIMIT {$limit}
                FOR UPDATE
            ");
            $sel->execute([':max' => $maxAttempts]);
            $ids = array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN, 0));

            if (empty($ids)) {
                $this->connection->commit();
                return [];
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $upd = $this->connection->prepare("
                UPDATE webhook_deliveries
                SET status = 'sending'
                WHERE id IN ({$placeholders})
            ");
            $upd->execute($ids);

            $loadSql = "
                SELECT d.id, d.webhook_id, d.event_type, d.payload_json, d.attempts,
                       w.target_url, w.secret
                FROM webhook_deliveries d
                JOIN outgoing_webhooks w ON w.id = d.webhook_id
                WHERE d.id IN ({$placeholders})
                ORDER BY d.id ASC
            ";
            $load = $this->connection->prepare($loadSql);
            $load->execute($ids);
            $rows = $load->fetchAll();

            $this->connection->commit();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            try { $this->connection->rollBack(); } catch (Throwable $ignored) {}
            error_log('claimDueWebhookDeliveries error: ' . $e->getMessage());
            return [];
        }
    }

    public function markWebhookDelivered(int $deliveryId, int $responseCode, string $responseExcerpt): bool
    {
        $excerpt = mb_substr($responseExcerpt, 0, 2000);
        try {
            $stmt = $this->prepareCached("
                UPDATE webhook_deliveries
                SET status = 'delivered',
                    response_code = :code,
                    response_excerpt = :excerpt,
                    attempts = attempts + 1,
                    delivered_at = NOW(),
                    next_retry_at = NULL
                WHERE id = :id
            ");
            return $stmt->execute([
                ':code'    => $responseCode,
                ':excerpt' => $excerpt,
                ':id'      => $deliveryId,
            ]);
        } catch (PDOException $e) {
            error_log('markWebhookDelivered error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark a delivery attempt as failed and schedule the next retry.
     * Backoff: 60s, 300s, 1800s, 7200s, then 'dropped'.
     */
    public function markWebhookFailed(int $deliveryId, ?int $responseCode, string $responseExcerpt, int $maxAttempts = 5): bool
    {
        $excerpt = mb_substr($responseExcerpt, 0, 2000);
        $backoff = [60, 300, 1800, 7200];
        try {
            $stmt = $this->prepareCached("SELECT attempts FROM webhook_deliveries WHERE id = :id");
            $stmt->execute([':id' => $deliveryId]);
            $currentAttempts = (int)$stmt->fetchColumn();
            $nextAttempt = $currentAttempts + 1;

            if ($nextAttempt >= $maxAttempts) {
                $upd = $this->prepareCached("
                    UPDATE webhook_deliveries
                    SET status = 'dropped',
                        response_code = :code,
                        response_excerpt = :excerpt,
                        attempts = :attempts,
                        next_retry_at = NULL
                    WHERE id = :id
                ");
                return $upd->execute([
                    ':code'     => $responseCode,
                    ':excerpt'  => $excerpt,
                    ':attempts' => $nextAttempt,
                    ':id'       => $deliveryId,
                ]);
            }

            $delaySec = $backoff[min($currentAttempts, count($backoff) - 1)];
            $upd = $this->prepareCached("
                UPDATE webhook_deliveries
                SET status = 'failed',
                    response_code = :code,
                    response_excerpt = :excerpt,
                    attempts = :attempts,
                    next_retry_at = DATE_ADD(NOW(), INTERVAL :delay SECOND)
                WHERE id = :id
            ");
            return $upd->execute([
                ':code'     => $responseCode,
                ':excerpt'  => $excerpt,
                ':attempts' => $nextAttempt,
                ':delay'    => $delaySec,
                ':id'       => $deliveryId,
            ]);
        } catch (PDOException $e) {
            error_log('markWebhookFailed error: ' . $e->getMessage());
            return false;
        }
    }

    public function getRecentWebhookDeliveries(int $webhookId, int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        try {
            $stmt = $this->connection->prepare("
                SELECT id, event_type, status, response_code, response_excerpt,
                       attempts, next_retry_at, created_at, delivered_at
                FROM webhook_deliveries
                WHERE webhook_id = :id
                ORDER BY id DESC
                LIMIT {$limit}
            ");
            $stmt->execute([':id' => $webhookId]);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('getRecentWebhookDeliveries error: ' . $e->getMessage());
            return [];
        }
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function getTenantContext(): array
    {
        return $this->tenantContext;
    }

    public function close()
    {
        $this->connection = null;
        self::$instance = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}

$db = Database::getInstance();
?>
