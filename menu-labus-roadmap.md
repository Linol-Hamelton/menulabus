# 🚀 Дорожная карта оптимизации проекта menu.labus.pro

**Проект:** Сайт-меню для точек питания  
**Домен:** https://menu.labus.pro/  
**Хостинг:** Beget + FastPanel (Nginx → Apache, PHP-FPM, MySQL)  
**Цель:** Максимальная скорость, устойчивость и пропускная способность БД на текущем стеке

---

## 1. Обзор текущего состояния

### 1.1. Архитектура и стек
- Frontend: классический PHP + JS, PWA (manifest, service worker, offline.html)
- Backend: PHP-FPM (отдельный пул для проекта), файловые сессии
- Веб-сервер: Nginx (SSL, HTTP/2, FastCGI cache) → PHP-FPM → Apache/FCGI (на уровне Beget)
- База данных: MySQL/InnoDB, активное использование JSON-полей и агрегирующих запросов
- Кэширование:
  - Nginx FastCGI cache (CAFECACHE) для анонимных GET-запросов
  - OPcache включен с достаточным объёмом памяти
  - Application-level кэша (Redis/memcached) нет
- Безопасность: жёсткий CSP, HSTS, X-Frame-Options, X-Content-Type-Options и др.

### 1.2. Ключевые узкие места
1. **PHP-FPM пул**
   - `pm = dynamic`, `pm.max_children = 10` — это очень мало для нагруженного проекта.
   - Любая одновременная активность 15–20 пользователей создаёт очередь запросов.

2. **Сессии на файловой системе**
   - `session.save_handler = files`, `session.save_path` на локальном диске.
   - Файловые блокировки и I/O → 10–50 мс накладных на каждый запрос с сессией.

3. **Нагрузка на БД**
   - Много логики работает по факту «OLTP + отчёты» на одной БД.
   - Использование JSON и `JSON_TABLE` + JOIN по `menu_items` в отчётах.
   - Отсутствие или нехватка композитных индексов по частым фильтрам (`status`, `created_at`, `user_id`).

4. **FastCGI cache используется не в полную силу**
   - Ключ кэша зависит от `PHPSESSID`, из-за чего публичные страницы не кэшируются для всех.
   - Нету агрессивной стратегии кэширования для полностью публичного меню.

5. **Мониторинг и нагрузочное тестирование ограничены**
   - Нет встроенного мониторинга PHP-FPM/MySQL/Redis.
   - Нет формализованных профилей нагрузки (пики будни/выходные, обеды, ужины).

---

## 2. Стратегия оптимизации

Оптимизация делится на 5 этапов, которые можно внедрять последовательно. Приоритет — максимальный прирост за минимальное время и с минимальными рисками.

1. **Этап 1 — Фундамент**: PHP-FPM + MySQL базовая настройка, индексы.
2. **Этап 2 — Кэширование**: Redis для сессий и application-level cache, усиление FastCGI cache.
3. **Этап 3 — Архитектура БД**: денормализация для отчётов, партиционирование крупных таблиц.
4. **Этап 4 — Frontend и PWA**: критический CSS/JS, правильные стратегии кэширования, HTTP/2 оптимизации.
5. **Этап 5 — Нагрузочное тестирование и мониторинг**: регулярные профили нагрузки, алерты, автоподдержка.

---

## 3. Этап 1 — Оптимизация PHP-FPM и MySQL

### 3.1. Тонкая настройка PHP-FPM

**Цель:** убрать очередь запросов и уменьшить latency от PHP.

#### 3.1.1. Расчёт `pm.max_children`

1. На проде замерить средний размер процесса PHP-FPM:
```bash
ps -o rss= -C php-fpm8.1 | awk '{sum+=$1} END {print sum/NR/1024 " MB"}'
```
2. Допустим, сервер имеет 4 ГБ RAM:
   - ОС + MySQL + прочее: ~1.5 ГБ
   - Под PHP-FPM можно выделить ~2 ГБ.
   - Если средний процесс PHP-FPM ≈ 50 МБ:
     - 2000 МБ / 50 МБ ≈ 40 воркеров.

3. Осторожный старт:
   - `pm.max_children = 30`
   - При необходимости поднять до 40, следя за swap и load average.

#### 3.1.2. Переход на `pm = static` (или агрессивный `dynamic`)

Пример обновлённого пула (концепт):

