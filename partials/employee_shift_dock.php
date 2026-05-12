<?php
// partials/employee_shift_dock.php — Phase 35 (Кассовая смена).
//
// Renders a sticky "cashier dock" above the employee tabs.
// Expects: $db (Database instance), $user (current user row), $_SESSION['csrf_token'].
//
// Visibility: shown for owner/admin/employee on the employee page.
// Owners/admins act as supervisors; for them the dock surfaces whoever's shift is open.

if (!isset($db) || !isset($user)) {
    return;
}

$shiftRole = $user['role'] ?? '';
if (!in_array($shiftRole, ['owner', 'admin', 'employee'], true)) {
    return;
}

$ownShift = $db->getOpenShift((int)$user['id']);
$globalShift = $ownShift ?: $db->getAnyOpenShift();
$activeShift = $ownShift ?: $globalShift;

$report = null;
$cashierName = '';
if ($activeShift) {
    $report = $db->getShiftReport((int)$activeShift['id']);
    if (!empty($activeShift['cashier_id'])) {
        $cashier = $db->getUserById((int)$activeShift['cashier_id']);
        $cashierName = (string)($cashier['name'] ?? '');
        if ($cashierName === '') {
            $cashierName = (string)($cashier['email'] ?? '');
        }
    }
}

$canCloseShift = $activeShift
    && ($shiftRole === 'admin' || $shiftRole === 'owner'
        || (int)$activeShift['cashier_id'] === (int)$user['id']);
$canOpenShift = $activeShift === null && in_array($shiftRole, ['owner', 'admin', 'employee'], true);

$openedAtLabel = '';
if ($activeShift && !empty($activeShift['opened_at'])) {
    $ts = strtotime((string)$activeShift['opened_at']);
    if ($ts > 0) {
        $openedAtLabel = date('d.m H:i', $ts);
    }
}

$fmtMoney = static function ($value): string {
    return number_format((float)$value, 2, '.', ' ') . ' ₽';
};
?>
<section class="shift-dock" data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"
         data-shift-id="<?= $activeShift ? (int)$activeShift['id'] : '' ?>"
         data-shift-cashier-id="<?= $activeShift ? (int)$activeShift['cashier_id'] : '' ?>"
         data-current-user-id="<?= (int)$user['id'] ?>"
         data-current-user-role="<?= htmlspecialchars($shiftRole) ?>"
         aria-label="Кассовая смена">
    <?php if ($activeShift): ?>
        <div class="shift-dock-row shift-dock-row--open">
            <div class="shift-dock-status">
                <span class="shift-dock-dot" aria-hidden="true"></span>
                <span class="shift-dock-label">Смена открыта</span>
                <?php if ($cashierName !== ''): ?>
                    <span class="shift-dock-cashier">· <?= htmlspecialchars($cashierName) ?></span>
                <?php endif; ?>
                <?php if ($openedAtLabel !== ''): ?>
                    <span class="shift-dock-time">· с <?= htmlspecialchars($openedAtLabel) ?></span>
                <?php endif; ?>
            </div>
            <div class="shift-dock-metrics" role="group" aria-label="X-отчёт">
                <div class="shift-dock-metric">
                    <span class="shift-dock-metric-label">Размен</span>
                    <span class="shift-dock-metric-value"><?= $fmtMoney($activeShift['opening_cash'] ?? 0) ?></span>
                </div>
                <div class="shift-dock-metric">
                    <span class="shift-dock-metric-label">Выручка</span>
                    <span class="shift-dock-metric-value"><?= $fmtMoney($report['gross_total'] ?? 0) ?></span>
                </div>
                <div class="shift-dock-metric">
                    <span class="shift-dock-metric-label">Заказов</span>
                    <span class="shift-dock-metric-value"><?= (int)($report['orders_count'] ?? 0) ?></span>
                </div>
                <div class="shift-dock-metric">
                    <span class="shift-dock-metric-label">Возвраты</span>
                    <span class="shift-dock-metric-value"><?= $fmtMoney($report['refund_total'] ?? 0) ?></span>
                </div>
            </div>
            <div class="shift-dock-actions">
                <?php if ($canCloseShift): ?>
                    <button type="button" class="admin-checkout-btn js-shift-encash">Инкассация</button>
                    <button type="button" class="checkout-btn js-shift-close">Закрыть смену</button>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="shift-dock-row shift-dock-row--closed">
            <div class="shift-dock-status">
                <span class="shift-dock-dot shift-dock-dot--off" aria-hidden="true"></span>
                <span class="shift-dock-label">Смена закрыта</span>
                <span class="shift-dock-caption">Откройте смену, чтобы привязать к ней новые заказы и видеть X/Z отчёт.</span>
            </div>
            <div class="shift-dock-actions">
                <?php if ($canOpenShift): ?>
                    <button type="button" class="checkout-btn js-shift-open">Открыть смену</button>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>

