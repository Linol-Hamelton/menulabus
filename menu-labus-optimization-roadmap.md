# 🚀 ДОРОЖНАЯ КАРТА ОПТИМИЗАЦИИ ПРОЕКТА menu.labus.pro

**Дата создания:** 04 февраля 2026  
**Проект:** Система электронного меню для точек питания  
**Текущий стек:** Nginx (фронтенд) + Apache (бэкенд на порту 81) + PHP + MySQL + FastPanel на хостинге Beget  
**Цель:** Максимальное увеличение производительности, быстродействия и количества запросов к БД в секунду

---

## 📊 АНАЛИЗ ТЕКУЩЕГО СОСТОЯНИЯ ПРОЕКТА

### Архитектура
- **Reverse Proxy:** Nginx (443/80) → Apache (127.0.0.1:81)
- **Backend:** PHP с использованием PDO, Singleton паттерн для Database класса
- **База данных:** MySQL с InnoDB движком
- **Кэширование:** OPcache включен (256MB), Gzip/Deflate сжатие активно
- **Безопасность:** CSP headers, CSRF защита, session hardening

### Выявленные узкие места

#### 🔴 КРИТИЧЕСКИЕ
1. **Отсутствие кэширования FastCGI** - каждый запрос обрабатывается PHP
2. **Файловая система для сессий** - высокий I/O при большой нагрузке
3. **Отсутствие query cache на уровне приложения** - повторяющиеся запросы к БД
4. **Неоптимизированные настройки InnoDB** - buffer pool использует только 256MB (по умолчанию)
5. **Последовательная обработка запросов** - нет асинхронности для независимых операций

#### 🟡 ВАЖНЫЕ
1. **Отсутствие CDN** для статических ресурсов
2. **Неоптимальная конфигурация PHP-FPM** - не настроено connection pooling
3. **Большой размер сессионных данных** - можно минимизировать
4. **Отсутствие мониторинга производительности** - нет метрик для анализа bottleneck'ов

#### 🟢 ЖЕЛАТЕЛЬНЫЕ
1. **HTTP/3 (QUIC)** для дальнейшего снижения латентности
2. **Brotli компрессия** вместо/в дополнение к Gzip
3. **Lazy loading изображений** - отложенная загрузка
4. **Service Workers** для offline-режима (уже реализовано базово)

---

## 🎯 ДОРОЖНАЯ КАРТА: ПОЭТАПНЫЙ ПЛАН ОПТИМИЗАЦИИ

### 🏆 ФАЗА 1: БЫСТРЫЕ ПОБЕДЫ (1-3 дня, прирост до 300%)

#### 1.1 Внедрение Nginx FastCGI Cache (Microcache)
**Приоритет:** МАКСИМАЛЬНЫЙ  
**Ожидаемый прирост:** 200-400% для повторяющихся запросов  
**Сложность:** Средняя

**Реализация:**

```nginx
# Добавить в /etc/nginx/nginx.conf внутри http блока
fastcgi_cache_path /var/cache/nginx/fastcgi 
    levels=1:2 
    keys_zone=MENUCACHE:100m 
    max_size=1g 
    inactive=60m 
    use_temp_path=off;

fastcgi_cache_key "$scheme$request_method$host$request_uri$cookie_PHPSESSID";
fastcgi_cache_use_stale error timeout invalid_header updating http_500 http_503;
fastcgi_cache_background_update on;
fastcgi_cache_lock on;
fastcgi_cache_lock_timeout 5s;
```

**В конфиге сайта (nginx):**

```nginx
# Для динамических страниц (меню, заказы)
location ~ \.php$ {
    # Пропускаем кэш для авторизованных пользователей и POST запросов
    set $skip_cache 0;
    
    if ($request_method = POST) {
        set $skip_cache 1;
    }
    
    if ($http_cookie ~* "user_logged_in|wordpress_logged_in") {
        set $skip_cache 1;
    }
    
    # Для административных страниц
    if ($request_uri ~* "/admin-menu|/owner|/employee|/account") {
        set $skip_cache 1;
    }
    
    fastcgi_cache_bypass $skip_cache;
    fastcgi_no_cache $skip_cache;
    
    # Кэшируем только успешные ответы
    fastcgi_cache MENUCACHE;
    fastcgi_cache_valid 200 301 302 5m;  # 5 минут для меню
    fastcgi_cache_valid 404 1m;
    
    # Добавляем заголовок для отладки
    add_header X-Cache-Status $upstream_cache_status;
    
    proxy_pass http://127.0.0.1:81;
    include /etc/nginx/proxy_params;
}
```

**Очистка кэша при обновлении меню (добавить в PHP):**

```php
// В admin-menu.php после успешного обновления
function clearNginxCache() {
    // Используем специальный endpoint или команду
    exec('find /var/cache/nginx/fastcgi -type f -delete 2>/dev/null');
}
```

---

#### 1.2 Оптимизация OPcache
**Приоритет:** ВЫСОКИЙ  
**Ожидаемый прирост:** 20-40%  
**Сложность:** Низкая

**Текущие настройки (Apache config):**
```apache
opcache.max_accelerated_files = 7963
opcache.memory_consumption = 256
opcache.max_wasted_percentage = 10
opcache.enable = 1
```

**Рекомендуемые улучшения (добавить в php.ini или apache config):**

```ini
; Базовые настройки
opcache.enable=1
opcache.enable_cli=1

; Увеличиваем память до максимально доступной
opcache.memory_consumption=512

; Количество файлов - должно покрывать весь проект
opcache.max_accelerated_files=20000

; Строковый буфер
opcache.interned_strings_buffer=16

; Валидация файлов (для продакшена - отключить)
opcache.validate_timestamps=1
opcache.revalidate_freq=60

; Оптимизация
opcache.save_comments=0
opcache.enable_file_override=1
opcache.huge_code_pages=1

; Fast shutdown
opcache.fast_shutdown=1

; JIT компиляция (PHP 8.0+)
opcache.jit_buffer_size=100M
opcache.jit=1255
```

**Мониторинг OPcache (создать файл opcache-status.php):**

```php
<?php
require_once 'check-auth.php';
if ($_SESSION['user_role'] !== 'owner') {
    die('Access denied');
}

$status = opcache_get_status();
$config = opcache_get_configuration();

echo "<h2>OPcache Status</h2>";
echo "<p>Memory Usage: " . round($status['memory_usage']['used_memory'] / 1024 / 1024, 2) . " MB / " 
     . round($status['memory_usage']['free_memory'] / 1024 / 1024, 2) . " MB free</p>";
echo "<p>Hit Rate: " . round($status['opcache_statistics']['opcache_hit_rate'], 2) . "%</p>";
echo "<p>Cached Scripts: " . $status['opcache_statistics']['num_cached_scripts'] . " / " 
     . $config['directives']['opcache.max_accelerated_files'] . "</p>";
?>
```

