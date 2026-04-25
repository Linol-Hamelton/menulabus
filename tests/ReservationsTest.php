<?php

declare(strict_types=1);

namespace Cleanmenu\Tests;

use Cleanmenu\Tests\Fixtures\TestDatabase;
use Database;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers the reservations DB methods added in db.php against a real MySQL
 * schema (sql/reservations-migration.sql).
 *
 * MySQL-coupled — skipped gracefully when CLEANMENU_TEST_MYSQL_DSN is unset,
 * matching the convention used by CreateOrderTest / IdempotencyTest.
 *
 * Critical invariants this suite locks down:
 *   - createReservation rejects overlapping slots on the same table.
 *   - checkTableAvailable ignores cancelled / no_show rows (a cancelled
 *     booking must not block a later attempt for the same window).
 *   - updateReservationStatus stamps confirmed_at when transitioning to
 *     'confirmed', so the admin "confirmed N minutes ago" UI has data.
 *   - Guest reservations (user_id IS NULL) are first-class.
 */
final class ReservationsTest extends TestCase
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
                . 'ReservationsTest requires db.php -> tenant_runtime.php -> config_copy.php to be loadable.'
            );
        }

        $this->pdo = TestDatabase::requirePdo();

        $this->pdo->exec('DROP TABLE IF EXISTS reservations');
        $this->pdo->exec("
            CREATE TABLE reservations (
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                table_label     VARCHAR(64) NOT NULL,
                user_id         INT DEFAULT NULL,
                guest_name      VARCHAR(255) DEFAULT NULL,
                guest_phone     VARCHAR(32) DEFAULT NULL,
                guests_count    TINYINT UNSIGNED NOT NULL,
                starts_at       DATETIME NOT NULL,
                ends_at         DATETIME NOT NULL,
                status          VARCHAR(32) NOT NULL DEFAULT 'pending',
                note            TEXT DEFAULT NULL,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                confirmed_at    DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_reservations_table_time (table_label, starts_at, ends_at),
                KEY idx_reservations_status_time (status, starts_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        require_once __DIR__ . '/../db.php';
        $this->db = $this->makeDatabaseWithPdo($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS reservations');
        }
        $this->db  = null;
        $this->pdo = null;
    }

    public function test_create_reservation_returns_id_and_persists_row(): void
    {
        $id = $this->db->createReservation(
            'T1', 42, null, null, 4,
            '2099-01-01 19:00:00', '2099-01-01 21:00:00',
            'window seat please'
        );

        self::assertIsInt($id);
        self::assertGreaterThan(0, $id);

        $row = $this->db->getReservationById($id);
        self::assertIsArray($row);
        self::assertSame('T1',      $row['table_label']);
        self::assertSame(42,        (int)$row['user_id']);
        self::assertSame(4,         (int)$row['guests_count']);
        self::assertSame('pending', $row['status']);
        self::assertSame('window seat please', $row['note']);
        self::assertNull($row['confirmed_at']);
    }

    public function test_create_reservation_supports_guest_without_user_id(): void
    {
        $id = $this->db->createReservation(
            'T2', null, 'Иван', '+70000000000', 2,
            '2099-01-02 18:00:00', '2099-01-02 20:00:00',
            null
        );

        self::assertIsInt($id);
        $row = $this->db->getReservationById($id);
        self::assertNull($row['user_id']);
        self::assertSame('Иван', $row['guest_name']);
        self::assertSame('+70000000000', $row['guest_phone']);
    }

    public function test_overlapping_reservation_on_same_table_is_rejected(): void
    {
        $first = $this->db->createReservation(
            'T3', 1, null, null, 2,
            '2099-02-01 12:00:00', '2099-02-01 14:00:00',
            null
        );
        self::assertIsInt($first);

        $conflict = $this->db->createReservation(
            'T3', 2, null, null, 2,
            '2099-02-01 13:00:00', '2099-02-01 15:00:00',
            null
        );
        self::assertNull($conflict, 'Overlapping reservations on the same table must be refused.');
    }

    public function test_back_to_back_reservations_on_same_table_are_allowed(): void
    {
        $a = $this->db->createReservation(
            'T4', 1, null, null, 2,
            '2099-02-02 12:00:00', '2099-02-02 14:00:00',
            null
        );
        $b = $this->db->createReservation(
            'T4', 2, null, null, 2,
            '2099-02-02 14:00:00', '2099-02-02 16:00:00',
            null
        );
        self::assertIsInt($a);
        self::assertIsInt($b, 'A reservation that begins exactly when the previous one ends must be allowed.');
    }

    public function test_overlap_on_different_tables_is_allowed(): void
    {
        $a = $this->db->createReservation(
            'T5', 1, null, null, 2,
            '2099-02-03 12:00:00', '2099-02-03 14:00:00',
            null
        );
        $b = $this->db->createReservation(
            'T6', 2, null, null, 2,
            '2099-02-03 12:30:00', '2099-02-03 13:30:00',
            null
        );
        self::assertIsInt($a);
        self::assertIsInt($b);
    }

    public function test_cancelled_reservation_does_not_block_new_booking(): void
    {
        $a = $this->db->createReservation(
            'T7', 1, null, null, 2,
            '2099-02-04 12:00:00', '2099-02-04 14:00:00',
            null
        );
        self::assertTrue($this->db->updateReservationStatus($a, 'cancelled'));

        $b = $this->db->createReservation(
            'T7', 2, null, null, 2,
            '2099-02-04 12:00:00', '2099-02-04 14:00:00',
            null
        );
        self::assertIsInt($b, 'A cancelled slot must be free for re-booking.');
    }

    public function test_confirm_status_stamps_confirmed_at(): void
    {
        $id = $this->db->createReservation(
            'T8', 1, null, null, 2,
            '2099-02-05 12:00:00', '2099-02-05 14:00:00',
            null
        );
        self::assertTrue($this->db->updateReservationStatus($id, 'confirmed'));

        $row = $this->db->getReservationById($id);
        self::assertSame('confirmed', $row['status']);
        self::assertNotNull($row['confirmed_at'], 'confirmed_at must be set when transitioning to confirmed.');
    }

    public function test_update_rejects_unknown_status(): void
    {
        $id = $this->db->createReservation(
            'T9', 1, null, null, 2,
            '2099-02-06 12:00:00', '2099-02-06 14:00:00',
            null
        );
        self::assertFalse($this->db->updateReservationStatus($id, 'in_progress'));
    }

    public function test_create_rejects_inverted_window(): void
    {
        $id = $this->db->createReservation(
            'T10', 1, null, null, 2,
            '2099-02-07 14:00:00', '2099-02-07 12:00:00',
            null
        );
        self::assertNull($id, 'ends_at <= starts_at must be refused.');
    }

    public function test_get_range_returns_only_starts_in_window(): void
    {
        $inside = $this->db->createReservation(
            'T11', 1, null, null, 2,
            '2099-03-01 19:00:00', '2099-03-01 21:00:00',
            null
        );
        $outside = $this->db->createReservation(
            'T11', 2, null, null, 2,
            '2099-03-02 19:00:00', '2099-03-02 21:00:00',
            null
        );

        $rows = $this->db->getReservationsByRange('2099-03-01 00:00:00', '2099-03-02 00:00:00');
        $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
        self::assertContains($inside, $ids);
        self::assertNotContains($outside, $ids);
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
