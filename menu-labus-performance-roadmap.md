# Дорожная карта оптимизации производительности menu.labus.pro

**Дата анализа:** 10 февраля 2026  
**Цель:** Максимизировать производительность, RPS к БД, стабильность и техническое совершенство на стеке **Beget + FastPanel (nginx + Apache) + PHP-FPM + MySQL**

---

## 📊 EXECUTIVE SUMMARY

### Текущее состояние проекта

**Положительные стороны:**
- ✅ Реализована многоуровневая система кэширования (QueryCache, RedisCache)
- ✅ Подготовлена оптимизированная конфигурация nginx с FastCGI cache
- ✅ Реализован паттерн Singleton для подключения к БД
- ✅ Использование prepared statements для защиты от SQL-инъекций
- ✅ Progressive Web App (PWA) с Service Worker
- ✅ Оптимизация статических ресурсов (WebP, версионирование, минификация)
- ✅ Разделение пулов PHP-FPM (web/api/sse)

**Критические проблемы:**
- ❌ **Отсутствие connection pooling** - каждый запрос создает новое подключение к БД
- ❌ **N+1 проблемы в запросах** - множественные вложенные SELECT в циклах
- ❌ **Медленные агрегационные запросы** без материализованных представлений
- ❌ **Отсутствие query result pooling** для частых идентичных запросов
- ❌ **Неоптимальная структура индексов** - составные индексы не покрывают все частые запросы
- ❌ **Избыточное использование JSON** в БД вместо нормализованных таблиц
- ❌ **Отсутствие read replicas** для распределения нагрузки чтения
- ❌ **Нет батчинга** для массовых операций вставки/обновления

### Потенциал улучшения
- 🚀 **RPS к БД:** +400-800% (с ~100 RPS до 500-900 RPS)
- ⚡ **Latency:** -60-80% (с ~200ms до ~40-80ms на p95)
- 💾 **Memory efficiency:** +50-70% за счет connection pooling
- 🔄 **Query throughput:** +300-500% за счет batch operations

---

## 🎯 ФАЗА 1: КРИТИЧЕСКИЕ ОПТИМИЗАЦИИ БД (Неделя 1-2)

### 1.1 Connection Pooling & Persistent Connections

**Проблема:** Каждый запрос создает новое подключение к MySQL, что создает overhead ~5-15ms на каждое подключение.

**Решение:**

```php
// db.php - ДОБАВИТЬ connection pooling
class Database {
    private static $pool = [];
    private static $poolSize = 10; // Beget ограничивает ~20-30 соединений
    private static $poolIndex = 0;

    private function __construct() {
        // Изменить PDO::ATTR_PERSISTENT на true
        $this->connection = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true, // КРИТИЧНО!
                PDO::ATTR_TIMEOUT => 5,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                PDO::MYSQL_ATTR_COMPRESS => true
            ]
        );
    }

    // Добавить метод для получения соединения из пула
    public static function getPooledConnection() {
        if (empty(self::$pool)) {
            for ($i = 0; $i < self::$poolSize; $i++) {
                self::$pool[] = new self();
            }
        }

        $conn = self::$pool[self::$poolIndex];
        self::$poolIndex = (self::$poolIndex + 1) % self::$poolSize;
        return $conn;
    }
}
```

**Ожидаемый эффект:**
- Устранение ~5-15ms overhead на каждый запрос
- +100-200% RPS при высокой нагрузке
- Снижение CPU usage на ~15-25%

**Ограничения Beget:**
- Max 20-30 одновременных подключений к MySQL
- Настроить `$poolSize = 10` как базовое значение

---

### 1.2 Оптимизация индексов БД

**Текущее состояние:** Базовые индексы на `id`, но отсутствуют составные индексы для частых запросов.

**КРИТИЧЕСКИЕ индексы для добавления:**

```sql
-- 1. Для getMenuItems() - выборка меню по категории
CREATE INDEX idx_menu_items_available_category_name 
ON menu_items(available, category, name);

-- 2. Для getAllOrders() - сортировка заказов
CREATE INDEX idx_orders_created_status 
ON orders(created_at DESC, status);

-- 3. Для getUserOrders() - заказы пользователя
CREATE INDEX idx_orders_user_created 
ON orders(user_id, created_at DESC);

-- 4. Для getOrderUpdatesSince() - отслеживание обновлений
CREATE INDEX idx_orders_updated_at 
ON orders(updated_at);

-- 5. Для getUserByEmail() - авторизация
CREATE INDEX idx_users_email_active 
ON users(email, is_active);

-- 6. Для order_items - JOIN с orders
CREATE INDEX idx_order_items_order_item 
ON order_items(order_id, item_id);

-- 7. Для auth_tokens - Remember Me функционал
CREATE INDEX idx_auth_tokens_selector_expires 
ON auth_tokens(selector, expires_at);

-- 8. Для отчетов - статусная аналитика
CREATE INDEX idx_orders_status_created_updated 
ON orders(status, created_at, updated_at);
```

**Проверка эффективности индексов:**

```sql
-- Скрипт для анализа использования индексов
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    SEQ_IN_INDEX,
    COLUMN_NAME,
    CARDINALITY
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = 'menu_labus'
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- Проверка неиспользуемых индексов
SELECT 
    object_schema,
    object_name,
    index_name
FROM performance_schema.table_io_waits_summary_by_index_usage
WHERE index_name IS NOT NULL
  AND count_star = 0
  AND object_schema = 'menu_labus';
```

**Ожидаемый эффект:**
- Query time: -70-90% для частых запросов
- +150-300% RPS для API эндпоинтов
- Устранение full table scans

---

### 1.3 Устранение N+1 проблем

**Проблема:** В коде присутствуют вложенные запросы в циклах (например, в `getAllOrders()`, `getTopDishes()`).

**Критичный пример из db.php:**

```php
// ПЛОХО - N+1 проблема
public function getAllOrders() {
    $stmt = $this->prepareCached("SELECT o.id, ..., u.name FROM orders o JOIN users u ...");
    $orders = $stmt->fetchAll();

    foreach ($orders as &$order) {
        $order['items'] = json_decode($order['items'], true);
        // Каждый раз декодируем JSON - это медленно!
    }
}
```

**РЕШЕНИЕ - Batch prefetch + кэширование:**