---

#### 1.3 MySQL Query Cache на уровне приложения
**Приоритет:** ВЫСОКИЙ  
**Ожидаемый прирост:** 150-250% для часто запрашиваемых данных  
**Сложность:** Средняя

**Создать новый файл QueryCache.php:**

```php
<?php

class QueryCache {
    private static $cache = [];
    private static $ttl = 300; // 5 минут
    private static $memoryLimit = 10 * 1024 * 1024; // 10MB
    
    public static function get($key) {
        if (!isset(self::$cache[$key])) {
            return null;
        }
        
        $item = self::$cache[$key];
        
        // Проверка TTL
        if (time() > $item['expires']) {
            unset(self::$cache[$key]);
            return null;
        }
        
        return $item['data'];
    }
    
    public static function set($key, $data, $ttl = null) {
        $ttl = $ttl ?? self::$ttl;
        
        // Проверка лимита памяти
        if (self::getMemoryUsage() > self::$memoryLimit) {
            self::evictOldest();
        }
        
        self::$cache[$key] = [
            'data' => $data,
            'expires' => time() + $ttl,
            'created' => time()
        ];
    }
    
    public static function invalidate($pattern = null) {
        if ($pattern === null) {
            self::$cache = [];
            return;
        }
        
        foreach (self::$cache as $key => $item) {
            if (preg_match($pattern, $key)) {
                unset(self::$cache[$key]);
            }
        }
    }
    
    private static function getMemoryUsage() {
        return strlen(serialize(self::$cache));
    }
    
    private static function evictOldest() {
        uasort(self::$cache, function($a, $b) {
            return $a['created'] - $b['created'];
        });
        
        // Удаляем 20% старых записей
        $toRemove = max(1, count(self::$cache) * 0.2);
        self::$cache = array_slice(self::$cache, $toRemove, null, true);
    }
}
```

**Модификация db.php для использования кэша:**

```php
public function getMenuItems($category = null)
{
    $cacheKey = 'menu_items_' . ($category ?? 'all');
    
    // Проверяем кэш
    $cached = QueryCache::get($cacheKey);
    if ($cached !== null) {
        return $cached;
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
        
        // Кэшируем на 5 минут
        QueryCache::set($cacheKey, $result, 300);
        
        return $result;
    } catch (PDOException $e) {
        error_log("getMenuItems Error: " . $e->getMessage());
        return [];
    }
}

// После обновления меню - инвалидируем кэш
public function updateMenuItems(...) {
    $result = /* ... existing update logic ... */;
    
    if ($result) {
        QueryCache::invalidate('/^menu_items_/');
    }
    
    return $result;
}
```

---

#### 1.4 Оптимизация MySQL InnoDB
**Приоритет:** ВЫСОКИЙ  
**Ожидаемый прирост:** 100-200%  
**Сложность:** Низкая

**Определение оптимального размера InnoDB Buffer Pool:**

```bash
# Подключитесь по SSH и выполните
mysql -u root -p -e "SELECT CEILING(SUM(data_length+index_length)/1024/1024) AS 'DB Size (MB)' FROM information_schema.TABLES WHERE engine='InnoDB';"

# Узнать доступную RAM
free -h
```

**Рекомендуемые настройки (добавить в /etc/mysql/my.cnf или через FastPanel):**

```ini
[mysqld]
# InnoDB Buffer Pool - 60-70% от доступной RAM
# Для 4GB RAM рекомендуется 2.5-3GB
innodb_buffer_pool_size = 2G
innodb_buffer_pool_instances = 8
innodb_buffer_pool_chunk_size = 256M

# Оптимизация записи
innodb_log_file_size = 512M
innodb_log_buffer_size = 16M
innodb_flush_log_at_trx_commit = 2
innodb_flush_method = O_DIRECT

# Оптимизация чтения
innodb_read_io_threads = 4
innodb_write_io_threads = 4
innodb_io_capacity = 2000
innodb_io_capacity_max = 4000

# Кэширование
query_cache_type = 1
query_cache_size = 64M
query_cache_limit = 2M

# Временные таблицы
tmp_table_size = 64M
max_heap_table_size = 64M

# Connections
max_connections = 150
thread_cache_size = 50
table_open_cache = 4000
table_definition_cache = 2000
```

**Проверка эффективности:**

```sql
-- Проверка hit rate буфер пула
SHOW GLOBAL STATUS LIKE 'Innodb_buffer_pool_read%';

-- Должно быть >99%
SELECT 
    (1 - (Innodb_buffer_pool_reads / Innodb_buffer_pool_read_requests)) * 100 
    AS buffer_pool_hit_rate
FROM (
    SELECT 
        VARIABLE_VALUE AS Innodb_buffer_pool_reads 
    FROM performance_schema.global_status 
    WHERE VARIABLE_NAME = 'Innodb_buffer_pool_reads'
) reads,
(
    SELECT 
        VARIABLE_VALUE AS Innodb_buffer_pool_read_requests 
    FROM performance_schema.global_status 
    WHERE VARIABLE_NAME = 'Innodb_buffer_pool_read_requests'
) requests;
```

---

### 🚀 ФАЗА 2: СЕРЬЕЗНЫЕ УЛУЧШЕНИЯ (4-7 дней, прирост до 500%)

#### 2.1 Миграция сессий на Redis/Memcached
**Приоритет:** ВЫСОКИЙ  
**Ожидаемый прирост:** 300-500% при высокой нагрузке  
**Сложность:** Высокая

**Установка Redis на Beget (если доступно через SSH):**

```bash
# Обычно Redis уже установлен на Beget
# Проверка
redis-cli ping

# Если нет - использовать Memcached
# который точно есть на Beget
```

**Конфигурация PHP для Redis (session_init.php):**

```php
// В начале session_init.php, ДО session_start()

// Проверяем доступность Redis
if (extension_loaded('redis')) {
    ini_set('session.save_handler', 'redis');
    ini_set('session.save_path', 'tcp://127.0.0.1:6379?database=0');
    
    // Дополнительные настройки Redis
    ini_set('redis.session.locking_enabled', 1);
    ini_set('redis.session.lock_retries', -1);
    ini_set('redis.session.lock_wait_time', 10000); // 10ms
} 
// Fallback на Memcached если Redis недоступен
elseif (extension_loaded('memcached')) {
    ini_set('session.save_handler', 'memcached');
    ini_set('session.save_path', '127.0.0.1:11211');
    
    // Настройки Memcached
    ini_set('memcached.sess_binary_protocol', 1);
    ini_set('memcached.sess_consistent_hash', 1);
}
// Иначе остаемся на файлах
else {
    ini_set('session.save_handler', 'files');
    ini_set('session.save_path', '/var/www/labus_pro_usr/data/www/menu.labus.pro/data/tmp');
}
```

