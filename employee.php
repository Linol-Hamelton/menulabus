<?php
ob_clean();
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';

// 1. Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    $_SESSION['auth_error'] = "Для доступа необходимо авторизоваться";
    header("Location: auth.php");
    exit;
}

$db = Database::getInstance();
$user = $db->getUserById($_SESSION['user_id']);

// 2. Проверка существования пользователя и активности
if (!$user || !$user['is_active']) {
    session_destroy();
    $_SESSION['auth_error'] = $user ? "Аккаунт деактивирован" : "Пользователь не найден";
    header("Location: auth.php");
    exit;
}

// 3. Проверка роли (строго по значениям из БД)
if (!in_array($user['role'], ['owner', 'admin', 'employee'], true)) {
    error_log("Access denied for user_id: {$_SESSION['user_id']}. Role: {$user['role']}");
    $_SESSION['auth_error'] = "У вас нет доступа к панели управления";
    header("Location: account.php");
    exit;
}

// Проверка доступности ЮKassa для генерации ссылок на оплату (после auth)
$paymentEnabled = json_decode($db->getSetting('yookassa_enabled') ?? '"false"', true) === 'true'
    && (json_decode($db->getSetting('yookassa_shop_id')    ?? '""', true) !== '')
    && (json_decode($db->getSetting('yookassa_secret_key') ?? '""', true) !== '');

// 4. Сохраняем только **после** проверки
$_SESSION['user'] = $user;
$_SESSION['user_role'] = $user['role'];

// Generate/refresh CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    $_SESSION['csrf_token_created'] = time();
}

// Rotate CSRF token every 24 hours for security
$csrfMaxAge = 24 * 3600; // 24 hours
if (($_SESSION['csrf_token_created'] ?? 0) + $csrfMaxAge < time()) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    $_SESSION['csrf_token_created'] = time();
}

$activeTab = $_COOKIE['activeOrderTab'] ?? 'Приём';

