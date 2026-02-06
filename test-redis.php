<?php
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing Redis connection...\n";

// Проверка расширения Redis
if (!extension_loaded('redis')) {
    echo "ERROR: Redis extension not loaded.\n";
    echo "Loaded extensions: " . implode(', ', get_loaded_extensions()) . "\n";
    exit(1);
}

echo "Redis extension version: " . phpversion('redis') . "\n";

$redis = new Redis();
$timeout = 2.5;
$host = '127.0.0.1';
$port = 6379;

try {
    $connected = $redis->connect($host, $port, $timeout);
    if ($connected) {
        echo "SUCCESS: Redis connection established.\n";
        
        // Тест записи/чтения
        $testKey = 'test_' . time();
        $testValue = 'Hello Redis at ' . date('Y-m-d H:i:s');
        $redis->set($testKey, $testValue);
        $retrieved = $redis->get($testKey);
        
        if ($retrieved === $testValue) {
            echo "Data storage test PASSED.\n";
        } else {
            echo "Data storage test FAILED (retrieved: $retrieved).\n";
        }
        
        $redis->del($testKey);
        
        // Проверка конфигурации сессий
        echo "\nSession save handler: " . ini_get('session.save_handler') . "\n";
        echo "Session save path: " . ini_get('session.save_path') . "\n";
        
        if (ini_get('session.save_handler') === 'redis') {
            echo "INFO: PHP sessions are configured to use Redis.\n";
        } else {
            echo "WARNING: PHP sessions are NOT using Redis (current: " . ini_get('session.save_handler') . ").\n";
            echo "To switch, ensure session.save_handler = redis in php.ini or .user.ini\n";
        }
        
    } else {
        echo "ERROR: Redis connection failed (no error details).\n";
    }
} catch (Exception $e) {
    echo "ERROR: Redis connection exception: " . $e->getMessage() . "\n";
}