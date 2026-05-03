<?php
/**
 * One-shot cleanup для test-menu_items с битыми путями к картинкам.
 *
 * Audit 2026-05-03 (M6): test items на табе «Тест» ссылаются на файлы,
 * которых на диске нет — console показывает 7×404 на customer-facing
 * /menu.php. Items созданы вручную через admin UI, не в seed-SQL.
 *
 * Реальный DB-формат пути: `./images/Pizza/piperoni.jpg` (relative с
 * префиксом ./), не `/images/Pizza/...`. Используем suffix-match
 * (`LIKE '%/piperoni.jpg'`) чтобы покрыть оба возможных формата
 * (relative `./images/...` И absolute `/images/...`) одним правилом
 * без риска ложных срабатываний на однофамильцах из других папок.
 *
 * Скрипт archive'ит (soft-delete), не удаляет — history сохраняется.
 *
 * Запуск (CLI, на проде, под webuser):
 *   runuser -u labus_pro_usr -- php /var/www/.../scripts/cleanup-broken-image-items.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../tenant_runtime.php';
require_once __DIR__ . '/../db.php';

$db  = Database::getInstance();
$pdo = $db->getConnection();

// Файлы, которые отсутствуют на диске (`/images/Pizza/`, `/Sets/`, `/Snaks/`).
// Используем pair (folder, filename) для точного suffix-match — `LIKE '%Pizza/piperoni.jpg'`
// безопаснее чем `%piperoni.jpg`, исключает случайные коллизии.
$brokenSuffixes = [
    'Pizza/piperoni.jpg',
    'Pizza/margarita.jpg',
    'Pizza/misnaya.jpg',
    'Sets/mangal.jpg',
    'Sets/bavarskiy.jpg',
    'Sets/pivnoy.jpg',
    'Snaks/meatnut.jpg',
    'Snaks/meat.jpg',
    'Snaks/piperonichips.jpg',
];

$stmt = $pdo->prepare('UPDATE menu_items SET archived_at = NOW() WHERE image LIKE :pattern AND archived_at IS NULL');

$total = 0;
foreach ($brokenSuffixes as $suffix) {
    $stmt->execute([':pattern' => '%' . $suffix]);
    $count = $stmt->rowCount();
    $total += $count;
    echo sprintf("  *%s → archived %d row(s)\n", $suffix, $count);
}

echo sprintf("\nDone. Total archived: %d menu_items.\n", $total);
