<?php
/**
 * payment-return.php — landing page after ЮKassa redirect
 *
 * ЮKassa redirects the user here after payment (success or failure).
 * We verify the payment status via API and show the result.
 */

require_once __DIR__ . '/session_init.php';

// $db is set by session_init.php in web context; ensure it's always available
if (!isset($db)) {
    $db = Database::getInstance();
}

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($orderId <= 0) {
    header('Location: index.php');
    exit;
}

$order = $db->getOrderById($orderId);
if (!$order) {
    header('HTTP/1.1 404 Not Found');
    header('Location: index.php');
    exit;
}

// Authorization: a logged-in user may only view their own order's payment page.
// Guest orders (user_id = null) remain accessible by order ID, same as order tracking.
$sessionUserId = $_SESSION['user_id'] ?? null;
$orderUserId   = $order['user_id'] ?? null;
if ($sessionUserId !== null && $orderUserId !== null && (int)$sessionUserId !== (int)$orderUserId) {
    header('HTTP/1.1 403 Forbidden');
    header('Location: index.php');
    exit;
}

$paymentId     = $order['payment_id']     ?? '';
$paymentStatus = $order['payment_status'] ?? 'not_required';

// If we have a payment_id and status is still pending, verify via payment provider API
if ($paymentId !== '' && $paymentStatus === 'pending') {
    $payMethod = $order['payment_method'] ?? '';

    if ($payMethod === 'tbank_sbp') {
        // ── Verify via T-Bank GetState ────────────────────────────────────
        $tbKey  = json_decode($db->getSetting('tbank_terminal_key') ?? '""', true) ?? '';
        $tbPass = json_decode($db->getSetting('tbank_password')     ?? '""', true) ?? '';
        if ($tbKey !== '' && $tbPass !== '') {
            require_once __DIR__ . '/lib/TBank.php';
            $state     = tBankRequest('GetState', ['TerminalKey' => $tbKey, 'PaymentId' => $paymentId], $tbPass);
            $tbStatus  = $state['Status'] ?? '';
            if ($tbStatus === 'CONFIRMED') {
                $db->updateOrderPayment($orderId, $paymentId, 'paid', 'tbank_sbp');
                $paymentStatus = 'paid';
            } elseif (in_array($tbStatus, ['REJECTED', 'AUTH_FAIL', 'REVERSED'], true)) {
                $db->updateOrderPayment($orderId, $paymentId, 'cancelled', 'tbank_sbp');
                $paymentStatus = 'cancelled';
            }
        }
    } else {
        // ── Verify via ЮKassa API ─────────────────────────────────────────
        $shopId    = json_decode($db->getSetting('yookassa_shop_id')    ?? '""', true) ?? '';
        $secretKey = json_decode($db->getSetting('yookassa_secret_key') ?? '""', true) ?? '';

        if ($shopId !== '' && $secretKey !== '') {
            $ch = curl_init("https://api.yookassa.ru/v3/payments/" . urlencode($paymentId));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD        => "$shopId:$secretKey",
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 8,
            ]);
            $result  = curl_exec($ch);
            $apiCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($apiCode === 200 && $result) {
                $apiPayment = json_decode($result, true);
                $apiStatus  = $apiPayment['status'] ?? '';

                if ($apiStatus === 'succeeded') {
                    $db->updateOrderPayment($orderId, $paymentId, 'paid');
                    $paymentStatus = 'paid';
                } elseif ($apiStatus === 'canceled') {
                    $db->updateOrderPayment($orderId, $paymentId, 'cancelled');
                    $paymentStatus = 'cancelled';
                }
            }
        }
    }
}

