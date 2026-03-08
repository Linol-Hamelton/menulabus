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

$menuView = (($_GET['view'] ?? 'active') === 'archived') ? 'archived' : 'active';
$showArchived = $menuView === 'archived';
$items = $showArchived
    ? $db->getArchivedMenuItems()
    : $db->getMenuItems(null, false);

// РџРѕР»СѓС‡Р°РµРј СѓРЅРёРєР°Р»СЊРЅС‹Рµ РєР°С‚РµРіРѕСЂРёРё
$categories = !empty($items)
    ? array_values(array_unique(array_column($items, 'category')))
    : [];

$errors = $success = null;

/* --- CRUD logic --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* ---------- 1. РћРґРёРЅРѕС‡РЅРѕРµ РґРѕР±Р°РІР»РµРЅРёРµ / СЂРµРґР°РєС‚РёСЂРѕРІР°РЅРёРµ С‚РѕРІР°СЂР° ---------- */
    if (isset($_POST['restore_archived'])) {
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'РћС€РёР±РєР° Р±РµР·РѕРїР°СЃРЅРѕСЃС‚Рё';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $_SESSION['error'] = 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ ID';
            } else {
                $ok = $db->restoreArchivedMenuItem($id);
                $_SESSION[$ok ? 'success' : 'error'] = $ok
                    ? 'Р‘Р»СЋРґРѕ РІРѕСЃСЃС‚Р°РЅРѕРІР»РµРЅРѕ РёР· Р°СЂС…РёРІР°'
                    : 'РќРµ СѓРґР°Р»РѕСЃСЊ РІРѕСЃСЃС‚Р°РЅРѕРІРёС‚СЊ Р±Р»СЋРґРѕ';
            }
        }
        header('Location: admin-menu.php?view=archived');
        exit;
    } elseif (isset($_POST['name'])) {
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'РћС€РёР±РєР° Р±РµР·РѕРїР°СЃРЅРѕСЃС‚Рё';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $price = (float)($_POST['price'] ?? 0);
            $image = trim($_POST['image'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $available = isset($_POST['available']) ? 1 : 0;

            // РћР±СЂР°Р±РѕС‚РєР° РїРѕР»СЏ composition
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
                    $_SESSION['success'] = 'РўРѕРІР°СЂ РѕР±РЅРѕРІР»С‘РЅ!';
                    header('Location: admin-menu.php?edit=' . $id);
                    exit;
                }
                $_SESSION['error'] = 'РћС€РёР±РєР° РїСЂРё РѕР±РЅРѕРІР»РµРЅРёРё';
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
                    $_SESSION['success'] = 'РўРѕРІР°СЂ РґРѕР±Р°РІР»РµРЅ!';
                    header('Location: admin-menu.php');
                    exit;
                }
                $_SESSION['error'] = 'РћС€РёР±РєР° РїСЂРё РґРѕР±Р°РІР»РµРЅРёРё';
            }
        }
    }
    /* ---------- 2. РњР°СЃСЃРѕРІР°СЏ Р·Р°РіСЂСѓР·РєР° CSV ---------- */ elseif (isset($_POST['bulk_upload'])) {
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'РћС€РёР±РєР° Р±РµР·РѕРїР°СЃРЅРѕСЃС‚Рё';
        } else {
            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                $_SESSION['error'] = 'РћС€РёР±РєР° Р·Р°РіСЂСѓР·РєРё С„Р°Р№Р»Р°';
            } else {
                $fileContent = file_get_contents($_FILES['csv_file']['tmp_name']);
                if ($fileContent === false || trim($fileContent) === '') {
                    $_SESSION['error'] = 'Р¤Р°Р№Р» РїСѓСЃС‚РѕР№';
                } elseif (function_exists('mb_check_encoding') && !mb_check_encoding($fileContent, 'UTF-8')) {
                    $_SESSION['error'] = 'CSV РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ РІ UTF-8';
                } else {
                    $firstLine = strtok($fileContent, "\r\n");
                    $delimiter = (is_string($firstLine) && strpos($firstLine, ',') !== false) ? ',' : ';';

                    $tempHandle = fopen('php://temp', 'r+');
                    fwrite($tempHandle, $fileContent);
                    rewind($tempHandle);

                    $stats = $db->bulkSyncMenuFromCsv($tempHandle, $delimiter);
                    if (is_array($stats)) {
                        $_SESSION['success'] = sprintf(
                            'РЎРёРЅС…СЂРѕРЅРёР·Р°С†РёСЏ Р·Р°РІРµСЂС€РµРЅР°: РґРѕР±Р°РІР»РµРЅРѕ %d, РѕР±РЅРѕРІР»РµРЅРѕ %d, РІРѕСЃСЃС‚Р°РЅРѕРІР»РµРЅРѕ %d, Р°СЂС…РёРІРёСЂРѕРІР°РЅРѕ %d.',
                            (int)($stats['inserted'] ?? 0),
                            (int)($stats['updated'] ?? 0),
                            (int)($stats['restored_from_archive'] ?? 0),
                            (int)($stats['archived_missing'] ?? 0)
                        );
                    } elseif (!isset($_SESSION['error'])) {
                        $_SESSION['error'] = 'РћС€РёР±РєР° РїСЂРё СЃРёРЅС…СЂРѕРЅРёР·Р°С†РёРё CSV';
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
    <link rel="manifest" href="/manifest.php?v=<?= $appVersion ?>">
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= $appVersion ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= $appVersion ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($adminMenuCssVersion, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= $appVersion ?>">

    <title>Р‘Р»СЋРґР° | <?= htmlspecialchars($GLOBALS['siteName'] ?? 'labus') ?></title>

    <!-- Preloader - РјРіРЅРѕРІРµРЅРЅР°СЏ Р·Р°РіСЂСѓР·РєР° -->

</head>

<body class="employee-page admin-menu-page" data-admin-font-settings="<?= $savedDbFontsJson ?>">
    <?php require_once __DIR__ . '/header.php'; ?>
    <?php require_once __DIR__ . '/account-header.php'; ?>

    <!-- Admin Tabs -->
    <div class="admin-tabs-container">
        <div class="admin-tabs">
            <button type="button" class="admin-tab-btn active" data-tab="dishes">Р‘Р»СЋРґР°</button>
            <button type="button" class="admin-tab-btn" data-tab="design">Р”РёР·Р°Р№РЅ</button>
            <?php if (in_array($_SESSION['user_role'] ?? '', ['admin', 'owner'])): ?>
                <button type="button" class="admin-tab-btn" data-tab="payment">РћРїР»Р°С‚Р°</button>
                <button type="button" class="admin-tab-btn" data-tab="system">РЎРёСЃС‚РµРјР°</button>
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
                        <p class="admin-pane-kicker">РљР°С‚Р°Р»РѕРі Рё РЅР°РїРѕР»РЅРµРЅРёРµ</p>
                        <p class="admin-pane-caption">Р—Р°РіСЂСѓР·РєР°, СЂСѓС‡РЅРѕРµ СЂРµРґР°РєС‚РёСЂРѕРІР°РЅРёРµ Рё СѓРїСЂР°РІР»РµРЅРёРµ С‚РµРєСѓС‰РёРј РєР°С‚Р°Р»РѕРіРѕРј СЃРѕР±СЂР°РЅС‹ РІ РѕРґРЅРѕРј СЂР°Р±РѕС‡РµРј РїСЂРѕСЃС‚СЂР°РЅСЃС‚РІРµ.</p>
                    </div>
                </div>
                <div class="admin-dishes-workspace">
                <h2><?= $editItem ? 'Р РµРґР°РєС‚РёСЂРѕРІР°С‚СЊ' : 'РћР±РЅРѕРІР»РµРЅРёРµ' ?></h2>

                <!-- Bulk upload -->
                <section class="admin-form-group admin-subsection-card">
                    <h3>РР· CSV</h3>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <a href="download-sample.php" download="Update.csv" class="download-button-container">РћР±СЂР°Р·РµС†</a>
                        <input type="file" name="csv_file" accept=".csv" required>
                        <button type="submit" name="bulk_upload" class="checkout-btn">Р—Р°РіСЂСѓР·РёС‚СЊ</button>
                    </form>
                    <small>UTF-8 CSV. РџРѕР»РЅР°СЏ СЃРёРЅС…СЂРѕРЅРёР·Р°С†РёСЏ: РїРѕР·РёС†РёРё РІРЅРµ С„Р°Р№Р»Р° Р±СѓРґСѓС‚ Р°СЂС…РёРІРёСЂРѕРІР°РЅС‹. Р¤РѕСЂРјР°С‚: external_id;name;description;composition;price;image;calories;protein;fat;carbs;category;available</small>
                </section>

                <div class="admin-subsection-card">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="id" value="<?= $editItem['id'] ?? '' ?>">

                    <div class="admin-form-group">
                        <h3>Р’СЂСѓС‡РЅСѓСЋ</h3>
                        <label>РќР°Р·РІР°РЅРёРµ</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($editItem['name'] ?? '') ?>" required>
                    </div>

                    <div class="admin-form-group">
                        <label>РћРїРёСЃР°РЅРёРµ</label>
                        <textarea name="description" rows="3"><?= htmlspecialchars($editItem['description'] ?? '') ?></textarea>
                    </div>

                    <div class="admin-form-group">
                        <label>РЎРѕСЃС‚Р°РІ</label>
                        <textarea name="composition" rows="2"><?= htmlspecialchars($editItem['composition'] ?? '') ?></textarea>
                        <small>Р Р°Р·РґРµР»СЏР№С‚Рµ РёРЅРіСЂРµРґРёРµРЅС‚С‹ Р·Р°РїСЏС‚С‹РјРё (РЅР°РїСЂРёРјРµСЂ: "СЏР№С†Рѕ, РјСѓРєР°, РјРѕР»РѕРєРѕ")</small>
                    </div>

                    <!-- РљР°Р»РѕСЂРёР№РЅРѕСЃС‚СЊ Рё Р‘Р–РЈ -->
                    <div class="admin-form-group">
                        <label>РљР°Р»РѕСЂРёР№РЅРѕСЃС‚СЊ (РєРєР°Р»)</label>
                        <input type="number" name="calories" value="<?= $editItem['calories'] ?? '' ?>">
                    </div>

                    <div class="admin-form-group">
                        <label>Р‘РµР»РєРё (Рі)</label>
                        <input type="number" name="protein" value="<?= $editItem['protein'] ?? '' ?>">
                    </div>

                    <div class="admin-form-group">
                        <label>Р–РёСЂС‹ (Рі)</label>
                        <input type="number" name="fat" value="<?= $editItem['fat'] ?? '' ?>">
                    </div>

                    <div class="admin-form-group">
                        <label>РЈРіР»РµРІРѕРґС‹ (Рі)</label>
                        <input type="number" name="carbs" value="<?= $editItem['carbs'] ?? '' ?>">
                    </div>

                    <div class="admin-form-group">
                        <label>Р¦РµРЅР° (в‚Ѕ)</label>
                        <input type="number" step="0.01" name="price" value="<?= $editItem['price'] ?? '' ?>" required>
                    </div>

                    <div class="admin-form-group">
                        <label>РР·РѕР±СЂР°Р¶РµРЅРёРµ (./dir/name.jpg)</label>
                        <input type="text" name="image" value="<?= htmlspecialchars($editItem['image'] ?? '') ?>">
                    </div>

                    <div class="admin-form-group">
                        <label>РљР°С‚РµРіРѕСЂРёСЏ</label>
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
                            Р”РѕСЃС‚СѓРїРµРЅ
                        </label>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="checkout-btn"><?= $editItem ? 'РЎРѕС…СЂР°РЅРёС‚СЊ' : 'Р”РѕР±Р°РІРёС‚СЊ' ?></button>
                        <?php if ($editItem): ?>
                            <a href="admin-menu.php" class="admin-checkout-btn cancel">РћС‚РјРµРЅР°</a>
                        <?php endif; ?>
                    </div>
                </form>
                </div>
                </div>

                <?php if ($editItem): ?>
                    <!-- в”Ђв”Ђ РњРѕРґРёС„РёРєР°С‚РѕСЂС‹ (С‚РѕР»СЊРєРѕ РїСЂРё СЂРµРґР°РєС‚РёСЂРѕРІР°РЅРёРё) в”Ђв”Ђ -->
                    <section class="admin-form-group admin-subsection-card" id="modifiersSection" data-item-id="<?= (int)$editItem['id'] ?>">
                        <h3>РњРѕРґРёС„РёРєР°С‚РѕСЂС‹ (РІР°СЂРёР°РЅС‚С‹ Р±Р»СЋРґР°)</h3>
                        <p class="yk-desc">РќР°РїСЂРёРјРµСЂ: В«РЎС‚РµРїРµРЅСЊ РїСЂРѕР¶Р°СЂРєРёВ» СЃ РІР°СЂРёР°РЅС‚Р°РјРё Medium / Well-done, РёР»Рё В«Р”РѕР±Р°РІРєРёВ» СЃ РЅРµСЃРєРѕР»СЊРєРёРјРё РІР°СЂРёР°РЅС‚Р°РјРё.</p>
                        <div id="modifierGroupList"></div>
                        <div class="mod-new-group-row">
                            <input type="text" id="newGroupName" placeholder="РќР°Р·РІР°РЅРёРµ РіСЂСѓРїРїС‹" maxlength="100">
                            <select id="newGroupType">
                                <option value="radio">РћРґРёРЅ РІР°СЂРёР°РЅС‚ (radio)</option>
                                <option value="checkbox">РќРµСЃРєРѕР»СЊРєРѕ (checkbox)</option>
                            </select>
                            <label>
                                <input type="checkbox" id="newGroupRequired"> РћР±СЏР·Р°С‚РµР»СЊРЅРѕ
                            </label>
                            <button id="addModifierGroupBtn" class="checkout-btn">+ Р“СЂСѓРїРїР°</button>
                        </div>
                    </section>
                <?php endif; ?>
            </section>

            <section class="admin-form-container admin-section-card admin-catalog-card">
            <div class="admin-catalog-toolbar">
                <div class="form-actions menu-view-switch">
                <a href="admin-menu.php?view=active" class="admin-checkout-btn<?= !$showArchived ? ' cancel' : '' ?>">РђРєС‚РёРІРЅС‹Рµ</a>
                <a href="admin-menu.php?view=archived" class="admin-checkout-btn<?= $showArchived ? ' cancel' : '' ?>">РђСЂС…РёРІ</a>
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
                            <th>РќР°Р·РІР°РЅРёРµ</th>
                            <th>РљР°С‚РµРіРѕСЂРёСЏ</th>
                            <th>Р¦РµРЅР°</th>
                            <th><?= $showArchived ? 'РђСЂС…РёРІРёСЂРѕРІР°РЅ' : 'РЎС‚РѕРї' ?></th>
                            <th class="last-col">Р”РµР№СЃС‚РІРёСЏ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr class="menu-table tab-row" data-category="<?= htmlspecialchars($it['category']) ?>">
                                <td><?= $it['id'] ?></td>
                                <td><?= htmlspecialchars($it['name']) ?></td>
                                <td><?= htmlspecialchars($it['category']) ?></td>
                                <td><?= number_format($it['price'], 2) ?> в‚Ѕ</td>
                                <td>
                                    <?php if ($showArchived): ?>
                                        <?= htmlspecialchars((string)($it['archived_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    <?php else: ?>
                                        <button class="stop-btn <?= $it['available'] ? '' : 'stop-btn--active' ?>"
                                            data-item-id="<?= (int)$it['id'] ?>"
                                            title="<?= $it['available'] ? 'РЎРЅСЏС‚СЊ СЃ РїСЂРѕРґР°Р¶Рё' : 'Р’РµСЂРЅСѓС‚СЊ РІ РїСЂРѕРґР°Р¶Сѓ' ?>">
                                            <?= $it['available'] ? 'РЎРўРћРџ' : 'Р’РµСЂРЅСѓС‚СЊ' ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($showArchived): ?>
                                        <form method="POST" class="inline-action-form">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                            <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                            <button type="submit" name="restore_archived" class="admin-checkout-btn">Р’РѕСЃСЃС‚Р°РЅРѕРІРёС‚СЊ</button>
                                        </form>
                                    <?php else: ?>
                                        <a href="admin-menu.php?edit=<?= $it['id'] ?>" class="admin-checkout-btn">Р РµРґР°РєС‚РёСЂРѕРІР°С‚СЊ</a>
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
                                <span class="mobile-table-label">РќР°Р·РІР°РЅРёРµ:</span>
                                <span class="mobile-table-value"><?= htmlspecialchars($it['name']) ?></span>
                            </div>
                            <div class="mobile-table-row">
                                <span class="mobile-table-label">РљР°С‚РµРіРѕСЂРёСЏ:</span>
                                <span class="mobile-table-value"><?= htmlspecialchars($it['category']) ?></span>
                            </div>
                            <div class="mobile-table-row">
                                <span class="mobile-table-label">Р¦РµРЅР°:</span>
                                <span class="mobile-table-value"><?= number_format($it['price'], 2) ?> в‚Ѕ</span>
                            </div>
                            <?php if ($showArchived): ?>
                                <div class="mobile-table-row">
                                    <span class="mobile-table-label">РђСЂС…РёРІРёСЂРѕРІР°РЅ:</span>
                                    <span class="mobile-table-value"><?= htmlspecialchars((string)($it['archived_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="mobile-table-actions">
                                <?php if ($showArchived): ?>
                                    <form method="POST" class="inline-action-form">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                        <button type="submit" name="restore_archived" class="mobile-table-btn">
                                            Р’РѕСЃСЃС‚Р°РЅРѕРІРёС‚СЊ
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button class="stop-btn <?= $it['available'] ? '' : 'stop-btn--active' ?>"
                                        data-item-id="<?= (int)$it['id'] ?>"
                                        title="<?= $it['available'] ? 'РЎРЅСЏС‚СЊ СЃ РїСЂРѕРґР°Р¶Рё' : 'Р’РµСЂРЅСѓС‚СЊ РІ РїСЂРѕРґР°Р¶Сѓ' ?>">
                                        <?= $it['available'] ? 'РЎРўРћРџ' : 'Р’РµСЂРЅСѓС‚СЊ' ?>
                                    </button>
                                    <a href="admin-menu.php?edit=<?= $it['id'] ?>" class="mobile-table-btn">
                                        Р РµРґР°РєС‚РёСЂРѕРІР°С‚СЊ
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
            <section class="admin-form-container admin-section-card admin-design-panel">
                <div class="admin-pane-header">
                    <div class="admin-pane-header-copy">
                        <p class="admin-pane-kicker">Визуал и бренд</p>
                        <h2 class="admin-pane-title">Управление файлами и дизайном</h2>
                        <p class="admin-pane-caption">Файлы, бренд, шрифты и цвета собраны в одном рабочем пространстве, чтобы дизайн-операции были короче и чище.</p>
                    </div>
                </div>
                <h2>РЈРїСЂР°РІР»РµРЅРёРµ С„Р°Р№Р»Р°РјРё Рё РґРёР·Р°Р№РЅРѕРј</h2>

                <!-- РќР°Р·РІР°РЅРёРµ РїСЂРѕРµРєС‚Р° -->
                <div class="admin-form-group">
                    <h3>РќР°Р·РІР°РЅРёРµ РїСЂРѕРµРєС‚Р°</h3>
                    <div class="project-name-control">
                        <input type="text" id="projectName" value="labus">
                        <button type="button" class="checkout-btn" id="saveProjectNameBtn">РЎРѕС…СЂР°РЅРёС‚СЊ РЅР°Р·РІР°РЅРёРµ</button>
                    </div>
                </div>
                <!-- РЈРїСЂР°РІР»РµРЅРёРµ С„Р°Р№Р»Р°РјРё -->
                <div class="admin-form-group">
                    <h3>Р¤Р°Р№Р»С‹</h3>
                    <div class="file-manager-buttons">
                        <button type="button" class="checkout-btn" id="browseImages">Images</button>
                        <button type="button" class="checkout-btn" id="browseFonts">Fonts</button>
                        <button type="button" class="checkout-btn" id="browseIcons">Icons</button>
                    </div>

                    <div id="fileBrowser" class="file-browser">
                        <div class="file-navigation">
                            <span class="current-folder">РўРµРєСѓС‰Р°СЏ РїР°РїРєР°: <span id="currentFolder"></span></span>
                            <button type="button" class="checkout-btn" id="goBackBtn">в†ђ РќР°Р·Р°Рґ</button>
                        </div>

                        <div class="folder-actions">
                            <button type="button" class="checkout-btn" id="createFolderBtn">РЎРѕР·РґР°С‚СЊ РїР°РїРєСѓ</button>
                        </div>

                        <div id="fileList" class="file-list-container"></div>

                        <div class="admin-form-group file-upload-group">
                            <label>Р—Р°РіСЂСѓР·РёС‚СЊ С„Р°Р№Р»С‹:</label>
                            <input type="file" id="fileUpload" multiple>
                            <button type="button" class="checkout-btn" id="uploadFileBtn">Р—Р°РіСЂСѓР·РёС‚СЊ</button>
                        </div>
                        <div class="upload-progress">
                            <div class="progress-bar">
                                <div class="progress"></div>
                            </div>
                            <div class="progress-text">0%</div>
                        </div>
                    </div>
                </div>

                <!-- в”Ђв”Ђ Р‘СЂРµРЅРґ в”Ђв”Ђ -->
                <?php
                // Settings.value is a JSON column; decode before displaying
                $bs = static function (string $key, string $default = '') use ($db): string {
                    $raw = $db->getSetting($key);
                    return $raw !== null ? (json_decode($raw, true) ?? $default) : $default;
                };
                ?>
                <div class="admin-form-group" id="brandSettings">
                    <h3>Р‘СЂРµРЅРґ</h3>
                    <div class="brand-fields">
                        <label class="admin-label">
                            РќР°Р·РІР°РЅРёРµ (СЂРµСЃС‚РѕСЂР°РЅ / РїСЂРёР»РѕР¶РµРЅРёРµ)
                            <input type="text" id="brandName" class="admin-input"
                                value="<?= htmlspecialchars($bs('app_name', 'labus')) ?>"
                                maxlength="200" placeholder="labus">
                        </label>
                        <label class="admin-label">
                            РЎР»РѕРіР°РЅ
                            <input type="text" id="brandTagline" class="admin-input"
                                value="<?= htmlspecialchars($bs('app_tagline')) ?>"
                                maxlength="200" placeholder="РњРµРЅСЋ СЂРµСЃС‚РѕСЂР°РЅР°">
                        </label>
                        <label class="admin-label">
                            РћРїРёСЃР°РЅРёРµ (meta / PWA)
                            <textarea id="brandDesc" class="admin-input brand-desc-area" rows="2"
                                maxlength="200" placeholder="Р¦РёС„СЂРѕРІРѕРµ РјРµРЅСЋ СЂРµСЃС‚РѕСЂР°РЅР°"><?= htmlspecialchars($bs('app_description')) ?></textarea>
                        </label>
                        <label class="admin-label">
                            РўРµР»РµС„РѕРЅ
                            <input type="text" id="brandPhone" class="admin-input"
                                value="<?= htmlspecialchars($bs('contact_phone')) ?>"
                                maxlength="200" placeholder="+79000000000">
                        </label>
                        <label class="admin-label">
                            РђРґСЂРµСЃ (СЃСЃС‹Р»РєР° РЅР° РєР°СЂС‚Сѓ)
                            <input type="text" id="brandAddress" class="admin-input"
                                value="<?= htmlspecialchars($bs('contact_address')) ?>"
                                maxlength="200" placeholder="https://yandex.ru/maps/...">
                        </label>
                        <label class="admin-label">
                            Telegram (СЃСЃС‹Р»РєР°)
                            <input type="url" id="brandTg" class="admin-input"
                                value="<?= htmlspecialchars($bs('social_tg')) ?>"
                                maxlength="200" placeholder="https://t.me/...">
                        </label>
                        <label class="admin-label">
                            VK (СЃСЃС‹Р»РєР°)
                            <input type="url" id="brandVk" class="admin-input"
                                value="<?= htmlspecialchars($bs('social_vk')) ?>"
                                maxlength="200" placeholder="https://vk.com/...">
                        </label>
                        <?php $logoUrl = $bs('logo_url'); ?>
                        <label class="admin-label">
                            URL Р»РѕРіРѕС‚РёРїР°
                            <small class="brand-logo-hint">Р—Р°РіСЂСѓР·РёС‚Рµ PNG С‡РµСЂРµР· С„Р°Р№Р»-РјРµРЅРµРґР¶РµСЂ Рё РІСЃС‚Р°РІСЊС‚Рµ РїСѓС‚СЊ</small>
                            <input type="text" id="brandLogoUrl" class="admin-input"
                                value="<?= htmlspecialchars($logoUrl) ?>"
                                maxlength="200" placeholder="/images/logo.png">
                            <img id="brandLogoPreview"
                                src="<?= htmlspecialchars($logoUrl) ?>"
                                alt="РџСЂРµРІСЊСЋ Р»РѕРіРѕС‚РёРїР°"
                                class="brand-logo-preview<?= $logoUrl ? '' : ' brand-logo-preview--hidden' ?>">
                        </label>
                        <label class="admin-label">
                            URL favicon
                            <input type="text" id="brandFaviconUrl" class="admin-input"
                                value="<?= htmlspecialchars($bs('favicon_url', '/icons/favicon.ico')) ?>"
                                maxlength="200" placeholder="/icons/favicon.ico">
                        </label>
                        <label class="admin-label">
                            РЎРѕР±СЃС‚РІРµРЅРЅС‹Р№ РґРѕРјРµРЅ (White Label)
                            <input type="text" id="brandCustomDomain" class="admin-input"
                                value="<?= htmlspecialchars($bs('custom_domain')) ?>"
                                maxlength="253" placeholder="menu.myrestaurant.ru">
                            <small class="brand-logo-hint">
                                Р”РѕР±Р°РІСЊС‚Рµ CNAME-Р·Р°РїРёСЃСЊ: <strong><?= htmlspecialchars($bs('custom_domain') ?: 'menu.myrestaurant.ru') ?></strong> в†’ <strong>menu.labus.pro</strong>,
                                Р·Р°С‚РµРј СѓРІРµРґРѕРјРёС‚Рµ РїРѕРґРґРµСЂР¶РєСѓ РґР»СЏ РІС‹РїСѓСЃРєР° SSL-СЃРµСЂС‚РёС„РёРєР°С‚Р°.
                            </small>
                        </label>
                        <label class="admin-label" id="hideBrandingLabel">
                            <input type="checkbox" id="brandHideBranding" <?= $bs('hide_labus_branding') === 'true' ? ' checked' : '' ?>>
                            РЎРєСЂС‹С‚СЊ СѓРїРѕРјРёРЅР°РЅРёРµ Labus РІ РїСѓР±Р»РёС‡РЅС‹С… СЃС‚СЂР°РЅРёС†Р°С…
                        </label>
                        <div class="brand-save-row">
                            <button id="saveBrandBtn" class="btn-save-colors">РЎРѕС…СЂР°РЅРёС‚СЊ Р±СЂРµРЅРґ</button>
                            <span id="brandStatus" class="brand-status"></span>
                        </div>
                    </div>
                </div>

                <!-- РЈРїСЂР°РІР»РµРЅРёРµ С€СЂРёС„С‚Р°РјРё -->
                <div class="admin-form-group">
                    <h3>РЁСЂРёС„С‚С‹</h3>

                    <div class="font-controls">
                        <div class="font-control">
                            <label>
                                <input type="checkbox" id="fontLogoOverride" class="font-override-checkbox">
                                РР·РјРµРЅРёС‚СЊ С€СЂРёС„С‚ Р»РѕРіРѕС‚РёРїР°
                            </label>
                            <select id="fontLogo" class="font-selector" disabled>
                                <option value="'Magistral', serif">Magistral (РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ)</option>
                                <!-- РЁСЂРёС„С‚С‹ Р±СѓРґСѓС‚ РґРѕР±Р°РІР»РµРЅС‹ РґРёРЅР°РјРёС‡РµСЃРєРё -->
                            </select>
                        </div>

                        <div class="font-control">
                            <label>
                                <input type="checkbox" id="fontTextOverride" class="font-override-checkbox">
                                РР·РјРµРЅРёС‚СЊ С€СЂРёС„С‚ С‚РµРєСЃС‚Р°
                            </label>
                            <select id="fontText" class="font-selector" disabled>
                                <option value="'proxima-nova', sans-serif">Proxima-nova (РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ)</option>
                                <!-- РЁСЂРёС„С‚С‹ Р±СѓРґСѓС‚ РґРѕР±Р°РІР»РµРЅС‹ РґРёРЅР°РјРёС‡РµСЃРєРё -->
                            </select>
                        </div>

                        <div class="font-control">
                            <label>
                                <input type="checkbox" id="fontHeadingOverride" class="font-override-checkbox">
                                РР·РјРµРЅРёС‚СЊ С€СЂРёС„С‚ Р·Р°РіРѕР»РѕРІРєРѕРІ
                            </label>
                            <select id="fontHeading" class="font-selector" disabled>
                                <option value="'Inter', sans-serif">Inter (РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ)</option>
                                <!-- РЁСЂРёС„С‚С‹ Р±СѓРґСѓС‚ РґРѕР±Р°РІР»РµРЅС‹ РґРёРЅР°РјРёС‡РµСЃРєРё -->
                            </select>
                        </div>
                    </div>
                </div>

                <!-- РЈРїСЂР°РІР»РµРЅРёРµ С†РІРµС‚Р°РјРё -->
                <div class="admin-form-group">
                    <h3>Р¦РІРµС‚Р°</h3>

                    <div class="color-controls">
                        <?php
                        $colorVariables = [
                            'primary-color' => ['#cd1719', 'РћСЃРЅРѕРІРЅРѕР№ С†РІРµС‚'],
                            'secondary-color' => ['#121212', 'Р’С‚РѕСЂРёС‡РЅС‹Р№ С†РІРµС‚'],
                            'primary-dark' => ['#000000', 'РўС‘РјРЅС‹Р№ РѕСЃРЅРѕРІРЅРѕР№'],
                            'accent-color' => ['#db3a34', 'РђРєС†РµРЅС‚РЅС‹Р№ С†РІРµС‚'],
                            'text-color' => ['#333333', 'Р¦РІРµС‚ С‚РµРєСЃС‚Р°'],
                            'acception' => ['#2c83c2', 'Р¦РІРµС‚ РїСЂРёРЅСЏС‚РёСЏ'],
                            'light-text' => ['#555555', 'РЎРІРµС‚Р»С‹Р№ С‚РµРєСЃС‚'],
                            'bg-light' => ['#f9f9f9', 'РЎРІРµС‚Р»С‹Р№ С„РѕРЅ'],
                            'white' => ['#ffffff', 'Р‘РµР»С‹Р№'],
                            'agree' => ['#4CAF50', 'Р¦РІРµС‚ СЃРѕРіР»Р°СЃРёСЏ'],
                            'procces' => ['#ff9321', 'Р¦РІРµС‚ РїСЂРѕС†РµСЃСЃР°'],
                            'brown' => ['#712121', 'РљРѕСЂРёС‡РЅРµРІС‹Р№']
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

                <!-- РљРЅРѕРїРєРё СЃРѕС…СЂР°РЅРµРЅРёСЏ -->
                <div class="design-buttons">
                    <button type="button" class="checkout-btn" id="saveFontsBtn">РЎРѕС…СЂР°РЅРёС‚СЊ С€СЂРёС„С‚С‹</button>
                    <button type="button" class="checkout-btn" id="saveColorsBtn">РЎРѕС…СЂР°РЅРёС‚СЊ С†РІРµС‚Р°</button>
                    <button type="button" class="checkout-btn cancel" id="resetDesignBtn">РЎР±СЂРѕСЃРёС‚СЊ РІСЃС‘</button>
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
                            <p class="admin-pane-kicker">Платёжные провайдеры</p>
                            <h2 class="admin-pane-title">Онлайн-оплата</h2>
                            <p class="admin-pane-caption">Ключи и переключатели провайдеров разделены на отдельные карточки, чтобы настройки оплаты читались как рабочая панель, а не как длинная форма.</p>
                        </div>
                    </div>
                    <h2>РћРЅР»Р°Р№РЅ-РѕРїР»Р°С‚Р°</h2>

                    <div class="admin-form-group">
                        <h3>Р®Kassa</h3>
                        <p class="yk-desc">
                            РџРѕРґРєР»СЋС‡РёС‚Рµ Р®Kassa РґР»СЏ РїСЂРёС‘РјР° РѕРЅР»Р°Р№РЅ-РїР»Р°С‚РµР¶РµР№ (РєР°СЂС‚Р°, РЎР‘Рџ, Р®Money).<br>
                            Webhook URL: <code><?= htmlspecialchars(
                                                    (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '')
                                                ) ?>/payment-webhook.php</code>
                        </p>

                        <div class="project-name-control yk-toggle-row">
                            <label class="yk-toggle-label">Р’РєР»СЋС‡РёС‚СЊ РѕРЅР»Р°Р№РЅ-РѕРїР»Р°С‚Сѓ</label>
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
                            <label for="ykSecretKey">РЎРµРєСЂРµС‚РЅС‹Р№ РєР»СЋС‡</label>
                            <input type="password" id="ykSecretKey" class="form-group"
                                value="<?= htmlspecialchars($ykSecretKey) ?>"
                                placeholder="test_XXXXXXXXXXXXXXXXXXXXXXXX" autocomplete="new-password">
                        </div>

                        <div class="yk-save-row">
                            <button type="button" id="savePaymentBtn" class="checkout-btn">
                                РЎРѕС…СЂР°РЅРёС‚СЊ
                            </button>
                            <span id="paymentStatus" class="yk-status"></span>
                        </div>

                        <p class="yk-note">
                            РўРµСЃС‚РѕРІС‹Рµ РєР»СЋС‡Рё: shop_id РЅР°С‡РёРЅР°РµС‚СЃСЏ СЃ <code>test_</code>. РџСЂРѕРґСѓРєС‚РёРІРЅС‹Рµ &mdash; Р±РµР· РїСЂРµС„РёРєСЃР°.<br>
                            Р¤Р—-54: РєРІРёС‚Р°РЅС†РёРё С„РѕСЂРјРёСЂСѓСЋС‚СЃСЏ Р°РІС‚РѕРјР°С‚РёС‡РµСЃРєРё С‡РµСЂРµР· Р®Kassa РћР¤Р”.
                        </p>
                    </div>

                    <div class="admin-form-group">
                        <h3>РЎР‘Рџ С‡РµСЂРµР· Рў-Р‘Р°РЅРє</h3>
                        <p class="yk-desc">
                            РџРѕРґРєР»СЋС‡РёС‚Рµ Рў-Р‘Р°РЅРє СЌРєРІР°Р№СЂРёРЅРі РґР»СЏ РЎР‘Рџ-РїР»Р°С‚РµР¶РµР№ РЅР°РїСЂСЏРјСѓСЋ.<br>
                            Webhook URL: <code><?= htmlspecialchars($webhookBase) ?>/payment-webhook.php</code>
                        </p>

                        <div class="project-name-control yk-toggle-row">
                            <label class="yk-toggle-label">Р’РєР»СЋС‡РёС‚СЊ РЎР‘Рџ С‡РµСЂРµР· Рў-Р‘Р°РЅРє</label>
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
                            <label for="tbPassword">РџР°СЂРѕР»СЊ</label>
                            <input type="password" id="tbPassword" class="form-group"
                                value="<?= htmlspecialchars($tbPassword) ?>"
                                placeholder="TinkoffBankTest" autocomplete="new-password">
                        </div>

                        <div class="yk-save-row">
                            <button type="button" id="saveTBankBtn" class="checkout-btn">РЎРѕС…СЂР°РЅРёС‚СЊ</button>
                            <span id="tbankStatus" class="yk-status"></span>
                        </div>

                        <p class="yk-note">
                            РўРµСЃС‚РѕРІС‹Рµ РєР»СЋС‡Рё: Terminal Key = <code>TinkoffBankTest</code>, РџР°СЂРѕР»СЊ = <code>TinkoffBankTest</code>.<br>
                            Р•СЃР»Рё Рў-Р‘Р°РЅРє РІРєР»СЋС‡С‘РЅ вЂ” РєРЅРѕРїРєР° В«РЎР‘РџВ» РІ РєРѕСЂР·РёРЅРµ РјР°СЂС€СЂСѓС‚РёР·РёСЂСѓРµС‚ РїР»Р°С‚РµР¶Рё С‡РµСЂРµР· РЅРµРіРѕ.
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
                            <p class="admin-pane-caption">Оповещения и служебные инструменты сгруппированы отдельно, чтобы критичные действия были видны сразу и не спорили с настройками каталога.</p>
                        </div>
                    </div>
                    <h2>РЎРёСЃС‚РµРјР°</h2>
                    <div class="admin-form-group">
                        <h3>Telegram-СѓРІРµРґРѕРјР»РµРЅРёСЏ</h3>
                        <p class="yk-desc">
                            РџРѕР»СѓС‡Р°Р№С‚Рµ СѓРІРµРґРѕРјР»РµРЅРёСЏ Рѕ РЅРѕРІС‹С… Р·Р°РєР°Р·Р°С… РІ Telegram СЃ РєРЅРѕРїРєР°РјРё В«РџСЂРёРЅСЏС‚СЊВ» Рё В«РћС‚РєР°Р·Р°С‚СЊВ».<br>
                            Webhook URL РґР»СЏ Р±РѕС‚Р°: <code><?= htmlspecialchars(
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
                            <button type="button" id="saveTgChatIdBtn" class="checkout-btn">РЎРѕС…СЂР°РЅРёС‚СЊ</button>
                            <span id="tgChatIdStatus" class="yk-status"></span>
                        </div>
                    </div>
                    <div class="admin-form-group">
                        <h3>РРЅСЃС‚СЂСѓРјРµРЅС‚С‹</h3>
                        <div class="form-actions">
                            <a href="monitor.php" class="checkout-btn">Р”РёР°РіРЅРѕСЃС‚РёРєР°</a>
                        </div>
                    </div>
                </section>
            </div>
        <?php endif; ?>
    </div>

    <script src="/js/admin-menu-page.js?v=<?= htmlspecialchars($adminMenuJsVersion, ENT_QUOTES, 'UTF-8') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/file-manager.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-modifiers.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-tabs-repair.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/security.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/cart.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/push-notifications.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>

</html>
