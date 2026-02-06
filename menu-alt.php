<?php
if (!defined('PUBLIC_MENU')) {
    require_once __DIR__ . '/session_init.php';
}
$requestTimestamp = $GLOBALS['menu_request_ts'] ?? time();

if (!isset($db)) {
    require_once __DIR__ . '/db.php';
    $db = Database::getInstance();
}
if (!isset($categories)) {
    $categories = $db->getUniqueCategories();
}
$activeCategory = $_COOKIE['activeMenuCategory'] ?? ($categories[0]['category'] ?? '');
$includeMenuCss = empty($GLOBALS['menu_css_in_head']);
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <title>Альтернативное меню</title>
</head>

<body>
    <!-- ========  Модальное окно «Состав»  ======== -->
    <div id="compositionModal" class="delivery-modal">
        <div class="delivery-modal-content">
            <div>
                <img id="modalImage" src="" loading="lazy"
                    class="image no-drag no-context" alt="">
            </div>
            <div class="modal-info-block">
                <h3 id="modalTitle"></h3>
                <h3 id="modalDescription" class="cart-item-price"></h3>

                <div class="cart-item-price"><strong class="modal-strong">Состав:</strong>
                    <span id="modalComposition"></span>
                </div>
                <div class="cart-item-price"><strong class="modal-strong">Калории:</strong>
                    <span id="modalCalories"></span> ккал
                </div>
                <div class="cart-item-price"><strong class="modal-strong">Белки:</strong>
                    <span id="modalProtein"></span> г
                </div>
                <div class="cart-item-price"><strong class="modal-strong">Жиры:</strong>
                    <span id="modalFat"></span> г
                </div>
                <div class="cart-item-price"><strong class="modal-strong">Углеводы:</strong>
                    <span id="modalCarbs"></span> г
                </div>
            </div>
            <div class="modal-footer">
                <button id="closeModalBtn" class="checkout-btn">Закрыть</button>
            </div>
        </div>
    </div>

    <section id="menu" class="section menu">
        <div class="container">
            <div class="section-header-menu">
                <h2>Меню</h2>
                <a href="cart.php" class="order-summary-btn">
                    <span class="order-total">0 ₽</span>
                    <i class="fas fa-shopping-cart"></i>
                </a>
            </div>

            <div class="menu-content">
                <?php foreach ($categories as $index => $category):
                    $items = $db->getMenuItems($category['category']);
                    $isActive = $index === 0 ? 'active' : '';
                ?>
                    <div class="tab-pane <?= $isActive ?>" id="<?= htmlspecialchars($category['category']) ?>">
                <?php foreach ($items as $item):
                        ?>
                            <div class="cart-item">
                                <div class="cart-item-info">
                                    <img class="cart-item-image no-drag no-context modal-trigger"
                                        src="<?= htmlspecialchars($item['image']) ?>"
                                        loading="lazy"
                                        alt="<?= htmlspecialchars($item['name']) ?>"
                                        data-composition="<?= htmlspecialchars($item['composition'] ?? '') ?>"
                                        data-calories="<?= (int)($item['calories'] ?? 0) ?>"
                                        data-protein="<?= (int)($item['protein'] ?? 0) ?>"
                                        data-fat="<?= (int)($item['fat'] ?? 0) ?>"
                                        data-carbs="<?= (int)($item['carbs'] ?? 0) ?>"
                                        data-name="<?= htmlspecialchars($item['name']) ?>"
                                        data-image="<?= htmlspecialchars($item['image']) ?>"
                                        data-description="<?= htmlspecialchars($item['description'] ?? '') ?>">
                                    <div>
                                        <div class="cart-item-title"><?= htmlspecialchars($item['name']) ?></div>
                                        <div class="cart-item-price"><?= number_format($item['price'], 0, '.', '') ?> ₽</div>
                                    </div>
                                </div>

                                <div class="cart-item-quantity">
                                    <div class="buy"
                                        data-product-id="<?= $item['id'] ?>"
                                        data-product-name="<?= htmlspecialchars($item['name']) ?>"
                                        data-product-price="<?= $item['price'] ?>"
                                        data-product-image="<?= htmlspecialchars($item['image']) ?>"
                                        data-calories="<?= (int)($item['calories'] ?? 0) ?>"
                                        data-protein="<?= (int)($item['protein'] ?? 0) ?>"
                                        data-fat="<?= (int)($item['fat'] ?? 0) ?>"
                                        data-carbs="<?= (int)($item['carbs'] ?? 0) ?>"
                                        data-csrf="<?= $csrfToken ?>">

                                        <span class="buy-text">+</span>

                                        <div class="buy-counter hidden">
                                            <span class="counter-plus" data-action="increase">+</span>
                                            <span class="counter-value">1</span>
                                            <span class="counter-minus" data-action="decrease">-</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php if ($includeMenuCss): ?>
        <link rel="stylesheet" href="/css/fa-styles.min.css" nonce="<?= $styleNonce ?>">
        <link rel="stylesheet" href="/css/fa-purged.min.css" nonce="<?= $styleNonce ?>">
        <link rel="stylesheet" href="/css/menu-alt.min.css" nonce="<?= $styleNonce ?>">
    <?php endif; ?>
</body>

</html>
