<?php
require_once __DIR__ . '/../config_copy.php';

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
            $persistentEnv = getenv('DB_PDO_PERSISTENT');
            // Default is ON for performance (saves ~5-15ms connection overhead per request).
            // Singleton pattern + PHP-FPM pm.max_requests ensure no state leaks.
            // Set DB_PDO_PERSISTENT=0 to disable if issues arise.
            $usePersistent = filter_var($persistentEnv !== false ? $persistentEnv : '1', FILTER_VALIDATE_BOOLEAN);

            $this->connection = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
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
            die("РћС€РёР±РєР° РїРѕРґРєР»СЋС‡РµРЅРёСЏ Рє Р±Р°Р·Рµ РґР°РЅРЅС‹С…. РџРѕР¶Р°Р»СѓР№СЃС‚Р°, РїРѕРїСЂРѕР±СѓР№С‚Рµ РїРѕР·Р¶Рµ.");
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
            $this->redisCache->invalidate('menu_items_*');
            $this->redisCache->invalidate('product_*');
            $this->redisCache->invalidate('categories_*');
        }
    }

    private function invalidateOrderCache($orderId = null)
    {
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
            $this->redisCache->set('orders_last_update_ts', time(), 86400);
        }
    }

    public function getOrdersLastUpdateTs(): int
    {
        if ($this->redisCache) {
            $cached = $this->redisCache->get('orders_last_update_ts');
            if (is_numeric($cached)) {
                return (int)$cached;
            }
        }

        $timestamp = (int)$this->scalar("SELECT UNIX_TIMESTAMP(MAX(updated_at)) FROM orders WHERE 1");
        if ($timestamp > 0 && $this->redisCache) {
            $this->redisCache->set('orders_last_update_ts', $timestamp, 86400);
        }
        return $timestamp;
    }

    private function invalidateUserCache($userId = null)
    {
    }

    public function getProductById($id)
    {
        $cacheKey = 'product_' . $id;

        if ($this->redisCache) {
            $cached = $this->redisCache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        try {
            $stmt = $this->prepareCached(
                "SELECT id, name, description, composition, price, image,
                 calories, protein, fat, carbs, category, available
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
                       o.delivery_type, o.delivery_details, o.created_at
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

    public function getMenuItems($category = null)
    {
        $cacheKey = 'menu_items_' . ($category ?: 'all');

        if ($this->redisCache) {
            $cached = $this->redisCache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        try {
            $sql = "SELECT id, name, description, composition, price, image, 
                   calories, protein, fat, carbs, category, available 
                   FROM menu_items WHERE available = 1";
            
            if ($category) {
                $sql .= " AND category = :category";
            }
            $sql .= " ORDER BY category, name";

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

    public function getUniqueCategories()
    {
        $cacheKey = 'categories_unique';

        if ($this->redisCache) {
            $cached = $this->redisCache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        try {
            $stmt = $this->prepareCached(
                "SELECT DISTINCT category FROM menu_items WHERE available = 1 ORDER BY category"
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
            $sql = "INSERT INTO menu_items (name, description, composition, price, image, 
                    calories, protein, fat, carbs, category, available) 
                    VALUES (:n, :d, :cmp, :p, :i, :cal, :prot, :fat, :carb, :c, :a)";
            $stmt = $this->prepareCached($sql);
            $result = $stmt->execute([
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
                throw new Exception("РќРµРІРµСЂРЅС‹Р№ С„РѕСЂРјР°С‚ CSV-С„Р°Р№Р»Р°");
            }

            $count = 0;
            while (($row = fgetcsv($csvHandle, 0, $delimiter, '"')) !== false) {
                if (count($row) < 11) {
                    error_log("РџСЂРѕРїСѓСЃРє СЃС‚СЂРѕРєРё: РЅРµРґРѕСЃС‚Р°С‚РѕС‡РЅРѕ РґР°РЅРЅС‹С…");
                    continue;
                }

                error_log("РћР±СЂР°Р±РѕС‚РєР° СЃС‚СЂРѕРєРё: " . implode(',', $row));

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
                    error_log("РћС€РёР±РєР° РІС‹РїРѕР»РЅРµРЅРёСЏ Р·Р°РїСЂРѕСЃР° РґР»СЏ СЃС‚СЂРѕРєРё: " . implode(',', $row));
                }
                $count++;
            }

            if ($count === 0) {
                throw new Exception("CSV-С„Р°Р№Р» РЅРµ СЃРѕРґРµСЂР¶РёС‚ РґР°РЅРЅС‹С… РґР»СЏ РёРјРїРѕСЂС‚Р°");
            }

            $this->connection->commit();
            
            $this->invalidateMenuCache();
            
            return true;
        } catch (Throwable $e) {
            $this->connection->rollBack();
            error_log("bulkUpdateMenu Error: " . $e->getMessage());
            $_SESSION['error'] = "РћС€РёР±РєР° Р·Р°РіСЂСѓР·РєРё CSV: " . $e->getMessage();
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
                VALUES (?, 'guest@system.local', '', 'Р“РѕСЃС‚СЊ', '', 'guest', NOW())
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

    // Р”РѕР±Р°РІР»СЏРµРј РѕСЃС‚Р°Р»СЊРЅС‹Рµ РѕС‚С‡РµС‚С‹ Р°РЅР°Р»РѕРіРёС‡РЅРѕ (getEfficiencyReport, getTopCustomers, getTopDishes, getEmployeeStats, getHourlyLoad)
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

    public function createGuestOrder($items, $total, $deliveryType = 'bar', $deliveryDetail = '')
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
                (user_id, items, total, status, delivery_type, delivery_details, created_at, updated_at)
                VALUES (:user_id, :items, :total, :initial_status, :delivery_type, :delivery_details, NOW(), NOW())
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

    public function createOrder($userId, $items, $total, $deliveryType = 'bar', $deliveryDetail = '')
    {
        try {
            $this->connection->beginTransaction();
            $initialStatus = $this->getInitialOrderStatus();

            $stmt = $this->prepareCached("
                INSERT INTO orders
                (user_id, items, total, status, delivery_type, delivery_details, created_at, updated_at)
                VALUES (:user_id, :items, :total, :initial_status, :delivery_type, :delivery_details, NOW(), NOW())
            ");

            $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($itemsJson === false) {
                throw new Exception('Failed to encode order items to JSON');
            }

            $params = [
                ':user_id' => $userId,
                ':items' => $itemsJson,
                ':total' => $total,
                ':initial_status' => $initialStatus,
                ':delivery_type' => $deliveryType,
                ':delivery_details' => $deliveryDetail,
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

    public function getConnection()
    {
        return $this->connection;
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