// Redirect to order tracking after a short delay (JS handles the wait screen)
$siteName   = htmlspecialchars($GLOBALS['siteName'] ?? 'labus');
$appVersion = htmlspecialchars($_SESSION['app_version'] ?? '1.0.0');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Оплата заказа #<?= $orderId ?> — <?= $siteName ?></title>
    <link rel="stylesheet" href="/css/order-track.css?v=<?= $appVersion ?>">
    <style nonce="<?= $styleNonce ?>">
        .pay-result{text-align:center;padding:48px 24px}
        .pay-result .icon{display:flex;align-items:center;justify-content:center;margin-bottom:16px}
        .pay-result .icon.success{color:var(--agree,#4caf50)}
        .pay-result .icon.error{color:#e53935}
        .pay-result .icon.pending{color:var(--primary-color,#cd1719)}
        .pay-result h2{margin:0 0 8px;font-size:22px}
        .pay-result p{color:var(--light-text,#777);margin:0 0 24px}
        .pay-result a{display:inline-block;padding:12px 28px;background:var(--primary-color,#cd1719);color:#fff;border-radius:8px;text-decoration:none;font-weight:600}
    </style>
</head>
<body class="track-page">

<header class="track-header">
    <a href="order-track.php?id=<?= $orderId ?>" class="back-link" aria-label="К заказу">&#8592;</a>
    <h1>Результат оплаты</h1>
</header>

<main class="track-card">
    <div class="pay-result">
        <?php if ($paymentStatus === 'paid'): ?>
            <div class="icon success">
                <svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M173.66,98.34a8,8,0,0,1,0,11.32l-56,56a8,8,0,0,1-11.32,0l-24-24a8,8,0,0,1,11.32-11.32L112,148.69l50.34-50.35A8,8,0,0,1,173.66,98.34ZM232,128A104,104,0,1,1,128,24,104.11,104.11,0,0,1,232,128Zm-16,0a88,88,0,1,0-88,88A88.1,88.1,0,0,0,216,128Z"/></svg>
            </div>
            <h2>Оплата прошла успешно!</h2>
            <p>Заказ #<?= $orderId ?> принят. Мы уже начинаем его готовить.</p>
            <a href="order-track.php?id=<?= $orderId ?>">Следить за заказом</a>
        <?php elseif ($paymentStatus === 'cancelled'): ?>
            <div class="icon error">
                <svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M205.66,194.34a8,8,0,0,1-11.32,11.32L128,139.31l-66.34,66.35a8,8,0,0,1-11.32-11.32L116.69,128,50.34,61.66A8,8,0,0,1,61.66,50.34L128,116.69l66.34-66.35a8,8,0,0,1,11.32,11.32L139.31,128Z"/><path d="M232,128A104,104,0,1,1,128,24,104.11,104.11,0,0,1,232,128Zm-16,0a88,88,0,1,0-88,88A88.1,88.1,0,0,0,216,128Z"/></svg>
            </div>
            <h2>Оплата отменена</h2>
            <p>Платёж не был завершён. Заказ #<?= $orderId ?> отменён.</p>
            <a href="cart.php">Вернуться в корзину</a>
        <?php else: ?>
            <div class="icon pending">
                <svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M128,24A104,104,0,1,0,232,128,104.11,104.11,0,0,0,128,24Zm0,192a88,88,0,1,1,88-88A88.1,88.1,0,0,1,128,216Zm64-88a8,8,0,0,1-8,8H128a8,8,0,0,1-8-8V72a8,8,0,0,1,16,0v48h56A8,8,0,0,1,192,128Z"/></svg>
            </div>
            <h2>Проверяем оплату…</h2>
            <p>Пожалуйста, подождите. Страница обновится автоматически.</p>
            <a href="order-track.php?id=<?= $orderId ?>">Перейти к заказу</a>
        <?php endif; ?>
    </div>
</main>

<?php if ($paymentStatus === 'paid'): ?>
<script nonce="<?= $scriptNonce ?>">
    setTimeout(function() {
        window.location.href = 'order-track.php?id=<?= $orderId ?>';
    }, 3000);
</script>
<?php elseif ($paymentStatus === 'pending'): ?>
<script nonce="<?= $scriptNonce ?>">
    // Status still pending — retry after 3s
    setTimeout(function() {
        window.location.reload();
    }, 3000);
</script>
<?php endif; ?>

</body>
</html>
