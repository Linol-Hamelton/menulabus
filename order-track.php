<?php
require_once __DIR__ . '/session_init.php';

// No auth check — accessible to guests and logged-in users alike.
// Only exposes status and order composition, no personal data.

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
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

$isLoggedIn = !empty($_SESSION['user_id']);

// Map status → step index
$statusStepMap = [
    'принят'     => 0,
    'готовим'    => 1,
    'доставляем' => 2,
    'завершён'   => 3,
    'отказ'      => -1,
];
$status  = mb_strtolower(trim($order['status']));
$step    = $statusStepMap[$status] ?? 0;
$rejected = ($step === -1);
$done     = ($step === 3);

$deliveryType = $order['delivery_type'] ?? 'takeaway';

// Step 2 label changes by delivery type
$step2Label = in_array($deliveryType, ['delivery']) ? 'Доставляем' : 'Готов к выдаче';
// Phosphor SVG icons (20×20, fill="currentColor" inherits CSS color)
$si = fn(string $path, int $sz = 20) =>
    '<svg xmlns="http://www.w3.org/2000/svg" width="' . $sz . '" height="' . $sz .
    '" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="' . $path . '"/></svg>';

$svgCheck = $si('M229.66,77.66l-128,128a8,8,0,0,1-11.32,0l-56-56a8,8,0,0,1,11.32-11.32L96,188.69,218.34,66.34a8,8,0,0,1,11.32,11.32Z');
$svgFire  = $si('M143.38,17.23a8,8,0,0,0-12.63,3.27C128.3,26.98,117.06,60,104,76.67c-8,10.36-20,15.53-27.07,26.42-15.48,23.76-12.35,57.17,7.51,77.86a8.1,8.1,0,0,0,8.64,1.83A8,8,0,0,0,97.72,176a32.47,32.47,0,0,1,3.51-21.34c2.66,14.9,9.15,28.78,20,40a8,8,0,0,0,6,2.66,7.82,7.82,0,0,0,2.26-.33C183.06,183.26,208,160,208,128,208,89.69,175.68,43.89,143.38,17.23Z');
$svgTruck = $si('M247.42,117l-14-35A15.93,15.93,0,0,0,218.58,72H192V64a8,8,0,0,0-8-8H40A16,16,0,0,0,24,72V184a16,16,0,0,0,16,16H56a32,32,0,0,0,64,0h48a32,32,0,0,0,64,0h8a16,16,0,0,0,16-16V120A8,8,0,0,0,247.42,117ZM88,208a16,16,0,1,1,16-16A16,16,0,0,1,88,208Zm128,0a16,16,0,1,1,16-16A16,16,0,0,1,216,208ZM40,72H176v96H119.1A32.16,32.16,0,0,0,88,144a31.94,31.94,0,0,0-25,12H40Zm152,96V88h26.58l11.19,28Z');
$svgStar  = $si('M234.29,114.85l-45,38.83L203,211a16,16,0,0,1-23.84,17.71L128,199.45,76.84,228.7A16,16,0,0,1,53,211l13.76-57.32-45-38.83A16,16,0,0,1,31.08,86l58.64-5.91,22.72-56.16a16,16,0,0,1,29.12,0l22.72,56.16,58.64,5.91a16,16,0,0,1,9.37,28.86Zm-33.19-4.47-56.2-5.68a8,8,0,0,1-6.73-4.89L128,50.65l-21.82,56.16a8,8,0,0,1-6.73,4.89l-56.2,5.68,40.94,35.31a8,8,0,0,1,2.58,7.93L71.06,215.06l50.42-27.47a8,8,0,0,1,7.62,0l50.42,27.47-15.65-53.44a8,8,0,0,1,2.58-7.93Z');

$step2Icon  = in_array($deliveryType, ['delivery']) ? $svgTruck : $svgCheck;

$avgMinutes = $db->getAvgCompletionMinutes($deliveryType);

