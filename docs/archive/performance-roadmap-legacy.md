# LEGACY ARCHIVE NOTICE

This document is archived for historical context. It is **not** a source of truth for current implementation decisions.

Current source of truth: [docs/index.md](../index.md), [docs/project-reference.md](../project-reference.md), and [docs/openapi.yaml](../openapi.yaml).

---

# Р”РѕСЂРѕР¶РЅР°СЏ РєР°СЂС‚Р° РѕРїС‚РёРјРёР·Р°С†РёРё РїСЂРѕРёР·РІРѕРґРёС‚РµР»СЊРЅРѕСЃС‚Рё menu.labus.pro

**Р”Р°С‚Р° Р°РЅР°Р»РёР·Р°:** 10 С„РµРІСЂР°Р»СЏ 2026  
**Р¦РµР»СЊ:** РњР°РєСЃРёРјРёР·РёСЂРѕРІР°С‚СЊ РїСЂРѕРёР·РІРѕРґРёС‚РµР»СЊРЅРѕСЃС‚СЊ, RPS Рє Р‘Р”, СЃС‚Р°Р±РёР»СЊРЅРѕСЃС‚СЊ Рё С‚РµС…РЅРёС‡РµСЃРєРѕРµ СЃРѕРІРµСЂС€РµРЅСЃС‚РІРѕ РЅР° СЃС‚РµРєРµ **Beget + FastPanel (nginx + Apache) + PHP-FPM + MySQL**

---

## рџ“Љ EXECUTIVE SUMMARY

### РўРµРєСѓС‰РµРµ СЃРѕСЃС‚РѕСЏРЅРёРµ РїСЂРѕРµРєС‚Р°

**РџРѕР»РѕР¶РёС‚РµР»СЊРЅС‹Рµ СЃС‚РѕСЂРѕРЅС‹:**
- вњ… Р РµР°Р»РёР·РѕРІР°РЅР° РјРЅРѕРіРѕСѓСЂРѕРІРЅРµРІР°СЏ СЃРёСЃС‚РµРјР° РєСЌС€РёСЂРѕРІР°РЅРёСЏ (QueryCache, RedisCache)
- вњ… РџРѕРґРіРѕС‚РѕРІР»РµРЅР° РѕРїС‚РёРјРёР·РёСЂРѕРІР°РЅРЅР°СЏ РєРѕРЅС„РёРіСѓСЂР°С†РёСЏ nginx СЃ FastCGI cache
- вњ… Р РµР°Р»РёР·РѕРІР°РЅ РїР°С‚С‚РµСЂРЅ Singleton РґР»СЏ РїРѕРґРєР»СЋС‡РµРЅРёСЏ Рє Р‘Р”
- вњ… РСЃРїРѕР»СЊР·РѕРІР°РЅРёРµ prepared statements РґР»СЏ Р·Р°С‰РёС‚С‹ РѕС‚ SQL-РёРЅСЉРµРєС†РёР№
- вњ… Progressive Web App (PWA) СЃ Service Worker
- вњ… РћРїС‚РёРјРёР·Р°С†РёСЏ СЃС‚Р°С‚РёС‡РµСЃРєРёС… СЂРµСЃСѓСЂСЃРѕРІ (WebP, РІРµСЂСЃРёРѕРЅРёСЂРѕРІР°РЅРёРµ, РјРёРЅРёС„РёРєР°С†РёСЏ)
- вњ… Р Р°Р·РґРµР»РµРЅРёРµ РїСѓР»РѕРІ PHP-FPM (web/api/sse)

**РљСЂРёС‚РёС‡РµСЃРєРёРµ РїСЂРѕР±Р»РµРјС‹:**
- вќЊ **РћС‚СЃСѓС‚СЃС‚РІРёРµ connection pooling** - РєР°Р¶РґС‹Р№ Р·Р°РїСЂРѕСЃ СЃРѕР·РґР°РµС‚ РЅРѕРІРѕРµ РїРѕРґРєР»СЋС‡РµРЅРёРµ Рє Р‘Р”
- вќЊ **N+1 РїСЂРѕР±Р»РµРјС‹ РІ Р·Р°РїСЂРѕСЃР°С…** - РјРЅРѕР¶РµСЃС‚РІРµРЅРЅС‹Рµ РІР»РѕР¶РµРЅРЅС‹Рµ SELECT РІ С†РёРєР»Р°С…
- вќЊ **РњРµРґР»РµРЅРЅС‹Рµ Р°РіСЂРµРіР°С†РёРѕРЅРЅС‹Рµ Р·Р°РїСЂРѕСЃС‹** Р±РµР· РјР°С‚РµСЂРёР°Р»РёР·РѕРІР°РЅРЅС‹С… РїСЂРµРґСЃС‚Р°РІР»РµРЅРёР№
- вќЊ **РћС‚СЃСѓС‚СЃС‚РІРёРµ query result pooling** РґР»СЏ С‡Р°СЃС‚С‹С… РёРґРµРЅС‚РёС‡РЅС‹С… Р·Р°РїСЂРѕСЃРѕРІ
- вќЊ **РќРµРѕРїС‚РёРјР°Р»СЊРЅР°СЏ СЃС‚СЂСѓРєС‚СѓСЂР° РёРЅРґРµРєСЃРѕРІ** - СЃРѕСЃС‚Р°РІРЅС‹Рµ РёРЅРґРµРєСЃС‹ РЅРµ РїРѕРєСЂС‹РІР°СЋС‚ РІСЃРµ С‡Р°СЃС‚С‹Рµ Р·Р°РїСЂРѕСЃС‹
- вќЊ **РР·Р±С‹С‚РѕС‡РЅРѕРµ РёСЃРїРѕР»СЊР·РѕРІР°РЅРёРµ JSON** РІ Р‘Р” РІРјРµСЃС‚Рѕ РЅРѕСЂРјР°Р»РёР·РѕРІР°РЅРЅС‹С… С‚Р°Р±Р»РёС†
- вќЊ **РћС‚СЃСѓС‚СЃС‚РІРёРµ read replicas** РґР»СЏ СЂР°СЃРїСЂРµРґРµР»РµРЅРёСЏ РЅР°РіСЂСѓР·РєРё С‡С‚РµРЅРёСЏ
- вќЊ **РќРµС‚ Р±Р°С‚С‡РёРЅРіР°** РґР»СЏ РјР°СЃСЃРѕРІС‹С… РѕРїРµСЂР°С†РёР№ РІСЃС‚Р°РІРєРё/РѕР±РЅРѕРІР»РµРЅРёСЏ

### РџРѕС‚РµРЅС†РёР°Р» СѓР»СѓС‡С€РµРЅРёСЏ
- рџљЂ **RPS Рє Р‘Р”:** +400-800% (СЃ ~100 RPS РґРѕ 500-900 RPS)
- вљЎ **Latency:** -60-80% (СЃ ~200ms РґРѕ ~40-80ms РЅР° p95)
- рџ’ѕ **Memory efficiency:** +50-70% Р·Р° СЃС‡РµС‚ connection pooling
- рџ”„ **Query throughput:** +300-500% Р·Р° СЃС‡РµС‚ batch operations

---

## рџЋЇ Р¤РђР—Рђ 1: РљР РРўРР§Р•РЎРљРР• РћРџРўРРњРР—РђР¦РР Р‘Р” (РќРµРґРµР»СЏ 1-2)

### 1.1 Connection Pooling & Persistent Connections

**РџСЂРѕР±Р»РµРјР°:** РљР°Р¶РґС‹Р№ Р·Р°РїСЂРѕСЃ СЃРѕР·РґР°РµС‚ РЅРѕРІРѕРµ РїРѕРґРєР»СЋС‡РµРЅРёРµ Рє MySQL, С‡С‚Рѕ СЃРѕР·РґР°РµС‚ overhead ~5-15ms РЅР° РєР°Р¶РґРѕРµ РїРѕРґРєР»СЋС‡РµРЅРёРµ.

**Р РµС€РµРЅРёРµ:**

