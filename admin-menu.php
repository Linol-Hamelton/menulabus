<?php
$required_role = 'admin';
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/require_auth.php';

// Ensure script nonce is available for CSP
if (empty($scriptNonce) && isset($GLOBALS['scriptNonce'])) {
    $scriptNonce = $GLOBALS['scriptNonce'];
}
// Fallback to session nonce if still empty
if (empty($scriptNonce) && isset($_SESSION['csp_nonce']['script'])) {
    $scriptNonce = $_SESSION['csp_nonce']['script'];
}

$db = Database::getInstance();
$appVersion = htmlspecialchars($_SESSION['app_version'] ?? '1.0.0');
$adminMenuCssVersion = @filemtime(__DIR__ . '/css/admin-menu-polish.css');
$adminMenuCssVersion = $adminMenuCssVersion ? (string)$adminMenuCssVersion : ($appVersion ?: '1.0.0');
$adminMenuJsVersion = @filemtime(__DIR__ . '/js/admin-menu-page.js');
$adminMenuJsVersion = $adminMenuJsVersion ? (string)$adminMenuJsVersion : ($appVersion ?: '1.0.0');
$adminModifiersJsVersion = @filemtime(__DIR__ . '/js/admin-modifiers.js');
$adminModifiersJsVersion = $adminModifiersJsVersion ? (string)$adminModifiersJsVersion : ($appVersion ?: '1.0.0');

$menuView = (($_GET['view'] ?? 'active') === 'archived') ? 'archived' : 'active';
$showArchived = $menuView === 'archived';
$items = $showArchived
    ? $db->getArchivedMenuItems()
    : $db->getMenuItems(null, false);

// Получаем уникальные категории
$categories = !empty($items)
    ? array_values(array_unique(array_column($items, 'category')))
    : [];

$errors = $success = null;

/* --- CRUD logic --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* ---------- 1. Одиночное добавление / редактирование товара ---------- */
    if (isset($_POST['archive_item'])) {
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Ошибка безопасности';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $_SESSION['error'] = 'Некорректный ID';
            } else {
                $ok = $db->archiveMenuItem($id);
                $_SESSION[$ok ? 'success' : 'error'] = $ok
                    ? 'Блюдо архивировано'
                    : 'Не удалось архивировать блюдо';
            }
        }
        header('Location: admin-menu.php?view=active');
        exit;
    } elseif (isset($_POST['restore_archived'])) {
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Ошибка безопасности';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $_SESSION['error'] = 'Некорректный ID';
            } else {
                $ok = $db->restoreArchivedMenuItem($id);
                $_SESSION[$ok ? 'success' : 'error'] = $ok
                    ? 'Блюдо восстановлено из архива'
                    : 'Не удалось восстановить блюдо';
            }
        }
        header('Location: admin-menu.php?view=archived');
        exit;
    } elseif (isset($_POST['name'])) {
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Ошибка безопасности';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $price = (float)($_POST['price'] ?? 0);
            $image = trim($_POST['image'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $available = isset($_POST['available']) ? 1 : 0;

            // Обработка поля composition
            $composition = trim($_POST['composition'] ?? '');
            $composition = preg_replace('/([^\s])\s+([^\s])/', '$1, $2', $composition);
            $composition = preg_replace('/,{2,}/', ',', $composition);
            $composition = trim($composition, ', ');

            $calories = (int)($_POST['calories'] ?? 0);
            $protein = (int)($_POST['protein'] ?? 0);
            $fat = (int)($_POST['fat'] ?? 0);
            $carbs = (int)($_POST['carbs'] ?? 0);

            if ($id) {
                $ok = $db->updateMenuItems(
                    $id,
                    $name,
                    $description,
                    $composition,
                    $price,
                    $image,
                    $calories,
                    $protein,
                    $fat,
                    $carbs,
                    $category,
                    $available
                );
                if ($ok) {
                    $_SESSION['success'] = 'Товар обновлён!';
                    header('Location: admin-menu.php?edit=' . $id);
                    exit;
                }
                $_SESSION['error'] = 'Ошибка при обновлении';
            } else {
                $ok = $db->addMenuItem(
                    $name,
                    $description,
                    $composition,
                    $price,
                    $image,
                    $calories,
                    $protein,
                    $fat,
                    $carbs,
                    $category,
                    $available
                );
                if ($ok) {
                    $_SESSION['success'] = 'Товар добавлен!';
                    header('Location: admin-menu.php');
                    exit;
                }
                $_SESSION['error'] = 'Ошибка при добавлении';
            }
        }
    }
    /* ---------- 2. Массовая загрузка CSV ---------- */ elseif (isset($_POST['bulk_upload'])) {
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Ошибка безопасности';
        } else {
            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                $_SESSION['error'] = 'Ошибка загрузки файла';
            } else {
                $fileContent = file_get_contents($_FILES['csv_file']['tmp_name']);
                if ($fileContent === false || trim($fileContent) === '') {
                    $_SESSION['error'] = 'Файл пустой';
                } elseif (function_exists('mb_check_encoding') && !mb_check_encoding($fileContent, 'UTF-8')) {
                    $_SESSION['error'] = 'CSV должен быть в UTF-8';
                } else {
                    $firstLine = strtok($fileContent, "\r\n");
                    $delimiter = (is_string($firstLine) && strpos($firstLine, ',') !== false) ? ',' : ';';

                    $tempHandle = fopen('php://temp', 'r+');
                    fwrite($tempHandle, $fileContent);
                    rewind($tempHandle);

                    $stats = $db->bulkSyncMenuFromCsv($tempHandle, $delimiter);
                    if (is_array($stats)) {
                        $_SESSION['success'] = sprintf(
                            'Синхронизация завершена: добавлено %d, обновлено %d, восстановлено %d, архивировано %d.',
                            (int)($stats['inserted'] ?? 0),
                            (int)($stats['updated'] ?? 0),
                            (int)($stats['restored_from_archive'] ?? 0),
                            (int)($stats['archived_missing'] ?? 0)
                        );
                    } elseif (!isset($_SESSION['error'])) {
                        $_SESSION['error'] = 'Ошибка при синхронизации CSV';
                    }
                    fclose($tempHandle);
                }
            }
        }
        header('Location: admin-menu.php?view=active');
        exit;
    }
}

