<?php

declare(strict_types=1);

namespace Cleanmenu\Tests;

use Cleanmenu\Tests\Fixtures\TestDatabase;
use Database;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers the Kitchen Display System DB layer (Phase 6.1) against a real
 * MySQL schema. Mirrors the MySQL-gated pattern used by ReservationsTest /
 * CreateOrderTest — safely skips when CLEANMENU_TEST_MYSQL_DSN is unset.
 *
 * Invariants locked down here:
 *   - routeOrderItemsToStations writes one row per (slot × station), plus a
 *     single NULL-station row for items whose menu row has no mapping.
 *   - advanceKdsItemStatus enforces the queued → cooking → ready machine and
 *     stamps started_at / ready_at exactly once.
 *   - isOrderFullyReady returns true only when every non-cancelled row for
 *     the order is in 'ready'.
 *   - cancelled rows do not block isOrderFullyReady.
 */
final class KdsTest extends TestCase
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
                . 'KdsTest requires db.php -> tenant_runtime.php -> config_copy.php to be loadable.'
            );
        }

        $this->pdo = TestDatabase::requirePdo();

        // Create a minimal menu_items shell so FK constraints from
        // menu_item_stations can bind. Uses existing pattern of one-shot
        // CREATE TABLE IF NOT EXISTS; TestDatabase does not manage these.
        $this->pdo->exec('DROP TABLE IF EXISTS order_item_status');
        $this->pdo->exec('DROP TABLE IF EXISTS menu_item_stations');
        $this->pdo->exec('DROP TABLE IF EXISTS kitchen_stations');
        $this->pdo->exec('DROP TABLE IF EXISTS menu_items_kds_stub');

        $this->pdo->exec("
            CREATE TABLE menu_items_kds_stub (
                id INT NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // Insert three dishes to reference from tests.
        $this->pdo->exec("INSERT INTO menu_items_kds_stub (id, name) VALUES (101, 'Pizza'), (102, 'Salad'), (103, 'Mystery')");

        $this->pdo->exec("
            CREATE TABLE kitchen_stations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                label VARCHAR(64) NOT NULL,
                slug VARCHAR(32) NOT NULL,
                active TINYINT UNSIGNED NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_kitchen_stations_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Point the FK at the stub table so both real menu_items schema and
        // this test fixture accept the same DDL without a branch.
        $this->pdo->exec("
            CREATE TABLE menu_item_stations (
                menu_item_id INT NOT NULL,
                station_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (menu_item_id, station_id),
                CONSTRAINT fk_mis_item FOREIGN KEY (menu_item_id) REFERENCES menu_items_kds_stub(id) ON DELETE CASCADE,
                CONSTRAINT fk_mis_station FOREIGN KEY (station_id) REFERENCES kitchen_stations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo->exec("
            CREATE TABLE order_item_status (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id INT NOT NULL,
                item_index SMALLINT UNSIGNED NOT NULL,
                menu_item_id INT DEFAULT NULL,
                item_name VARCHAR(255) DEFAULT NULL,
                quantity INT NOT NULL DEFAULT 1,
                station_id INT UNSIGNED DEFAULT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'queued',
                started_at DATETIME DEFAULT NULL,
                ready_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                CONSTRAINT chk_ois_status CHECK (status IN ('queued','cooking','ready','cancelled'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        require_once __DIR__ . '/../db.php';
        $this->db = $this->makeDatabaseWithPdo($this->pdo);

        // Patch getMenuItemStations() against the stub: Database uses
        // prepareCached() under tenant scope, and our simplified schema does
        // not care about FK to real menu_items, so joins work natively.
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS order_item_status');
            $this->pdo->exec('DROP TABLE IF EXISTS menu_item_stations');
            $this->pdo->exec('DROP TABLE IF EXISTS kitchen_stations');
            $this->pdo->exec('DROP TABLE IF EXISTS menu_items_kds_stub');
        }
        $this->db = null;
        $this->pdo = null;
    }

    public function test_save_station_validates_slug_format(): void
    {
        self::assertNotNull($this->db->saveKitchenStation(null, 'Горячий цех', 'hot', true, 0));
        self::assertNull($this->db->saveKitchenStation(null, 'Бар', '', true, 0), 'Empty slug must be refused.');
        self::assertNull($this->db->saveKitchenStation(null, 'Бар', 'BAR WITH SPACES', true, 0), 'Slug with spaces must be refused.');
        self::assertNull($this->db->saveKitchenStation(null, '', 'cold', true, 0), 'Empty label must be refused.');
    }

    public function test_routing_writes_rows_per_slot_and_station(): void
    {
        $hotId  = $this->db->saveKitchenStation(null, 'Горячий', 'hot',  true, 0);
        $coldId = $this->db->saveKitchenStation(null, 'Холодный', 'cold', true, 1);
        $this->db->setMenuItemStations(101, [$hotId]);
        $this->db->setMenuItemStations(102, [$coldId]);
        // 103 has no mapping → should land in the unrouted queue.

        // Use a synthetic order_id; FK to orders is checked in prod DDL but
        // our fixture table has no FK on order_id, which keeps tests cheap.
        $written = $this->db->routeOrderItemsToStations(5001, [
            ['id' => 101, 'name' => 'Pizza',   'quantity' => 2],
            ['id' => 102, 'name' => 'Salad',   'quantity' => 1],
            ['id' => 103, 'name' => 'Mystery', 'quantity' => 1],
        ]);

        self::assertSame(3, $written, 'Two routed slots + one unrouted slot.');

        $hotBoard   = $this->db->getKdsBoardForStation($hotId);
        $coldBoard  = $this->db->getKdsBoardForStation($coldId);
        $unrouted   = $this->db->getKdsBoardForStation(null);

        // The board join requires a matching orders row; without it the joins
        // return empty. Verify at the raw-SQL layer instead.
        $rows = $this->pdo->query("SELECT station_id, item_index, item_name, quantity, status FROM order_item_status ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(3, $rows);
        self::assertSame((string)$hotId,  (string)$rows[0]['station_id']);
        self::assertSame('Pizza',   $rows[0]['item_name']);
        self::assertSame(2,         (int)$rows[0]['quantity']);
        self::assertSame((string)$coldId, (string)$rows[1]['station_id']);
        self::assertNull($rows[2]['station_id'], 'Items without station mapping must have NULL station_id.');
    }

    public function test_routing_is_idempotent_on_retry(): void
    {
        $hotId = $this->db->saveKitchenStation(null, 'Горячий', 'hot', true, 0);
        $this->db->setMenuItemStations(101, [$hotId]);
        $items = [['id' => 101, 'name' => 'Pizza', 'quantity' => 1]];

        self::assertSame(1, $this->db->routeOrderItemsToStations(6001, $items));
        self::assertSame(0, $this->db->routeOrderItemsToStations(6001, $items), 'Second call must be a no-op.');

        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM order_item_status WHERE order_id = 6001")->fetchColumn();
        self::assertSame(1, $count);
    }

    public function test_advance_status_transitions_and_stamps_timestamps(): void
    {
        $hotId = $this->db->saveKitchenStation(null, 'Горячий', 'hot', true, 0);
        $this->db->setMenuItemStations(101, [$hotId]);
        $this->db->routeOrderItemsToStations(7001, [['id' => 101, 'name' => 'Pizza', 'quantity' => 1]]);

        $rowId = (int)$this->pdo->query("SELECT id FROM order_item_status WHERE order_id = 7001")->fetchColumn();

        self::assertTrue($this->db->advanceKdsItemStatus($rowId, 'cooking'));
        $row = $this->pdo->query("SELECT status, started_at, ready_at FROM order_item_status WHERE id = {$rowId}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('cooking', $row['status']);
        self::assertNotNull($row['started_at']);
        self::assertNull($row['ready_at']);

        self::assertFalse($this->db->advanceKdsItemStatus($rowId, 'cooking'), 'Same-status transition must return false.');

        self::assertTrue($this->db->advanceKdsItemStatus($rowId, 'ready'));
        $row = $this->pdo->query("SELECT status, started_at, ready_at FROM order_item_status WHERE id = {$rowId}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('ready', $row['status']);
        self::assertNotNull($row['ready_at']);
    }

    public function test_advance_rejects_unknown_status(): void
    {
        $hotId = $this->db->saveKitchenStation(null, 'Горячий', 'hot', true, 0);
        $this->db->setMenuItemStations(101, [$hotId]);
        $this->db->routeOrderItemsToStations(7101, [['id' => 101, 'name' => 'Pizza', 'quantity' => 1]]);
        $rowId = (int)$this->pdo->query("SELECT id FROM order_item_status WHERE order_id = 7101")->fetchColumn();

        self::assertFalse($this->db->advanceKdsItemStatus($rowId, 'plating'));
        self::assertFalse($this->db->advanceKdsItemStatus($rowId, 'done'));
    }

    public function test_order_fully_ready_requires_all_non_cancelled_slots_ready(): void
    {
        $hotId  = $this->db->saveKitchenStation(null, 'Горячий', 'hot',  true, 0);
        $coldId = $this->db->saveKitchenStation(null, 'Холодный', 'cold', true, 1);
        $this->db->setMenuItemStations(101, [$hotId]);
        $this->db->setMenuItemStations(102, [$coldId]);
        $this->db->routeOrderItemsToStations(8001, [
            ['id' => 101, 'name' => 'Pizza', 'quantity' => 1],
            ['id' => 102, 'name' => 'Salad', 'quantity' => 1],
        ]);

        $rows = $this->pdo->query("SELECT id, station_id FROM order_item_status WHERE order_id = 8001 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $rows);

        $this->db->advanceKdsItemStatus((int)$rows[0]['id'], 'ready');
        self::assertFalse($this->db->isOrderFullyReady(8001), 'One of two slots still cooking; order must not be fully ready.');

        $this->db->advanceKdsItemStatus((int)$rows[1]['id'], 'ready');
        self::assertTrue($this->db->isOrderFullyReady(8001));
    }

    public function test_cancelled_slot_does_not_block_order_fully_ready(): void
    {
        $hotId  = $this->db->saveKitchenStation(null, 'Горячий', 'hot',  true, 0);
        $coldId = $this->db->saveKitchenStation(null, 'Холодный', 'cold', true, 1);
        $this->db->setMenuItemStations(101, [$hotId]);
        $this->db->setMenuItemStations(102, [$coldId]);
        $this->db->routeOrderItemsToStations(8501, [
            ['id' => 101, 'name' => 'Pizza', 'quantity' => 1],
            ['id' => 102, 'name' => 'Salad', 'quantity' => 1],
        ]);
        $rows = $this->pdo->query("SELECT id FROM order_item_status WHERE order_id = 8501 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

        $this->db->advanceKdsItemStatus((int)$rows[0]['id'], 'ready');
        $this->db->advanceKdsItemStatus((int)$rows[1]['id'], 'cancelled');
        self::assertTrue($this->db->isOrderFullyReady(8501), 'Cancelled slots are excluded from the ready check.');
    }

    public function test_set_menu_item_stations_replaces_prior_assignments(): void
    {
        $hotId  = $this->db->saveKitchenStation(null, 'Горячий', 'hot', true, 0);
        $coldId = $this->db->saveKitchenStation(null, 'Холодный', 'cold', true, 1);
        $barId  = $this->db->saveKitchenStation(null, 'Бар', 'bar', true, 2);

        $this->db->setMenuItemStations(101, [$hotId, $coldId]);
        $this->db->setMenuItemStations(101, [$barId]);

        $current = $this->db->getMenuItemStations(101);
        self::assertCount(1, $current);
        self::assertSame((int)$barId, (int)$current[0]['id']);
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
