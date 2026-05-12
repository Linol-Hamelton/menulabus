<?php
/**
 * api/v1/odata/customers.php — Phase 37 (1С OData read-only).
 *
 * Exposes users with role='customer' as Customers entity for 1С.
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
    'Id'        => 'u.id',
    'Email'     => 'u.email',
    'Name'      => 'u.name',
    'Phone'     => 'u.phone',
    'IsActive'  => 'u.is_active',
    'CreatedAt' => 'u.created_at',
    'UpdatedAt' => 'u.updated_at',
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
$baseWhere = "u.role = 'customer'";
$where = $q['filter_sql'] !== ''
    ? ' WHERE ' . $baseWhere . ' AND (' . $q['filter_sql'] . ')'
    : ' WHERE ' . $baseWhere;
$sql = 'SELECT ' . implode(', ', $selectCols)
     . ' FROM users u'
     . $where
     . ' ORDER BY u.id ASC LIMIT ' . $q['top'] . ' OFFSET ' . $q['skip'];

try {
    $stmt = $db->getConnection()->prepare($sql);
    $stmt->execute($q['filter_params']);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $totalCount = null;
    if ($q['count']) {
        $countSql = 'SELECT COUNT(*) AS n FROM users u' . $where;
        $cs = $db->getConnection()->prepare($countSql);
        $cs->execute($q['filter_params']);
        $cr = $cs->fetch(\PDO::FETCH_ASSOC);
        $totalCount = (int)($cr['n'] ?? 0);
    }
    \Cleanmenu\OData\OData::emit($rows, $totalCount);
} catch (Throwable $e) {
    error_log('odata/customers error: ' . $e->getMessage());
    \Cleanmenu\OData\OData::emitError(500, 'internal_error', 'Внутренняя ошибка сервера');
}