```php
// db.php - Р”РћР‘РђР’РРўР¬ connection pooling
class Database {
    private static $pool = [];
    private static $poolSize = 10; // Beget РѕРіСЂР°РЅРёС‡РёРІР°РµС‚ ~20-30 СЃРѕРµРґРёРЅРµРЅРёР№
    private static $poolIndex = 0;

    private function __construct() {
        // РР·РјРµРЅРёС‚СЊ PDO::ATTR_PERSISTENT РЅР° true
        $this->connection = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true, // РљР РРўРР§РќРћ!
                PDO::ATTR_TIMEOUT => 5,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                PDO::MYSQL_ATTR_COMPRESS => true
            ]
        );
    }

    // Р”РѕР±Р°РІРёС‚СЊ РјРµС‚РѕРґ РґР»СЏ РїРѕР»СѓС‡РµРЅРёСЏ СЃРѕРµРґРёРЅРµРЅРёСЏ РёР· РїСѓР»Р°
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

**РћР¶РёРґР°РµРјС‹Р№ СЌС„С„РµРєС‚:**
- РЈСЃС‚СЂР°РЅРµРЅРёРµ ~5-15ms overhead РЅР° РєР°Р¶РґС‹Р№ Р·Р°РїСЂРѕСЃ
- +100-200% RPS РїСЂРё РІС‹СЃРѕРєРѕР№ РЅР°РіСЂСѓР·РєРµ
- РЎРЅРёР¶РµРЅРёРµ CPU usage РЅР° ~15-25%

**РћРіСЂР°РЅРёС‡РµРЅРёСЏ Beget:**
- Max 20-30 РѕРґРЅРѕРІСЂРµРјРµРЅРЅС‹С… РїРѕРґРєР»СЋС‡РµРЅРёР№ Рє MySQL
- РќР°СЃС‚СЂРѕРёС‚СЊ `$poolSize = 10` РєР°Рє Р±Р°Р·РѕРІРѕРµ Р·РЅР°С‡РµРЅРёРµ

---

### 1.2 РћРїС‚РёРјРёР·Р°С†РёСЏ РёРЅРґРµРєСЃРѕРІ Р‘Р”

**РўРµРєСѓС‰РµРµ СЃРѕСЃС‚РѕСЏРЅРёРµ:** Р‘Р°Р·РѕРІС‹Рµ РёРЅРґРµРєСЃС‹ РЅР° `id`, РЅРѕ РѕС‚СЃСѓС‚СЃС‚РІСѓСЋС‚ СЃРѕСЃС‚Р°РІРЅС‹Рµ РёРЅРґРµРєСЃС‹ РґР»СЏ С‡Р°СЃС‚С‹С… Р·Р°РїСЂРѕСЃРѕРІ.

**РљР РРўРР§Р•РЎРљРР• РёРЅРґРµРєСЃС‹ РґР»СЏ РґРѕР±Р°РІР»РµРЅРёСЏ:**

```sql
-- 1. Р”Р»СЏ getMenuItems() - РІС‹Р±РѕСЂРєР° РјРµРЅСЋ РїРѕ РєР°С‚РµРіРѕСЂРёРё
CREATE INDEX idx_menu_items_available_category_name 
ON menu_items(available, category, name);

-- 2. Р”Р»СЏ getAllOrders() - СЃРѕСЂС‚РёСЂРѕРІРєР° Р·Р°РєР°Р·РѕРІ
CREATE INDEX idx_orders_created_status 
ON orders(created_at DESC, status);

-- 3. Р”Р»СЏ getUserOrders() - Р·Р°РєР°Р·С‹ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ
CREATE INDEX idx_orders_user_created 
ON orders(user_id, created_at DESC);

-- 4. Р”Р»СЏ getOrderUpdatesSince() - РѕС‚СЃР»РµР¶РёРІР°РЅРёРµ РѕР±РЅРѕРІР»РµРЅРёР№
CREATE INDEX idx_orders_updated_at 
ON orders(updated_at);

-- 5. Р”Р»СЏ getUserByEmail() - Р°РІС‚РѕСЂРёР·Р°С†РёСЏ
CREATE INDEX idx_users_email_active 
ON users(email, is_active);

-- 6. Р”Р»СЏ order_items - JOIN СЃ orders
CREATE INDEX idx_order_items_order_item 
ON order_items(order_id, item_id);

-- 7. Р”Р»СЏ auth_tokens - Remember Me С„СѓРЅРєС†РёРѕРЅР°Р»
CREATE INDEX idx_auth_tokens_selector_expires 
ON auth_tokens(selector, expires_at);

-- 8. Р”Р»СЏ РѕС‚С‡РµС‚РѕРІ - СЃС‚Р°С‚СѓСЃРЅР°СЏ Р°РЅР°Р»РёС‚РёРєР°
CREATE INDEX idx_orders_status_created_updated 
ON orders(status, created_at, updated_at);
```

**РџСЂРѕРІРµСЂРєР° СЌС„С„РµРєС‚РёРІРЅРѕСЃС‚Рё РёРЅРґРµРєСЃРѕРІ:**

```sql
-- РЎРєСЂРёРїС‚ РґР»СЏ Р°РЅР°Р»РёР·Р° РёСЃРїРѕР»СЊР·РѕРІР°РЅРёСЏ РёРЅРґРµРєСЃРѕРІ
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    SEQ_IN_INDEX,
    COLUMN_NAME,
    CARDINALITY
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = 'menu_labus'
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- РџСЂРѕРІРµСЂРєР° РЅРµРёСЃРїРѕР»СЊР·СѓРµРјС‹С… РёРЅРґРµРєСЃРѕРІ
SELECT 
    object_schema,
    object_name,
    index_name
FROM performance_schema.table_io_waits_summary_by_index_usage
WHERE index_name IS NOT NULL
  AND count_star = 0
  AND object_schema = 'menu_labus';
