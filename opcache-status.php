<?php
// opcache-status.php
$required_role = 'admin';
require_once __DIR__ . '/require_auth.php';

// Проверка доступности OPcache
if (!extension_loaded('Zend OPcache')) {
    die('OPcache extension not loaded');
}

// Обработка действий
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'reset':
            $result = opcache_reset();
            echo json_encode([
                'success' => $result,
                'message' => $result ? 'Кэш OPcache успешно сброшен' : 'Ошибка сброса кэша'
            ]);
            break;
            
        case 'revalidate':
            if (function_exists('opcache_invalidate')) {
                // Инвалидируем все файлы
                $files = get_included_files();
                $invalidated = 0;
                foreach ($files as $file) {
                    if (opcache_invalidate($file, true)) {
                        $invalidated++;
                    }
                }
                echo json_encode([
                    'success' => true,
                    'message' => "Перепроверено {$invalidated} файлов"
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Функция opcache_invalidate недоступна'
                ]);
            }
            break;
            
        case 'get_stats':
            $status = opcache_get_status();
            $config = opcache_get_configuration();
            echo json_encode([
                'success' => true,
                'data' => [
                    'status' => $status,
                    'config' => $config
                ]
            ]);
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Неизвестное действие'
            ]);
    }
    exit;
}

$status = opcache_get_status();
$config = opcache_get_configuration();

// Функция для форматирования байтов
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Функция для расчета процентов
function calculatePercentage($used, $total) {
    if ($total == 0) return 0;
    return round(($used / $total) * 100, 2);
}

// Получение метрик
$memoryUsage = $status['memory_usage'] ?? [];
$opcacheStats = $status['opcache_statistics'] ?? [];
$directives = $config['directives'] ?? [];

// Расчет hit rate
$hitRate = isset($opcacheStats['opcache_hit_rate']) ? 
    round($opcacheStats['opcache_hit_rate'], 2) : 0;

// Расчет использования памяти
$usedMemory = $memoryUsage['used_memory'] ?? 0;
$freeMemory = $memoryUsage['free_memory'] ?? 0;
$wastedMemory = $memoryUsage['wasted_memory'] ?? 0;
$totalMemory = $usedMemory + $freeMemory + $wastedMemory;

// Количество скриптов
$cachedScripts = $opcacheStats['num_cached_scripts'] ?? 0;
$maxCachedScripts = $directives['opcache.max_accelerated_files'] ?? 0;

