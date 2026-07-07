<?php

declare(strict_types=1);

namespace Cleanmenu\Tests;

use Cleanmenu\Billing\Features;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/Billing/Features.php';

/**
 * Phase L103.3 — covers the static TIER_MATRIX + minTierRank + isUnlockedByRank
 * pure-PHP logic. Does NOT require MySQL or a control plane connection.
 *
 * Locks the contract that the L103.5 callsite gating depends on:
 *   - catalog.display ALWAYS unlocks (even with no subscription)
 *   - unknown features fail closed at tier 6
 *   - cart/delivery/analytics/staff/inventory each unlock exactly at their tier
 */
final class L103FeaturesTest extends TestCase
{
    public function testCatalogIsTier1(): void
    {
        $this->assertSame(1, Features::minTierRank(Features::CATALOG_DISPLAY));
    }

    public function testCartIsTier2(): void
    {
        $this->assertSame(2, Features::minTierRank(Features::CART_BASIC));
        $this->assertSame(2, Features::minTierRank(Features::CUSTOMER_ACCOUNT));
        $this->assertSame(2, Features::minTierRank(Features::ORDER_LOYALTY));
    }

    public function testDeliveryIsTier3(): void
    {
        $this->assertSame(3, Features::minTierRank(Features::ORDER_DELIVERY));
        $this->assertSame(3, Features::minTierRank(Features::ORDER_AGGREGATOR));
    }

    /**
     * Phase L103.9 split: takeaway and delivery are SEPARATE atomic keys —
     * the order-create paths (create_new_order / create_guest_order /
     * api/v1/orders/create) map delivery_type to its own key. Both live on
     * tier 3, but the split must survive future re-pricing.
     */
    public function testTakeawayAndDeliveryAreSeparateKeys(): void
    {
        $this->assertNotSame(Features::ORDER_TAKEAWAY, Features::ORDER_DELIVERY);
        $this->assertSame(3, Features::minTierRank(Features::ORDER_TAKEAWAY));
        $this->assertFalse(Features::isUnlockedByRank(Features::ORDER_TAKEAWAY, 2));
        $this->assertTrue(Features::isUnlockedByRank(Features::ORDER_TAKEAWAY, 3));
    }

    /** Tier-2 order sub-family wired in Phase L103.9/10 — lock the ranks. */
    public function testTier2OrderFamilyRanks(): void
    {
        $this->assertSame(2, Features::minTierRank(Features::ORDER_TIPS));
        $this->assertSame(2, Features::minTierRank(Features::ORDER_RESERVATION));
        $this->assertSame(2, Features::minTierRank(Features::ORDER_PAYMENT_ONLINE));
        $this->assertSame(2, Features::minTierRank(Features::ORDER_IN_RESTAURANT));
        $this->assertSame(2, Features::minTierRank(Features::ORDER_REVIEWS));
        $this->assertSame(3, Features::minTierRank(Features::ORDER_WAITLIST));
    }

    public function testAnalyticsIsTier4(): void
    {
        $this->assertSame(4, Features::minTierRank(Features::ANALYTICS_REVENUE));
        $this->assertSame(4, Features::minTierRank(Features::ANALYTICS_FISCAL_54FZ));
    }

    public function testStaffIsTier5(): void
    {
        $this->assertSame(5, Features::minTierRank(Features::STAFF_CRUD));
        $this->assertSame(5, Features::minTierRank(Features::STAFF_KDS));
    }

    public function testInventoryIsTier6(): void
    {
        $this->assertSame(6, Features::minTierRank(Features::INVENTORY_INGREDIENTS));
        $this->assertSame(6, Features::minTierRank(Features::INVENTORY_EGAIS));
    }

    public function testUnknownKeyDefaultsToTier6FailClosed(): void
    {
        // Defensive default: a typo in a callsite must NOT accidentally
        // open the feature on the cheapest tier.
        $this->assertSame(6, Features::minTierRank('something.never.added'));
    }

    public function testCatalogAlwaysUnlocked(): void
    {
        // Even with tier_rank=0 (no subscription) the public menu must render.
        $this->assertTrue(Features::isUnlockedByRank(Features::CATALOG_DISPLAY, 0));
        $this->assertTrue(Features::isUnlockedByRank(Features::CATALOG_DISPLAY, -1));
        $this->assertTrue(Features::isUnlockedByRank(Features::CATALOG_DISPLAY, 6));
    }

    public function testCartLockedBelowTier2(): void
    {
        $this->assertFalse(Features::isUnlockedByRank(Features::CART_BASIC, 0));
        $this->assertFalse(Features::isUnlockedByRank(Features::CART_BASIC, 1));
        $this->assertTrue(Features::isUnlockedByRank(Features::CART_BASIC, 2));
        $this->assertTrue(Features::isUnlockedByRank(Features::CART_BASIC, 6));
    }

    public function testInventoryOnlyOnTier6(): void
    {
        foreach (range(0, 5) as $rank) {
            $this->assertFalse(
                Features::isUnlockedByRank(Features::INVENTORY_EGAIS, $rank),
                "EGAIS must NOT unlock at tier $rank"
            );
        }
        $this->assertTrue(Features::isUnlockedByRank(Features::INVENTORY_EGAIS, 6));
    }

    public function testAllRegisteredKeysHaveMatrixEntry(): void
    {
        $keys = Features::allKeys();
        $this->assertNotEmpty($keys);
        foreach ($keys as $k) {
            $this->assertArrayHasKey($k, Features::TIER_MATRIX);
            $rank = Features::TIER_MATRIX[$k];
            $this->assertIsInt($rank);
            $this->assertGreaterThanOrEqual(1, $rank);
            $this->assertLessThanOrEqual(6, $rank);
        }
    }

    /** Every one of the 6 tiers must have at least one feature pinned to it. */
    public function testAllSixRanksRepresented(): void
    {
        $ranks = array_unique(array_values(Features::TIER_MATRIX));
        sort($ranks);
        $this->assertSame([1, 2, 3, 4, 5, 6], $ranks);
    }
}