**Оптимизация размера сессии:**

```php
// Уменьшить данные в сессии - хранить только ID
// Вместо:
$_SESSION['user'] = $user; // весь массив

// Использовать:
$_SESSION['user_id'] = $user['id'];

// А данные получать из кэша при необходимости
function getCurrentUser() {
    static $user = null;
    
    if ($user === null && !empty($_SESSION['user_id'])) {
        $cacheKey = 'user_' . $_SESSION['user_id'];
        $user = QueryCache::get($cacheKey);
        
        if ($user === null) {
            $db = Database::getInstance();
            $user = $db->getUserById($_SESSION['user_id']);
            QueryCache::set($cacheKey, $user, 600);
        }
    }
    
    return $user;
}
```

---

#### 2.2 Внедрение Connection Pooling для БД
**Приоритет:** ВЫСОКИЙ  
**Ожидаемый прирост:** 50-100%  
**Сложность:** Средняя

**Модификация db.php для использования persistent connections:**

```php
private function connect()
{
    try {
        // Используем persistent connections
        $this->connection = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                
                // КРИТИЧЕСКИ ВАЖНО - персистентные соединения
                PDO::ATTR_PERSISTENT => true,
                
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false, // Для больших результатов
                PDO::MYSQL_ATTR_COMPRESS => true,
                
                // Connection timeout
                PDO::ATTR_TIMEOUT => 5,
            ]
        );
        
        // Устанавливаем SQL режим
        $this->connection->exec("SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
        $this->connection->exec("SET time_zone='+03:00'");
        
        // Оптимизация для конкретной сессии
        $this->connection->exec("SET SESSION query_cache_type = ON");
        $this->connection->exec("SET SESSION query_cache_size = 64M");
        
    } catch (PDOException $e) {
        error_log("DB Connection Error: " . $e->getMessage());
        header('HTTP/1.1 503 Service Unavailable');
        die("Ошибка подключения к базе данных. Пожалуйста, попробуйте позже.");
    }
}
```

---

#### 2.3 Оптимизация структуры БД
**Приоритет:** СРЕДНИЙ  
**Ожидаемый прирост:** 50-150%  
**Сложность:** Высокая

**Анализ текущей структуры и добавление индексов:**

```sql
-- 1. Анализ медленных запросов
-- Включить slow query log в MySQL
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 0.5; -- запросы > 0.5 сек
SET GLOBAL log_queries_not_using_indexes = 'ON';

-- 2. Проверка существующих индексов
SHOW INDEX FROM menu_items;
SHOW INDEX FROM orders;
SHOW INDEX FROM users;

-- 3. Добавление недостающих индексов
-- Для menu_items
ALTER TABLE menu_items ADD INDEX idx_category_available (category, available);
ALTER TABLE menu_items ADD INDEX idx_available (available);

-- Для orders
ALTER TABLE orders ADD INDEX idx_user_created (user_id, created_at DESC);
ALTER TABLE orders ADD INDEX idx_status_created (status, created_at DESC);
ALTER TABLE orders ADD INDEX idx_updated (updated_at);

-- Для users
ALTER TABLE users ADD INDEX idx_email (email);
ALTER TABLE users ADD INDEX idx_role_active (role, is_active);

-- Для auth_tokens
ALTER TABLE auth_tokens ADD INDEX idx_selector_expires (selector, expires_at);

-- Для order_status_history
ALTER TABLE order_status_history ADD INDEX idx_order_changed (order_id, changed_at DESC);

-- 4. Анализ и оптимизация таблиц
ANALYZE TABLE menu_items, orders, users, auth_tokens, order_status_history;
OPTIMIZE TABLE menu_items, orders, users, auth_tokens, order_status_history;
```

**Оптимизация JSON-полей в orders:**

```sql
-- Для эффективной работы с JSON нужны generated columns
ALTER TABLE orders ADD COLUMN items_count INT GENERATED ALWAYS AS (
    JSON_LENGTH(items)
) STORED;

ALTER TABLE orders ADD INDEX idx_items_count (items_count);

-- Теперь можно быстро искать заказы по количеству товаров
SELECT * FROM orders WHERE items_count > 5;
```

---

#### 2.4 Асинхронная обработка и очереди
**Приоритет:** СРЕДНИЙ  
**Ожидаемый прирост:** 200-300% для тяжелых операций  
**Сложность:** Очень высокая

**Создать систему очередей для тяжелых операций:**

```php
// Файл: Queue.php
class Queue {
    private $queueFile;
    
    public function __construct($queueName = 'default') {
        $this->queueFile = __DIR__ . "/data/queues/{$queueName}.queue";
    }
    
    public function push($job, $data) {
        $item = [
            'id' => uniqid('job_', true),
            'job' => $job,
            'data' => $data,
            'created_at' => time(),
            'attempts' => 0
        ];
        
        file_put_contents(
            $this->queueFile, 
            json_encode($item) . PHP_EOL, 
            FILE_APPEND | LOCK_EX
        );
        
        return $item['id'];
    }
    
    public function pop() {
        if (!file_exists($this->queueFile)) {
            return null;
        }
        
        $fp = fopen($this->queueFile, 'r+');
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return null;
        }
        
        $lines = [];
        $job = null;
        
        while (($line = fgets($fp)) !== false) {
            if ($job === null && trim($line) !== '') {
                $job = json_decode(trim($line), true);
            } else {
                $lines[] = $line;
            }
        }
        
        // Перезаписываем файл без первой задачи
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, implode('', $lines));
        
        flock($fp, LOCK_UN);
        fclose($fp);
        
        return $job;
    }
}

// Пример использования - отправка email
// Вместо синхронной отправки:
// sendEmail($to, $subject, $body);

// Асинхронная отправка:
$queue = new Queue('emails');
$queue->push('send_email', [
    'to' => $to,
    'subject' => $subject,
    'body' => $body
]);

// Создать worker: worker.php
while (true) {
    $queue = new Queue('emails');
    $job = $queue->pop();
    
    if ($job !== null) {
        try {
            switch ($job['job']) {
                case 'send_email':
                    sendEmail(
                        $job['data']['to'],
                        $job['data']['subject'],
                        $job['data']['body']
                    );
                    break;
            }
        } catch (Exception $e) {
            error_log("Job failed: " . $e->getMessage());
        }
    } else {
        sleep(1); // Ожидание новых задач
    }
}

// Запуск worker через cron каждую минуту
// */1 * * * * php /path/to/worker.php > /dev/null 2>&1 &
```

---

### ⚡ ФАЗА 3: ПРОДВИНУТЫЕ ТЕХНИКИ (8-14 дней, прирост до 800%)

