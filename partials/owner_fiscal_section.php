<?php
/**
 * partials/owner_fiscal_section.php — owner.php?tab=fiscal (Phase 13A.3, 2026-04-28).
 *
 * UI for configuring 54-ФЗ fiscalisation credentials. Reads/writes
 * tenant settings:
 *   fiscal_provider, fiscal_atol_login, fiscal_atol_password,
 *   fiscal_atol_group_code, fiscal_atol_inn, fiscal_atol_payment_address,
 *   fiscal_atol_sno, fiscal_atol_sandbox.
 *
 * The form posts to /api/save-fiscal-settings.php; a "Тест соединения"
 * button posts the same payload with ?test=1, which calls
 * AtolOnline::ensureToken() without saving and returns a
 * success/error JSON.
 *
 * Manual receipt re-emit: input order_id + button → POST
 * /api/save-fiscal-settings.php?reemit=<order_id>, which runs
 * cleanmenu_emit_fiscal_receipt for that legacy order.
 *
 * Expected $db is in scope from owner.php (Database::getInstance()).
 */

if (!isset($db) || !($db instanceof Database)) {
    $db = Database::getInstance();
}

$fp = (string)json_decode($db->getSetting('fiscal_provider') ?? '""', true);
$fl = (string)json_decode($db->getSetting('fiscal_atol_login') ?? '""', true);
$fg = (string)json_decode($db->getSetting('fiscal_atol_group_code') ?? '""', true);
$fi = (string)json_decode($db->getSetting('fiscal_atol_inn') ?? '""', true);
$fa = (string)json_decode($db->getSetting('fiscal_atol_payment_address') ?? '""', true);
$fs = (string)json_decode($db->getSetting('fiscal_atol_sno') ?? '"usn_income"', true);
$fb = (string)json_decode($db->getSetting('fiscal_atol_sandbox') ?? '"1"', true) === '1';
// password is intentionally not pre-filled — show a placeholder instead
$hasPw = (string)json_decode($db->getSetting('fiscal_atol_password') ?? '""', true) !== '';

$snoOptions = [
    'osn'                  => 'ОСН (общая)',
    'usn_income'           => 'УСН доходы',
    'usn_income_outcome'   => 'УСН доходы-расходы',
    'envd'                 => 'ЕНВД',
    'esn'                  => 'ЕСХН',
    'patent'               => 'Патент',
];
?>
<div class="owner-workspace-stack">
    <div class="owner-workspace-header">
        <div>
            <p class="owner-workspace-kicker">54-ФЗ</p>
            <h2>Фискальные чеки</h2>
        </div>
        <p class="owner-workspace-copy">
            Подключаем АТОЛ Онлайн для автоматической выписки чеков по каждому
            оплаченному заказу. Чек будет доступен покупателю по ссылке в
            личном кабинете и отправлен на email, если он указан.
        </p>
    </div>

    <div class="account-section fiscal-config-section">
        <form id="fiscalConfigForm" class="fiscal-config-form" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

            <fieldset>
                <legend>Провайдер</legend>
                <label>
                    <input type="radio" name="fiscal_provider" value=""    <?= $fp === ''     ? 'checked' : '' ?>>
                    Отключено
                </label>
                <label>
                    <input type="radio" name="fiscal_provider" value="atol" <?= $fp === 'atol' ? 'checked' : '' ?>>
                    АТОЛ Онлайн
                </label>
            </fieldset>

            <div class="fiscal-atol-fields" <?= $fp !== 'atol' ? 'hidden' : '' ?>>
                <label>
                    <span>Login</span>
                    <input type="text" name="fiscal_atol_login" value="<?= htmlspecialchars($fl, ENT_QUOTES) ?>" autocomplete="off">
                </label>
                <label>
                    <span>Password</span>
                    <input type="password" name="fiscal_atol_password" placeholder="<?= $hasPw ? '•••• (сохранён, перезапишите чтобы сменить)' : 'введите пароль' ?>" autocomplete="new-password">
                </label>
                <label>
                    <span>Group code</span>
                    <input type="text" name="fiscal_atol_group_code" value="<?= htmlspecialchars($fg, ENT_QUOTES) ?>">
                </label>
                <label>
                    <span>ИНН организации</span>
                    <input type="text" name="fiscal_atol_inn" value="<?= htmlspecialchars($fi, ENT_QUOTES) ?>" pattern="\d{10}|\d{12}" maxlength="12">
                </label>
                <label>
                    <span>Адрес кассы (URL или физический)</span>
                    <input type="text" name="fiscal_atol_payment_address" value="<?= htmlspecialchars($fa, ENT_QUOTES) ?>" placeholder="https://menu.labus.pro">
                </label>
                <label>
                    <span>Система налогообложения</span>
                    <select name="fiscal_atol_sno">
                        <?php foreach ($snoOptions as $code => $label): ?>
                            <option value="<?= htmlspecialchars($code) ?>" <?= $fs === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="fiscal-atol-sandbox">
                    <input type="checkbox" name="fiscal_atol_sandbox" value="1" <?= $fb ? 'checked' : '' ?>>
                    Sandbox-режим (testonline.atol.ru)
                </label>
            </div>

            <div class="fiscal-actions">
                <button type="button" class="checkout-btn" id="fiscalSaveBtn">Сохранить</button>
                <button type="button" class="admin-checkout-btn" id="fiscalTestBtn" <?= $fp !== 'atol' ? 'disabled' : '' ?>>
                    Тест соединения
                </button>
                <span class="fiscal-action-feedback" id="fiscalFeedback" hidden></span>
            </div>
        </form>
    </div>

    <div class="account-section fiscal-reemit-section">
        <h3>Выбить чек вручную</h3>
        <p class="fiscal-reemit-hint">Для legacy-заказов или если автоматический чек не выписался — указать ID заказа, нажать «Выбить».</p>
        <div class="fiscal-reemit-form">
            <label>
                <span>ID заказа</span>
                <input type="number" id="fiscalReemitOrderId" min="1" placeholder="например, 1234">
            </label>
            <button type="button" class="admin-checkout-btn" id="fiscalReemitBtn">Выбить чек</button>
            <span class="fiscal-action-feedback" id="fiscalReemitFeedback" hidden></span>
        </div>
    </div>
</div>

<script src="/js/owner-fiscal.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
