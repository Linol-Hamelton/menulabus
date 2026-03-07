<!-- menu-content-info.php LOADED -->
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
$includeMenuCss = empty($GLOBALS['menu_css_in_head']);
?>
<?php if ($includeMenuCss): ?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <title>Альтернативное меню</title>
    <link rel="stylesheet" href="/css/fa-styles.min.css">
    <link rel="stylesheet" href="/css/fa-purged.min.css">
    <link rel="stylesheet" href="/css/menu-alt.min.css">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion ?? $_SESSION['app_version'] ?? '1.0.0') ?>">
</head>

<body>
<?php endif; ?>
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

    <section id="menu" class="section menu info">
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
                    $items = $db->getMenuItems($category['category'], false);
                    $isActive = $index === 0 ? 'active' : '';
                ?>
                    <div class="tab-pane <?= $isActive ?>" id="<?= htmlspecialchars($category['category']) ?>">
                <?php foreach ($items as $item):
                    $unavail = !$item['available'];
                    $itemMods = $unavail ? [] : $db->getModifiersByItemId((int)$item['id']);
                        ?>
                            <div class="menu-item<?= $unavail ? ' menu-item--unavailable' : '' ?>">
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
                                <h3><?= htmlspecialchars($item['name']) ?></h3>
                                <p><?= htmlspecialchars($item['description']) ?></p>
                                <span class="price"><?= number_format($item['price'], 0, '.', '') ?> ₽</span>
                                <?php if ($unavail): ?>
                                    <span class="menu-item__stopbadge">Снято</span>
                                <?php else: ?>
                                <span class="buy"
                                    data-product-id="<?= $item['id'] ?>"
                                    data-product-name="<?= htmlspecialchars($item['name']) ?>"
                                    data-product-price="<?= $item['price'] ?>"
                                    data-product-image="<?= htmlspecialchars($item['image']) ?>"
                                    data-calories="<?= (int)($item['calories'] ?? 0) ?>"
                                    data-protein="<?= (int)($item['protein'] ?? 0) ?>"
                                    data-fat="<?= (int)($item['fat'] ?? 0) ?>"
                                    data-carbs="<?= (int)($item['carbs'] ?? 0) ?>"
                                    data-csrf="<?= $csrfToken ?>"<?php if ($itemMods): ?> data-modifiers="<?= htmlspecialchars(json_encode($itemMods, JSON_UNESCAPED_UNICODE)) ?>"<?php endif; ?>>
                                    <span class="buy-text">+</span>
                                    <span class="buy-counter hidden">
                                        <span class="counter-minus" data-action="decrease">-</span>
                                        <span class="counter-value">1</span>
                                        <span class="counter-plus" data-action="increase">+</span>
                                    </span>
                                </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php if ($includeMenuCss): ?>
</body>

</html>
<?php endif; ?>