#### 3.1 HTTP/2 Server Push и Preload
**Приоритет:** СРЕДНИЙ  
**Ожидаемый прирост:** 30-60% для первой загрузки  
**Сложность:** Средняя

**Модификация Nginx конфига:**

```nginx
server {
    listen 443 ssl http2;
    server_name menu.labus.pro;
    
    # HTTP/2 Server Push для критических ресурсов
    location = /menu.php {
        proxy_pass http://127.0.0.1:81;
        
        # Push критических ресурсов
        http2_push /css/main.css;
        http2_push /js/app.js;
        http2_push /manifest.json;
        
        add_header Link "</css/main.css>; rel=preload; as=style";
        add_header Link "</js/app.js>; rel=preload; as=script";
        add_header Link "</fonts/main.woff2>; rel=preload; as=font; crossorigin";
    }
}
```

**В HTML добавить preconnect и dns-prefetch:**

```php
<!-- В header.php -->
<head>
    <!-- Критичные preload -->
    <link rel="preload" href="/css/main.css" as="style">
    <link rel="preload" href="/js/app.js" as="script">
    <link rel="preload" href="/fonts/main.woff2" as="font" type="font/woff2" crossorigin>
    
    <!-- DNS prefetch для внешних ресурсов -->
    <link rel="dns-prefetch" href="//nominatim.openstreetmap.org">
    
    <!-- Preconnect для критичных внешних ресурсов -->
    <link rel="preconnect" href="https://nominatim.openstreetmap.org" crossorigin>
    
    <!-- Остальной content -->
</head>
```

---

#### 3.2 Brotli Compression
**Приоритет:** НИЗКИЙ  
**Ожидаемый прирост:** 15-25% размер передаваемых данных  
**Сложность:** Средняя

**Проверка наличия модуля Brotli в Nginx:**

```bash
nginx -V 2>&1 | grep brotli
```

**Если доступен, добавить в Nginx конфиг:**

```nginx
# В nginx.conf или в конфиге сайта
http {
    # Brotli сжатие
    brotli on;
    brotli_comp_level 6;
    brotli_types text/plain text/css text/xml text/javascript 
                 application/javascript application/json application/xml 
                 image/svg+xml application/x-font-ttf font/opentype;
    
    # Статическое brotli сжатие (предсжатые файлы)
    brotli_static on;
}
```

**Предкомпиляция статических файлов:**

```bash
# Создать скрипт compress-static.sh
#!/bin/bash

find /var/www/labus_pro_usr/data/www/menu.labus.pro -type f \
    \( -name "*.css" -o -name "*.js" -o -name "*.svg" -o -name "*.json" \) \
    -exec brotli -q 11 {} \;

find /var/www/labus_pro_usr/data/www/menu.labus.pro -type f \
    \( -name "*.css" -o -name "*.js" -o -name "*.svg" -o -name "*.json" \) \
    -exec gzip -9 -k {} \;
```

---

#### 3.3 Продвинутая минификация и объединение ресурсов
**Приоритет:** СРЕДНИЙ  
**Ожидаемый прирост:** 20-40% время загрузки  
**Сложность:** Средняя

**Создать систему asset pipeline:**

```php
// Файл: AssetPipeline.php
class AssetPipeline {
    private static $manifest = null;
    private static $manifestFile = __DIR__ . '/public/manifest.json';
    
    public static function asset($path) {
        if (self::$manifest === null) {
            self::loadManifest();
        }
        
        // В production возвращаем версионированный файл
        if (isset(self::$manifest[$path])) {
            return self::$manifest[$path];
        }
        
        return $path;
    }
    
    private static function loadManifest() {
        if (file_exists(self::$manifestFile)) {
            self::$manifest = json_decode(
                file_get_contents(self::$manifestFile), 
                true
            ) ?? [];
        } else {
            self::$manifest = [];
        }
    }
    
    public static function build() {
        $manifest = [];
        
        // CSS
        $cssFiles = glob(__DIR__ . '/css/*.css');
        $cssContent = '';
        foreach ($cssFiles as $file) {
            $cssContent .= file_get_contents($file) . "\n";
        }
        
        // Минификация CSS (простая)
        $cssContent = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $cssContent);
        $cssContent = preg_replace('/\s+/', ' ', $cssContent);
        
        $cssHash = md5($cssContent);
        $cssFile = "css/app.{$cssHash}.min.css";
        file_put_contents(__DIR__ . '/public/' . $cssFile, $cssContent);
        
        $manifest['css/app.css'] = '/' . $cssFile;
        
        // JS
        $jsFiles = glob(__DIR__ . '/js/*.js');
        $jsContent = '';
        foreach ($jsFiles as $file) {
            $jsContent .= file_get_contents($file) . ";\n";
        }
        
        // Базовая минификация JS
        $jsContent = preg_replace('!/\*.*?\*/!s', '', $jsContent);
        $jsContent = preg_replace('/\s+/', ' ', $jsContent);
        
        $jsHash = md5($jsContent);
        $jsFile = "js/app.{$jsHash}.min.js";
        file_put_contents(__DIR__ . '/public/' . $jsFile, $jsContent);
        
        $manifest['js/app.js'] = '/' . $jsFile;
        
        file_put_contents(
            self::$manifestFile, 
            json_encode($manifest, JSON_PRETTY_PRINT)
        );
    }
}

// В HTML:
<link rel="stylesheet" href="<?= AssetPipeline::asset('css/app.css') ?>">
<script src="<?= AssetPipeline::asset('js/app.js') ?>" defer></script>
```

---

#### 3.4 Оптимизация изображений и lazy loading
**Приоритет:** ВЫСОКИЙ  
**Ожидаемый прирост:** 40-60% для страниц с изображениями  
**Сложность:** Средняя

**Автоматическая конвертация в WebP:**

```php
// Файл: ImageOptimizer.php
class ImageOptimizer {
    private $quality = 85;
    private $webpQuality = 80;
    
    public function optimize($imagePath) {
        $info = getimagesize($imagePath);
        $type = $info[2];
        
        $image = match($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($imagePath),
            IMAGETYPE_PNG => imagecreatefrompng($imagePath),
            IMAGETYPE_GIF => imagecreatefromgif($imagePath),
            default => throw new Exception('Unsupported image type')
        };
        
        // Создаем WebP версию
        $webpPath = preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $imagePath);
        imagewebp($image, $webpPath, $this->webpQuality);
        
        // Оптимизируем оригинал
        match($type) {
            IMAGETYPE_JPEG => imagejpeg($image, $imagePath, $this->quality),
            IMAGETYPE_PNG => $this->optimizePng($image, $imagePath),
            default => null
        };
        
        imagedestroy($image);
        
        return [
            'original' => $imagePath,
            'webp' => $webpPath
        ];
    }
    
    private function optimizePng($image, $path) {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagepng($image, $path, 9);
    }
}

// В file-manager.php при загрузке файла
$optimizer = new ImageOptimizer();
$result = $optimizer->optimize($uploadedFilePath);
```