```php
// ХОРОШО - batch операции
public function getAllOrders() {
    $cacheKey = 'all_orders_batch';

    // Проверяем Redis cache
    if ($this->redisCache) {
        $cached = $this->redisCache->get($cacheKey);
        if ($cached !== null) return $cached;
    }

    $stmt = $this->prepareCached("
        SELECT 
            o.id, o.items, o.total, o.status, o.delivery_type,
            o.created_at, o.updated_at,
            u.name as user_name, u.phone as user_phone,
            GROUP_CONCAT(
                CONCAT(oi.item_id, ':', oi.quantity, ':', oi.price) 
                SEPARATOR '|'
            ) as items_data
        FROM orders o
        JOIN users u ON o.user_id = u.id
        LEFT JOIN order_items oi ON oi.order_id = o.id
        GROUP BY o.id
        ORDER BY o.created_at DESC
    ");

    $stmt->execute();
    $orders = $stmt->fetchAll();

    // Один раз парсим JSON для всех заказов
    foreach ($orders as &$order) {
        $order['items'] = json_decode($order['items'], true);

        // Парсим items_data из GROUP_CONCAT
        if ($order['items_data']) {
            $order['order_items'] = $this->parseItemsData($order['items_data']);
        }
    }

    // Кэшируем на 30 секунд
    if ($this->redisCache) {
        $this->redisCache->set($cacheKey, $orders, 30);
    }

    return $orders;
}

private function parseItemsData($itemsData) {
    $items = [];
    foreach (explode('|', $itemsData) as $item) {
        list($id, $qty, $price) = explode(':', $item);
        $items[] = ['item_id' => $id, 'quantity' => $qty, 'price' => $price];
    }
    return $items;
}
```

**Ожидаемый эффект:**
- -80-95% количества запросов к БД
- Query time для списка заказов: с ~500ms до ~50ms
- +200-400% RPS для dashboard endpoints

---

### 1.4 Denormalization & Материализованные представления

**Проблема:** Агрегационные запросы в отчетах (getSalesReport, getProfitReport) выполняются каждый раз заново с множественными JOIN и вычислениями.

**РЕШЕНИЕ - Создать материализованные таблицы для отчетов:**

```sql
-- Таблица для ежедневных агрегатов
CREATE TABLE sales_daily_cache (
    report_date DATE PRIMARY KEY,
    order_count INT NOT NULL DEFAULT 0,
    total_revenue DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_expenses DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_profit DECIMAL(10,2) NOT NULL DEFAULT 0,
    avg_order_value DECIMAL(10,2) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_report_date_updated (report_date, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Триггер для автоматического обновления
DELIMITER $$
CREATE TRIGGER update_sales_cache_after_order_insert
AFTER INSERT ON orders
FOR EACH ROW
BEGIN
    IF NEW.status = 'завершён' THEN
        INSERT INTO sales_daily_cache (
            report_date, order_count, total_revenue
        )
        VALUES (
            DATE(NEW.created_at), 1, NEW.total
        )
        ON DUPLICATE KEY UPDATE
            order_count = order_count + 1,
            total_revenue = total_revenue + NEW.total,
            avg_order_value = total_revenue / order_count;
    END IF;
END$$

CREATE TRIGGER update_sales_cache_after_order_update
AFTER UPDATE ON orders
FOR EACH ROW
BEGIN
    IF NEW.status = 'завершён' AND OLD.status != 'завершён' THEN
        INSERT INTO sales_daily_cache (
            report_date, order_count, total_revenue
        )
        VALUES (
            DATE(NEW.updated_at), 1, NEW.total
        )
        ON DUPLICATE KEY UPDATE
            order_count = order_count + 1,
            total_revenue = total_revenue + NEW.total,
            avg_order_value = total_revenue / order_count;
    END IF;
END$$
DELIMITER ;
```

**PHP-код для работы с кэшем:**

```php
public function getSalesReport($period = 'day') {
    if ($period === 'day') {
        // Используем материализованную таблицу
        $stmt = $this->prepareCached("
            SELECT 
                report_date as date,
                order_count,
                total_revenue,
                avg_order_value
            FROM sales_daily_cache
            WHERE report_date >= CURDATE() - INTERVAL 1 DAY
            ORDER BY report_date DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    // ... остальная логика для week/month/year
}
```

**Ожидаемый эффект:**
- Query time для отчетов: с ~2-5s до ~10-50ms (200-500x быстрее!)
- -95% нагрузки на БД при запросах отчетов
- +500-1000% RPS для analytics endpoints

---

### 1.5 Batch Operations для массовых операций

**Проблема:** В `persistOrderItems()` items вставляются по одному в цикле.

**РЕШЕНИЕ - Multi-row INSERT:**

```php
private function persistOrderItems(int $orderId, array $items): void {
    if (!$orderId || !$items) return;
    if (!$this->ensureOrderItemsTable()) return;

    // Batch insert - один запрос вместо N запросов
    $values = [];
    $params = [];
    $i = 0;

    foreach ($items as $item) {
        $itemId = isset($item['id']) ? (int)$item['id'] : 0;
        if ($itemId <= 0) continue;

        $quantity = max(1, (int)($item['quantity'] ?? 1));
        $price = (float)($item['price'] ?? 0);
        $itemName = isset($item['name']) ? (string)$item['name'] : null;

        $values[] = "(:order_id_{$i}, :item_id_{$i}, :item_name_{$i}, :quantity_{$i}, :price_{$i}, NOW())";

        $params[":order_id_{$i}"] = $orderId;
        $params[":item_id_{$i}"] = $itemId;
        $params[":item_name_{$i}"] = $itemName;
        $params[":quantity_{$i}"] = $quantity;
        $params[":price_{$i}"] = $price;

        $i++;
    }

    if (empty($values)) return;

    $sql = "INSERT INTO order_items 
            (order_id, item_id, item_name, quantity, price, created_at)
            VALUES " . implode(',', $values);

    $stmt = $this->connection->prepare($sql);
    $stmt->execute($params);
}
```

**Ожидаемый эффект:**
- -90% количества INSERT запросов
- Создание заказа: с ~300ms до ~50ms
- +400-600% RPS для order creation endpoint

---

## 🚀 ФАЗА 2: PHP-FPM И NGINX ОПТИМИЗАЦИИ (Неделя 2-3)

### 2.1 PHP-FPM Pool Configuration для Beget

