<?php
// clear-nginx-cache.php
require_once 'check-auth.php';

if ($_SESSION['user_role'] !== 'owner' && $_SESSION['user_role'] !== 'admin') {
    die('Access denied');
}

function clearNginxCache() {
    $cacheDir = '/var/cache/nginx/fastcgi';
    
    if (!is_dir($cacheDir)) {
        return ['success' => false, 'message' => 'Директория кэша не найдена: ' . $cacheDir];
    }
    
    // Используем find для удаления файлов кэша
    $command = "find {$cacheDir} -type f -delete 2>/dev/null";
    exec($command, $output, $returnCode);
    
    if ($returnCode === 0) {
        return ['success' => true, 'message' => 'Кэш Nginx успешно очищен'];
    } else {
        // Альтернативный метод: удаление через PHP
        $filesDeleted = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                if (unlink($file->getPathname())) {
                    $filesDeleted++;
                }
            }
        }
        
        if ($filesDeleted > 0) {
            return ['success' => true, 'message' => "Кэш очищен (удалено {$filesDeleted} файлов через PHP)"];
        } else {
            return ['success' => false, 'message' => 'Не удалось очистить кэш'];
        }
    }
}

// Очистка кэша при GET запросе с параметром clear=1
if (isset($_GET['clear']) && $_GET['clear'] == '1') {
    header('Content-Type: application/json');
    $result = clearNginxCache();
    echo json_encode($result);
    exit;
}