**Responsive images в HTML:**

```php
<!-- Вместо: -->
<img src="/image/dish.jpg" alt="Dish">

<!-- Использовать: -->
<picture>
    <source 
        type="image/webp" 
        srcset="/image/dish-400.webp 400w,
                /image/dish-800.webp 800w,
                /image/dish-1200.webp 1200w"
        sizes="(max-width: 600px) 400px,
               (max-width: 1200px) 800px,
               1200px">
    <img 
        src="/image/dish-800.jpg" 
        srcset="/image/dish-400.jpg 400w,
                /image/dish-800.jpg 800w,
                /image/dish-1200.jpg 1200w"
        sizes="(max-width: 600px) 400px,
               (max-width: 1200px) 800px,
               1200px"
        alt="Dish"
        loading="lazy"
        decoding="async">
</picture>
```

**Intersection Observer для lazy loading:**

```javascript
// В app.js
document.addEventListener('DOMContentLoaded', () => {
    const lazyImages = document.querySelectorAll('img[loading="lazy"]');
    
    if ('IntersectionObserver' in window) {
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    img.src = img.dataset.src || img.src;
                    img.classList.add('loaded');
                    observer.unobserve(img);
                }
            });
        }, {
            rootMargin: '50px 0px',
            threshold: 0.01
        });
        
        lazyImages.forEach(img => imageObserver.observe(img));
    }
});
```

---

### 🔥 ФАЗА 4: РЕВОЛЮЦИОННЫЕ ПРОРЫВЫ (15-30 дней, прирост до 1000%)

#### 4.1 Внедрение CDN
**Приоритет:** ВЫСОКИЙ  
**Ожидаемый прирост:** 200-400% для статики  
**Сложность:** Средняя

**Рекомендуемые CDN провайдеры для России:**

1. **CloudFlare** (бесплатный тариф доступен)
2. **Selectel CDN** (российский)
3. **Gcore** (российский)

**Конфигурация для CloudFlare:**

```nginx
# Добавить в nginx конфиг
# Получаем реальный IP посетителя
set_real_ip_from 103.21.244.0/22;
set_real_ip_from 103.22.200.0/22;
set_real_ip_from 103.31.4.0/22;
# ... другие IP CloudFlare
real_ip_header CF-Connecting-IP;

# Оптимизация кэширования для CloudFlare
location ~* \.(jpg|jpeg|png|gif|webp|svg|ico|css|js|woff|woff2|ttf|eot)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
    add_header CDN-Cache-Control "public, max-age=31536000";
}
```

**Page Rules в CloudFlare:**
1. `menu.labus.pro/image/*` - Cache Everything, Edge TTL 1 month
2. `menu.labus.pro/css/*` - Cache Everything, Edge TTL 1 month
3. `menu.labus.pro/js/*` - Cache Everything, Edge TTL 1 month

---

#### 4.2 Database Sharding для масштабирования
**Приоритет:** НИЗКИЙ (только при > 1M записей)  
**Ожидаемый прирост:** 300-500% при масштабе  
**Сложность:** Очень высокая

**Концепция вертикального шардинга:**

```php
// DatabaseRouter.php
class DatabaseRouter {
    private $connections = [];
    
    public function getConnection($table) {
        // Маршрутизация по таблицам
        $shard = match($table) {
            'orders', 'order_status_history' => 'orders_db',
            'menu_items' => 'menu_db',
            'users', 'auth_tokens' => 'users_db',
            default => 'main_db'
        };
        
        if (!isset($this->connections[$shard])) {
            $this->connections[$shard] = $this->createConnection($shard);
        }
        
        return $this->connections[$shard];
    }
    
    private function createConnection($shard) {
        $configs = [
            'main_db' => ['host' => 'localhost', 'db' => 'main'],
            'orders_db' => ['host' => 'localhost', 'db' => 'orders'],
            'menu_db' => ['host' => 'localhost', 'db' => 'menu'],
            'users_db' => ['host' => 'localhost', 'db' => 'users']
        ];
        
        $config = $configs[$shard];
        return new PDO(
            "mysql:host={$config['host']};dbname={$config['db']};charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_PERSISTENT => true]
        );
    }
}
```

**Примечание:** Sharding требуется только при очень больших объемах данных (миллионы записей) и должен реализовываться очень осторожно.

---

#### 4.3 GraphQL для оптимизации API запросов
**Приоритет:** СРЕДНИЙ  
**Ожидаемый прирост:** 100-200% для сложных запросов  
**Сложность:** Очень высокая

**Базовая имплементация GraphQL (используя библиотеку webonyx/graphql-php):**

```php
// api/graphql.php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../db.php';

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\GraphQL as GraphQLBase;

$menuItemType = new ObjectType([
    'name' => 'MenuItem',
    'fields' => [
        'id' => Type::int(),
        'name' => Type::string(),
        'description' => Type::string(),
        'price' => Type::float(),
        'category' => Type::string(),
        'image' => Type::string(),
        'available' => Type::boolean(),
    ]
]);

$queryType = new ObjectType([
    'name' => 'Query',
    'fields' => [
        'menuItems' => [
            'type' => Type::listOf($menuItemType),
            'args' => [
                'category' => Type::string(),
            ],
            'resolve' => function ($root, $args) {
                $db = Database::getInstance();
                return $db->getMenuItems($args['category'] ?? null);
            }
        ],
    ]
]);

$schema = new Schema([
    'query' => $queryType
]);

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
$query = $input['query'];
$variableValues = isset($input['variables']) ? $input['variables'] : null;

try {
    $result = GraphQLBase::executeQuery($schema, $query, null, null, $variableValues);
    $output = $result->toArray();
} catch (\Exception $e) {
    $output = [
        'errors' => [
            ['message' => $e->getMessage()]
        ]
    ];
}

header('Content-Type: application/json');
echo json_encode($output);
```

**Использование на фронтенде:**

```javascript
async function fetchMenu(category = null) {
    const query = `
        query GetMenu($category: String) {
            menuItems(category: $category) {
                id
                name
                description
                price
                image
            }
        }
    `;
    
    const response = await fetch('/api/graphql.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            query,
            variables: { category }
        })
    });
    
    const data = await response.json();
    return data.data.menuItems;
}
```

---

#### 4.4 WebAssembly для критичных вычислений
**Приоритет:** ОЧЕНЬ НИЗКИЙ  
**Ожидаемый прирост:** 500-1000% для специфичных алгоритмов  
**Сложность:** Экстремальная

**Пример использования WASM для сложных расчетов (например, расчет доставки):**