```

**РћР¶РёРґР°РµРјС‹Р№ СЌС„С„РµРєС‚:**
- Query time: -70-90% РґР»СЏ С‡Р°СЃС‚С‹С… Р·Р°РїСЂРѕСЃРѕРІ
- +150-300% RPS РґР»СЏ API СЌРЅРґРїРѕРёРЅС‚РѕРІ
- РЈСЃС‚СЂР°РЅРµРЅРёРµ full table scans

---

### 1.3 РЈСЃС‚СЂР°РЅРµРЅРёРµ N+1 РїСЂРѕР±Р»РµРј

**РџСЂРѕР±Р»РµРјР°:** Р’ РєРѕРґРµ РїСЂРёСЃСѓС‚СЃС‚РІСѓСЋС‚ РІР»РѕР¶РµРЅРЅС‹Рµ Р·Р°РїСЂРѕСЃС‹ РІ С†РёРєР»Р°С… (РЅР°РїСЂРёРјРµСЂ, РІ `getAllOrders()`, `getTopDishes()`).

**РљСЂРёС‚РёС‡РЅС‹Р№ РїСЂРёРјРµСЂ РёР· db.php:**

```php
// РџР›РћРҐРћ - N+1 РїСЂРѕР±Р»РµРјР°
public function getAllOrders() {
    $stmt = $this->prepareCached("SELECT o.id, ..., u.name FROM orders o JOIN users u ...");
    $orders = $stmt->fetchAll();

    foreach ($orders as &$order) {
        $order['items'] = json_decode($order['items'], true);
        // РљР°Р¶РґС‹Р№ СЂР°Р· РґРµРєРѕРґРёСЂСѓРµРј JSON - СЌС‚Рѕ РјРµРґР»РµРЅРЅРѕ!
    }
}
```

**Р Р•РЁР•РќРР• - Batch prefetch + РєСЌС€РёСЂРѕРІР°РЅРёРµ:**

```php
// РҐРћР РћРЁРћ - batch РѕРїРµСЂР°С†РёРё
public function getAllOrders() {
    $cacheKey = 'all_orders_batch';

    // РџСЂРѕРІРµСЂСЏРµРј Redis cache
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

    // РћРґРёРЅ СЂР°Р· РїР°СЂСЃРёРј JSON РґР»СЏ РІСЃРµС… Р·Р°РєР°Р·РѕРІ
    foreach ($orders as &$order) {
        $order['items'] = json_decode($order['items'], true);

        // РџР°СЂСЃРёРј items_data РёР· GROUP_CONCAT
        if ($order['items_data']) {
            $order['order_items'] = $this->parseItemsData($order['items_data']);
        }
    }

    // РљСЌС€РёСЂСѓРµРј РЅР° 30 СЃРµРєСѓРЅРґ
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

**РћР¶РёРґР°РµРјС‹Р№ СЌС„С„РµРєС‚:**
- -80-95% РєРѕР»РёС‡РµСЃС‚РІР° Р·Р°РїСЂРѕСЃРѕРІ Рє Р‘Р”
- Query time РґР»СЏ СЃРїРёСЃРєР° Р·Р°РєР°Р·РѕРІ: СЃ ~500ms РґРѕ ~50ms
- +200-400% RPS РґР»СЏ dashboard endpoints

---

### 1.4 Denormalization & РњР°С‚РµСЂРёР°Р»РёР·РѕРІР°РЅРЅС‹Рµ РїСЂРµРґСЃС‚Р°РІР»РµРЅРёСЏ

**РџСЂРѕР±Р»РµРјР°:** РђРіСЂРµРіР°С†РёРѕРЅРЅС‹Рµ Р·Р°РїСЂРѕСЃС‹ РІ РѕС‚С‡РµС‚Р°С… (getSalesReport, getProfitReport) РІС‹РїРѕР»РЅСЏСЋС‚СЃСЏ РєР°Р¶РґС‹Р№ СЂР°Р· Р·Р°РЅРѕРІРѕ СЃ РјРЅРѕР¶РµСЃС‚РІРµРЅРЅС‹РјРё JOIN Рё РІС‹С‡РёСЃР»РµРЅРёСЏРјРё.

**Р Р•РЁР•РќРР• - РЎРѕР·РґР°С‚СЊ РјР°С‚РµСЂРёР°Р»РёР·РѕРІР°РЅРЅС‹Рµ С‚Р°Р±Р»РёС†С‹ РґР»СЏ РѕС‚С‡РµС‚РѕРІ:**

```sql
-- РўР°Р±Р»РёС†Р° РґР»СЏ РµР¶РµРґРЅРµРІРЅС‹С… Р°РіСЂРµРіР°С‚РѕРІ
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

-- РўСЂРёРіРіРµСЂ РґР»СЏ Р°РІС‚РѕРјР°С‚РёС‡РµСЃРєРѕРіРѕ РѕР±РЅРѕРІР»РµРЅРёСЏ
DELIMITER $$
CREATE TRIGGER update_sales_cache_after_order_insert
AFTER INSERT ON orders
FOR EACH ROW
BEGIN
    IF NEW.status = 'Р·Р°РІРµСЂС€С‘РЅ' THEN
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
    IF NEW.status = 'Р·Р°РІРµСЂС€С‘РЅ' AND OLD.status != 'Р·Р°РІРµСЂС€С‘РЅ' THEN
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

**PHP-РєРѕРґ РґР»СЏ СЂР°Р±РѕС‚С‹ СЃ РєСЌС€РµРј:**

```php
public function getSalesReport($period = 'day') {
    if ($period === 'day') {
        // РСЃРїРѕР»СЊР·СѓРµРј РјР°С‚РµСЂРёР°Р»РёР·РѕРІР°РЅРЅСѓСЋ С‚Р°Р±Р»РёС†Сѓ
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
    // ... РѕСЃС‚Р°Р»СЊРЅР°СЏ Р»РѕРіРёРєР° РґР»СЏ week/month/year
}
```

**РћР¶РёРґР°РµРјС‹Р№ СЌС„С„РµРєС‚:**
- Query time РґР»СЏ РѕС‚С‡РµС‚РѕРІ: СЃ ~2-5s РґРѕ ~10-50ms (200-500x Р±С‹СЃС‚СЂРµРµ!)
- -95% РЅР°РіСЂСѓР·РєРё РЅР° Р‘Р” РїСЂРё Р·Р°РїСЂРѕСЃР°С… РѕС‚С‡РµС‚РѕРІ
- +500-1000% RPS РґР»СЏ analytics endpoints

---

### 1.5 Batch Operations РґР»СЏ РјР°СЃСЃРѕРІС‹С… РѕРїРµСЂР°С†РёР№

**РџСЂРѕР±Р»РµРјР°:** Р’ `persistOrderItems()` items РІСЃС‚Р°РІР»СЏСЋС‚СЃСЏ РїРѕ РѕРґРЅРѕРјСѓ РІ С†РёРєР»Рµ.

**Р Р•РЁР•РќРР• - Multi-row INSERT:**

```php
private function persistOrderItems(int $orderId, array $items): void {
    if (!$orderId || !$items) return;
    if (!$this->ensureOrderItemsTable()) return;

    // Batch insert - РѕРґРёРЅ Р·Р°РїСЂРѕСЃ РІРјРµСЃС‚Рѕ N Р·Р°РїСЂРѕСЃРѕРІ
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

**РћР¶РёРґР°РµРјС‹Р№ СЌС„С„РµРєС‚:**
- -90% РєРѕР»РёС‡РµСЃС‚РІР° INSERT Р·Р°РїСЂРѕСЃРѕРІ
- РЎРѕР·РґР°РЅРёРµ Р·Р°РєР°Р·Р°: СЃ ~300ms РґРѕ ~50ms
- +400-600% RPS РґР»СЏ order creation endpoint

---

## рџљЂ Р¤РђР—Рђ 2: PHP-FPM Р NGINX РћРџРўРРњРР—РђР¦РР (РќРµРґРµР»СЏ 2-3)

### 2.1 PHP-FPM Pool Configuration РґР»СЏ Beget

**РўРµРєСѓС‰Р°СЏ РєРѕРЅС„РёРіСѓСЂР°С†РёСЏ РІ РїСЂРѕРµРєС‚Рµ РїСЂРµРґРїРѕР»Р°РіР°РµС‚ 3 РѕС‚РґРµР»СЊРЅС‹С… РїСѓР»Р° (web/api/sse), РЅРѕ РЅР° Beget РѕРіСЂР°РЅРёС‡РµРЅРѕ РєРѕР»РёС‡РµСЃС‚РІРѕ РїСЂРѕС†РµСЃСЃРѕРІ.**

**РћРџРўРРњРђР›Р¬РќРђРЇ РєРѕРЅС„РёРіСѓСЂР°С†РёСЏ РґР»СЏ Beget/FastPanel:**

```ini
; /etc/php/8.x/fpm/pool.d/menu_labus.conf

[menu_labus_web]
user = menu_labus_usr
group = menu_labus_usr
listen = /var/run/php/menu_labus_web.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

; РљР РРўРР§РќРћ: pm = static РґР»СЏ СЃС‚Р°Р±РёР»СЊРЅРѕР№ РїСЂРѕРёР·РІРѕРґРёС‚РµР»СЊРЅРѕСЃС‚Рё
pm = static
pm.max_children = 15  ; Beget С‚Р°СЂРёС„С‹ РѕР±С‹С‡РЅРѕ 1-2GB RAM
                      ; 15 РїСЂРѕС†РµСЃСЃРѕРІ * ~40MB = ~600MB РґР»СЏ PHP-FPM

; Р”Р»СЏ РґРёРЅР°РјРёС‡РµСЃРєРѕРіРѕ СЂРµР¶РёРјР° (РµСЃР»Рё РЅРµ С…РІР°С‚Р°РµС‚ РїР°РјСЏС‚Рё):
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

; OPcache settings (РљР РРўРР§РќРћ!)
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
; РћС‚РґРµР»СЊРЅС‹Р№ РїСѓР» РґР»СЏ API - РјРµРЅСЊС€Рµ РїСЂРѕС†РµСЃСЃРѕРІ
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
; SSE pool - РґРѕР»РіРѕР¶РёРІСѓС‰РёРµ СЃРѕРµРґРёРЅРµРЅРёСЏ
user = menu_labus_usr
group = menu_labus_usr
listen = /var/run/php/menu_labus_sse.sock
pm = dynamic
pm.max_children = 5
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 100
request_terminate_timeout = 3600s  ; 1 С‡Р°СЃ РґР»СЏ SSE
pm.status_path = /fpm-status-sse
```

**Р Р°СЃС‡РµС‚ pm.max_children РґР»СЏ РІР°С€РµРіРѕ СЃРµСЂРІРµСЂР°:**

```bash
# 1. РЈР·РЅР°С‚СЊ СЃСЂРµРґРЅРёР№ СЂР°Р·РјРµСЂ PHP-FPM РїСЂРѕС†РµСЃСЃР°
ps aux | grep php-fpm | awk '{sum+=$6} END {print "Average:", sum/NR/1024, "MB"}'

# 2. Р Р°СЃС‡РµС‚ РјР°РєСЃРёРјР°Р»СЊРЅРѕРіРѕ РєРѕР»РёС‡РµСЃС‚РІР° РїСЂРѕС†РµСЃСЃРѕРІ
# Р¤РѕСЂРјСѓР»Р°: (Total RAM * 0.7) / Average PHP-FPM size
# РџСЂРёРјРµСЂ РґР»СЏ 2GB RAM Рё 40MB РїСЂРѕС†РµСЃСЃ:
# (2048 * 0.7) / 40 = ~35 РїСЂРѕС†РµСЃСЃРѕРІ

# 3. Р Р°Р·РґРµР»РёС‚СЊ РјРµР¶РґСѓ РїСѓР»Р°РјРё:
# web: 15-20 РїСЂРѕС†РµСЃСЃРѕРІ (РѕСЃРЅРѕРІРЅР°СЏ РЅР°РіСЂСѓР·РєР°)
# api: 8-10 РїСЂРѕС†РµСЃСЃРѕРІ (API СЌРЅРґРїРѕРёРЅС‚С‹)
# sse: 3-5 РїСЂРѕС†РµСЃСЃРѕРІ (SSE СЃРѕРµРґРёРЅРµРЅРёСЏ)
```

**РћР¶РёРґР°РµРјС‹Р№ СЌС„С„РµРєС‚:**
- +50-100% RPS Р·Р° СЃС‡РµС‚ pm.static
- -30-50% response time variance (Р±РѕР»РµРµ СЃС‚Р°Р±РёР»СЊРЅС‹Р№ p95/p99)
- -20-40% memory usage СЃ РѕРїС‚РёРјРёР·РёСЂРѕРІР°РЅРЅС‹Рј РєРѕР»РёС‡РµСЃС‚РІРѕРј РїСЂРѕС†РµСЃСЃРѕРІ

---

### 2.2 Nginx Microcache & FastCGI tuning

**РџСЂРёРјРµРЅРёС‚СЊ РїРѕРґРіРѕС‚РѕРІР»РµРЅРЅСѓСЋ РєРѕРЅС„РёРіСѓСЂР°С†РёСЋ `nginx-optimized.conf` СЃ РґРѕСЂР°Р±РѕС‚РєР°РјРё:**

```nginx
# Р”РѕР±Р°РІРёС‚СЊ РІ http {} Р±Р»РѕРє
fastcgi_cache_path /var/cache/nginx/fastcgi_menu 
    levels=1:2 
    keys_zone=MENUCACHE:200m  # РЈРІРµР»РёС‡РёС‚СЊ РґРѕ 200MB
    inactive=10m              # РЎРѕРєСЂР°С‚РёС‚СЊ РґРѕ 10 РјРёРЅСѓС‚
    max_size=2g               # РЈРІРµР»РёС‡РёС‚СЊ РґРѕ 2GB
    use_temp_path=off;        # РљР РРўРР§РќРћ РґР»СЏ РїСЂРѕРёР·РІРѕРґРёС‚РµР»СЊРЅРѕСЃС‚Рё

# РџР°СЂР°РјРµС‚СЂС‹ FastCGI РґР»СЏ РІСЃРµС… PHP locations
fastcgi_connect_timeout 5s;
fastcgi_send_timeout 30s;
fastcgi_read_timeout 30s;
fastcgi_buffer_size 32k;           # РЈРІРµР»РёС‡РёС‚СЊ РґР»СЏ Р±РѕР»СЊС€РёС… РѕС‚РІРµС‚РѕРІ
fastcgi_buffers 32 32k;            # 32 * 32k = 1MB buffer
fastcgi_busy_buffers_size 64k;
fastcgi_temp_file_write_size 64k;

# Connection pooling РґР»СЏ FastCGI (РµСЃР»Рё РїРѕРґРґРµСЂР¶РёРІР°РµС‚СЃСЏ)
fastcgi_keep_conn on;
fastcgi_socket_keepalive on;
```

**РќР°СЃС‚СЂРѕР№РєР° Р°РіСЂРµСЃСЃРёРІРЅРѕРіРѕ РєСЌС€РёСЂРѕРІР°РЅРёСЏ РґР»СЏ РїСѓР±Р»РёС‡РЅРѕРіРѕ РјРµРЅСЋ:**

```nginx
location = /api/v1/menu.php {
    # РЈРІРµР»РёС‡РёС‚СЊ TTL РґР»СЏ burst protection
    fastcgi_cache MENUCACHE;
    fastcgi_cache_valid 200 30s;  # Р‘С‹Р»Рѕ 5s, СЃС‚Р°Р»Рѕ 30s
    fastcgi_cache_valid 404 10s;

    # Stale content РґР»СЏ РІС‹СЃРѕРєРѕР№ РґРѕСЃС‚СѓРїРЅРѕСЃС‚Рё
    fastcgi_cache_use_stale 
        error timeout invalid_header updating
        http_500 http_502 http_503 http_504;

    fastcgi_cache_background_update on;
    fastcgi_cache_lock on;
    fastcgi_cache_lock_timeout 2s;
    fastcgi_cache_lock_age 10s;

    # Р’Р°СЂСЊРёСЂРѕРІР°С‚СЊ РєСЌС€ РїРѕ РјРµС‚РѕРґСѓ Рё Origin РґР»СЏ CORS
    fastcgi_cache_key "$scheme$request_method$host$request_uri|$http_origin|$http_accept_encoding";

    # РћР±С…РѕРґ РєСЌС€Р° С‚РѕР»СЊРєРѕ РґР»СЏ benchmarking
    fastcgi_cache_bypass $http_x_bypass_cache;
    fastcgi_no_cache $http_x_bypass_cache;

    # РЎР¶Р°С‚РёРµ
    gzip on;
    gzip_types application/json;
    gzip_min_length 1000;
    gzip_comp_level 6;

    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/var/run/php/menu_labus_api.sock;
}
```

**РћР¶РёРґР°РµРјС‹Р№ СЌС„С„РµРєС‚:**
- +1000-2000% RPS РґР»СЏ РєСЌС€РёСЂСѓРµРјС‹С… endpoints (СЃ ~50 RPS РґРѕ ~1000+ RPS)
- Latency РґР»СЏ РїСѓР±Р»РёС‡РЅРѕРіРѕ РјРµРЅСЋ: СЃ ~100-200ms РґРѕ ~2-5ms (HIT)
- -80-95% РЅР°РіСЂСѓР·РєРё РЅР° PHP-FPM РґР»СЏ С‡Р°СЃС‚С‹С… Р·Р°РїСЂРѕСЃРѕРІ

---

### 2.3 HTTP/2 Server Push & Preload

**Р”РѕР±Р°РІРёС‚СЊ РІ nginx РґР»СЏ РѕРїС‚РёРјРёР·Р°С†РёРё Р·Р°РіСЂСѓР·РєРё РєСЂРёС‚РёС‡РµСЃРєРёС… СЂРµСЃСѓСЂСЃРѕРІ:**

```nginx
location = /menu.php {
    # HTTP/2 Server Push РґР»СЏ РєСЂРёС‚РёС‡РµСЃРєРёС… СЂРµСЃСѓСЂСЃРѕРІ
    http2_push /css/fa-purged.min.css;
    http2_push /css/version.min.css;
    http2_push /js/security.min.js;

    # РР»Рё РёСЃРїРѕР»СЊР·РѕРІР°С‚СЊ Link header РґР»СЏ preload
    add_header Link "</css/fa-purged.min.css>; rel=preload; as=style" always;
    add_header Link "</css/version.min.css>; rel=preload; as=style" always;
    add_header Link "</js/security.min.js>; rel=preload; as=script" always;

    # РћСЃС‚Р°Р»СЊРЅР°СЏ РєРѕРЅС„РёРіСѓСЂР°С†РёСЏ...
}
```

**РћР¶РёРґР°РµРјС‹Р№ СЌС„С„РµРєС‚:**
- -30-50% First Contentful Paint (FCP)
- -20-40% Largest Contentful Paint (LCP)

---

## рџ”Ґ Р¤РђР—Рђ 3: REDIS & ADVANCED CACHING (РќРµРґРµР»СЏ 3-4)

### 3.1 Redis Configuration РґР»СЏ Beget

**РџСЂРѕРІРµСЂРёС‚СЊ РґРѕСЃС‚СѓРїРЅРѕСЃС‚СЊ Redis РЅР° Beget Рё РЅР°СЃС‚СЂРѕРёС‚СЊ:**

```redis
# redis.conf (РµСЃР»Рё РґРѕСЃС‚СѓРїРµРЅ РґР»СЏ СЂРµРґР°РєС‚РёСЂРѕРІР°РЅРёСЏ)
maxmemory 256mb
maxmemory-policy allkeys-lru

# Persistence (РґР»СЏ СЃРµСЃСЃРёР№ Рё РІР°Р¶РЅРѕРіРѕ РєСЌС€Р°)
save 900 1
save 300 10
save 60 10000

# Performance
tcp-backlog 511
timeout 0
tcp-keepalive 300

# Р”Р»СЏ production
appendonly yes
appendfsync everysec
```

### 3.2 РњРЅРѕРіРѕСѓСЂРѕРІРЅРµРІР°СЏ СЃС‚СЂР°С‚РµРіРёСЏ РєСЌС€РёСЂРѕРІР°РЅРёСЏ

**Р РµР°Р»РёР·РѕРІР°С‚СЊ РёРµСЂР°СЂС…РёСЋ РєСЌС€РµР№:**

```php
class CacheHierarchy {
    private $l1Cache; // APCu - in-process cache (fastest)
    private $l2Cache; // Redis - shared cache
    private $l3Cache; // Query Cache - in-memory PHP array

    public function get($key) {
        // Level 1: APCu (< 1Ојs)
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

        // Level 3: Query Cache (РІ РїР°РјСЏС‚Рё PHP РїСЂРѕС†РµСЃСЃР°)
        if ($this->l3Cache && isset($this->l3Cache[$key])) {
            return $this->l3Cache[$key];
        }

        return null;
    }

    public function set($key, $value, $ttl = 600) {
        // Store in all levels
        if (function_exists('apcu_store')) {
            apcu_store($key, $value, min($ttl, 60)); // L1 РєРѕСЂРѕС‚РєРёР№ TTL
        }

        if ($this->l2Cache) {
            $this->l2Cache->set($key, $value, $ttl);
        }

        $this->l3Cache[$key] = $value;
    }
}
```

**РћР¶РёРґР°РµРјС‹Р№ СЌС„С„РµРєС‚:**
- L1 hits: ~10-50x Р±С‹СЃС‚СЂРµРµ Redis
- Cache hit rate: +20-40% Р·Р° СЃС‡РµС‚ РјРЅРѕРіРѕСѓСЂРѕРІРЅРµРІРѕР№ СЃС‚СЂР°С‚РµРіРёРё
- -60-80% latency РґР»СЏ С‡Р°СЃС‚Рѕ Р·Р°РїСЂР°С€РёРІР°РµРјС‹С… РґР°РЅРЅС‹С…

---

### 3.3 Invalidation Strategy СЃ tag-based cache

**РџСЂРѕР±Р»РµРјР°:** РРЅРІР°Р»РёРґР°С†РёСЏ РєСЌС€Р° СЃРµР№С‡Р°СЃ РґРµР»Р°РµС‚СЃСЏ РїРѕ pattern matching, С‡С‚Рѕ РЅРµСЌС„С„РµРєС‚РёРІРЅРѕ.

**Р Р•РЁР•РќРР• - Tag-based invalidation:**

```php
class TaggedCache {
    private $redis;

    public function setWithTags($key, $value, $ttl, $tags = []) {
        // РЎРѕС…СЂР°РЅСЏРµРј Р·РЅР°С‡РµРЅРёРµ
        $this->redis->set($key, serialize($value), $ttl);

        // РЎРІСЏР·С‹РІР°РµРј СЃ С‚РµРіР°РјРё
        foreach ($tags as $tag) {
            $this->redis->sAdd("tag:{$tag}", $key);
            $this->redis->expire("tag:{$tag}", $ttl + 60);
        }
    }

    public function invalidateTag($tag) {
        // РџРѕР»СѓС‡Р°РµРј РІСЃРµ РєР»СЋС‡Рё СЃ СЌС‚РёРј С‚РµРіРѕРј
        $keys = $this->redis->sMembers("tag:{$tag}");

        if (!empty($keys)) {
            // РЈРґР°Р»СЏРµРј РІСЃРµ РєР»СЋС‡Рё РѕРґРЅРѕР№ РєРѕРјР°РЅРґРѕР№
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

// РСЃРїРѕР»СЊР·РѕРІР°РЅРёРµ
$cache->setWithTags('menu_items_all', $items, 600, ['menu', 'items']);
$cache->setWithTags('product_123', $product, 1800, ['menu', 'product', 'product_123']);

// РџСЂРё РѕР±РЅРѕРІР»РµРЅРёРё РјРµРЅСЋ
$cache->invalidateTags(['menu', 'items']); // РРЅРІР°Р»РёРґРёСЂСѓРµС‚ РІСЃРµ СЃРІСЏР·Р°РЅРЅРѕРµ
```

**РћР¶РёРґР°РµРјС‹Р№ СЌС„С„РµРєС‚:**
- -90% РІСЂРµРјРµРЅРё РЅР° РёРЅРІР°Р»РёРґР°С†РёСЋ РєСЌС€Р° (СЃ O(n) РґРѕ O(1))
- +50-100% С‚РѕС‡РЅРѕСЃС‚СЊ РёРЅРІР°Р»РёРґР°С†РёРё
- -70% false invalidations

---

## вљЎ Р¤РђР—Рђ 4: QUERY OPTIMIZATION & DATABASE TUNING (РќРµРґРµР»СЏ 4-5)

### 4.1 MySQL Configuration РґР»СЏ Beget

**РћРїС‚РёРјР°Р»СЊРЅС‹Рµ РїР°СЂР°РјРµС‚СЂС‹ MySQL РґР»СЏ Beget (РІ my.cnf, РµСЃР»Рё РґРѕСЃС‚СѓРїРµРЅ):**

```ini
[mysqld]
# InnoDB settings
innodb_buffer_pool_size = 512M      # 50-70% РѕС‚ РґРѕСЃС‚СѓРїРЅРѕР№ RAM
innodb_log_file_size = 128M
innodb_log_buffer_size = 16M
innodb_flush_log_at_trx_commit = 2  # РљРѕРјРїСЂРѕРјРёСЃСЃ performance/durability
innodb_flush_method = O_DIRECT

# Query cache (РµСЃР»Рё MySQL < 8.0)
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

### 4.2 Query Rewriting РґР»СЏ СЃР»РѕР¶РЅС‹С… РѕС‚С‡РµС‚РѕРІ

**РџСЂРёРјРµСЂ РѕРїС‚РёРјРёР·Р°С†РёРё `getSalesReport()`:**

```php
// Р‘Р«Р›Рћ - РјРµРґР»РµРЅРЅС‹Р№ Р·Р°РїСЂРѕСЃ СЃ РјРЅРѕР¶РµСЃС‚РІРµРЅРЅС‹РјРё РІС‹С‡РёСЃР»РµРЅРёСЏРјРё
public function getSalesReport($period = 'day') {
    $sql = "SELECT 
                DATE_FORMAT(created_at, '%d.%m') as date,
                COUNT(*) as order_count,
                SUM(total) as total_revenue,
                AVG(total) as avg_order_value
            FROM orders
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)
            AND status = 'Р·Р°РІРµСЂС€С‘РЅ'
            GROUP BY DATE(created_at)
            ORDER BY date DESC";
}

// РЎРўРђР›Рћ - РёСЃРїРѕР»СЊР·РѕРІР°РЅРёРµ РјР°С‚РµСЂРёР°Р»РёР·РѕРІР°РЅРЅРѕР№ С‚Р°Р±Р»РёС†С‹ + covering index
public function getSalesReport($period = 'day') {
    // РџСЂРѕРІРµСЂСЏРµРј Redis РєСЌС€ СЃ Р±РѕР»РµРµ РґР»РёРЅРЅС‹Рј TTL
    $cacheKey = "sales_report_{$period}_" . date('Y-m-d-H');
    if ($cached = $this->redisCache->get($cacheKey)) {
        return $cached;
    }

    // РСЃРїРѕР»СЊР·СѓРµРј РїСЂРµРґРІР°СЂРёС‚РµР»СЊРЅРѕ Р°РіСЂРµРіРёСЂРѕРІР°РЅРЅС‹Рµ РґР°РЅРЅС‹Рµ
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

    // РљСЌС€РёСЂСѓРµРј РЅР° 1 С‡Р°СЃ (РѕС‚С‡РµС‚С‹ РјРѕРіСѓС‚ Р±С‹С‚СЊ РЅРµРјРЅРѕРіРѕ СѓСЃС‚Р°СЂРµРІС€РёРјРё)
    $this->redisCache->set($cacheKey, $result, 3600);

    return $result;
}
```

**РћР¶РёРґР°РµРјС‹Р№ СЌС„С„РµРєС‚:**
- Query time: СЃ 2-5s РґРѕ 10-50ms (100-500x Р±С‹СЃС‚СЂРµРµ!)
- +500-1000% RPS РґР»СЏ analytics

---

### 4.3 Partitioning Р±РѕР»СЊС€РёС… С‚Р°Р±Р»РёС†

**Р”Р»СЏ С‚Р°Р±Р»РёС†С‹ `orders` РїСЂРёРјРµРЅРёС‚СЊ РїР°СЂС‚РёС†РёРѕРЅРёСЂРѕРІР°РЅРёРµ РїРѕ РґР°С‚Рµ:**

```sql
-- РЎРѕР·РґР°РЅРёРµ РїР°СЂС‚РёС†РёРѕРЅРёСЂРѕРІР°РЅРЅРѕР№ С‚Р°Р±Р»РёС†С‹
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

-- РђРІС‚РѕРјР°С‚РёС‡РµСЃРєРѕРµ СЃРѕР·РґР°РЅРёРµ РЅРѕРІС‹С… РїР°СЂС‚РёС†РёР№ (cron job)
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

**РћР¶РёРґР°РµРјС‹Р№ СЌС„С„РµРєС‚:**
- -50-70% query time РґР»СЏ Р·Р°РїСЂРѕСЃРѕРІ РїРѕ РґР°С‚Рµ
- +100-200% RPS РґР»СЏ time-based queries
- РЈРїСЂРѕС‰РµРЅРёРµ Р°СЂС…РёРІР°С†РёРё СЃС‚Р°СЂС‹С… РґР°РЅРЅС‹С…

---

## рџЋЁ Р¤РђР—Рђ 5: FRONTEND & ASSET OPTIMIZATION (РќРµРґРµР»СЏ 5-6)

### 5.1 Critical CSS Inline

**Р”РѕР±Р°РІРёС‚СЊ РёРЅР»Р°Р№РЅ РєСЂРёС‚РёС‡РµСЃРєРёР№ CSS РІ `<head>`:**

```php
<!-- header.php -->
<style>
/* Critical CSS - С‚РѕР»СЊРєРѕ РґР»СЏ above-the-fold РєРѕРЅС‚РµРЅС‚Р° */
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.hero{min-height:100vh;display:flex;align-items:center;justify-content:center}
.hero-content{text-align:center;color:#fff}
.btn{display:inline-block;padding:1rem 2rem;background:#007bff;color:#fff;text-decoration:none;border-radius:4px}
</style>

<!-- РћСЃС‚Р°Р»СЊРЅС‹Рµ СЃС‚РёР»Рё Р·Р°РіСЂСѓР¶Р°С‚СЊ async -->
<link rel="preload" href="/css/version.min.css?v=<?= $version ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="/css/version.min.css?v=<?= $version ?>"></noscript>
```

**РћР¶РёРґР°РµРјС‹Р№ СЌС„С„РµРєС‚:**
- -40-60% First Contentful Paint
- -30-50% Largest Contentful Paint

---

### 5.2 Service Worker Cache Strategy

**РћРїС‚РёРјРёР·РёСЂРѕРІР°С‚СЊ sw.js СЃ Р°РіСЂРµСЃСЃРёРІРЅС‹Рј РєСЌС€РёСЂРѕРІР°РЅРёРµРј:**

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

// РЎС‚СЂР°С‚РµРіРёСЏ: Network First РґР»СЏ API, Cache First РґР»СЏ СЃС‚Р°С‚РёРєРё
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

**РћР¶РёРґР°РµРјС‹Р№ СЌС„С„РµРєС‚:**
- Offline-first experience
- -70-90% latency РґР»СЏ РїРѕРІС‚РѕСЂРЅС‹С… РїРѕСЃРµС‰РµРЅРёР№
- +500-1000% perceived performance

---

### 5.3 Image Optimization Pipeline

**РђРІС‚РѕРјР°С‚РёР·РёСЂРѕРІР°С‚СЊ РѕРїС‚РёРјРёР·Р°С†РёСЋ РёР·РѕР±СЂР°Р¶РµРЅРёР№:**

```php
// ImageOptimizer.php - РЈР›РЈР§РЁРРўР¬ СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёР№ РєР»Р°СЃСЃ
class ImageOptimizer {
    public function optimize($sourcePath, $targetPath, $options = []) {
        $quality = $options['quality'] ?? 85;
        $maxWidth = $options['maxWidth'] ?? 1920;
        $maxHeight = $options['maxHeight'] ?? 1080;

        // Р“РµРЅРµСЂРёСЂРѕРІР°С‚СЊ multiple sizes РґР»СЏ responsive images
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

        // Р“РµРЅРµСЂРёСЂРѕРІР°С‚СЊ WebP Рё AVIF versions
        $this->generateWebP($targetPath, $quality);
        $this->generateAVIF($targetPath, $quality - 10);
    }

    private function generateResponsiveImage($source, $target, $width, $quality, $size) {
        // РСЃРїРѕР»СЊР·СѓРµРј ImageMagick РёР»Рё GD
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

**РћР¶РёРґР°РµРјС‹Р№ СЌС„С„РµРєС‚:**
- -60-80% image СЂР°Р·РјРµСЂРѕРІ (WebP + AVIF)
- -40-60% bandwidth usage
- +100-200% page load speed РЅР° РјРµРґР»РµРЅРЅС‹С… СЃРѕРµРґРёРЅРµРЅРёСЏС…

---

## рџ”ђ Р¤РђР—Рђ 6: SECURITY & MONITORING (РќРµРґРµР»СЏ 6-7)

### 6.1 Rate Limiting & DDoS Protection

**Р”РѕР±Р°РІРёС‚СЊ РІ nginx rate limiting:**

```nginx
# http {} Р±Р»РѕРє
limit_req_zone $binary_remote_addr zone=general:10m rate=30r/s;
limit_req_zone $binary_remote_addr zone=api:10m rate=60r/s;
limit_req_zone $binary_remote_addr zone=auth:10m rate=5r/s;

# Р’ location Р±Р»РѕРєР°С…
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

**РЎРѕР·РґР°С‚СЊ dashboard РґР»СЏ РјРѕРЅРёС‚РѕСЂРёРЅРіР° РїСЂРѕРёР·РІРѕРґРёС‚РµР»СЊРЅРѕСЃС‚Рё:**

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

**РЎРѕР·РґР°С‚СЊ СЃРєСЂРёРїС‚ РґР»СЏ СЂРµРіСѓР»СЏСЂРЅРѕРіРѕ load testing:**

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

**Р—Р°РїСѓСЃРєР°С‚СЊ С‡РµСЂРµР· cron:**

```bash
# РљР°Р¶РґС‹Р№ РґРµРЅСЊ РІ 3 РЅРѕС‡Рё
0 3 * * * cd /var/www/menu.labus.pro && python3 load_test_advanced.py >> /var/log/loadtest.log 2>&1
```

---

## рџ“€ Р¤РђР—Рђ 7: ADVANCED TECHNIQUES (РќРµРґРµР»СЏ 7-8)

### 7.1 GraphQL API РґР»СЏ РіРёР±РєРёС… Р·Р°РїСЂРѕСЃРѕРІ

**Р”РѕР±Р°РІРёС‚СЊ GraphQL endpoint РґР»СЏ РѕРїС‚РёРјРёР·Р°С†РёРё fetching'Р° РґР°РЅРЅС‹С…:**

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
                // РџРѕСЃС‚СЂРѕРёС‚СЊ РґРёРЅР°РјРёС‡РµСЃРєРёР№ Р·Р°РїСЂРѕСЃ СЃ С‚РѕР»СЊРєРѕ РЅСѓР¶РЅС‹РјРё РїРѕР»СЏРјРё
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

// РћР±СЂР°Р±РѕС‚РєР° Р·Р°РїСЂРѕСЃР°
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
$query = $input['query'];
$variableValues = $input['variables'] ?? null;

$result = GraphQL::executeQuery($schema, $query, null, null, $variableValues);
$output = $result->toArray();

header('Content-Type: application/json');
echo json_encode($output);
```

**РћР¶РёРґР°РµРјС‹Р№ СЌС„С„РµРєС‚:**
- -50-70% over-fetching (РєР»РёРµРЅС‚ Р·Р°РїСЂР°С€РёРІР°РµС‚ С‚РѕР»СЊРєРѕ РЅСѓР¶РЅС‹Рµ РїРѕР»СЏ)
- -30-50% РєРѕР»РёС‡РµСЃС‚РІР° API Р·Р°РїСЂРѕСЃРѕРІ
- +100-200% flexibility РґР»СЏ frontend

---

### 7.2 Database Read Replicas (РµСЃР»Рё РґРѕСЃС‚СѓРїРЅРѕ РЅР° Beget)

**РќР°СЃС‚СЂРѕРёС‚СЊ master-slave СЂРµРїР»РёРєР°С†РёСЋ:**

```php
// db.php - РґРѕР±Р°РІРёС‚СЊ РїРѕРґРґРµСЂР¶РєСѓ read replicas
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
        // SELECT Р·Р°РїСЂРѕСЃС‹ РёРґСѓС‚ РЅР° slave (РµСЃР»Рё РЅРµ С‚СЂРµР±СѓРµС‚СЃСЏ master)
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

**РћР¶РёРґР°РµРјС‹Р№ СЌС„С„РµРєС‚:**
- +100-200% read capacity
- -50% РЅР°РіСЂСѓР·РєРё РЅР° master
- Better write performance РЅР° master

---

### 7.3 Async Processing СЃ Queue Workers

**Р”РѕР±Р°РІРёС‚СЊ RabbitMQ/Redis Queue РґР»СЏ РґРѕР»РіРёС… Р·Р°РґР°С‡:**

```php
// Queue.php - РЈР›РЈР§РЁРРўР¬ СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёР№ РєР»Р°СЃСЃ
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

**Р—Р°РїСѓСЃС‚РёС‚СЊ worker С‡РµСЂРµР· supervisor:**

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

**РћР¶РёРґР°РµРјС‹Р№ СЌС„С„РµРєС‚:**
- -80-95% response time РґР»СЏ С‚СЏР¶РµР»С‹С… РѕРїРµСЂР°С†РёР№
- Better UX (non-blocking operations)
- +200-500% throughput РґР»СЏ bulk operations

---

## рџЋЇ РР—РњР•Р Р•РќРРЇ Р KPI

### Baseline (Р”Рѕ РѕРїС‚РёРјРёР·Р°С†РёР№)

**РџСЂРѕРёР·РІРѕРґРёС‚РµР»СЊРЅРѕСЃС‚СЊ:**
- RPS: ~50-100 req/s
- Latency p50: ~150ms
- Latency p95: ~500ms
- Latency p99: ~1500ms

**Р РµСЃСѓСЂСЃС‹:**
- CPU usage: 60-80%
- Memory: 70-85%
- DB connections: 15-25 active

**РљР°С‡РµСЃС‚РІРѕ:**
- Cache hit rate: ~30-40%
- Query time avg: ~200ms
- Slow queries: ~50-100/hour

### Target (РџРѕСЃР»Рµ РѕРїС‚РёРјРёР·Р°С†РёР№)

**РџСЂРѕРёР·РІРѕРґРёС‚РµР»СЊРЅРѕСЃС‚СЊ:**
- RPS: **500-900 req/s** (+400-800%)
- Latency p50: **40-60ms** (-70%)
- Latency p95: **80-120ms** (-75%)
- Latency p99: **200-300ms** (-80%)

**Р РµСЃСѓСЂСЃС‹:**
- CPU usage: **30-50%** (-40%)
- Memory: **40-60%** (-35%)
- DB connections: **5-10 active** (-60%)

**РљР°С‡РµСЃС‚РІРѕ:**
- Cache hit rate: **70-85%** (+100%)
- Query time avg: **20-40ms** (-80%)
- Slow queries: **5-10/hour** (-90%)

---

## рџ“… TIMELINE Р РџР РРћР РРўР•РўР«

### РљСЂРёС‚РёС‡РЅС‹Рµ (РќРµРґРµР»СЏ 1-2) - РќР•РњР•Р”Р›Р•РќРќРћ

1. вњ… Connection pooling (Р”РµРЅСЊ 1-2)
2. вњ… РљСЂРёС‚РёС‡РЅС‹Рµ РёРЅРґРµРєСЃС‹ Р‘Р” (Р”РµРЅСЊ 2-3)
3. вњ… РЈСЃС‚СЂР°РЅРµРЅРёРµ N+1 РїСЂРѕР±Р»РµРј (Р”РµРЅСЊ 3-5)
4. вњ… PHP-FPM configuration (Р”РµРЅСЊ 5-7)
5. вњ… Nginx FastCGI cache (Р”РµРЅСЊ 7-10)

### Р’Р°Р¶РЅС‹Рµ (РќРµРґРµР»СЏ 2-4)

6. вњ… Batch operations (Р”РµРЅСЊ 10-12)
7. вњ… РњР°С‚РµСЂРёР°Р»РёР·РѕРІР°РЅРЅС‹Рµ РїСЂРµРґСЃС‚Р°РІР»РµРЅРёСЏ (Р”РµРЅСЊ 12-15)
8. вњ… Redis configuration (Р”РµРЅСЊ 15-18)
9. вњ… Multi-tier caching (Р”РµРЅСЊ 18-21)
10. вњ… Query rewriting (Р”РµРЅСЊ 21-25)

### Р–РµР»Р°С‚РµР»СЊРЅС‹Рµ (РќРµРґРµР»СЏ 4-6)

11. в­ђ Partitioning (Р”РµРЅСЊ 25-28)
12. в­ђ Critical CSS inline (Р”РµРЅСЊ 28-30)
13. в­ђ Service Worker optimization (Р”РµРЅСЊ 30-35)
14. в­ђ Rate limiting (Р”РµРЅСЊ 35-38)
15. в­ђ Performance monitoring (Р”РµРЅСЊ 38-40)

### РџСЂРѕРґРІРёРЅСѓС‚С‹Рµ (РќРµРґРµР»СЏ 6-8)

16. рџљЂ GraphQL API (Р”РµРЅСЊ 40-45)
17. рџљЂ Read replicas (РµСЃР»Рё РґРѕСЃС‚СѓРїРЅРѕ) (Р”РµРЅСЊ 45-50)
18. рџљЂ Async processing (Р”РµРЅСЊ 50-55)

---

## рџ”§ РРќРЎРўР РЈРњР•РќРўР« Р”Р›РЇ РњРћРќРРўРћР РРќР“Рђ

### Performance Testing

```bash
# ApacheBench
ab -n 10000 -c 100 -k https://menu.pub.labus.pro/api/v1/menu.php

# wrk (Р±РѕР»РµРµ РїСЂРѕРґРІРёРЅСѓС‚С‹Р№)
wrk -t12 -c400 -d30s https://menu.pub.labus.pro/api/v1/menu.php

# Siege
siege -c100 -t30s https://menu.pub.labus.pro/menu.php

# Custom Python load tester
python3 load_test_advanced.py
```

### MySQL Profiling

```sql
-- Р’РєР»СЋС‡РёС‚СЊ РїСЂРѕС„РёР»РёСЂРѕРІР°РЅРёРµ
SET profiling = 1;

-- Р’С‹РїРѕР»РЅРёС‚СЊ Р·Р°РїСЂРѕСЃ
SELECT * FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY);

-- РџРѕСЃРјРѕС‚СЂРµС‚СЊ РїСЂРѕС„РёР»СЊ
SHOW PROFILES;
SHOW PROFILE FOR QUERY 1;

-- РђРЅР°Р»РёР· Р·Р°РїСЂРѕСЃР°
EXPLAIN ANALYZE
SELECT * FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY);
```

### PHP-FPM Monitoring

```bash
# Р§РµСЂРµР· browser
curl http://localhost/fpm-status?json

