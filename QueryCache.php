<?php
/**
 * QueryCache - система кэширования запросов к БД на уровне приложения
 * 
 * Особенности:
 * - In-memory кэширование в рамках одного запроса
 * - TTL (Time To Live) для каждой записи
 * - LRU (Least Recently Used) eviction при превышении лимита памяти
 * - Поддержка инвалидации по паттерну
 * - Мониторинг hit rate
 */

class QueryCache {
    private static $instance = null;
    private $cache = [];
    private $hits = 0;
    private $misses = 0;
    private $memoryLimit;
    private $defaultTTL;
    
    // Статистика использования памяти
    private $memoryUsage = 0;
    private $maxMemoryUsage = 0;
    
    // Приватный конструктор (Singleton)
    private function __construct() {
        $this->memoryLimit = 10 * 1024 * 1024; // 10MB по умолчанию
        $this->defaultTTL = 300; // 5 минут по умолчанию
        
        // Загружаем настройки из конфига если есть
        if (defined('QUERY_CACHE_MEMORY_LIMIT')) {
            $this->memoryLimit = QUERY_CACHE_MEMORY_LIMIT;
        }
        
        if (defined('QUERY_CACHE_DEFAULT_TTL')) {
            $this->defaultTTL = QUERY_CACHE_DEFAULT_TTL;
        }
    }
    
    /**
     * Получить экземпляр QueryCache (Singleton)
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Получить значение из кэша
     * 
     * @param string $key Ключ кэша
     * @return mixed|null Значение или null если не найдено/истек TTL
     */
    public function get($key) {
        if (!isset($this->cache[$key])) {
            $this->misses++;
            return null;
        }
        
        $item = $this->cache[$key];
        
        // Проверка TTL
        if (time() > $item['expires']) {
            $this->remove($key);
            $this->misses++;
            return null;
        }
        
        // Обновляем время последнего доступа (для LRU)
        $this->cache[$key]['last_access'] = time();
        
        $this->hits++;
        return $item['data'];
    }
    
    /**
     * Сохранить значение в кэш
     * 
     * @param string $key Ключ кэша
     * @param mixed $data Данные для кэширования
     * @param int|null $ttl TTL в секундах (null = использовать defaultTTL)
     * @return bool Успех операции
     */
    public function set($key, $data, $ttl = null) {
        $ttl = $ttl ?? $this->defaultTTL;
        
        // Проверяем лимит памяти
        $dataSize = $this->calculateSize($data);
        $keySize = strlen($key);
        $totalSize = $dataSize + $keySize + 100; // +100 байт на метаданные
        
        if ($totalSize > $this->memoryLimit) {
            error_log("QueryCache: Data too large for cache (size: {$totalSize}, limit: {$this->memoryLimit})");
            return false;
        }
        
        // Освобождаем память если нужно
        while ($this->memoryUsage + $totalSize > $this->memoryLimit && !empty($this->cache)) {
            $this->evictOldest();
        }
        
        // Сохраняем в кэш
        $this->cache[$key] = [
            'data' => $data,
            'expires' => time() + $ttl,
            'created' => time(),
            'last_access' => time(),
            'size' => $totalSize
        ];
        
        $this->memoryUsage += $totalSize;
        $this->maxMemoryUsage = max($this->maxMemoryUsage, $this->memoryUsage);
        
        return true;
    }
    
    /**
     * Удалить значение из кэша
     * 
     * @param string $key Ключ кэша
     * @return bool Успех операции
     */
    public function remove($key) {
        if (isset($this->cache[$key])) {
            $this->memoryUsage -= $this->cache[$key]['size'];
            unset($this->cache[$key]);
            return true;
        }
        return false;
    }
    
    /**
     * Инвалидировать кэш по паттерну
     * 
     * @param string $pattern Регулярное выражение для поиска ключей
     * @return int Количество удаленных записей
     */
    public function invalidate($pattern) {
        $removed = 0;
        
        foreach ($this->cache as $key => $item) {
            if (preg_match($pattern, $key)) {
                $this->remove($key);
                $removed++;
            }
        }
        
        return $removed;
    }
    
    /**
     * Очистить весь кэш
     */
    public function clear() {
        $this->cache = [];
        $this->memoryUsage = 0;
        $this->hits = 0;
        $this->misses = 0;
    }
    
    /**
     * Удалить устаревшие записи
     * 
     * @return int Количество удаленных записей
     */
    public function cleanup() {
        $removed = 0;
        $now = time();
        
        foreach ($this->cache as $key => $item) {
            if ($now > $item['expires']) {
                $this->remove($key);
                $removed++;
            }
        }
        
        return $removed;
    }
    
