<?php
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== Проверка применённых оптимизаций ===\n\n";

// 1. Проверка PHP-FPM параметров
echo "1. Параметры PHP-FPM:\n";
$params = [
    'pm' => ini_get('pm'),
    'pm.max_children' => ini_get('pm.max_children'),
    'pm.start_servers' => ini_get('pm.start_servers'),
    'pm.min_spare_servers' => ini_get('pm.min_spare_servers'),
    'pm.max_spare_servers' => ini_get('pm.max_spare_servers'),
    'pm.max_requests' => ini_get('pm.max_requests'),
    'request_terminate_timeout' => ini_get('request_terminate_timeout'),
];

foreach ($params as $key => $value) {
    echo "   $key = " . ($value !== false ? $value : 'не установлен') . "\n";
}

// 2. Проверка Redis/Memcached расширений
echo "\n2. Расширения кэширования:\n";
echo "   Redis: " . (extension_loaded('redis') ? '✅' : '❌') . "\n";
echo "   Memcached: " . (extension_loaded('memcached') ? '✅' : '❌') . "\n";
echo "   Memcache: " . (extension_loaded('memcache') ? '✅' : '❌') . "\n";

// 3. Проверка сессий
echo "\n3. Сессии:\n";
echo "   session.save_handler = " . ini_get('session.save_handler') . "\n";
echo "   session.save_path = " . ini_get('session.save_path') . "\n";

// 4. Проверка FastCGI cache (по заголовку X-Cache)
echo "\n4. FastCGI кэш (заголовок X-Cache):\n";
$headers = headers_list();
$xcacheFound = false;
foreach ($headers as $header) {
    if (stripos($header, 'X-Cache') !== false) {
        echo "   $header\n";
        $xcacheFound = true;
    }
}
if (!$xcacheFound) {
    echo "   Заголовок X-Cache не отправлен (возможно, кэш не настроен или страница не кэшируется).\n";
}

// 5. Проверка сжатия gzip
echo "\n5. Сжатие gzip:\n";
if (function_exists('apache_response_headers')) {
    $apacheHeaders = apache_response_headers();
    $gzip = false;
    foreach ($apacheHeaders as $key => $value) {
        if (strtolower($key) === 'content-encoding' && strpos($value, 'gzip') !== false) {
            $gzip = true;
            break;
        }
    }
    echo "   " . ($gzip ? '✅ Включено' : '❌ Отключено или не используется') . "\n";
} else {
    echo "   Не удалось проверить (функция apache_response_headers недоступна).\n";
}

// 6. Проверка времени выполнения
echo "\n6. Время выполнения скрипта:\n";
$time = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
echo "   " . round($time * 1000, 2) . " мс\n";

echo "\n=== Конец проверки ===\n";

// Рекомендации
if (!extension_loaded('redis') && ini_get('session.save_handler') === 'files') {
    echo "\n⚠️  Рекомендация: установите расширение Redis для сессий (см. fastpanel-redis-install.md).\n";
}

if (ini_get('pm.max_children') < 50) {
    echo "⚠️  Рекомендация: увеличьте pm.max_children до 50–100.\n";
}

?>