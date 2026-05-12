<?php
/**
 * api/v1/odata/menu_items.php — Phase 37 (1С OData read-only).
 */

require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../lib/OData/OData.php';
require_once __DIR__ . '/../../../lib/OData/BasicAuth.php';

$db = Database::getInstance();
\Cleanmenu\OData\BasicAuth::require($db);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    \Cleanmenu\OData\OData::emitError(405, 'method_not_allowed', 'Только GET');
    exit;
}

$allowedFields = [
    'Id'           => 'm.id',
    'Name'         => 'm.name',
    'Description'  => 'm.description',
    'Price'        => 'm.price',
    'Category'     => 'm.category',
    'IsAvailable'  => 'm.is_available',
    'Cost'         => 'm.cost',
    'CreatedAt'    => 'm.created_at',
    'UpdatedAt'    => 'm.updated_at',
];

try {
    $q = \Cleanmenu\OData\OData::parseQuery($_GET, $allowedFields);
} catch (\InvalidArgumentException $e) {
    \Cleanmenu\OData\OData::emitError(400, 'invalid_filter', $e->getMessage());
    exit;
}

$selectCols = [];
foreach ($q['select'] as $f) {
    $selectCols[] = $allowedFields[$f] . ' AS `' . $f . '`';
}
$where = $q['filter_sql'] !== '' ? ' WHERE ' . $q['filter_sql'] : '';
$sql = 'SELECT ' . implode(', ', $selectCols)
     . ' FROM menu_items m'
     . $where
     . ' ORDER BY m.id ASC LIMIT ' . $q['top'] . ' OFFSET ' . $q['skip'];

try {
    $stmt = $db->getConnection()->prepare($sql);
    $stmt->execute($q['filter_params']);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $totalCount = null;
    if ($q['count']) {
        $countSql = 'SELECT COUNT(*) AS n FROM menu_items m' . $where;
        $cs = $db->getConnection()->prepare($countSql);
        $cs->execute($q['filter_params']);
        $cr = $cs->fetch(\PDO::FETCH_ASSOC);
        $totalCount = (int)($cr['n'] ?? 0);
    }
    \Cleanmenu\OData\OData::emit($rows, $totalCount);
} catch (Throwable $e) {
    error_log('odata/menu_items error: ' . $e->getMessage());
    \Cleanmenu\OData\OData::emitError(500, 'internal_error', 'Внутренняя ошибка сервера');
}