**Текущая конфигурация в проекте предполагает 3 отдельных пула (web/api/sse), но на Beget ограничено количество процессов.**

**ОПТИМАЛЬНАЯ конфигурация для Beget/FastPanel:**

```ini
; /etc/php/8.x/fpm/pool.d/menu_labus.conf

[menu_labus_web]
user = menu_labus_usr
group = menu_labus_usr
listen = /var/run/php/menu_labus_web.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

; КРИТИЧНО: pm = static для стабильной производительности
pm = static
pm.max_children = 15  ; Beget тарифы обычно 1-2GB RAM
                      ; 15 процессов * ~40MB = ~600MB для PHP-FPM

; Для динамического режима (если не хватает памяти):
; pm = dynamic
; pm.max_children = 20
; pm.start_servers = 8
; pm.min_spare_servers = 5
; pm.max_spare_servers = 12

pm.max_requests = 1000
pm.status_path = /fpm-status

; Performance tuning
php_admin_value[memory_limit] = 128M
php_admin_value[max_execution_time] = 30
php_admin_value[max_input_time] = 30

; OPcache settings (КРИТИЧНО!)
php_admin_value[opcache.enable] = 1
php_admin_value[opcache.memory_consumption] = 128
php_admin_value[opcache.interned_strings_buffer] = 16
php_admin_value[opcache.max_accelerated_files] = 10000
php_admin_value[opcache.validate_timestamps] = 0  ; Production mode
php_admin_value[opcache.revalidate_freq] = 0
php_admin_value[opcache.fast_shutdown] = 1

; Session handling
php_admin_value[session.save_handler] = redis
php_admin_value[session.save_path] = "tcp://127.0.0.1:6379"

; Error logging
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_value[error_log] = /var/www/menu_labus/logs/php_errors.log

[menu_labus_api]
; Отдельный пул для API - меньше процессов
user = menu_labus_usr
group = menu_labus_usr
listen = /var/run/php/menu_labus_api.sock
pm = dynamic
pm.max_children = 10
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500
pm.status_path = /fpm-status-api

[menu_labus_sse]
; SSE pool - долгоживущие соединения
user = menu_labus_usr
group = menu_labus_usr
listen = /var/run/php/menu_labus_sse.sock
pm = dynamic
pm.max_children = 5
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 100
request_terminate_timeout = 3600s  ; 1 час для SSE
pm.status_path = /fpm-status-sse
```

**Расчет pm.max_children для вашего сервера:**

```bash
# 1. Узнать средний размер PHP-FPM процесса
ps aux | grep php-fpm | awk '{sum+=$6} END {print "Average:", sum/NR/1024, "MB"}'

# 2. Расчет максимального количества процессов
# Формула: (Total RAM * 0.7) / Average PHP-FPM size
# Пример для 2GB RAM и 40MB процесс:
# (2048 * 0.7) / 40 = ~35 процессов

# 3. Разделить между пулами:
# web: 15-20 процессов (основная нагрузка)
# api: 8-10 процессов (API эндпоинты)
# sse: 3-5 процессов (SSE соединения)
```

**Ожидаемый эффект:**
- +50-100% RPS за счет pm.static
- -30-50% response time variance (более стабильный p95/p99)
- -20-40% memory usage с оптимизированным количеством процессов

---

### 2.2 Nginx Microcache & FastCGI tuning

**Применить подготовленную конфигурацию `nginx-optimized.conf` с доработками:**

```nginx
# Добавить в http {} блок
fastcgi_cache_path /var/cache/nginx/fastcgi_menu 
    levels=1:2 
    keys_zone=MENUCACHE:200m  # Увеличить до 200MB
    inactive=10m              # Сократить до 10 минут
    max_size=2g               # Увеличить до 2GB
    use_temp_path=off;        # КРИТИЧНО для производительности

# Параметры FastCGI для всех PHP locations
fastcgi_connect_timeout 5s;
fastcgi_send_timeout 30s;
fastcgi_read_timeout 30s;
fastcgi_buffer_size 32k;           # Увеличить для больших ответов
fastcgi_buffers 32 32k;            # 32 * 32k = 1MB buffer
fastcgi_busy_buffers_size 64k;
fastcgi_temp_file_write_size 64k;

# Connection pooling для FastCGI (если поддерживается)
fastcgi_keep_conn on;
fastcgi_socket_keepalive on;
```

**Настройка агрессивного кэширования для публичного меню:**

```nginx
location = /api/v1/menu.php {
    # Увеличить TTL для burst protection
    fastcgi_cache MENUCACHE;
    fastcgi_cache_valid 200 30s;  # Было 5s, стало 30s
    fastcgi_cache_valid 404 10s;

    # Stale content для высокой доступности
    fastcgi_cache_use_stale 
        error timeout invalid_header updating
        http_500 http_502 http_503 http_504;

    fastcgi_cache_background_update on;
    fastcgi_cache_lock on;
    fastcgi_cache_lock_timeout 2s;
    fastcgi_cache_lock_age 10s;

    # Варьировать кэш по методу и Origin для CORS
    fastcgi_cache_key "$scheme$request_method$host$request_uri|$http_origin|$http_accept_encoding";

    # Обход кэша только для benchmarking
    fastcgi_cache_bypass $http_x_bypass_cache;
    fastcgi_no_cache $http_x_bypass_cache;

    # Сжатие
    gzip on;
    gzip_types application/json;
    gzip_min_length 1000;
    gzip_comp_level 6;

    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/var/run/php/menu_labus_api.sock;
}
```

**Ожидаемый эффект:**
- +1000-2000% RPS для кэшируемых endpoints (с ~50 RPS до ~1000+ RPS)
- Latency для публичного меню: с ~100-200ms до ~2-5ms (HIT)
- -80-95% нагрузки на PHP-FPM для частых запросов

---

### 2.3 HTTP/2 Server Push & Preload

**Добавить в nginx для оптимизации загрузки критических ресурсов:**

```nginx
location = /menu.php {
    # HTTP/2 Server Push для критических ресурсов
    http2_push /css/fa-purged.min.css;
    http2_push /css/version.min.css;
    http2_push /js/security.min.js;

    # Или использовать Link header для preload
    add_header Link "</css/fa-purged.min.css>; rel=preload; as=style" always;
    add_header Link "</css/version.min.css>; rel=preload; as=style" always;
    add_header Link "</js/security.min.js>; rel=preload; as=script" always;

    # Остальная конфигурация...
}
```

