<?php
$required_role = 'admin';
require_once __DIR__ . '/require_auth.php';

if (!extension_loaded('Zend OPcache')) {
    die('OPcache extension not loaded');
}

if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    switch ($_GET['action']) {
        case 'reset':
            $result = opcache_reset();
            echo json_encode([
                'success' => $result,
                'message' => $result ? 'Кэш OPcache успешно сброшен' : 'Ошибка сброса кэша',
            ]);
            break;

        case 'revalidate':
            if (function_exists('opcache_invalidate')) {
                $files = get_included_files();
                $invalidated = 0;
                foreach ($files as $file) {
                    if (opcache_invalidate($file, true)) {
                        $invalidated++;
                    }
                }
                echo json_encode([
                    'success' => true,
                    'message' => "Перепроверено {$invalidated} файлов",
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Функция opcache_invalidate недоступна',
                ]);
            }
            break;

        case 'get_stats':
            echo json_encode([
                'success' => true,
                'data' => [
                    'status' => opcache_get_status(),
                    'config' => opcache_get_configuration(),
                ],
            ]);
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Неизвестное действие',
            ]);
    }
    exit;
}

$status = opcache_get_status();
$config = opcache_get_configuration();

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function calculatePercentage($used, $total) {
    if ($total == 0) {
        return 0;
    }
    return round(($used / $total) * 100, 2);
}

$memoryUsage = $status['memory_usage'] ?? [];
$opcacheStats = $status['opcache_statistics'] ?? [];
$directives = $config['directives'] ?? [];

$hitRate = isset($opcacheStats['opcache_hit_rate']) ? round($opcacheStats['opcache_hit_rate'], 2) : 0;
$usedMemory = $memoryUsage['used_memory'] ?? 0;
$freeMemory = $memoryUsage['free_memory'] ?? 0;
$wastedMemory = $memoryUsage['wasted_memory'] ?? 0;
$totalMemory = $usedMemory + $freeMemory + $wastedMemory;
$cachedScripts = $opcacheStats['num_cached_scripts'] ?? 0;
$maxCachedScripts = $directives['opcache.max_accelerated_files'] ?? 0;
$startTime = $opcacheStats['start_time'] ?? 0;
$lastRestartTime = $opcacheStats['last_restart_time'] ?? 0;
$cssVersion = @filemtime(__DIR__ . '/css/opcache-status.css') ?: '1.0.0';
$jsVersion = @filemtime(__DIR__ . '/js/opcache-status-page.js') ?: '1.0.0';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мониторинг OPcache</title>
    <link rel="stylesheet" href="/css/opcache-status.css?v=<?= htmlspecialchars((string)$cssVersion) ?>">
