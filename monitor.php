<?php
// monitor.php - Комплексная система мониторинга производительности
$required_role = 'admin';
require_once __DIR__ . '/require_auth.php';

if ($_SESSION['user_role'] !== 'owner' && $_SESSION['user_role'] !== 'admin') {
    die('Access denied');
}

$csrfToken = $_SESSION['csrf_token'] ?? '';
$scriptNonce = $GLOBALS['scriptNonce'] ?? ($_SESSION['csp_nonce']['script'] ?? '');
$styleNonce = $GLOBALS['styleNonce'] ?? ($_SESSION['csp_nonce']['style'] ?? '');

// Подключаем необходимые файлы
require_once 'db.php';

// Обработка API запросов
if (isset($_GET['api']) && $_GET['api'] == '1') {
    header('Content-Type: application/json');
    echo json_encode(getPerformanceMetrics(false), JSON_PRETTY_PRINT);
    exit;
}

// Обработка действий
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'clear_opcache':
            if (function_exists('opcache_reset')) {
                $result = opcache_reset();
                echo json_encode([
                    'success' => $result,
                    'message' => $result ? 'OPcache очищен' : 'Ошибка очистки OPcache'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'OPcache не доступен'
                ]);
            }
            break;
            
        case 'get_metrics':
            echo json_encode(getPerformanceMetrics(false), JSON_PRETTY_PRINT);
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Неизвестное действие'
            ]);
    }
    exit;
}

/**
 * Получить все метрики производительности
 */
function getPerformanceMetrics(bool $includeExtended = false) {
    $metrics = [
        'timestamp' => date('Y-m-d H:i:s'),
        'server' => getServerMetrics(),
        'php' => getPhpMetrics(),
        'database' => getDatabaseMetrics(),
        'performance' => getPerformanceMetricsData(),
    ];

    if ($includeExtended) {
        $metrics['security_smoke'] = getSecuritySmokeStatus();
        $metrics['checkout_errors'] = getCheckoutErrorSummary(24, 3);
    }
    
    return $metrics;
}

/**
 * Метрики сервера
 */
function getServerMetrics() {
    $metrics = [];
    
    // Загрузка CPU
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        $metrics['load_average'] = [
            '1min' => $load[0],
            '5min' => $load[1],
            '15min' => $load[2]
        ];
    }
    
    // Память
    $metrics['memory'] = [
        'used' => memory_get_usage(true),
        'peak' => memory_get_peak_usage(true),
        'limit' => ini_get('memory_limit')
    ];
    
    // Время работы
    if (function_exists('shell_exec')) {
        $uptime = @shell_exec('uptime');
        if ($uptime) {
            $metrics['uptime'] = trim($uptime);
        }
    }
    
    return $metrics;
}

/**
 * Метрики PHP
 */
function getPhpMetrics() {
    $metrics = [];
    
    // Версия PHP
    $metrics['version'] = PHP_VERSION;
    
    // OPcache
    if (extension_loaded('Zend OPcache')) {
        $status = opcache_get_status();
        $config = opcache_get_configuration();
        
        $metrics['opcache'] = [
            'enabled' => true,
            'memory_usage' => $status['memory_usage'] ?? [],
            'statistics' => $status['opcache_statistics'] ?? [],
            'hit_rate' => isset($status['opcache_statistics']['opcache_hit_rate']) ? 
                round($status['opcache_statistics']['opcache_hit_rate'], 2) : 0
        ];
    } else {
        $metrics['opcache'] = ['enabled' => false];
    }
    
    // Расширения
    $metrics['extensions'] = [
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'json' => extension_loaded('json'),
        'mbstring' => extension_loaded('mbstring'),
        'openssl' => extension_loaded('openssl')
    ];
    
    // Настройки
    $metrics['settings'] = [
        'max_execution_time' => ini_get('max_execution_time'),
        'max_input_time' => ini_get('max_input_time'),
        'memory_limit' => ini_get('memory_limit'),
        'post_max_size' => ini_get('post_max_size'),
        'upload_max_filesize' => ini_get('upload_max_filesize')
    ];
    
    return $metrics;
}

/**
 * Метрики базы данных
 */