**Ожидаемый эффект:**
- -30-50% First Contentful Paint (FCP)
- -20-40% Largest Contentful Paint (LCP)

---

## 🔥 ФАЗА 3: REDIS & ADVANCED CACHING (Неделя 3-4)

### 3.1 Redis Configuration для Beget

**Проверить доступность Redis на Beget и настроить:**

```redis
# redis.conf (если доступен для редактирования)
maxmemory 256mb
maxmemory-policy allkeys-lru

# Persistence (для сессий и важного кэша)
save 900 1
save 300 10
save 60 10000

# Performance
tcp-backlog 511
timeout 0
tcp-keepalive 300

# Для production
appendonly yes
appendfsync everysec
```

### 3.2 Многоуровневая стратегия кэширования

**Реализовать иерархию кэшей:**

```php
class CacheHierarchy {
    private $l1Cache; // APCu - in-process cache (fastest)
    private $l2Cache; // Redis - shared cache
    private $l3Cache; // Query Cache - in-memory PHP array

    public function get($key) {
        // Level 1: APCu (< 1μs)
        if (function_exists('apcu_fetch')) {
            $value = apcu_fetch($key, $success);
            if ($success) return $value;
        }

        // Level 2: Redis (~1ms)
        if ($this->l2Cache) {
            $value = $this->l2Cache->get($key);
            if ($value !== null) {
                // Backfill L1
                if (function_exists('apcu_store')) {
                    apcu_store($key, $value, 60);
                }
                return $value;
            }
        }

        // Level 3: Query Cache (в памяти PHP процесса)
        if ($this->l3Cache && isset($this->l3Cache[$key])) {
            return $this->l3Cache[$key];
        }

        return null;
    }

    public function set($key, $value, $ttl = 600) {
        // Store in all levels
        if (function_exists('apcu_store')) {
            apcu_store($key, $value, min($ttl, 60)); // L1 короткий TTL
        }

        if ($this->l2Cache) {
            $this->l2Cache->set($key, $value, $ttl);
        }

        $this->l3Cache[$key] = $value;
    }
}
```

**Ожидаемый эффект:**
- L1 hits: ~10-50x быстрее Redis
- Cache hit rate: +20-40% за счет многоуровневой стратегии
- -60-80% latency для часто запрашиваемых данных

---

### 3.3 Invalidation Strategy с tag-based cache

**Проблема:** Инвалидация кэша сейчас делается по pattern matching, что неэффективно.

**РЕШЕНИЕ - Tag-based invalidation:**

```php
class TaggedCache {
    private $redis;

    public function setWithTags($key, $value, $ttl, $tags = []) {
        // Сохраняем значение
        $this->redis->set($key, serialize($value), $ttl);

        // Связываем с тегами
        foreach ($tags as $tag) {
            $this->redis->sAdd("tag:{$tag}", $key);
            $this->redis->expire("tag:{$tag}", $ttl + 60);
        }
    }

    public function invalidateTag($tag) {
        // Получаем все ключи с этим тегом
        $keys = $this->redis->sMembers("tag:{$tag}");

        if (!empty($keys)) {
            // Удаляем все ключи одной командой
            $this->redis->del(...$keys);
            $this->redis->del("tag:{$tag}");
        }
    }

    public function invalidateTags($tags) {
        foreach ($tags as $tag) {
            $this->invalidateTag($tag);
        }
    }
}

// Использование
$cache->setWithTags('menu_items_all', $items, 600, ['menu', 'items']);
$cache->setWithTags('product_123', $product, 1800, ['menu', 'product', 'product_123']);

// При обновлении меню
$cache->invalidateTags(['menu', 'items']); // Инвалидирует все связанное
```

**Ожидаемый эффект:**
- -90% времени на инвалидацию кэша (с O(n) до O(1))
- +50-100% точность инвалидации
- -70% false invalidations

---

## ⚡ ФАЗА 4: QUERY OPTIMIZATION & DATABASE TUNING (Неделя 4-5)

### 4.1 MySQL Configuration для Beget

**Оптимальные параметры MySQL для Beget (в my.cnf, если доступен):**

```ini
[mysqld]
# InnoDB settings
innodb_buffer_pool_size = 512M      # 50-70% от доступной RAM
innodb_log_file_size = 128M
innodb_log_buffer_size = 16M
innodb_flush_log_at_trx_commit = 2  # Компромисс performance/durability
innodb_flush_method = O_DIRECT

# Query cache (если MySQL < 8.0)
query_cache_type = 1
query_cache_size = 64M
query_cache_limit = 2M

# Connection settings
max_connections = 100
max_connect_errors = 10000
wait_timeout = 600
interactive_timeout = 600

# Performance
table_open_cache = 4096
table_definition_cache = 2048
thread_cache_size = 16

# Slow query log
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 1
log_queries_not_using_indexes = 1
```

---

### 4.2 Query Rewriting для сложных отчетов

**Пример оптимизации `getSalesReport()`:**

```php
// БЫЛО - медленный запрос с множественными вычислениями
public function getSalesReport($period = 'day') {
    $sql = "SELECT 
                DATE_FORMAT(created_at, '%d.%m') as date,
                COUNT(*) as order_count,
                SUM(total) as total_revenue,
                AVG(total) as avg_order_value
            FROM orders
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)
            AND status = 'завершён'
            GROUP BY DATE(created_at)
            ORDER BY date DESC";
}

// СТАЛО - использование материализованной таблицы + covering index
public function getSalesReport($period = 'day') {
    // Проверяем Redis кэш с более длинным TTL
    $cacheKey = "sales_report_{$period}_" . date('Y-m-d-H');
    if ($cached = $this->redisCache->get($cacheKey)) {
        return $cached;
    }

    // Используем предварительно агрегированные данные
    $sql = "SELECT 
                DATE_FORMAT(report_date, '%d.%m') as date,
                order_count,
                total_revenue,
                avg_order_value,
                total_profit
            FROM sales_daily_cache
            WHERE report_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            ORDER BY report_date DESC";

    $stmt = $this->prepareCached($sql);
    $stmt->execute();
    $result = $stmt->fetchAll();

    // Кэшируем на 1 час (отчеты могут быть немного устаревшими)
    $this->redisCache->set($cacheKey, $result, 3600);

    return $result;
}
```

