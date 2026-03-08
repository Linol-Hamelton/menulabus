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
    if (isset($_POST['restore_archived'])) {
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
?>
<!DOCTYPE html>
<html lang="ru">

<head>


    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="manifest" href="/manifest.php?v=<?= $appVersion ?>">
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= $appVersion ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= $appVersion ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($adminMenuCssVersion, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= $appVersion ?>">
    <style nonce="<?= $styleNonce ?>">
        .brand-fields {
            display: flex;
            flex-direction: column;
            gap: 12px
        }

        .brand-desc-area {
            resize: vertical
        }

        .brand-logo-hint {
            color: var(--light-text);
            display: block
        }

        .brand-logo-preview {
            max-height: 60px;
            margin-top: 8px;
            border-radius: 6px
        }

        .brand-logo-preview--hidden {
            display: none
        }

        .brand-save-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 4px
        }

        .brand-status {
            font-size: 13px;
            color: var(--agree)
        }

        .yk-desc {
            color: var(--light-text, #777);
            font-size: 13px;
            margin-bottom: 16px
        }

        .yk-toggle-row {
            align-items: center;
            gap: 10px;
            margin-bottom: 14px
        }

        /* Stop-list button */
        .stop-btn {
            padding: 4px 10px;
            border: 1.5px solid var(--primary-color, #cd1719);
            color: var(--primary-color, #cd1719);
            background: transparent;
            border-radius: 6px;
            font-size: .8rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s, color .2s
        }

        .stop-btn:hover {
            background: var(--primary-color, #cd1719);
            color: #fff
        }

        .stop-btn--active {
            background: var(--primary-color, #cd1719);
            color: #fff
        }

        .stop-btn--active:hover {
            background: var(--agree, #4caf50);
            border-color: var(--agree, #4caf50)
        }

        .yk-toggle-label {
            font-weight: 600
        }

        .yk-toggle-input {
            width: 20px;
            height: 20px;
            cursor: pointer
        }

        .yk-save-row {
            margin-top: 16px
        }

        .yk-status {
            margin-left: 12px;
            font-size: 13px
        }

        .yk-note {
            margin-top: 16px;
            font-size: 12px;
            color: var(--light-text, #777)
        }

    </style>

    <title>Блюда | <?= htmlspecialchars($GLOBALS['siteName'] ?? 'labus') ?></title>

    <!-- Preloader - мгновенная загрузка -->

</head>

<body class="employee-page admin-menu-page">
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
            <section class="admin-form-container admin-section-card">
                <div class="admin-pane-header">
                    <div class="admin-pane-header-copy">
                        <p class="admin-pane-kicker">Каталог и наполнение</p>
                        <p class="admin-pane-caption">Загрузка, ручное редактирование и управление текущим каталогом собраны в одном рабочем пространстве.</p>
                    </div>
                </div>
                <div class="admin-dishes-workspace">
                <h2><?= $editItem ? 'Редактировать' : 'Обновление' ?></h2>

                <!-- Bulk upload -->
                <section class="admin-form-group admin-subsection-card">
                    <h3>Из CSV</h3>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <a href="download-sample.php" download="Update.csv" class="download-button-container">Образец</a>
                        <input type="file" name="csv_file" accept=".csv" required>
                        <button type="submit" name="bulk_upload" class="checkout-btn">Загрузить</button>
                    </form>
                    <small>UTF-8 CSV. Полная синхронизация: позиции вне файла будут архивированы. Формат: external_id;name;description;composition;price;image;calories;protein;fat;carbs;category;available</small>
                </section>

                <div class="admin-subsection-card">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="id" value="<?= $editItem['id'] ?? '' ?>">

                    <div class="admin-form-group">
                        <h3>Вручную</h3>
                        <label>Название</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($editItem['name'] ?? '') ?>" required>
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
                    <section class="admin-form-group admin-subsection-card" id="modifiersSection" data-item-id="<?= (int)$editItem['id'] ?>">
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
                            <button id="addModifierGroupBtn" class="checkout-btn">+ Группа</button>
                        </div>
                    </section>
                <?php endif; ?>
            </section>

            <section class="admin-form-container admin-section-card admin-catalog-card">
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

            <!-- DESKTOP TABLE -->
            <div class="desktop-table">
                <table>
                    <thead>
                        <tr>
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
                            <tr class="menu-table tab-row" data-category="<?= htmlspecialchars($it['category']) ?>">
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
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="last-row">
                            <td colspan="6"></td>
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
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            </section>
            </div>
        </div>

        <!-- Design Tab -->
        <div class="admin-tab-pane" id="design">
            <section class="admin-form-container">
                <h2>Управление файлами и дизайном</h2>

                <!-- Название проекта -->
                <div class="admin-form-group">
                    <h3>Название проекта</h3>
                    <div class="project-name-control">
                        <input type="text" id="projectName" value="labus">
                        <button type="button" class="checkout-btn" id="saveProjectNameBtn">Сохранить название</button>
                    </div>
                </div>
                <!-- Управление файлами -->
                <div class="admin-form-group">
                    <h3>Файлы</h3>
                    <div class="file-manager-buttons">
                        <button type="button" class="checkout-btn" id="browseImages">Images</button>
                        <button type="button" class="checkout-btn" id="browseFonts">Fonts</button>
                        <button type="button" class="checkout-btn" id="browseIcons">Icons</button>
                    </div>

                    <div id="fileBrowser" class="file-browser">
                        <div class="file-navigation">
                            <span class="current-folder">Текущая папка: <span id="currentFolder"></span></span>
                            <button type="button" class="checkout-btn" id="goBackBtn">← Назад</button>
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
                ?>
                <div class="admin-form-group" id="brandSettings">
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
                            Телефон
                            <input type="text" id="brandPhone" class="admin-input"
                                value="<?= htmlspecialchars($bs('contact_phone')) ?>"
                                maxlength="200" placeholder="+79000000000">
                        </label>
                        <label class="admin-label">
                            Адрес (ссылка на карту)
                            <input type="text" id="brandAddress" class="admin-input"
                                value="<?= htmlspecialchars($bs('contact_address')) ?>"
                                maxlength="200" placeholder="https://yandex.ru/maps/...">
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
                                Добавьте CNAME-запись: <strong><?= htmlspecialchars($bs('custom_domain') ?: 'menu.myrestaurant.ru') ?></strong> → <strong>menu.labus.pro</strong>,
                                затем уведомите поддержку для выпуска SSL-сертификата.
                            </small>
                        </label>
                        <label class="admin-label" id="hideBrandingLabel">
                            <input type="checkbox" id="brandHideBranding" <?= $bs('hide_labus_branding') === 'true' ? ' checked' : '' ?>>
                            Скрыть упоминание Labus в публичных страницах
                        </label>
                        <div class="brand-save-row">
                            <button id="saveBrandBtn" class="btn-save-colors">Сохранить бренд</button>
                            <span id="brandStatus" class="brand-status"></span>
                        </div>
                    </div>
                </div>

                <!-- Управление шрифтами -->
                <div class="admin-form-group">
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
                <div class="admin-form-group">
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
                <div class="design-buttons">
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
                <section class="admin-form-container">
                    <h2>Онлайн-оплата</h2>

                    <div class="admin-form-group">
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

                    <div class="admin-form-group">
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
                <section class="admin-form-container">
                    <h2>Система</h2>
                    <div class="admin-form-group">
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
                    <div class="admin-form-group">
                        <h3>Инструменты</h3>
                        <div class="form-actions">
                            <a href="monitor.php" class="checkout-btn">Диагностика</a>
                        </div>
                    </div>
                </section>
            </div>
        <?php endif; ?>
    </div>

    <?php
    $savedDbFonts = [
        'logo'    => ($v = $db->getSetting('font_logo'))    ? json_decode($v, true) : null,
        'text'    => ($v = $db->getSetting('font_text'))    ? json_decode($v, true) : null,
        'heading' => ($v = $db->getSetting('font_heading')) ? json_decode($v, true) : null,
    ];
    if (array_filter($savedDbFonts)):
    ?>
        <script nonce="<?= $scriptNonce ?>">
            (function() {
                var db = <?= json_encode($savedDbFonts) ?>;
                var cur = {};
                try {
                    cur = JSON.parse(localStorage.getItem('fontSettings') || '{}');
                } catch (e) {}
                Object.keys(db).forEach(function(k) {
                    if (db[k] !== null) cur[k] = db[k];
                });
                localStorage.setItem('fontSettings', JSON.stringify(cur));
            })();
        </script>
    <?php endif; ?>
    <script nonce="<?= $scriptNonce ?>">
        document.addEventListener('DOMContentLoaded', function() {
            var saveBrandBtn = document.getElementById('saveBrandBtn');
            if (saveBrandBtn) saveBrandBtn.addEventListener('click', function() {
                if (typeof saveBrand === 'function') saveBrand();
            });
            var savePaymentBtn = document.getElementById('savePaymentBtn');
            if (savePaymentBtn) savePaymentBtn.addEventListener('click', savePaymentSettings);
            var saveTBankBtn = document.getElementById('saveTBankBtn');
            if (saveTBankBtn) saveTBankBtn.addEventListener('click', saveTBankSettings);
            var saveTgBtn = document.getElementById('saveTgChatIdBtn');
            if (saveTgBtn) saveTgBtn.addEventListener('click', function() {
                var val = document.getElementById('tgChatId')?.value.trim() || '';
                var csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                var status = document.getElementById('tgChatIdStatus');
                saveTgBtn.disabled = true;
                if (status) status.textContent = 'Сохраняю...';
                fetch('/save-brand.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrf
                        },
                        body: JSON.stringify({
                            brand: {
                                telegram_chat_id: val
                            },
                            csrf_token: csrf
                        })
                    }).then(r => r.json()).then(d => {
                        if (status) status.textContent = d.success ? '✓ Сохранено' : '✗ ' + (d.error || 'Ошибка');
                    }).catch(() => {
                        if (status) status.textContent = '✗ Ошибка сети';
                    })
                    .finally(() => {
                        saveTgBtn.disabled = false;
                    });
            });
            var logoInput = document.getElementById('brandLogoUrl');
            if (logoInput) logoInput.addEventListener('input', function() {
                if (typeof updateBrandLogoPreview === 'function') updateBrandLogoPreview(this.value);
            });
        });
        // Event delegation for .stop-btn (replaces inline onclick handlers — CSP compliance)
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.stop-btn');
            if (!btn) return;
            var id = parseInt(btn.getAttribute('data-item-id'), 10);
            if (id) toggleAvailable(id, btn);
        });
        async function toggleAvailable(id, btn) {
            var csrf = document.querySelector('input[name="csrf_token"]')?.value || '';
            var prev = btn.textContent;
            btn.disabled = true;
            try {
                var r = await fetch('/toggle-available.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrf
                    },
                    body: JSON.stringify({
                        id: id,
                        csrf_token: csrf
                    })
                });
                var d = await r.json();
                if (d.success) {
                    var isAvail = d.available === 1;
                    btn.textContent = isAvail ? 'СТОП' : 'Вернуть';
                    btn.title = isAvail ? 'Снять с продажи' : 'Вернуть в продажу';
                    btn.classList.toggle('stop-btn--active', !isAvail);
                    var row = btn.closest('tr') || btn.closest('.mobile-table-item');
                    if (row) row.style.opacity = isAvail ? '' : '0.5';
                } else {
                    alert(d.error || 'Ошибка');
                }
            } catch (e) {
                alert('Ошибка сети');
            }
            btn.disabled = false;
        }
        async function savePaymentSettings() {
            var status = document.getElementById('paymentStatus');
            status.textContent = 'Сохраняю…';
            var csrf = document.querySelector('input[name="csrf_token"]')?.value || '';
            var body = {
                csrf_token: csrf,
                payment: {
                    yookassa_enabled: document.getElementById('ykEnabled').checked,
                    yookassa_shop_id: document.getElementById('ykShopId').value.trim(),
                    yookassa_secret_key: document.getElementById('ykSecretKey').value.trim(),
                }
            };
            var _ok = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 256 256" fill="currentColor" class="ph-ok" aria-hidden="true"><path d="M173.66,98.34a8,8,0,0,1,0,11.32l-56,56a8,8,0,0,1-11.32,0l-24-24a8,8,0,0,1,11.32-11.32L112,148.69l50.34-50.35A8,8,0,0,1,173.66,98.34ZM232,128A104,104,0,1,1,128,24,104.11,104.11,0,0,1,232,128Zm-16,0a88,88,0,1,0-88,88A88.1,88.1,0,0,0,216,128Z"/></svg> ';
            var _err = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 256 256" fill="currentColor" class="ph-err" aria-hidden="true"><path d="M205.66,194.34a8,8,0,0,1-11.32,11.32L128,139.31l-66.34,66.35a8,8,0,0,1-11.32-11.32L116.69,128,50.34,61.66A8,8,0,0,1,61.66,50.34L128,116.69l66.34-66.35a8,8,0,0,1,11.32,11.32L139.31,128Z"/><path d="M232,128A104,104,0,1,1,128,24,104.11,104.11,0,0,1,232,128Zm-16,0a88,88,0,1,0-88,88A88.1,88.1,0,0,0,216,128Z"/></svg> ';
            try {
                var r = await fetch('/save-payment-settings.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrf
                    },
                    body: JSON.stringify(body)
                });
                var d = await r.json();
                status.innerHTML = d.success ? _ok + 'Сохранено' : (_err + (d.error || 'Ошибка'));
            } catch (e) {
                status.innerHTML = _err + 'Ошибка сети';
            }
        }
        async function saveTBankSettings() {
            var status = document.getElementById('tbankStatus');
            status.textContent = 'Сохраняю…';
            var csrf = document.querySelector('input[name="csrf_token"]')?.value || '';
            var _ok = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 256 256" fill="currentColor" class="ph-ok" aria-hidden="true"><path d="M173.66,98.34a8,8,0,0,1,0,11.32l-56,56a8,8,0,0,1-11.32,0l-24-24a8,8,0,0,1,11.32-11.32L112,148.69l50.34-50.35A8,8,0,0,1,173.66,98.34ZM232,128A104,104,0,1,1,128,24,104.11,104.11,0,0,1,232,128Zm-16,0a88,88,0,1,0-88,88A88.1,88.1,0,0,0,216,128Z"/></svg> ';
            var _err = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 256 256" fill="currentColor" class="ph-err" aria-hidden="true"><path d="M205.66,194.34a8,8,0,0,1-11.32,11.32L128,139.31l-66.34,66.35a8,8,0,0,1-11.32-11.32L116.69,128,50.34,61.66A8,8,0,0,1,61.66,50.34L128,116.69l66.34-66.35a8,8,0,0,1,11.32,11.32L139.31,128Z"/><path d="M232,128A104,104,0,1,1,128,24,104.11,104.11,0,0,1,232,128Zm-16,0a88,88,0,1,0-88,88A88.1,88.1,0,0,0,216,128Z"/></svg> ';
            try {
                var r = await fetch('/save-payment-settings.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrf
                    },
                    body: JSON.stringify({
                        csrf_token: csrf,
                        payment: {
                            tbank_enabled: document.getElementById('tbEnabled').checked,
                            tbank_terminal_key: document.getElementById('tbTerminalKey').value.trim(),
                            tbank_password: document.getElementById('tbPassword').value.trim(),
                        }
                    })
                });
                var d = await r.json();
                status.innerHTML = d.success ? _ok + 'Сохранено' : (_err + (d.error || 'Ошибка'));
            } catch (e) {
                status.innerHTML = _err + 'Ошибка сети';
            }
        }
    </script>
    <script src="/js/file-manager.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-modifiers.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-tabs-repair.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/security.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/cart.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/push-notifications.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>

</html>
