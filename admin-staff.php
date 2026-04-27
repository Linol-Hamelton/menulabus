<?php
/**
 * admin-staff.php — managers schedule shifts, employees clock in/out,
 * owners compute tip splits for a pay period. (Phase 7.4)
 */

$required_role = 'employee';
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/db.php';

$db = Database::getInstance();
$role = (string)($_SESSION['user_role'] ?? '');
if (!in_array($role, ['employee', 'admin', 'owner'], true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}
$isManager = in_array($role, ['admin', 'owner'], true);

$userId = (int)($_SESSION['user_id'] ?? 0);
$openEntry = $userId > 0 ? $db->getOpenTimeEntry($userId) : null;

$from = (string)($_GET['from'] ?? date('Y-m-d', strtotime('-7 days')));
$to   = (string)($_GET['to']   ?? date('Y-m-d', strtotime('+1 day')));
$shifts = $db->listShifts($from . ' 00:00:00', $to . ' 00:00:00', null);
$staffUsers = $db->getAllUsers();

$recentSplits = $db->listTipSplits(10);

$siteName   = $GLOBALS['siteName'] ?? 'labus';
$appVersion = (string)($_SESSION['app_version'] ?? '1.0.0');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <title>Персонал | <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-staff.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion) ?>">
</head>
<body class="admin-page account-page"
      data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"
      data-is-manager="<?= $isManager ? '1' : '0' ?>">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>
    <?php require_once __DIR__ . '/account-header.php'; ?>

    <div class="account-container">
        <section class="account-section">
            <div class="section-header-menu">
                <h2>Мой табель</h2>
            </div>
            <p>Статус: <?= $openEntry
                ? '<strong>На смене</strong> с ' . htmlspecialchars((string)$openEntry['clocked_in_at'])
                : '<strong>Не на смене</strong>' ?></p>
            <div class="staff-timeclock">
                <?php if ($openEntry): ?>
                    <button type="button" class="checkout-btn" id="clockOutBtn">Закончить смену</button>
                <?php else: ?>
                    <button type="button" class="checkout-btn" id="clockInBtn">Начать смену</button>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($isManager): ?>
        <section class="account-section">
            <div class="section-header-menu">
                <h3>Смены</h3>
            </div>

            <form method="GET" class="staff-filter">
                <label>С <input type="date" name="from" value="<?= htmlspecialchars($from) ?>"></label>
                <label>По <input type="date" name="to" value="<?= htmlspecialchars($to) ?>"></label>
                <button type="submit" class="admin-checkout-btn">Показать</button>
            </form>

            <table class="staff-shifts-table" id="staffShiftsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Сотрудник</th>
                        <th>Роль</th>
                        <th>Начало</th>
                        <th>Конец</th>
                        <th>Комментарий</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($shifts as $s): ?>
                        <tr data-shift-id="<?= (int)$s['id'] ?>">
                            <td>#<?= (int)$s['id'] ?></td>
                            <td>
                                <select class="s-user">
                                    <option value="">—</option>
                                    <?php foreach ($staffUsers as $u): ?>
                                        <option value="<?= (int)$u['id'] ?>" <?= (int)($s['user_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string)($u['name'] ?? $u['email'] ?? ('#' . (int)$u['id']))) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" class="s-role" value="<?= htmlspecialchars((string)$s['role']) ?>" maxlength="32" data-w="lg"></td>
                            <td><input type="datetime-local" class="s-start" value="<?= htmlspecialchars(str_replace(' ', 'T', (string)$s['starts_at'])) ?>"></td>
                            <td><input type="datetime-local" class="s-end" value="<?= htmlspecialchars(str_replace(' ', 'T', (string)$s['ends_at'])) ?>"></td>
                            <td><input type="text" class="s-note" value="<?= htmlspecialchars((string)($s['note'] ?? '')) ?>" maxlength="255"></td>
                            <td>
                                <button type="button" class="admin-checkout-btn btn-s-save">Сохранить</button>
                                <button type="button" class="admin-checkout-btn cancel btn-s-delete">Удалить</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="staff-new-row" data-shift-id="">
                        <td>—</td>
                        <td>
                            <select class="s-user">
                                <option value="">—</option>
                                <?php foreach ($staffUsers as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars((string)($u['name'] ?? $u['email'] ?? ('#' . (int)$u['id']))) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="text" class="s-role" placeholder="waiter" maxlength="32" data-w="lg"></td>
                        <td><input type="datetime-local" class="s-start"></td>
                        <td><input type="datetime-local" class="s-end"></td>
                        <td><input type="text" class="s-note" placeholder="" maxlength="255"></td>
                        <td><button type="button" class="admin-checkout-btn btn-s-save">Создать</button></td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="account-section">
            <div class="section-header-menu">
                <h3>Расчёт чаевых</h3>
            </div>
            <p class="staff-tips-hint">
                Пул = сумма <code>tips</code> по оплаченным заказам за период. Распределяется
                пропорционально отработанным минутам. Результат можно сохранить в <code>tip_splits</code>.
            </p>
            <div class="staff-tips-form">
                <label>С <input type="datetime-local" id="tipsFrom" value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime('-7 days'))) ?>"></label>
                <label>По <input type="datetime-local" id="tipsTo" value="<?= htmlspecialchars(date('Y-m-d\TH:i')) ?>"></label>
                <button type="button" class="checkout-btn" id="tipsComputeBtn">Рассчитать</button>
                <button type="button" class="admin-checkout-btn" id="tipsSaveBtn" hidden>Сохранить распределение</button>
            </div>
            <div id="tipsResult" class="staff-tips-result"></div>
        </section>

        <section class="account-section">
            <div class="section-header-menu">
                <h3>История выплат</h3>
            </div>
            <?php if (empty($recentSplits)): ?>
                <p class="staff-empty">Распределений ещё не было.</p>
            <?php else: ?>
                <ul class="staff-splits-list">
                    <?php foreach ($recentSplits as $sp): ?>
                        <li>
                            <strong><?= htmlspecialchars((string)$sp['period_from']) ?> — <?= htmlspecialchars((string)$sp['period_to']) ?></strong>
                            · пул <?= number_format((float)$sp['tips_pool'], 2, '.', ' ') ?> ₽
                            <small>(создано <?= htmlspecialchars((string)$sp['created_at']) ?>)</small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </div>

    <script src="/js/security.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-staff.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>
