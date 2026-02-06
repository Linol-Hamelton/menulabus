# Установка и настройка Redis для menu.labus.pro

## 1. Установка Redis сервера (Ubuntu/Debian)

```bash
sudo apt update
sudo apt install redis-server -y
```

## 2. Базовая оптимизация конфигурации Redis

Отредактируйте `/etc/redis/redis.conf`:

```conf
maxmemory 512mb
maxmemory-policy allkeys-lru

# Сохранение на диск (можно уменьшить для производительности)
save 900 1
save 300 10
save 60 10000

# Отключение опасных команд
rename-command FLUSHDB ""
rename-command FLUSHALL ""
rename-command KEYS ""

# Привязка к localhost (безопасность)
bind 127.0.0.1

# Защита паролем (опционально)
# requirepass ваш_пароль
```

Перезапустите Redis:

```bash
sudo systemctl restart redis-server
sudo systemctl enable redis-server
```

## 3. Установка PHP расширения Redis

```bash
sudo apt install php-redis -y
```

Или через PECL:

```bash
sudo pecl install redis
```

Добавьте расширение в php.ini:

```ini
extension=redis.so
```

Перезапустите PHP-FPM:

```bash
sudo systemctl restart php8.1-fpm  # версия PHP может отличаться
```

## 4. Проверка работы Redis

Создайте тестовый скрипт `test-redis.php`:

```php
<?php
$redis = new Redis();
$connected = $redis->connect('127.0.0.1', 6379, 2.5);
if ($connected) {
    echo "Redis connection successful\n";
    $redis->set('test_key', 'Hello Redis');
    echo "Value: " . $redis->get('test_key') . "\n";
} else {
    echo "Redis connection failed\n";
}
```

Выполните:

```bash
php test-redis.php
```

## 5. Настройка сессий PHP на Redis

В проекте уже реализована автоматическая переключение на Redis при наличии расширения (см. `session_init.php`).

Убедитесь, что в `php.ini` нет переопределения `session.save_handler` (или установите):

```ini
session.save_handler = redis
session.save_path = "tcp://127.0.0.1:6379?timeout=2.5&database=0"
```

Но лучше оставить как есть, так как `session_init.php` динамически выбирает Redis.

## 6. Настройка Redis как application cache

Класс `RedisCache` уже создан в `RedisCache.php`. Он автоматически определяет доступность Redis и использует in-memory fallback.

Чтобы использовать RedisCache в коде, можно заменить вызовы `QueryCache` на `RedisCache` для данных, которые должны быть общими между воркерами PHP-FPM.

Пример использования:

```php
$cache = RedisCache::getInstance();
$data = $cache->get('menu_items_all');
if (!$data) {
    $data = fetchFromDatabase();
    $cache->set('menu_items_all', $data, 600);
}
```

## 7. Мониторинг Redis

Установите redis-cli и проверьте статистику:

```bash
redis-cli info
```

Ключевые метрики:
- `used_memory` - потребляемая память
- `connected_clients` - количество подключений
- `keyspace_hits` / `keyspace_misses` - эффективность кэша

## 8. Резервное копирование и обслуживание

Redis данные хранятся в памяти, но периодически сохраняются на диск. Рекомендуется настроить ежедневное копирование RDB файла.

Путь к файлу дампа указан в `redis.conf` (`dbfilename dump.rdb`).

## 9. Примечания для Beget

На хостинге Beget может быть ограниченный доступ к установке пакетов. Обратитесь в поддержку для установки Redis и PHP расширения.

Если установка Redis невозможна, можно использовать Memcached (также поддерживается в `session_init.php`).