// Partial fetch for SSE refresh (reduces HTML size and prevents UI freezes on low-end devices).
if (($_GET['partial'] ?? '') === 'account-sections') {
    header('Content-Type: text/html; charset=utf-8');
    require __DIR__ . '/partials/employee_account_sections.php';
    exit;
}
// POST-обработка (профиль / пароль)
$errors = $successMessage = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = "Ошибка безопасности";
    } else {
        if (isset($_POST['update_profile'])) {
            $name = trim($_POST['name'] ?? '');
            if (strlen($name) < 2) {
                $errors[] = "Имя должно содержать минимум 2 символа";
            } elseif ($db->updateUser($_SESSION['user_id'], $name, trim($_POST['phone'] ?? ''))) {
                $_SESSION['user_name'] = $name;
                $successMessage = "Профиль обновлен";
                $user = $db->getUserById($_SESSION['user_id']);
            } else {
                $errors[] = "Ошибка при обновлении";
            }
        } elseif (isset($_POST['change_password'])) {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if (!password_verify($current, $user['password_hash'])) {
                $errors[] = "Текущий пароль неверен";
            } elseif (strlen($new) < 8 || $new !== $confirm) {
                $errors[] = "Новый пароль должен быть ≥8 символов и совпадать";
            } elseif ($db->updatePassword($_SESSION['user_id'], password_hash($new, PASSWORD_DEFAULT))) {
                $successMessage = "Пароль изменён";
            } else {
                $errors[] = "Ошибка при изменении пароля";
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="ru">

<head>
    

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="manifest" href="/manifest.php?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/employee-triage.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <title>Панель сотрудника | <?= htmlspecialchars($GLOBALS['siteName'] ?? 'labus') ?></title>

    <!-- Preloader - мгновенная загрузка -->
    
</head>

<body class="employee-page">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>
    <?php require_once __DIR__ . '/account-header.php'; ?>

    <div class="account-container">
        <?php if ($successMessage): ?>
            <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $e): ?>
                    <p><?= htmlspecialchars($e) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="menu-tabs-container">
            <div class="menu-tabs">
                <button class="tab-btn <?= $activeTab === 'Приём' ? 'active' : '' ?>" data-tab="Приём">Приём</button>
                <button class="tab-btn <?= $activeTab === 'готовим' ? 'active' : '' ?>" data-tab="готовим">Готовим</button>
                <button class="tab-btn <?= $activeTab === 'доставляем' ? 'active' : '' ?>" data-tab="доставляем">Доставляем</button>
                <button class="tab-btn <?= $activeTab === 'завершён' ? 'active' : '' ?>" data-tab="завершён">Завершено</button>
                <button class="tab-btn <?= $activeTab === 'отказ' ? 'active' : '' ?>" data-tab="отказ">Отказ</button>
                <button class="tab-btn <?= $activeTab === 'столы' ? 'active' : '' ?>" data-tab="столы">Столы</button>
            </div>
        </div>

        <?php require __DIR__ . '/partials/employee_account_sections.php'; ?>
    </div>
    <script src="/js/security.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/cart.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/account.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/employee-status-fix.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/employee-triage.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/ws-orders.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/push-notifications.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>

<?php if ($paymentEnabled): ?>
<!-- Модальное окно: ссылка на оплату от сотрудника -->
<div id="payLinkModal">
    <div class="modal-box">
        <button id="payLinkClose" class="btn-close-modal" aria-label="Закрыть">&times;</button>
        <h3>Ссылка на оплату</h3>
        <div id="payLinkSpinner" class="modal-spinner">Создаём ссылку…</div>
        <div id="payLinkContent">
            <p class="modal-hint">Покажите QR-код гостю или отправьте ссылку:</p>
            <div class="modal-qr">
                <img id="payLinkQr" src="" alt="QR код оплаты">
            </div>
            <div class="modal-url-row">
                <input id="payLinkUrl" type="text" readonly>
                <button id="payLinkCopy" class="btn-copy">Копировать</button>
            </div>
            <div id="payLinkCopyMsg" class="modal-copy-msg">Скопировано!</div>
        </div>
        <div id="payLinkError" class="modal-error"></div>
    </div>
</div>

<script nonce="<?= $scriptNonce ?>">
(function () {
    var modal     = document.getElementById('payLinkModal');
    var spinner   = document.getElementById('payLinkSpinner');
    var content   = document.getElementById('payLinkContent');
    var errorEl   = document.getElementById('payLinkError');
    var qrImg     = document.getElementById('payLinkQr');
    var urlInput  = document.getElementById('payLinkUrl');
    var copyBtn   = document.getElementById('payLinkCopy');
    var copyMsg   = document.getElementById('payLinkCopyMsg');
    var closeBtn  = document.getElementById('payLinkClose');
    var csrfToken = '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>';

    function showModal() {
        spinner.style.display  = 'block';
        content.style.display  = 'none';
        errorEl.style.display  = 'none';
        copyMsg.style.display  = 'none';
        modal.style.display    = 'flex';
    }

    function hideModal() { modal.style.display = 'none'; }

    function showError(msg) {
        spinner.style.display = 'none';
        content.style.display = 'none';
        errorEl.textContent   = msg;
        errorEl.style.display = 'block';
    }

    function showLink(url) {
        spinner.style.display = 'none';
        urlInput.value        = url;
        qrImg.src             = '/qr.php?url=' + encodeURIComponent(url);
        content.style.display = 'block';
    }

    // Event delegation — работает и после SSE-обновления DOM
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.pay-link-btn');
        if (!btn) return;
        var orderId = parseInt(btn.getAttribute('data-order-id'), 10);
        if (!orderId) return;

        showModal();

        fetch('/generate-payment-link.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ order_id: orderId })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success && data.paymentUrl) {
                showLink(data.paymentUrl);
            } else {
                showError(data.error || 'Не удалось создать ссылку');
            }
        })
        .catch(function () { showError('Ошибка сети. Попробуйте ещё раз.'); });
    });

    closeBtn.addEventListener('click', hideModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) hideModal(); });

    copyBtn.addEventListener('click', function () {
        var url = urlInput.value;
        if (!url) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () {
                copyMsg.style.display = 'block';
                setTimeout(function () { copyMsg.style.display = 'none'; }, 2000);
            });
        } else {
            urlInput.select();
            document.execCommand('copy');
            copyMsg.style.display = 'block';
            setTimeout(function () { copyMsg.style.display = 'none'; }, 2000);
        }
    });
})();
</script>
<?php endif; ?>
</body>

</html>
