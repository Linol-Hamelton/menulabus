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
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <title>Альтернативное меню</title>
    <?php if ($includeMenuCss): ?>
        <link rel="stylesheet" href="/css/fa-styles.css" nonce="<?= $styleNonce ?>">
        <link rel="stylesheet" href="css/fa-purged.css" nonce="<?= $styleNonce ?>">
        <link rel="stylesheet" href="css/menu-alt.css" nonce="<?= $styleNonce ?>">
    <?php endif; ?>
</head>

<body>
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
                    $items = $db->getMenuItems($category['category']);
                    $isActive = $index === 0 ? 'active' : '';
                ?>
                    <div class="tab-pane <?= $isActive ?>" id="<?= htmlspecialchars($category['category']) ?>">
                <?php foreach ($items as $item):
                        ?>
                            <div class="menu-item">
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
                                <span class="buy" onclick="addToCart(this)"
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
                                    <span class="buy-counter hidden">
                                        <span class="counter-minus" data-action="decrease">-</span>
                                        <span class="counter-value">1</span>
                                        <span class="counter-plus" data-action="increase">+</span>
                                    </span>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</body>

</html>
