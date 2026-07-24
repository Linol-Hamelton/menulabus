<?php
/**
 * TariffRegistry — read-only DAO for the control-plane `tariffs` table
 * (Phase L103.3).
 *
 * Replaces PlanRegistry as the source of truth for tariff display names,
 * prices and tier ranks. The legacy PlanRegistry stays in place until
 * Phase L103.8 to avoid breaking the existing billing surfaces in one big
 * cutover — both registries coexist during Phase L103.3 → L103.7.
 *
 * Caching: one round-trip on first call, then a static per-request cache
 * (mirrors the Phase L102 getMenuView pattern).
 *
 * Failure modes:
 *   - control plane not configured → return empty / null → callers fall back
 *     to PlanRegistry (legacy mode).
 *   - DB read fails → same: log + return empty.
 */
declare(strict_types=1);

namespace Cleanmenu\Billing;

require_once __DIR__ . '/FeatureGate.php'; // shares controlPlanePdo helper

final class TariffRegistry
{
    /** @var array<string, array<string,mixed>>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, array{
     *   code:string,
     *   display_name:string,
     *   tier_rank:?int,
     *   price_kop:?int,
     *   currency:string,
     *   billing_period:string,
     *   trial_days:int,
     *   is_self_service:bool,
     *   is_active:bool,
     *   sort_order:int,
     *   description:?string
     * }>
     */
    public static function all(): array
    {
        if (self::$cache !== null) return self::$cache;

        $pdo = self::controlPlanePdo();
        if (!$pdo) {
            self::$cache = [];
            return self::$cache;
        }

        try {
            $stmt = $pdo->query(
                'SELECT code, display_name, tier_rank, price_kop, currency,
                        billing_period, trial_days, is_self_service, is_active,
                        sort_order, description
                   FROM tariffs
                  WHERE is_active = 1
                  ORDER BY sort_order ASC, code ASC'
            );
            $out = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                $out[(string)$row['code']] = [
                    'code'            => (string)$row['code'],
                    'display_name'    => (string)$row['display_name'],
                    'tier_rank'       => isset($row['tier_rank']) ? (int)$row['tier_rank'] : null,
                    'price_kop'       => isset($row['price_kop']) ? (int)$row['price_kop'] : null,
                    'currency'        => (string)($row['currency'] ?? 'RUB'),
                    'billing_period'  => (string)($row['billing_period'] ?? 'monthly'),
                    'trial_days'      => (int)($row['trial_days'] ?? 0),
                    'is_self_service' => (bool)(int)($row['is_self_service'] ?? 1),
                    'is_active'       => (bool)(int)($row['is_active'] ?? 1),
                    'sort_order'      => (int)($row['sort_order'] ?? 0),
                    'description'     => $row['description'] !== null ? (string)$row['description'] : null,
                ];
            }
            self::$cache = $out;
        } catch (\Throwable $e) {
            error_log('TariffRegistry::all failed: ' . $e->getMessage());
            self::$cache = [];
        }
        return self::$cache;
    }

    public static function byCode(string $code): ?array
    {
        $all = self::all();
        return $all[$code] ?? null;
    }

    public static function exists(string $code): bool
    {
        return self::byCode($code) !== null;
    }

    /**
     * The 6 main tiers visible to customers (Меню → Кухня+), sorted by rank.
     * Excludes trial variants and sales-led chain.
     */
    public static function publicTiers(): array
    {
        $out = [];
        foreach (self::all() as $code => $t) {
            if ($t['tier_rank'] === null) continue;       // chain (sales-led)
            if (($t['trial_days'] ?? 0) > 0) continue;    // trial variants (menu_trial) — not a purchasable card
            if ($t['billing_period'] !== 'monthly') continue; // skip annual variants in the main grid
            if (!$t['is_self_service']) continue;
            $out[$code] = $t;
        }
        uasort($out, static fn($a, $b) => ($a['sort_order'] <=> $b['sort_order']) ?: ($a['tier_rank'] <=> $b['tier_rank']));
        return $out;
    }

    /**
     * Tariff codes that an owner can self-switch to from /owner.php?tab=billing.
     * Excludes trial (auto-assigned at signup) and chain (sales-led).
     */
    public static function ownerSwitchableCodes(): array
    {
        $out = [];
        foreach (self::all() as $code => $t) {
            if ($t['tier_rank'] === null) continue;
            if (($t['trial_days'] ?? 0) > 0) continue;    // trial variants are auto-assigned, not owner-switchable
            if (!$t['is_self_service']) continue;
            $out[] = $code;
        }
        return $out;
    }

    public static function tierRank(string $code): ?int
    {
        $t = self::byCode($code);
        return $t['tier_rank'] ?? null;
    }

    public static function priceKop(string $code): int
    {
        $t = self::byCode($code);
        return (int)($t['price_kop'] ?? 0);
    }

    /** "2 490 ₽/мес", "24 900 ₽/год", "Бесплатно", "Договорная". */
    public static function priceLabel(string $code): string
    {
        $t = self::byCode($code);
        if ($t === null) return '—';
        if ($t['price_kop'] === null) return 'Договорная';
        $kop = (int)$t['price_kop'];
        if ($kop === 0) return 'Бесплатно';
        $rub = $kop / 100;
        $period = $t['billing_period'] === 'annual' ? '/год' : '/мес';
        return number_format($rub, 0, '.', ' ') . ' ₽' . $period;
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /**
     * Re-use FeatureGate::controlPlanePdo via reflection so we don't have to
     * duplicate the tenant_runtime bootstrap logic (same trick SubscriptionStore
     * uses). Returns null in legacy single-DB mode.
     */
    private static function controlPlanePdo(): ?\PDO
    {
        try {
            $r = new \ReflectionClass(FeatureGate::class);
            $m = $r->getMethod('controlPlanePdo');
            $m->setAccessible(true);
            $pdo = $m->invoke(null);
            return $pdo instanceof \PDO ? $pdo : null;
        } catch (\Throwable $e) {
            error_log('TariffRegistry::controlPlanePdo: ' . $e->getMessage());
            return null;
        }
    }
}