```ini
[menu.labus.pro]
user = labus_pro_usr
group = labus_pro_usr
listen = /var/run/menu.labus.pro.sock
listen.owner = labus_pro_usr
listen.group = www-data
listen.mode = 0660

; Режим процессов
pm = static
pm.max_children = 30
pm.max_requests = 1000

; Диагностика
request_terminate_timeout = 60s
request_slowlog_timeout = 10s
slowlog = /var/www/labus_pro_usr/data/logs/menu.labus.pro-slow.log

; OPcache
php_admin_value[opcache.enable] = 1
php_admin_value[opcache.memory_consumption] = 512
php_admin_value[opcache.max_accelerated_files] = 20000
php_admin_value[opcache.interned_strings_buffer] = 32
php_admin_value[opcache.validate_timestamps] = 0
php_admin_value[opcache.revalidate_freq] = 0
php_admin_value[opcache.save_comments] = 0
php_admin_value[opcache.enable_file_override] = 1
php_admin_value[opcache.huge_code_pages] = 1
php_admin_value[opcache.jit] = tracing
php_admin_value[opcache.jit_buffer_size] = 128M

; Realpath cache
php_admin_value[realpath_cache_size] = 4M
php_admin_value[realpath_cache_ttl] = 7200
```

**Результат:**
- предсказуемое потребление памяти;
- исключение overhead на создание/убийство процессов;
- устойчивое поведение под высокой нагрузкой.

> Если static режим слишком агрессивен на вашем тарифе Beget, используйте `pm = dynamic` c увеличенным `pm.max_children` и адекватными `start_servers`, `min_spare_servers`, `max_spare_servers`.

### 3.2. Оптимизация MySQL/InnoDB

**Цель:** минимизировать чтение с диска и очереди на уровне БД.

Ключевые параметры в `my.cnf` (или в интерфейсе Beget, если доступно):

```ini
[mysqld]
# InnoDB Buffer Pool — 50–70% доступной памяти под MySQL
innodb_buffer_pool_size = 1G
innodb_buffer_pool_instances = 4

# Логи
innodb_log_file_size = 256M
innodb_log_buffer_size = 32M
innodb_flush_log_at_trx_commit = 2
innodb_flush_method = O_DIRECT

# Потоки
innodb_read_io_threads = 8
innodb_write_io_threads = 8

# Соединения
max_connections = 200
wait_timeout = 600
interactive_timeout = 600
thread_cache_size = 50

# Временные таблицы
tmp_table_size = 128M
max_heap_table_size = 128M

# Query cache (в MySQL 8 нет, отключить если включён)
query_cache_type = 0
query_cache_size = 0

performance_schema = ON
```

### 3.3. Индексация частых запросов

На основе типичных паттернов для CRM/меню/заказов, стоит гарантировать индексы:

```sql
-- Заказы по статусу и дате (список активных, история за период)
CREATE INDEX idx_orders_status_created
    ON orders(status, created_at);

-- Заказы по пользователю и дате (история клиента)
CREATE INDEX idx_orders_user_created
    ON orders(user_id, created_at);

-- Для аналитики по последним изменениям
CREATE INDEX idx_orders_updated
    ON orders(updated_at);

-- Меню: быстрый фильтр по категории и доступности
CREATE INDEX idx_menu_available_category
    ON menu_items(available, category);

-- История статусов заказа
CREATE INDEX idx_history_order_changed
    ON order_status_history(order_id, changed_at);

-- Пользователи: поиск по email (если ещё нет UNIQUE)
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_role ON users(role);

-- Токены авторизации
CREATE INDEX idx_tokens_selector_expires
    ON auth_tokens(selector, expires_at);
```

После добавления индексов — проверять `EXPLAIN` для сложных запросов и смотреть, что MySQL использует ожидаемые индексы.

---

## 4. Этап 2 — Redis, кэш запросов и усиление FastCGI Cache

### 4.1. Внедрение Redis для сессий

**Проблема:** файловые сессии создают блокировки и нагружают диск.

**Решение:** подключить Redis как `session.save_handler`.

1. Установка Redis (если доступен root / sudo):
```bash
sudo apt update
sudo apt install redis-server -y
```

2. Базовая оптимизация `/etc/redis/redis.conf`:
```conf
maxmemory 512mb
maxmemory-policy allkeys-lru

save 900 1
save 300 10
save 60 10000

rename-command FLUSHDB ""
rename-command FLUSHALL ""
rename-command KEYS ""
```

3. Обновление `session_init.php` (идея):

```php
$redisAvailable = false;
try {
    $redis = new Redis();
    $redisAvailable = $redis->connect('127.0.0.1', 6379, 2.5);
    $redis->close();
} catch (Exception $e) {
    error_log('Redis unavailable: '.$e->getMessage());
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
    ini_set('session.cookie_httponly', true);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.lazy_write', 1);

    if ($redisAvailable) {
        ini_set('session.save_handler', 'redis');
        ini_set('session.save_path', 'tcp://127.0.0.1:6379?timeout=2.5&database=0');
        ini_set('session.gc_probability', 0);
        ini_set('session.gc_divisor', 0);
    } else {
        ini_set('session.save_handler', 'files');
        ini_set('session.save_path', '/var/www/labus_pro_usr/data/tmp');
        ini_set('session.gc_probability', 1);
        ini_set('session.gc_divisor', 100);
    }

    $defaultLifetime = 7200;
    ini_set('session.cookie_lifetime', $defaultLifetime);
    ini_set('session.gc_maxlifetime', 2592000);

    session_start([
        'cookie_lifetime' => $defaultLifetime,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
    ]);
}
```

