<?php
/**
 * admin-marketing.php — owner / admin UI for email/push/Telegram campaigns (Phase 8.1).
 */

$required_role = 'admin';
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/db.php';

$db = Database::getInstance();
$campaigns = $db->listMarketingCampaigns(50);
$tiers     = $db->listLoyaltyTiers(false);

$siteName   = $GLOBALS['siteName'] ?? 'labus';
$appVersion = (string)($_SESSION['app_version'] ?? '1.0.0');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <title>Маркетинг | <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-marketing.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion) ?>">
</head>
<body class="admin-page account-page" data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>
    <?php require_once __DIR__ . '/account-header.php'; ?>

    <div class="account-container">
        <section class="account-section">
            <div class="section-header-menu">
                <h2>Маркетинг</h2>
                <a href="/admin-menu.php" class="back-to-menu-btn">К админке</a>
            </div>
            <p class="mk-intro">
                Создайте кампанию, выберите сегмент клиентов, отправьте.
                Запуск выполняет cron <code>scripts/marketing-worker.php</code>.
            </p>

            <h3>Новая кампания</h3>
            <div class="mk-form" id="mkCreateForm">
                <label>Название
                    <input type="text" id="mkName" maxlength="255" placeholder="Сезонный промо">
                </label>
                <label>Канал
                    <select id="mkChannel">
                        <option value="email">Email</option>
                        <option value="telegram">Telegram (общий чат)</option>
                        <option value="push">Push</option>
                    </select>
                </label>
                <label>Тема (для email)
                    <input type="text" id="mkSubject" maxlength="255">
                </label>
                <label>Текст
                    <textarea id="mkBodyText" rows="4" placeholder="Здравствуйте! Спасибо, что вы с нами…"></textarea>
                </label>
                <label>HTML (необязательно, для email)
                    <textarea id="mkBodyHtml" rows="4" placeholder="<p>…</p>"></textarea>
                </label>

                <fieldset class="mk-segment">
                    <legend>Сегмент</legend>
                    <label><input type="radio" name="mkSeg" value="all" checked> Все активные с email</label>
                    <label><input type="radio" name="mkSeg" value="min_orders"> Постоянные (≥ N заказов)
                        <input type="number" id="mkSegThreshold" value="3" min="1" max="100" data-w="2xs">
                    </label>
                    <label><input type="radio" name="mkSeg" value="loyalty_tier"> Тир лояльности
                        <select id="mkSegTier">
                            <option value="">—</option>
                            <?php foreach ($tiers as $t): ?>
                                <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars((string)$t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><input type="radio" name="mkSeg" value="birthday_today"> Сегодня день рождения</label>
                </fieldset>

                <div class="mk-actions">
                    <button type="button" class="checkout-btn" id="mkSaveBtn">Сохранить как черновик</button>
                </div>
                <div id="mkMsg" class="mk-msg" hidden></div>
            </div>
        </section>

        <section class="account-section">
            <h3>Кампании</h3>
            <?php if (empty($campaigns)): ?>
                <p class="mk-empty">Кампаний ещё не создавали.</p>
            <?php else: ?>
                <table class="mk-table" id="mkTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Название</th>
                            <th>Канал</th>
                            <th>Статус</th>
                            <th class="num-col">В очереди</th>
                            <th class="num-col">Доставлено</th>
                            <th>Создана</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($campaigns as $c): ?>
                            <tr data-campaign-id="<?= (int)$c['id'] ?>">
                                <td>#<?= (int)$c['id'] ?></td>
                                <td><?= htmlspecialchars((string)$c['name']) ?></td>
                                <td><?= htmlspecialchars((string)$c['channel']) ?></td>
                                <td><span class="mk-status mk-status-<?= htmlspecialchars((string)$c['status']) ?>"><?= htmlspecialchars((string)$c['status']) ?></span></td>
                                <td class="num-col"><?= (int)$c['sends_count'] ?></td>
                                <td class="num-col"><?= (int)$c['sent_count'] ?></td>
                                <td><?= htmlspecialchars((string)$c['created_at']) ?></td>
                                <td>
                                    <?php if (in_array($c['status'], ['draft', 'queued'], true)): ?>
                                        <button type="button" class="admin-checkout-btn btn-mk-queue">Поставить в очередь</button>
                                    <?php endif; ?>
                                    <?php if (in_array($c['status'], ['draft', 'queued', 'sending'], true)): ?>
                                        <button type="button" class="admin-checkout-btn cancel btn-mk-cancel">Отменить</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>

    <script src="/js/security.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-marketing.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>
