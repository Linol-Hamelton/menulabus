<!-- menu-content-info.php LOADED -->
<?php
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';

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
                            $productData = [
                                'id' => $item['id'],
                                'name' => $item['name'],
                                'price' => $item['price'],
                                'image' => $item['image'],
                                'calories' => $item['calories'] ?? 0,
                                'protein' => $item['protein'] ?? 0,
                                'fat' => $item['fat'] ?? 0,
                                'carbs' => $item['carbs'] ?? 0,
                                'desc' => $item['description'] ?? '',
                                'csrf' => $csrfToken,
                                'timestamp' => time()
                            ];
                            $signedData = signProductData($productData, CART_SECRET_KEY);
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
                                    data-signed="<?= htmlspecialchars($signedData) ?>"
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
    <link rel="stylesheet" href="/css/fa-styles.min.css" nonce="<?= $styleNonce ?>">
    <link rel="stylesheet" href="css/fa-purged.min.css" nonce="<?= $styleNonce ?>">
    <link rel="stylesheet" href="css/menu-alt.min.css" nonce="<?= $styleNonce ?>">
</body>

</html>