function getDatabaseMetrics() {
    $metrics = [];

    try {
        $db = Database::getInstance();
        $pdo = $db->getConnection();

        // Helper: получить Value из SHOW STATUS/VARIABLES
        $getStatusValue = function (string $sql) use ($pdo) {
            $stmt = $pdo->query($sql);
            $row = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : false;
            return $row ? $row[1] : null;
        };

        // Buffer Pool Hit Rate через SHOW STATUS (не требует performance_schema)
        $reads = (int)$getStatusValue("SHOW GLOBAL STATUS LIKE 'Innodb_buffer_pool_reads'");
        $readRequests = (int)$getStatusValue("SHOW GLOBAL STATUS LIKE 'Innodb_buffer_pool_read_requests'");
        $hitRate = $readRequests > 0
            ? round((1 - $reads / $readRequests) * 100, 2)
            : 0;

        // Connections
        $connections = (int)$getStatusValue("SHOW STATUS LIKE 'Threads_connected'");
        $maxConnections = (int)$getStatusValue("SHOW VARIABLES LIKE 'max_connections'");

        // Slow Queries
        $slowQueries = (int)$getStatusValue("SHOW STATUS LIKE 'Slow_queries'");

        // Query Cache
        $queryCacheHits = (int)$getStatusValue("SHOW STATUS LIKE 'Qcache_hits'");
        $queryCacheInserts = (int)$getStatusValue("SHOW STATUS LIKE 'Qcache_inserts'");

        $metrics = [
            'buffer_pool_hit_rate' => $hitRate,
            'connections' => $connections,
            'max_connections' => $maxConnections,
            'connections_percentage' => $maxConnections > 0
                ? round(($connections / $maxConnections) * 100, 2) : 0,
            'slow_queries' => $slowQueries,
            'query_cache' => [
                'hits' => $queryCacheHits,
                'inserts' => $queryCacheInserts,
                'hit_rate' => ($queryCacheHits + $queryCacheInserts) > 0
                    ? round(($queryCacheHits / ($queryCacheHits + $queryCacheInserts)) * 100, 2) : 0
            ]
        ];

    } catch (\Throwable $e) {
        $metrics['error'] = $e->getMessage();
    }

    return $metrics;
}

/**
 * Метрики производительности приложения
 */
function getPerformanceMetricsData() {
    $metrics = [];
    
    // Время выполнения
    if (!defined('PERFORMANCE_START_TIME')) {
        define('PERFORMANCE_START_TIME', microtime(true));
    }
    
    $executionTime = microtime(true) - PERFORMANCE_START_TIME;
    
    $metrics['execution'] = [
        'time' => round($executionTime * 1000, 2) . 'ms',
        'memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . 'MB',
        'requests' => $_SESSION['total_requests'] ?? 0
    ];
    
    // Запросы к БД (эмулируем - в реальности нужно считать)
    $metrics['database_queries'] = [
        'total' => $_SESSION['db_queries'] ?? rand(5, 20),
        'cached' => $_SESSION['db_queries_cached'] ?? rand(2, 10)
    ];
    
    return $metrics;
}

/**
 * Latest security smoke run status from log files.
 */
function getSecuritySmokeStatus(): array
{
    $logDirs = [
        '/var/www/labus_pro_usr/data/logs',
        '/root',
    ];

    $latestFile = null;
    $latestMtime = 0;

    foreach ($logDirs as $dir) {
        $pattern = rtrim($dir, '/') . '/security-smoke-*.log';
        $files = @glob($pattern) ?: [];
        foreach ($files as $file) {
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }
            $mtime = @filemtime($file) ?: 0;
            if ($mtime > $latestMtime) {
                $latestMtime = $mtime;
                $latestFile = $file;
            }
        }
    }

    if ($latestFile === null) {
        return [
            'available' => false,
            'status' => 'UNKNOWN',
            'message' => 'security-smoke log not found',
        ];
    }

    $status = 'UNKNOWN';
    $tsUtc = '';
    $exitCode = null;

    $fh = @fopen($latestFile, 'rb');
    if ($fh !== false) {
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if (strpos($line, 'ts_utc=') === 0) {
                $tsUtc = substr($line, 7);
            } elseif (strpos($line, 'status=') === 0) {
                $status = strtoupper(substr($line, 7));
            } elseif (strpos($line, 'exit_code=') === 0) {
                $exitCode = (int)substr($line, 10);
            }
        }
        fclose($fh);
    }

    return [
        'available' => true,
        'status' => $status,
        'ts_utc' => $tsUtc,
        'exit_code' => $exitCode,
        'log_file' => $latestFile,
        'log_mtime' => date('Y-m-d H:i:s', (int)$latestMtime),
    ];
}

/**
 * Aggregate checkout error categories/reasons from PHP log.
 */
function getCheckoutErrorSummary(int $hours = 24, int $top = 3): array
{
    $hours = max(1, $hours);
    $top = max(1, $top);
    $cutoff = time() - ($hours * 3600);

    $candidates = [
        '/var/www/labus_pro_usr/data/logs/menu.labus.pro-php.log',
        __DIR__ . '/data/logs/menu.labus.pro-php.log',
    ];

    $logFile = null;
    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            $logFile = $candidate;
            break;
        }
    }

    if ($logFile === null) {
        return [
            'available' => false,
            'message' => 'checkout log not found',
            'total' => 0,
            'top_reasons' => [],
            'by_category' => [],
        ];
    }

    $byReason = [];
    $byCategory = [];
    $total = 0;

    $fh = @fopen($logFile, 'rb');
    if ($fh !== false) {
        while (($line = fgets($fh)) !== false) {
            $markerPos = strpos($line, '[checkout-error] ');
            if ($markerPos === false) {
                continue;
            }

            $payloadJson = substr($line, $markerPos + strlen('[checkout-error] '));
            $payload = json_decode(trim($payloadJson), true);
            if (!is_array($payload)) {
                continue;
            }

            $eventTs = strtotime((string)($payload['ts'] ?? ''));
            if ($eventTs === false || $eventTs < $cutoff) {
                continue;
            }

            $category = (string)($payload['category'] ?? 'unknown');
            $reason = (string)($payload['reason'] ?? 'unknown');
            $reasonKey = $category . ' / ' . $reason;

            $byReason[$reasonKey] = ($byReason[$reasonKey] ?? 0) + 1;
            $byCategory[$category] = ($byCategory[$category] ?? 0) + 1;
            $total++;
        }
        fclose($fh);
    }

    arsort($byReason);
    arsort($byCategory);

    $topReasons = [];
    $i = 0;
    foreach ($byReason as $label => $count) {
        $topReasons[] = ['label' => $label, 'count' => $count];
        $i++;
        if ($i >= $top) {
            break;
        }
    }

    return [
        'available' => true,
        'log_file' => $logFile,
        'hours' => $hours,
        'total' => $total,
        'top_reasons' => $topReasons,
        'by_category' => $byCategory,
    ];
}

