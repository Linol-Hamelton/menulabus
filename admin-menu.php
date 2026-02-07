<?php
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/require_auth.php';
$required_role = 'admin';

// Ensure script nonce is available for CSP
if (empty($scriptNonce) && isset($GLOBALS['scriptNonce'])) {
    $scriptNonce = $GLOBALS['scriptNonce'];
}
// Fallback to session nonce if still empty
if (empty($scriptNonce) && isset($_SESSION['csp_nonce']['script'])) {
    $scriptNonce = $_SESSION['csp_nonce']['script'];
}

$db = Database::getInstance();

$items = $db->getMenuItems();
$categories = array_unique(array_column($items, 'category'));

if ($items === null) {
    $items = $db->getMenuItems(); // Получаем все товары из базы данных
}

// Получаем уникальные категории
$categories = [];
if (!empty($items)) {
    $categories = array_unique(array_column($items, 'category'));
}

$errors = $success = null;

/* --- CRUD logic --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* ---------- 1. Одиночное добавление / редактирование товара ---------- */
    if (isset($_POST['name'])) {
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
                $lines = explode("\n", $fileContent);

                // Определяем разделитель по первой строке
                $delimiter = (strpos($lines[0], ',') !== false) ? ',' : ';';

                // Создаем временный файл для обработки
                $tempHandle = fopen('php://memory', 'r+');
                fwrite($tempHandle, implode("\n", $lines));
                rewind($tempHandle);

                $header = fgetcsv($tempHandle, 0, $delimiter, '"');
                if ($header === false || count($header) < 11) {
                    $_SESSION['error'] = 'Неверный формат файла (меньше 11 колонок)';
                } else {
                    $ok = $db->bulkUpdateMenu($tempHandle, $delimiter);
                    $_SESSION['success'] = $ok ? 'Меню обновлено' : 'Ошибка при обновлении';
                }
                fclose($tempHandle);
            }
        }
        header('Location: admin-menu.php');
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
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">


    <title>Блюда | labus</title>

    <!-- Preloader - мгновенная загрузка -->
    
</head>

<body class="employee-page">
    <?php require_once __DIR__ . '/header.php'; ?>
    <?php require_once __DIR__ . '/account-header.php'; ?>

    <div class="account-form-container">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="message success"><?= htmlspecialchars($_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="message error"><?= htmlspecialchars($_SESSION['error']) ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Admin Tabs -->
        <div class="admin-tabs-container">
            <div class="admin-tabs">
                <button type="button" class="admin-tab-btn active" data-tab="dishes">Блюда</button>
                <button type="button" class="admin-tab-btn" data-tab="design">Дизайн</button>
            </div>
        </div>

        <!-- Dishes Tab -->
        <div class="admin-tab-pane active" id="dishes">
            <section class="admin-form-container">
            <h2><?= $editItem ? 'Редактировать' : 'Обновление' ?></h2>

            <!-- Bulk upload -->
            <section class="admin-form-group">
                <h3>Из CSV</h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <a href="download-sample.php" download="Update.csv" class="download-button-container">Образец</a>
                    <input type="file" name="csv_file" accept=".csv" required>
                    <button type="submit" name="bulk_upload" class="checkout-btn">Загрузить</button>
                </form>
                <small>UTF-8 CSV. Скачайте образец выше.</small>
            </section>

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
        </section>

            <!-- DESKTOP TABLE -->
            <div class="desktop-table">
                <table>
                    <thead>
                        <tr>
                            <th class="first-col">ID</th>
                            <th>Название</th>
                            <th>Категория</th>
                            <th>Цена</th>
                            <th>Дост.</th>
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
                                <td><?= $it['available'] ? '✅' : '❌' ?></td>
                                <td>
                                    <a href="admin-menu.php?edit=<?= $it['id'] ?>" class="admin-checkout-btn">Редактировать</a>
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
                            <div class="mobile-table-row">
                                <span class="mobile-table-label">Доступен:</span>
                                <span class="mobile-table-value"><?= $it['available'] ? 'Да' : 'Нет' ?></span>
                            </div>
                            <div class="mobile-table-actions">
                                <a href="admin-menu.php?edit=<?= $it['id'] ?>" class="mobile-table-btn">
                                    <i class="fas fa-edit"></i> Редактировать
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
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
    </div>

    <!-- TAB-BAR (bottom) -->
    <div class="menu-tabs-container">
        <div class="menu-tabs">
            <?php foreach ($categories as $i => $c): ?>
                <button class="tab-btn <?= $i === 0 ? 'active' : '' ?>" data-tab="<?= htmlspecialchars($c) ?>">
                    <?= htmlspecialchars($c) ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
    <script src="/js/file-manager.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-tabs-repair.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/security.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/cart.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/push-notifications.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>

</html>