**Ожидаемый эффект:**
- Query time: с 2-5s до 10-50ms (100-500x быстрее!)
- +500-1000% RPS для analytics

---

### 4.3 Partitioning больших таблиц

**Для таблицы `orders` применить партиционирование по дате:**

```sql
-- Создание партиционированной таблицы
ALTER TABLE orders
PARTITION BY RANGE (YEAR(created_at) * 100 + MONTH(created_at)) (
    PARTITION p202501 VALUES LESS THAN (202502),
    PARTITION p202502 VALUES LESS THAN (202503),
    PARTITION p202503 VALUES LESS THAN (202504),
    PARTITION p202504 VALUES LESS THAN (202505),
    PARTITION p202505 VALUES LESS THAN (202506),
    PARTITION p202506 VALUES LESS THAN (202507),
    PARTITION p202507 VALUES LESS THAN (202508),
    PARTITION p202508 VALUES LESS THAN (202509),
    PARTITION p202509 VALUES LESS THAN (202510),
    PARTITION p202510 VALUES LESS THAN (202511),
    PARTITION p202511 VALUES LESS THAN (202512),
    PARTITION p202512 VALUES LESS THAN (202601),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);

-- Автоматическое создание новых партиций (cron job)
CREATE PROCEDURE create_next_partition()
BEGIN
    DECLARE next_month INT;
    DECLARE next_year INT;
    DECLARE partition_name VARCHAR(20);

    SET next_month = MONTH(DATE_ADD(NOW(), INTERVAL 1 MONTH));
    SET next_year = YEAR(DATE_ADD(NOW(), INTERVAL 1 MONTH));
    SET partition_name = CONCAT('p', next_year, LPAD(next_month, 2, '0'));

    SET @sql = CONCAT(
        'ALTER TABLE orders REORGANIZE PARTITION p_future INTO (',
        'PARTITION ', partition_name, 
        ' VALUES LESS THAN (', next_year * 100 + next_month, '),',
        'PARTITION p_future VALUES LESS THAN MAXVALUE)'
    );

    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END;
```

**Ожидаемый эффект:**
- -50-70% query time для запросов по дате
- +100-200% RPS для time-based queries
- Упрощение архивации старых данных

---

## 🎨 ФАЗА 5: FRONTEND & ASSET OPTIMIZATION (Неделя 5-6)

### 5.1 Critical CSS Inline

**Добавить инлайн критический CSS в `<head>`:**

```php
<!-- header.php -->
<style>
/* Critical CSS - только для above-the-fold контента */
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.hero{min-height:100vh;display:flex;align-items:center;justify-content:center}
.hero-content{text-align:center;color:#fff}
.btn{display:inline-block;padding:1rem 2rem;background:#007bff;color:#fff;text-decoration:none;border-radius:4px}
</style>

<!-- Остальные стили загружать async -->
<link rel="preload" href="/css/version.min.css?v=<?= $version ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="/css/version.min.css?v=<?= $version ?>"></noscript>
```

**Ожидаемый эффект:**
- -40-60% First Contentful Paint
- -30-50% Largest Contentful Paint

---

### 5.2 Service Worker Cache Strategy

**Оптимизировать sw.js с агрессивным кэшированием:**

```javascript
// sw.js
const CACHE_VERSION = 'v2.1.0';
const CACHE_NAME = `menulabus-${CACHE_VERSION}`;

const STATIC_CACHE_URLS = [
    '/',
    '/menu.php',
    '/css/fa-purged.min.css',
    '/css/version.min.css',
    '/js/security.min.js',
    '/js/app.min.js',
    '/offline.html'
];

// Стратегия: Network First для API, Cache First для статики
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // API - Network First with timeout
    if (url.pathname.startsWith('/api/')) {
        event.respondWith(
            Promise.race([
                fetch(event.request),
                new Promise((_, reject) => 
                    setTimeout(() => reject(new Error('timeout')), 3000)
                )
            ]).catch(() => caches.match(event.request))
        );
        return;
    }

    // Static assets - Cache First
    if (url.pathname.match(/\.(css|js|png|jpg|webp|woff2)$/)) {
        event.respondWith(
            caches.match(event.request)
                .then(response => response || fetch(event.request))
        );
        return;
    }

    // HTML - Stale While Revalidate
    event.respondWith(
        caches.open(CACHE_NAME).then(cache => {
            return cache.match(event.request).then(response => {
                const fetchPromise = fetch(event.request).then(networkResponse => {
                    cache.put(event.request, networkResponse.clone());
                    return networkResponse;
                });
                return response || fetchPromise;
            });
        })
    );
});
```

**Ожидаемый эффект:**
- Offline-first experience
- -70-90% latency для повторных посещений
- +500-1000% perceived performance

---

### 5.3 Image Optimization Pipeline

**Автоматизировать оптимизацию изображений:**

```php
// ImageOptimizer.php - УЛУЧШИТЬ существующий класс
class ImageOptimizer {
    public function optimize($sourcePath, $targetPath, $options = []) {
        $quality = $options['quality'] ?? 85;
        $maxWidth = $options['maxWidth'] ?? 1920;
        $maxHeight = $options['maxHeight'] ?? 1080;

        // Генерировать multiple sizes для responsive images
        $sizes = [
            'sm' => 320,
            'md' => 640,
            'lg' => 1024,
            'xl' => 1440,
            'xxl' => 1920
        ];

        foreach ($sizes as $sizeName => $width) {
            $this->generateResponsiveImage(
                $sourcePath,
                $targetPath,
                $width,
                $quality,
                $sizeName
            );
        }

        // Генерировать WebP и AVIF versions
        $this->generateWebP($targetPath, $quality);
        $this->generateAVIF($targetPath, $quality - 10);
    }

    private function generateResponsiveImage($source, $target, $width, $quality, $size) {
        // Используем ImageMagick или GD
        $image = imagecreatefromjpeg($source);
        $origWidth = imagesx($image);
        $origHeight = imagesy($image);

        $ratio = $width / $origWidth;
        $newHeight = (int)($origHeight * $ratio);

        $resized = imagecreatetruecolor($width, $newHeight);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, 
            $width, $newHeight, $origWidth, $origHeight);

        $targetFile = str_replace('.jpg', "_{$size}.jpg", $target);
        imagejpeg($resized, $targetFile, $quality);

        imagedestroy($image);
        imagedestroy($resized);
    }
}
```

