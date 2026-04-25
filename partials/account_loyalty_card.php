<?php
/**
 * partials/account_loyalty_card.php — customer-facing loyalty widget.
 *
 * Rendered on /account.php?tab=profile when the user has any loyalty
 * activity (balance > 0, any lifetime spent, a tier, or history rows).
 * Hidden otherwise so brand-new customers don't see a zero-widget.
 *
 * Expects in scope:
 *   $loyaltyState   — Database::getUserLoyaltyState() result.
 *   $loyaltyHistory — last N loyalty_transactions for this user.
 */

$balance     = (float)($loyaltyState['points_balance'] ?? 0);
$tierName    = $loyaltyState['tier_name'] ?? null;
$cashbackPct = (float)($loyaltyState['tier_cashback_pct'] ?? 0);
$totalSpent  = (float)($loyaltyState['total_spent'] ?? 0);
$nextTier    = $loyaltyState['next_tier_name'] ?? null;
$nextTierAt  = $loyaltyState['next_tier_at'] ?? null;

$progressPct = 0.0;
$remaining   = 0.0;
if ($nextTier !== null && $nextTierAt !== null && $nextTierAt > 0) {
    $progressPct = min(100, max(0, round(($totalSpent / $nextTierAt) * 100)));
    $remaining   = max(0, $nextTierAt - $totalSpent);
}

$reasonLabels = [
    'accrual'  => 'Начисление',
    'redeem'   => 'Списание',
    'manual'   => 'Вручную',
    'expire'   => 'Сгорели',
    'birthday' => 'День рождения',
    'refund'   => 'Возврат',
];
?>
<section class="account-section loyalty-card">
    <div class="account-section-head">
        <div class="account-section-heading">
            <p class="account-section-kicker">Loyalty</p>
            <h2>Программа лояльности</h2>
        </div>
    </div>

    <div class="loyalty-card-grid">
        <div class="loyalty-stat loyalty-stat-balance">
            <span class="loyalty-stat-label">Баллы</span>
            <span class="loyalty-stat-value"><?= rtrim(rtrim(number_format($balance, 2, '.', ' '), '0'), '.') ?></span>
            <span class="loyalty-stat-hint">1 балл = 1 ₽ при оплате</span>
        </div>
        <div class="loyalty-stat loyalty-stat-tier">
            <span class="loyalty-stat-label">Уровень</span>
            <span class="loyalty-stat-value">
                <?= $tierName !== null ? htmlspecialchars((string)$tierName) : '—' ?>
            </span>
            <?php if ($cashbackPct > 0): ?>
                <span class="loyalty-stat-hint">Cashback <?= rtrim(rtrim(number_format($cashbackPct, 2, '.', ''), '0'), '.') ?>%</span>
            <?php else: ?>
                <span class="loyalty-stat-hint">Заказы копят прогресс до следующего уровня</span>
            <?php endif; ?>
        </div>
        <div class="loyalty-stat loyalty-stat-spent">
            <span class="loyalty-stat-label">Всего потрачено</span>
            <span class="loyalty-stat-value"><?= number_format($totalSpent, 0, '.', ' ') ?> ₽</span>
        </div>
    </div>

    <?php if ($nextTier !== null): ?>
        <div class="loyalty-progress">
            <div class="loyalty-progress-meta">
                <span>До уровня <strong><?= htmlspecialchars($nextTier) ?></strong></span>
                <span>осталось <?= number_format($remaining, 0, '.', ' ') ?> ₽</span>
            </div>
            <div class="loyalty-progress-bar">
                <div class="loyalty-progress-fill" data-progress="<?= $progressPct ?>" style="width: <?= $progressPct ?>%"></div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($loyaltyHistory)): ?>
        <h3 class="loyalty-history-title">История</h3>
        <ul class="loyalty-history">
            <?php foreach ($loyaltyHistory as $tx): ?>
                <?php
                $delta = (float)$tx['points_delta'];
                $sign  = $delta > 0 ? '+' : '';
                $label = $reasonLabels[(string)$tx['reason']] ?? (string)$tx['reason'];
                ?>
                <li class="loyalty-history-row <?= $delta > 0 ? 'loyalty-history-row-in' : 'loyalty-history-row-out' ?>">
                    <span class="loyalty-history-date"><?= htmlspecialchars((string)$tx['created_at']) ?></span>
                    <span class="loyalty-history-reason"><?= htmlspecialchars($label) ?>
                        <?php if (!empty($tx['order_id'])): ?>
                            · заказ #<?= (int)$tx['order_id'] ?>
                        <?php endif; ?>
                    </span>
                    <span class="loyalty-history-delta"><?= $sign ?><?= rtrim(rtrim(number_format($delta, 2, '.', ''), '0'), '.') ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
