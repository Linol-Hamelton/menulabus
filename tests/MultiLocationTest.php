<?php

declare(strict_types=1);

namespace Cleanmenu\Tests;

use Cleanmenu\Tests\Fixtures\TestDatabase;
use Database;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers the multi-location DB layer (Phase 6.5) against a real MySQL schema.
 * MySQL-gated — skipped when CLEANMENU_TEST_MYSQL_DSN is unset.
 *
 * Invariants locked here:
 *   - saveLocation validates name and timezone.
 *   - deleteLocation is a soft deactivation (active=0), not hard DROP — history must survive.
 *   - getOrdersByLocationSummary groups legacy NULL orders under pseudo-id 0.
 *   - Excludes refused orders from revenue aggregation.
 */
final class MultiLocationTest extends TestCase
{
    private ?PDO $pdo = null;
    private ?Database $db = null;

    protected function setUp(): void
    {
        $skip = TestDatabase::skipReason();
        if ($skip !== null) {
            self::markTestSkipped($skip);
        }

        $parentConfig = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config_copy.php';
        if (!is_file($parentConfig)) {
            self::markTestSkipped(
                "config_copy.php not found at {$parentConfig}; "
                . 'MultiLocationTest requires db.php -> tenant_runtime.php -> config_copy.php to be loadable.'
            );
        }

        $this->pdo = TestDatabase::requirePdo();

        $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $this->pdo->exec('DROP TABLE IF EXISTS orders');
        $this->pdo->exec('DROP TABLE IF EXISTS locations');
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        $this->pdo->exec("
            CREATE TABLE locations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                address VARCHAR(500) DEFAULT NULL,
                phone VARCHAR(32) DEFAULT NULL,
                timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Moscow',
                active TINYINT UNSIGNED NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo->exec("
            CREATE TABLE orders (
                id INT NOT NULL AUTO_INCREMENT,
                user_id INT DEFAULT NULL,
                items JSON NOT NULL,
                total DECIMAL(10,2) NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'Приём',
                delivery_details VARCHAR(255) DEFAULT '',
                location_id INT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        require_once __DIR__ . '/../db.php';
        $this->db = $this->makeDatabaseWithPdo($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS orders');
            $this->pdo->exec('DROP TABLE IF EXISTS locations');
        }
        $this->db = null;
        $this->pdo = null;
    }

    public function test_save_location_validates_inputs(): void
    {
        self::assertNull($this->db->saveLocation(null, '', null, null, 'Europe/Moscow', true, 0), 'Empty name refused.');
        self::assertNull($this->db->saveLocation(null, 'Центр', null, null, '', true, 0), 'Empty timezone refused.');
        self::assertIsInt($this->db->saveLocation(null, 'Центр', 'ул. Ленина', '+7 495 000 0000', 'Europe/Moscow', true, 0));
    }

    public function test_delete_is_soft_deactivation(): void
    {
        $id = $this->db->saveLocation(null, 'Север', null, null, 'Europe/Moscow', true, 1);
        self::assertTrue($this->db->deleteLocation($id));

        $row = $this->db->getLocationById($id);
        self::assertNotNull($row, 'Row still present after soft delete.');
        self::assertSame(0, (int)$row['active']);
    }

    public function test_summary_groups_legacy_null_orders_under_zero(): void
    {
        $central = $this->db->saveLocation(null, 'Центр', null, null, 'Europe/Moscow', true, 0);
        $north   = $this->db->saveLocation(null, 'Север', null, null, 'Europe/Moscow', true, 1);

        $today = date('Y-m-d 12:00:00');
        $mk = function (?int $loc, float $total, string $status) use ($today) {
            $this->pdo->prepare("INSERT INTO orders (user_id, items, total, status, location_id, created_at) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([10, json_encode([['id' => 1]]), $total, $status, $loc, $today]);
        };

        $mk($central, 500,  'завершён');
        $mk($central, 800,  'завершён');
        $mk($north,   400,  'завершён');
        $mk(null,     200,  'завершён'); // legacy pre-migration
        $mk($central, 999,  'отказ');    // refused — excluded

        $from = date('Y-m-d 00:00:00');
        $to   = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $rows = $this->db->getOrdersByLocationSummary($from, $to);

        $byLoc = [];
        foreach ($rows as $r) { $byLoc[(int)$r['location_id']] = $r; }

        self::assertSame(1300.0, (float)$byLoc[$central]['revenue'], 'Central sums 500 + 800, excludes refused 999.');
        self::assertSame(400.0,  (float)$byLoc[$north]['revenue']);
        self::assertSame(200.0,  (float)$byLoc[0]['revenue'],   'Legacy NULL order bucket.');
        self::assertNull($byLoc[0]['location_name'] ?? null,    'Legacy bucket has no name.');
    }

    public function test_list_active_only_filters_inactive(): void
    {
        $a = $this->db->saveLocation(null, 'Active1', null, null, 'Europe/Moscow', true, 0);
        $b = $this->db->saveLocation(null, 'Hidden',  null, null, 'Europe/Moscow', false, 1);

        $all = $this->db->listLocations(false);
        $active = $this->db->listLocations(true);

        self::assertCount(2, $all);
        self::assertCount(1, $active);
        self::assertSame($a, (int)$active[0]['id']);
    }

    private function makeDatabaseWithPdo(PDO $pdo): Database
    {
        $reflection = new ReflectionClass(Database::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $connection = $reflection->getProperty('connection');
        $connection->setAccessible(true);
        $connection->setValue($instance, $pdo);

        $prepared = $reflection->getProperty('preparedStatements');
        $prepared->setAccessible(true);
        $prepared->setValue($instance, []);

        $tenantContext = $reflection->getProperty('tenantContext');
        $tenantContext->setAccessible(true);
        $tenantContext->setValue($instance, []);

        $tenantCacheNs = $reflection->getProperty('tenantCacheNamespace');
        $tenantCacheNs->setAccessible(true);
        $tenantCacheNs->setValue($instance, 'tenant:test');

        return $instance;
    }
}