// HTML интерфейс для очистки кэша
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Очистка кэша Nginx</title>
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0; 
            padding: 20px; 
            background: #f5f5f5; 
            color: #333;
        }
        .container { 
            max-width: 800px; 
            margin: 40px auto; 
            background: white; 
            padding: 30px; 
            border-radius: 10px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { 
            color: #2c3e50; 
            margin-bottom: 20px; 
            border-bottom: 2px solid #3498db; 
            padding-bottom: 10px;
        }
        .info-box { 
            background: #e8f4fc; 
            border-left: 4px solid #3498db; 
            padding: 15px; 
            margin: 20px 0; 
            border-radius: 4px;
        }
        .btn { 
            background: #3498db; 
            color: white; 
            border: none; 
            padding: 12px 24px; 
            border-radius: 4px; 
            cursor: pointer; 
            font-size: 16px; 
            font-weight: bold;
            transition: background 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn:hover { 
            background: #2980b9; 
        }
        .btn:disabled { 
            background: #95a5a6; 
            cursor: not-allowed;
        }
        .btn-danger { 
            background: #e74c3c; 
        }
        .btn-danger:hover { 
            background: #c0392b; 
        }
        .result { 
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
        .stats { 
            background: #f8f9fa; 
            padding: 15px; 
            border-radius: 4px; 
            margin: 20px 0;
        }
        .stats h3 { 
            margin-top: 0; 
            color: #2c3e50;
        }
        .cache-info { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 15px; 
            margin-top: 15px;
        }
        .cache-item { 
            background: white; 
            padding: 10px; 
            border-radius: 4px; 
            border: 1px solid #dee2e6;
        }
        .cache-label { 
            font-weight: bold; 
            color: #6c757d; 
            font-size: 0.9em;
        }
        .cache-value { 
            font-size: 1.2em; 
            color: #2c3e50;
        }
        .icon { 
            font-size: 1.2em;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 Управление кэшем Nginx</h1>
        
        <div class="info-box">
            <p><strong>Информация:</strong> FastCGI Cache хранит кэшированные версии страниц для ускорения загрузки. Очистка кэша необходима после обновления контента на сайте.</p>
        </div>
        
        <div class="stats">
            <h3>📊 Статистика кэша</h3>
            <div class="cache-info" id="cacheStats">
                <div class="cache-item">
                    <div class="cache-label">Статус кэша</div>
                    <div class="cache-value" id="cacheStatus">Загрузка...</div>
                </div>
                <div class="cache-item">
                    <div class="cache-label">Размер кэша</div>
                    <div class="cache-value" id="cacheSize">Загрузка...</div>
                </div>
                <div class="cache-item">
                    <div class="cache-label">Файлов в кэше</div>
                    <div class="cache-value" id="cacheFiles">Загрузка...</div>
                </div>
            </div>
        </div>
        
        <div style="margin: 30px 0;">
            <h3>⚡ Быстрые действия</h3>
            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                <button class="btn" onclick="clearCache()">
                    <span class="icon">🗑️</span> Очистить весь кэш
                </button>
                
                <button class="btn" onclick="clearMenuCache()">
                    <span class="icon">🍽️</span> Очистить кэш меню
                </button>
                
                <button class="btn" onclick="clearStaticCache()">
                    <span class="icon">📁</span> Очистить статический кэш
                </button>
                
                <button class="btn btn-danger" onclick="purgeAllCache()">
                    <span class="icon">🔥</span> Полная очистка
                </button>
            </div>
        </div>
        
        <div id="result" class="result"></div>
        
        <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #dee2e6;">
            <h3>📋 История операций</h3>
            <div id="operationLog" style="max-height: 200px; overflow-y: auto; margin-top: 10px;">
                <!-- История будет добавляться здесь -->
            </div>
        </div>
    </div>
    
    <script>
    // Функция для добавления записи в лог
    function addLog(message, type = 'info') {
        const logDiv = document.getElementById('operationLog');
        const timestamp = new Date().toLocaleTimeString();
        const logEntry = document.createElement('div');
        logEntry.style.padding = '5px 10px';
        logEntry.style.margin = '5px 0';
        logEntry.style.borderLeft = '3px solid ' + (type === 'error' ? '#e74c3c' : type === 'success' ? '#2ecc71' : '#3498db');
        logEntry.style.background = '#f8f9fa';
        logEntry.innerHTML = `<strong>[${timestamp}]</strong> ${message}`;
        logDiv.prepend(logEntry);
    }
    
    // Функция для обновления статистики
    function updateStats() {
        fetch('?stats=1')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('cacheStatus').textContent = data.status;
                    document.getElementById('cacheSize').textContent = data.size;
                    document.getElementById('cacheFiles').textContent = data.files;
                }
            })
            .catch(error => {
                console.error('Error fetching stats:', error);
            });
    }
    
    // Функция очистки всего кэша
    function clearCache() {
        const btn = document.querySelector('.btn');
        const resultDiv = document.getElementById('result');
        
        btn.disabled = true;
        btn.innerHTML = '<span class="icon">⏳</span> Очистка...';
        resultDiv.style.display = 'none';
        
        fetch('?clear=1')
            .then(response => response.json())
            .then(data => {
                resultDiv.className = 'result ' + (data.success ? 'success' : 'error');
                resultDiv.textContent = data.message;
                resultDiv.style.display = 'block';
                
                addLog(data.message, data.success ? 'success' : 'error');
                
                btn.disabled = false;
                btn.innerHTML = '<span class="icon">🗑️</span> Очистить весь кэш';
                
                // Обновить статистику
                setTimeout(updateStats, 1000);
            })
            .catch(error => {
                resultDiv.className = 'result error';
                resultDiv.textContent = 'Ошибка: ' + error.message;
                resultDiv.style.display = 'block';
                
                addLog('Ошибка очистки кэша: ' + error.message, 'error');
                
                btn.disabled = false;
                btn.innerHTML = '<span class="icon">🗑️</span> Очистить весь кэш';
            });
    }
    
    // Функция очистки кэша меню
    function clearMenuCache() {
        addLog('Запущена очистка кэша меню...', 'info');
        // Реализация очистки только кэша меню
        // В реальной реализации здесь был бы отдельный endpoint
        alert('Функция очистки кэша меню будет реализована в следующей версии');
    }
    
    // Функция очистки статического кэша
    function clearStaticCache() {
        addLog('Запущена очистка статического кэша...', 'info');
        // Реализация очистки статического кэша
        alert('Функция очистки статического кэша будет реализована в следующей версии');
    }
    
    // Функция полной очистки
    function purgeAllCache() {
        if (confirm('Вы уверены? Это удалит ВЕСЬ кэш, включая системный. Операция может занять несколько минут.')) {
            addLog('Запущена полная очистка кэша...', 'warning');
            // Реализация полной очистки
            alert('Функция полной очистки кэша будет реализована в следующей версии');
        }
    }
    
    // Загрузить статистику при загрузке страницы
    document.addEventListener('DOMContentLoaded', function() {
        updateStats();
        addLog('Страница управления кэшем загружена', 'info');
    });
    
    // Обновлять статистику каждые 30 секунд
    setInterval(updateStats, 30000);
    </script>
</body>
</html><?php
// Обработка запроса статистики
if (isset($_GET['stats']) && $_GET['stats'] == '1') {
    header('Content-Type: application/json');
    
    $cacheDir = '/var/cache/nginx/fastcgi';
    $status = 'Недоступен';
    $size = '0 B';
    $files = 0;
    
    if (is_dir($cacheDir)) {
        $status = 'Активен';
        
        // Получить размер директории
        $output = [];
        exec("du -sh {$cacheDir} 2>/dev/null", $output);
        if (!empty($output)) {
            $size = trim($output[0]);
        }
        
        // Получить количество файлов
        $output = [];
        exec("find {$cacheDir} -type f | wc -l 2>/dev/null", $output);
        if (!empty($output)) {
            $files = intval(trim($output[0]));
        }
    }
    
    echo json_encode([
        'success' => true,
        'status' => $status,
        'size' => $size,
        'files' => $files
    ]);
    exit;
}
?>