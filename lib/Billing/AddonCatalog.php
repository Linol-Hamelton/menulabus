<?php
/**
 * AddonCatalog — registry of paid one-time and recurring add-on services
 * (Phase 32B, 2026-05-08).
 *
 * Single source of truth for add-on SKUs, prices, descriptions, and
 * delivery type (one-time charge vs recurring monthly fee). Used by:
 *   - /owner.php?tab=billing — render addon purchase grid
 *   - /api/billing-purchase-addon.php — validate SKU + price before
 *     creating YK payment
 *   - /index.php pricing section — display in customer-facing list
 *
 * Pricing rationale and full strategy lives in
 * docs/marketing-strategy-2026.md. Catalog is intentionally small and
 * curated — these are services performed by founder/team, not
 * self-served digital goods.
 */

namespace Cleanmenu\Billing;

final class AddonCatalog
{
    /**
     * @return array<string, array{
     *   sku: string,
     *   name: string,
     *   description: string,
     *   price_kop: int,
     *   currency: string,
     *   type: string,
     *   bundled_in_plans?: array<string>,
     *   estimated_hours?: float,
     * }>
     */
    public static function all(): array
    {
        return [
            'express_onboarding' => [
                'sku'         => 'express_onboarding',
                'name'        => 'Express onboarding',
                'description' => '3 часа: настройка аккаунта, бренд (логотип + цвета), импорт меню из CSV/Excel, печать QR-кодов на 1 локацию.',
                'price_kop'   => 990000, // 9 900 ₽
                'currency'    => 'RUB',
                'type'        => 'one_time',
                'bundled_in_plans' => ['enterprise', 'enterprise_annual', 'enterprise_plus'],
                'estimated_hours'  => 3.0,
            ],
            'full_onboarding' => [
                'sku'         => 'full_onboarding',
                'name'        => 'Full onboarding',
                'description' => '8 часов: всё из Express + категории, модификаторы, рецепты с ингредиентами, KDS-станции, 54-ФЗ через АТОЛ, Telegram-бот.',
                'price_kop'   => 2490000, // 24 900 ₽
                'currency'    => 'RUB',
                'type'        => 'one_time',
                'bundled_in_plans' => ['enterprise_plus'],
                'estimated_hours'  => 8.0,
            ],
            'migration_from_competitor' => [
                'sku'         => 'migration_from_competitor',
                'name'        => 'Миграция с iiko / R-Keeper / Quick Resto / Poster',
                'description' => '12+ часов: экспорт данных от конкурента, mapping категорий и блюд, валидация, parallel-run сопровождение до полного перехода.',
                'price_kop'   => 4990000, // 49 900 ₽
                'currency'    => 'RUB',
                'type'        => 'one_time',
                'estimated_hours' => 12.0,
            ],
            'group_training' => [
                'sku'         => 'group_training',
                'name'        => 'Group training (онлайн, до 5 человек)',
                'description' => '2 часа онлайн: приём заказов, KDS, отчётность, маркетинговые рассылки. До 5 сотрудников.',
                'price_kop'   => 690000, // 6 900 ₽
                'currency'    => 'RUB',
                'type'        => 'one_time',
                'estimated_hours' => 2.0,
            ],
            'individual_training' => [
                'sku'         => 'individual_training',
                'name'        => 'Individual training (1-on-1)',
                'description' => '1 час 1-on-1: настройки, аналитика v2, маркетинговые сегменты, лояльность. Для owner / шеф-повара.',
                'price_kop'   => 490000, // 4 900 ₽
                'currency'    => 'RUB',
                'type'        => 'one_time',
                'estimated_hours' => 1.0,
            ],
            'training_4h' => [
                'sku'         => 'training_4h',
                'name'        => '4 часа обучения (бандл для Enterprise)',
                'description' => 'Маркер бандла, входящего в тариф Enterprise/Enterprise+. Не продаётся отдельно — включено в подписку.',
                'price_kop'   => 0,
                'currency'    => 'RUB',
                'type'        => 'bundled_only',
            ],
            'telegram_bot_setup' => [
                'sku'         => 'telegram_bot_setup',
                'name'        => 'Telegram-bot setup',
                'description' => 'Создание Telegram-бота, webhook config, inline-кнопки на pending-заказы, тестирование на dev-данных.',
                'price_kop'   => 490000, // 4 900 ₽
                'currency'    => 'RUB',
                'type'        => 'one_time',
            ],
            'custom_domain_config' => [
                'sku'         => 'custom_domain_config',
                'name'        => 'Custom domain config',
                'description' => 'DNS + Let\'s Encrypt SSL + nginx config для tenant-домена. На Pro — доплата; в Enterprise — included.',
                'price_kop'   => 290000, // 2 900 ₽
                'currency'    => 'RUB',
                'type'        => 'one_time',
                'bundled_in_plans' => ['enterprise', 'enterprise_annual', 'enterprise_plus'],
            ],
            'recorded_video_course' => [
                'sku'         => 'recorded_video_course',
                'name'        => 'Recorded video course (lifetime access)',
                'description' => 'Запись полного курса по платформе для самообучения нового персонала. Lifetime access, обновляется по мере выпуска новых функций.',
                'price_kop'   => 290000, // 2 900 ₽
                'currency'    => 'RUB',
                'type'        => 'one_time',
            ],
            'priority_support' => [
                'sku'         => 'priority_support',
                'name'        => 'Priority support 24/7 (SLA-2h)',
                'description' => 'Приоритетная поддержка с гарантией ответа за 2 часа в любой день. На Pro — доплата +990 ₽/мес; в Enterprise — included.',
                'price_kop'   => 99000, // 990 ₽/мес
                'currency'    => 'RUB',
                'type'        => 'recurring',
                'bundled_in_plans' => ['enterprise', 'enterprise_annual', 'enterprise_plus'],
            ],
            'on_site_visit' => [
                'sku'         => 'on_site_visit',
                'name'        => 'On-site visit (Москва / СПб, half-day)',
                'description' => '4 часа выезда в заведение: установка, обучение персонала, подключение оборудования. Travel-расходы оплачиваются отдельно.',
                'price_kop'   => 1990000, // 19 900 ₽
                'currency'    => 'RUB',
                'type'        => 'one_time',
                'estimated_hours' => 4.0,
            ],
        ];
    }

    public static function bySku(string $sku): ?array
    {
        $all = self::all();
        return $all[$sku] ?? null;
    }

    public static function exists(string $sku): bool
    {
        return self::bySku($sku) !== null;
    }

    /** Add-ons that can be purchased standalone via /api/billing-purchase-addon.php. */
    public static function purchasableSkus(): array
    {
        $out = [];
        foreach (self::all() as $sku => $addon) {
            if (in_array($addon['type'] ?? '', ['one_time', 'recurring'], true)) {
                $out[] = $sku;
            }
        }
        return $out;
    }

    public static function priceKop(string $sku): int
    {
        $a = self::bySku($sku);
        return $a ? (int)$a['price_kop'] : 0;
    }

    /** "9 900 ₽" / "990 ₽/мес" / "—" */
    public static function priceLabel(string $sku): string
    {
        $a = self::bySku($sku);
        if (!$a) return '—';
        $kop = (int)$a['price_kop'];
        if ($kop === 0) return 'Включено в тариф';
        $rub = $kop / 100;
        $suffix = ($a['type'] ?? 'one_time') === 'recurring' ? '/мес' : '';
        return number_format($rub, 0, '.', ' ') . ' ₽' . $suffix;
    }
}
