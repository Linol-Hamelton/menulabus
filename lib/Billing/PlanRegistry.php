<?php
/**
 * PlanRegistry — static catalog of subscription plans (Phase 32, 2026-05-08).
 *
 * @deprecated Phase L103 (2026-06-30) — superseded by control-plane.tariffs
 *             table + lib/Billing/TariffRegistry.php for the new 6-tier
 *             per-location pricing model (Меню / Заказ+ / Доставка+ /
 *             Контроль+ / Смена+ / Кухня+ + Сеть+). New code MUST read tier
 *             metadata via TariffRegistry and gate features via
 *             Database::hasFeature(Features::*, $locationId) (see
 *             lib/Billing/Features.php).
 *
 *             This class is kept ALIVE during the transition so legacy
 *             surfaces (signup.php, owner_billing_section.php,
 *             billing-cycle-worker.php, partials/billing_feature_gate.php)
 *             continue to work without an emergency rewrite. It will be
 *             removed once those callsites migrate to the per-location
 *             model — tracked under follow-up phase L104.
 *
 * Single source of truth for plan IDs, prices, included features, and
 * usage limits. Used by:
 *   - signup.php           — render plan picker
 *   - owner.php?tab=billing — render current plan + upgrade modal
 *   - billing-cycle-worker  — derive amount_kop for invoices
 *   - FeatureGate          — runtime feature checks
 *   - provider/billing.php — list MRR contribution by plan
 *
 * Phase 32 v3 pricing model (DEPRECATED — see L103 mapping in
 * scripts/l103-backfill-subscriptions.php for the legacy→new tariff_code
 * translation: trial→menu_trial, pro/pro_annual→order,
 * enterprise/enterprise_annual→kitchen, enterprise_plus→chain):
 *   - Killed `starter` (2 990 ₽) tier — was a "starter trap" with severely
 *     gutted features. New entry point is the 90-day Pro trial.
 *   - `trial` is now a 90-day Pro trial (full Pro feature set, full Pro limits).
 *     Card required at signup (no charge until day 91); enforces serious-only
 *     customers vs tire-kickers.
 *   - `pro` (6 990 ₽/mo) is the mainstream tier. Annual variant via
 *     `pro_annual` (69 900 ₽/year = 2 months free, ~16% discount).
 *   - `enterprise` (19 990 ₽/mo) bundles Express onboarding + 4h training
 *     (~24 900 ₽ value) — anchor pricing.
 *   - `enterprise_plus` (sales-led, договорная) for chains 10+ locations,
 *     SSO, dedicated DB, custom SLA.
 *
 * Self-service signup is restricted to `trial` (which auto-converts to
 * `pro` on day 91 if card is on file). All other plans go through
 * /owner.php?tab=billing for plan switching.
 *
 * Add-on services (one-time and recurring) live in lib/Billing/AddonCatalog.php.
 */

namespace Cleanmenu\Billing;