```javascript
// Компиляция С++ в WASM (локально)
// delivery-calculator.cpp
#include <emscripten/emscripten.h>
#include <cmath>

extern "C" {
    EMSCRIPTEN_KEEPALIVE
    double calculateDeliveryPrice(double distance, double orderTotal) {
        double basePrice = 100.0;
        double pricePerKm = 20.0;
        double discount = orderTotal > 1000.0 ? 0.5 : 1.0;
        
        return (basePrice + distance * pricePerKm) * discount;
    }
}

// Компиляция:
// emcc delivery-calculator.cpp -o delivery-calculator.js -s WASM=1 -s EXPORTED_FUNCTIONS="['_calculateDeliveryPrice']"

// Использование в JS
async function initWasm() {
    const wasmModule = await WebAssembly.instantiateStreaming(
        fetch('/wasm/delivery-calculator.wasm')
    );
    
    return wasmModule.instance.exports;
}

let wasmExports;
initWasm().then(exports => {
    wasmExports = exports;
});

function calculateDelivery(distance, orderTotal) {
    if (wasmExports) {
        return wasmExports.calculateDeliveryPrice(distance, orderTotal);
    } else {
        // Fallback на JS
        return (100 + distance * 20) * (orderTotal > 1000 ? 0.5 : 1);
    }
}
```

---

## 📈 МОНИТОРИНГ И МЕТРИКИ

### Инструменты мониторинга

#### 1. Performance Monitoring Dashboard
**Создать файл: monitor.php**

```php
<?php
require_once 'check-auth.php';
if ($_SESSION['user_role'] !== 'owner') {
    die('Access denied');
}

// Получение метрик
$metrics = [
    'server' => [
        'load' => sys_getloadavg(),
        'memory' => [
            'used' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => ini_get('memory_limit')
        ],
        'uptime' => shell_exec('uptime -p')
    ],
    
    'php' => [
        'version' => PHP_VERSION,
        'opcache' => opcache_get_status(),
        'sessions' => [
            'handler' => ini_get('session.save_handler'),
            'path' => ini_get('session.save_path')
        ]
    ],
    
    'nginx' => [
        'cache_status' => file_exists('/var/cache/nginx/fastcgi') ? 
            'enabled' : 'disabled',
        'cache_size' => shell_exec('du -sh /var/cache/nginx/fastcgi 2>/dev/null')
    ],
    
    'database' => [
        'connections' => Database::getInstance()->scalar(
            "SHOW STATUS LIKE 'Threads_connected'"
        ),
        'buffer_pool_hit_rate' => Database::getInstance()->scalar(
            "SELECT (1 - (
                SELECT VARIABLE_VALUE FROM performance_schema.global_status 
                WHERE VARIABLE_NAME = 'Innodb_buffer_pool_reads'
            ) / (
                SELECT VARIABLE_VALUE FROM performance_schema.global_status 
                WHERE VARIABLE_NAME = 'Innodb_buffer_pool_read_requests'
            )) * 100"
        ),
        'slow_queries' => Database::getInstance()->scalar(
            "SHOW STATUS LIKE 'Slow_queries'"
        )
    ],
    
    'application' => [
        'query_cache_hit_rate' => QueryCache::getHitRate(),
        'total_requests' => $_SESSION['total_requests'] ?? 0,
        'avg_response_time' => $_SESSION['avg_response_time'] ?? 0
    ]
];

header('Content-Type: application/json');
echo json_encode($metrics, JSON_PRETTY_PRINT);
?>
```

#### 2. Real-time Monitoring с New Relic (бесплатный тариф)

```php
// Добавить в начало каждой страницы (в session_init.php)
if (extension_loaded('newrelic')) {
    newrelic_set_appname('MenuLabus');
    newrelic_name_transaction(basename($_SERVER['PHP_SELF'], '.php'));
    
    // Кастомные метрики
    if (defined('QUERY_TIME')) {
        newrelic_custom_metric('Custom/QueryTime', QUERY_TIME);
    }
}
```

#### 3. Custom Performance Logger

```php
// PerformanceLogger.php
class PerformanceLogger {
    private static $startTime;
    private static $markers = [];
    
    public static function start() {
        self::$startTime = microtime(true);
        self::$markers = [];
    }
    
    public static function mark($label) {
        self::$markers[$label] = microtime(true) - self::$startTime;
    }
    
    public static function end() {
        $totalTime = microtime(true) - self::$startTime;
        
        $log = [
            'timestamp' => date('Y-m-d H:i:s'),
            'url' => $_SERVER['REQUEST_URI'],
            'total_time' => round($totalTime * 1000, 2) . 'ms',
            'memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . 'MB',
            'markers' => array_map(function($time) {
                return round($time * 1000, 2) . 'ms';
            }, self::$markers)
        ];
        
        // Логируем только медленные запросы (> 200ms)
        if ($totalTime > 0.2) {
            error_log('SLOW_REQUEST: ' . json_encode($log));
        }
        
        return $log;
    }
}

// Использование в каждом файле
PerformanceLogger::start();

// ... код страницы ...

PerformanceLogger::mark('DB queries');
// ... запросы к БД ...

PerformanceLogger::mark('Rendering');
// ... рендеринг ...

$metrics = PerformanceLogger::end();
```

---

## 🧪 НАГРУЗОЧНОЕ ТЕСТИРОВАНИЕ

### Инструменты

1. **Apache Bench (ab)** - простой инструмент командной строки
2. **Siege** - более продвинутый вариант
3. **k6** - современный инструмент с JavaScript API
4. **Locust** - Python-based, с веб-интерфейсом

### Безопасное нагрузочное тестирование

**Сценарий 1: Тестирование главной страницы**

```bash
# Apache Bench - 1000 запросов, 50 одновременных
ab -n 1000 -c 50 https://menu.labus.pro/

# Siege - постепенное увеличение нагрузки
siege -c 10 -t 1M https://menu.labus.pro/
siege -c 25 -t 1M https://menu.labus.pro/
siege -c 50 -t 1M https://menu.labus.pro/
```

**Сценарий 2: Тестирование меню**

```bash
# k6 скрипт (menu-test.js)
import http from 'k6/http';
import { check, sleep } from 'k6';

export let options = {
    stages: [
        { duration: '2m', target: 10 },  // Разогрев
        { duration: '5m', target: 50 },  // Рост до 50 пользователей
        { duration: '2m', target: 100 }, // Пик 100 пользователей
        { duration: '5m', target: 50 },  // Снижение
        { duration: '2m', target: 0 },   // Завершение
    ],
    thresholds: {
        http_req_duration: ['p(95)<500'], // 95% запросов < 500ms
        http_req_failed: ['rate<0.01'],   // < 1% ошибок
    },
};

export default function () {
    // Тест получения меню
    let res = http.get('https://menu.labus.pro/menu.php');
    check(res, {
        'status is 200': (r) => r.status === 200,
        'response time < 500ms': (r) => r.timings.duration < 500,
    });
    
    sleep(1);
    
    // Тест получения конкретной категории
    res = http.get('https://menu.labus.pro/menu-content.php?category=Салаты');
    check(res, {
        'category loaded': (r) => r.status === 200,
    });
    
    sleep(2);
}

# Запуск:
# k6 run menu-test.js
```

