<?php

declare(strict_types=1);

namespace Cleanmenu\Tests\Fixtures;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Shared helper for tests that need a real MySQL connection.
 *
 * Opt in by exporting:
 *
 *   CLEANMENU_TEST_MYSQL_DSN   (e.g. "mysql:host=127.0.0.1;dbname=cleanmenu_test;charset=utf8mb4")
 *   CLEANMENU_TEST_MYSQL_USER
 *   CLEANMENU_TEST_MYSQL_PASS
 *
 * Without CLEANMENU_TEST_MYSQL_DSN, tests that call requirePdo() must check
 * skipReason() at the top of setUp() and markTestSkipped() with the returned
 * message. This keeps the lifecycle suite green on vanilla hosts while letting
 * CI opt into the DB-coupled suite.
 *
 * The database named in the DSN must already exist and be dedicated to tests —
 * applySchema() drops and recreates the tables listed in MANAGED_TABLES on
 * the first call per process, and resetManagedTables() TRUNCATEs them on
 * every setUp(). Both will clobber any data in those tables.
 */
final class TestDatabase
{
    private const MANAGED_TABLES = [
        'api_idempotency_keys',
        'order_items',
        'order_status_history',
        'orders',
    ];

    public static function dsn(): ?string
    {
        $dsn = getenv('CLEANMENU_TEST_MYSQL_DSN');
        return is_string($dsn) && $dsn !== '' ? $dsn : null;
    }

    public static function skipReason(): ?string
    {
        if (self::dsn() === null) {
            return 'CLEANMENU_TEST_MYSQL_DSN is not set; skipping MySQL-coupled test.';
        }
        return null;
    }

    public static function requirePdo(): PDO
    {
        $dsn = self::dsn();
        if ($dsn === null) {
            throw new RuntimeException('CLEANMENU_TEST_MYSQL_DSN is not set.');
        }

        $user = (string)(getenv('CLEANMENU_TEST_MYSQL_USER') ?: '');
        $pass = (string)(getenv('CLEANMENU_TEST_MYSQL_PASS') ?: '');

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to connect to test MySQL: ' . $e->getMessage(), 0, $e);
        }

        // Force strict mode so DECIMAL/INT overflow and bad ENUM values raise
        // instead of silently truncating. CreateOrderTest's rollback case
        // relies on this — without STRICT_ALL_TABLES a DECIMAL(10,2) overflow
        // would clamp and the "transaction must roll back" assertion would
        // never fire. Keeping this in the fixture means every DB-coupled test
        // sees identical behavior regardless of host sql_mode defaults.
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'");

        self::applySchema($pdo);
        return $pdo;
    }

    public static function resetManagedTables(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::MANAGED_TABLES as $table) {
            $pdo->exec("TRUNCATE TABLE `{$table}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Apply the minimal schema this fixture needs. Uses CREATE TABLE IF NOT
     * EXISTS so repeated runs are cheap; drops and recreates on the first
     * call per process to guarantee a clean baseline shape.
     */
    private static function applySchema(PDO $pdo): void
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::MANAGED_TABLES) as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $pdo->exec("
            CREATE TABLE `orders` (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT DEFAULT NULL,
                items JSON NOT NULL,
                total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                tips DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                status ENUM('Приём','готовим','доставляем','завершён','отказ') NOT NULL DEFAULT 'Приём',
                delivery_type VARCHAR(32) NOT NULL DEFAULT 'bar',
                delivery_details VARCHAR(255) NOT NULL DEFAULT '',
                payment_method ENUM('cash','online','sbp') NOT NULL DEFAULT 'cash',
                payment_id VARCHAR(100) DEFAULT NULL,
                payment_status ENUM('not_required','pending','paid','failed','cancelled') NOT NULL DEFAULT 'not_required',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_orders_payment_id (payment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE `order_status_history` (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id INT UNSIGNED NOT NULL,
                status VARCHAR(32) NOT NULL,
                changed_by INT DEFAULT NULL,
                changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_osh_order_id (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE `order_items` (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                item_id BIGINT UNSIGNED NOT NULL,
                item_name VARCHAR(255) DEFAULT NULL,
                quantity INT NOT NULL DEFAULT 1,
                price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_order_items_order_id (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE `api_idempotency_keys` (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                idempotency_key VARCHAR(128) NOT NULL,
                scope VARCHAR(64) NOT NULL,
                request_hash CHAR(64) NOT NULL,
                response_json JSON NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_idempotency_scope_key (scope, idempotency_key),
                KEY idx_idempotency_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $initialized = true;
    }
}