final class PlanRegistry
{
    /**
     * @return array<string, array{
     *   id: string,
     *   name: string,
     *   price_kop: int|null,
     *   currency: string,
     *   billing_period: string,
     *   trial_days: int,
     *   features: array<string, bool>,
     *   limits: array<string, int|null>,
     *   description: string,
     *   bundled_addons?: array<string>,
     *   sales_only?: bool,
     * }>
     */
    public static function all(): array
    {
        $proFeatures = self::features([
            'kds', 'inventory', 'loyalty', 'multi_location',
            'analytics_v2', 'marketing', 'reviews', 'group_orders',
            'waitlist', 'staff_v2', 'fiscal_54fz', 'webhooks',
            'i18n', 'split_bill',
        ]);
        $enterpriseFeatures = self::features([
            'kds', 'inventory', 'loyalty', 'multi_location',
            'analytics_v2', 'marketing', 'reviews', 'group_orders',
            'waitlist', 'staff_v2', 'fiscal_54fz', 'webhooks',
            'i18n', 'split_bill', 'dev_api', 'white_label',
            'priority_support', 'sla',
        ]);

        return [
            'trial' => [
                'id'             => 'trial',
                'name'           => 'Pro Trial 90 дней',
                'price_kop'      => 0,
                'currency'       => 'RUB',
                'billing_period' => 'monthly',
                'trial_days'     => 90,
                'features'       => $proFeatures,
                'limits'         => [
                    'max_locations'         => 3,
                    'max_menu_items'        => null,
                    'max_orders_per_month'  => null,
                    'max_staff_users'       => 30,
                ],
                'description' => '90 дней полного Pro-доступа. Карта при регистрации (не списываем до дня 91). Отмена в любой момент.',
            ],
            'pro' => [
                'id'             => 'pro',
                'name'           => 'Pro',
                'price_kop'      => 699000, // 6 990 ₽/мес
                'currency'       => 'RUB',
                'billing_period' => 'monthly',
                'trial_days'     => 0,
                'features'       => $proFeatures,
                'limits'         => [
                    'max_locations'         => 3,
                    'max_menu_items'        => null,
                    'max_orders_per_month'  => null,
                    'max_staff_users'       => 30,
                ],
                'description' => 'Mainstream-тариф: KDS, склад, лояльность, маркетинг, 54-ФЗ, group split-bill, отзывы. До 3 локаций.',
            ],
            'pro_annual' => [
                'id'             => 'pro_annual',
                'name'           => 'Pro (годовой)',
                'price_kop'      => 6990000, // 69 900 ₽/год = 2 месяца бесплатно (~16% скидка)
                'currency'       => 'RUB',
                'billing_period' => 'annual',
                'trial_days'     => 0,
                'features'       => $proFeatures,
                'limits'         => [
                    'max_locations'         => 3,
                    'max_menu_items'        => null,
                    'max_orders_per_month'  => null,
                    'max_staff_users'       => 30,
                ],
                'description' => 'Годовая оплата Pro: 69 900 ₽ (2 месяца бесплатно). Lock-in цены на 12 месяцев.',
            ],
            'enterprise' => [
                'id'             => 'enterprise',
                'name'           => 'Enterprise',
                'price_kop'      => 1999000, // 19 990 ₽/мес
                'currency'       => 'RUB',
                'billing_period' => 'monthly',
                'trial_days'     => 0,
                'features'       => $enterpriseFeatures,
                'limits'         => [
                    'max_locations'         => 10,
                    'max_menu_items'        => null,
                    'max_orders_per_month'  => null,
                    'max_staff_users'       => null,
                ],
                'bundled_addons' => ['express_onboarding', 'training_4h'],
                'description' => 'Сети до 10 локаций. White-label, custom-domain, dev API, priority support, SLA. В цену включён Express onboarding + 4ч обучения (~24 900 ₽ value).',
            ],
            'enterprise_annual' => [
                'id'             => 'enterprise_annual',
                'name'           => 'Enterprise (годовой)',
                'price_kop'      => 19990000, // 199 900 ₽/год = 2 месяца бесплатно
                'currency'       => 'RUB',
                'billing_period' => 'annual',
                'trial_days'     => 0,
                'features'       => $enterpriseFeatures,
                'limits'         => [
                    'max_locations'         => 10,
                    'max_menu_items'        => null,
                    'max_orders_per_month'  => null,
                    'max_staff_users'       => null,
                ],
                'bundled_addons' => ['express_onboarding', 'training_4h'],
                'description' => 'Годовая оплата Enterprise: 199 900 ₽ (2 месяца бесплатно). Lock-in цены на 12 месяцев.',
            ],
            'enterprise_plus' => [
                'id'             => 'enterprise_plus',
                'name'           => 'Enterprise+ / Сеть',
                'price_kop'      => null, // sales-led, договорная (от 35 000 ₽/мес)
                'currency'       => 'RUB',
                'billing_period' => 'monthly',
                'trial_days'     => 0,
                'features'       => $enterpriseFeatures,
                'limits'         => [
                    'max_locations'         => null, // unlimited
                    'max_menu_items'        => null,
                    'max_orders_per_month'  => null,
                    'max_staff_users'       => null,
                ],
                'bundled_addons' => ['full_onboarding', 'training_4h', 'priority_support'],
                'sales_only'     => true,
                'description' => 'Сети 10+ локаций, SSO/SAML, dedicated DB, кастомная SLA, выделенный success-manager. Свяжитесь с нами: sales@labus.pro',
            ],
        ];
    }