**Эффект:** уменьшение задержек на сессию до единиц миллисекунд.

### 4.2. Redis как application cache

Создаём простой класс `RedisCache` и оборачиваем частые SELECT’ы.

**Пример кэша для меню:**

```php
class RedisCache {
    /* Singleton */
}

// В Database::__construct():
$this->cache = RedisCache::getInstance();

public function getMenuItems($category = null)
{
    $key = 'menu_items:' . ($category ?? 'all');
    if (($cached = $this->cache->get($key)) !== null) {
        return $cached;
    }

    $sql = "SELECT ... FROM menu_items WHERE available = 1";
    // + фильтрация по категории

    $stmt = $this->prepareCached($sql);
    ...
    $data = $stmt->fetchAll();
    $this->cache->set($key, $data, 600); // 10 минут
    return $data;
}
```

Кэшировать в первую очередь:
- меню и категории;
- настройки заведения;
- предвычисленные отчёты (с TTL 1–5 минут).

### 4.3. Улучшение FastCGI Cache в Nginx

**Суть:** вынести `PHPSESSID` из ключа кэша для публичных страниц.

Текущий ключ:
```nginx
fastcgi_cache_key "$scheme$request_method$host$request_uri$cookie_PHPSESSID";
```

Рекомендуемый подход:

```nginx
set $skip_cache 0;

if ($request_method = POST) { set $skip_cache 1; }
if ($http_cookie ~* "PHPSESSID") { set $skip_cache 1; }
if ($request_uri ~* "/admin-menu|/owner|/employee|/account|monitor|clear-cache|api|ws-poll|cart|checkout|order") {
    set $skip_cache 1;
}

location / {
    fastcgi_pass php_fpm_backend;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include /etc/nginx/fastcgi_params;

    fastcgi_cache CAFECACHE;
    fastcgi_cache_bypass $skip_cache;
    fastcgi_no_cache $skip_cache;

    fastcgi_cache_key "$scheme$request_method$host$request_uri";

    fastcgi_cache_valid 200 302 10m;
    fastcgi_cache_valid 301 1h;
    fastcgi_cache_valid 404 1m;
    fastcgi_cache_valid any 1m;

    fastcgi_cache_use_stale error timeout updating invalid_header http_500 http_503;
    fastcgi_cache_background_update on;
    fastcgi_cache_lock on;
    fastcgi_cache_lock_timeout 5s;
    fastcgi_cache_min_uses 2;

    add_header X-Cache-Status $upstream_cache_status always;
}
```

**Эффект:**
- повторные запросы к публичным страницам (меню) обслуживаются из Nginx без PHP и БД;
- нагрузка на PHP и MySQL падает в разы.

---

## 5. Этап 3 — Архитектура БД и отчёты

### 5.1. Материализованные агрегаты

**Проблема:** отчёты по заказам на лету через `JSON_TABLE` + JOIN’ы — тяжёлые.

**Решение:** отдельная таблица `order_aggregates` + триггеры.

```sql
CREATE TABLE order_aggregates (
    order_id INT PRIMARY KEY,
    user_id INT NOT NULL,
    order_date DATE NOT NULL,
    order_hour TINYINT NOT NULL,
    total_revenue DECIMAL(10,2) NOT NULL,
    total_expenses DECIMAL(10,2) NOT NULL,
    total_profit DECIMAL(10,2) NOT NULL,
    item_count INT NOT NULL,
    status VARCHAR(50) NOT NULL,
    delivery_type VARCHAR(50),
    processing_time_minutes INT,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_date_status (order_date, status),
    INDEX idx_user_date (user_id, order_date)
) ENGINE=InnoDB;
```

Триггер `AFTER INSERT ON orders` наполняет агрегаты. Отчёты читают из `order_aggregates`, а не из «сырых» `orders`.

### 5.2. Партиционирование таблиц заказов

При объёмах > 100k–200k заказов:

```sql
ALTER TABLE orders
PARTITION BY RANGE (YEAR(created_at) * 100 + MONTH(created_at)) (
    PARTITION p202401 VALUES LESS THAN (202402),
    PARTITION p202402 VALUES LESS THAN (202403),
    ...,
    PARTITION pmax VALUES LESS THAN MAXVALUE
);
```

