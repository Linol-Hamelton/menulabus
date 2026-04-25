<?php
/**
 * group.php — shared-tab page for guests at one physical table (Phase 8.3).
 *
 * Two modes:
 *   ?action=new         → host creates a new group, redirects to ?code=<generated>
 *   ?code=<token>       → joined view: pick seat label, add items, remove,
 *                         host can submit the whole tab.
 *
 * Open to any session (customer or guest). CSRF is checked on mutating actions
 * through api/save-group-order.php.
 */

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';

$db = Database::getInstance();
$siteName   = $GLOBALS['siteName'] ?? 'labus';
$appVersion = (string)($_SESSION['app_version'] ?? '1.0.0');
$csrfToken  = $_SESSION['csrf_token'] ?? '';

$action = (string)($_GET['action'] ?? '');
$codeIn = trim((string)($_GET['code'] ?? ''));
$group  = null;

if ($action === 'new') {
    $tableLabel = isset($_GET['table']) ? trim((string)$_GET['table']) : null;
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $created = $db->createGroupOrder($userId, $tableLabel, null);
    if ($created) {
        header('Location: /group.php?code=' . urlencode($created['code']));
        exit;
    }
    http_response_code(500);
    echo 'Failed to create group';
    exit;
}

if ($codeIn !== '') {
    $group = $db->getGroupOrderByCode($codeIn);
}

$items = $group ? $db->getGroupOrderItems((int)$group['id']) : [];
$menuItems = $db->getMenuItems(null, true);

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$isHost = $group && $userId !== null && (int)($group['host_user_id'] ?? 0) === $userId;
$isGuestFlow = $group && $group['host_user_id'] === null;
$canSubmit = $group && $group['status'] === 'open' && ($isHost || $isGuestFlow);

// Seat sticky cookie so a refreshing guest doesn't lose "who they are".
$seatCookie = $_COOKIE['cleanmenu_group_seat'] ?? '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
    <title>Общий заказ · <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/group-order.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion) ?>">
</head>
<body class="group-page" data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>

    <div class="account-container">
        <?php if (!$group): ?>
            <section class="account-section">
                <h2>Общий заказ на столе</h2>
                <p>Откройте общий заказ — поделитесь ссылкой с гостями за вашим столом. Каждый добавляет свои блюда, в конце один чек (или разделим поровну).</p>
                <div class="group-create-form">
                    <label>Номер / название стола (необязательно)
                        <input type="text" id="gTable" maxlength="64" placeholder="5 или «у окна»">
                    </label>
                    <button type="button" class="checkout-btn" id="gCreateBtn">Создать общий заказ</button>
                </div>
                <form method="GET" class="group-join-form" action="/group.php">
                    <label>Или введите код от хоста
                        <input type="text" name="code" maxlength="16" required pattern="[a-z0-9_-]{3,16}">
                    </label>
                    <button type="submit" class="admin-checkout-btn">Присоединиться</button>
                </form>
            </section>
        <?php else: ?>
            <section class="account-section group-live" data-group-code="<?= htmlspecialchars((string)$group['code']) ?>" data-group-id="<?= (int)$group['id'] ?>">
                <h2>Общий заказ <?= htmlspecialchars((string)$group['code']) ?></h2>
                <p class="group-subtitle">
                    Стол: <strong><?= htmlspecialchars((string)($group['table_label'] ?? '—')) ?></strong>
                    · Статус: <strong><?= htmlspecialchars((string)$group['status']) ?></strong>
                </p>

                <?php if ($group['status'] === 'open'): ?>
                    <div class="group-add-form">
                        <label>Ваше имя / место
                            <input type="text" id="gSeat" maxlength="64" value="<?= htmlspecialchars($seatCookie, ENT_QUOTES) ?>" placeholder="Маша / Seat 3" required>
                        </label>
                        <label>Блюдо
                            <select id="gMenuItem">
                                <option value="">Выбрать…</option>
                                <?php foreach ($menuItems as $mi): ?>
                                    <option value="<?= (int)$mi['id'] ?>"><?= htmlspecialchars((string)$mi['name']) ?> — <?= number_format((float)$mi['price'], 0, '.', ' ') ?> ₽</option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Кол-во
                            <input type="number" id="gQty" min="1" max="20" value="1" style="width: 70px">
                        </label>
                        <button type="button" class="checkout-btn" id="gAddBtn">Добавить</button>
                    </div>
                <?php endif; ?>

                <h3>В заказе</h3>
                <?php if (empty($items)): ?>
                    <p class="group-empty">Ещё ничего не добавлено.</p>
                <?php else: ?>
                    <?php
                    $bySeat = [];
                    $totalSum = 0;
                    foreach ($items as $it) {
                        $bySeat[(string)$it['seat_label']][] = $it;
                        $totalSum += (int)$it['quantity'] * (float)$it['unit_price'];
                    }
                    ?>
                    <?php foreach ($bySeat as $seat => $seatItems): ?>
                        <div class="group-seat">
                            <h4><?= htmlspecialchars($seat) ?></h4>
                            <ul class="group-seat-items">
                                <?php foreach ($seatItems as $si): ?>
                                    <li data-item-row-id="<?= (int)$si['id'] ?>">
                                        <span class="group-seat-name"><?= htmlspecialchars((string)$si['item_name']) ?> × <?= (int)$si['quantity'] ?></span>
                                        <span class="group-seat-price"><?= number_format((int)$si['quantity'] * (float)$si['unit_price'], 0, '.', ' ') ?> ₽</span>
                                        <?php if ($group['status'] === 'open'): ?>
                                            <button type="button" class="group-del">×</button>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                    <div class="group-total">Итого: <strong><?= number_format($totalSum, 0, '.', ' ') ?> ₽</strong></div>
                <?php endif; ?>

                <?php if ($canSubmit && !empty($items)): ?>
                    <div class="group-submit">
                        <label>
                            <input type="radio" name="gMode" value="single" checked>
                            Один общий чек
                        </label>
                        <label>
                            <input type="radio" name="gMode" value="per_seat">
                            Разделить: отдельный чек на каждого
                        </label>
                        <button type="button" class="checkout-btn" id="gSubmitBtn">Отправить на кухню</button>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>

    <script src="/js/security.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/group-order.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>
