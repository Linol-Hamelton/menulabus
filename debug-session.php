<?php
ob_start();
require_once __DIR__ . '/session_init.php';
ob_end_clean();

echo "Current session save handler: " . ini_get('session.save_handler') . "\n";
echo "Current session save path: " . ini_get('session.save_path') . "\n";
echo "Redis extension loaded: " . (extension_loaded('redis') ? 'yes' : 'no') . "\n";
echo "Memcached extension loaded: " . (extension_loaded('memcached') ? 'yes' : 'no') . "\n";
