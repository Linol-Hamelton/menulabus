<?php
/**
 * partials/owner_billing_section.php — owner.php?tab=billing (Phase 32, 2026-05-08).
 *
 * Renders the tenant-facing subscription panel:
 *   - Current plan card with status badge
 *   - Saved card (last4 / brand) + "Заменить карту" button
 *   - Last 12 invoices table
 *   - Plan picker modal (upgrade / downgrade)
 *   - Add-on services catalog (display-only in 32A; purchase in 32B)
 *   - Cancel subscription button
 *
 * Phase 32 changes:
 *   - Plan grid: Starter killed, Pro / Pro-annual / Enterprise / Enterprise+
 *   - Annual billing toggle (Pro→Pro-annual, Enterprise→Enterprise-annual)
 *   - Add-on services section (Express onboarding, Full onboarding,
 *     Migration, Telegram-bot setup, Custom domain, Training packages)
 *   - Enterprise+ → "Свяжитесь" CTA (sales-only)
 *
 * All actions go to /api/billing-action.php (CSRF-gated, owner-only).
 */

require_once __DIR__ . '/../lib/Billing/PlanRegistry.php';
require_once __DIR__ . '/../lib/Billing/FeatureGate.php';
require_once __DIR__ . '/../lib/Billing/SubscriptionStore.php';

use Cleanmenu\Billing\PlanRegistry;
use Cleanmenu\Billing\FeatureGate;
use Cleanmenu\Billing\SubscriptionStore;

$tenantId = (int)($GLOBALS['tenantId'] ?? 0);
$billing  = $tenantId > 0 ? SubscriptionStore::getTenantBilling($tenantId) : null;
$pm       = $tenantId > 0 ? SubscriptionStore::getDefaultPaymentMethod($tenantId) : null;
$invoices = $tenantId > 0 ? SubscriptionStore::getInvoicesByTenant($tenantId, 12) : [];

$planId    = $billing['plan_id'] ?? FeatureGate::planId();
$plan      = PlanRegistry::byId($planId) ?? PlanRegistry::byId('trial');
$status    = $billing['subscription_status'] ?? FeatureGate::status();
$periodEnd = $billing['current_period_end'] ?? null;
$trialEnd  = $billing['trial_ends_at'] ?? null;

