<?php
/**
 * Скрипт тестирования производительности
 * Измеряет результаты оптимизаций из дорожной карты
 */

header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Включить таймер
$start_time = microtime(true);
$start_memory = memory_get_usage();

// Подключение к БД
require_once 'db.php';

// Подключение QueryCache если доступен
$queryCacheAvailable = file_exists('QueryCache.php');
if ($queryCacheAvailable) {
    require_once 'QueryCache.php';
}

/**
 * Класс для тестирования производительности
 */
class PerformanceTest {
    private $db;
    private $queryCache;
    private $results = [];
    private $testCount = 0;
    
    public function __construct($db) {
        $this->db = $db;
        
        if (class_exists('QueryCache')) {
            $this->queryCache = QueryCache::getInstance();
        }
    }
    
    /**
     * Запустить все тесты
     */
    public function runAllTests() {
        echo "<h1>🧪 Тестирование производительности</h1>";
        echo "<p>Тестирование оптимизаций из дорожной карты</p>";
        echo "<hr>";
        
        $this->testDatabaseQueries();
        $this->testQueryCache();
        $this->testOpcachePerformance();
        $this->testMemoryUsage();
        $this->testConcurrentRequests();
        
        $this->printSummary();
    }
    
    /**
     * Тест производительности запросов к БД
     */
    private function testDatabaseQueries() {
        $this->startTest('Производительность запросов к БД');
        
        $iterations = 100;
        $times = [];
        
        // Тест без кэша
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->db->query("SELECT COUNT(*) as count FROM orders");
        }
        $times['no_cache'] = microtime(true) - $start;
        