/* --- load item for edit --- */
$editItem = null;
if (!empty($_GET['edit'])) {
    $editItem = $db->getProductById((int)$_GET['edit']);
}
$savedDbFonts = [
    'logo'    => ($v = $db->getSetting('font_logo'))    ? json_decode($v, true) : null,
    'text'    => ($v = $db->getSetting('font_text'))    ? json_decode($v, true) : null,
    'heading' => ($v = $db->getSetting('font_heading')) ? json_decode($v, true) : null,
];
$savedDbFontsJson = htmlspecialchars(
    json_encode($savedDbFonts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ENT_QUOTES,
    'UTF-8'
);
?>
<!DOCTYPE html>
<html lang="ru">

<head>


    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <link rel="manifest" href="/manifest.php?v=<?= $appVersion ?>">
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= $appVersion ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= $appVersion ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($adminMenuCssVersion, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="/css/admin-menu-sort.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/admin-menu-bulk.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/admin-menu-filters.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/undo-toast.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/admin-recipe.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/hotkeys.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= $appVersion ?>">
    <title>Блюда | <?= htmlspecialchars($GLOBALS['siteName'] ?? 'labus') ?></title>

    <!-- Preloader - мгновенная загрузка -->

</head>

<body class="employee-page admin-menu-page account-page" data-admin-font-settings="<?= $savedDbFontsJson ?>">
    <?php require_once __DIR__ . '/header.php'; ?>
    <?php require_once __DIR__ . '/account-header.php'; ?>

    <!-- Admin Tabs -->
    <div class="admin-tabs-container">
        <div class="admin-tabs">
            <button type="button" class="admin-tab-btn active" data-tab="dishes">Блюда</button>
            <button type="button" class="admin-tab-btn" data-tab="design">Дизайн</button>
            <?php if (in_array($_SESSION['user_role'] ?? '', ['admin', 'owner'])): ?>
                <button type="button" class="admin-tab-btn" data-tab="payment">Оплата</button>
                <button type="button" class="admin-tab-btn" data-tab="system">Система</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="account-form-container">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="message success"><?= htmlspecialchars($_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="message error"><?= htmlspecialchars($_SESSION['error']) ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Dishes Tab -->
        <div class="admin-tab-pane active" id="dishes">
            <div class="admin-dishes-pane">
            <section class="admin-form-container admin-section-card admin-catalog-card admin-block admin-block--catalog">
            <div class="admin-catalog-toolbar">
                <div class="form-actions menu-view-switch">
                <a href="admin-menu.php?view=active" class="admin-checkout-btn<?= !$showArchived ? ' cancel' : '' ?>">Активные</a>
                <a href="admin-menu.php?view=archived" class="admin-checkout-btn<?= $showArchived ? ' cancel' : '' ?>">Архив</a>
                </div>
                <div class="menu-tabs-container admin-menu-categories">
                    <div class="menu-tabs">
                        <?php foreach ($categories as $i => $c): ?>
                            <button class="tab-btn <?= $i === 0 ? 'active' : '' ?>" data-tab="<?= htmlspecialchars($c) ?>">
                                <?= htmlspecialchars($c) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php if (!$showArchived): ?>
                <div class="menu-filter-bar" id="menuFilterBar">
                    <label class="menu-filter-group menu-filter-search">
                        <span class="menu-filter-label">Поиск</span>
                        <input type="search" name="search" id="menuFilterSearch" placeholder="Название или ID" autocomplete="off">
                    </label>
                    <label class="menu-filter-group">
                        <span class="menu-filter-label">Наличие</span>
                        <select id="menuFilterAvailability">
                            <option value="all">Все</option>
                            <option value="available">В продаже</option>
                            <option value="stop">Стоп</option>
                        </select>
                    </label>
                    <label class="menu-filter-group">
                        <span class="menu-filter-label">Сортировка</span>
                        <select id="menuFilterSort">
                            <option value="default">По умолчанию</option>
                            <option value="name_asc">Название ↑</option>
                            <option value="name_desc">Название ↓</option>
                            <option value="price_asc">Цена ↑</option>
                            <option value="price_desc">Цена ↓</option>
                        </select>
                    </label>
                    <button type="button" class="menu-filter-reset" id="menuFilterReset">Сбросить</button>
                    <span class="menu-filter-count" id="menuFilterCount" hidden></span>
                </div>
            <?php endif; ?>

            <?php if (!$showArchived): ?>
                <div id="bulkActionBar" class="bulk-action-bar" hidden>
                    <span class="bulk-action-count">Выбрано: <strong id="bulkActionCount">0</strong></span>
                    <div class="bulk-action-buttons">
                        <button type="button" class="bulk-action-btn" data-bulk-action="show">Показать</button>
                        <button type="button" class="bulk-action-btn" data-bulk-action="hide">Скрыть (стоп)</button>
                        <select class="bulk-action-select" id="bulkMoveCategory" aria-label="Перенести в категорию">
                            <option value="">Перенести в категорию…</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="bulk-action-btn bulk-action-btn-danger" data-bulk-action="archive">Архивировать</button>
                        <button type="button" class="bulk-action-btn bulk-action-btn-secondary" data-bulk-action="clear">Сбросить</button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- DESKTOP TABLE -->
            <div class="desktop-table">
                <table class="menu-items-table" data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
                    <thead>
                        <tr>
                            <?php if (!$showArchived): ?>
                                <th class="drag-col" aria-label="Порядок"></th>
                                <th class="bulk-col">
                                    <input type="checkbox" class="bulk-select-all" aria-label="Выбрать все">
                                </th>
                            <?php endif; ?>
                            <th class="first-col">ID</th>
                            <th>Название</th>
                            <th>Категория</th>
                            <th>Цена</th>
                            <th><?= $showArchived ? 'Архивирован' : 'Стоп' ?></th>
                            <th class="last-col">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr class="menu-table tab-row<?= $showArchived ? '' : ' sortable-row' ?>"
                                data-category="<?= htmlspecialchars($it['category']) ?>"
                                data-item-id="<?= (int)$it['id'] ?>"
                                <?php if (!$showArchived): ?>draggable="true"<?php endif; ?>>
                                <?php if (!$showArchived): ?>
                                    <td class="drag-handle" title="Перетащите, чтобы изменить порядок">⋮⋮</td>
                                    <td class="bulk-col">
                                        <input type="checkbox" class="bulk-select-row" aria-label="Выбрать строку" value="<?= (int)$it['id'] ?>">
                                    </td>
                                <?php endif; ?>
                                <td><?= $it['id'] ?></td>
                                <td><?= htmlspecialchars($it['name']) ?></td>
                                <td><?= htmlspecialchars($it['category']) ?></td>
                                <td><?= number_format($it['price'], 2) ?> ₽</td>
                                <td>
                                    <?php if ($showArchived): ?>
                                        <?= htmlspecialchars((string)($it['archived_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    <?php else: ?>
                                        <button class="stop-btn <?= $it['available'] ? '' : 'stop-btn--active' ?>"
                                            data-item-id="<?= (int)$it['id'] ?>"
                                            title="<?= $it['available'] ? 'Снять с продажи' : 'Вернуть в продажу' ?>">
                                            <?= $it['available'] ? 'СТОП' : 'Вернуть' ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($showArchived): ?>
                                        <form method="POST" class="inline-action-form">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                            <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                            <button type="submit" name="restore_archived" class="admin-checkout-btn">Восстановить</button>
                                        </form>
                                    <?php else: ?>
                                        <a href="admin-menu.php?edit=<?= $it['id'] ?>" class="admin-checkout-btn">Редактировать</a>
                                        <form method="POST" class="inline-action-form">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                            <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                            <button type="submit" name="archive_item" class="admin-checkout-btn cancel">Архивировать</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="last-row">
                            <td colspan="<?= $showArchived ? 6 : 8 ?>"></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- MOBILE TABLE -->
            <div class="mobile-table-container">
                <div class="mobile-table">
                    <?php foreach ($items as $it): ?>
                        <div class="mobile-table-item tab-row" data-category="<?= htmlspecialchars($it['category']) ?>">
                            <div class="mobile-table-row">
                                <span class="mobile-table-label">ID:</span>
                                <span class="mobile-table-value"><?= $it['id'] ?></span>
                            </div>
                            <div class="mobile-table-row">
                                <span class="mobile-table-label">Название:</span>
                                <span class="mobile-table-value"><?= htmlspecialchars($it['name']) ?></span>
                            </div>
                            <div class="mobile-table-row">
                                <span class="mobile-table-label">Категория:</span>
                                <span class="mobile-table-value"><?= htmlspecialchars($it['category']) ?></span>
                            </div>
                            <div class="mobile-table-row">
                                <span class="mobile-table-label">Цена:</span>
                                <span class="mobile-table-value"><?= number_format($it['price'], 2) ?> ₽</span>
                            </div>
                            <?php if ($showArchived): ?>
                                <div class="mobile-table-row">
                                    <span class="mobile-table-label">Архивирован:</span>
                                    <span class="mobile-table-value"><?= htmlspecialchars((string)($it['archived_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="mobile-table-actions">
                                <?php if ($showArchived): ?>
                                    <form method="POST" class="inline-action-form">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                        <button type="submit" name="restore_archived" class="mobile-table-btn">
                                            Восстановить
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button class="stop-btn <?= $it['available'] ? '' : 'stop-btn--active' ?>"
                                        data-item-id="<?= (int)$it['id'] ?>"
                                        title="<?= $it['available'] ? 'Снять с продажи' : 'Вернуть в продажу' ?>">
                                        <?= $it['available'] ? 'СТОП' : 'Вернуть' ?>
                                    </button>
                                    <a href="admin-menu.php?edit=<?= $it['id'] ?>" class="mobile-table-btn">
                                        Редактировать
                                    </a>
                                    <form method="POST" class="inline-action-form">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                        <button type="submit" name="archive_item" class="mobile-table-btn">Архивировать</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            </section>

            <section class="admin-form-container admin-section-card admin-dishes-editor">
                <div class="admin-pane-header">
                    <div class="admin-pane-header-copy">
                        <p class="admin-pane-kicker">Каталог и наполнение</p>
                        <p class="admin-pane-caption">Загрузка, ручное редактирование и управление текущим каталогом собраны в одном рабочем пространстве.</p>
                    </div>
                </div>
                <div class="admin-dishes-workspace">
                <h2><?= $editItem ? 'Редактировать' : 'Обновление' ?></h2>

                <!-- Bulk upload -->
                <section class="admin-form-group admin-subsection-card admin-block admin-block--csv">
                    <h3>Из CSV</h3>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <a href="download-sample.php" download="Update.csv" class="download-button-container">Образец</a>
                        <input type="file" name="csv_file" accept=".csv" required>
                        <button type="submit" name="bulk_upload" class="checkout-btn">Загрузить</button>
                    </form>
                    <small>UTF-8 CSV. Полная синхронизация: позиции вне файла будут архивированы. Формат: external_id;name;description;composition;price;image;calories;protein;fat;carbs;category;available</small>
                </section>

                <div class="admin-subsection-card admin-block admin-block--manual">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="id" value="<?= $editItem['id'] ?? '' ?>">

                    <div class="admin-form-group">
                        <h3>Вручную</h3>
                        <label>Название</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($editItem['name'] ?? '') ?>" required data-hotkey-new>
                    </div>

                    <div class="admin-form-group">
                        <label>Описание</label>
                        <textarea name="description" rows="3"><?= htmlspecialchars($editItem['description'] ?? '') ?></textarea>
                    </div>

                    <div class="admin-form-group">
                        <label>Состав</label>
                        <textarea name="composition" rows="2"><?= htmlspecialchars($editItem['composition'] ?? '') ?></textarea>
                        <small>Разделяйте ингредиенты запятыми (например: "яйцо, мука, молоко")</small>
                    </div>

                    <!-- Калорийность и БЖУ -->
                    <div class="admin-form-group">
                        <label>Калорийность (ккал)</label>
                        <input type="number" name="calories" value="<?= $editItem['calories'] ?? '' ?>">
                    </div>

                    <div class="admin-form-group">
                        <label>Белки (г)</label>
                        <input type="number" name="protein" value="<?= $editItem['protein'] ?? '' ?>">
                    </div>

                    <div class="admin-form-group">
                        <label>Жиры (г)</label>
                        <input type="number" name="fat" value="<?= $editItem['fat'] ?? '' ?>">
                    </div>

                    <div class="admin-form-group">
                        <label>Углеводы (г)</label>
                        <input type="number" name="carbs" value="<?= $editItem['carbs'] ?? '' ?>">
                    </div>

                    <div class="admin-form-group">
                        <label>Цена (₽)</label>
                        <input type="number" step="0.01" name="price" value="<?= $editItem['price'] ?? '' ?>" required>
                    </div>

                    <div class="admin-form-group">
                        <label>Изображение (./dir/name.jpg)</label>
                        <input type="text" name="image" value="<?= htmlspecialchars($editItem['image'] ?? '') ?>">
                    </div>

                    <div class="admin-form-group">
                        <label>Категория</label>
                        <input type="text" name="category" list="cats" value="<?= htmlspecialchars($editItem['category'] ?? '') ?>" required>
                        <datalist id="cats">
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= htmlspecialchars($c) ?>">
                                <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="admin-form-group">
                        <label>
                            <input type="checkbox" name="available" <?= isset($editItem['available']) && $editItem['available'] ? 'checked' : 'checked' ?>>
                            Доступен
                        </label>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="checkout-btn"><?= $editItem ? 'Сохранить' : 'Добавить' ?></button>
                        <?php if ($editItem): ?>
                            <a href="admin-menu.php" class="admin-checkout-btn cancel">Отмена</a>
                        <?php endif; ?>
                    </div>
                </form>
                </div>
                </div>

                <?php if ($editItem): ?>
                    <!-- ── Модификаторы (только при редактировании) ── -->
                    <section class="admin-form-group admin-subsection-card admin-block admin-block--modifiers" id="modifiersSection" data-item-id="<?= (int)$editItem['id'] ?>">
                        <h3>Модификаторы (варианты блюда)</h3>
                        <p class="yk-desc">Например: «Степень прожарки» с вариантами Medium / Well-done, или «Добавки» с несколькими вариантами.</p>
                        <div id="modifierGroupList"></div>
                        <div class="mod-new-group-row">
                            <input type="text" id="newGroupName" placeholder="Название группы" maxlength="100">
                            <select id="newGroupType">
                                <option value="radio">Один вариант (radio)</option>
                                <option value="checkbox">Несколько (checkbox)</option>
                            </select>
                            <label>
                                <input type="checkbox" id="newGroupRequired"> Обязательно
                            </label>
                            <button id="addModifierGroupBtn" class="checkout-btn">
                                <svg class="btn-inline-icon" aria-hidden="true" viewBox="0 0 256 256">
                                    <use href="/images/icons/phosphor-sprite.svg#plus"></use>
                                </svg>
                                <span>Группа</span>
                            </button>
                        </div>
                    </section>

                    <!-- ── Рецепт: ингредиенты и их количество ── -->
                    <section class="admin-form-group admin-subsection-card admin-block admin-block--recipe" id="recipeSection" data-item-id="<?= (int)$editItem['id'] ?>">
                        <h3>Рецепт (списание со склада)</h3>
                        <p class="yk-desc">
                            Когда заказ приходит, эти количества автоматически списываются со склада.
                            Управление ингредиентами — <a href="/admin-inventory.php" target="_blank" rel="noopener">в «Складе»</a>.
                        </p>
                        <div id="recipeRows" class="recipe-rows"></div>
                        <div class="recipe-add-row">
                            <select id="recipeAddIngredient">
                                <option value="">Выбрать ингредиент…</option>
                                <?php foreach ($db->listIngredients(false) as $ing): ?>
                                    <option value="<?= (int)$ing['id'] ?>" data-unit="<?= htmlspecialchars((string)$ing['unit']) ?>">
                                        <?= htmlspecialchars((string)$ing['name']) ?> (<?= htmlspecialchars((string)$ing['unit']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" step="0.001" min="0" id="recipeAddQty" placeholder="Кол-во" data-w="lg">
                            <button type="button" id="recipeAddBtn" class="checkout-btn">Добавить</button>
                            <button type="button" id="recipeSaveBtn" class="checkout-btn admin-checkout-btn">Сохранить рецепт</button>
                        </div>
                        <div id="recipeSaveMsg" class="recipe-save-msg" hidden></div>
                    </section>
                <?php endif; ?>
            </section>

            </div>
        </div>

        <!-- Design Tab -->
        <div class="admin-tab-pane" id="design">
            <section class="admin-form-container admin-section-card admin-design-panel">
                <div class="admin-pane-header">
                    <div class="admin-pane-header-copy">
                        <p class="admin-pane-kicker">Файлы и бренд</p>
                        <h2 class="admin-pane-title">Управление файлами и дизайном</h2>
                        <p class="admin-pane-caption">Файлы, бренд, шрифты и цвета собраны в одном рабочем пространстве, чтобы white-label настройки было проще проверять и менять.</p>
                    </div>
                </div>
                <h2>Управление файлами и дизайном</h2>

                <!-- Название проекта -->
                <div class="admin-form-group admin-block admin-block--project">
                    <h3>Название проекта</h3>
                    <div class="project-name-control">
                        <input type="text" id="projectName" value="labus">
                        <button type="button" class="checkout-btn" id="saveProjectNameBtn">Сохранить название</button>
                    </div>
                </div>
                <!-- Управление файлами -->
                <div class="admin-form-group admin-block admin-block--files">
                    <h3>Файлы</h3>
                    <div class="file-manager-buttons">
                        <button type="button" class="checkout-btn" id="browseImages">Images</button>
                        <button type="button" class="checkout-btn" id="browseFonts">Fonts</button>
                        <button type="button" class="checkout-btn" id="browseIcons">Icons</button>
                    </div>

                    <div id="fileBrowser" class="file-browser">
                        <div class="file-navigation">
                            <span class="current-folder">Текущая папка: <span id="currentFolder"></span></span>
                            <button type="button" class="checkout-btn" id="goBackBtn">
                                <svg class="btn-inline-icon" aria-hidden="true" viewBox="0 0 256 256">
                                    <use href="/images/icons/phosphor-sprite.svg#arrow-left"></use>
                                </svg>
                                <span>Назад</span>
                            </button>
                        </div>

                        <div class="folder-actions">
                            <button type="button" class="checkout-btn" id="createFolderBtn">Создать папку</button>
                        </div>

                        <div id="fileList" class="file-list-container"></div>

                        <div class="admin-form-group file-upload-group">
                            <label>Загрузить файлы:</label>
                            <input type="file" id="fileUpload" multiple>
                            <button type="button" class="checkout-btn" id="uploadFileBtn">Загрузить</button>
                        </div>
                        <div class="upload-progress">
                            <div class="progress-bar">
                                <div class="progress"></div>
                            </div>
                            <div class="progress-text">0%</div>
                        </div>
                    </div>
                </div>

                <!-- ── Бренд ── -->
                <?php
                // Settings.value is a JSON column; decode before displaying
                $bs = static function (string $key, string $default = '') use ($db): string {
                    $raw = $db->getSetting($key);
                    return $raw !== null ? (json_decode($raw, true) ?? $default) : $default;
                };
                $brandAddressValue = (string)$bs('contact_address');
                $brandMapUrlValue = (string)$bs('contact_map_url');
                $publicEntryModeValue = cleanmenu_normalize_tenant_public_entry_mode(
                    (string)$bs('public_entry_mode', ''),
                    !empty($GLOBALS['isProviderMode'])
                );
                if ($brandMapUrlValue === '' && $brandAddressValue !== '' && filter_var($brandAddressValue, FILTER_VALIDATE_URL)) {
                    $brandMapUrlValue = $brandAddressValue;
                    $brandAddressValue = '';
                }
                $launchAcceptance = cleanmenu_launch_acceptance_summary([
                    'app_name' => (string)$bs('app_name', 'labus'),
                    'contact_address' => $brandAddressValue,
                    'contact_map_url' => $brandMapUrlValue,
                    'public_entry_mode' => $publicEntryModeValue,
                    'custom_domain' => (string)$bs('custom_domain'),
                ], !empty($GLOBALS['isProviderMode']));
                ?>
                <div class="admin-form-group admin-block admin-block--brand" id="brandSettings">
                    <h3>Бренд</h3>
                    <div class="brand-fields">
                        <label class="admin-label">
                            Название (ресторан / приложение)
                            <input type="text" id="brandName" class="admin-input"
                                value="<?= htmlspecialchars($bs('app_name', 'labus')) ?>"
                                maxlength="200" placeholder="labus">
                        </label>
                        <label class="admin-label">
                            Слоган
                            <input type="text" id="brandTagline" class="admin-input"
                                value="<?= htmlspecialchars($bs('app_tagline')) ?>"
                                maxlength="200" placeholder="Меню ресторана">
                        </label>
                        <label class="admin-label">
                            Описание (meta / PWA)
                            <textarea id="brandDesc" class="admin-input brand-desc-area" rows="2"
                                maxlength="200" placeholder="Цифровое меню ресторана"><?= htmlspecialchars($bs('app_description')) ?></textarea>
                        </label>
                        <label class="admin-label">
                            Публичный вход tenant-домена
                            <select id="brandPublicEntryMode" class="admin-input">
                                <option value="homepage" <?= $publicEntryModeValue === 'homepage' ? 'selected' : '' ?>>Главная страница ресторана</option>
                                <option value="menu" <?= $publicEntryModeValue === 'menu' ? 'selected' : '' ?>>Сразу в меню</option>
                            </select>
                            <small class="brand-logo-hint">
                                Настройка применяется только для tenant-домена. Provider-домен всегда остаётся B2B landing.
                            </small>
                        </label>
                        <label class="admin-label">
                            Телефон
                            <input type="text" id="brandPhone" class="admin-input"
                                value="<?= htmlspecialchars($bs('contact_phone')) ?>"
                                maxlength="200" placeholder="+79000000000">
                        </label>
                        <label class="admin-label">
                            Ссылка на карту
                            <input type="url" id="brandMapUrl" class="admin-input"
                                value="<?= htmlspecialchars($brandMapUrlValue) ?>"
                                maxlength="200" placeholder="https://yandex.ru/maps/...">
                            <small class="brand-logo-hint">
                                CTA "Приехать" показывается только если здесь сохранён валидный URL.
                            </small>
                        </label>
                        <label class="admin-label">
                            Адрес
                            <input type="text" id="brandAddress" class="admin-input"
                                value="<?= htmlspecialchars($brandAddressValue) ?>"
                                maxlength="200" placeholder="Москва, Цветной б-р, 24">
                            <small class="brand-logo-hint">
                                Текст адреса отображается отдельно от ссылки на карту и не должен содержать URL.
                            </small>
                        </label>
                        <label class="admin-label">
                            Telegram (ссылка)
                            <input type="url" id="brandTg" class="admin-input"
                                value="<?= htmlspecialchars($bs('social_tg')) ?>"
                                maxlength="200" placeholder="https://t.me/...">
                        </label>
                        <label class="admin-label">
                            VK (ссылка)
                            <input type="url" id="brandVk" class="admin-input"
                                value="<?= htmlspecialchars($bs('social_vk')) ?>"
                                maxlength="200" placeholder="https://vk.com/...">
                        </label>
                        <?php $logoUrl = $bs('logo_url'); ?>
                        <label class="admin-label">
                            URL логотипа
                            <small class="brand-logo-hint">Загрузите PNG через файл-менеджер и вставьте путь</small>
                            <input type="text" id="brandLogoUrl" class="admin-input"
                                value="<?= htmlspecialchars($logoUrl) ?>"
                                maxlength="200" placeholder="/images/logo.png">
                            <img id="brandLogoPreview"
                                src="<?= htmlspecialchars($logoUrl) ?>"
                                alt="Превью логотипа"
                                class="brand-logo-preview<?= $logoUrl ? '' : ' brand-logo-preview--hidden' ?>">
                        </label>
                        <label class="admin-label">
                            URL favicon
                            <input type="text" id="brandFaviconUrl" class="admin-input"
                                value="<?= htmlspecialchars($bs('favicon_url', '/icons/favicon.ico')) ?>"
                                maxlength="200" placeholder="/icons/favicon.ico">
                        </label>
                        <label class="admin-label">
                            Собственный домен (White Label)
                            <input type="text" id="brandCustomDomain" class="admin-input"
                                value="<?= htmlspecialchars($bs('custom_domain')) ?>"
                                maxlength="253" placeholder="menu.myrestaurant.ru">
                            <small class="brand-logo-hint">
                                Это информационное поле. Фактическое подключение домена и выбор tenant-БД теперь управляются через внешний tenant registry.
                            </small>
                        </label>
                        <label class="admin-label">
                            Ссылка на отзыв в Google
                            <input type="url" id="brandGoogleReviewUrl" class="admin-input"
                                value="<?= htmlspecialchars($bs('google_review_url')) ?>"
                                maxlength="500" placeholder="https://g.page/r/.../review">
                            <small class="brand-logo-hint">
                                Ссылка, куда гостям предлагается опубликовать 5-звёздочный отзыв после завершения заказа. Если поле пустое — кнопка «Поделиться в Google» скрыта.
                            </small>
                        </label>
                        <label class="admin-label" id="hideBrandingLabel">
                            <input type="checkbox" id="brandHideBranding" <?= $bs('hide_labus_branding') === 'true' ? ' checked' : '' ?>>
                            Скрыть упоминание Labus в публичных страницах
                        </label>
                        <div class="launch-readiness-card">
                            <h4>Launch readiness</h4>
                            <ul class="launch-readiness-list">
                                <?php foreach (($launchAcceptance['items'] ?? []) as $item): ?>
                                    <li class="launch-readiness-item">
                                        <span class="account-badge account-badge--<?= !empty($item['ok']) ? 'fresh' : 'warning' ?>">
                                            <?= !empty($item['ok']) ? 'OK' : 'Check' ?>
                                        </span>
                                        <div class="launch-readiness-copy">
                                            <strong><?= htmlspecialchars((string)($item['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                                            <span><?= htmlspecialchars((string)($item['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if (!empty($launchAcceptance['warnings'])): ?>
                                <div class="launch-readiness-warnings">
                                    <?php foreach ((array)$launchAcceptance['warnings'] as $warning): ?>
                                        <p><?= htmlspecialchars((string)$warning, ENT_QUOTES, 'UTF-8') ?></p>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="brand-save-row">
                            <button id="saveBrandBtn" class="checkout-btn">Сохранить бренд</button>
                            <span id="brandStatus" class="brand-status"></span>
                        </div>
                    </div>
                </div>

                <!-- Управление шрифтами -->
                <div class="admin-form-group admin-block admin-block--fonts">
                    <h3>Шрифты</h3>

                    <div class="font-controls">
                        <div class="font-control">
                            <label>
                                <input type="checkbox" id="fontLogoOverride" class="font-override-checkbox">
                                Изменить шрифт логотипа
                            </label>
                            <select id="fontLogo" class="font-selector" disabled>
                                <option value="'Magistral', serif">Magistral (по умолчанию)</option>
                                <!-- Шрифты будут добавлены динамически -->
                            </select>
                        </div>

                        <div class="font-control">
                            <label>
                                <input type="checkbox" id="fontTextOverride" class="font-override-checkbox">
                                Изменить шрифт текста
                            </label>
                            <select id="fontText" class="font-selector" disabled>
                                <option value="'proxima-nova', sans-serif">Proxima-nova (по умолчанию)</option>
                                <!-- Шрифты будут добавлены динамически -->
                            </select>
                        </div>

                        <div class="font-control">
                            <label>
                                <input type="checkbox" id="fontHeadingOverride" class="font-override-checkbox">
                                Изменить шрифт заголовков
                            </label>
                            <select id="fontHeading" class="font-selector" disabled>
                                <option value="'Inter', sans-serif">Inter (по умолчанию)</option>
                                <!-- Шрифты будут добавлены динамически -->
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Управление цветами -->
                <div class="admin-form-group admin-block admin-block--colors">
                    <h3>Цвета</h3>

                    <div class="color-controls">
                        <?php
                        $colorVariables = [
                            'primary-color' => ['#cd1719', 'Основной цвет'],
                            'secondary-color' => ['#121212', 'Вторичный цвет'],
                            'primary-dark' => ['#000000', 'Тёмный основной'],
                            'accent-color' => ['#db3a34', 'Акцентный цвет'],
                            'text-color' => ['#333333', 'Цвет текста'],
                            'acception' => ['#2c83c2', 'Цвет принятия'],
                            'light-text' => ['#555555', 'Светлый текст'],
                            'bg-light' => ['#f9f9f9', 'Светлый фон'],
                            'white' => ['#ffffff', 'Белый'],
                            'agree' => ['#4CAF50', 'Цвет согласия'],
                            'procces' => ['#ff9321', 'Цвет процесса'],
                            'brown' => ['#712121', 'Коричневый']
                        ];

                        foreach ($colorVariables as $varName => $data):
                            list($defaultValue, $label) = $data;
                            $savedValue = $db->getSetting("color_$varName");
                            $currentValue = $savedValue ? json_decode($savedValue, true) : $defaultValue;
                        ?>
                            <div class="color-control">
                                <label for="color<?= ucfirst(str_replace('-', '', $varName)) ?>"><?= $label ?>:</label>
                                <input class="color" type="color" id="color<?= ucfirst(str_replace('-', '', $varName)) ?>"
                                    data-var="<?= $varName ?>" value="<?= htmlspecialchars($currentValue) ?>">
                                <span class="color-value"><?= htmlspecialchars($currentValue) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Кнопки сохранения -->
                <div class="design-buttons admin-block admin-block--design-actions">
                    <button type="button" class="checkout-btn" id="saveFontsBtn">Сохранить шрифты</button>
                    <button type="button" class="checkout-btn" id="saveColorsBtn">Сохранить цвета</button>
                    <button type="button" class="checkout-btn cancel" id="resetDesignBtn">Сбросить всё</button>
                </div>
            </section>
        </div>

        <?php if (in_array($_SESSION['user_role'] ?? '', ['admin', 'owner'])): ?>
            <!-- System Tab -->
            <?php
            $ykEnabled   = json_decode($db->getSetting('yookassa_enabled')    ?? '"false"', true) ?? 'false';
            $ykShopId    = json_decode($db->getSetting('yookassa_shop_id')    ?? '""', true)      ?? '';
            $ykSecretKey = json_decode($db->getSetting('yookassa_secret_key') ?? '""', true)      ?? '';
            $tbEnabled     = json_decode($db->getSetting('tbank_enabled')      ?? '"false"', true) ?? 'false';
            $tbTerminalKey = json_decode($db->getSetting('tbank_terminal_key') ?? '""', true)      ?? '';
            $tbPassword    = json_decode($db->getSetting('tbank_password')     ?? '""', true)      ?? '';
            $webhookBase   = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
            ?>
            <div class="admin-tab-pane" id="payment">
                <section class="admin-form-container admin-section-card admin-payment-panel">
                    <div class="admin-pane-header">
                        <div class="admin-pane-header-copy">
                            <p class="admin-pane-kicker">Платёжные настройки</p>
                            <h2 class="admin-pane-title">Онлайн-оплата</h2>
                            <p class="admin-pane-caption">ЮKassa и интеграция Т-Банка вынесены по отдельным карточкам, чтобы настройки каждого провайдера было проще проверить, а не искать между ними.</p>
                        </div>
                    </div>
                    <h2>Онлайн-оплата</h2>

                    <div class="admin-form-group admin-block admin-block--provider admin-block--provider-yk">
                        <h3>ЮKassa</h3>
                        <p class="yk-desc">
                            Подключите ЮKassa для приёма онлайн-платежей (карта, СБП, ЮMoney).<br>
                            Webhook URL: <code><?= htmlspecialchars(
                                                    (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '')
                                                ) ?>/payment-webhook.php</code>
                        </p>

                        <div class="project-name-control yk-toggle-row">
                            <label class="yk-toggle-label">Включить онлайн-оплату</label>
                            <input type="checkbox" id="ykEnabled" <?= $ykEnabled === 'true' ? 'checked' : '' ?>
                                class="yk-toggle-input">
                        </div>

                        <div class="project-name-control">
                            <label for="ykShopId">Shop ID</label>
                            <input type="text" id="ykShopId" class="form-group"
                                value="<?= htmlspecialchars($ykShopId) ?>"
                                placeholder="123456" autocomplete="off">
                        </div>

                        <div class="project-name-control">
                            <label for="ykSecretKey">Секретный ключ</label>
                            <input type="password" id="ykSecretKey" class="form-group"
                                value="<?= htmlspecialchars($ykSecretKey) ?>"
                                placeholder="test_XXXXXXXXXXXXXXXXXXXXXXXX" autocomplete="new-password">
                        </div>

                        <div class="yk-save-row">
                            <button type="button" id="savePaymentBtn" class="checkout-btn">
                                Сохранить
                            </button>
                            <span id="paymentStatus" class="yk-status"></span>
                        </div>

                        <p class="yk-note">
                            Тестовые ключи: shop_id начинается с <code>test_</code>. Продуктивные &mdash; без префикса.<br>
                            ФЗ-54: квитанции формируются автоматически через ЮKassa ОФД.
                        </p>
                    </div>

                    <div class="admin-form-group admin-block admin-block--provider admin-block--provider-tbank">
                        <h3>СБП через Т-Банк</h3>
                        <p class="yk-desc">
                            Подключите Т-Банк эквайринг для СБП-платежей напрямую.<br>
                            Webhook URL: <code><?= htmlspecialchars($webhookBase) ?>/payment-webhook.php</code>
                        </p>

                        <div class="project-name-control yk-toggle-row">
                            <label class="yk-toggle-label">Включить СБП через Т-Банк</label>
                            <input type="checkbox" id="tbEnabled" <?= $tbEnabled === 'true' ? 'checked' : '' ?>
                                class="yk-toggle-input">
                        </div>

                        <div class="project-name-control">
                            <label for="tbTerminalKey">Terminal Key</label>
                            <input type="text" id="tbTerminalKey" class="form-group"
                                value="<?= htmlspecialchars($tbTerminalKey) ?>"
                                placeholder="TinkoffBankTest" autocomplete="off">
                        </div>

                        <div class="project-name-control">
                            <label for="tbPassword">Пароль</label>
                            <input type="password" id="tbPassword" class="form-group"
                                value="<?= htmlspecialchars($tbPassword) ?>"
                                placeholder="TinkoffBankTest" autocomplete="new-password">
                        </div>

                        <div class="yk-save-row">
                            <button type="button" id="saveTBankBtn" class="checkout-btn">Сохранить</button>
                            <span id="tbankStatus" class="yk-status"></span>
                        </div>

                        <p class="yk-note">
                            Тестовые ключи: Terminal Key = <code>TinkoffBankTest</code>, Пароль = <code>TinkoffBankTest</code>.<br>
                            Если Т-Банк включён — кнопка «СБП» в корзине маршрутизирует платежи через него.
                        </p>
                    </div>
                </section>
            </div>

            <div class="admin-tab-pane" id="system">
                <section class="admin-form-container admin-section-card admin-system-panel">
                    <div class="admin-pane-header">
                        <div class="admin-pane-header-copy">
                            <p class="admin-pane-kicker">Системные настройки</p>
                            <h2 class="admin-pane-title">Система</h2>
                            <p class="admin-pane-caption">Уведомления и служебные инструменты сгруппированы в отдельные карточки, чтобы ключевые ссылки были под рукой и не спорили с платёжными настройками.</p>
                        </div>
                    </div>
                    <h2>Система</h2>
                    <div class="admin-form-group admin-block admin-block--telegram">
                        <h3>Telegram-уведомления</h3>
                        <p class="yk-desc">
                            Получайте уведомления о новых заказах в Telegram с кнопками «Принять» и «Отказать».<br>
                            Webhook URL для бота: <code><?= htmlspecialchars(
                                                            (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '')
                                                        ) ?>/telegram-webhook.php</code>
                        </p>
                        <div class="project-name-control">
                            <label for="tgChatId">Telegram Chat ID</label>
                            <input type="number" id="tgChatId" class="form-group"
                                value="<?= htmlspecialchars(json_decode($db->getSetting('telegram_chat_id') ?? '""', true) ?? '') ?>"
                                placeholder="-1001234567890">
                        </div>
                        <div class="yk-save-row">
                            <button type="button" id="saveTgChatIdBtn" class="checkout-btn">Сохранить</button>
                            <span id="tgChatIdStatus" class="yk-status"></span>
                        </div>
                    </div>
                    <div class="admin-form-group admin-block admin-block--tools">
                        <h3>Инструменты</h3>
                        <div class="form-actions">
                            <a href="monitor.php" class="checkout-btn">Диагностика</a>
                        </div>
                    </div>
                </section>
            </div>
        <?php endif; ?>
    </div>

    <script src="/js/admin-menu-page.js?v=<?= htmlspecialchars($adminMenuJsVersion, ENT_QUOTES, 'UTF-8') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/file-manager.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-modifiers.js?v=<?= htmlspecialchars($adminModifiersJsVersion, ENT_QUOTES, 'UTF-8') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-tabs-repair.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/undo-toast.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-recipe.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-menu-sort.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-menu-bulk.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-menu-filters.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/hotkeys.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/security.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/cart.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/push-notifications.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>

</html>