Это ускоряет выборки по дате и обслуживание (backup/optimize).

---

## 6. Этап 4 — Frontend и PWA оптимизация

### 6.1. Критический CSS и отложенная загрузка

- Вынести небольшой блок **critical CSS** напрямую в `<head>`.
- Основной CSS грузить через `media="print" onload="this.media='all'"`.
- JS — только с `defer` и внизу `</body>`.

### 6.2. Resource Hints

В `<head>`:

```html
<link rel="dns-prefetch" href="//nominatim.openstreetmap.org">
<link rel="preconnect" href="https://nominatim.openstreetmap.org">
<link rel="prefetch" href="/menu.php" as="document">
<link rel="prefetch" href="/cart.php" as="document">
```

### 6.3. Service Worker стратегии

- Статика: Cache First
- HTML: Network First + fallback на кэш/`offline.html`
- API: Network First + кэширование в отдельный cache storage.

---

## 7. Этап 5 — Нагрузочное тестирование и мониторинг

### 7.1. Нагрузочные профили (не уронить прод)

Подход:
- начинать с небольших значений `RPS` и постепенно увеличивать;
- тестировать преимущественно **чтения** (GET-страницы меню) и выборочно **создание заказов**;
- мониторить в реальном времени: CPU, RAM, load, MySQL `Threads_running`, PHP-FPM `listen queue`.

### 7.2. Инструменты

1. **Apache Bench** (быстрый старт):
```bash
ab -n 1000 -c 10 https://menu.labus.pro/
```

2. **wrk** (предпочтительно):
```bash
wrk -t4 -c50 -d30s --latency https://menu.labus.pro/
```

3. **Locust** (сложные сценарии пользователей).

### 7.3. Мониторинг

- Включить `slow_query_log` в MySQL.
- PHP-FPM status page (`pm.status_path` + отдель location в Nginx).
- Скрипт, который каждые 5 минут собирает метрики и пишет в лог/БД.

---

## 8. План внедрения по дням

### День 1–2: быстрые выигрыши
- Поднять `pm.max_children` до 30 (или оптимального значения под вашу RAM).
- Включить/дотюнить OPcache, realpath cache.
- Применить индексы для ключевых запросов.
- Лёгкий стресс-тест (аб/wrk) + наблюдение за нагрузкой.

### День 3–4: Redis и FastCGI cache
- Установить Redis, перевести сессии на Redis.
- Добавить Redis-кэш для меню и настроек.
- Упростить ключ FastCGI cache (убрать PHPSESSID для публичных страниц).
- Повторный нагрузочный тест.

### Неделя 2: БД и отчёты
- Ввести `order_aggregates` и/или партиционирование таблиц.
- Переписать тяжёлые отчётные запросы на использование агрегатов.
- Оценить ускорение аналитики.

### Неделя 3: Frontend и PWA
- Вынести critical CSS.
- Добавить resource hints и оптимизировать Service Worker.
- Прогнать Lighthouse и WebPageTest.

### Неделя 4: Мониторинг и автоматизация
- Настроить сбор метрик PHP-FPM, MySQL, Redis, Nginx.
- Включить автоматическое обслуживание БД (ANALYZE/OPTIMIZE по крону).
- Добавить снапшоты backup’ов БД и файлов.

---

## 9. Ожидаемый прирост

При аккуратной реализации всех этапов:

- **RPS (запросов в секунду)**: рост с ~50 до 800+ на том же железе.
- **Средний TTFB**: снижение с 300–500 мс до 50–100 мс для основных страниц.
- **Нагрузка на БД**: падение числа «тяжёлых» запросов в 5–10 раз.
- **Устойчивость:** предсказуемое поведение под пиками (обед/вечер), меньше «спайков» latency.

---

## 10. Приоритетный мини-чеклист (если времени мало)

Если нужно «выжать максимум за 3–4 дня»:

1. **Сегодня**
   - Увеличить `pm.max_children` и включить OPcache JIT.
   - Добавить индексы (`orders`, `menu_items`, `users`).

2. **Завтра**
   - Внедрить Redis для сессий.
   - Включить Redis-кэш для меню.

3. **Послезавтра**
   - Упростить ключ FastCGI cache и настроить агрессивное кэширование публичных страниц.
   - Прогнать нагрузочный тест и замерить реальные цифры.

4. **4-й день**
   - Добавить базовый мониторинг (PHP-FPM status, slow_query_log, простые метрики в лог).

Это уже даст **кратный прирост** производительности без сложных миграций и серьёзных рисков.

---

**Файл подготовлен как дорожная карта для поэтапного внедрения. Рекомендуется вести отдельный changelog по каждому шагу (что сделано, какие метрики до/после).**
