<?php
/**
 * api/save-menu-view.php — Phase L102 admin/owner-only endpoint для записи
 * tenant-wide menu view selection.
 *
 * Принимает POST из `<form action="/api/save-menu-view.php">` в /admin/menu.php
 * (таб «Дизайн» → блок «Отображение меню для клиентов»).
 *
 * Валидация:
 *   - role: admin/owner (через require_auth.php + $required_role)
 *   - CSRF: header X-CSRF-Token ИЛИ body csrf_token
 *   - menu_view: whitelist Database::MENU_VIEWS
 *
 * Успех — 303 redirect на /admin/menu.php?tab=design с success-флагом в session.
 * Ошибка — 400/403 + редирект назад с error-флагом.
 */
declare(strict_types=1);

$required_role = 'admin';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Csrf.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Method Not Allowed';
    exit;
}

Csrf::requireValid();

$view = isset($_POST['menu_view']) ? (string)$_POST['menu_view'] : '';
$db = Database::getInstance();

$applied = $db->setMenuView($view, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
if ($applied === null) {
    $_SESSION['error'] = 'Неверный вид меню';
    header('Location: /admin/menu.php?tab=design', true, 303);
    exit;
}

$_SESSION['success'] = 'Вид меню применён ко всему тенанту';
header('Location: /admin/menu.php?tab=design', true, 303);
exit;