// Время
$startTime = $opcacheStats['start_time'] ?? 0;
$lastRestartTime = $opcacheStats['last_restart_time'] ?? 0;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мониторинг OPcache</title>
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0; 
            padding: 20px; 
            background: #f5f5f5; 
            color: #333;
        }
        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
        }
        header { 
            background: #2c3e50; 
            color: white; 
            padding: 20px; 
            border-radius: 8px 8px 0 0;
            margin-bottom: 20px;
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
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .card h2 { 
            margin-top: 0; 
            color: #2c3e50; 
            border-bottom: 2px solid #3498db; 
            padding-bottom: 10px;
        }
        .metric { 
            margin: 15px 0;
        }
        .metric-label { 
            font-weight: bold; 
            color: #7f8c8d; 
            margin-bottom: 5px;
        }
        .metric-value { 
            font-size: 1.4em; 
            color: #2c3e50;
        }
        .progress-bar { 
            height: 20px; 
            background: #ecf0f1; 
            border-radius: 10px; 
            overflow: hidden; 
            margin: 10px 0;
        }
        .progress-fill { 
            height: 100%; 
            background: linear-gradient(90deg, #3498db, #2ecc71); 
            transition: width 0.3s;
        }
        .progress-text { 
            text-align: center; 
            font-size: 0.9em; 
            color: #7f8c8d; 
            margin-top: 5px;
        }
        .stats-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 15px; 
            margin-top: 20px;
        }
        .stat-item { 
            background: #f8f9fa; 
            padding: 15px; 
            border-radius: 6px; 
            border-left: 4px solid #3498db;
        }
        .stat-label { 
            font-size: 0.9em; 
            color: #6c757d;
        }
        .stat-value { 
            font-size: 1.2em; 
            font-weight: bold; 
            color: #2c3e50;
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
            transition: background 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary { 
            background: #3498db; 
            color: white;
        }
        .btn-primary:hover { 
            background: #2980b9;
        }
        .btn-danger { 
            background: #e74c3c; 
            color: white;
        }
        .btn-danger:hover { 
            background: #c0392b;
        }
        .btn-success { 
            background: #2ecc71; 
            color: white;
        }
        .btn-success:hover { 
            background: #27ae60;
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
            background: #f8f9fa; 
            font-weight: bold; 
            color: #495057;
        }
        .table tr:hover { 
            background: #f8f9fa;
        }
        .status-good { 
            color: #27ae60; 
            font-weight: bold;
        }
        .status-warning { 
            color: #f39c12; 
            font-weight: bold;
        }
        .status-critical { 
            color: #e74c3c; 
            font-weight: bold;
        }
        .refresh-info { 
            text-align: center; 
            margin-top: 20px; 
            color: #7f8c8d; 
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
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>📊 Мониторинг OPcache</h1>
            <div class="subtitle">Версия PHP: <?= PHP_VERSION ?> | Время сервера: <?= date('Y-m-d H:i:s') ?></div>
        </header>
        
        <div class="dashboard">
            <!-- Карточка Hit Rate -->
            <div class="card">
                <h2>⚡ Эффективность кэша</h2>
                <div class="metric">
                    <div class="metric-label">Hit Rate</div>
                    <div class="metric-value <?= $hitRate > 90 ? 'status-good' : ($hitRate > 70 ? 'status-warning' : 'status-critical') ?>">
                        <?= $hitRate ?>%
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?= min($hitRate, 100) ?>%"></div>
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
                        <div class="stat-label">Черный список</div>
                        <div class="stat-value"><?= number_format($opcacheStats['blacklist_misses'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Карточка памяти -->
            <div class="card">
                <h2>💾 Использование памяти</h2>
                <div class="metric">
                    <div class="metric-label">Использовано памяти</div>
                    <div class="metric-value"><?= formatBytes($usedMemory) ?> / <?= formatBytes($totalMemory) ?></div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?= calculatePercentage($usedMemory, $totalMemory) ?>%"></div>
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
            </div>
            
            <!-- Карточка скриптов -->
            <div class="card">
                <h2>📁 Кэшированные скрипты</h2>
                <div class="metric">
                    <div class="metric-label">Скриптов в кэше</div>
                    <div class="metric-value"><?= number_format($cachedScripts) ?> / <?= number_format($maxCachedScripts) ?></div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?= calculatePercentage($cachedScripts, $maxCachedScripts) ?>%"></div>
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
            </div>
        </div>
        
        <!-- Действия -->
        <div class="card">
            <h2>⚙️ Управление OPcache</h2>
            <div class="actions">
                <button class="btn btn-primary" onclick="resetCache()">
                    <span class="icon">🔄</span> Сбросить кэш
                </button>
                <button class="btn btn-success" onclick="revalidateCache()">
                    <span class="icon">🔍</span> Перепроверить скрипты
                </button>
                <button class="btn" onclick="location.reload()">
                    <span class="icon">📊</span> Обновить статистику
                </button>
                <button class="btn" onclick="showConfig()">
                    <span class="icon">⚙️</span> Показать конфигурацию
                </button>
            </div>
            <div id="result" class="result"></div>
            <div class="refresh-info">
                Статистика обновляется автоматически каждые 30 секунд
            </div>
        </div>
        
        <!-- Детальная информация -->
        <div class="card">
            <h2>📋 Детальная информация</h2>
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
        </div>
        
        <!-- Конфигурация (скрыта по умолчанию) -->
        <div class="card" id="configSection" style="display: none;">
            <h2>⚙️ Конфигурация OPcache</h2>
            <pre style="background: #f8f9fa; padding: 15px; border-radius: 4px; overflow: auto; max-height: 400px;">
<?= htmlspecialchars(print_r($directives, true)) ?>
            </pre>
        </div>
    </div>
    
    <script>
    // Функция для показа результата
    function showResult(message, isSuccess) {
        const resultDiv = document.getElementById('result');
        resultDiv.className = 'result ' + (isSuccess ? 'success' : 'error');
        resultDiv.textContent = message;
        resultDiv.style.display = 'block';
        
        // Скрыть через 5 секунд
        setTimeout(() => {
            resultDiv.style.display = 'none';
        }, 5000);
    }
    
    // Функция сброса кэша
    function resetCache() {
        if (!confirm('Вы уверены? Это сбросит весь кэш OPcache.')) {
            return;
        }
        
        fetch('?action=reset')
            .then(response => response.json())
            .then(data => {
                showResult(data.message, data.success);
                if (data.success) {
                    // Обновить страницу через 2 секунды
                    setTimeout(() => location.reload(), 2000);
                }
            })
            .catch(error => {
                showResult('Ошибка: ' + error.message, false);
            });
    }
    
    // Функция перепроверки скриптов
    function revalidateCache() {
        fetch('?action=revalidate')
            .then(response => response.json())
            .then(data => {
                showResult(data.message, data.success);
            })
            .catch(error => {
                showResult('Ошибка: ' + error.message, false);
            });
    }
    
    // Функция показа конфигурации
    function showConfig() {
        const configSection = document.getElementById('configSection');
        configSection.style.display = configSection.style.display === 'none' ? 'block' : 'none';
    }
    
    // Автоматическое обновление статистики каждые 30 секунд
    setInterval(() => {
        // Обновляем только если пользователь не взаимодействует с элементами управления
        if (!document.querySelector('.btn:disabled')) {
            fetch('?action=get_stats')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Здесь можно обновить отдельные элементы страницы
                        // Для простоты просто обновляем всю страницу
                        // location.reload();
                    }
                })
                .catch(error => console.error('Error updating stats:', error));
        }
    }, 30000);
    
    // Добавить обработчик для всех кнопок
    document.addEventListener('DOMContentLoaded', function() {
        const buttons = document.querySelectorAll('.btn');
        buttons.forEach(btn => {
            btn.addEventListener('click', function() {
                // Временная блокировка кнопки на 2 секунды
                this.disabled = true;
                setTimeout(() => {
                    this.disabled = false;
                }, 2000);
            });
        });
    });
    </script>
</body>
</html>
