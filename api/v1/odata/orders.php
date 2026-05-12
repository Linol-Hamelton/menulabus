<?php
/**
 * api/v1/odata/orders.php — Phase 37 (1С OData read-only).
 *
 * Returns orders as OData v3 envelope. BasicAuth required.
 *
 * Whitelisted fields (mirrored to 1С Конфигуратор):
 *   Id, UserId, Total, Tips, Status, DeliveryType, DeliveryDetails,
 *   PaymentMethod, PaymentStatus, ShiftId, CreatedAt, UpdatedAt
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
    'Id'              => 'o.id',
    'UserId'          => 'o.user_id',
    'Total'           => 'o.total',
    'Tips'            => 'o.tips',
    'Status'          => 'o.status',
    'DeliveryType'    => 'o.delivery_type',
    'DeliveryDetails' => 'o.delivery_details',
    'PaymentMethod'   => 'o.payment_method',
    'PaymentStatus'   => 'o.payment_status',
    'ShiftId'         => 'o.shift_id',
    'CreatedAt'       => 'o.created_at',
    'UpdatedAt'       => 'o.updated_at',
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
     . ' FROM orders o'
     . $where
     . ' ORDER BY o.id DESC LIMIT ' . $q['top'] . ' OFFSET ' . $q['skip'];

try {
    $stmt = $db->getConnection()->prepare($sql);
    $stmt->execute($q['filter_params']);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $totalCount = null;
    if ($q['count']) {
        $countSql = 'SELECT COUNT(*) AS n FROM orders o' . $where;
        $cs = $db->getConnection()->prepare($countSql);
        $cs->execute($q['filter_params']);
        $cr = $cs->fetch(\PDO::FETCH_ASSOC);
        $totalCount = (int)($cr['n'] ?? 0);
    }
    \Cleanmenu\OData\OData::emit($rows, $totalCount);
} catch (Throwable $e) {
    error_log('odata/orders error: ' . $e->getMessage());
    \Cleanmenu\OData\OData::emitError(500, 'internal_error', 'Внутренняя ошибка сервера');
}