    public static function byId(string $planId): ?array
    {
        $plans = self::all();
        return $plans[$planId] ?? null;
    }

    public static function exists(string $planId): bool
    {
        return self::byId($planId) !== null;
    }

    /**
     * Plans available for self-service signup. After Phase 32 only `trial`
     * is offered at signup — Pro/Enterprise switch happens later via
     * /owner.php?tab=billing. Enterprise+ is sales-only.
     */
    public static function selfServiceIds(): array
    {
        return ['trial'];
    }

    /** Plans visible in /owner.php?tab=billing plan-switch grid (excludes sales-only tiers from grid; rendered as separate "Свяжитесь" CTA). */
    public static function ownerSwitchableIds(): array
    {
        return ['pro', 'pro_annual', 'enterprise', 'enterprise_annual'];
    }

    /** Plans the user can switch to from a given plan via /owner.php?tab=billing. */
    public static function upgradePathsFrom(string $currentPlanId): array
    {
        $order = [
            'trial'              => 0,
            'pro'                => 1,
            'pro_annual'         => 2,
            'enterprise'         => 3,
            'enterprise_annual'  => 4,
            'enterprise_plus'    => 5,
        ];
        $out = [];
        foreach ($order as $id => $rank) {
            if ($id === $currentPlanId) continue;
            $out[] = $id;
        }
        return $out;
    }

    public static function priceKop(string $planId): int
    {
        $plan = self::byId($planId);
        if ($plan === null) return 0;
        return (int)($plan['price_kop'] ?? 0);
    }

    /** "6 990 ₽/мес", "69 900 ₽/год", "Бесплатно", "Договорная" */
    public static function priceLabel(string $planId): string
    {
        $plan = self::byId($planId);
        if ($plan === null) return '—';
        if (($plan['sales_only'] ?? false) || $plan['price_kop'] === null) {
            return 'Договорная';
        }
        $kop = (int)$plan['price_kop'];
        if ($kop === 0) return 'Бесплатно';
        $rub = $kop / 100;
        $period = ($plan['billing_period'] ?? 'monthly') === 'annual' ? '/год' : '/мес';
        return number_format($rub, 0, '.', ' ') . ' ₽' . $period;
    }

    /** Number of trial days for a given plan. Currently only `trial` has >0. */
    public static function trialDays(string $planId): int
    {
        $plan = self::byId($planId);
        return $plan ? (int)($plan['trial_days'] ?? 0) : 0;
    }

    /** Returns true if signing up to this plan requires a payment method (card) at signup. */
    public static function requiresCardAtSignup(string $planId): bool
    {
        // Phase 32: trial requires card (charge deferred to day 91 if not cancelled).
        // Pro/Enterprise direct purchase obviously requires card.
        // Enterprise+ is sales-led — invoice billing handled outside self-service.
        if ($planId === 'enterprise_plus') return false;
        return true;
    }

    /** Build features map: enabled true, otherwise false (canonical key list below). */
    private static function features(array $enabled): array
    {
        $all = [
            'kds', 'inventory', 'loyalty', 'multi_location', 'analytics_v2',
            'marketing', 'reviews', 'group_orders', 'waitlist', 'staff_v2',
            'fiscal_54fz', 'webhooks', 'i18n', 'split_bill',
            'dev_api', 'white_label', 'priority_support', 'sla',
        ];
        $map = [];
        foreach ($all as $f) {
            $map[$f] = in_array($f, $enabled, true);
        }
        return $map;
    }
}