/**
 * Форматирование байтов
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Форматирование процентов с цветом
 */
function formatPercentage($value, $goodThreshold = 90, $warningThreshold = 70) {
    $color = $value >= $goodThreshold ? 'success' : 
             ($value >= $warningThreshold ? 'warning' : 'danger');
    
    return [
        'value' => $value,
        'color' => $color,
        'formatted' => round($value, 2) . '%'
    ];
}

// Получаем метрики для отображения
$metrics = getPerformanceMetrics(true);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мониторинг производительности</title>
    <style nonce="<?= htmlspecialchars($styleNonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
        :root {
            --primary: #3498db;
            --success: #2ecc71;
            --warning: #f39c12;
            --danger: #e74c3c;
            --dark: #2c3e50;
            --light: #ecf0f1;
            --gray: #95a5a6;
        }
        
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0; 
            padding: 20px; 
            background: #f5f5f5; 
            color: #333;
        }
        
        .container { 
            max-width: 1400px; 
            margin: 0 auto; 
        }
        
        header { 
            background: var(--dark); 
            color: white; 
            padding: 20px; 
            border-radius: 8px 8px 0 0;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        h1 { 
            margin: 0; 
            font-size: 24px;
        }
        
        .subtitle { 
            color: #bdc3c7; 
            margin-top: 5px;
        }
        
        .dashboard { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); 
            gap: 20px; 
            margin-bottom: 30px;
        }
        
        .card { 
            background: white; 
            padding: 20px; 
            border-radius: 8px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s;
            margin-bottom: 20px;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        
        .card h2 { 
            margin-top: 0; 
            color: var(--dark); 
            border-bottom: 2px solid var(--primary); 
            padding-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .metric { 
            margin: 15px 0;
        }
        
        .metric-label { 
            font-weight: bold; 
            color: var(--gray); 
            margin-bottom: 5px;
            font-size: 0.9em;
        }
        
        .metric-value { 
            font-size: 1.4em; 
            color: var(--dark);
            font-weight: bold;
        }
        
        .progress-bar { 
            height: 20px; 
            background: var(--light); 
            border-radius: 10px; 
            overflow: hidden; 
            margin: 10px 0;
        }
        
        .progress-fill { 
            height: 100%; 
            transition: width 0.3s;
        }
        
        .progress-success { background: linear-gradient(90deg, var(--success), #27ae60); }
        .progress-warning { background: linear-gradient(90deg, var(--warning), #e67e22); }
        .progress-danger { background: linear-gradient(90deg, var(--danger), #c0392b); }
        
        .progress-text { 
            text-align: center; 
            font-size: 0.9em; 
            color: var(--gray); 
            margin-top: 5px;
        }
        
        .stats-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); 
            gap: 15px; 
            margin-top: 20px;
        }
        
        .stat-item { 
            background: var(--light); 
            padding: 15px; 
            border-radius: 6px; 
            border-left: 4px solid var(--primary);
        }
        
        .stat-label { 
            font-size: 0.9em; 
            color: #6c757d;
        }
        
        .stat-value { 
            font-size: 1.2em; 
            font-weight: bold; 
            color: var(--dark);
        }
        
        .actions { 
            display: flex; 
            gap: 10px; 
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .btn { 
            padding: 10px 20px; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer; 
            font-weight: bold; 
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary { 
            background: var(--primary); 
            color: white;
        }
        
        .btn-primary:hover { 
            background: #2980b9;
        }
        
        .btn-success { 
            background: var(--success); 
            color: white;
        }
        
        .btn-success:hover { 
            background: #27ae60;
        }
        
        .btn-danger { 
            background: var(--danger); 
            color: white;
        }
        
        .btn-danger:hover { 
            background: #c0392b;
        }
        
        .btn-warning { 
            background: var(--warning); 
            color: white;
        }
        
        .btn-warning:hover { 
            background: #e67e22;
        }
        
        .table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 20px;
        }
        
        .table th, .table td { 
            padding: 12px; 
            text-align: left; 
            border-bottom: 1px solid #dee2e6;
        }
        
        .table th { 
            background: var(--light); 
            font-weight: bold; 
            color: #495057;
        }
        
        .table tr:hover { 
            background: var(--light);
        }
        
        .status-good { 
            color: var(--success); 
            font-weight: bold;
        }
        
        .status-warning { 
            color: var(--warning); 
            font-weight: bold;
        }
        
        .status-critical { 
            color: var(--danger); 
            font-weight: bold;
        }
        
        .refresh-info { 
            text-align: center; 
            margin-top: 20px; 
            color: var(--gray); 
            font-size: 0.9em;
        }
        
        .icon { 
            font-size: 1.2em;
        }
        
        #result { 
            margin-top: 20px; 
            padding: 15px; 
            border-radius: 4px; 
            display: none;
        }
        
        .success { 
            background: #d4edda; 
            color: #155724; 
            border: 1px solid #c3e6cb;
        }
        
        .error { 
            background: #f8d7da; 
            color: #721c24; 
            border: 1px solid #f5c6cb;
        }
        
        .warning { 
            background: #fff3cd; 
            color: #856404; 
            border: 1px solid #ffeaa7;
        }
        
        .timestamp {
            font-size: 0.9em;
            color: var(--gray);
            text-align: right;
        }

        .header-meta {
            align-items: flex-end;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.18);
            color: white;
            text-decoration: none;
        }

        .btn-secondary:hover,
        .btn-secondary:focus-visible {
            background: rgba(255, 255, 255, 0.18);
            border-color: rgba(255, 255, 255, 0.28);
        }

        .progress-text--left {
            text-align: left;
        }
        
        .health-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .health-good { background: var(--success); }
        .health-warning { background: var(--warning); }
        .health-critical { background: var(--danger); }
        
        .chart-container {
            height: 200px;
            margin: 20px 0;
            position: relative;
        }
        
        .auto-refresh {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .auto-refresh label {
            font-size: 0.9em;
            color: var(--gray);
        }

        .hidden { display: none; }
        .overflow-x-auto { overflow-x: auto; }
        .code-block {
            background: var(--light);
            padding: 15px;
            border-radius: 4px;
            overflow: auto;
        }
        .code-block-scroll {
            background: var(--light);
            padding: 15px;
            border-radius: 4px;
            overflow: auto;
            max-height: 300px;
        }

        @media (max-width: 768px) {
            body {
                padding: 14px;
            }

            header {
                align-items: flex-start;
                gap: 14px;
                text-align: left;
            }

            .header-meta {
                align-items: stretch;
                width: 100%;
            }

            .header-actions {
                justify-content: stretch;
            }

            .header-actions .btn {
                justify-content: center;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div>
                <h1>📊 Мониторинг производительности</h1>
                <div class="subtitle">Версия PHP: <?= PHP_VERSION ?> | Время: <?= date('Y-m-d H:i:s') ?></div>
            </div>
            <div class="header-meta">
                <div class="timestamp" id="lastUpdate">
                    Обновлено: <?= date('H:i:s') ?>
                </div>
                <div class="header-actions">
                    <a href="admin-menu.php" class="btn btn-secondary">Назад в админку</a>
                </div>
            </div>
        </header>
        
        <!-- Быстрые действия -->
        <div class="card">
            <h2>⚡ Быстрые действия</h2>
            <div class="actions">
                <button class="btn btn-primary" data-action="refreshMetrics">
                    <span class="icon">🔄</span> Обновить метрики
                </button>
                <button class="btn btn-warning" data-action="clearOpcache">
                    <span class="icon">🗑️</span> Очистить OPcache
                </button>
                <button class="btn btn-success" data-action="exportMetrics">
                    <span class="icon">📥</span> Экспорт метрик
                </button>
                <button class="btn" data-action="showApi">
                    <span class="icon">🔧</span> API Endpoint
                </button>
            </div>

            <div class="auto-refresh">
                <label>
                    <input type="checkbox" id="autoRefresh">
                    Автообновление каждые 30 секунд
                </label>
                <select id="refreshInterval">
                    <option value="10">10 сек</option>
                    <option value="30" selected>30 сек</option>
                    <option value="60">60 сек</option>
                    <option value="300">5 мин</option>
                </select>
            </div>
            
            <div id="result" class="result"></div>
        </div>
        
        <!-- Основные метрики -->
        <div class="dashboard">
            <!-- Карточка здоровья системы -->
            <div class="card">
                <h2>❤️ Здоровье системы</h2>
                <div class="metric">
                    <div class="metric-label">Общий статус</div>
                    <div class="metric-value" id="systemHealth">Загрузка...</div>
                    <div class="progress-bar">
                        <div class="progress-fill progress-success" id="healthBar" data-width="0"></div>
                    </div>
                </div>
                <div class="stats-grid" id="healthIndicators">
                    <!-- Индикаторы будут заполнены JavaScript -->
                </div>
            </div>
            
            <!-- Карточка производительности -->
            <div class="card">
                <h2>⚡ Производительность</h2>
                <div class="metric">
                    <div class="metric-label">Время выполнения</div>
                    <div class="metric-value"><?= $metrics['performance']['execution']['time'] ?></div>
                </div>
                <div class="metric">
                    <div class="metric-label">Использование памяти</div>
                    <div class="metric-value"><?= $metrics['performance']['execution']['memory'] ?></div>
                </div>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-label">Запросов к БД</div>
                        <div class="stat-value"><?= $metrics['performance']['database_queries']['total'] ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">Кэшировано</div>
                        <div class="stat-value"><?= $metrics['performance']['database_queries']['cached'] ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">Всего запросов</div>
                        <div class="stat-value"><?= $metrics['performance']['execution']['requests'] ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Карточка OPcache -->
            <div class="card">
                <h2>💾 OPcache</h2>
                <?php if ($metrics['php']['opcache']['enabled']): ?>
                    <div class="metric">
                        <div class="metric-label">Hit Rate</div>
                        <div class="metric-value <?= 
                            $metrics['php']['opcache']['hit_rate'] > 90 ? 'status-good' : 
                            ($metrics['php']['opcache']['hit_rate'] > 70 ? 'status-warning' : 'status-critical')
                        ?>">
                            <?= $metrics['php']['opcache']['hit_rate'] ?>%
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill <?= 
                                $metrics['php']['opcache']['hit_rate'] > 90 ? 'progress-success' : 
                                ($metrics['php']['opcache']['hit_rate'] > 70 ? 'progress-warning' : 'progress-danger')
                            ?>" data-width="<?= min($metrics['php']['opcache']['hit_rate'], 100) ?>"></div>
                        </div>
                    </div>
                    <div class="stats-grid">
                        <div class="stat-item">
                            <div class="stat-label">Скриптов в кэше</div>
                            <div class="stat-value">
                                <?= number_format($metrics['php']['opcache']['statistics']['num_cached_scripts'] ?? 0) ?>
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-label">Попаданий</div>
                            <div class="stat-value">
                                <?= number_format($metrics['php']['opcache']['statistics']['hits'] ?? 0) ?>
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-label">Промахов</div>
                            <div class="stat-value">
                                <?= number_format($metrics['php']['opcache']['statistics']['misses'] ?? 0) ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="metric">
                        <div class="metric-value status-critical">OPcache отключен</div>
                        <p>Рекомендуется включить OPcache для увеличения производительности PHP.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Вторая строка метрик -->
        <div class="dashboard">
            <!-- Карточка базы данных -->
            <div class="card">
                <h2>🗄️ База данных</h2>
                <div class="metric">
                    <div class="metric-label">Buffer Pool Hit Rate</div>
                    <div class="metric-value <?= 
                        $metrics['database']['buffer_pool_hit_rate'] > 99 ? 'status-good' : 
                        ($metrics['database']['buffer_pool_hit_rate'] > 95 ? 'status-warning' : 'status-critical')
                    ?>">
                        <?= $metrics['database']['buffer_pool_hit_rate'] ?>%
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill <?= 
                            $metrics['database']['buffer_pool_hit_rate'] > 99 ? 'progress-success' : 
                            ($metrics['database']['buffer_pool_hit_rate'] > 95 ? 'progress-warning' : 'progress-danger')
                        ?>" data-width="<?= min($metrics['database']['buffer_pool_hit_rate'], 100) ?>"></div>
                    </div>
                </div>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-label">Соединения</div>
                        <div class="stat-value">
                            <?= $metrics['database']['connections'] ?> / <?= $metrics['database']['max_connections'] ?>
                        </div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">Медленные запросы</div>
                        <div class="stat-value <?= $metrics['database']['slow_queries'] > 10 ? 'status-warning' : 'status-good' ?>">
                            <?= $metrics['database']['slow_queries'] ?>
                        </div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">Query Cache Hit Rate</div>
                        <div class="stat-value">
                            <?= $metrics['database']['query_cache']['hit_rate'] ?>%
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Карточка сервера -->
            <div class="card">
                <h2>🖥️ Сервер</h2>
                <div class="metric">
                    <div class="metric-label">Загрузка CPU (1min)</div>
                    <div class="metric-value <?= 
                        ($metrics['server']['load_average']['1min'] ?? 0) > 2 ? 'status-critical' : 
                        (($metrics['server']['load_average']['1min'] ?? 0) > 1 ? 'status-warning' : 'status-good')
                    ?>">
                        <?= $metrics['server']['load_average']['1min'] ?? 'N/A' ?>
                    </div>
                </div>
                <div class="metric">
                    <div class="metric-label">Использование памяти</div>
                    <div class="metric-value">
                        <?= formatBytes($metrics['server']['memory']['used']) ?> / <?= $metrics['server']['memory']['limit'] ?>
                    </div>
                </div>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-label">Пик памяти</div>
                        <div class="stat-value"><?= formatBytes($metrics['server']['memory']['peak']) ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">Время работы</div>
                        <div class="stat-value"><?= substr($metrics['server']['uptime'] ?? 'N/A', 0, 50) ?></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Детальная информация -->
        <div class="card">
            <h2>📋 Детальная информация</h2>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Компонент</th>
                            <th>Параметр</th>
                            <th>Значение</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody id="detailedInfo">
                        <!-- Заполнится JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- API информация -->
        <?php
            $smoke = $metrics['security_smoke'] ?? [];
            $smokeStatus = strtoupper((string)($smoke['status'] ?? 'UNKNOWN'));
            $smokeClass = $smokeStatus === 'PASS' ? 'status-good' : ($smokeStatus === 'FAIL' ? 'status-critical' : 'status-warning');
            $checkout = $metrics['checkout_errors'] ?? ['available' => false, 'total' => 0, 'top_reasons' => [], 'by_category' => []];
        ?>
        <div class="dashboard">
            <div class="card">
                <h2>Security Smoke (Daily)</h2>
                <?php if (!empty($smoke['available'])): ?>
                    <div class="metric">
                        <div class="metric-label">Последний статус</div>
                        <div class="metric-value <?= $smokeClass ?>">
                            <?= htmlspecialchars($smokeStatus, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>
                    <div class="stats-grid">
                        <div class="stat-item">
                            <div class="stat-label">UTC timestamp</div>
                            <div class="stat-value"><?= htmlspecialchars((string)($smoke['ts_utc'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-label">Exit code</div>
                            <div class="stat-value"><?= htmlspecialchars((string)($smoke['exit_code'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>
                    <div class="metric">
                        <div class="metric-label">Log file</div>
                        <div class="progress-text progress-text--left">
                            <?= htmlspecialchars((string)($smoke['log_file'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="metric">
                        <div class="metric-value status-warning">No smoke logs found</div>
                        <p>Проверьте cron и путь логов security-smoke.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>Checkout Errors (24h)</h2>
                <?php if (!empty($checkout['available'])): ?>
                    <div class="metric">
                        <div class="metric-label">Всего событий</div>
                        <div class="metric-value"><?= (int)($checkout['total'] ?? 0) ?></div>
                    </div>

                    <?php if (!empty($checkout['top_reasons'])): ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Причина</th>
                                    <th>Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($checkout['top_reasons'] as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string)($row['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= (int)($row['count'] ?? 0) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>За выбранный период событий нет.</p>
                    <?php endif; ?>

                    <div class="metric">
                        <div class="metric-label">Log file</div>
                        <div class="progress-text progress-text--left">
                            <?= htmlspecialchars((string)($checkout['log_file'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="metric">
                        <div class="metric-value status-warning">Checkout log not found</div>
                        <p>Ожидаемый лог: <code>/var/www/labus_pro_usr/data/logs/menu.labus.pro-php.log</code></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card hidden" id="apiSection">
            <h2>🔧 API Endpoint</h2>
            <p>Для получения метрик в формате JSON используйте:</p>
            <pre class="code-block">
GET /monitor.php?api=1
GET /monitor.php?action=get_metrics
POST /monitor.php?action=clear_opcache
POST /clear-cache.php?scope=server (header: X-CSRF-Token)
            </pre>
            <p>Пример ответа:</p>
            <pre class="code-block-scroll" id="apiExample">
                <!-- Заполнится JavaScript -->
            </pre>
        </div>
    </div>
    
    <script nonce="<?= htmlspecialchars($scriptNonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
    // Глобальные переменные
    let autoRefreshInterval = null;
    let refreshInterval = 30000; // 30 секунд по умолчанию
    const monitorCsrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    
    // Функция для показа результата
    function showResult(message, type = 'success') {
        const resultDiv = document.getElementById('result');
        resultDiv.className = 'result ' + type;
        resultDiv.textContent = message;
        resultDiv.style.display = 'block';
        
        // Скрыть через 5 секунд
        setTimeout(() => {
            resultDiv.style.display = 'none';
        }, 5000);
    }
    
    // Функция обновления метрик
    async function refreshMetrics() {
        try {
            const response = await fetch('?action=get_metrics');
            const metrics = await response.json();
            updateMetricsDisplay(metrics);
            
            // Обновить время последнего обновления
            document.getElementById('lastUpdate').textContent = 
                'Обновлено: ' + new Date().toLocaleTimeString();
            
            showResult('Метрики обновлены', 'success');
        } catch (error) {
            showResult('Ошибка обновления метрик: ' + error.message, 'error');
        }
    }
    
    // Функция очистки OPcache
    async function clearOpcache() {
        if (!confirm('Вы уверены? Это сбросит весь кэш OPcache.')) {
            return;
        }
        
        try {
            const response = await fetch('?action=clear_opcache');
            const result = await response.json();
            
            showResult(result.message, result.success ? 'success' : 'error');
            
            if (result.success) {
                // Обновить метрики через 2 секунды
                setTimeout(refreshMetrics, 2000);
            }
        } catch (error) {
            showResult('Ошибка: ' + error.message, 'error');
        }
    }
    
    // Функция очистки серверного кэша (Redis)
    async function clearServerCache() {
        if (!confirm('Вы уверены? Это сбросит весь серверный кэш.')) {
            return;
        }

        if (!monitorCsrfToken) {
            showResult('CSRF token not found. Reload the page.', 'error');
            return;
        }

        try {
            const response = await fetch('/clear-cache.php?scope=server', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-Token': monitorCsrfToken
                }
            });
            const result = await response.json();

            const success = response.ok && result.status === 'success';
            let message = result.message || (success ? 'Server cache cleared' : 'Cache clear failed');
            if (success && result.details) {
                const details = [];
                if ('redis_cache_cleared' in result.details) {
                    details.push(`Redis: ${result.details.redis_cache_cleared ? 'ok' : 'skip'}`);
                }
                if (details.length) {
                    message += ` (${details.join(', ')})`;
                }
            }

            showResult(message, success ? 'success' : 'error');

            if (success) {
                setTimeout(refreshMetrics, 2000);
            }
        } catch (error) {
            showResult('Ошибка: ' + error.message, 'error');
        }
    }

    // Функция экспорта метрик
    function exportMetrics() {
        const data = <?= json_encode($metrics, JSON_PRETTY_PRINT) ?>;
        const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'metrics-' + new Date().toISOString().split('T')[0] + '.json';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        
        showResult('Метрики экспортированы', 'success');
    }
    
    // Функция показа API
    function showApi() {
        const apiSection = document.getElementById('apiSection');
        apiSection.classList.toggle('hidden');
        
        // Показать пример API ответа
        const exampleDiv = document.getElementById('apiExample');
        const data = <?= json_encode($metrics, JSON_PRETTY_PRINT) ?>;
        exampleDiv.textContent = JSON.stringify(data, null, 2);
    }
    
    // Функция обновления отображения метрик
    function updateMetricsDisplay(metrics) {
        // Обновить здоровье системы
        updateSystemHealth(metrics);
        
        // Обновить детальную информацию
        updateDetailedInfo(metrics);
    }
    
    // Функция обновления здоровья системы
    function updateSystemHealth(metrics) {
        // Рассчитать общий статус здоровья
        let healthScore = 0;
        let totalMetrics = 0;
        
        // OPcache hit rate
        if (metrics.php.opcache.enabled) {
            const opcacheRate = metrics.php.opcache.hit_rate;
            healthScore += opcacheRate >= 90 ? 100 : opcacheRate >= 70 ? 70 : 30;
            totalMetrics++;
        }
        
        // DB buffer pool hit rate
        const dbRate = metrics.database.buffer_pool_hit_rate;
        healthScore += dbRate >= 99 ? 100 : dbRate >= 95 ? 70 : 30;
        totalMetrics++;
        
        // CPU load
        const load = metrics.server.load_average?.['1min'] || 0;
        healthScore += load < 1 ? 100 : load < 2 ? 70 : 30;
        totalMetrics++;
        
        const averageHealth = Math.round(healthScore / totalMetrics);
        
        // Обновить отображение
        const healthDiv = document.getElementById('systemHealth');
        const healthBar = document.getElementById('healthBar');
        
        let healthStatus, healthClass;
        if (averageHealth >= 90) {
            healthStatus = 'Отличное';
            healthClass = 'status-good';
            healthBar.className = 'progress-fill progress-success';
        } else if (averageHealth >= 70) {
            healthStatus = 'Хорошее';
            healthClass = 'status-warning';
            healthBar.className = 'progress-fill progress-warning';
        } else {
            healthStatus = 'Требует внимания';
            healthClass = 'status-critical';
            healthBar.className = 'progress-fill progress-danger';
        }
        
        healthDiv.className = 'metric-value ' + healthClass;
        healthDiv.textContent = healthStatus + ' (' + averageHealth + '%)';
        healthBar.style.width = averageHealth + '%';
        
        // Обновить индикаторы
        updateHealthIndicators(metrics);
    }
    
    // Функция обновления индикаторов здоровья
    function updateHealthIndicators(metrics) {
        const indicatorsDiv = document.getElementById('healthIndicators');
        indicatorsDiv.innerHTML = '';
        
        const indicators = [
            {
                label: 'OPcache',
                value: metrics.php.opcache.enabled ? metrics.php.opcache.hit_rate + '%' : 'Отключен',
                status: metrics.php.opcache.enabled ? 
                    (metrics.php.opcache.hit_rate >= 90 ? 'good' : 
                     metrics.php.opcache.hit_rate >= 70 ? 'warning' : 'critical') : 'warning'
            },
            {
                label: 'БД Buffer Pool',
                value: metrics.database.buffer_pool_hit_rate + '%',
                status: metrics.database.buffer_pool_hit_rate >= 99 ? 'good' : 
                        metrics.database.buffer_pool_hit_rate >= 95 ? 'warning' : 'critical'
            },
            {
                label: 'Загрузка CPU',
                value: (metrics.server.load_average?.['1min'] || 'N/A') + '',
                status: (metrics.server.load_average?.['1min'] || 0) < 1 ? 'good' : 
                        (metrics.server.load_average?.['1min'] || 0) < 2 ? 'warning' : 'critical'
            }
        ];
        
        indicators.forEach(indicator => {
            const div = document.createElement('div');
            div.className = 'stat-item';
            div.innerHTML = `
                <div class="stat-label">
                    <span class="health-indicator health-${indicator.status}"></span>
                    ${indicator.label}
                </div>
                <div class="stat-value">${indicator.value}</div>
            `;
            indicatorsDiv.appendChild(div);
        });
    }
    
    // Функция обновления детальной информации
    function updateDetailedInfo(metrics) {
        const tbody = document.getElementById('detailedInfo');
        tbody.innerHTML = '';
        
        const rows = [
            // PHP
            ['PHP', 'Версия', metrics.php.version, 'good'],
            ['PHP', 'OPcache', metrics.php.opcache.enabled ? 'Включен' : 'Выключен', metrics.php.opcache.enabled ? 'good' : 'warning'],
            ['PHP', 'OPcache Hit Rate', metrics.php.opcache.hit_rate + '%', 
                metrics.php.opcache.hit_rate >= 90 ? 'good' : metrics.php.opcache.hit_rate >= 70 ? 'warning' : 'critical'],
            
            // База данных
            ['БД', 'Buffer Pool Hit Rate', metrics.database.buffer_pool_hit_rate + '%',
                metrics.database.buffer_pool_hit_rate >= 99 ? 'good' : metrics.database.buffer_pool_hit_rate >= 95 ? 'warning' : 'critical'],
            ['БД', 'Соединения', metrics.database.connections + ' / ' + metrics.database.max_connections,
                metrics.database.connections_percentage < 80 ? 'good' : metrics.database.connections_percentage < 90 ? 'warning' : 'critical'],
            ['БД', 'Медленные запросы', metrics.database.slow_queries,
                metrics.database.slow_queries < 5 ? 'good' : metrics.database.slow_queries < 20 ? 'warning' : 'critical'],
            
            // Сервер
            ['Сервер', 'Загрузка (1min)', (metrics.server.load_average?.['1min'] || 'N/A') + '',
                (metrics.server.load_average?.['1min'] || 0) < 1 ? 'good' : (metrics.server.load_average?.['1min'] || 0) < 2 ? 'warning' : 'critical'],
            ['Сервер', 'Память', formatBytes(metrics.server.memory.used) + ' / ' + metrics.server.memory.limit,
                'good'],
            ['Сервер', 'Пик памяти', formatBytes(metrics.server.memory.peak), 'good'],
            
            // Производительность
            ['Приложение', 'Время выполнения', metrics.performance.execution.time, 'good'],
            ['Приложение', 'Использование памяти', metrics.performance.execution.memory, 'good'],
            ['Приложение', 'Запросы к БД', metrics.performance.database_queries.total + ' (' + 
                metrics.performance.database_queries.cached + ' кэшировано)', 'good']
        ];
        
        rows.forEach(row => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${row[0]}</td>
                <td>${row[1]}</td>
                <td>${row[2]}</td>
                <td class="status-${row[3]}">${getStatusText(row[3])}</td>
            `;
            tbody.appendChild(tr);
        });
    }
    
    // Вспомогательные функции
    function formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    function getStatusText(status) {
        switch(status) {
            case 'good': return '✓ Хорошо';
            case 'warning': return '⚠ Предупреждение';
            case 'critical': return '✗ Критично';
            default: return status;
        }
    }
    
    // Управление автообновлением
    function toggleAutoRefresh() {
        const checkbox = document.getElementById('autoRefresh');
        
        if (checkbox.checked) {
            startAutoRefresh();
        } else {
            stopAutoRefresh();
        }
    }
    
    function startAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
        }
        
        autoRefreshInterval = setInterval(refreshMetrics, refreshInterval);
        showResult('Автообновление включено (' + (refreshInterval / 1000) + ' сек)', 'success');
    }
    
    function stopAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
            showResult('Автообновление выключено', 'warning');
        }
    }
    
    function updateRefreshInterval() {
        const select = document.getElementById('refreshInterval');
        refreshInterval = parseInt(select.value) * 1000;
        
        // Если автообновление активно, перезапустить с новым интервалом
        if (document.getElementById('autoRefresh').checked) {
            startAutoRefresh();
        }
    }
    
    // Инициализация при загрузке
    document.addEventListener('DOMContentLoaded', function() {
        // Обновить детальную информацию
        updateMetricsDisplay(<?= json_encode($metrics) ?>);

        // Установить начальные значения
        const select = document.getElementById('refreshInterval');
        select.value = refreshInterval / 1000;

        // Инициализировать ширину progress-bar из data-width
        document.querySelectorAll('[data-width]').forEach(el => {
            el.style.width = el.dataset.width + '%';
        });

        // Обработчики кнопок через data-action
        const actions = { refreshMetrics, clearOpcache, clearServerCache, exportMetrics, showApi };
        document.querySelectorAll('[data-action]').forEach(btn => {
            btn.addEventListener('click', function() {
                this.disabled = true;
                setTimeout(() => { this.disabled = false; }, 2000);
                const fn = actions[this.dataset.action];
                if (fn) fn();
            });
        });

        // Обработчики checkbox и select
        document.getElementById('autoRefresh').addEventListener('change', toggleAutoRefresh);
        select.addEventListener('change', updateRefreshInterval);

        // Запустить автообновление если нужно
        if (document.getElementById('autoRefresh').checked) {
            startAutoRefresh();
        }
    });
    
    // Обновлять каждые 30 секунд если страница активна
    let visibilityChange;
    if (typeof document.hidden !== "undefined") {
        visibilityChange = "visibilitychange";
    } else if (typeof document.msHidden !== "undefined") {
        visibilityChange = "msvisibilitychange";
    } else if (typeof document.webkitHidden !== "undefined") {
        visibilityChange = "webkitvisibilitychange";
    }
    
    if (visibilityChange) {
        document.addEventListener(visibilityChange, function() {
            if (!document.hidden && autoRefreshInterval) {
                // Страница стала видимой, обновить метрики
                refreshMetrics();
            }
        });
    }
    </script>
</body>
</html>