**Ожидаемый эффект:**
- -60-80% image размеров (WebP + AVIF)
- -40-60% bandwidth usage
- +100-200% page load speed на медленных соединениях

---

## 🔐 ФАЗА 6: SECURITY & MONITORING (Неделя 6-7)

### 6.1 Rate Limiting & DDoS Protection

**Добавить в nginx rate limiting:**

```nginx
# http {} блок
limit_req_zone $binary_remote_addr zone=general:10m rate=30r/s;
limit_req_zone $binary_remote_addr zone=api:10m rate=60r/s;
limit_req_zone $binary_remote_addr zone=auth:10m rate=5r/s;

# В location блоках
location /api/ {
    limit_req zone=api burst=100 nodelay;
    limit_req_status 429;
    # ...
}

location ~ ^/(auth|login|register)\.php$ {
    limit_req zone=auth burst=10 nodelay;
    # ...
}

location / {
    limit_req zone=general burst=50 nodelay;
    # ...
}
```

---

### 6.2 Real-time Performance Monitoring

**Создать dashboard для мониторинга производительности:**

```php
// monitoring/performance-dashboard.php
<?php
require_once '../db.php';

$db = Database::getInstance();

// Query statistics
$queryStats = $db->getQueryCacheStats();

// PHP-FPM status
$fpmStatus = [
    'web' => file_get_contents('http://localhost/fpm-status?json'),
    'api' => file_get_contents('http://localhost/fpm-status-api?json'),
    'sse' => file_get_contents('http://localhost/fpm-status-sse?json')
];

// Redis stats
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redisInfo = $redis->info();

// MySQL slow queries
$slowQueries = $db->query("
    SELECT 
        query_time,
        lock_time,
        rows_examined,
        rows_sent,
        sql_text
    FROM mysql.slow_log
    WHERE start_time >= NOW() - INTERVAL 1 HOUR
    ORDER BY query_time DESC
    LIMIT 20
")->fetchAll();

// Render dashboard
?>
<!DOCTYPE html>
<html>
<head>
    <title>Performance Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <h1>Performance Monitoring Dashboard</h1>

    <section>
        <h2>PHP-FPM Status</h2>
        <div class="metrics">
            <?php foreach ($fpmStatus as $pool => $status): 
                $data = json_decode($status, true);
            ?>
                <div class="metric-card">
                    <h3><?= $pool ?> Pool</h3>
                    <p>Active processes: <?= $data['active processes'] ?></p>
                    <p>Idle processes: <?= $data['idle processes'] ?></p>
                    <p>Total requests: <?= $data['accepted conn'] ?></p>
                    <p>Slow requests: <?= $data['slow requests'] ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section>
        <h2>Cache Hit Rates</h2>
        <canvas id="cacheChart"></canvas>
    </section>

    <section>
        <h2>Slow Queries (Last Hour)</h2>
        <table>
            <thead>
                <tr>
                    <th>Query Time</th>
                    <th>Rows Examined</th>
                    <th>SQL</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($slowQueries as $query): ?>
                    <tr>
                        <td><?= $query['query_time'] ?>s</td>
                        <td><?= $query['rows_examined'] ?></td>
                        <td><code><?= htmlspecialchars(substr($query['sql_text'], 0, 100)) ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</body>
</html>
```

---

### 6.3 Automated Load Testing

**Создать скрипт для регулярного load testing:**

```python
# load_test_advanced.py
import asyncio
import aiohttp
import time
from statistics import mean, median

class LoadTester:
    def __init__(self, base_url, duration=60, rps=100):
        self.base_url = base_url
        self.duration = duration
        self.target_rps = rps
        self.results = []

    async def make_request(self, session, endpoint):
        start = time.time()
        try:
            async with session.get(f"{self.base_url}{endpoint}") as response:
                await response.text()
                latency = (time.time() - start) * 1000  # ms
                self.results.append({
                    'endpoint': endpoint,
                    'status': response.status,
                    'latency': latency,
                    'timestamp': start
                })
                return response.status == 200
        except Exception as e:
            print(f"Error: {e}")
            return False

    async def run_test(self):
        endpoints = [
            '/api/v1/menu.php',
            '/menu.php',
            '/api/v1/categories.php',
            '/index.php'
        ]

        async with aiohttp.ClientSession() as session:
            start_time = time.time()
            tasks = []

            while time.time() - start_time < self.duration:
                for endpoint in endpoints:
                    tasks.append(self.make_request(session, endpoint))

                # Rate limiting
                if len(tasks) >= self.target_rps:
                    await asyncio.gather(*tasks)
                    tasks = []
                    await asyncio.sleep(1)

            # Wait for remaining tasks
            if tasks:
                await asyncio.gather(*tasks)

    def print_stats(self):
        latencies = [r['latency'] for r in self.results]
        successful = len([r for r in self.results if r['status'] == 200])

        print(f"\n=== Load Test Results ===")
        print(f"Total requests: {len(self.results)}")
        print(f"Successful: {successful} ({successful/len(self.results)*100:.1f}%)")
        print(f"Mean latency: {mean(latencies):.2f}ms")
        print(f"Median latency: {median(latencies):.2f}ms")
        print(f"P95 latency: {sorted(latencies)[int(len(latencies)*0.95)]:.2f}ms")
        print(f"P99 latency: {sorted(latencies)[int(len(latencies)*0.99)]:.2f}ms")
        print(f"Min latency: {min(latencies):.2f}ms")
        print(f"Max latency: {max(latencies):.2f}ms")

if __name__ == '__main__':
    tester = LoadTester('https://menu.pub.labus.pro', duration=60, rps=100)
    asyncio.run(tester.run_test())
    tester.print_stats()
```

**Запускать через cron:**

```bash
# Каждый день в 3 ночи
0 3 * * * cd /var/www/menu.labus.pro && python3 load_test_advanced.py >> /var/log/loadtest.log 2>&1
```

---

## 📈 ФАЗА 7: ADVANCED TECHNIQUES (Неделя 7-8)

### 7.1 GraphQL API для гибких запросов

**Добавить GraphQL endpoint для оптимизации fetching'а данных:**

