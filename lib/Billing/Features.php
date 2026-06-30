<?php
/**
 * Features — atomic feature key registry for Phase L103 6-tier pricing.
 *
 * Single source of truth for "what minimum tier rank unlocks feature X".
 *
 * Tier ranks:
 *   1 = Меню       (catalog display only)
 *   2 = Заказ+     (+ cart, account, in-restaurant orders, reservations, loyalty, tips, online pay, reviews)
 *   3 = Доставка+  (+ takeaway/delivery, courier assignment, aggregators, waitlist)
 *   4 = Контроль+  (+ analytics revenue/v2, 54-ФЗ fiscal, reviews moderation, marketing campaigns, webhooks)
 *   5 = Смена+     (+ staff CRUD, shifts, tip-pool, shift-swap, cashier shift / Z-report, KDS)
 *   6 = Кухня+     (+ inventory, recipes, COGS deduction, stocktake, semi-finished, EGAIS, VSD, suppliers)
 *
 * Sales-led tariff `chain` is treated as tier rank 6 (full unlock).
 *
 * Naming convention: <domain>.<capability>, lowercase, dot-separated. Used as
 * the parameter to Database::hasFeature(string $featureKey, int $locationId).
 *
 * THIS FILE IS PURE SCAFFOLD (Phase L103.3). No application code reads from
 * Features::* until Phase L103.5 callsite gating. Adding a new constant here
 * is safe — it doesn't change behaviour until a site explicitly uses it.
 */
declare(strict_types=1);

namespace Cleanmenu\Billing;

final class Features
{
    // Tier 1 — Меню (always on for any active subscription, also fail-open
    // for tenants with no subscription row so a public menu always renders).
    public const CATALOG_DISPLAY      = 'catalog.display';
    public const CATALOG_SEARCH       = 'catalog.search';
    public const CATALOG_QR_LANDING   = 'catalog.qr_landing';
    public const CATALOG_I18N         = 'catalog.i18n';

    // Tier 2 — Заказ+
    public const CART_BASIC           = 'cart.basic';
    public const CUSTOMER_ACCOUNT     = 'customer.account';
    public const CUSTOMER_ORDERS_HIST = 'customer.orders_history';
    public const ORDER_IN_RESTAURANT  = 'order.in_restaurant';
    public const ORDER_RESERVATION    = 'order.reservation';
    public const ORDER_LOYALTY        = 'order.loyalty';
    public const ORDER_TIPS           = 'order.tips';
    public const ORDER_PAYMENT_ONLINE = 'order.payment_online';
    public const ORDER_REVIEWS        = 'order.reviews';

    // Tier 3 — Доставка+
    public const ORDER_TAKEAWAY       = 'order.takeaway';
    public const ORDER_DELIVERY       = 'order.delivery';
    public const ORDER_COURIER_ASSIGN = 'order.courier_assign';
    public const ORDER_AGGREGATOR     = 'order.aggregator';
    public const ORDER_WAITLIST       = 'order.waitlist';

    // Tier 4 — Контроль+
    public const ANALYTICS_REVENUE       = 'analytics.revenue';
    public const ANALYTICS_V2            = 'analytics.v2';
    public const ANALYTICS_FISCAL_54FZ   = 'analytics.fiscal_54fz';
    public const ANALYTICS_REVIEWS_MOD   = 'analytics.reviews_moderation';
    public const ANALYTICS_MARKETING     = 'analytics.marketing_campaigns';
    public const ANALYTICS_WEBHOOKS      = 'analytics.webhooks';

    // Tier 5 — Смена+
    public const STAFF_CRUD           = 'staff.crud';
    public const STAFF_SHIFTS         = 'staff.shifts';
    public const STAFF_TIP_POOL       = 'staff.tip_pool';
    public const STAFF_SHIFT_SWAP     = 'staff.shift_swap';
    public const STAFF_CASHIER_SHIFT  = 'staff.cashier_shift';
    public const STAFF_KDS            = 'staff.kds';