// Delivery-type display (16×16 icons for the small badge)
$deliveryLabels = [
    'delivery' => ['icon' => $si('M247.42,117l-14-35A15.93,15.93,0,0,0,218.58,72H192V64a8,8,0,0,0-8-8H40A16,16,0,0,0,24,72V184a16,16,0,0,0,16,16H56a32,32,0,0,0,64,0h48a32,32,0,0,0,64,0h8a16,16,0,0,0,16-16V120A8,8,0,0,0,247.42,117ZM88,208a16,16,0,1,1,16-16A16,16,0,0,1,88,208Zm128,0a16,16,0,1,1,16-16A16,16,0,0,1,216,208ZM40,72H176v96H119.1A32.16,32.16,0,0,0,88,144a31.94,31.94,0,0,0-25,12H40Zm152,96V88h26.58l11.19,28Z', 16), 'text' => 'Доставка'],
    'takeaway' => ['icon' => $si('M216,48H40A16,16,0,0,0,24,64V200a16,16,0,0,0,16,16H216a16,16,0,0,0,16-16V64A16,16,0,0,0,216,48ZM96,64h64v8a32,32,0,0,1-64,0ZM216,200H40V64H80v8a48,48,0,0,0,96,0V64h40Z', 16), 'text' => 'Самовывоз'],
    'table'    => ['icon' => $si('M224,56H32A16,16,0,0,0,16,72v16a16,16,0,0,0,16,16H40v96a8,8,0,0,0,16,0V104H200v96a8,8,0,0,0,16,0V104h8a16,16,0,0,0,16-16V72A16,16,0,0,0,224,56Zm0,32H32V72H224Z', 16), 'text' => 'На стол'],
    'bar'      => ['icon' => $si('M201.18,40H54.82a8,8,0,0,0-5.65,13.66L120,124.69V200H96a8,8,0,0,0,0,16h64a8,8,0,0,0,0-16H136V124.69l70.83-71A8,8,0,0,0,201.18,40Zm-20.57,16L128,108.69,75.39,56Z', 16), 'text' => 'К стойке бара'],
];
$deliveryInfo = $deliveryLabels[$deliveryType] ?? ['icon' => $si('M216,48H40A16,16,0,0,0,24,64V200a16,16,0,0,0,16,16H216a16,16,0,0,0,16-16V64A16,16,0,0,0,216,48ZM96,64h64v8a32,32,0,0,1-64,0ZM216,200H40V64H80v8a48,48,0,0,0,96,0V64h40Z', 16), 'text' => ucfirst($deliveryType)];

$details = is_array($order['delivery_details'])
    ? $order['delivery_details']
    : (json_decode($order['delivery_details'] ?? '{}', true) ?? []);

$createdAt = date('d.m.Y H:i', strtotime($order['created_at']));