$statusLabels = [
    'trial'     => 'Пробный период',
    'active'    => 'Активна',
    'past_due'  => 'Платёж не прошёл',
    'suspended' => 'Приостановлена',
    'cancelled' => 'Отменена',
];
$statusLabel = $statusLabels[$status] ?? $status;
?>
<div class="owner-workspace-stack billing-tab">
    <div class="owner-workspace-header">
        <div>
            <p class="owner-workspace-kicker">Подписка</p>
            <h2>Тариф и оплата</h2>
        </div>
        <p class="owner-workspace-copy">
            Управление подпиской, способом оплаты и счетами. Всё что нужно, чтобы продолжать пользоваться платформой.
        </p>
    </div>

    <div class="account-section billing-current-card">
        <div class="billing-current-head">
            <div>
                <h3><?= htmlspecialchars($plan['name'] ?? 'Tariff') ?></h3>
                <p class="billing-current-price">
                    <?= htmlspecialchars(PlanRegistry::priceLabel($planId)) ?>
                    <?php if ($plan && (int)$plan['price_kop'] > 0): ?>
                        <small> / месяц</small>
                    <?php endif; ?>
                </p>
            </div>
            <span class="billing-status-badge billing-status-<?= htmlspecialchars($status) ?>">
                <?= htmlspecialchars($statusLabel) ?>
            </span>
        </div>

        <?php if ($status === 'trial' && $trialEnd): ?>
            <p class="billing-status-line">
                Пробный период до <strong><?= htmlspecialchars(date('d.m.Y', strtotime($trialEnd))) ?></strong>.
                Чтобы продолжить — добавьте карту и выберите тариф.
            </p>
        <?php elseif ($status === 'active' && $periodEnd): ?>
            <p class="billing-status-line">
                Следующее списание: <strong><?= htmlspecialchars(date('d.m.Y', strtotime($periodEnd))) ?></strong>.
            </p>
        <?php elseif ($status === 'past_due'): ?>
            <p class="billing-status-line billing-warning">
                Не удалось списать оплату. Витрина работает в режиме read-only. Замените карту или попробуйте оплатить вручную.
            </p>
        <?php elseif ($status === 'suspended'): ?>
            <p class="billing-status-line billing-danger">
                Подписка приостановлена. Витрина закрыта для гостей. Оплатите подписку, чтобы возобновить работу.
            </p>
        <?php endif; ?>
    </div>

    <div class="account-section billing-card-section">
        <h3>Способ оплаты</h3>
        <?php if ($pm): ?>
            <p class="billing-card-line">
                <strong><?= htmlspecialchars(strtoupper((string)($pm['brand'] ?? 'CARD'))) ?> •••• <?= htmlspecialchars((string)($pm['last4'] ?? '????')) ?></strong>
                <?php if (!empty($pm['expires_month']) && !empty($pm['expires_year'])): ?>
                    <small>· до <?= sprintf('%02d/%02d', (int)$pm['expires_month'], (int)$pm['expires_year'] % 100) ?></small>
                <?php endif; ?>
            </p>
        <?php else: ?>
            <p class="billing-card-line billing-card-empty">Карта ещё не привязана.</p>
        <?php endif; ?>
        <button type="button" class="checkout-btn" id="billingUpdateCardBtn">
            <?= $pm ? 'Заменить карту' : 'Добавить карту' ?>
        </button>
        <span class="billing-action-feedback" id="billingFeedback" hidden></span>
    </div>

    <div class="account-section billing-plan-picker">
        <h3>Сменить тариф</h3>
        <p class="billing-plan-hint">
            Месячная оплата — гибкая, отмена в любой момент. Годовая оплата — 2 месяца бесплатно.
            Сетевой план (10+ локаций) — договорная цена.
        </p>
        <div class="billing-plan-grid">
            <?php foreach (['pro', 'pro_annual', 'enterprise', 'enterprise_annual'] as $optId):
                $opt = PlanRegistry::byId($optId);
                if (!$opt) continue;
                $isCurrent  = $optId === $planId;
                $isAnnual   = ($opt['billing_period'] ?? 'monthly') === 'annual';
                $isEntPlus  = false;
            ?>
                <div class="billing-plan-card <?= $isCurrent ? 'is-current' : '' ?> <?= $isAnnual ? 'is-annual' : '' ?>">
                    <h4>
                        <?= htmlspecialchars($opt['name']) ?>
                        <?php if ($isAnnual): ?><span class="billing-plan-saving">−2 мес</span><?php endif; ?>
                    </h4>
                    <p class="billing-plan-price"><?= htmlspecialchars(PlanRegistry::priceLabel($optId)) ?></p>
                    <p class="billing-plan-desc"><?= htmlspecialchars($opt['description']) ?></p>
                    <ul class="billing-plan-limits">
                        <?php
                        $loc = $opt['limits']['max_locations'];
                        $items = $opt['limits']['max_menu_items'];
                        $orders = $opt['limits']['max_orders_per_month'];
                        ?>
                        <li>Локации: <strong><?= $loc === null ? 'без лимита' : (int)$loc ?></strong></li>
                        <li>Позиции меню: <strong><?= $items === null ? 'без лимита' : (int)$items ?></strong></li>
                        <li>Заказы / месяц: <strong><?= $orders === null ? 'без лимита' : number_format((int)$orders, 0, '.', ' ') ?></strong></li>
                        <?php if (!empty($opt['bundled_addons'])): ?>
                            <li>В цену включено: <strong>Express onboarding + 4 ч обучения</strong> (~24 900 ₽ value)</li>
                        <?php endif; ?>
                    </ul>
                    <?php if ($isCurrent): ?>
                        <button type="button" class="checkout-btn" disabled>Текущий</button>
                    <?php else: ?>
                        <button type="button" class="checkout-btn billing-change-plan-btn" data-target-plan="<?= htmlspecialchars($optId) ?>">
                            Перейти
                        </button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php
            // Enterprise+ — sales-only, separate CTA card
            $entPlus = PlanRegistry::byId('enterprise_plus');
            if ($entPlus):
            ?>
                <div class="billing-plan-card billing-plan-card--sales">
                    <h4><?= htmlspecialchars($entPlus['name']) ?></h4>
                    <p class="billing-plan-price">Договорная</p>
                    <p class="billing-plan-desc"><?= htmlspecialchars($entPlus['description']) ?></p>
                    <ul class="billing-plan-limits">
                        <li>Локации: <strong>без лимита</strong></li>
                        <li>SSO/SAML, dedicated DB</li>
                        <li>Кастомная SLA, выделенный success-manager</li>
                        <li>Включено: Full onboarding + 4 ч обучения + priority support</li>
                    </ul>
                    <a href="mailto:sales@labus.pro?subject=Enterprise%2B%20plan%20inquiry" class="admin-checkout-btn">Связаться</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="account-section billing-addons-section">
        <h3>Дополнительные услуги</h3>
        <p class="billing-addon-hint">
            Разовая помощь от команды CleanMenu. Покупка с карты на странице (доступна
            в Phase 32B — пока для заявки используйте контакт ниже).
        </p>
        <div class="billing-addon-grid">
            <div class="billing-addon-card">
                <h4>Express onboarding</h4>
                <p class="billing-addon-price">9 900 ₽ <small>единоразово</small></p>
                <p class="billing-addon-desc">3 часа: настройка аккаунта, бренд (логотип + цвета), импорт меню из CSV/Excel, печать QR-кодов на 1 локацию.</p>
            </div>
            <div class="billing-addon-card">
                <h4>Full onboarding</h4>
                <p class="billing-addon-price">24 900 ₽ <small>единоразово</small></p>
                <p class="billing-addon-desc">8 часов: всё из Express + категории, модификаторы, рецепты с ингредиентами, KDS-станции, 54-ФЗ, Telegram-бот.</p>
            </div>
            <div class="billing-addon-card">
                <h4>Миграция с iiko / R-Keeper / Quick Resto</h4>
                <p class="billing-addon-price">49 900 ₽ <small>единоразово</small></p>
                <p class="billing-addon-desc">12+ часов: экспорт данных от конкурента, mapping, валидация, parallel-run сопровождение до полного перехода.</p>
            </div>
            <div class="billing-addon-card">
                <h4>Group training (онлайн)</h4>
                <p class="billing-addon-price">6 900 ₽ <small>за 2 часа, до 5 человек</small></p>
                <p class="billing-addon-desc">Обучение персонала: приём заказов, KDS, отчётность, маркетинговые рассылки.</p>
            </div>
            <div class="billing-addon-card">
                <h4>Individual training</h4>
                <p class="billing-addon-price">4 900 ₽ <small>за 1 час 1-on-1</small></p>
                <p class="billing-addon-desc">Owner-training: настройки, аналитика v2, маркетинговые сегменты, лояльность.</p>
            </div>
            <div class="billing-addon-card">
                <h4>Telegram-bot setup</h4>
                <p class="billing-addon-price">4 900 ₽ <small>единоразово</small></p>
                <p class="billing-addon-desc">Создание бота, webhook config, inline-buttons на pending-заказы, тестирование.</p>
            </div>
            <div class="billing-addon-card">
                <h4>Custom domain config</h4>
                <p class="billing-addon-price">2 900 ₽ <small>единоразово</small></p>
                <p class="billing-addon-desc">DNS + Let's Encrypt SSL + nginx config для tenant-домена. На Pro как доплата; в Enterprise включено.</p>
            </div>
            <div class="billing-addon-card">
                <h4>Recorded video course</h4>
                <p class="billing-addon-price">2 900 ₽ <small>lifetime access</small></p>
                <p class="billing-addon-desc">Запись полного курса по платформе для самообучения нового персонала.</p>
            </div>
        </div>
        <p class="billing-addon-contact">
            Чтобы заказать — пишите <a href="mailto:hello@labus.pro?subject=Onboarding%20%2F%20training%20request">hello@labus.pro</a> или в Telegram-чат поддержки.
        </p>
    </div>

    <div class="account-section billing-invoices-section">
        <h3>История списаний</h3>
        <?php if (empty($invoices)): ?>
            <p class="billing-empty">Списаний ещё не было.</p>
        <?php else: ?>
            <table class="billing-invoices-table">
                <thead>
                    <tr>
                        <th>Период</th>
                        <th>Тариф</th>
                        <th>Сумма</th>
                        <th>Статус</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars(date('d.m.Y', strtotime((string)$inv['period_start']))) ?>
                                — <?= htmlspecialchars(date('d.m.Y', strtotime((string)$inv['period_end']))) ?>
                            </td>
                            <td><?= htmlspecialchars((string)$inv['plan_id']) ?></td>
                            <td><?= number_format((int)$inv['amount_kop'] / 100, 0, '.', ' ') ?> ₽</td>
                            <td>
                                <span class="billing-invoice-status billing-invoice-<?= htmlspecialchars((string)$inv['status']) ?>">
                                    <?php
                                    echo htmlspecialchars(match((string)$inv['status']) {
                                        'paid'      => '✅ оплачено',
                                        'pending'   => '⏳ в обработке',
                                        'failed'    => '❌ не прошло (попыток: ' . (int)$inv['retry_count'] . ')',
                                        'refunded'  => '↺ возврат',
                                        'cancelled' => '⊘ отменено',
                                        default     => (string)$inv['status'],
                                    });
                                    ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php if (in_array($status, ['active', 'trial', 'past_due'], true)): ?>
        <div class="account-section billing-cancel-section">
            <h3>Отмена подписки</h3>
            <p class="billing-cancel-hint">
                Подписка останется активной до конца оплаченного периода. После — статус сменится на <strong>cancelled</strong>, доступ закроется.
            </p>
            <button type="button" class="admin-checkout-btn cancel" id="billingCancelBtn">Отменить подписку</button>
        </div>
    <?php endif; ?>
</div>

<script src="/js/owner-billing.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?? '' ?>"></script>
