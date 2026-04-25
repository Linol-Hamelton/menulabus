<?php

declare(strict_types=1);

namespace Cleanmenu\Tests;

use Cleanmenu\Tests\Fixtures\TestDatabase;
use Database;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers Database::createOrder() end-to-end against a real MySQL schema.
 *
 * Database is a singleton with a private constructor that calls
 * tenant_runtime_require_resolved(), so this test bypasses the constructor
 * via ReflectionClass::newInstanceWithoutConstructor() and injects a PDO
 * directly. That keeps production code untouched while still letting us
 * exercise the actual SQL.
 *
 * MySQL-coupled — skipped gracefully when CLEANMENU_TEST_MYSQL_DSN is unset.
 */
final class CreateOrderTest extends TestCase
{
    private ?PDO $pdo = null;
    private ?Database $db = null;

    protected function setUp(): void
    {
        $skip = TestDatabase::skipReason();
        if ($skip !== null) {
            self::markTestSkipped($skip);
        }

        // db.php unconditionally require_once's tenant_runtime.php, which in
        // turn unconditionally require_once's ../config_copy.php. If that
        // file is missing (fresh clone, CI without host config), PHP raises
        // an uncatchable fatal at file-load time. Guard the test with an
        // explicit skip so the failure mode is "skipped with reason" instead
        // of "whole PHPUnit process crashes."
        $parentConfig = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config_copy.php';
        if (!is_file($parentConfig)) {
            self::markTestSkipped(
                "config_copy.php not found at {$parentConfig}; "
                . 'CreateOrderTest requires db.php -> tenant_runtime.php -> config_copy.php to be loadable. '
                . 'Place a stub with DB_HOST / DB_NAME / DB_USER / DB_PASS constants there, or run only the lifecycle suite.'
            );
        }

        $this->pdo = TestDatabase::requirePdo();
        TestDatabase::resetManagedTables($this->pdo);

        // Lazy-load db.php only when we actually need it, so pure-PHP suites
        // do not get dragged into the tenant_runtime include chain.
        require_once __DIR__ . '/../db.php';

        $this->db = $this->makeDatabaseWithPdo($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->db  = null;
        $this->pdo = null;
    }

    public function test_create_order_inserts_row_with_initial_status(): void
    {
        $items = [
            ['id' => 10, 'name' => 'Маргарита', 'quantity' => 2, 'price' => 550.0],
        ];

        $orderId = $this->db->createOrder(
            null,
            $items,
            1100.00,
            'bar',
            'Стол 3',
            0.0,
            'cash',
            'not_required'
        );

        self::assertIsNumeric($orderId);
        self::assertGreaterThan(0, (int)$orderId);

        $row = $this->fetchOrder((int)$orderId);
        self::assertSame('Приём',       $row['status']);
        self::assertSame('bar',         $row['delivery_type']);
        self::assertSame('Стол 3',      $row['delivery_details']);
        self::assertSame('cash',        $row['payment_method']);
        self::assertSame('not_required', $row['payment_status']);
        self::assertSame('1100.00',     $row['total']);
        self::assertSame('0.00',        $row['tips']);

        $decoded = json_decode((string)$row['items'], true);
        self::assertIsArray($decoded);
        self::assertCount(1, $decoded);
        self::assertSame('Маргарита', $decoded[0]['name']);
        self::assertSame(2,           (int)$decoded[0]['quantity']);
    }

    public function test_create_order_persists_tips_separately(): void
    {
        $orderId = $this->db->createOrder(
            null,
            [['id' => 1, 'name' => 'Кола', 'quantity' => 1, 'price' => 200.0]],
            200.00,
            'delivery',
            'ул. Ленина 1',
            50.00,
            'online',
            'pending'
        );

        $row = $this->fetchOrder((int)$orderId);
        self::assertSame('200.00', $row['total'], 'total must not include tips');
        self::assertSame('50.00',  $row['tips'],  'tips stored in separate column');
        self::assertSame('online', $row['payment_method']);
        self::assertSame('pending', $row['payment_status']);
    }

    public function test_create_order_clamps_negative_tips_to_zero(): void
    {
        $orderId = $this->db->createOrder(
            null,
            [['id' => 1, 'name' => 'Чай', 'quantity' => 1, 'price' => 100.0]],
            100.00,
            'bar',
            '',
            -25.00
        );

        $row = $this->fetchOrder((int)$orderId);
        self::assertSame('0.00', $row['tips'], 'negative tips must be clamped to 0');
    }

    public function test_create_order_writes_order_items_rows(): void
    {
        $items = [
            ['id' => 10, 'name' => 'Маргарита',  'quantity' => 2, 'price' => 550.0],
            ['id' => 22, 'name' => 'Пепперони',  'quantity' => 1, 'price' => 640.0],
        ];

        $orderId = (int)$this->db->createOrder(null, $items, 1740.00, 'bar', '');

        $rows = $this->pdo
            ->query("SELECT item_id, item_name, quantity, price FROM order_items WHERE order_id = {$orderId} ORDER BY item_id ASC")
            ->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(2, $rows);
        self::assertSame(10,           (int)$rows[0]['item_id']);
        self::assertSame('Маргарита',  $rows[0]['item_name']);
        self::assertSame(2,            (int)$rows[0]['quantity']);
        self::assertSame('550.00',     $rows[0]['price']);
        self::assertSame(22,           (int)$rows[1]['item_id']);
        self::assertSame('Пепперони',  $rows[1]['item_name']);
        self::assertSame(1,            (int)$rows[1]['quantity']);
        self::assertSame('640.00',     $rows[1]['price']);
    }

    public function test_create_order_writes_initial_status_history_entry(): void
    {
        $orderId = (int)$this->db->createOrder(
            null,
            [['id' => 1, 'name' => 'Чай', 'quantity' => 1, 'price' => 100.0]],
            100.00,
            'bar',
            ''
        );

        $rows = $this->pdo
            ->query("SELECT status, changed_by FROM order_status_history WHERE order_id = {$orderId}")
            ->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(1, $rows, 'Exactly one history row is written at creation time.');
        self::assertSame('Приём', $rows[0]['status']);
        self::assertNull($rows[0]['changed_by']);
    }

    public function test_create_order_skips_order_items_with_invalid_ids(): void
    {
        $items = [
            ['id' => 0,  'name' => 'Ghost',    'quantity' => 1, 'price' => 10.0], // skipped: id <= 0
            ['id' => 7,  'name' => 'Real',     'quantity' => 1, 'price' => 50.0],
        ];

        $orderId = (int)$this->db->createOrder(null, $items, 60.00, 'bar', '');

        $rows = $this->pdo
            ->query("SELECT item_id FROM order_items WHERE order_id = {$orderId}")
            ->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(1, $rows, 'Items with id <= 0 must be dropped before insert.');
        self::assertSame(7, (int)$rows[0]['item_id']);
    }

    public function test_create_order_rolls_back_on_insert_failure(): void
    {
        // Force failure by passing a total that violates the DECIMAL(10,2) range.
        // 10^10 > max value, so MySQL raises and the transaction must roll back
        // leaving zero orders rows.
        $result = $this->db->createOrder(
            null,
            [['id' => 1, 'name' => 'X', 'quantity' => 1, 'price' => 1.0]],
            99999999999.99,
            'bar',
            ''
        );

        self::assertFalse($result, 'createOrder returns false on transaction failure.');

        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        self::assertSame(0, $count, 'Failed createOrder must leave the orders table empty.');

        $historyCount = (int)$this->pdo->query('SELECT COUNT(*) FROM order_status_history')->fetchColumn();
        self::assertSame(0, $historyCount, 'Rollback must also remove the history row.');
    }

    /**
     * Build a Database instance with its private constructor bypassed so the
     * singleton does not try to resolve a tenant context. We inject the test
     * PDO directly into the private $connection slot.
     */
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

    /**
     * @return array<string, mixed>
     */
    private function fetchOrder(int $orderId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id');
        $stmt->execute([':id' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Expected orders row with id={$orderId}.");
        return $row;
    }
}