</head>
<body>
    <div class="opcache-page container">
        <header class="opcache-header">
            <h1 class="opcache-heading">
                <svg class="opcache-heading-icon" aria-hidden="true" viewBox="0 0 256 256">
                    <use href="/images/icons/phosphor-sprite.svg#chart-bar"></use>
                </svg>
                <span>Мониторинг OPcache</span>
            </h1>
            <div class="subtitle">Версия PHP: <?= PHP_VERSION ?> | Время сервера: <?= date('Y-m-d H:i:s') ?></div>
        </header>

        <div class="dashboard">
            <section class="card">
                <h2 class="card-title">
                    <svg class="card-title-icon" aria-hidden="true" viewBox="0 0 256 256">
                        <use href="/images/icons/phosphor-sprite.svg#lightning"></use>
                    </svg>
                    <span>Эффективность кэша</span>
                </h2>
                <div class="metric">
                    <div class="metric-label">Hit Rate</div>
                    <div class="metric-value <?= $hitRate > 90 ? 'status-good' : ($hitRate > 70 ? 'status-warning' : 'status-critical') ?>">
                        <?= $hitRate ?>%
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" data-progress="<?= htmlspecialchars((string)min($hitRate, 100)) ?>"></div>
                    </div>
                    <div class="progress-text">
                        <?php if ($hitRate > 90): ?>
                            Отличная эффективность
                        <?php elseif ($hitRate > 70): ?>
                            Хорошая эффективность
                        <?php else: ?>
                            Требуется оптимизация
                        <?php endif; ?>
                    </div>
                </div>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-label">Попаданий</div>
                        <div class="stat-value"><?= number_format($opcacheStats['hits'] ?? 0) ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">Промахов</div>
                        <div class="stat-value"><?= number_format($opcacheStats['misses'] ?? 0) ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">Чёрный список</div>
                        <div class="stat-value"><?= number_format($opcacheStats['blacklist_misses'] ?? 0) ?></div>
                    </div>
                </div>
            </section>

            <section class="card">
                <h2 class="card-title">
                    <svg class="card-title-icon" aria-hidden="true" viewBox="0 0 256 256">
                        <use href="/images/icons/phosphor-sprite.svg#hard-drive"></use>
                    </svg>
                    <span>Использование памяти</span>
                </h2>
                <div class="metric">
                    <div class="metric-label">Использовано памяти</div>
                    <div class="metric-value"><?= formatBytes($usedMemory) ?> / <?= formatBytes($totalMemory) ?></div>
                    <div class="progress-bar">
                        <div class="progress-fill" data-progress="<?= htmlspecialchars((string)calculatePercentage($usedMemory, $totalMemory)) ?>"></div>
                    </div>
                    <div class="progress-text">
                        <?= calculatePercentage($usedMemory, $totalMemory) ?>% использовано
                    </div>
                </div>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-label">Свободно</div>
                        <div class="stat-value"><?= formatBytes($freeMemory) ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">Потеряно</div>
                        <div class="stat-value <?= $wastedMemory > $totalMemory * 0.05 ? 'status-warning' : 'status-good' ?>">
                            <?= formatBytes($wastedMemory) ?>
                        </div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">Процент потерь</div>
                        <div class="stat-value <?= calculatePercentage($wastedMemory, $totalMemory) > 5 ? 'status-warning' : 'status-good' ?>">
                            <?= calculatePercentage($wastedMemory, $totalMemory) ?>%
                        </div>
                    </div>
                </div>
            </section>

            <section class="card">
                <h2 class="card-title">
                    <svg class="card-title-icon" aria-hidden="true" viewBox="0 0 256 256">
                        <use href="/images/icons/phosphor-sprite.svg#list-bullets"></use>
                    </svg>
                    <span>Кэшированные скрипты</span>
                </h2>
                <div class="metric">
                    <div class="metric-label">Скриптов в кэше</div>
                    <div class="metric-value"><?= number_format($cachedScripts) ?> / <?= number_format($maxCachedScripts) ?></div>
                    <div class="progress-bar">
                        <div class="progress-fill" data-progress="<?= htmlspecialchars((string)calculatePercentage($cachedScripts, $maxCachedScripts)) ?>"></div>
                    </div>
                    <div class="progress-text">
                        <?= calculatePercentage($cachedScripts, $maxCachedScripts) ?>% заполнено
                    </div>
                </div>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-label">Всего ключей</div>
                        <div class="stat-value"><?= number_format($opcacheStats['num_cached_keys'] ?? 0) ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">Макс. ключей</div>
                        <div class="stat-value"><?= number_format($opcacheStats['max_cached_keys'] ?? 0) ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">Перезапусков</div>
                        <div class="stat-value"><?= number_format($opcacheStats['oom_restarts'] ?? 0) ?></div>
                    </div>
                </div>
            </section>
        </div>

        <section class="card">
            <h2 class="card-title">
                <svg class="card-title-icon" aria-hidden="true" viewBox="0 0 256 256">
                    <use href="/images/icons/phosphor-sprite.svg#gear-six"></use>
                </svg>
                <span>Управление OPcache</span>
            </h2>
            <div class="actions">
                <button type="button" class="btn btn-primary" data-opcache-action="reset">
                    <svg class="btn-icon" aria-hidden="true" viewBox="0 0 256 256">
                        <use href="/images/icons/phosphor-sprite.svg#arrows-clockwise"></use>
                    </svg>
                    <span>Сбросить кэш</span>
                </button>
                <button type="button" class="btn btn-success" data-opcache-action="revalidate">
                    <svg class="btn-icon" aria-hidden="true" viewBox="0 0 256 256">
                        <use href="/images/icons/phosphor-sprite.svg#lightning"></use>
                    </svg>
                    <span>Перепроверить скрипты</span>
                </button>
                <button type="button" class="btn" data-opcache-action="reload">
                    <svg class="btn-icon" aria-hidden="true" viewBox="0 0 256 256">
                        <use href="/images/icons/phosphor-sprite.svg#chart-bar"></use>
                    </svg>
                    <span>Обновить статистику</span>
                </button>
                <button type="button" class="btn" data-opcache-action="toggle-config">
                    <svg class="btn-icon" aria-hidden="true" viewBox="0 0 256 256">
                        <use href="/images/icons/phosphor-sprite.svg#wrench"></use>
                    </svg>
                    <span>Показать конфигурацию</span>
                </button>
            </div>
            <div id="result" class="result" hidden></div>
            <div class="refresh-info">Статистика обновляется автоматически каждые 30 секунд</div>
        </section>

        <section class="card">
            <h2 class="card-title">
                <svg class="card-title-icon" aria-hidden="true" viewBox="0 0 256 256">
                    <use href="/images/icons/phosphor-sprite.svg#list-bullets"></use>
                </svg>
                <span>Детальная информация</span>
            </h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Параметр</th>
                        <th>Значение</th>
                        <th>Описание</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Время запуска</td>
                        <td><?= $startTime ? date('Y-m-d H:i:s', $startTime) : 'N/A' ?></td>
                        <td>Время когда OPcache был запущен</td>
                    </tr>
                    <tr>
                        <td>Последний перезапуск</td>
                        <td><?= $lastRestartTime ? date('Y-m-d H:i:s', $lastRestartTime) : 'N/A' ?></td>
                        <td>Время последнего перезапуска OPcache</td>
                    </tr>
                    <tr>
                        <td>Время работы</td>
                        <td><?= $startTime ? gmdate('H:i:s', time() - $startTime) : 'N/A' ?></td>
                        <td>Сколько времени работает OPcache</td>
                    </tr>
                    <tr>
                        <td>opcache.memory_consumption</td>
                        <td><?= formatBytes($directives['opcache.memory_consumption'] ?? 0) ?></td>
                        <td>Максимальный размер памяти для OPcache</td>
                    </tr>
                    <tr>
                        <td>opcache.max_accelerated_files</td>
                        <td><?= number_format($directives['opcache.max_accelerated_files'] ?? 0) ?></td>
                        <td>Максимальное количество файлов в кэше</td>
                    </tr>
                    <tr>
                        <td>opcache.validate_timestamps</td>
                        <td><?= $directives['opcache.validate_timestamps'] ? 'Включено' : 'Выключено' ?></td>
                        <td>Проверка изменений файлов</td>
                    </tr>
                    <tr>
                        <td>opcache.revalidate_freq</td>
                        <td><?= $directives['opcache.revalidate_freq'] ?? 0 ?> секунд</td>
                        <td>Частота перепроверки файлов</td>
                    </tr>
                    <tr>
                        <td>opcache.save_comments</td>
                        <td><?= $directives['opcache.save_comments'] ? 'Включено' : 'Выключено' ?></td>
                        <td>Сохранение комментариев в кэше</td>
                    </tr>
                    <tr>
                        <td>opcache.enable_file_override</td>
                        <td><?= $directives['opcache.enable_file_override'] ? 'Включено' : 'Выключено' ?></td>
                        <td>Переопределение файлов</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="card" id="configSection" hidden>
            <h2 class="card-title">
                <svg class="card-title-icon" aria-hidden="true" viewBox="0 0 256 256">
                    <use href="/images/icons/phosphor-sprite.svg#wrench"></use>
                </svg>
                <span>Конфигурация OPcache</span>
            </h2>
            <pre class="config-pre"><?= htmlspecialchars(print_r($directives, true)) ?></pre>
        </section>
    </div>

    <script src="/js/opcache-status-page.js?v=<?= htmlspecialchars((string)$jsVersion) ?>"></script>
</body>
</html>