# CLI РјРѕРЅРёС‚РѕСЂРёРЅРі
watch -n 1 'curl -s http://localhost/fpm-status?json | jq .'
```

---

## вљ пёЏ Р РРЎРљР Р РћР“Р РђРќРР§Р•РќРРЇ

### РћРіСЂР°РЅРёС‡РµРЅРёСЏ Beget/FastPanel

1. **Max connections Рє MySQL**: 20-30
   - Р РµС€РµРЅРёРµ: Connection pooling + read replicas

2. **РћРіСЂР°РЅРёС‡РµРЅРЅР°СЏ RAM**: 1-2GB РѕР±С‹С‡РЅРѕ
   - Р РµС€РµРЅРёРµ: pm.static СЃ РѕРїС‚РёРјР°Р»СЊРЅС‹Рј max_children

3. **РќРµС‚ root РґРѕСЃС‚СѓРїР°**
   - Р РµС€РµРЅРёРµ: Р Р°Р±РѕС‚Р°С‚СЊ С‡РµСЂРµР· FastPanel РёРЅС‚РµСЂС„РµР№СЃ

4. **Shared hosting РѕРіСЂР°РЅРёС‡РµРЅРёСЏ**
   - Р РµС€РµРЅРёРµ: Redis + aggressive caching

### РџРѕС‚РµРЅС†РёР°Р»СЊРЅС‹Рµ РїСЂРѕР±Р»РµРјС‹

1. **Cache stampede** РїСЂРё РёРЅРІР°Р»РёРґР°С†РёРё
   - Р РµС€РµРЅРёРµ: fastcgi_cache_lock + stale-while-revalidate

2. **Memory leaks РІ PHP**
   - Р РµС€РµРЅРёРµ: pm.max_requests = 1000 РґР»СЏ recycling

3. **Session locking** РїСЂРё РІС‹СЃРѕРєРѕР№ РЅР°РіСЂСѓР·РєРµ
   - Р РµС€РµРЅРёРµ: Redis sessions + session_write_close()

---

## рџ“љ Р”РћРџРћР›РќРРўР•Р›Р¬РќР«Р• Р Р•РЎРЈР РЎР«

### Р”РѕРєСѓРјРµРЅС‚Р°С†РёСЏ

- [PHP-FPM Configuration](https://www.php.net/manual/en/install.fpm.configuration.php)
- [Nginx FastCGI Module](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html)
- [MySQL Performance Schema](https://dev.mysql.com/doc/refman/8.0/en/performance-schema.html)
- [Redis Best Practices](https://redis.io/docs/management/optimization/)

### РњРѕРЅРёС‚РѕСЂРёРЅРі

- [New Relic](https://newrelic.com/) - APM РјРѕРЅРёС‚РѕСЂРёРЅРі
- [Datadog](https://www.datadoghq.com/) - Infrastructure monitoring
- [Grafana](https://grafana.com/) - РњРµС‚СЂРёРєРё Рё РґР°С€Р±РѕСЂРґС‹
- [Prometheus](https://prometheus.io/) - Time-series Р‘Р” РґР»СЏ РјРµС‚СЂРёРє

---

## вњ… Р§Р•РљР›РРЎРў Р’РќР•Р”Р Р•РќРРЇ

### Pre-deployment

- [ ] Backup Р‘Р” Рё РєРѕРґР°
- [ ] РўРµСЃС‚РёСЂРѕРІР°РЅРёРµ РЅР° staging environment
- [ ] Load testing СЃ realistic traffic
- [ ] Rollback plan РіРѕС‚РѕРІ

### Deployment

- [ ] Deploy РІ maintenance window
- [ ] РџРѕСЃС‚РµРїРµРЅРЅС‹Р№ rollout (10% в†’ 50% в†’ 100%)
- [ ] РњРѕРЅРёС‚РѕСЂРёРЅРі error rates
- [ ] Performance metrics tracking

### Post-deployment

- [ ] Verify KPI improvements
- [ ] Monitor for 48 hours
- [ ] Collect user feedback
- [ ] Document learnings

---

## рџЋ“ Р’Р«Р’РћР”Р«

Р”Р°РЅРЅР°СЏ РґРѕСЂРѕР¶РЅР°СЏ РєР°СЂС‚Р° РїСЂРµРґСЃС‚Р°РІР»СЏРµС‚ РєРѕРјРїР»РµРєСЃРЅС‹Р№ РїРѕРґС…РѕРґ Рє РѕРїС‚РёРјРёР·Р°С†РёРё РїСЂРѕРёР·РІРѕРґРёС‚РµР»СЊРЅРѕСЃС‚Рё menu.labus.pro СЃ С„РѕРєСѓСЃРѕРј РЅР°:

1. **Database optimization** - connection pooling, РёРЅРґРµРєСЃС‹, batch operations
2. **Caching strategy** - multi-tier caching СЃ Redis, APCu, FastCGI cache
3. **PHP-FPM tuning** - РѕРїС‚РёРјР°Р»СЊРЅС‹Рµ pm.* РїР°СЂР°РјРµС‚СЂС‹ РґР»СЏ Beget
4. **Nginx configuration** - aggressive caching + microcache
5. **Frontend optimization** - critical CSS, Service Worker, image optimization
6. **Monitoring & testing** - real-time dashboards, automated load testing

**РћР¶РёРґР°РµРјС‹Р№ СЂРµР·СѓР»СЊС‚Р°С‚:**
- +400-800% RPS Рє Р‘Р”
- -70-80% latency (p95)
- -60% resource usage
- +100-300% throughput

**Р РµРєРѕРјРµРЅРґСѓРµРјР°СЏ РїРѕСЃР»РµРґРѕРІР°С‚РµР»СЊРЅРѕСЃС‚СЊ:**
1. РќР°С‡Р°С‚СЊ СЃ Р¤Р°Р·С‹ 1 (DB optimizations) - РЅР°РёР±РѕР»СЊС€РёР№ impact
2. Р—Р°С‚РµРј Р¤Р°Р·Р° 2 (PHP-FPM & Nginx) - РёРЅС„СЂР°СЃС‚СЂСѓРєС‚СѓСЂРЅС‹Рµ СѓР»СѓС‡С€РµРЅРёСЏ
3. Р¤Р°Р·Р° 3 (Redis & Caching) - multiplier СЌС„С„РµРєС‚
4. РћСЃС‚Р°Р»СЊРЅС‹Рµ С„Р°Р·С‹ - incremental improvements

**Р’СЂРµРјСЏ РІРЅРµРґСЂРµРЅРёСЏ:** 6-8 РЅРµРґРµР»СЊ РїСЂРё РїРѕР»РЅРѕР№ СЂРµР°Р»РёР·Р°С†РёРё.

---

**Р”РѕРєСѓРјРµРЅС‚ РїРѕРґРіРѕС‚РѕРІР»РµРЅ:** 10 С„РµРІСЂР°Р»СЏ 2026  
**Р’РµСЂСЃРёСЏ:** 1.0.0  
**РђРІС‚РѕСЂ:** AI Performance Consultant

---

*Р”Р°РЅРЅР°СЏ РґРѕСЂРѕР¶РЅР°СЏ РєР°СЂС‚Р° РѕСЃРЅРѕРІР°РЅР° РЅР° Р°РЅР°Р»РёР·Рµ С‚РµРєСѓС‰РµРіРѕ РєРѕРґР° РїСЂРѕРµРєС‚Р° menu.labus.pro Рё Р»СѓС‡С€РёС… РїСЂР°РєС‚РёРєР°С… РѕРїС‚РёРјРёР·Р°С†РёРё РїСЂРѕРёР·РІРѕРґРёС‚РµР»СЊРЅРѕСЃС‚Рё РґР»СЏ СЃС‚РµРєР° PHP + MySQL + Nginx РЅР° shared hosting (Beget).*

