<?php

declare(strict_types=1);

namespace Cleanmenu\Tests;

use Cleanmenu\Tests\Fixtures\TestDatabase;
use Database;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers the Loyalty DB layer (Phase 6.3) against a real MySQL schema.
 * MySQL-gated — skipped when CLEANMENU_TEST_MYSQL_DSN is unset.
 *
 * Invariants locked here:
 *   - resolveTierForSpent picks the highest tier whose min_spent ≤ spent.
 *   - accrueLoyaltyPoints is idempotent per (user_id, order_id).
 *   - redeem blocks on insufficient balance, writes ledger row on success.
 *   - evaluatePromoCode rejects empty, unknown, expired, limit-reached codes.
 *   - evaluatePromoCode computes pct and amount discount correctly and clamps
 *     discount to the order total.
 *   - incrementPromoCodeUsage blocks once limit is reached.
 */
final class LoyaltyTest extends TestCase
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
                . 'LoyaltyTest requires db.php -> tenant_runtime.php -> config_copy.php to be loadable.'
            );
        }

        $this->pdo = TestDatabase::requirePdo();

        $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $this->pdo->exec('DROP TABLE IF EXISTS loyalty_transactions');
        $this->pdo->exec('DROP TABLE IF EXISTS loyalty_accounts');
        $this->pdo->exec('DROP TABLE IF EXISTS loyalty_tiers');
        $this->pdo->exec('DROP TABLE IF EXISTS promo_codes');
        $this->pdo->exec('DROP TABLE IF EXISTS users_loy_stub');
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        $this->pdo->exec("
            CREATE TABLE users_loy_stub (
                id INT NOT NULL AUTO_INCREMENT,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $this->pdo->exec("INSERT INTO users_loy_stub (id) VALUES (301), (302), (303)");

        $this->pdo->exec("
            CREATE TABLE loyalty_tiers (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(64) NOT NULL,
                min_spent DECIMAL(12,2) NOT NULL DEFAULT 0,
                cashback_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                archived_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo->exec("
            CREATE TABLE loyalty_accounts (
                user_id INT NOT NULL,
                points_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
                total_spent DECIMAL(12,2) NOT NULL DEFAULT 0,
                tier_id INT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id),
                CONSTRAINT fk_la_user FOREIGN KEY (user_id) REFERENCES users_loy_stub(id) ON DELETE CASCADE,
                CONSTRAINT fk_la_tier FOREIGN KEY (tier_id) REFERENCES loyalty_tiers(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo->exec("
            CREATE TABLE loyalty_transactions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                points_delta DECIMAL(12,2) NOT NULL,
                reason VARCHAR(32) NOT NULL,
                order_id INT DEFAULT NULL,
                note VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                CONSTRAINT chk_lt_reason CHECK (reason IN ('accrual','redeem','manual','expire','birthday','refund')),
                CONSTRAINT fk_lt_user FOREIGN KEY (user_id) REFERENCES users_loy_stub(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo->exec("
            CREATE TABLE promo_codes (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(64) NOT NULL,
                discount_pct DECIMAL(5,2) DEFAULT NULL,
                discount_amount DECIMAL(12,2) DEFAULT NULL,
                min_order_total DECIMAL(12,2) NOT NULL DEFAULT 0,
                valid_from DATETIME DEFAULT NULL,
                valid_to DATETIME DEFAULT NULL,
                usage_limit INT UNSIGNED NOT NULL DEFAULT 0,
                used_count INT UNSIGNED NOT NULL DEFAULT 0,
                description VARCHAR(255) DEFAULT NULL,
                archived_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_pc_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        require_once __DIR__ . '/../db.php';
        $this->db = $this->makeDatabaseWithPdo($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $this->pdo->exec('DROP TABLE IF EXISTS loyalty_transactions');
            $this->pdo->exec('DROP TABLE IF EXISTS loyalty_accounts');
            $this->pdo->exec('DROP TABLE IF EXISTS loyalty_tiers');
            $this->pdo->exec('DROP TABLE IF EXISTS promo_codes');
            $this->pdo->exec('DROP TABLE IF EXISTS users_loy_stub');
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        }
        $this->db = null;
        $this->pdo = null;
    }

    public function test_resolve_tier_picks_highest_min_spent_at_or_below(): void
    {
        $bronze = $this->db->saveLoyaltyTier(null, 'Bronze', 0,    1.0, 0);
        $silver = $this->db->saveLoyaltyTier(null, 'Silver', 1000, 3.0, 1);
        $gold   = $this->db->saveLoyaltyTier(null, 'Gold',   5000, 5.0, 2);

        self::assertSame($bronze, (int)$this->db->resolveTierForSpent(0)['id']);
        self::assertSame($bronze, (int)$this->db->resolveTierForSpent(999.99)['id']);
        self::assertSame($silver, (int)$this->db->resolveTierForSpent(1000)['id']);
        self::assertSame($silver, (int)$this->db->resolveTierForSpent(4999)['id']);
        self::assertSame($gold,   (int)$this->db->resolveTierForSpent(5000)['id']);
        self::assertSame($gold,   (int)$this->db->resolveTierForSpent(999999)['id']);
    }

    public function test_accrue_is_idempotent_per_order(): void
    {
        $this->db->saveLoyaltyTier(null, 'Bronze', 0, 5.0, 0);

        $pts1 = $this->db->accrueLoyaltyPoints(301, 4001, 1000.0);
        self::assertSame(50.0, $pts1, '5% of 1000 ₽ = 50 points.');

        $pts2 = $this->db->accrueLoyaltyPoints(301, 4001, 1000.0);
        self::assertSame(0.0, $pts2, 'Second accrual for the same order is a no-op.');

        $state = $this->db->getUserLoyaltyState(301);
        self::assertSame(50.0, (float)$state['points_balance']);
        self::assertSame(1000.0, (float)$state['total_spent']);
    }

    public function test_accrue_updates_tier_as_lifetime_spent_crosses_threshold(): void
    {
        $this->db->saveLoyaltyTier(null, 'Bronze', 0,    1.0, 0);
        $silver = $this->db->saveLoyaltyTier(null, 'Silver', 1000, 3.0, 1);

        $this->db->accrueLoyaltyPoints(302, 5001, 500.0); // still Bronze
        $this->db->accrueLoyaltyPoints(302, 5002, 600.0); // crosses 1000 → Silver

        $state = $this->db->getUserLoyaltyState(302);
        self::assertSame('Silver', $state['tier_name']);
        self::assertSame(3.0, (float)$state['tier_cashback_pct']);
    }

    public function test_redeem_blocks_when_insufficient(): void
    {
        $this->db->saveLoyaltyTier(null, 'Bronze', 0, 10.0, 0);
        $this->db->accrueLoyaltyPoints(303, 6001, 500.0); // +50 pts

        self::assertTrue($this->db->redeemLoyaltyPoints(303, 40.0));
        self::assertFalse($this->db->redeemLoyaltyPoints(303, 100.0), 'Cannot spend more than balance.');

        $state = $this->db->getUserLoyaltyState(303);
        self::assertSame(10.0, (float)$state['points_balance']);
    }

    public function test_evaluate_promo_rejects_empty_and_unknown(): void
    {
        $r1 = $this->db->evaluatePromoCode('', 1000);
        self::assertFalse($r1['ok']);
        self::assertSame('empty', $r1['error']);

        $r2 = $this->db->evaluatePromoCode('GHOSTCODE', 1000);
        self::assertFalse($r2['ok']);
        self::assertSame('not_found', $r2['error']);
    }

    public function test_evaluate_promo_enforces_min_total(): void
    {
        $this->db->savePromoCode(null, 'SUMMER', 10.0, null, 500.0, null, null, 0, null);
        $r = $this->db->evaluatePromoCode('SUMMER', 300);
        self::assertFalse($r['ok']);
        self::assertSame('below_min_total', $r['error']);
        self::assertSame(500.0, (float)$r['min']);
    }

    public function test_evaluate_promo_computes_pct_discount(): void
    {
        $this->db->savePromoCode(null, 'TEN', 10.0, null, 0.0, null, null, 0, null);
        $r = $this->db->evaluatePromoCode('TEN', 1000);
        self::assertTrue($r['ok']);
        self::assertSame(100.0, (float)$r['discount']);
        self::assertSame(900.0, (float)$r['new_total']);
    }

    public function test_evaluate_promo_clamps_fixed_amount_to_order_total(): void
    {
        $this->db->savePromoCode(null, 'BIGAMT', null, 500.0, 0.0, null, null, 0, null);
        $r = $this->db->evaluatePromoCode('BIGAMT', 200);
        self::assertTrue($r['ok']);
        self::assertSame(200.0, (float)$r['discount'], 'Discount cannot exceed order total.');
        self::assertSame(0.0, (float)$r['new_total']);
    }

    public function test_save_promo_rejects_both_pct_and_amount(): void
    {
        $id = $this->db->savePromoCode(null, 'BAD', 10.0, 100.0, 0.0, null, null, 0, null);
        self::assertNull($id, 'Cannot set both discount_pct and discount_amount.');
    }

    public function test_increment_promo_usage_respects_limit(): void
    {
        $this->db->savePromoCode(null, 'LIM', 5.0, null, 0.0, null, null, 2, null);
        $promo = $this->pdo->query("SELECT id FROM promo_codes WHERE code = 'LIM'")->fetchColumn();

        self::assertTrue($this->db->incrementPromoCodeUsage((int)$promo));
        self::assertTrue($this->db->incrementPromoCodeUsage((int)$promo));
        self::assertFalse($this->db->incrementPromoCodeUsage((int)$promo), 'Third increment hits the limit.');

        $used = (int)$this->pdo->query("SELECT used_count FROM promo_codes WHERE id = {$promo}")->fetchColumn();
        self::assertSame(2, $used);
    }

    public function test_save_tier_validates(): void
    {
        self::assertNull($this->db->saveLoyaltyTier(null, '', 0, 1.0, 0), 'Empty name refused.');
        self::assertNull($this->db->saveLoyaltyTier(null, 'Bronze', -1, 1.0, 0), 'Negative min_spent refused.');
        self::assertNull($this->db->saveLoyaltyTier(null, 'Bronze', 0, 150.0, 0), 'cashback > 100% refused.');
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