<!-- Modal: open shift -->
<dialog id="shiftOpenModal" class="design-modal" aria-labelledby="shiftOpenModalTitle">
    <div class="modal-card">
        <header class="modal-head">
            <div>
                <h2 id="shiftOpenModalTitle" class="modal-title">Открыть кассовую смену</h2>
                <p class="modal-subtitle">Введите сумму размена, которую вы кладёте в кассу.</p>
            </div>
            <button type="button" class="modal-close" data-shift-modal-close aria-label="Закрыть">×</button>
        </header>
        <form class="modal-body inv-edit-form" id="shiftOpenForm">
            <label class="inv-edit-field">
                <span class="inv-edit-label">Размен, ₽</span>
                <input type="number" step="0.01" min="0" id="shiftOpenCash" value="0" required>
            </label>
            <label class="inv-edit-field">
                <span class="inv-edit-label">Примечание</span>
                <input type="text" id="shiftOpenNotes" maxlength="500" placeholder="необязательно">
            </label>
            <div id="shiftOpenMsg" class="recipe-save-msg" hidden></div>
        </form>
        <footer class="modal-foot">
            <button type="button" class="admin-checkout-btn cancel" data-shift-modal-close>Отмена</button>
            <button type="button" class="checkout-btn" id="shiftOpenSubmit">Открыть</button>
        </footer>
    </div>
</dialog>

<!-- Modal: close shift -->
<dialog id="shiftCloseModal" class="design-modal" aria-labelledby="shiftCloseModalTitle">
    <div class="modal-card">
        <header class="modal-head">
            <div>
                <h2 id="shiftCloseModalTitle" class="modal-title">Закрыть смену (Z-отчёт)</h2>
                <p class="modal-subtitle">Сверьте факт наличных с ожидаемой суммой и завершите смену.</p>
            </div>
            <button type="button" class="modal-close" data-shift-modal-close aria-label="Закрыть">×</button>
        </header>
        <div class="modal-body">
            <div class="shift-zreport" id="shiftZReport" aria-live="polite">
                <div class="shift-zreport-row">
                    <span class="shift-zreport-label">Заказов</span>
                    <span class="shift-zreport-value" data-z="orders_count">—</span>
                </div>
                <div class="shift-zreport-row">
                    <span class="shift-zreport-label">Выручка (gross)</span>
                    <span class="shift-zreport-value" data-z="gross_total">—</span>
                </div>
                <div class="shift-zreport-row">
                    <span class="shift-zreport-label">Чаевые</span>
                    <span class="shift-zreport-value" data-z="tips_total">—</span>
                </div>
                <div class="shift-zreport-row">
                    <span class="shift-zreport-label">Возвраты</span>
                    <span class="shift-zreport-value" data-z="refund_total">—</span>
                </div>
                <div class="shift-zreport-row">
                    <span class="shift-zreport-label">Инкассация</span>
                    <span class="shift-zreport-value" data-z="encashment_total">—</span>
                </div>
                <div class="shift-zreport-row shift-zreport-row--accent">
                    <span class="shift-zreport-label">Ожидаемые наличные</span>
                    <span class="shift-zreport-value" data-z="expected_cash">—</span>
                </div>
            </div>
            <form class="inv-edit-form" id="shiftCloseForm">
                <label class="inv-edit-field">
                    <span class="inv-edit-label">Факт наличных в кассе, ₽</span>
                    <input type="number" step="0.01" min="0" id="shiftCloseCash" value="0" required>
                </label>
                <label class="inv-edit-field">
                    <span class="inv-edit-label">Примечание</span>
                    <input type="text" id="shiftCloseNotes" maxlength="500" placeholder="необязательно">
                </label>
                <div id="shiftCloseMsg" class="recipe-save-msg" hidden></div>
            </form>
        </div>
        <footer class="modal-foot">
            <button type="button" class="admin-checkout-btn cancel" data-shift-modal-close>Отмена</button>
            <button type="button" class="checkout-btn" id="shiftCloseSubmit">Закрыть смену</button>
        </footer>
    </div>
