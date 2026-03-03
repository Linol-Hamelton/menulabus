<?php
// Начало сессии с безопасными настройками
require_once __DIR__ . '/session_init.php';

$db = Database::getInstance();
$paymentEnabled = json_decode($db->getSetting('yookassa_enabled') ?? '"false"', true) === 'true';
$paymentEnabled = $paymentEnabled
    && (json_decode($db->getSetting('yookassa_shop_id')    ?? '""', true) !== '')
    && (json_decode($db->getSetting('yookassa_secret_key') ?? '""', true) !== '');

// T-Bank SBP
$tbEnabled   = json_decode($db->getSetting('tbank_enabled')      ?? '"false"', true) === 'true';
$tbTermKey   = json_decode($db->getSetting('tbank_terminal_key') ?? '""', true) ?? '';
$tbankActive = $tbEnabled && $tbTermKey !== '';
// СБП кнопка: если T-Bank включён — маршрутизируем туда, иначе через ЮКасса
$sbpMethod  = $tbankActive ? 'tbank_sbp' : 'sbp';
$sbpVisible = $tbankActive || $paymentEnabled;
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="manifest" href="/manifest.php?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?? '' ?>">
    <meta http-equiv="Permissions-Policy" content="camera=(self 'https://menu.labus.pro'), microphone=()">
    <meta http-equiv="Content-Security-Policy" content="media-src 'self' blob:;">
    <title><?= htmlspecialchars($GLOBALS['siteName'] ?? 'labus') ?> | Заказ</title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/menu-alt.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <style nonce="<?= $styleNonce ?>">
        /* Payment block */
        .payment-method-block{margin-top:16px}
        .payment-method-label{font-weight:600;margin-bottom:8px;display:block;color:var(--light-text)}
        .payment-options-gap{gap:8px}
        /* Tips: override grid for narrow screens if needed */
        @media(max-width:380px){.tips-options{grid-template-columns:repeat(3,1fr)}}
        /* Delivery type options: icon stacked above label */
        .delivery-option{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px;line-height:1.2;font-size:13px}
        .delivery-option svg{flex-shrink:0;display:block}
        /* Payment options: icon + label side by side */
        .payment-option{display:flex;flex-direction:row;align-items:center;justify-content:center;gap:7px;font-size:13px}
        .payment-option svg{flex-shrink:0;display:block}
        /* Scan button layout */
        .scan-btn{display:flex;align-items:center;justify-content:center;gap:10px}
        .scan-btn svg{flex-shrink:0}
        /* Location button */
        .detect-location-btn{line-height:0;display:flex;align-items:center;justify-content:center}
    </style>
</head>