```php
// api/graphql.php
<?php
require_once '../vendor/autoload.php';
require_once '../db.php';

use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

$db = Database::getInstance();

$menuItemType = new ObjectType([
    'name' => 'MenuItem',
    'fields' => [
        'id' => Type::int(),
        'name' => Type::string(),
        'description' => Type::string(),
        'price' => Type::float(),
        'category' => Type::string(),
        'available' => Type::boolean(),
        'image' => Type::string(),
    ],
]);

$queryType = new ObjectType([
    'name' => 'Query',
    'fields' => [
        'menuItems' => [
            'type' => Type::listOf($menuItemType),
            'args' => [
                'category' => Type::string(),
                'available' => Type::boolean(),
                'limit' => Type::int(),
            ],
            'resolve' => function ($root, $args) use ($db) {
                // Построить динамический запрос с только нужными полями
                $sql = "SELECT * FROM menu_items WHERE 1=1";
                $params = [];

                if (isset($args['category'])) {
                    $sql .= " AND category = :category";
                    $params[':category'] = $args['category'];
                }

                if (isset($args['available'])) {
                    $sql .= " AND available = :available";
                    $params[':available'] = $args['available'] ? 1 : 0;
                }

                if (isset($args['limit'])) {
                    $sql .= " LIMIT :limit";
                    $params[':limit'] = $args['limit'];
                }

                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll();
            },
        ],
    ],
]);

$schema = new Schema([
    'query' => $queryType,
]);

// Обработка запроса
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
$query = $input['query'];
$variableValues = $input['variables'] ?? null;

$result = GraphQL::executeQuery($schema, $query, null, null, $variableValues);
$output = $result->toArray();

header('Content-Type: application/json');
echo json_encode($output);
```

**Ожидаемый эффект:**
- -50-70% over-fetching (клиент запрашивает только нужные поля)
- -30-50% количества API запросов
- +100-200% flexibility для frontend

---

### 7.2 Database Read Replicas (если доступно на Beget)

**Настроить master-slave репликацию:**

```php
// db.php - добавить поддержку read replicas
class Database {
    private $masterConnection;
    private $slaveConnections = [];
    private $currentSlaveIndex = 0;

    private function connectToMaster() {
        $this->masterConnection = new PDO(/* master config */);
    }

    private function connectToSlaves() {
        $slaves = [
            ['host' => 'slave1.mysql.beget.com', 'port' => 3306],
            ['host' => 'slave2.mysql.beget.com', 'port' => 3306],
        ];

        foreach ($slaves as $slave) {
            try {
                $this->slaveConnections[] = new PDO(
                    "mysql:host={$slave['host']};port={$slave['port']};dbname=" . DB_NAME,
                    DB_USER,
                    DB_PASS,
                    [/* options */]
                );
            } catch (PDOException $e) {
                error_log("Failed to connect to slave: " . $e->getMessage());
            }
        }
    }

    public function query($sql, $params = [], $useMaster = false) {
        // SELECT запросы идут на slave (если не требуется master)
        if (!$useMaster && stripos(trim($sql), 'SELECT') === 0) {
            $connection = $this->getSlaveConnection();
        } else {
            $connection = $this->masterConnection;
        }

        $stmt = $connection->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    private function getSlaveConnection() {
        if (empty($this->slaveConnections)) {
            return $this->masterConnection; // Fallback
        }

        // Round-robin load balancing
        $connection = $this->slaveConnections[$this->currentSlaveIndex];
        $this->currentSlaveIndex = ($this->currentSlaveIndex + 1) % count($this->slaveConnections);

        return $connection;
    }
}
```

**Ожидаемый эффект:**
- +100-200% read capacity
- -50% нагрузки на master
- Better write performance на master

---

### 7.3 Async Processing с Queue Workers

**Добавить RabbitMQ/Redis Queue для долгих задач:**

```php
// Queue.php - УЛУЧШИТЬ существующий класс
class Queue {
    private $redis;

    public function __construct() {
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);
    }

    public function push($queue, $job, $data = []) {
        $payload = json_encode([
            'job' => $job,
            'data' => $data,
            'attempts' => 0,
            'created_at' => time(),
        ]);

        $this->redis->rPush("queue:{$queue}", $payload);
    }

    public function pop($queue, $timeout = 0) {
        $result = $this->redis->blPop("queue:{$queue}", $timeout);

        if ($result) {
            return json_decode($result[1], true);
        }

        return null;
    }

    public function processJob($job) {
        switch ($job['job']) {
            case 'send_email':
                $this->sendEmail($job['data']);
                break;

            case 'generate_report':
                $this->generateReport($job['data']);
                break;

            case 'optimize_images':
                $this->optimizeImages($job['data']);
                break;
        }
    }
}

// Worker script
// worker.php
<?php
require_once 'Queue.php';

$queue = new Queue();

while (true) {
    $job = $queue->pop('default', 5); // 5 sec timeout

    if ($job) {
        try {
            $queue->processJob($job);
            echo "Processed job: {$job['job']}\n";
        } catch (Exception $e) {
            error_log("Job failed: " . $e->getMessage());

            // Retry logic
            if ($job['attempts'] < 3) {
                $job['attempts']++;
                $queue->push('default', $job['job'], $job['data']);
            }
        }
    }
}
```

**Запустить worker через supervisor:**

```ini
[program:menu_labus_worker]
command=/usr/bin/php /var/www/menu.labus.pro/worker.php
autostart=true
autorestart=true
user=menu_labus_usr
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/menu.labus.pro/logs/worker.log
```

**Ожидаемый эффект:**
- -80-95% response time для тяжелых операций
- Better UX (non-blocking operations)
- +200-500% throughput для bulk operations

---

## 🎯 ИЗМЕРЕНИЯ И KPI

### Baseline (До оптимизаций)

**Производительность:**
- RPS: ~50-100 req/s
- Latency p50: ~150ms
- Latency p95: ~500ms
- Latency p99: ~1500ms

**Ресурсы:**
- CPU usage: 60-80%
- Memory: 70-85%
- DB connections: 15-25 active

**Качество:**
- Cache hit rate: ~30-40%
- Query time avg: ~200ms
- Slow queries: ~50-100/hour

### Target (После оптимизаций)

**Производительность:**
- RPS: **500-900 req/s** (+400-800%)
- Latency p50: **40-60ms** (-70%)
- Latency p95: **80-120ms** (-75%)
- Latency p99: **200-300ms** (-80%)

