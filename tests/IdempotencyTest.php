<?php

declare(strict_types=1);

namespace Cleanmenu\Tests;

use Cleanmenu\Tests\Fixtures\TestDatabase;
use Idempotency;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Covers lib/Idempotency.php — the shared idempotency store used by the order
 * creation flow and any other POST endpoint that accepts an Idempotency-Key
 * header.
 *
 * MySQL-coupled: set CLEANMENU_TEST_MYSQL_DSN to run this suite. Without it,
 * every test here is skipped with a clear reason so the pure-PHP lifecycle
 * suite stays green on vanilla hosts.
 */
final class IdempotencyTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $skip = TestDatabase::skipReason();
        if ($skip !== null) {
            self::markTestSkipped($skip);
        }

        $this->pdo = TestDatabase::requirePdo();
        TestDatabase::resetManagedTables($this->pdo);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
        $this->pdo = null;
    }

    public function test_hash_payload_is_deterministic_for_same_input(): void
    {
        $payload = ['items' => [['id' => 1, 'qty' => 2]], 'total' => 500];

        self::assertSame(
            Idempotency::hashPayload($payload),
            Idempotency::hashPayload($payload)
        );
    }

    public function test_hash_payload_differs_for_different_input(): void
    {
        $a = ['items' => [['id' => 1, 'qty' => 2]], 'total' => 500];
        $b = ['items' => [['id' => 1, 'qty' => 3]], 'total' => 500];

        self::assertNotSame(
            Idempotency::hashPayload($a),
            Idempotency::hashPayload($b)
        );
    }

    public function test_hash_payload_is_sha256_hex_string(): void
    {
        $hash = Idempotency::hashPayload(['k' => 'v']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function test_find_returns_null_for_unknown_key(): void
    {
        $result = Idempotency::find(
            $this->pdo,
            'orders.create',
            'missing-key',
            Idempotency::hashPayload(['x' => 1])
        );

        self::assertNull($result);
    }

    public function test_store_then_find_replays_original_response(): void
    {
        $payload = ['items' => [['id' => 7, 'qty' => 1]], 'total' => 990];
        $hash = Idempotency::hashPayload($payload);
        $response = ['success' => true, 'order_id' => 42];

        Idempotency::store($this->pdo, 'orders.create', 'abc-123', $hash, $response);

        $replay = Idempotency::find($this->pdo, 'orders.create', 'abc-123', $hash);

        self::assertIsArray($replay);
        self::assertFalse($replay['conflict']);
        self::assertSame($response, $replay['response']);
    }

    public function test_same_key_different_payload_is_a_conflict(): void
    {
        $firstHash  = Idempotency::hashPayload(['items' => [['id' => 1, 'qty' => 1]]]);
        $secondHash = Idempotency::hashPayload(['items' => [['id' => 2, 'qty' => 5]]]);

        Idempotency::store(
            $this->pdo,
            'orders.create',
            'same-key',
            $firstHash,
            ['success' => true, 'order_id' => 1]
        );

        $result = Idempotency::find($this->pdo, 'orders.create', 'same-key', $secondHash);

        self::assertIsArray($result);
        self::assertTrue($result['conflict']);
        self::assertNull($result['response']);
    }

    public function test_different_scopes_do_not_collide(): void
    {
        $hash = Idempotency::hashPayload(['x' => 1]);

        Idempotency::store($this->pdo, 'orders.create', 'shared-key', $hash, ['scope' => 'orders']);
        Idempotency::store($this->pdo, 'payments.create', 'shared-key', $hash, ['scope' => 'payments']);

        $orders   = Idempotency::find($this->pdo, 'orders.create', 'shared-key', $hash);
        $payments = Idempotency::find($this->pdo, 'payments.create', 'shared-key', $hash);

        self::assertSame(['scope' => 'orders'],   $orders['response']);
        self::assertSame(['scope' => 'payments'], $payments['response']);
    }

    public function test_store_upserts_on_repeated_key_and_same_hash(): void
    {
        $hash = Idempotency::hashPayload(['x' => 1]);

        Idempotency::store($this->pdo, 'orders.create', 'repeat-key', $hash, ['attempt' => 1]);
        Idempotency::store($this->pdo, 'orders.create', 'repeat-key', $hash, ['attempt' => 2]);

        $count = (int)$this->pdo
            ->query("SELECT COUNT(*) FROM api_idempotency_keys WHERE idempotency_key = 'repeat-key'")
            ->fetchColumn();
        self::assertSame(1, $count, 'Repeated store must upsert, not insert a duplicate row.');

        $replay = Idempotency::find($this->pdo, 'orders.create', 'repeat-key', $hash);
        self::assertSame(['attempt' => 2], $replay['response']);
    }

    public function test_find_cleans_up_expired_entries(): void
    {
        $hash = Idempotency::hashPayload(['x' => 1]);
        Idempotency::store($this->pdo, 'orders.create', 'expired-key', $hash, ['ok' => true]);

        // Force-expire the row in place.
        $this->pdo->exec(
            "UPDATE api_idempotency_keys
             SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
             WHERE idempotency_key = 'expired-key'"
        );

        // find() runs the cleanup DELETE and should miss the row.
        $result = Idempotency::find($this->pdo, 'orders.create', 'expired-key', $hash);
        self::assertNull($result);

        $leftover = (int)$this->pdo
            ->query("SELECT COUNT(*) FROM api_idempotency_keys WHERE idempotency_key = 'expired-key'")
            ->fetchColumn();
        self::assertSame(0, $leftover, 'Expired rows must be deleted by find().');
    }

    public function test_get_header_key_reads_server_header(): void
    {
        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'header-value-xyz';
        self::assertSame('header-value-xyz', Idempotency::getHeaderKey());
    }

    public function test_get_header_key_trims_whitespace(): void
    {
        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = "  padded-key\n";
        self::assertSame('padded-key', Idempotency::getHeaderKey());
    }

    public function test_get_header_key_returns_null_when_missing_or_empty(): void
    {
        unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
        self::assertNull(Idempotency::getHeaderKey());

        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = '   ';
        self::assertNull(Idempotency::getHeaderKey());
    }

    public function test_get_header_key_truncates_at_128_chars(): void
    {
        $long = str_repeat('a', 200);
        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = $long;

        $result = Idempotency::getHeaderKey();
        self::assertIsString($result);
        self::assertSame(128, strlen($result));
        self::assertSame(str_repeat('a', 128), $result);
    }
}
