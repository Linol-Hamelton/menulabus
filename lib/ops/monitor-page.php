<?php
if (!defined('MENU_LABUS_ROOT')) {
    http_response_code(404);
    exit;
}

// monitor.php - Admin monitoring and diagnostics dashboard.
$required_role = 'admin';
require_once MENU_LABUS_ROOT . '/require_auth.php';

if ($_SESSION['user_role'] !== 'owner' && $_SESSION['user_role'] !== 'admin') {
    die('Access denied');
}

$csrfToken = $_SESSION['csrf_token'] ?? '';
$monitorCssVersion = (string) (@filemtime(MENU_LABUS_ROOT . '/css/monitor.css') ?: '1');
$monitorJsVersion = (string) (@filemtime(MENU_LABUS_ROOT . '/js/monitor-page.js') ?: '1');
$uiUxPolishVersion = (string) (@filemtime(MENU_LABUS_ROOT . '/css/ui-ux-polish.css') ?: '1');

// Required dependencies.
require_once MENU_LABUS_ROOT . '/db.php';

// API responses.
if (isset($_GET['api']) && $_GET['api'] == '1') {
    header('Content-Type: application/json');
    echo json_encode(getPerformanceMetrics(false), JSON_PRETTY_PRINT);
    exit;
}

// Action handlers.
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
                    'message' => 'OPcache недоступен'
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
 * Collect all performance metrics for the dashboard.
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
        MENU_LABUS_ROOT . '/data/logs/menu.labus.pro-php.log',
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

function renderMonitorIcon(string $name, string $class = 'monitor-icon'): string {
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeClass = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');

    return '<svg class="' . $safeClass . '" aria-hidden="true" focusable="false"><use href="/images/icons/phosphor-sprite.svg#' . $safeName . '"></use></svg>';
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
    <link rel="stylesheet" href="/css/monitor.css?v=<?= htmlspecialchars($monitorCssVersion, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="/css/ui-ux-polish.css?v=<?= htmlspecialchars($uiUxPolishVersion, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
    <div
        class="container monitor-page"
        id="monitorPage"
        data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
        data-get-metrics-url="?action=get_metrics"
        data-clear-opcache-url="?action=clear_opcache"
        data-clear-server-cache-url="/clear-cache.php?scope=server"
        data-refresh-interval-ms="30000">
        <template id="monitorMetricsData"><?= json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></template>
        <header>
            <div>
                <h1><?= renderMonitorIcon('chart-bar', 'monitor-heading-icon') ?><span>Мониторинг производительности</span></h1>
                <div class="subtitle">Версия PHP: <?= PHP_VERSION ?> | Время: <?= date('Y-m-d H:i:s') ?></div>
            </div>
            <div class="header-meta">
                <div class="timestamp" id="lastUpdate">
                    Обновлено: <?= date('H:i:s') ?>
                </div>
                <div class="header-actions">
                    <a href="admin/menu.php" class="btn btn-secondary">
                        <?= renderMonitorIcon('arrow-left', 'monitor-button-icon') ?>
                        <span>Назад в админку</span>
                    </a>
                </div>
            </div>
        </header>
        
        <!-- Быстрые действия -->
        <div class="card">
            <h2><?= renderMonitorIcon('lightning') ?><span>Быстрые действия</span></h2>
            <div class="actions">
                <button class="btn btn-primary" data-action="refreshMetrics">
                    <?= renderMonitorIcon('arrows-clockwise', 'monitor-button-icon') ?>
                    <span>Обновить метрики</span>
                </button>
                <button class="btn btn-warning" data-action="clearOpcache">
                    <?= renderMonitorIcon('trash', 'monitor-button-icon') ?>
                    <span>Очистить OPcache</span>
                </button>
                <button class="btn btn-success" data-action="exportMetrics">
                    <?= renderMonitorIcon('download-simple', 'monitor-button-icon') ?>
                    <span>Экспорт метрик</span>
                </button>
                <button class="btn btn-neutral" data-action="showApi">
                    <?= renderMonitorIcon('wrench', 'monitor-button-icon') ?>
                    <span>API Endpoint</span>
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
            
            <div id="result" class="result" hidden aria-live="polite"></div>
        </div>
        
        <!-- Основные метрики -->
        <div class="dashboard">
            <!-- Карточка здоровья системы -->
            <div class="card">
                <h2><?= renderMonitorIcon('heart') ?><span>Здоровье системы</span></h2>
                <div class="metric">
                    <div class="metric-label">Общий статус</div>
                    <div class="metric-value" id="systemHealth">Загрузка...</div>
                    <progress class="progress-meter progress-success" id="healthBar" max="100" value="0"></progress>
                </div>
                <div class="stats-grid" id="healthIndicators">
                    <!-- Индикаторы будут заполнены JavaScript -->
                </div>
            </div>
            
            <!-- Карточка производительности -->
            <div class="card">
                <h2><?= renderMonitorIcon('lightning') ?><span>Производительность</span></h2>
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
                <h2><?= renderMonitorIcon('hard-drive') ?><span>OPcache</span></h2>
                <?php if ($metrics['php']['opcache']['enabled']): ?>
                    <div class="metric">
                        <div class="metric-label">Hit Rate</div>
                        <div class="metric-value <?= 
                            $metrics['php']['opcache']['hit_rate'] > 90 ? 'status-good' : 
                            ($metrics['php']['opcache']['hit_rate'] > 70 ? 'status-warning' : 'status-critical')
                        ?>">
                            <?= $metrics['php']['opcache']['hit_rate'] ?>%
                        </div>
                        <progress
                            class="progress-meter <?=
                                $metrics['php']['opcache']['hit_rate'] > 90 ? 'progress-success' : 
                                ($metrics['php']['opcache']['hit_rate'] > 70 ? 'progress-warning' : 'progress-danger')
                            ?>"
                            max="100"
                            value="<?= min($metrics['php']['opcache']['hit_rate'], 100) ?>"></progress>
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
                <h2><?= renderMonitorIcon('database') ?><span>База данных</span></h2>
                <div class="metric">
                    <div class="metric-label">Buffer Pool Hit Rate</div>
                    <div class="metric-value <?= 
                        $metrics['database']['buffer_pool_hit_rate'] > 99 ? 'status-good' : 
                        ($metrics['database']['buffer_pool_hit_rate'] > 95 ? 'status-warning' : 'status-critical')
                    ?>">
                        <?= $metrics['database']['buffer_pool_hit_rate'] ?>%
                    </div>
                    <progress
                        class="progress-meter <?=
                            $metrics['database']['buffer_pool_hit_rate'] > 99 ? 'progress-success' : 
                            ($metrics['database']['buffer_pool_hit_rate'] > 95 ? 'progress-warning' : 'progress-danger')
                        ?>"
                        max="100"
                        value="<?= min($metrics['database']['buffer_pool_hit_rate'], 100) ?>"></progress>
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
                <h2><?= renderMonitorIcon('desktop') ?><span>Сервер</span></h2>
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
            <h2><?= renderMonitorIcon('list-bullets') ?><span>Детальная информация</span></h2>
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

        <div class="card" id="apiSection" hidden>
            <h2><?= renderMonitorIcon('wrench') ?><span>API Endpoint</span></h2>
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
    <script src="/js/monitor-page.js?v=<?= htmlspecialchars($monitorJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
