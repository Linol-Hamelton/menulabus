<?php

declare(strict_types=1);

// PHPUnit bootstrap for Cleanmenu / Menu Labus.
//
// Loads pure-PHP modules that tests depend on without pulling in the full
// session_init.php / tenant_runtime.php stack. Tests that need a real MySQL
// connection (CreateOrderTest, IdempotencyTest) opt in via the
// CLEANMENU_TEST_MYSQL_DSN environment variable and skip gracefully when it
// is missing — see tests/fixtures/TestDatabase.php.

define('LABUS_CTX', 'test');

$_SERVER['SCRIPT_FILENAME'] = $_SERVER['SCRIPT_FILENAME'] ?? __FILE__;
$_SERVER['HTTP_HOST']       = $_SERVER['HTTP_HOST']       ?? 'test.local';

require_once __DIR__ . '/../lib/orders/lifecycle.php';
require_once __DIR__ . '/../lib/Idempotency.php';
require_once __DIR__ . '/fixtures/TestDatabase.php';
