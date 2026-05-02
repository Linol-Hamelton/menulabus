<?php
/**
 * partials/owner_billing_section.php — owner.php?tab=billing (Phase 14.5).
 *
 * Renders the tenant-facing subscription panel:
 *   - Current plan card with status badge
 *   - Saved card (last4 / brand) + "Заменить карту" button
 *   - Last 12 invoices table
 *   - Plan picker modal (upgrade / downgrade)
 *   - Cancel subscription button
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
        <div class="billing-plan-grid">
            <?php foreach (['starter', 'pro', 'enterprise'] as $optId):
                $opt = PlanRegistry::byId($optId);
                if (!$opt) continue;
                $isCurrent = $optId === $planId;
            ?>
                <div class="billing-plan-card <?= $isCurrent ? 'is-current' : '' ?>">
                    <h4><?= htmlspecialchars($opt['name']) ?></h4>
                    <p class="billing-plan-price"><?= htmlspecialchars(PlanRegistry::priceLabel($optId)) ?><small> / мес</small></p>
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
                    </ul>
                    <?php if ($isCurrent): ?>
                        <button type="button" class="checkout-btn" disabled>Текущий</button>
                    <?php elseif ($optId === 'enterprise'): ?>
                        <a href="mailto:sales@labus.pro?subject=Enterprise%20plan%20interest" class="admin-checkout-btn">Связаться</a>
                    <?php else: ?>
                        <button type="button" class="checkout-btn billing-change-plan-btn" data-target-plan="<?= htmlspecialchars($optId) ?>">
                            <?= $optId === 'pro' && $planId === 'starter' ? 'Перейти' : ($optId === 'starter' ? 'Понизить' : 'Перейти') ?>
                        </button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
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
