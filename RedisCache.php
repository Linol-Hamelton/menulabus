<?php
/**
 * RedisCache - кэширование данных в Redis с fallback на in-memory кэш
 * 
 * Особенности:
 * - Автоматическое определение доступности Redis
 * - TTL для каждой записи
 * - Поддержка инвалидации по паттерну
 * - Статистика hit rate
 */

class RedisCache {
    private static $instance = null;
    private $redis = null;
    private $available = false;
    private $hits = 0;
    private $misses = 0;
    private $memoryCache = []; // fallback in-memory cache
    
    // Приватный конструктор (Singleton)
    private function __construct() {
        $this->available = $this->connect();
    }
    
    /**
     * Получить экземпляр RedisCache (Singleton)
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Подключиться к Redis
     */
    private function connect() {
        if (!extension_loaded('redis') || !class_exists('Redis')) {
            error_log('RedisCache: Redis extension not loaded');
            return false;
        }
        
        try {
            $this->redis = new Redis();
            // Параметры подключения можно вынести в конфигурацию
            $host = '127.0.0.1';
            $port = 6379;
            $timeout = 2.5;
            $connected = $this->redis->connect($host, $port, $timeout);
            
            if (!$connected) {
                error_log('RedisCache: Connection failed');
                return false;
            }
            
            // Проверка доступности
            $this->redis->ping();

            // Кэш использует database 1, чтобы не пересекаться с сессиями (database 0).
            // flushDB() очистит только кэш, не затрагивая сессии пользователей.
            $this->redis->select(1);

            return true;
        } catch (Exception $e) {
            error_log('RedisCache: Connection exception - ' . $e->getMessage());
            $this->redis = null;
            return false;
        }
    }
    
    /**
     * Сопоставить ключ с паттерном (поддержка fnmatch)
     */
    private function matchPattern($pattern, $key) {
        if (function_exists('fnmatch')) {
            return fnmatch($pattern, $key);
        }
        // Простая замена: преобразуем * в .*
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
        return preg_match($regex, $key) === 1;
    }

    /**
     * Получить значение из кэша
     *
     * @param string $key Ключ кэша
     * @return mixed|null Значение или null если не найдено/истек TTL
     */
    public function get($key) {
        // Пробуем Redis
        if ($this->available && $this->redis) {
            try {
                $value = $this->redis->get($key);
                if ($value !== false) {
                    $this->hits++;
                    return unserialize($value);
                }
            } catch (Exception $e) {
                error_log('RedisCache get error: ' . $e->getMessage());
                $this->available = false;
            }
        }
        
        // Fallback на in-memory кэш
        if (isset($this->memoryCache[$key])) {
            $item = $this->memoryCache[$key];
            if (time() <= $item['expires']) {
                $this->hits++;
                return $item['data'];
            } else {
                unset($this->memoryCache[$key]);
            }
        }
        
        $this->misses++;
        return null;
    }

    /**
     * Batch get (best-effort): returns an associative array key => value (null if missing).
     * Uses Redis MGET when available; falls back to individual get().
     *
     * @param string[] $keys
     * @return array<string,mixed|null>
     */
    public function mget(array $keys): array {
        $out = [];
        if (empty($keys)) {
            return $out;
        }

        // Ensure stable order and string keys.
        $keys = array_values(array_map('strval', $keys));

        if ($this->available && $this->redis) {
            try {
                $values = $this->redis->mGet($keys);
                if (is_array($values)) {
                    foreach ($keys as $i => $k) {
                        $v = $values[$i] ?? false;
                        if ($v !== false && $v !== null) {
                            $this->hits++;
                            $out[$k] = @unserialize($v);
                        } else {
                            $this->misses++;
                            $out[$k] = null;
                        }
                    }
                    return $out;
                }
            } catch (Exception $e) {
                error_log('RedisCache mget error: ' . $e->getMessage());
                $this->available = false;
            }
        }

        // Fallback.
        foreach ($keys as $k) {
            $out[$k] = $this->get($k);
        }
        return $out;
    }

    /**
     * Batch set (best-effort) with a single TTL for all keys.
     * Uses Redis pipeline when available; falls back to individual set().
     *
     * @param array<string,mixed> $items
     * @param int $ttl
     * @return bool
     */
    public function mset(array $items, int $ttl = 300): bool {
        if (empty($items)) {
            return true;
        }

        if ($this->available && $this->redis) {
            try {
                $this->redis->multi(Redis::PIPELINE);
                foreach ($items as $k => $data) {
                    $this->redis->setex((string)$k, $ttl, serialize($data));
                    $this->memoryCache[(string)$k] = [
                        'data' => $data,
                        'expires' => time() + $ttl
                    ];
                }
                $this->redis->exec();
                return true;
            } catch (Exception $e) {
                error_log('RedisCache mset error: ' . $e->getMessage());
                $this->available = false;
            }
        }

        foreach ($items as $k => $data) {
            $this->set((string)$k, $data, $ttl);
        }
        return true;
    }
    