</dialog>

<!-- Modal: encashment (inkassatsiya) -->
<dialog id="shiftEncashModal" class="design-modal" aria-labelledby="shiftEncashModalTitle">
    <div class="modal-card">
        <header class="modal-head">
            <div>
                <h2 id="shiftEncashModalTitle" class="modal-title">Инкассация</h2>
                <p class="modal-subtitle">Зафиксируйте сумму, которую сняли из кассы.</p>
            </div>
            <button type="button" class="modal-close" data-shift-modal-close aria-label="Закрыть">×</button>
        </header>
        <form class="modal-body inv-edit-form" id="shiftEncashForm">
            <label class="inv-edit-field">
                <span class="inv-edit-label">Сумма, ₽</span>
                <input type="number" step="0.01" min="0.01" id="shiftEncashAmount" value="0" required>
            </label>
            <label class="inv-edit-field">
                <span class="inv-edit-label">Причина</span>
                <select id="shiftEncashReason">
                    <option value="bank_deposit">В банк</option>
                    <option value="safe">В сейф</option>
                    <option value="other" selected>Другое</option>
                </select>
            </label>
            <div id="shiftEncashMsg" class="recipe-save-msg" hidden></div>
        </form>
        <footer class="modal-foot">
            <button type="button" class="admin-checkout-btn cancel" data-shift-modal-close>Отмена</button>
            <button type="button" class="checkout-btn" id="shiftEncashSubmit">Зафиксировать</button>
        </footer>
    </div>
</dialog>

<!-- Modal: refund (чек коррекции) — opened from order cards via js-refund-trigger -->
<dialog id="orderRefundModal" class="design-modal" aria-labelledby="orderRefundModalTitle">
    <div class="modal-card">
        <header class="modal-head">
            <div>
                <h2 id="orderRefundModalTitle" class="modal-title">Возврат / чек коррекции</h2>
                <p class="modal-subtitle" id="orderRefundSubtitle">—</p>
            </div>
            <button type="button" class="modal-close" data-refund-modal-close aria-label="Закрыть">×</button>
        </header>
        <form class="modal-body inv-edit-form" id="orderRefundForm">
            <input type="hidden" id="orderRefundOrderId" value="">
            <input type="hidden" id="orderRefundOrderTotal" value="0">
            <div class="refund-mode">
                <label class="refund-mode-option">
                    <input type="radio" name="refundMode" value="full" checked>
                    <span>Полный возврат</span>
                </label>
                <label class="refund-mode-option">
                    <input type="radio" name="refundMode" value="partial">
                    <span>Частичный</span>
                </label>
            </div>
            <label class="inv-edit-field" id="orderRefundAmountWrap" hidden>
                <span class="inv-edit-label">Сумма возврата, ₽</span>
                <input type="number" step="0.01" min="0.01" id="orderRefundAmount" value="0">
            </label>
            <label class="inv-edit-field">
                <span class="inv-edit-label">Причина</span>
                <input type="text" id="orderRefundReason" maxlength="255" placeholder="напр. брак / отказ клиента">
            </label>
            <div id="orderRefundMsg" class="recipe-save-msg" hidden></div>
        </form>
        <footer class="modal-foot">
            <button type="button" class="admin-checkout-btn cancel" data-refund-modal-close>Отмена</button>
            <button type="button" class="checkout-btn" id="orderRefundSubmit">Оформить возврат</button>
        </footer>
    </div>
</dialog>