<body id="body" data-is-logged-in="<?= isset($_SESSION['user_id']) ? 'true' : 'false' ?>">
    <?php
    // Вставка JavaScript для повторения заказа
    if (isset($_SESSION['repeat_order_js'])) {
        echo $_SESSION['repeat_order_js'];
        unset($_SESSION['repeat_order_js']);
    }
    ?>
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>
    <section id="menu" class="section menu">
        <div class="container">
            <div class="section-header-menu">
                <h2>Заказ</h2>
                <a href="menu.php" class="back-to-menu-btn">В меню</a>
            </div>
            <div id="cart-items-container" class="menu-content">
                <div class="empty-cart">
                    <p>Ваша корзина пуста</p>
                </div>
            </div>

            <div class="clear-cart-container">
                <button id="clear-cart-btn" class="checkout-btn">Очистить</button>
                <button id="checkout-btn" class="checkout-btn" disabled>Заказать</button>
            </div>
            <div id="cart-total" class="cart-total">
                Итого: 0 ₽
            </div>
            <div class="cart-summary-container">
                <div id="nutrition-summary" class="nutrition-summary"></div>
            </div>
        </div>
    </section>

    <!-- Модальное окно выбора типа доставки (измененная версия) -->
    <div id="deliveryModal" class="delivery">
        <div class="delivery-content">
            <h3>Выберите тип получения заказа</h3>
            <div class="delivery-options">
                <div class="delivery-option" data-type="bar">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M201.18,40H54.82a8,8,0,0,0-5.65,13.66L120,124.69V200H96a8,8,0,0,0,0,16h64a8,8,0,0,0,0-16H136V124.69l70.83-71A8,8,0,0,0,201.18,40Zm-20.57,16L128,108.69,75.39,56Z"/></svg>
                    На бар
                </div>
                <div class="delivery-option" data-type="table">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M224,56H32A16,16,0,0,0,16,72v16a16,16,0,0,0,16,16H40v96a8,8,0,0,0,16,0V104H200v96a8,8,0,0,0,16,0V104h8a16,16,0,0,0,16-16V72A16,16,0,0,0,224,56Zm0,32H32V72H224Z"/></svg>
                    За стол
                </div>
                <div class="delivery-option" data-type="takeaway">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M216,48H40A16,16,0,0,0,24,64V200a16,16,0,0,0,16,16H216a16,16,0,0,0,16-16V64A16,16,0,0,0,216,48ZM96,64h64v8a32,32,0,0,1-64,0ZM216,200H40V64H80v8a48,48,0,0,0,96,0V64h40Z"/></svg>
                    На вынос
                </div>
                <div class="delivery-option" data-type="delivery">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M247.42,117l-14-35A15.93,15.93,0,0,0,218.58,72H192V64a8,8,0,0,0-8-8H40A16,16,0,0,0,24,72V184a16,16,0,0,0,16,16H56a32,32,0,0,0,64,0h48a32,32,0,0,0,64,0h8a16,16,0,0,0,16-16V120A8,8,0,0,0,247.42,117ZM88,208a16,16,0,1,1,16-16A16,16,0,0,1,88,208Zm128,0a16,16,0,1,1,16-16A16,16,0,0,1,216,208ZM40,72H176v96H119.1A32.16,32.16,0,0,0,88,144a31.94,31.94,0,0,0-25,12H40Zm152,96V88h26.58l11.19,28Z"/></svg>
                    Доставка
                </div>
            </div>

            <!-- Блок для адреса доставки -->
            <div id="deliveryAddressBlock" class="delivery-extra-block hidden">
                <label for="deliveryAddress">Укажите адрес:</label>
                <div class="address-input-wrapper">
                    <input type="text" id="deliveryAddress" placeholder="Введите адрес доставки" autocomplete="address-line1">
                    <button type="button" id="detectLocationBtn" class="detect-location-btn" title="Определить автоматически"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M128,16a72,72,0,0,0-72,72c0,48.1,72,152,72,152s72-103.9,72-152A72,72,0,0,0,128,16Zm0,104a32,32,0,1,1,32-32A32,32,0,0,1,128,120Z"/></svg></button>
                </div>
                <div class="address-suggestions hidden"></div>
                <small class="location-permission-hint hidden">Для определения местоположения разрешите доступ к геоданным.</small>
            </div>

            <!-- Блок для сканирования QR-кода -->
            <div id="tableQrBlock" class="delivery-extra-block hidden">
                                <div class="project-name-control">
                    <input type="number" id="manualTableNumber" placeholder="№ стола" min="1" class="form-group" inputmode="numeric">
                    <button type="button" id="manualTableBtn" class="checkout-btn">Ок</button>
                </div>

                <label>Отсканируйте QR-код или введите номер стола:</label>
                <div id="qr-scanner-container" class="qr-scanner-container"></div>
                <button type="button" id="scanQrBtn" class="scan-btn"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M208,56H180.28L166.65,35.56A8,8,0,0,0,160,32H96a8,8,0,0,0-6.65,3.56L75.72,56H48A24,24,0,0,0,24,80V192a24,24,0,0,0,24,24H208a24,24,0,0,0,24-24V80A24,24,0,0,0,208,56Zm8,136a8,8,0,0,1-8,8H48a8,8,0,0,1-8-8V80a8,8,0,0,1,8-8H80a8,8,0,0,0,6.65-3.56L100.28,48h55.44l13.63,20.44A8,8,0,0,0,176,72h32a8,8,0,0,1,8,8ZM128,88a44,44,0,1,0,44,44A44.05,44.05,0,0,0,128,88Zm0,72a28,28,0,1,1,28-28A28,28,0,0,1,128,160Z"/></svg> Сканировать</button>
                <input type="hidden" id="tableNumber">
                <div id="qrResult" class="qr-result"></div>
            </div>

            <div class="delivery-extra-block payment-method-block" id="paymentMethodBlock">
                <label class="payment-method-label">Способ оплаты</label>
                <div class="delivery-options payment-options-gap" id="paymentOptionsRow">
                    <div class="payment-option" id="pmCash" data-method="cash">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M216,72H56a8,8,0,0,1,0-16H192a8,8,0,0,0,0-16H56A24,24,0,0,0,32,64V192a24,24,0,0,0,24,24H216a16,16,0,0,0,16-16V88A16,16,0,0,0,216,72Zm0,128H56a8,8,0,0,1-8-8V86.63A23.84,23.84,0,0,0,56,88H216Zm-32-76a12,12,0,1,1-12-12A12,12,0,0,1,184,124Z"/></svg>
                        На месте
                    </div>
                    <?php if ($paymentEnabled): ?>
                    <div class="payment-option" id="pmOnline" data-method="online">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M224,48H32A16,16,0,0,0,16,64V192a16,16,0,0,0,16,16H224a16,16,0,0,0,16-16V64A16,16,0,0,0,224,48Zm0,16V96H32V64Zm0,128H32V112H224v80ZM48,152a8,8,0,0,1,8-8H96a8,8,0,0,1,0,16H56A8,8,0,0,1,48,152Zm144,0a8,8,0,0,1-8,8H168a8,8,0,0,1,0-16h16A8,8,0,0,1,192,152Z"/></svg>
                        Карта
                    </div>
                    <?php endif; ?>
                    <?php if ($sbpVisible): ?>
                    <div class="payment-option" id="pmSbp" data-method="<?= htmlspecialchars($sbpMethod) ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M213.85,125.46l-112,120a8,8,0,0,1-13.69-7l14.66-73.33L48.15,146.54A8,8,0,0,1,42,133.88L154,13.88a8,8,0,0,1,13.69,7L153,94.21l55.67,18.17A8,8,0,0,1,213.85,125.46Z"/></svg>
                        СБП
                    </div>
                    <?php endif; ?>
                </div>
                <input type="hidden" id="selectedPaymentMethod" value="">
            </div>
            <script nonce="<?= $scriptNonce ?>">
            (function () {
                var row = document.getElementById('paymentOptionsRow');
                if (!row) return;
                row.addEventListener('click', function (e) {
                    var opt = e.target.closest('.payment-option');
                    if (!opt) return;
                    var method = opt.getAttribute('data-method');
                    if (!method) return;
                    var hidden = document.getElementById('selectedPaymentMethod');
                    if (hidden) hidden.value = method;
                    try { localStorage.setItem('paymentMethod', method); } catch (err) {}
                    row.querySelectorAll('.payment-option').forEach(function (el) { el.classList.remove('active'); });
                    opt.classList.add('active');
                });
            })();
            </script>

            <div class="tips-section">
                <label class="payment-label">Чаевые</label>
                <div class="tips-options">
                    <button class="tips-option active" data-pct="0">Без</button>
                    <button class="tips-option" data-pct="5">5%</button>
                    <button class="tips-option" data-pct="10">10%</button>
                    <button class="tips-option" data-pct="15">15%</button>
                    <button class="tips-option" data-pct="custom">Своя</button>
                </div>
                <div class="tips-custom-wrap" id="tipsCustomWrap">
                    <input type="number" id="tipsCustomInput" min="0" max="9999" placeholder="Сумма ₽" class="form-group">
                </div>
                <div class="tips-total" id="tipsTotalDisplay"></div>
                <input type="hidden" id="selectedTip" value="0">
            </div>

            <div class="delivery-modal-buttons">
                <button id="confirmDeliveryBtn" class="checkout-btn" disabled>Подтвердить</button>
                <button id="cancelDeliveryBtn" class="checkout-btn cancel-btn">Отмена</button>
            </div>
        </div>
    </div>

    <!-- Модальное окно для неавторизованных пользователей -->
    <div id="guestOrderModal" class="delivery">
        <div class="delivery-content">
            <h3>Оформить заказ</h3>
            <p>Вы не авторизованы. Вы можете:</p>
            <div class="delivery-options">
                <div id="guestLoginBtn" class="delivery-option" data-action="login">Войти</div>
                <div id="guestRegisterBtn" class="delivery-option" data-action="register">Зарегистрироваться</div>
            </div>
            <div class="delivery-extra-block">
                <label for="guestPhone">Или оставьте номер телефона, мы перезвоним для подтверждения заказа:</label>
                <input type="tel" id="guestPhone" class="form-group" placeholder="+7 (___) ___-__-__" inputmode="numeric" autocomplete="tel">
                <button id="guestCallBtn" class="checkout-btn" disabled>Принять звонок</button>
            </div>
            <div class="delivery-modal-buttons">
                <button id="cancelGuestBtn" class="checkout-btn cancel-btn">Отмена</button>
            </div>
        </div>
    </div>

    <div class="footer-bottom">
        <p>&copy; <?= date('Y') ?> «<?= htmlspecialchars($GLOBALS['siteName'] ?? 'labus') ?>». Все права защищены.</p>
    </div>

    <?php if (!empty($_SESSION['qr_table'])): ?>
    <script nonce="<?= $scriptNonce ?>">
    document.addEventListener('DOMContentLoaded', function() {
        var tableNum = <?= (int)$_SESSION['qr_table'] ?>;
        // Click the "За стол" option to activate the table block
        var tableOpt = document.querySelector('.delivery-option[data-type="table"]');
        if (tableOpt) tableOpt.click();
        // Pre-fill table number
        var manualInput = document.getElementById('manualTableNumber');
        var hiddenInput = document.getElementById('tableNumber');
        if (manualInput) manualInput.value = tableNum;
        if (hiddenInput) hiddenInput.value = tableNum;
        // Trigger confirm button so cart.js saves the number
        var confirmBtn = document.getElementById('manualTableBtn');
        if (confirmBtn) confirmBtn.click();
    });
    </script>
    <?php endif; ?>
    <script src="/js/cart-tips.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/qr-scanner-lazy.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/security.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/offline-queue.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/cart.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>

</html>
