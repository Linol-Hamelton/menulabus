<?php
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing Memcached connection...\n";

// Проверка расширения Memcached
if (!extension_loaded('memcached') && !extension_loaded('memcache')) {
    echo "ERROR: Neither Memcached nor Memcache extensions loaded.\n";
    echo "Please install php-memcached (sudo apt install php-memcached)\n";
    exit(1);
}

if (extension_loaded('memcached')) {
    echo "Memcached extension version: " . phpversion('memcached') . "\n";
    $mem = new Memcached();
    $mem->addServer('127.0.0.1', 11211);
    $version = $mem->getVersion();
    echo "Server version: " . json_encode($version) . "\n";
    
    // Тест записи/чтения
    $testKey = 'test_' . time();
    $testValue = 'Hello Memcached at ' . date('Y-m-d H:i:s');
    $mem->set($testKey, $testValue, 10);
    $retrieved = $mem->get($testKey);
    
    if ($retrieved === $testValue) {
        echo "Data storage test PASSED.\n";
    } else {
        echo "Data storage test FAILED (retrieved: $retrieved).\n";
    }
    
    $mem->delete($testKey);
    
    // Проверка конфигурации сессий
    echo "\nSession save handler: " . ini_get('session.save_handler') . "\n";
    echo "Session save path: " . ini_get('session.save_path') . "\n";
    
    if (ini_get('session.save_handler') === 'memcached' || ini_get('session.save_handler') === 'memcache') {
        echo "INFO: PHP sessions are configured to use Memcached.\n";
    } else {
        echo "WARNING: PHP sessions are NOT using Memcached (current: " . ini_get('session.save_handler') . ").\n";
    }
    
} elseif (extension_loaded('memcache')) {
    echo "Memcache extension version: " . phpversion('memcache') . "\n";
    $mem = new Memcache();
    if ($mem->connect('127.0.0.1', 11211)) {
        echo "Connected to Memcache.\n";
        $testKey = 'test_' . time();
        $testValue = 'Hello Memcache at ' . date('Y-m-d H:i:s');
        $mem->set($testKey, $testValue, 0, 10);
        $retrieved = $mem->get($testKey);
        if ($retrieved === $testValue) {
            echo "Data storage test PASSED.\n";
        } else {
            echo "Data storage test FAILED (retrieved: $retrieved).\n";
        }
        $mem->delete($testKey);
    } else {
        echo "ERROR: Cannot connect to Memcache.\n";
    }
}
?>