    // Tier 6 — Кухня+
    public const INVENTORY_INGREDIENTS  = 'inventory.ingredients';
    public const INVENTORY_RECIPES      = 'inventory.recipes';
    public const INVENTORY_COGS         = 'inventory.cogs_deduction';
    public const INVENTORY_STOCKTAKE    = 'inventory.stocktake';
    public const INVENTORY_SEMI_FINISHED = 'inventory.semi_finished';
    public const INVENTORY_EGAIS        = 'inventory.egais';
    public const INVENTORY_VSD          = 'inventory.vsd_mercury';
    public const INVENTORY_SUPPLIERS    = 'inventory.suppliers';

    /**
     * Feature key → minimum tier rank required to use it.
     * Adding a new feature row here is a pure-PHP edit — no DB write needed.
     */
    public const TIER_MATRIX = [
        // Tier 1
        self::CATALOG_DISPLAY        => 1,
        self::CATALOG_SEARCH         => 1,
        self::CATALOG_QR_LANDING     => 1,
        self::CATALOG_I18N           => 1,
        // Tier 2
        self::CART_BASIC             => 2,
        self::CUSTOMER_ACCOUNT       => 2,
        self::CUSTOMER_ORDERS_HIST   => 2,
        self::ORDER_IN_RESTAURANT    => 2,
        self::ORDER_RESERVATION      => 2,
        self::ORDER_LOYALTY          => 2,
        self::ORDER_TIPS             => 2,
        self::ORDER_PAYMENT_ONLINE   => 2,
        self::ORDER_REVIEWS          => 2,
        // Tier 3
        self::ORDER_TAKEAWAY         => 3,
        self::ORDER_DELIVERY         => 3,
        self::ORDER_COURIER_ASSIGN   => 3,
        self::ORDER_AGGREGATOR       => 3,
        self::ORDER_WAITLIST         => 3,
        // Tier 4
        self::ANALYTICS_REVENUE      => 4,
        self::ANALYTICS_V2           => 4,
        self::ANALYTICS_FISCAL_54FZ  => 4,
        self::ANALYTICS_REVIEWS_MOD  => 4,
        self::ANALYTICS_MARKETING    => 4,
        self::ANALYTICS_WEBHOOKS     => 4,
        // Tier 5
        self::STAFF_CRUD             => 5,
        self::STAFF_SHIFTS           => 5,
        self::STAFF_TIP_POOL         => 5,
        self::STAFF_SHIFT_SWAP       => 5,
        self::STAFF_CASHIER_SHIFT    => 5,
        self::STAFF_KDS              => 5,
        // Tier 6
        self::INVENTORY_INGREDIENTS  => 6,
        self::INVENTORY_RECIPES      => 6,
        self::INVENTORY_COGS         => 6,
        self::INVENTORY_STOCKTAKE    => 6,
        self::INVENTORY_SEMI_FINISHED => 6,
        self::INVENTORY_EGAIS        => 6,
        self::INVENTORY_VSD          => 6,
        self::INVENTORY_SUPPLIERS    => 6,
    ];

    /**
     * Minimum tier rank required for a feature.
     * Unknown features fail closed at rank 6 (top tier required) — defensive
     * default so a typo in a callsite doesn't accidentally open a feature on
     * the cheapest tier.
     */
    public static function minTierRank(string $featureKey): int
    {
        return self::TIER_MATRIX[$featureKey] ?? 6;
    }

    /**
     * True iff a tariff at the given rank unlocks the feature.
     * Both rank arguments < 1 are treated as no-subscription / fail-closed
     * except for catalog.display which always returns true.
     */
    public static function isUnlockedByRank(string $featureKey, int $tariffRank): bool
    {
        if ($featureKey === self::CATALOG_DISPLAY) {
            return true; // public menu is always on
        }
        if ($tariffRank < 1) return false;
        return $tariffRank >= self::minTierRank($featureKey);
    }

    /** @return string[] */
    public static function allKeys(): array
    {
        return array_keys(self::TIER_MATRIX);
    }
}