**Сценарий 3: Стресс-тест БД**

```bash
# Locust скрипт (locustfile.py)
from locust import HttpUser, task, between

class MenuUser(HttpUser):
    wait_time = between(1, 3)
    
    @task(3)  # Вес задачи - 3 (чаще выполняется)
    def view_menu(self):
        self.client.get("/menu.php")
    
    @task(2)
    def view_category(self):
        categories = ["Салаты", "Горячие блюда", "Напитки", "Десерты"]
        category = random.choice(categories)
        self.client.get(f"/menu-content.php?category={category}")
    
    @task(1)
    def view_dish(self):
        dish_id = random.randint(1, 100)
        self.client.get(f"/menu-content-info.php?id={dish_id}")

# Запуск:
# locust -f locustfile.py --host=https://menu.labus.pro
# Открыть http://localhost:8089 для управления
```

### Мониторинг во время тестов

```bash
# Терминал 1: Мониторинг нагрузки сервера
watch -n 1 'uptime; free -h'

# Терминал 2: Мониторинг MySQL
watch -n 1 'mysql -e "SHOW PROCESSLIST; SHOW STATUS LIKE \"Threads%\""'

# Терминал 3: Мониторинг Nginx/Apache
tail -f /var/www/labus_pro_usr/data/logs/menu.labus.pro-*.log

# Терминал 4: Мониторинг PHP-FPM
watch -n 1 'ps aux | grep php-fpm | wc -l'
```

### Анализ результатов

**Ключевые метрики:**

1. **Response Time** (время отклика)
   - Target: < 200ms для 95% запросов
   - Acceptable: < 500ms для 99% запросов

2. **Throughput** (пропускная способность)
   - Target: > 100 requests/sec
   - Good: > 500 requests/sec

3. **Error Rate** (частота ошибок)
   - Target: < 0.1%
   - Acceptable: < 1%

4. **Concurrency** (одновременные пользователи)
   - Target: 100+ без деградации
   - Good: 500+ с минимальной деградацией

---

## 🔒 БЕЗОПАСНОСТЬ ПРИ ОПТИМИЗАЦИИ

### Важные соображения

1. **Кэширование НЕ должно кэшировать**:
   - Приватные данные пользователей
   - Корзину заказов
   - Административные страницы
   - CSRF токены

2. **Redis/Memcached безопасность**:
   - Использовать password protection
   - Bind только на localhost
   - Firewall правила

3. **Мониторинг должен быть защищен**:
   - Только для owner роли
   - IP whitelist для доступа
   - Rate limiting

---

## 📊 ОЖИДАЕМЫЕ РЕЗУЛЬТАТЫ

### До оптимизации (предполагаемые метрики)
- **TTFB (Time To First Byte):** 400-800ms
- **Page Load Time:** 2-4s
- **Database Queries/Request:** 15-30
- **Peak Concurrent Users:** 50-100
- **Requests/Second:** 20-50

### После Фазы 1 (FastCGI Cache + OPcache + InnoDB)
- **TTFB:** 50-150ms (↓ 70-80%)
- **Page Load Time:** 0.8-1.5s (↓ 60%)
- **Database Queries/Request:** 5-10 (↓ 65%)
- **Peak Concurrent Users:** 200-300 (↑ 200%)
- **Requests/Second:** 100-200 (↑ 300%)

### После Фазы 2 (Redis Sessions + Connection Pool + Query Cache)
- **TTFB:** 30-80ms (↓ 85-90%)
- **Page Load Time:** 0.5-1.0s (↓ 75%)
- **Database Queries/Request:** 2-5 (↓ 85%)
- **Peak Concurrent Users:** 500-800 (↑ 600%)
- **Requests/Second:** 300-500 (↑ 900%)

### После Фазы 3 (HTTP/2 Push + Brotli + Assets Optimization)
- **TTFB:** 20-60ms (↓ 90-95%)
- **Page Load Time:** 0.3-0.7s (↓ 85%)
- **Database Queries/Request:** 1-3 (↓ 90%)
- **Peak Concurrent Users:** 800-1500 (↑ 1200%)
- **Requests/Second:** 500-800 (↑ 1500%)

### После Фазы 4 (CDN + Advanced Techniques)
- **TTFB:** 10-30ms (↓ 95-98%)
- **Page Load Time:** 0.2-0.4s (↓ 90%)
- **Database Queries/Request:** 0-2 (↓ 95%)
- **Peak Concurrent Users:** 2000-5000 (↑ 3000%)
- **Requests/Second:** 1000-2000 (↑ 4000%)

---

## ⚡ БЫСТРАЯ ДИАГНОСТИКА ПРОБЛЕМ

### Checklist проблем производительности

```bash
# 1. Проверка OPcache
php -i | grep opcache
# Если opcache.enable=0 - включить!

# 2. Проверка FastCGI cache
curl -I https://menu.labus.pro/ | grep X-Cache-Status
# Должно быть HIT для повторных запросов

# 3. Проверка InnoDB Buffer Pool
mysql -e "SELECT (1 - (
    SELECT VARIABLE_VALUE FROM performance_schema.global_status 
    WHERE VARIABLE_NAME = 'Innodb_buffer_pool_reads'
) / (
    SELECT VARIABLE_VALUE FROM performance_schema.global_status 
    WHERE VARIABLE_NAME = 'Innodb_buffer_pool_read_requests'
)) * 100 AS hit_rate;"
# Должно быть > 99%

# 4. Проверка slow queries
mysql -e "SHOW STATUS LIKE 'Slow_queries';"
# Должно быть минимальным

# 5. Проверка соединений с БД
mysql -e "SHOW STATUS LIKE 'Threads_connected';"
# Не должно превышать max_connections

# 6. Проверка сжатия
curl -H "Accept-Encoding: gzip,deflate,br" -I https://menu.labus.pro/ | grep -i "content-encoding"
# Должно быть gzip или br (brotli)

# 7. Проверка HTTP/2
curl -I --http2 https://menu.labus.pro/ | grep -i "http/2"
# Должно показывать HTTP/2

# 8. Размер страницы
curl -so /dev/null -w '%{size_download}\n' https://menu.labus.pro/
# Сравнить до/после оптимизации
```

---

## 🎓 ОБУЧЕНИЕ КОМАНДЫ

### Документация для разработчиков

**Создать файл: PERFORMANCE.md в репозитории**

