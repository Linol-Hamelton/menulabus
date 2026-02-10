<?php
// Начало сессии с безопасными настройками
require_once __DIR__ . '/session_init.php';
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="manifest" href="/manifest.webmanifest?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?? '' ?>">
    <meta http-equiv="Permissions-Policy" content="camera=(self 'https://menu.labus.pro'), microphone=()">
    <meta http-equiv="Content-Security-Policy" content="media-src 'self' blob:;">
    <title>labus | Заказ</title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/menu-alt.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
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
                <div class="delivery-option" data-type="bar">На бар</div>
                <div class="delivery-option" data-type="table">За стол</div>
                <div class="delivery-option" data-type="takeaway">На вынос</div>
                <div class="delivery-option" data-type="delivery">Доставка</div>
            </div>

            <!-- Блок для адреса доставки -->
            <div id="deliveryAddressBlock" class="delivery-extra-block hidden">
                <label for="deliveryAddress">Укажите адрес:</label>
                <div class="address-input-wrapper">
                    <input type="text" id="deliveryAddress" placeholder="Введите адрес доставки" autocomplete="address-line1">
                    <button type="button" id="detectLocationBtn" class="detect-location-btn" title="Определить автоматически">📍</button>
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
                <button type="button" id="scanQrBtn" class="scan-btn">📷 Сканировать</button>
                <input type="hidden" id="tableNumber">
                <div id="qrResult" class="qr-result"></div>
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
        <p>&copy; 2023 "labus". Все права защищены.</p>
    </div>

    <script src="/js/qr-scanner-lazy.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/security.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/offline-queue.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/cart.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>

</html>
