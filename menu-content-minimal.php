<!-- menu-content-minimal.php — Phase L100 minimal-tile view -->
<?php
if (!defined('PUBLIC_MENU')) {
    require_once __DIR__ . '/session_init.php';
}
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
    <title>Меню</title>
    <link rel="stylesheet" href="/css/fa-styles.min.css">
    <link rel="stylesheet" href="/css/fa-purged.min.css">
    <link rel="stylesheet" href="/css/menu-alt.min.css">
    <link rel="stylesheet" href="/css/menu-minimal.css">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion ?? $_SESSION['app_version'] ?? '1.0.0') ?>">
</head>
<body class="menu-catalog-page menu-view-minimal">
<?php endif; ?>

    <!-- Modal — same #compositionModal id чтобы cart.js wiring срабатывал -->
    <div id="compositionModal" class="delivery-modal menu-minimal-modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="delivery-modal-content menu-minimal-modal-content">
            <div class="menu-minimal-modal-hero">
                <img id="modalImage" src="" loading="lazy"
                    class="image no-drag no-context menu-minimal-modal-image" alt="">
            </div>
            <div class="menu-minimal-modal-body">
                <h3 id="modalTitle" class="menu-minimal-modal-title"></h3>
                <p id="modalDescription" class="menu-minimal-modal-description"></p>

                <dl class="menu-minimal-meta">
                    <div class="menu-minimal-meta-row">
                        <dt>Состав</dt>
                        <dd><span id="modalComposition">—</span></dd>
                    </div>
                    <div class="menu-minimal-meta-row">
                        <dt>Калории</dt>
                        <dd><span id="modalCalories">0</span> ккал</dd>
                    </div>
                    <div class="menu-minimal-meta-row menu-minimal-meta-nutrition">
                        <dt>Б / Ж / У</dt>
                        <dd>
                            <span id="modalProtein">0</span> /
                            <span id="modalFat">0</span> /
                            <span id="modalCarbs">0</span>&nbsp;г
                        </dd>
                    </div>
                </dl>
            </div>
            <div class="menu-minimal-modal-footer">
                <button id="closeModalBtn" class="menu-minimal-modal-close">Закрыть</button>
            </div>
        </div>
    </div>

    <section id="menu" class="section menu menu-minimal">
        <div class="container">
            <div class="section-header-menu menu-minimal-header">
                <h2 class="menu-minimal-h2">Меню</h2>
                <a href="cart.php" class="order-summary-btn menu-minimal-cart-link">
                    <span class="order-total">0 ₽</span>
                    <svg class="btn-inline-icon" aria-hidden="true" viewBox="0 0 256 256">
                        <use href="/images/icons/phosphor-sprite.svg#shopping-cart-simple"></use>
                    </svg>
                </a>
            </div>

            <div class="menu-tabs-container menu-minimal-tabs-container">
                <div class="menu-tabs menu-minimal-tabs">
                    <?php foreach ($categories as $category): ?>
                        <button class="tab-btn menu-minimal-tab <?= $category['category'] === $activeCategory ? 'active' : '' ?>"
                            data-tab="<?= htmlspecialchars($category['category']) ?>">
                            <?= htmlspecialchars($category['category']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="menu-content menu-minimal-content">
                <?php foreach ($categories as $index => $category):
                    $items = $db->getMenuItems($category['category'], false);
                    $isActive = $index === 0 ? 'active' : '';
                ?>
                    <div class="tab-pane <?= $isActive ?>" id="<?= htmlspecialchars($category['category']) ?>">
                        <div class="menu-minimal-grid">
                <?php foreach ($items as $item):
                    $unavail = !$item['available'];
                    $itemMods = $unavail ? [] : $db->getModifiersByItemId((int)$item['id']);
                ?>
                            <article class="menu-item menu-minimal-tile<?= $unavail ? ' menu-item--unavailable' : '' ?>">
                                <div class="menu-minimal-tile-imagewrap">
                                    <img class="cart-item-image menu-minimal-tile-image no-drag no-context modal-trigger"
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
                                    <?php if ($unavail): ?>
                                        <span class="menu-item__stopbadge menu-minimal-tile-stop">Снято</span>
                                    <?php endif; ?>
                                </div>
                                <div class="menu-minimal-tile-body">
                                    <h3 class="menu-minimal-tile-name"><?= htmlspecialchars($item['name']) ?></h3>
                                    <?php
                                    $caption = trim((string)($item['composition'] ?? ''));
                                    if ($caption === '') {
                                        $caption = trim((string)($item['description'] ?? ''));
                                    }
                                    if ($caption !== ''):
                                    ?>
                                        <p class="menu-minimal-tile-caption"><?= htmlspecialchars($caption) ?></p>
                                    <?php endif; ?>
                                    <div class="menu-minimal-tile-footer">
                                        <span class="price menu-minimal-tile-price"><?= number_format($item['price'], 0, '.', '') ?> ₽</span>
                                        <?php if (!$unavail): ?>
                                            <span class="buy menu-minimal-tile-buy"
                                                data-product-id="<?= $item['id'] ?>"
                                                data-product-name="<?= htmlspecialchars($item['name']) ?>"
                                                data-product-price="<?= $item['price'] ?>"
                                                data-product-image="<?= htmlspecialchars($item['image']) ?>"
                                                data-calories="<?= (int)($item['calories'] ?? 0) ?>"
                                                data-protein="<?= (int)($item['protein'] ?? 0) ?>"
                                                data-fat="<?= (int)($item['fat'] ?? 0) ?>"
                                                data-carbs="<?= (int)($item['carbs'] ?? 0) ?>"
                                                data-csrf="<?= $csrfToken ?>"<?php if ($itemMods): ?> data-modifiers="<?= htmlspecialchars(json_encode($itemMods, JSON_UNESCAPED_UNICODE)) ?>"<?php endif; ?>>
                                                <span class="buy-text">В&nbsp;корзину</span>
                                                <span class="buy-counter hidden">
                                                    <span class="counter-minus" data-action="decrease">−</span>
                                                    <span class="counter-value">1</span>
                                                    <span class="counter-plus" data-action="increase">+</span>
                                                </span>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

<?php if ($includeMenuCss): ?>
</body>
</html>
<?php endif; ?>
