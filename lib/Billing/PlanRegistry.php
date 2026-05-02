<?php
/**
 * PlanRegistry — static catalog of subscription plans (Phase 14.2, 2026-05-03).
 *
 * Single source of truth for plan IDs, prices, included features, and
 * usage limits. Used by:
 *   - signup.php           — render plan picker
 *   - owner.php?tab=billing — render current plan + upgrade modal
 *   - billing-cycle-worker  — derive amount_kop for invoices
 *   - FeatureGate          — runtime feature checks
 *   - provider/billing.php — list MRR contribution by plan
 *
 * Trial is a virtual plan: full Pro-level features, 0 ₽, 14 days. After
 * trial expires without payment_method, tenant goes past_due.
 */

namespace Cleanmenu\Billing;

final class PlanRegistry
{
    /**
     * @return array<string, array{
     *   id: string,
     *   name: string,
     *   price_kop: int,
     *   currency: string,
     *   billing_period: string,
     *   trial_days: int,
     *   features: array<string, bool>,
     *   limits: array<string, int|null>,
     *   description: string,
     * }>
     */
    public static function all(): array
    {
        return [
            'trial' => [
                'id'             => 'trial',
                'name'           => 'Trial 14 days',
                'price_kop'      => 0,
                'currency'       => 'RUB',
                'billing_period' => 'monthly',
                'trial_days'     => 14,
                'features'       => self::features(['kds', 'inventory', 'loyalty', 'multi_location',
                                                    'analytics_v2', 'marketing', 'reviews', 'group_orders',
                                                    'waitlist', 'staff_v2', 'fiscal_54fz', 'webhooks',
                                                    'i18n', 'split_bill']),
                'limits'         => [
                    'max_locations'         => 3,
                    'max_menu_items'        => null, // unlimited during trial
                    'max_orders_per_month'  => null,
                    'max_staff_users'       => 10,
                ],
                'description' => 'Полный доступ ко всем фичам на 14 дней. Без оплаты. После — выбор тарифа.',
            ],
            'starter' => [
                'id'             => 'starter',
                'name'           => 'Starter',
                'price_kop'      => 299000, // 2 990 ₽
                'currency'       => 'RUB',
                'billing_period' => 'monthly',
                'trial_days'     => 0,
                'features'       => self::features(['analytics_v2', 'reviews', 'webhooks', 'i18n']),
                'limits'         => [
                    'max_locations'         => 1,
                    'max_menu_items'        => 200,
                    'max_orders_per_month'  => 1500,
                    'max_staff_users'       => 3,
                ],
                'description' => 'Меню, заказы, бронирования, отзывы. До 200 позиций, до 1500 заказов/мес.',
            ],
            'pro' => [
                'id'             => 'pro',
                'name'           => 'Pro',
                'price_kop'      => 699000, // 6 990 ₽
                'currency'       => 'RUB',
                'billing_period' => 'monthly',
                'trial_days'     => 0,
                'features'       => self::features(['kds', 'inventory', 'loyalty', 'multi_location',
                                                    'analytics_v2', 'marketing', 'reviews', 'group_orders',
                                                    'waitlist', 'staff_v2', 'fiscal_54fz', 'webhooks',
                                                    'i18n', 'split_bill']),
                'limits'         => [
                    'max_locations'         => 3,
                    'max_menu_items'        => null,
                    'max_orders_per_month'  => null,
                    'max_staff_users'       => 30,
                ],
                'description' => 'Всё для активной операции: KDS, склад, лояльность, маркетинг, 54-ФЗ. До 3 локаций.',
            ],
            'enterprise' => [
                'id'             => 'enterprise',
                'name'           => 'Enterprise',
                'price_kop'      => 1999000, // 19 990 ₽ baseline; final price negotiated
                'currency'       => 'RUB',
                'billing_period' => 'monthly',
                'trial_days'     => 0,
                'features'       => self::features(['kds', 'inventory', 'loyalty', 'multi_location',
                                                    'analytics_v2', 'marketing', 'reviews', 'group_orders',
                                                    'waitlist', 'staff_v2', 'fiscal_54fz', 'webhooks',
                                                    'i18n', 'split_bill', 'dev_api', 'white_label',
                                                    'priority_support', 'sla']),
                'limits'         => [
                    'max_locations'         => null,
                    'max_menu_items'        => null,
                    'max_orders_per_month'  => null,
                    'max_staff_users'       => null,
                ],
                'description' => 'Сети, dev API, white-label, приоритетная поддержка. Цена обсуждается.',
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

    /** Plans available for self-service signup (excludes 'enterprise' — sales contact). */
    public static function selfServiceIds(): array
    {
        return ['trial', 'starter', 'pro'];
    }

    /** Plans the user can switch to from a given plan via /owner.php?tab=billing. */
    public static function upgradePathsFrom(string $currentPlanId): array
    {
        $order = ['trial' => 0, 'starter' => 1, 'pro' => 2, 'enterprise' => 3];
        $cur = $order[$currentPlanId] ?? 0;
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
        return $plan ? (int)$plan['price_kop'] : 0;
    }

    /** "2 990 ₽" / "6 990 ₽" / "Бесплатно" */
    public static function priceLabel(string $planId): string
    {
        $kop = self::priceKop($planId);
        if ($kop === 0) return 'Бесплатно';
        $rub = $kop / 100;
        return number_format($rub, 0, '.', ' ') . ' ₽';
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