    /**
     * Сохранить значение в кэш
     * 
     * @param string $key Ключ кэша
     * @param mixed $data Данные для кэширования
     * @param int $ttl TTL в секундах (по умолчанию 300)
     * @return bool Успех операции
     */
    public function set($key, $data, $ttl = 300) {
        $serialized = serialize($data);
        
        // Пробуем Redis
        if ($this->available && $this->redis) {
            try {
                $success = $this->redis->setex($key, $ttl, $serialized);
                if ($success) {
                    // Также сохраняем в memory cache для быстрого доступа
                    $this->memoryCache[$key] = [
                        'data' => $data,
                        'expires' => time() + $ttl
                    ];
                    return true;
                }
            } catch (Exception $e) {
                error_log('RedisCache set error: ' . $e->getMessage());
                $this->available = false;
            }
        }
        
        // Fallback на in-memory кэш
        $this->memoryCache[$key] = [
            'data' => $data,
            'expires' => time() + $ttl
        ];
        return true;
    }
    
    /**
     * Удалить значение из кэша
     * 
     * @param string $key Ключ кэша
     * @return bool Успех операции
     */
    public function delete($key) {
        // Redis
        if ($this->available && $this->redis) {
            try {
                $this->redis->del($key);
            } catch (Exception $e) {
                error_log('RedisCache delete error: ' . $e->getMessage());
            }
        }
        
        // Memory
        unset($this->memoryCache[$key]);
        return true;
    }
    
    /**
     * Инвалидировать кэш по паттерну
     * 
     * @param string $pattern Паттерн ключей (поддерживает *)
     * @return int Количество удаленных записей
     */
    public function invalidate($pattern) {
        $deleted = 0;
        
        // Redis
        if ($this->available && $this->redis) {
            try {
                // Use SCAN to avoid blocking Redis on large keyspaces.
                $iterator = null;
                do {
                    $keys = $this->redis->scan($iterator, $pattern, 500);
                    if ($keys !== false && !empty($keys)) {
                        $deletedInBatch = $this->redis->del($keys);
                        $deleted += is_int($deletedInBatch) ? $deletedInBatch : count($keys);
                    }
                } while ($iterator !== 0);
            } catch (Exception $e) {
                error_log('RedisCache invalidate error: ' . $e->getMessage());
            }
        }
        
        // Memory (простой перебор)
        foreach ($this->memoryCache as $key => $item) {
            if ($this->matchPattern($pattern, $key)) {
                unset($this->memoryCache[$key]);
                $deleted++;
            }
        }
        
        return $deleted;
    }
    
    /**
     * Очистить весь кэш
     */
    public function clear() {
        // Redis
        if ($this->available && $this->redis) {
            try {
                $this->redis->flushDB();
            } catch (Exception $e) {
                error_log('RedisCache clear error: ' . $e->getMessage());
            }
        }
        
        // Memory
        $this->memoryCache = [];
        $this->hits = 0;
        $this->misses = 0;
        return true;
    }
    
    /**
     * Получить статистику кэша
     */
    public function getStats() {
        $totalRequests = $this->hits + $this->misses;
        $hitRate = $totalRequests > 0 ? ($this->hits / $totalRequests) * 100 : 0;
        
        return [
            'available' => $this->available,
            'hits' => $this->hits,
            'misses' => $this->misses,
            'hit_rate' => round($hitRate, 2),
            'memory_items' => count($this->memoryCache),
            'redis_connected' => $this->available && $this->redis ? true : false
        ];
    }
    
    /**
     * Проверить доступность Redis
     */
    public function isAvailable() {
        return $this->available;
    }
}

// Вспомогательные функции для удобства использования

/**
 * Получить значение из Redis кэша
 */
function redis_cache_get($key) {
    return RedisCache::getInstance()->get($key);
}

/**
 * Сохранить значение в Redis кэш
 */
function redis_cache_set($key, $data, $ttl = 300) {
    return RedisCache::getInstance()->set($key, $data, $ttl);
}

/**
 * Удалить значение из Redis кэша
 */
function redis_cache_delete($key) {
    return RedisCache::getInstance()->delete($key);
}

/**
 * Инвалидировать кэш по паттерну
 */
function redis_cache_invalidate($pattern) {
    return RedisCache::getInstance()->invalidate($pattern);
}

/**
 * Очистить весь Redis кэш
 */
function redis_cache_clear() {
    return RedisCache::getInstance()->clear();
}

/**
 * Получить статистику Redis кэша
 */
function redis_cache_stats() {
    return RedisCache::getInstance()->getStats();
}

?>