        // Тест с QueryCache
        if ($this->queryCache) {
            $start = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $cacheKey = 'test_count_orders_' . $i;
                $result = $this->queryCache->get($cacheKey);
                if ($result === false) {
                    $result = $this->db->query("SELECT COUNT(*) as count FROM orders");
                    $this->queryCache->set($cacheKey, $result, 60);
                }
            }
            $times['with_cache'] = microtime(true) - $start;
        }
        
        $this->addResult('database_queries', [
            'iterations' => $iterations,
            'times' => $times,
            'improvement' => isset($times['with_cache']) ? 
                round(($times['no_cache'] - $times['with_cache']) / $times['no_cache'] * 100, 2) : 0
        ]);
    }
    
    /**
     * Тест эффективности QueryCache
     */
    private function testQueryCache() {
        if (!$this->queryCache) {
            $this->addResult('query_cache', [
                'available' => false,
                'message' => 'QueryCache не доступен'
            ]);
            return;
        }
        
        $this->startTest('Эффективность QueryCache');
        
        // Очистить кэш для чистого теста
        $this->queryCache->clear();
        
        $hits = 0;
        $misses = 0;
        $iterations = 50;
        
        for ($i = 0; $i < $iterations; $i++) {
            $cacheKey = 'test_query_' . ($i % 10); // 10 уникальных запросов
            
            if ($this->queryCache->get($cacheKey) !== false) {
                $hits++;
            } else {
                $misses++;
                // Имитация выполнения запроса
                usleep(1000); // 1ms задержка
                $this->queryCache->set($cacheKey, ['data' => 'test'], 60);
            }
        }
        
        $hitRate = $hits / ($hits + $misses) * 100;
        
        $this->addResult('query_cache', [
            'available' => true,
            'hits' => $hits,
            'misses' => $misses,
            'hit_rate' => round($hitRate, 2),
            'iterations' => $iterations,
            'status' => $hitRate > 70 ? 'good' : ($hitRate > 50 ? 'warning' : 'critical')
        ]);
    }
    
    /**
     * Тест производительности OPcache
     */
    private function testOpcachePerformance() {
        $this->startTest('Производительность OPcache');
        
        $opcacheEnabled = function_exists('opcache_get_status') && opcache_get_status()['opcache_enabled'];
        
        if (!$opcacheEnabled) {
            $this->addResult('opcache', [
                'enabled' => false,
                'message' => 'OPcache отключен'
            ]);
            return;
        }
        
        $status = opcache_get_status();
        $config = opcache_get_configuration();
        
        // Тест скорости выполнения PHP кода
        $iterations = 10000;
        $code = '<?php $a = 1; $b = 2; $c = $a + $b;';
        
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            eval($code);
        }
        $executionTime = microtime(true) - $start;
        
        $this->addResult('opcache', [
            'enabled' => true,
            'memory_usage' => round($status['memory_usage']['used_memory'] / 1024 / 1024, 2) . ' MB',
            'memory_free' => round($status['memory_usage']['free_memory'] / 1024 / 1024, 2) . ' MB',
            'hit_rate' => round($status['opcache_statistics']['opcache_hit_rate'], 2),
            'cached_scripts' => $status['opcache_statistics']['num_cached_scripts'],
            'execution_time' => round($executionTime, 4) . ' сек',
            'iterations' => $iterations,
            'status' => $status['opcache_statistics']['opcache_hit_rate'] > 90 ? 'good' : 
                       ($status['opcache_statistics']['opcache_hit_rate'] > 70 ? 'warning' : 'critical')
        ]);
    }
    
    /**
     * Тест использования памяти
     */
    private function testMemoryUsage() {
        $this->startTest('Использование памяти');
        
        // Тест памяти для различных операций
        $memoryTests = [];
        
        // Тест 1: Создание массива
        $startMemory = memory_get_usage();
        $array = [];
        for ($i = 0; $i < 10000; $i++) {
            $array[] = 'test_string_' . $i;
        }
        $memoryTests['array_10000'] = memory_get_usage() - $startMemory;
        
        // Тест 2: Создание объектов
        $startMemory = memory_get_usage();
        $objects = [];
        for ($i = 0; $i < 1000; $i++) {
            $objects[] = new stdClass();
        }
        $memoryTests['objects_1000'] = memory_get_usage() - $startMemory;
        
        // Тест 3: Работа с БД
        $startMemory = memory_get_usage();
        $result = $this->db->query("SELECT * FROM orders LIMIT 100");
        $memoryTests['db_query_100'] = memory_get_usage() - $startMemory;
        
        $this->addResult('memory_usage', [
            'tests' => array_map(function($bytes) {
                return round($bytes / 1024, 2) . ' KB';
            }, $memoryTests),
            'peak_memory' => round(memory_get_peak_usage() / 1024 / 1024, 2) . ' MB',
            'current_memory' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB'
        ]);
    }
    
    /**
     * Тест конкурентных запросов
     */
    private function testConcurrentRequests() {
        $this->startTest('Конкурентные запросы (имитация)');
        
        // Имитация 10 параллельных запросов
        $concurrent = 10;
        $totalTime = 0;
        
        for ($i = 0; $i < $concurrent; $i++) {
            $start = microtime(true);
            
            // Имитация работы приложения
            $this->db->query("SELECT 1");
            usleep(5000); // 5ms задержка
            
            $totalTime += microtime(true) - $start;
        }
        
        $avgTime = $totalTime / $concurrent;
        
        $this->addResult('concurrent_requests', [
            'concurrent' => $concurrent,
            'total_time' => round($totalTime, 4) . ' сек',
            'avg_time_per_request' => round($avgTime * 1000, 2) . ' мс',
            'requests_per_second' => round(1 / $avgTime, 2),
            'status' => $avgTime < 0.01 ? 'good' : ($avgTime < 0.05 ? 'warning' : 'critical')
        ]);
    }
    
    /**
     * Начать новый тест
     */
    private function startTest($name) {
        $this->testCount++;
        echo "<h3>Тест {$this->testCount}: {$name}</h3>";
    }
    
    /**
     * Добавить результат теста
     */
    private function addResult($key, $data) {
        $this->results[$key] = $data;
        
        // Вывести результат
        echo "<div style='background: #f5f5f5; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "<pre>" . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";
        echo "</div>";
    }
    
    /**
     * Вывести итоговый отчет
     */
    private function printSummary() {
        $totalTime = microtime(true) - $GLOBALS['start_time'];
        $totalMemory = memory_get_peak_usage() - $GLOBALS['start_memory'];
        
        echo "<hr>";
        echo "<h2>📊 Итоговый отчет</h2>";
        
        // Сводная таблица
        echo "<table border='1' cellpadding='10' cellspacing='0' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr style='background: #e9ecef;'>";
        echo "<th>Тест</th><th>Статус</th><th>Результат</th><th>Рекомендации</th>";
        echo "</tr>";
        
        foreach ($this->results as $key => $result) {
            $status = $result['status'] ?? 'unknown';
            $statusText = $this->getStatusText($status);
            $statusColor = $this->getStatusColor($status);
            
            $resultText = $this->getResultText($key, $result);
            $recommendation = $this->getRecommendation($key, $result);
            
            echo "<tr>";
            echo "<td><strong>" . $this->getTestName($key) . "</strong></td>";
            echo "<td style='background: {$statusColor}; color: white;'>{$statusText}</td>";
            echo "<td>{$resultText}</td>";
            echo "<td>{$recommendation}</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        
        // Общая статистика
        echo "<div style='margin-top: 20px; padding: 15px; background: #e3f2fd; border-radius: 5px;'>";
        echo "<h3>📈 Общая статистика тестирования</h3>";
        echo "<p><strong>Общее время выполнения:</strong> " . round($totalTime, 3) . " сек</p>";
        echo "<p><strong>Пиковое использование памяти:</strong> " . round(memory_get_peak_usage() / 1024 / 1024, 2) . " MB</p>";
        echo "<p><strong>Количество тестов:</strong> {$this->testCount}</p>";
        echo "</div>";
        
        // Рекомендации по оптимизации
        echo "<div style='margin-top: 20px; padding: 15px; background: #fff3cd; border-radius: 5px;'>";
        echo "<h3>🚀 Рекомендации по оптимизации</h3>";
        echo "<ul>";
        
        if (isset($this->results['opcache']) && !$this->results['opcache']['enabled']) {
            echo "<li><strong>Включите OPcache</strong> - это ускорит выполнение PHP скриптов в 2-5 раз</li>";
        }
        
        if (isset($this->results['query_cache']) && !$this->results['query_cache']['available']) {
            echo "<li><strong>Внедрите QueryCache</strong> - уменьшит нагрузку на БД на 30-70%</li>";
        }
        
        if (isset($this->results['database_queries']) && $this->results['database_queries']['improvement'] < 50) {
            echo "<li><strong>Оптимизируйте запросы к БД</strong> - добавьте индексы и используйте кэширование</li>";
        }
        
        if (isset($this->results['concurrent_requests']) && $this->results['concurrent_requests']['avg_time_per_request'] > '50 мс') {
            echo "<li><strong>Рассмотрите FastCGI Cache</strong> - для кэширования динамических страниц</li>";
        }
        
        echo "</ul>";
        echo "</div>";
    }
    
    /**
     * Вспомогательные методы
     */
    private function getStatusText($status) {
        switch ($status) {
            case 'good': return '✓ Отлично';
            case 'warning': return '⚠ Требует внимания';
            case 'critical': return '✗ Критично';
            default: return '? Неизвестно';
        }
    }
    
    private function getStatusColor($status) {
        switch ($status) {
            case 'good': return '#28a745';
            case 'warning': return '#ffc107';
            case 'critical': return '#dc3545';
            default: return '#6c757d';
        }
    }
    
    private function getTestName($key) {
        $names = [
            'database_queries' => 'Запросы к БД',
            'query_cache' => 'Query Cache',
            'opcache' => 'OPcache',
            'memory_usage' => 'Использование памяти',
            'concurrent_requests' => 'Конкурентные запросы'
        ];
        return $names[$key] ?? $key;
    }
    
    private function getResultText($key, $result) {
        switch ($key) {
            case 'database_queries':
                $improvement = $result['improvement'] ?? 0;
                return "Ускорение с кэшем: {$improvement}%";
                
            case 'query_cache':
                if (!$result['available']) return 'Не доступен';
                return "Hit Rate: {$result['hit_rate']}%";
                
            case 'opcache':
                if (!$result['enabled']) return 'Отключен';
                return "Hit Rate: {$result['hit_rate']}%, Скриптов: {$result['cached_scripts']}";
                
            case 'memory_usage':
                return "Пик: {$result['peak_memory']}";
                
            case 'concurrent_requests':
                return "Среднее время: {$result['avg_time_per_request']}";
                
            default:
                return json_encode($result, JSON_UNESCAPED_UNICODE);
        }
    }
    
    private function getRecommendation($key, $result) {
        switch ($key) {
            case 'database_queries':
                $improvement = $result['improvement'] ?? 0;
                if ($improvement < 30) {
                    return 'Увеличьте время жизни кэша и оптимизируйте запросы';
                } elseif ($improvement < 60) {
                    return 'Хороший результат, можно улучшить стратегию инвалидации';
                } else {
                    return 'Отличный результат!';
                }
                
            case 'query_cache':
                if (!$result['available']) {
                    return 'Установите QueryCache.php в корень проекта';
                }
                $hitRate = $result['hit_rate'] ?? 0;
                if ($hitRate < 50) {
                    return 'Увеличьте разнообразие кэшируемых запросов';
                } elseif ($hitRate < 80) {
                    return 'Хорошо, оптимизируйте TTL для часто меняющихся данных';
                } else {
                    return 'Отлично! Кэш работает эффективно';
                }
                
            case 'opcache':
                if (!$result['enabled']) {
                    return 'Включите OPcache в php.ini';
                }
                $hitRate = $result['hit_rate'] ?? 0;
                if ($hitRate < 70) {
                    return 'Увеличьте memory_consumption и max_accelerated_files';
                } elseif ($hitRate < 90) {
                    return 'Хорошо, мониторьте использование памяти';
                } else {
                    return 'Отлично! OPcache работает оптимально';
                }
                
            case 'memory_usage':
                $peak = (float) str_replace([' MB', ' KB'], '', $result['peak_memory']);
                if ($peak > 256) {
                    return 'Оптимизируйте использование памяти, возможны утечки';
                } elseif ($peak > 128) {
                    return 'Приемлемо, но можно улучшить';
                } else {
                    return 'Отлично! Эффективное использование памяти';
                }
                
            case 'concurrent_requests':
                $avgTime = (float) str_replace([' мс'], '', $result['avg_time_per_request']);
                if ($avgTime > 100) {
                    return 'Критично! Оптимизируйте запросы и используйте кэширование';
                } elseif ($avgTime > 50) {
                    return 'Требует оптимизации, рассмотрите FastCGI Cache';
                } else {
                    return 'Отлично! Приложение хорошо масштабируется';
                }
                
            default:
                return 'Нет рекомендаций';
        }
    }
}

// Запуск тестирования
echo "<!DOCTYPE html>
<html lang='ru'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Тестирование производительности</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f8f9fa; }
        h1, h2, h3 { color: #333; }
        .container { max-width: 1200px; margin: 0 auto; }
        .test-result { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .status-good { color: #28a745; font-weight: bold; }
        .status-warning { color: #ffc107; font-weight: bold; }
        .status-critical { color: #dc3545; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #e9ecef; }
        tr:hover { background: #f5f5f5; }
    </style>
</head>
<body>
    <div class='container'>";

try {
    $db = Database::getInstance();
    $tester = new PerformanceTest($db);
    $tester->runAllTests();
} catch (Exception $e) {
    echo "<div class='test-result status-critical'>";
    echo "<h3>Ошибка при тестировании</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Убедитесь, что база данных доступна и файл db.php настроен правильно.</p>";
    echo "</div>";
}

echo "</div>
</body>
</html>";