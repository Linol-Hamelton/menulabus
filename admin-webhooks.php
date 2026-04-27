<?php
/**
 * admin-webhooks.php — admin UI for outgoing webhook subscriptions.
 *
 * Read/write surface; CRUD goes through api/save-webhook.php.
 * See docs/webhook-integration.md for the event catalogue and HMAC recipe.
 */

$required_role = 'admin';
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/db.php';

$db = Database::getInstance();
$webhooks = $db->listWebhooks();
$siteName = $GLOBALS['siteName'] ?? 'labus';
$appVersion = $_SESSION['app_version'] ?? '1.0.0';

$knownEvents = [
    'order.created'              => 'Заказ создан',
    'reservation.created'        => 'Бронь создана',
    'reservation.confirmed'      => 'Бронь подтверждена',
    'reservation.seated'         => 'Гость рассажен',
    'reservation.cancelled'      => 'Бронь отменена',
    'reservation.no_show'        => 'Гость не пришёл',
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <title>Вебхуки | <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-webhooks.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion) ?>">
</head>
<body class="admin-page account-page" data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>
    <?php require_once __DIR__ . '/account-header.php'; ?>

    <div class="account-container">
        <section class="account-section">
            <div class="section-header-menu">
                <h2>Вебхуки</h2>
                <a href="/admin-menu.php" class="back-to-menu-btn">Назад в админку</a>
            </div>
            <p class="webhooks-intro">
                Подписывайте внешние сервисы (CRM, аналитику, POS) на события системы.
                Полная инструкция — в <code>docs/webhook-integration.md</code>.
            </p>

            <h3>Создать подписку</h3>
            <form id="webhookCreateForm" class="webhook-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
                <div class="form-row">
                    <label class="form-group">
                        <span class="form-label">Событие</span>
                        <select name="event_type" required>
                            <?php foreach ($knownEvents as $key => $label): ?>
                                <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?> (<?= htmlspecialchars($key) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-group">
                        <span class="form-label">URL получателя (HTTPS)</span>
                        <input type="url" name="target_url" required maxlength="512" placeholder="https://crm.example.com/webhooks/cleanmenu">
                    </label>
                </div>
                <label class="form-group">
                    <span class="form-label">Описание (необязательно)</span>
                    <input type="text" name="description" maxlength="255" placeholder="Например: amoCRM продакшн">
                </label>
                <div class="form-actions">
                    <button type="submit" class="checkout-btn">Создать</button>
                </div>
                <div id="webhookCreateMsg" class="webhook-msg" hidden></div>
            </form>

            <h3>Активные и архивные подписки</h3>
            <?php if (empty($webhooks)): ?>
                <p>Подписок пока нет.</p>
            <?php else: ?>
                <table class="webhooks-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Событие</th>
                            <th>URL</th>
                            <th>Описание</th>
                            <th>Активна</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($webhooks as $w): ?>
                            <tr data-webhook-id="<?= (int)$w['id'] ?>">
                                <td>#<?= (int)$w['id'] ?></td>
                                <td><code><?= htmlspecialchars((string)$w['event_type']) ?></code></td>
                                <td class="url-cell"><?= htmlspecialchars((string)$w['target_url']) ?></td>
                                <td><?= htmlspecialchars((string)($w['description'] ?? '')) ?></td>
                                <td>
                                    <button type="button" class="btn-toggle-active" data-active="<?= (int)$w['active'] ?>">
                                        <?= (int)$w['active'] === 1 ? 'Да' : 'Нет' ?>
                                    </button>
                                </td>
                                <td class="actions-cell">
                                    <button type="button" class="btn-history">История</button>
                                    <button type="button" class="btn-rotate">Сменить ключ</button>
                                    <button type="button" class="btn-delete btn-resv-danger">Удалить</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div id="webhookHistoryPanel" class="webhook-history" hidden>
                <h3>История доставок</h3>
                <div class="webhook-history-meta"></div>
                <table class="webhooks-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Событие</th>
                            <th>Статус</th>
                            <th>HTTP</th>
                            <th>Попыток</th>
                            <th>Создано</th>
                            <th>Доставлено</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </section>
    </div>

    <script src="/js/security.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-webhooks.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>
