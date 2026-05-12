<?php
/**
 * admin/semi-finished.php — Phase 40 (полуфабрикаты).
 *
 * Список полуфабрикатов + редактор рецепта + кнопка «Приготовить партию».
 * Полуфабрикат — это ingredient с is_semi_finished=1. Включается через
 * /admin/inventory.php edit-modal → toggle «Полуфабрикат».
 */

$required_role = 'admin';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../db.php';

$gate_feature = 'inventory';
$gate_label   = 'Полуфабрикаты';
require __DIR__ . '/../partials/billing_feature_gate.php';

$db = Database::getInstance();
$semiFinished = $db->listSemiFinishedIngredients();
$allIngredients = $db->listIngredients(false);

// Filter out the semi-finished items themselves to avoid nested cycles in default UI
$nonSemiIngredients = array_filter($allIngredients, static fn($i) => empty($i['is_semi_finished'] ?? 0));

$siteName    = $GLOBALS['siteName'] ?? 'labus';
$appVersion  = (string)($_SESSION['app_version'] ?? '1.0.0');
$scriptNonce = $GLOBALS['scriptNonce'] ?? '';

$fmtQty = static fn($v): string => rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <title>Полуфабрикаты | <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-design-modals.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-inventory.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-vsd.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-egais.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/css/admin-semi-finished.css?v=<?= htmlspecialchars($appVersion) ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($appVersion) ?>">
</head>
<body class="admin-page account-page" data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/../header.php'; ?>
    <?php require_once __DIR__ . '/../account-header.php'; ?>

    <div class="account-container">
        <section class="account-section admin-section-card">
            <div class="admin-pane-header">
                <div class="admin-pane-header-copy">
                    <p class="admin-pane-kicker">Заготовки</p>
                    <h2 class="admin-pane-title">Полуфабрикаты</h2>
                    <p class="admin-pane-caption">
                        Полуфабрикат — это ingredient (с пометкой «Полуфабрикат» в /admin/inventory.php) который
                        готовится партией из других ингредиентов. Используется в рецептах блюд как обычный ингредиент.
                        Включить toggle на ингредиенте — в edit-modal /admin/inventory.php.
                    </p>
                </div>
                <a href="/admin/inventory.php" class="back-to-menu-btn">К складу</a>
            </div>

            <?php if (empty($semiFinished)): ?>
                <p class="vsd-empty">
                    Полуфабрикатов нет. Откройте /admin/inventory.php → редактирование любого ингредиента →
                    включите «Полуфабрикат», укажите «Выход партии» (например, 5 кг соуса с одной готовки).
                </p>
            <?php else: ?>
                <div class="sf-list">
                    <?php foreach ($semiFinished as $sf): ?>
                        <?php $recipe = $db->getSemiFinishedRecipe((int)$sf['id']); ?>
                        <article class="sf-card" data-sf-id="<?= (int)$sf['id'] ?>">
                            <header class="sf-card-head">
                                <h3 class="sf-card-title"><?= htmlspecialchars((string)$sf['name']) ?></h3>
                                <div class="sf-card-meta">
                                    <span><strong>Выход:</strong> <?= $fmtQty($sf['yield_per_batch']) ?> <?= htmlspecialchars((string)$sf['unit']) ?></span>
                                    <span><strong>Остаток:</strong> <?= $fmtQty($sf['stock_qty']) ?> <?= htmlspecialchars((string)$sf['unit']) ?></span>
                                </div>
                            </header>

                            <div class="sf-recipe-section">
                                <p class="sf-section-label">Рецепт партии (на 1 единицу batch size)</p>
                                <?php if (empty($recipe)): ?>
                                    <p class="integrations-meta">Рецепт пока не задан. Добавьте ингредиенты ниже.</p>
                                <?php else: ?>
                                    <ul class="sf-recipe-list">
                                        <?php foreach ($recipe as $r): ?>
                                            <li>
                                                <span class="sf-recipe-name"><?= htmlspecialchars((string)$r['child_name']) ?></span>
                                                <span class="sf-recipe-qty"><?= $fmtQty($r['quantity']) ?> <?= htmlspecialchars((string)$r['child_unit']) ?></span>
                                                <span class="integrations-meta">(остаток: <?= $fmtQty($r['child_stock']) ?>)</span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                <button type="button" class="admin-checkout-btn js-sf-edit-recipe" data-sf-id="<?= (int)$sf['id'] ?>">
                                    <?= empty($recipe) ? '+ Задать рецепт' : 'Изменить рецепт' ?>
                                </button>
                            </div>

                            <div class="sf-actions">
                                <button type="button" class="checkout-btn js-sf-cook" data-sf-id="<?= (int)$sf['id'] ?>" <?= empty($recipe) ? 'disabled' : '' ?>>
                                    Приготовить партию
                                </button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- Recipe edit modal -->
    <dialog id="sfRecipeModal" class="design-modal" aria-labelledby="sfRecipeTitle">
        <div class="modal-card">
            <header class="modal-head">
                <div>
                    <h2 id="sfRecipeTitle" class="modal-title">Рецепт полуфабриката</h2>
                    <p class="modal-subtitle" id="sfRecipeSubtitle">—</p>
                </div>
                <button type="button" class="modal-close" data-sf-modal-close aria-label="Закрыть">×</button>
            </header>
            <form class="modal-body inv-edit-form" id="sfRecipeForm">
                <input type="hidden" id="sfRecipeParentId" value="">
                <div class="sf-recipe-items" id="sfRecipeItems"></div>
                <button type="button" class="admin-checkout-btn js-sf-add-item">+ Добавить ингредиент</button>
                <div id="sfRecipeMsg" class="recipe-save-msg" hidden></div>
            </form>
            <footer class="modal-foot">
                <button type="button" class="admin-checkout-btn cancel" data-sf-modal-close>Отмена</button>
                <button type="button" class="checkout-btn" id="sfRecipeSubmit">Сохранить</button>
            </footer>
        </div>
    </dialog>

    <!-- Cook batch modal -->
    <dialog id="sfCookModal" class="design-modal" aria-labelledby="sfCookTitle">
        <div class="modal-card">
            <header class="modal-head">
                <div>
                    <h2 id="sfCookTitle" class="modal-title">Приготовить партию</h2>
                    <p class="modal-subtitle" id="sfCookSubtitle">—</p>
                </div>
                <button type="button" class="modal-close" data-sf-modal-close aria-label="Закрыть">×</button>
            </header>
            <form class="modal-body inv-edit-form" id="sfCookForm">
                <input type="hidden" id="sfCookParentId" value="">
                <label class="inv-edit-field">
                    <span class="inv-edit-label">Размер партии (множитель рецепта)</span>
                    <input type="number" id="sfCookBatchSize" step="0.01" min="0.01" value="1" required>
                </label>
                <label class="inv-edit-field">
                    <span class="inv-edit-label">Заметки</span>
                    <input type="text" id="sfCookNotes" maxlength="500" placeholder="опционально">
                </label>
                <div id="sfCookMsg" class="recipe-save-msg" hidden></div>
            </form>
            <footer class="modal-foot">
                <button type="button" class="admin-checkout-btn cancel" data-sf-modal-close>Отмена</button>
                <button type="button" class="checkout-btn" id="sfCookSubmit">Приготовить</button>
            </footer>
        </div>
    </dialog>

    <!-- Embedded data for JS -->
    <script type="application/json" id="sfIngredientsData" nonce="<?= $scriptNonce ?>">
        <?= json_encode(array_values(array_map(static fn($i) => [
            'id'   => (int)$i['id'],
            'name' => (string)$i['name'],
            'unit' => (string)$i['unit'],
        ], $nonSemiIngredients)), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>
    </script>

    <script src="/js/security.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-semi-finished.js?v=<?= htmlspecialchars($appVersion) ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>
</html>