$appVersion = htmlspecialchars($_SESSION['app_version'] ?? '1.0.0');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Заказ #<?= $orderId ?> — <?= htmlspecialchars($GLOBALS['siteName'] ?? 'labus') ?></title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= $appVersion ?>">
    <link rel="stylesheet" href="/css/order-track.css?v=<?= $appVersion ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= $appVersion ?>">
    <style nonce="<?= $styleNonce ?>">
        .track-step-circle{color:#9e9e9e}
        .track-step.rejected .track-step-circle{color:#fff}
        .track-rejected-banner--visible{display:flex;align-items:center;gap:8px}
        .track-created-at{text-align:center;font-size:12px;color:var(--light-text);margin-top:10px}
    </style>
</head>
<body class="track-page">

<!-- ── Header ── -->
<header class="track-header">
    <?php if ($isLoggedIn): ?>
    <a href="customer_orders.php" class="back-link" aria-label="Назад">&#8592;</a>
    <?php else: ?>
    <a href="index.php" class="back-link" aria-label="На главную">&#8592;</a>
    <?php endif; ?>
    <h1>Трекинг заказа</h1>
</header>

<!-- ── Main card ── -->
<main class="track-card">

    <!-- Order meta -->
    <div class="track-meta">
        <div class="track-order-id">Заказ <span>#<?= $orderId ?></span></div>
        <div class="track-delivery-label">
            <?= $deliveryInfo['icon'] ?>&nbsp;<?= htmlspecialchars($deliveryInfo['text']) ?>
            <?php if (!empty($details['table_number'])): ?>
                &nbsp;· Стол <?= htmlspecialchars($details['table_number']) ?>
            <?php elseif (!empty($details['address'])): ?>
                &nbsp;· <?= htmlspecialchars(mb_substr($details['address'], 0, 30)) ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Rejected banner -->
    <div class="track-rejected-banner <?= $rejected ? 'track-rejected-banner--visible' : '' ?>" id="rejectedBanner">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M205.66,194.34a8,8,0,0,1-11.32,11.32L128,139.31l-66.34,66.35a8,8,0,0,1-11.32-11.32L116.69,128,50.34,61.66A8,8,0,0,1,61.66,50.34L128,116.69l66.34-66.35a8,8,0,0,1,11.32,11.32L139.31,128Z"/><path d="M232,128A104,104,0,1,1,128,24,104.11,104.11,0,0,1,232,128Zm-16,0a88,88,0,1,0-88,88A88.1,88.1,0,0,0,216,128Z"/></svg>
        Заказ отменён. Свяжитесь с рестораном для уточнения деталей.
    </div>

    <!-- Progress stepper -->
    <div class="track-steps" id="trackSteps">
        <?php
        $steps = [
            ['icon' => $svgCheck, 'label' => 'Принят'],
            ['icon' => $svgFire,  'label' => 'Готовим'],
            ['icon' => $step2Icon, 'label' => $step2Label],
            ['icon' => $svgStar,  'label' => 'Получен'],
        ];
        foreach ($steps as $i => $s):
            if ($rejected) {
                $cls = ($i === 0) ? 'done' : ($i === 1 ? 'rejected' : '');
            } else {
                $cls = ($i < $step) ? 'done' : ($i === $step ? 'active' : '');
            }
        ?>
        <div class="track-step <?= $cls ?>" data-step="<?= $i ?>">
            <div class="track-step-circle"><?= $s['icon'] ?></div>
            <div class="track-step-label"
                 data-label-default="<?= htmlspecialchars($s['label']) ?>"
                 data-label-step2-delivery="Доставляем"
                 data-label-step2-pickup="Готов к выдаче">
                <?= htmlspecialchars($s['label']) ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Estimated time -->
    <div class="track-time-box" id="timeBox">
        <?php if ($done): ?>
            <div class="time-value done">Заказ получен!</div>
        <?php elseif ($rejected): ?>
            <div class="time-value error">Заказ отменён</div>
        <?php else: ?>
            <div class="time-label">Примерное время</div>
            <div class="time-value" id="timeValue">~<?= $avgMinutes ?> мин</div>
        <?php endif; ?>
    </div>

    <!-- Order items -->
    <div class="track-items-title">Состав заказа</div>
    <?php
    $items = is_array($order['items']) ? $order['items'] : [];
    foreach ($items as $item):
        $name  = htmlspecialchars($item['name'] ?? 'Блюдо');
        $qty   = (int)($item['quantity'] ?? 1);
        $price = number_format((float)($item['price'] ?? 0), 0, '.', ' ');
        $total = number_format((float)($item['price'] ?? 0) * $qty, 0, '.', ' ');
    ?>
    <div class="track-item">
        <div>
            <span class="track-item-name"><?= $name ?></span>
            <span class="track-item-qty">× <?= $qty ?></span>
        </div>
        <div class="track-item-price"><?= $total ?> ₽</div>
    </div>
    <?php endforeach; ?>

    <!-- Total -->
    <div class="track-total">
        <span class="track-total-label">Итого</span>
        <span class="track-total-value"><?= number_format((float)$order['total'], 0, '.', ' ') ?> ₽</span>
    </div>

    <div class="track-created-at">
        Создан: <?= $createdAt ?>
    </div>
</main>

<!-- ── CTA ── -->
<div class="track-cta">
    <?php if ($isLoggedIn): ?>
        <a href="customer_orders.php">Все мои заказы</a>
    <?php else: ?>
        <a href="index.php">На главную</a>
    <?php endif; ?>
</div>

<!-- ── Confetti container ── -->
<div class="track-confetti" id="confetti"></div>

<!-- ── Polling script ── -->
<script nonce="<?= $scriptNonce ?>">
(function () {
    var ORDER_ID     = <?= $orderId ?>;
    var POLL_MS      = 5000;
    var currentStep  = <?= $step ?>;
    var done         = <?= $done ? 'true' : 'false' ?>;
    var rejected     = <?= $rejected ? 'true' : 'false' ?>;
    var deliveryType = <?= json_encode($deliveryType) ?>;
    var pollTimer    = null;
    var SVG_TRUCK = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M247.42,117l-14-35A15.93,15.93,0,0,0,218.58,72H192V64a8,8,0,0,0-8-8H40A16,16,0,0,0,24,72V184a16,16,0,0,0,16,16H56a32,32,0,0,0,64,0h48a32,32,0,0,0,64,0h8a16,16,0,0,0,16-16V120A8,8,0,0,0,247.42,117ZM88,208a16,16,0,1,1,16-16A16,16,0,0,1,88,208Zm128,0a16,16,0,1,1,16-16A16,16,0,0,1,216,208ZM40,72H176v96H119.1A32.16,32.16,0,0,0,88,144a31.94,31.94,0,0,0-25,12H40Zm152,96V88h26.58l11.19,28Z"/></svg>';
    var SVG_CHECK = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M229.66,77.66l-128,128a8,8,0,0,1-11.32,0l-56-56a8,8,0,0,1,11.32-11.32L96,188.69,218.34,66.34a8,8,0,0,1,11.32,11.32Z"/></svg>';

    if (done || rejected) return; // nothing to poll

    function stepClass(stepEl, i, step, rej) {
        stepEl.classList.remove('done', 'active', 'rejected');
        if (rej) {
            if (i === 0) stepEl.classList.add('done');
            else if (i === 1) stepEl.classList.add('rejected');
        } else {
            if (i < step) stepEl.classList.add('done');
            else if (i === step) stepEl.classList.add('active');
        }
    }

    function applyState(data) {
        var newStep = data.step;
        var newRej  = (newStep === -1);
        var newDone = (newStep === 3);

        // Update step 2 label based on delivery_type
        var step2El = document.querySelector('[data-step="2"] .track-step-label');
        if (step2El) {
            var step2Circle = document.querySelector('[data-step="2"] .track-step-circle');
            if (data.delivery_type === 'delivery') {
                step2El.textContent = step2El.dataset.labelStep2Delivery;
                if (step2Circle) step2Circle.innerHTML = SVG_TRUCK;
            } else {
                step2El.textContent = step2El.dataset.labelStep2Pickup;
                if (step2Circle) step2Circle.innerHTML = SVG_CHECK;
            }
        }

        // Refresh step classes
        document.querySelectorAll('.track-step').forEach(function (el, i) {
            stepClass(el, i, newStep, newRej);
        });

        // Time box
        var timeBox = document.getElementById('timeBox');
        if (newDone) {
            timeBox.innerHTML = '<div class="time-value done">Заказ получен!</div>';
            launchConfetti();
            clearInterval(pollTimer);
        } else if (newRej) {
            timeBox.innerHTML = '<div class="time-value error">Заказ отменён</div>';
            document.getElementById('rejectedBanner').classList.add('track-rejected-banner--visible');
            clearInterval(pollTimer);
        } else {
            var elapsed = Math.floor(Date.now() / 1000 - data.created_at_ts);
            var remaining = Math.max(1, data.avg_minutes - Math.floor(elapsed / 60));
            document.getElementById('timeValue') &&
                (document.getElementById('timeValue').textContent = '~' + remaining + ' мин');
        }

        currentStep = newStep;
        done = newDone;
        rejected = newRej;
    }

    function poll() {
        fetch('/order-status.php?id=' + ORDER_ID, { cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) { if (data && !data.error) applyState(data); })
            .catch(function () { /* network error, retry next tick */ });
    }

    pollTimer = setInterval(poll, POLL_MS);

    // Confetti
    function launchConfetti() {
        var container = document.getElementById('confetti');
        container.style.display = 'block';
        var colors = ['#cd1719', '#ff9800', '#4caf50', '#2196f3', '#9c27b0', '#ffeb3b'];
        for (var i = 0; i < 60; i++) {
            (function () {
                var el = document.createElement('div');
                el.className = 'confetti-piece';
                el.style.left = Math.random() * 100 + '%';
                el.style.background = colors[Math.floor(Math.random() * colors.length)];
                el.style.animationDelay = (Math.random() * 1.5) + 's';
                el.style.transform = 'rotate(' + (Math.random() * 360) + 'deg)';
                el.style.width  = (8 + Math.random() * 8) + 'px';
                el.style.height = (8 + Math.random() * 8) + 'px';
                container.appendChild(el);
                setTimeout(function () { el.remove(); }, 4500);
            })();
        }
        setTimeout(function () { container.style.display = 'none'; }, 4500);
    }

    <?php if ($done): ?>launchConfetti();<?php endif; ?>
})();
</script>

</body>
</html>