    /**
     * Получить статистику кэша
     */
    public function getStats() {
        $now = time();
        $expired = 0;
        
        foreach ($this->cache as $item) {
            if ($now > $item['expires']) {
                $expired++;
            }
        }
        
        $totalRequests = $this->hits + $this->misses;
        $hitRate = $totalRequests > 0 ? ($this->hits / $totalRequests) * 100 : 0;
        
        return [
            'items' => count($this->cache),
            'hits' => $this->hits,
            'misses' => $this->misses,
            'hit_rate' => round($hitRate, 2),
            'memory_usage' => $this->formatBytes($this->memoryUsage),
            'memory_limit' => $this->formatBytes($this->memoryLimit),
            'memory_percentage' => round(($this->memoryUsage / $this->memoryLimit) * 100, 2),
            'max_memory_usage' => $this->formatBytes($this->maxMemoryUsage),
            'expired_items' => $expired,
            'default_ttl' => $this->defaultTTL
        ];
    }
    
    /**
     * Получить информацию о конкретном ключе
     */
    public function getKeyInfo($key) {
        if (!isset($this->cache[$key])) {
            return null;
        }
        
        $item = $this->cache[$key];
        $now = time();
        
        return [
            'key' => $key,
            'data_size' => $this->formatBytes($item['size']),
            'created' => date('Y-m-d H:i:s', $item['created']),
            'last_access' => date('Y-m-d H:i:s', $item['last_access']),
            'expires' => date('Y-m-d H:i:s', $item['expires']),
            'ttl_remaining' => max(0, $item['expires'] - $now),
            'is_expired' => $now > $item['expires']
        ];
    }
    
    /**
     * Получить все ключи в кэше
     */
    public function getAllKeys() {
        return array_keys($this->cache);
    }
    
    /**
     * Удалить самые старые записи (LRU eviction)
     */
    private function evictOldest() {
        if (empty($this->cache)) {
            return;
        }
        
        // Находим запись с самым старым временем доступа
        $oldestKey = null;
        $oldestTime = PHP_INT_MAX;
        
        foreach ($this->cache as $key => $item) {
            if ($item['last_access'] < $oldestTime) {
                $oldestTime = $item['last_access'];
                $oldestKey = $key;
            }
        }
        
        if ($oldestKey !== null) {
            $this->remove($oldestKey);
        }
    }
    
    /**
     * Рассчитать размер данных в байтах
     */
    private function calculateSize($data) {
        return strlen(serialize($data));
    }
    
    /**
     * Форматировать байты в читаемый вид
     */
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    /**
     * Генерация ключа кэша на основе SQL запроса и параметров
     */
    public static function generateKey($sql, $params = []) {
        $key = $sql;
        
        if (!empty($params)) {
            // Сортируем параметры для консистентности
            ksort($params);
            $key .= '|' . json_encode($params, JSON_UNESCAPED_UNICODE);
        }
        
        // Хэшируем для уменьшения размера ключа
        return 'query_' . md5($key);
    }
    
    /**
     * Генерация ключа для меню
     */
    public static function generateMenuKey($category = null) {
        return 'menu_items_' . ($category ?? 'all');
    }
    
    /**
     * Генерация ключа для пользователя
     */
    public static function generateUserKey($userId) {
        return 'user_' . $userId;
    }
    
    /**
     * Генерация ключа для категорий
     */
    public static function generateCategoriesKey() {
        return 'categories_all';
    }
}

/**
 * Вспомогательные функции для удобства использования
 */

/**
 * Получить значение из кэша запросов
 */
function query_cache_get($key) {
    return QueryCache::getInstance()->get($key);
}

/**
 * Сохранить значение в кэш запросов
 */
function query_cache_set($key, $data, $ttl = null) {
    return QueryCache::getInstance()->set($key, $data, $ttl);
}

/**
 * Удалить значение из кэша запросов
 */
function query_cache_remove($key) {
    return QueryCache::getInstance()->remove($key);
}

/**
 * Инвалидировать кэш по паттерну
 */
function query_cache_invalidate($pattern) {
    return QueryCache::getInstance()->invalidate($pattern);
}

/**
 * Очистить весь кэш запросов
 */
function query_cache_clear() {
    return QueryCache::getInstance()->clear();
}

/**
 * Получить статистику кэша
 */
function query_cache_stats() {
    return QueryCache::getInstance()->getStats();
}

// Автоматическая очистка устаревших записей при каждом 100-м запросе
if (rand(1, 100) === 1) {
    register_shutdown_function(function() {
        $cache = QueryCache::getInstance();
        $removed = $cache->cleanup();
        if ($removed > 0) {
            error_log("QueryCache: Cleaned up {$removed} expired entries");
        }
    });
}

// Глобальный экземпляр для обратной совместимости
$queryCache = QueryCache::getInstance();

?>