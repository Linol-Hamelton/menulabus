<?php
/**
 * api/save-menu-item.php — AJAX endpoint для CRUD меню-позиции из модального
 * редактора в /admin/menu.php (Phase 16).
 *
 * POST JSON:
 *   { action: 'get',         id: int }                    → { success, item }
 *   { action: 'save',        id?: int, name, description?, composition?,
 *                            price, image?, calories?, protein?, fat?, carbs?,
 *                            category, available?: bool }  → { success, id, item }
 *   { action: 'archive',     id: int }                    → { success }
 *   { action: 'restore',     id: int }                    → { success }
 *   { action: 'inline_save', id: int, field: 'price'|'available', value }
 *                                                          → { success, value }
 *
 * GET (action=get) — для удобства:
 *   /api/save-menu-item.php?action=get&id=42
 *
 * Auth: admin / owner (через require_auth.php).
 * CSRF: header X-CSRF-Token или body csrf_token (для всех мутирующих
 *       actions — get не требует).
 */
declare(strict_types=1);

$required_role = 'admin';
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../require_auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Csrf.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- Read input -------------------------------------------------------------
$input = [];
if ($method === 'GET') {
    $input = $_GET;
} else {
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    } else {
        $input = $_POST;
    }
}

$action = (string)($input['action'] ?? '');

// --- CSRF for mutating actions ---------------------------------------------
$mutating = !in_array($action, ['get'], true);
if ($mutating) {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
        exit;
    }
    Csrf::requireValid();
}

$db = Database::getInstance();

// --- Helpers ----------------------------------------------------------------
$intOrNull = static function ($v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
};

$normaliseComposition = static function (?string $raw): ?string {
    if ($raw === null) return null;
    $trim = trim($raw);
    if ($trim === '') return null;
    // Same normalisation pattern as legacy POST handler in admin/menu.php:
    // collapse whitespace, normalise commas.
    $trim = preg_replace('/\s+/u', ' ', $trim) ?? $trim;
    return $trim;
};

$loadItem = static function (int $id) use ($db): ?array {
    $row = $db->getProductById($id);
    return is_array($row) ? $row : null;
};

// --- Dispatch ---------------------------------------------------------------
switch ($action) {
    case 'get': {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_id']);
            exit;
        }
        $item = $loadItem($id);
        if (!$item) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'not_found']);
            exit;
        }
        echo json_encode(['success' => true, 'item' => $item], JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'save': {
        $id          = isset($input['id']) ? (int)$input['id'] : 0;
        $name        = trim((string)($input['name'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $composition = $normaliseComposition($input['composition'] ?? null);
        $price       = (float)($input['price'] ?? -1);
        $image       = trim((string)($input['image'] ?? ''));
        $calories    = $intOrNull($input['calories'] ?? null);
        $protein     = $intOrNull($input['protein'] ?? null);
        $fat         = $intOrNull($input['fat'] ?? null);
        $carbs       = $intOrNull($input['carbs'] ?? null);
        $category    = trim((string)($input['category'] ?? ''));
        $available   = !empty($input['available']) ? 1 : 0;

        if ($name === '' || $category === '' || $price < 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_params']);
            exit;
        }
        if (mb_strlen($name) > 200 || mb_strlen($category) > 50) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'field_too_long']);
            exit;
        }

        if ($id > 0) {
            $ok = $db->updateMenuItems($id, $name, $description, $composition, $price, $image ?: null,
                $calories, $protein, $fat, $carbs, $category, $available);
            if (!$ok) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'update_failed']);
                exit;
            }
            $item = $loadItem($id);
            echo json_encode(['success' => true, 'id' => $id, 'item' => $item, 'created' => false], JSON_UNESCAPED_UNICODE);
        } else {
            $newId = $db->addMenuItem($name, $description, $composition, $price, $image ?: null,
                $calories, $protein, $fat, $carbs, $category, $available);
            if (!$newId) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'create_failed']);
                exit;
            }
            $item = $loadItem((int)$newId);
            echo json_encode(['success' => true, 'id' => (int)$newId, 'item' => $item, 'created' => true], JSON_UNESCAPED_UNICODE);
        }
        break;
    }

    case 'archive': {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_id']);
            exit;
        }
        $ok = $db->archiveMenuItem($id);
        echo json_encode(['success' => (bool)$ok]);
        break;
    }

    case 'restore': {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_id']);
            exit;
        }
        $ok = $db->restoreArchivedMenuItem($id);
        echo json_encode(['success' => (bool)$ok]);
        break;
    }

    case 'inline_save': {
        $id    = (int)($input['id'] ?? 0);
        $field = (string)($input['field'] ?? '');
        $value = $input['value'] ?? null;

        if ($id <= 0 || !in_array($field, ['price', 'available', 'category'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_params']);
            exit;
        }

        $current = $loadItem($id);
        if (!$current) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'not_found']);
            exit;
        }

        // Patch single field, keep the rest.
        $newPrice    = (float)$current['price'];
        $newAvail    = (int)$current['available'];
        $newCategory = (string)$current['category'];

        if ($field === 'price') {
            $newPrice = (float)$value;
            if ($newPrice < 0 || $newPrice > 9999999) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'invalid_price']);
                exit;
            }
        } elseif ($field === 'available') {
            $newAvail = !empty($value) ? 1 : 0;
        } elseif ($field === 'category') {
            $newCategory = trim((string)$value);
            if ($newCategory === '' || mb_strlen($newCategory) > 50) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'invalid_category']);
                exit;
            }
        }

        $ok = $db->updateMenuItems(
            $id,
            (string)$current['name'],
            (string)$current['description'],
            $current['composition'] !== null ? (string)$current['composition'] : null,
            $newPrice,
            $current['image'] !== null ? (string)$current['image'] : null,
            $intOrNull($current['calories']),
            $intOrNull($current['protein']),
            $intOrNull($current['fat']),
            $intOrNull($current['carbs']),
            $newCategory,
            $newAvail
        );
        if (!$ok) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'update_failed']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'field'   => $field,
            'value'   => $field === 'price' ? $newPrice : ($field === 'available' ? $newAvail : $newCategory),
        ], JSON_UNESCAPED_UNICODE);
        break;
    }

    default: {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
        exit;
    }
}