```markdown
# Performance Guidelines для Menu.Labus.Pro

## Правила разработки

### 1. Всегда используйте кэширование
- Для часто запрашиваемых данных используйте QueryCache
- Для статических данных используйте длительное кэширование
- Для сессионных данных используйте Redis

### 2. Оптимизируйте запросы к БД
- Используйте EXPLAIN для анализа
- Добавляйте индексы для часто используемых WHERE/JOIN
- Избегайте N+1 проблемы

### 3. Минимизируйте размер сессии
- Храните только ID, не полные объекты
- Используйте кэш для данных пользователя

### 4. Асинхронные операции
- Отправка email через очереди
- Генерация отчетов в фоне
- Обработка изображений асинхронно

### 5. Мониторинг
- Используйте PerformanceLogger для критичных операций
- Проверяйте медленные запросы еженедельно
- Настройте алерты для критичных метрик
```

---

## 📅 ГРАФИК РЕАЛИЗАЦИИ

### Недели 1-2: Фаза 1 (Быстрые победы)
- **День 1-2:** Внедрение FastCGI Cache
- **День 3:** Оптимизация OPcache
- **День 4-5:** Внедрение Query Cache на уровне приложения
- **День 6-7:** Оптимизация MySQL InnoDB
- **День 8-14:** Тестирование, мониторинг, документирование

### Недели 3-4: Фаза 2 (Серьезные улучшения)
- **День 15-17:** Миграция сессий на Redis/Memcached
- **День 18-19:** Connection Pooling для БД
- **День 20-22:** Оптимизация структуры БД и индексов
- **День 23-28:** Внедрение очередей, тестирование

### Недели 5-6: Фаза 3 (Продвинутые техники)
- **День 29-31:** HTTP/2 Server Push и Preload
- **День 32-33:** Brotli Compression
- **День 34-36:** Asset Pipeline и минификация
- **День 37-42:** Оптимизация изображений, тестирование

### Недели 7-8: Фаза 4 (Революционные прорывы)
- **День 43-46:** Внедрение CDN
- **День 47-49:** GraphQL API (опционально)
- **День 50-56:** Финальное тестирование, оптимизация, документация

### Неделя 9: Финал
- Комплексное нагрузочное тестирование
- Анализ метрик до/после
- Финальная документация
- Обучение команды

---

## 🎯 КРИТЕРИИ УСПЕХА

### KPI проекта оптимизации

1. **Page Load Time < 500ms** для 95% запросов
2. **TTFB < 100ms** для кэшированных страниц
3. **Database Queries/Request < 5** в среднем
4. **Peak Concurrent Users > 500** без деградации
5. **Requests/Second > 300** устойчиво
6. **Error Rate < 0.5%** под нагрузкой
7. **Buffer Pool Hit Rate > 99%**
8. **OPcache Hit Rate > 95%**

---

## 🔧 ИНСТРУМЕНТЫ И РЕСУРСЫ

### Обязательные инструменты

1. **GTmetrix** - анализ скорости загрузки
2. **WebPageTest** - детальный анализ производительности
3. **Chrome DevTools** - Performance tab
4. **New Relic** (free tier) - APM мониторинг
5. **Adminer/phpMyAdmin** - управление БД
6. **Redis Commander** - управление Redis
7. **Nginx Amplify** - мониторинг Nginx

### Полезные ресурсы

1. **web.dev** - гайды по производительности от Google
2. **MySQL Performance Blog** от Percona
3. **Nginx Blog** - официальные best practices
4. **PHP The Right Way** - современные подходы PHP
5. **High Performance Browser Networking** - книга O'Reilly

---

## ✅ ЧЕКЛИСТ ПЕРЕД PRODUCTION

### Pre-Deployment Checklist

- [ ] OPcache настроен и работает
- [ ] FastCGI cache активен для публичных страниц
- [ ] InnoDB Buffer Pool оптимизирован
- [ ] Redis/Memcached для сессий работает
- [ ] Все индексы созданы
- [ ] Slow query log включен
- [ ] Мониторинг настроен
- [ ] Backup стратегия обновлена
- [ ] Нагрузочное тестирование пройдено
- [ ] Документация обновлена
- [ ] Команда обучена
- [ ] Rollback plan готов

---

## 🚨 ПЛАН ОТКАТА (ROLLBACK)

### Если что-то пошло не так

```bash
# 1. Отключение FastCGI Cache
# В nginx конфиге закомментировать:
# fastcgi_cache MENUCACHE;

# 2. Откат Redis сессий
# В session_init.php вернуть:
ini_set('session.save_handler', 'files');

# 3. Откат MySQL настроек
# Восстановить дефолтные значения в my.cnf

# 4. Очистка всех кэшей
rm -rf /var/cache/nginx/fastcgi/*
php -r "opcache_reset();"
redis-cli FLUSHALL

# 5. Перезапуск сервисов
sudo systemctl restart nginx
sudo systemctl restart apache2
sudo systemctl restart mysql
sudo systemctl restart redis
```

---

## 📞 ПОДДЕРЖКА И ВОПРОСЫ

### Контакты для помощи

1. **Beget Support** - техническая поддержка хостинга
2. **FastPanel Docs** - документация панели управления
3. **Stack Overflow** - для технических вопросов
4. **GitHub Issues** - для проблем с кодом

### Рекомендуемые консультанты

- **PHP Performance** - консультации по оптимизации PHP
- **MySQL DBA** - для сложных вопросов БД
- **DevOps Engineer** - для инфраструктурных решений

---

## 🎉 ЗАКЛЮЧЕНИЕ

Эта дорожная карта представляет собой комплексный план оптимизации проекта Menu.Labus.Pro на текущем стеке технологий (Nginx + Apache + PHP + MySQL) с учетом ограничений хостинга Beget и FastPanel.

### Ключевые принципы

1. **Постепенность** - внедряйте изменения поэтапно
2. **Измеримость** - всегда измеряйте до и после
3. **Безопасность** - всегда имейте план отката
4. **Документирование** - фиксируйте все изменения
5. **Мониторинг** - постоянно следите за метриками

### Ожидаемые результаты

При полной реализации всех 4 фаз вы получите:
- **10-20x** увеличение производительности
- **5-10x** увеличение пропускной способности
- **90%** снижение времени отклика
- **95%** снижение нагрузки на БД
- Способность обслуживать **2000-5000** одновременных пользователей

### Следующие шаги

1. **Создайте резервную копию** всего проекта и БД
2. **Начните с Фазы 1** - это даст быстрый результат
3. **Измерьте базовые метрики** до начала оптимизаций
4. **Внедряйте по одному изменению** и тестируйте
5. **Документируйте каждое изменение**

Удачи в оптимизации! 🚀

---

**Автор:** AI Assistant  
**Дата:** 04 февраля 2026  
**Версия:** 1.0  
**Лицензия:** Для использования в проекте Menu.Labus.Pro
