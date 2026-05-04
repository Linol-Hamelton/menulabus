<?php
/**
 * onboarding.php — First-launch wizard for new restaurant owners.
 *
 * Step 1 : Enter restaurant name       (saved via api/save/brand.php)
 * Step 2 : Upload logo URL             (saved via api/save/brand.php)
 * Step 3 : Choose brand colors         (saved via api/save/colors.php)
 * Step 4 : Download / print QR code   (via qr-print.php)
 * Step 5 : Done → owner.php
 *
 * After completion writes onboarding_done=true to settings table
 * and redirects to owner.php.
 */

$required_role = 'owner';
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/require_auth.php';

$db = Database::getInstance();

// ── Mark onboarding complete (POST action=complete) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit;
    }
    $db->setSetting('onboarding_done', json_encode(true), (int)$_SESSION['user_id']);
    header('Location: /owner.php');
    exit;
}

// ── Already done → skip wizard ────────────────────────────────────────────────
$rawDone = $db->getSetting('onboarding_done');
if ($rawDone !== null && json_decode($rawDone, true) === true) {
    header('Location: /owner.php');
    exit;
}

// ── Wizard state ──────────────────────────────────────────────────────────────
$step     = max(1, min(5, (int)($_GET['step'] ?? 1)));
$siteName = htmlspecialchars($GLOBALS['siteName'] ?? 'labus');
$csrf     = $_SESSION['csrf_token'] ?? '';
$av       = htmlspecialchars($_SESSION['app_version'] ?? '1');

$protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$menuUrl   = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/menu.php?table=1';
$qrSrc     = '/qr.php?url=' . urlencode($menuUrl);
$qrDl      = '/qr.php?url=' . urlencode($menuUrl) . '&dl=1';

$currentPrimary   = json_decode($db->getSetting('color_primary-color')   ?? '"#cd1719"', true) ?? '#cd1719';
$currentSecondary = json_decode($db->getSetting('color_secondary-color') ?? '"#121212"', true) ?? '#121212';
$currentLogo      = json_decode($db->getSetting('logo_url') ?? '""', true) ?? '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Настройка ресторана</title>
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= $av ?>">
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= $av ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= $av ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= $av ?>">
    <link rel="stylesheet" href="/css/onboarding.css?v=<?= $av ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= $av ?>">
</head>
<body class="auth-page">
<?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>

