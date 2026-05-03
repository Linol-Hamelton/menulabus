<?php
/**
 * One-shot cleanup для test-menu_items с битыми путями к картинкам.
 *
 * Audit 2026-05-03 (M6): 7 test items на табе «Тест» ссылаются на
 * /images/Pizza/{piperoni,margarita,misnaya}.jpg, /images/Sets/mangal.jpg,
 * /images/Snaks/{meatnut,meat,piperonichips}.jpg — файлов на диске нет,
 * console показывает 7×404 на customer-facing /menu.php.
 *
 * Items были созданы вручную через admin UI, не в seed-SQL. Скрипт
 * archive'ит их (soft-delete) — не удаляет, чтобы не терять history.
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

$brokenPaths = [
    '/images/Pizza/piperoni.jpg',
    '/images/Pizza/margarita.jpg',
    '/images/Pizza/misnaya.jpg',
    '/images/Sets/mangal.jpg',
    '/images/Snaks/meatnut.jpg',
    '/images/Snaks/meat.jpg',
    '/images/Snaks/piperonichips.jpg',
];

$stmt = $pdo->prepare('UPDATE menu_items SET archived_at = NOW() WHERE image = :path AND archived_at IS NULL');

$total = 0;
foreach ($brokenPaths as $path) {
    $stmt->execute([':path' => $path]);
    $count = $stmt->rowCount();
    $total += $count;
    echo sprintf("  %s → archived %d row(s)\n", $path, $count);
}

echo sprintf("\nDone. Total archived: %d menu_items.\n", $total);