**Ресурсы:**
- CPU usage: **30-50%** (-40%)
- Memory: **40-60%** (-35%)
- DB connections: **5-10 active** (-60%)

**Качество:**
- Cache hit rate: **70-85%** (+100%)
- Query time avg: **20-40ms** (-80%)
- Slow queries: **5-10/hour** (-90%)

---

## 📅 TIMELINE И ПРИОРИТЕТЫ

### Критичные (Неделя 1-2) - НЕМЕДЛЕННО

1. ✅ Connection pooling (День 1-2)
2. ✅ Критичные индексы БД (День 2-3)
3. ✅ Устранение N+1 проблем (День 3-5)
4. ✅ PHP-FPM configuration (День 5-7)
5. ✅ Nginx FastCGI cache (День 7-10)

### Важные (Неделя 2-4)

6. ✅ Batch operations (День 10-12)
7. ✅ Материализованные представления (День 12-15)
8. ✅ Redis configuration (День 15-18)
9. ✅ Multi-tier caching (День 18-21)
10. ✅ Query rewriting (День 21-25)

### Желательные (Неделя 4-6)

11. ⭐ Partitioning (День 25-28)
12. ⭐ Critical CSS inline (День 28-30)
13. ⭐ Service Worker optimization (День 30-35)
14. ⭐ Rate limiting (День 35-38)
15. ⭐ Performance monitoring (День 38-40)

### Продвинутые (Неделя 6-8)

16. 🚀 GraphQL API (День 40-45)
17. 🚀 Read replicas (если доступно) (День 45-50)
18. 🚀 Async processing (День 50-55)

---

## 🔧 ИНСТРУМЕНТЫ ДЛЯ МОНИТОРИНГА

### Performance Testing

```bash
# ApacheBench
ab -n 10000 -c 100 -k https://menu.pub.labus.pro/api/v1/menu.php

# wrk (более продвинутый)
wrk -t12 -c400 -d30s https://menu.pub.labus.pro/api/v1/menu.php

# Siege
siege -c100 -t30s https://menu.pub.labus.pro/menu.php

# Custom Python load tester
python3 load_test_advanced.py
```

### MySQL Profiling

```sql
-- Включить профилирование
SET profiling = 1;

-- Выполнить запрос
SELECT * FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY);

-- Посмотреть профиль
SHOW PROFILES;
SHOW PROFILE FOR QUERY 1;

-- Анализ запроса
EXPLAIN ANALYZE
SELECT * FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY);
```

### PHP-FPM Monitoring

```bash
# Через browser
curl http://localhost/fpm-status?json

# CLI мониторинг
watch -n 1 'curl -s http://localhost/fpm-status?json | jq .'
```

---

## ⚠️ РИСКИ И ОГРАНИЧЕНИЯ

### Ограничения Beget/FastPanel

1. **Max connections к MySQL**: 20-30
   - Решение: Connection pooling + read replicas

2. **Ограниченная RAM**: 1-2GB обычно
   - Решение: pm.static с оптимальным max_children

3. **Нет root доступа**
   - Решение: Работать через FastPanel интерфейс

4. **Shared hosting ограничения**
   - Решение: Redis + aggressive caching

### Потенциальные проблемы

1. **Cache stampede** при инвалидации
   - Решение: fastcgi_cache_lock + stale-while-revalidate

2. **Memory leaks в PHP**
   - Решение: pm.max_requests = 1000 для recycling

3. **Session locking** при высокой нагрузке
   - Решение: Redis sessions + session_write_close()

---

## 📚 ДОПОЛНИТЕЛЬНЫЕ РЕСУРСЫ

### Документация

- [PHP-FPM Configuration](https://www.php.net/manual/en/install.fpm.configuration.php)
- [Nginx FastCGI Module](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html)
- [MySQL Performance Schema](https://dev.mysql.com/doc/refman/8.0/en/performance-schema.html)
- [Redis Best Practices](https://redis.io/docs/management/optimization/)

### Мониторинг

- [New Relic](https://newrelic.com/) - APM мониторинг
- [Datadog](https://www.datadoghq.com/) - Infrastructure monitoring
- [Grafana](https://grafana.com/) - Метрики и дашборды
- [Prometheus](https://prometheus.io/) - Time-series БД для метрик

---

## ✅ ЧЕКЛИСТ ВНЕДРЕНИЯ

### Pre-deployment

- [ ] Backup БД и кода
- [ ] Тестирование на staging environment
- [ ] Load testing с realistic traffic
- [ ] Rollback plan готов

### Deployment

- [ ] Deploy в maintenance window
- [ ] Постепенный rollout (10% → 50% → 100%)
- [ ] Мониторинг error rates
- [ ] Performance metrics tracking

### Post-deployment

- [ ] Verify KPI improvements
- [ ] Monitor for 48 hours
- [ ] Collect user feedback
- [ ] Document learnings

---

## 🎓 ВЫВОДЫ

Данная дорожная карта представляет комплексный подход к оптимизации производительности menu.labus.pro с фокусом на:

1. **Database optimization** - connection pooling, индексы, batch operations
2. **Caching strategy** - multi-tier caching с Redis, APCu, FastCGI cache
3. **PHP-FPM tuning** - оптимальные pm.* параметры для Beget
4. **Nginx configuration** - aggressive caching + microcache
5. **Frontend optimization** - critical CSS, Service Worker, image optimization
6. **Monitoring & testing** - real-time dashboards, automated load testing

**Ожидаемый результат:**
- +400-800% RPS к БД
- -70-80% latency (p95)
- -60% resource usage
- +100-300% throughput

**Рекомендуемая последовательность:**
1. Начать с Фазы 1 (DB optimizations) - наибольший impact
2. Затем Фаза 2 (PHP-FPM & Nginx) - инфраструктурные улучшения
3. Фаза 3 (Redis & Caching) - multiplier эффект
4. Остальные фазы - incremental improvements

**Время внедрения:** 6-8 недель при полной реализации.

---

**Документ подготовлен:** 10 февраля 2026  
**Версия:** 1.0.0  
**Автор:** AI Performance Consultant

---

*Данная дорожная карта основана на анализе текущего кода проекта menu.labus.pro и лучших практиках оптимизации производительности для стека PHP + MySQL + Nginx на shared hosting (Beget).*