<div class="ob-wrap">
    <div class="ob-dots">
        <?php for ($i = 1; $i <= 5; $i++): ?>
        <div class="ob-dot <?= $step === $i ? 'active' : '' ?>"></div>
        <?php endfor; ?>
    </div>
    <div class="ob-card">

        <!-- ── Шаг 1: название ресторана ──────────────────────────────────── -->
        <div class="ob-step <?= $step === 1 ? 'active' : '' ?>" id="step1">
            <div class="ob-icon"><i class="fas fa-utensils"></i></div>
            <div class="ob-title">Как называется ваш ресторан?</div>
            <div class="ob-sub">Это имя появится в заголовке, меню и PWA-иконке на телефоне гостя</div>
            <input type="text" class="ob-input" id="restaurantName"
                   placeholder="Например: Пиццерия Белла"
                   value="<?= $siteName !== 'labus' ? $siteName : '' ?>"
                   maxlength="100">
            <button class="ob-btn" id="nextBtn">Далее</button>
            <div class="ob-skip"><a href="?step=2">Пропустить этот шаг</a></div>
        </div>

        <!-- ── Шаг 2: логотип ─────────────────────────────────────────────── -->
        <div class="ob-step <?= $step === 2 ? 'active' : '' ?>" id="step2">
            <div class="ob-icon"><i class="fas fa-image"></i></div>
            <div class="ob-title">Добавьте логотип</div>
            <div class="ob-sub">Загрузите PNG через файл-менеджер и вставьте путь, или введите публичный URL изображения.</div>
            <input type="text" class="ob-input" id="obLogoUrl"
                   value="<?= htmlspecialchars($currentLogo) ?>"
                   placeholder="/images/logo.png или https://..."
                   maxlength="200">
            <img id="obLogoPreview" class="ob-logo-preview<?= $currentLogo ? ' visible' : '' ?>"
                 src="<?= htmlspecialchars($currentLogo) ?>" alt="Превью логотипа">
            <button class="ob-btn" id="logoNextBtn">Далее</button>
            <div class="ob-skip"><a href="?step=3">Пропустить этот шаг</a></div>
        </div>

        <!-- ── Шаг 3: цвета бренда ────────────────────────────────────────── -->
        <div class="ob-step <?= $step === 3 ? 'active' : '' ?>" id="step3">
            <div class="ob-icon"><i class="fas fa-palette"></i></div>
            <div class="ob-title">Настройте фирменные цвета</div>
            <div class="ob-sub">Эти цвета применятся по всему сайту — кнопки, акценты и фон</div>
            <div class="ob-colors-row">
                <div class="ob-color-item">
                    <label for="obPrimaryColor">Основной</label>
                    <input type="color" id="obPrimaryColor" class="ob-color-swatch"
                           value="<?= htmlspecialchars($currentPrimary) ?>">
                </div>
                <div class="ob-color-item">
                    <label for="obSecondaryColor">Фоновый</label>
                    <input type="color" id="obSecondaryColor" class="ob-color-swatch"
                           value="<?= htmlspecialchars($currentSecondary) ?>">
                </div>
            </div>
            <button class="ob-btn" id="colorsNextBtn">Сохранить и продолжить</button>
            <div class="ob-skip"><a href="?step=4">Пропустить этот шаг</a></div>
        </div>

        <!-- ── Шаг 4: QR-коды столов ──────────────────────────────────────── -->
        <div class="ob-step <?= $step === 4 ? 'active' : '' ?>" id="step4">
            <div class="ob-icon"><i class="fas fa-qrcode"></i></div>
            <div class="ob-title">QR-коды для столов</div>
            <div class="ob-sub">Распечатайте QR-коды для каждого столика — гость отсканирует и откроет ваше меню без ввода адреса</div>
            <div class="ob-qr">
                <img src="<?= htmlspecialchars($qrSrc) ?>" alt="QR-код меню стол 1" loading="lazy">
                <div class="ob-qr-url"><?= htmlspecialchars($menuUrl) ?></div>
            </div>
            <a href="/qr-print.php" class="ob-btn" target="_blank">
                <i class="fas fa-print"></i> Распечатать все QR-коды
            </a>
            <a href="<?= htmlspecialchars($qrDl) ?>" class="ob-btn ob-btn-ghost" download="menu-qr.png">
                <i class="fas fa-download"></i> Скачать QR для стола 1
            </a>
            <div class="ob-skip"><a href="?step=5">Продолжить без печати</a></div>
        </div>

        <!-- ── Шаг 5: завершение ──────────────────────────────────────────── -->
        <div class="ob-step <?= $step === 5 ? 'active' : '' ?>" id="step5">
            <div class="ob-icon"><i class="fas fa-check-circle"></i></div>
            <div class="ob-title">Всё готово!</div>
            <div class="ob-sub">Ваш ресторан настроен. Перейдите в панель владельца для управления меню и заказами.</div>
            <form method="POST">
                <input type="hidden" name="action" value="complete">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit" class="ob-btn">Приступить к работе</button>
            </form>
        </div>

    </div>
</div>

<script nonce="<?= $scriptNonce ?>">
const csrf = <?= json_encode($csrf) ?>;

async function postBrand(data) {
    return fetch('/api/save/brand.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ brand: data, csrf_token: csrf })
    });
}

async function saveNameAndNext() {
    const name = document.getElementById('restaurantName').value.trim();
    if (!name) { document.getElementById('restaurantName').focus(); return; }
    const btn = document.getElementById('nextBtn');
    btn.textContent = '...'; btn.disabled = true;
    try {
        await postBrand({ app_name: name });
        window.location.href = '?step=2';
    } catch (e) { btn.textContent = 'Далее'; btn.disabled = false; }
}

async function saveLogoAndNext() {
    const url = document.getElementById('obLogoUrl').value.trim();
    const btn = document.getElementById('logoNextBtn');
    btn.textContent = '...'; btn.disabled = true;
    try {
        if (url) await postBrand({ logo_url: url });
        window.location.href = '?step=3';
    } catch (e) { btn.textContent = 'Далее'; btn.disabled = false; }
}

async function saveColorsAndNext() {
    const primary   = document.getElementById('obPrimaryColor')?.value   || '#cd1719';
    const secondary = document.getElementById('obSecondaryColor')?.value || '#121212';
    const btn = document.getElementById('colorsNextBtn');
    btn.textContent = '...'; btn.disabled = true;
    try {
        await fetch('/api/save/colors.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({
                colors: { 'primary-color': primary, 'secondary-color': secondary },
                csrf_token: csrf
            })
        });
        window.location.href = '?step=4';
    } catch (e) { btn.textContent = 'Сохранить и продолжить'; btn.disabled = false; }
}

document.getElementById('obLogoUrl')?.addEventListener('input', function() {
    var img = document.getElementById('obLogoPreview');
    if (!img) return;
    if (this.value.trim()) { img.src = this.value; img.classList.add('visible'); }
    else { img.classList.remove('visible'); }
});

document.getElementById('nextBtn')?.addEventListener('click', saveNameAndNext);
document.getElementById('logoNextBtn')?.addEventListener('click', saveLogoAndNext);
document.getElementById('colorsNextBtn')?.addEventListener('click', saveColorsAndNext);
</script>
</body>
</html>
