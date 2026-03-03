<?php
/**
 * qr-print.php — Print QR codes for table service
 *
 * GET /qr-print.php?count=N  — print N table QR codes (1–50)
 *
 * Requires employee/admin/owner role.
 */

$required_role = 'employee';
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/db.php';

$count = max(1, min(50, (int)($_GET['count'] ?? 10)));
$db    = Database::getInstance();

$appVersion  = htmlspecialchars($_SESSION['app_version'] ?? '1.0.0');
$siteName    = htmlspecialchars($GLOBALS['siteName'] ?? 'labus');
$scriptNonce = $_SESSION['csp_nonce'] ?? '';
$styleNonce  = $_SESSION['csp_nonce'] ?? '';

$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Печать QR-кодов столов | <?= $siteName ?></title>
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= $appVersion ?>">
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= $appVersion ?>">
    <link rel="stylesheet" href="/css/qr-print.css?v=<?= $appVersion ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= $appVersion ?>">
</head>
<body>
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>

    <main class="qr-print-container">
        <div class="qr-print-header no-print">
            <h1>QR-коды для столов</h1>
            <div class="qr-count-row">
                <label for="tableCount">Столов:</label>
                <input type="number" id="tableCount" class="qr-count-input"
                       value="<?= $count ?>" min="1" max="50">
                <a href="#" id="applyCount" class="checkout-btn">Применить</a>
                <button onclick="window.print()" class="checkout-btn">Распечатать</button>
                <a href="employee.php" class="checkout-btn cancel-btn">← Назад</a>
            </div>
        </div>

        <div class="qr-grid" id="qrGrid">
            <?php for ($i = 1; $i <= $count; $i++): ?>
            <?php
                $tableUrl = $baseUrl . '/menu.php?table=' . $i;
                $qrSrc    = '/qr.php?url=' . urlencode($tableUrl);
            ?>
            <div class="qr-item">
                <img src="<?= htmlspecialchars($qrSrc) ?>"
                     alt="QR стол <?= $i ?>"
                     loading="lazy"
                     width="150" height="150">
                <div class="qr-item-label">Стол <?= $i ?></div>
                <div class="qr-item-sub"><?= htmlspecialchars($siteName) ?></div>
            </div>
            <?php endfor; ?>
        </div>
    </main>

    <script nonce="<?= $scriptNonce ?>">
    document.getElementById('applyCount')?.addEventListener('click', function(e) {
        e.preventDefault();
        var n = parseInt(document.getElementById('tableCount').value, 10) || 10;
        n = Math.max(1, Math.min(50, n));
        window.location.href = '/qr-print.php?count=' + n;
    });
    </script>
</body>
</html>
