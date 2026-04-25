<?php

declare(strict_types=1);

namespace Cleanmenu\Tests;

use Cleanmenu\Tests\Fixtures\TestDatabase;
use Database;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers the enhanced analytics DB layer (Phase 6.4) against a real MySQL schema.
 * MySQL-gated — skipped when CLEANMENU_TEST_MYSQL_DSN is unset.
 *
 * These queries lean on MySQL 8 JSON_TABLE / CTEs. Tests also skip if the
 * server is older than 8.0 (JSON_TABLE appeared in 8.0.4).
 */
final class AnalyticsV2Test extends TestCase
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
                . 'AnalyticsV2Test requires db.php -> tenant_runtime.php -> config_copy.php to be loadable.'
            );
        }

        $this->pdo = TestDatabase::requirePdo();

        // JSON_TABLE and WITH-CTEs require MySQL 8. Skip on older.
        try {
            $ver = (string)$this->pdo->query('SELECT VERSION()')->fetchColumn();
            if (!preg_match('/^8\./', $ver) && !preg_match('/^1[0-9]\./', $ver)) {
                self::markTestSkipped('MySQL 8+ required for JSON_TABLE/CTE; got ' . $ver);
            }
        } catch (\Throwable $e) {
            self::markTestSkipped('Could not detect MySQL version: ' . $e->getMessage());
        }

        // Minimal schema sufficient for the analytics queries.
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $this->pdo->exec('DROP TABLE IF EXISTS orders_a2');
        $this->pdo->exec('DROP TABLE IF EXISTS menu_items_a2');
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        // The production queries reference "orders" and "menu_items" by name.
        // Use these names via temporary CREATEs; TestDatabase may already own
        // an `orders` table (for CreateOrderTest), so we drop and recreate.
        $this->pdo->exec('DROP TABLE IF EXISTS orders');
        $this->pdo->exec('DROP TABLE IF EXISTS menu_items');
        $this->pdo->exec("
            CREATE TABLE menu_items (
                id INT NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                category VARCHAR(50) NOT NULL,
                cost DECIMAL(10,2) NOT NULL DEFAULT 0,
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
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Seed menu.
        $this->pdo->exec("INSERT INTO menu_items (id, name, category, cost) VALUES
            (401, 'Pizza', 'Горячее', 150),
            (402, 'Salad', 'Холодное', 50),
            (403, 'Coffee', 'Напитки', 15)
        ");

        // Seed orders spanning two months and multiple users, all within the
        // last 60 days so the "last 30 days" window still picks part of them.
        $mkOrder = function (int $userId, string $createdAt, array $items, float $total, string $status = 'завершён') {
            $this->pdo->prepare("
                INSERT INTO orders (user_id, items, total, status, created_at)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$userId, json_encode($items, JSON_UNESCAPED_UNICODE), $total, $status, $createdAt]);
        };

        $today = date('Y-m-d');
        $wk1 = date('Y-m-d', strtotime('-7 days'));
        $wk2 = date('Y-m-d', strtotime('-14 days'));
        $wk3 = date('Y-m-d', strtotime('-21 days'));

        $mkOrder(501, $wk3 . ' 12:00:00', [['id' => 401, 'price' => 500, 'quantity' => 2]], 1000);
        $mkOrder(501, $wk2 . ' 13:00:00', [['id' => 402, 'price' => 300, 'quantity' => 1]], 300);
        $mkOrder(501, $wk1 . ' 19:00:00', [['id' => 401, 'price' => 500, 'quantity' => 1], ['id' => 403, 'price' => 120, 'quantity' => 2]], 740);
        $mkOrder(502, $wk2 . ' 14:00:00', [['id' => 401, 'price' => 500, 'quantity' => 1]], 500);
        $mkOrder(502, $today . ' 20:00:00', [['id' => 403, 'price' => 120, 'quantity' => 3]], 360);
        $mkOrder(503, $wk1 . ' 18:00:00', [['id' => 402, 'price' => 300, 'quantity' => 2]], 600);
        // Refused — must be excluded from margins.
        $mkOrder(503, $wk1 . ' 21:00:00', [['id' => 401, 'price' => 500, 'quantity' => 1]], 500, 'отказ');

        require_once __DIR__ . '/../db.php';
        $this->db = $this->makeDatabaseWithPdo($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS orders');
            $this->pdo->exec('DROP TABLE IF EXISTS menu_items');
        }
        $this->db = null;
        $this->pdo = null;
    }

    public function test_dish_margins_excludes_refused_orders_and_computes_pct(): void
    {
        $from = date('Y-m-d 00:00:00', strtotime('-60 days'));
        $to   = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $rows = $this->db->getDishMargins($from, $to, 10);

        self::assertNotEmpty($rows);

        $byId = [];
        foreach ($rows as $r) { $byId[(int)$r['id']] = $r; }

        // Pizza: 2 + 1 + 1 = 4 units sold across non-refused orders.
        self::assertArrayHasKey(401, $byId);
        self::assertSame(4, (int)$byId[401]['units_sold']);
        // revenue = 2×500 + 1×500 + 1×500 = 2000
        self::assertSame(2000.0, (float)$byId[401]['revenue']);
        // cogs = 4 × 150 = 600; gross_margin = 1400
        self::assertSame(600.0, (float)$byId[401]['cogs']);
        self::assertSame(1400.0, (float)$byId[401]['gross_margin']);
        self::assertSame(70.0, (float)$byId[401]['gross_margin_pct']);
    }

    public function test_heatmap_returns_dense_7x24_grid(): void
    {
        $h = $this->db->getHourlyHeatmap(365);
        self::assertArrayHasKey('grid', $h);
        self::assertCount(7, $h['grid']);
        foreach ($h['grid'] as $row) {
            self::assertCount(24, $row);
        }
        self::assertGreaterThan(0, $h['max']);
    }

    public function test_cohorts_returns_structured_rows(): void
    {
        $c = $this->db->getCustomerCohorts(12);
        self::assertIsArray($c);
        // Three users placed orders → at most 3 cohort rows (likely 1 if all
        // first orders fell in the same month).
        if (!empty($c)) {
            self::assertArrayHasKey('cohort', $c[0]);
            self::assertArrayHasKey('size', $c[0]);
            self::assertArrayHasKey('retention', $c[0]);
            self::assertCount(13, $c[0]['retention'], 'Normalized to 13 columns (0..12).');
        }
    }

    public function test_forecast_returns_series_and_number(): void
    {
        $f = $this->db->forecastNextWeekRevenue(8);
        self::assertArrayHasKey('weekly', $f);
        self::assertArrayHasKey('forecast', $f);
        self::assertIsFloat($f['forecast']);
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
