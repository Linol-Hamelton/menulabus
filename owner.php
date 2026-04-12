<?php
// owner.php
$required_role = 'owner';
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/lib/orders/lifecycle.php';
$csrfToken = $_SESSION['csrf_token'] ?? '';

// Ensure script nonce is available for CSP
if (empty($scriptNonce) && isset($GLOBALS['scriptNonce'])) {
    $scriptNonce = $GLOBALS['scriptNonce'];
}
// Fallback to session nonce if still empty
if (empty($scriptNonce) && isset($_SESSION['csp_nonce']['script'])) {
    $scriptNonce = $_SESSION['csp_nonce']['script'];
}

$db = Database::getInstance();

// Получаем пользователей для вкладки "Пользователи"
$users = $db->getAllUsers();

// Последние 50 отзывов для вкладки "Отзывы"
$recentReviews = $db->getRecentReviews(50);
$reviewRatingAvg = 0.0;
$reviewRatingCount = 0;
foreach ($recentReviews as $r) {
    $rv = (int)($r['rating'] ?? 0);
    if ($rv >= 1 && $rv <= 5) {
        $reviewRatingAvg += $rv;
        $reviewRatingCount++;
    }
}
if ($reviewRatingCount > 0) {
    $reviewRatingAvg = round($reviewRatingAvg / $reviewRatingCount, 1);
}

// Определяем активную вкладку
$tab = $_GET['tab'] ?? 'stats';

// Получаем данные для отчетов
$period = $_GET['period'] ?? 'day';
$report_type = $_GET['report'] ?? 'sales';

// Валидация периода
$valid_periods = ['day', 'week', 'month', 'year'];
$period = in_array($period, $valid_periods) ? $period : 'day';

// Валидация типа отчета
$valid_reports = ['sales', 'profit', 'efficiency', 'customers', 'dishes', 'load', 'employees', 'bottlenecks'];
$report_type = in_array($report_type, $valid_reports) ? $report_type : 'sales';

function translateFieldName($fieldName)
{
    $translations = [
        'date' => 'Дата',
        'order_count' => 'Заказов',
        'total_revenue' => 'Выручка',
        'total_profit' => 'Прибыль',
        'total_expenses' => 'Расходы',
        'profitability_percent' => 'Рентабельность',
        'delivery_type' => 'Тип доставки',
        'avg_time_minutes' => 'Время(мин)',
        'id' => 'ID',
        'name' => 'Имя',
        'phone' => 'Телефон',
        'total_spent' => 'Расходы',
        'avg_order_value' => 'Средний чек',
        'category' => 'Категория',
        'total_quantity' => 'Заказов',
        'hour' => 'Час',
        'avg_processing_time' => 'Время(мин)',
        'order_id' => '№ заказа',
        'time' => 'Время',
        'Время' => 'Время',
        'time_minutes' => 'Время (мин)',
        'order_total' => 'Сумма заказа',
        'item_count' => 'Товаров',
        'order_expenses' => 'Расходы',
        'order_profit' => 'Прибыль',
        'processing_time' => 'Время (мин)',
        'abc'         => 'ABC',
        'revenue_pct' => '% выручки',
        'stage' => 'Этап',
        'avg_minutes' => 'Среднее (мин)',
        'max_minutes' => 'Максимум (мин)',
        'orders_count' => 'Заказов',
    ];

    return $translations[$fieldName] ?? ucfirst(str_replace('_', ' ', $fieldName));
}

function translateDeliveryType($type)
{
    $translations = [
        'delivery' => 'Доставка',
        'takeaway' => 'Самовывоз',
        'bar' => 'Бар',
        'table' => 'В заведении'
    ];

    return $translations[$type] ?? $type;
}

function formatPhoneNumber($phone)
{
    if (empty($phone)) return '';

    // Убираем все нечисловые символы
    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

    // Форматируем номер в формате +7(903)498-16-42
    if (strlen($cleanPhone) === 11 && $cleanPhone[0] === '7') {
        return '+7(' . substr($cleanPhone, 1, 3) . ')' . substr($cleanPhone, 4, 3) . '-' . substr($cleanPhone, 7, 2) . '-' . substr($cleanPhone, 9, 2);
    }

    // Если номер не соответствует ожидаемому формату, возвращаем как есть
    return $phone;
}

function renderReportValue($key, $value)
{
    if ($key === 'abc') {
        $cls = 'abc-' . strtolower((string)$value);
        return '<span class="abc-badge ' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    if ($key === 'delivery_type') {
        return htmlspecialchars(translateDeliveryType($value), ENT_QUOTES, 'UTF-8');
    }

    if ($key === 'phone') {
        return htmlspecialchars(formatPhoneNumber($value), ENT_QUOTES, 'UTF-8');
    }

    if (is_numeric($value) && $key !== 'avg_time_minutes' && $key !== 'avg_processing_time') {
        if (strpos((string)$value, '.') !== false) {
            return number_format((float)$value, 2);
        }

        return number_format((float)$value, 0);
    }

    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Получаем данные в зависимости от типа отчета
$report_data = [];
$report_title = '';

switch ($report_type) {
    case 'sales':
        $report_data = $db->getSalesReport($period);
        $report_title = 'Продажи';
        break;
    case 'profit':
        $report_data = $db->getProfitReport($period);
        $report_title = 'Прибыль';
        break;
    case 'efficiency':
        $report_data = $db->getEfficiencyReport($period);
        $report_title = 'Оперативность';
        break;
    case 'customers':
        $report_data = $db->getTopCustomers($period);
        // Убираем email из данных и удаляем дубликаты
        $unique_data = [];
        foreach ($report_data as $row) {
            if (isset($row['email'])) {
                unset($row['email']);
            }
            if ($period === 'day') {
                $unique_data[$row['order_id']] = $row;
            } else {
                $unique_data[$row['id']] = $row;
            }
        }
        $report_data = array_values($unique_data);
        $report_title = 'Топ клиентов';
        break;
    case 'dishes':
        $report_data = $db->getTopDishes($period);
        $report_title = 'Топ блюд (ABC)';
        // ABC-категоризация по выручке: A=80%, B=15%, C=5%
        if (!empty($report_data)) {
            $totalRevenue = array_sum(array_column($report_data, 'total_revenue'));
            $cumulative = 0;
            foreach ($report_data as &$dish) {
                $dish['revenue_pct'] = $totalRevenue > 0
                    ? round((float)$dish['total_revenue'] / $totalRevenue * 100, 1)
                    : 0;
                $cumulative += $dish['revenue_pct'];
                $dish['abc'] = $cumulative <= 80 ? 'A' : ($cumulative <= 95 ? 'B' : 'C');
            }
            unset($dish);
        }
        break;
    case 'load':
        $report_data = $db->getHourlyLoad($period);
        $report_title = 'Загруженность';
        break;
    case 'employees':
        $report_data = $db->getEmployeeStats($period);
        // Убираем email из данных и удаляем дубликаты
        $unique_data = [];
        foreach ($report_data as $row) {
            if (isset($row['email'])) {
                unset($row['email']);
            }
            if ($period === 'day') {
                $unique_data[$row['order_id']] = $row;
            } else {
                $unique_data[$row['id']] = $row;
            }
        }
        $report_data = array_values($unique_data);
        $report_title = 'Официанты';
        break;
    case 'bottlenecks':
        $report_data = method_exists($db, 'getOrderFlowBottleneckReport')
            ? (array)$db->getOrderFlowBottleneckReport($period)
            : [];
        $report_title = 'Узкие места';
        break;
}

// Функция для определения, какие поля показывать в графиках
$ownerKpi = [
    'orders_today' => 0,
    'paid_today' => 0,
    'cancelled_today' => 0,
    'aov_today' => 0.0,
];
$topItemsToday = [];
$topItemsWeek = [];
$ownerOrderLifecycle = [
    'open' => 0,
    'closed' => 0,
    'attention' => 0,
    'stale' => 0,
];

// Guard against stale OPcache/partial deploys where db.php may lag behind owner.php.
try {
    if (method_exists($db, 'getOwnerKpiSnapshot')) {
        $ownerKpi = (array)$db->getOwnerKpiSnapshot();
    }
    if (method_exists($db, 'getTopItemsSnapshot')) {
        $topItemsToday = (array)$db->getTopItemsSnapshot('day', 5);
        $topItemsWeek = (array)$db->getTopItemsSnapshot('week', 5);
    }
} catch (Throwable $e) {
    error_log('owner dashboard KPI fallback: ' . $e->getMessage());
}

try {
    $ownerOrderLifecycle = cleanmenu_order_lifecycle_summary($db->getAllOrders());
} catch (Throwable $e) {
    error_log('owner dashboard order lifecycle fallback: ' . $e->getMessage());
}

function getChartFields($report_type, $period)
{
    switch ($report_type) {
        case 'sales':
            $fields = $period === 'day'
                ? ['total_revenue', 'item_count']
                : ['order_count', 'total_revenue', 'avg_order_value'];
            break;
        case 'profit':
            $fields = $period === 'day'
                ? ['total_revenue', 'total_expenses', 'total_profit', 'profitability_percent']
                : ['order_count', 'total_revenue', 'total_expenses', 'total_profit', 'profitability_percent'];
            break;
        case 'efficiency':
            $fields = $period === 'day'
                ? ['time_minutes', 'total_revenue', 'total_expenses', 'total_profit']
                : ['avg_time_minutes', 'order_count', 'total_revenue', 'total_expenses', 'total_profit'];
            break;
        case 'customers':
            $fields = $period === 'day'
                ? ['order_total', 'item_count', 'order_expenses', 'order_profit']
                : ['order_count', 'total_spent', 'avg_order_value'];
            break;
        case 'dishes':
            $fields = ['total_quantity', 'total_revenue', 'total_expenses', 'total_profit'];
            break;
        case 'load':
            $fields = ['order_count', 'total_revenue', 'total_profit', 'total_expenses', 'avg_order_value'];
            break;
        case 'employees':
            $fields = $period === 'day'
                ? ['processing_time', 'total_revenue', 'total_expenses', 'total_profit']
                : ['order_count', 'total_revenue', 'avg_processing_time'];
            break;
        case 'bottlenecks':
            $fields = ['avg_minutes', 'orders_count'];
            break;
        default:
            $fields = [];
    }

    return $fields;
}

// Получаем поля для графиков
$chart_fields = getChartFields($report_type, $period);

// Цвета для столбцов графиков
$chart_colors = [
    '#cd1719',
    '#121212',
    '#2c83c2',
    '#4CAF50',
    '#ff9321',
    '#712121',
    '#db3a34',
    '#000000',
    '#555555',
    '#f9f9f9'
];

// Подготовка данных для JavaScript
$chart_data = [];
if (!empty($report_data)) {
    foreach ($chart_fields as $field_index => $field) {
        // Проверяем, существует ли поле в данных
        $field_exists = false;
        $values = [];

        foreach ($report_data as $row) {
            if (isset($row[$field])) {
                $field_exists = true;
                $values[] = $row[$field];
            } else {
                $values[] = 0; // Заполняем нулями если поле отсутствует
            }
        }

        if ($field_exists) {
            // Получаем правильные метки в зависимости от периода и типа отчета
            $labels = [];
            foreach ($report_data as $row) {
                if ($period === 'day') {
                    if ($report_type === 'sales' && isset($row['order_id'])) {
                        $labels[] = $row['order_id'];
                    } else if (isset($row['stage'])) {
                        $labels[] = $row['stage'];
                    } else if ($report_type === 'customers' && isset($row['name'])) {
                        $labels[] = $row['name'];
                    } else if (isset($row['order_id'])) {
                        $labels[] = $row['order_id'];
                    } else if (isset($row['id'])) {
                        $labels[] = $row['id'];
                    } else {
                        $labels[] = '';
                    }
                } else {
                    if (isset($row['date'])) {
                        $labels[] = $row['date'];
                    } else if (isset($row['stage'])) {
                        $labels[] = $row['stage'];
                    } else if (isset($row['id'])) {
                        $labels[] = $row['id'];
                    } else {
                        $labels[] = '';
                    }
                }
            }

            $chart_data[] = [
                'field' => $field,
                'title' => translateFieldName($field),
                'color' => $chart_colors[$field_index % count($chart_colors)],
                'values' => $values,
                'labels' => $labels
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">

<head>


    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
    <link rel="manifest" href="/manifest.php?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <title>Панель владельца | <?= htmlspecialchars($GLOBALS['siteName'] ?? 'labus') ?></title>
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/owner-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
</head>

<body class="owner-page account-page">
    <?php $GLOBALS['header_css_in_head'] = true;
    require_once __DIR__ . '/header.php'; ?>
    <?php require_once __DIR__ . '/account-header.php'; ?>

    <div class="account-container">
        <div class="admin-tabs-container">
            <div class="admin-tabs">
                <button type="button" class="admin-tab-btn <?= $tab === 'stats' ? 'active' : '' ?>" data-tab="stats">Статистика</button>
                <button type="button" class="admin-tab-btn <?= $tab === 'users' ? 'active' : '' ?>" data-tab="users">Пользователи</button>
                <button type="button" class="admin-tab-btn <?= $tab === 'reviews' ? 'active' : '' ?>" data-tab="reviews">Отзывы<?= $reviewRatingCount > 0 ? ' <span class="owner-tab-count">' . $reviewRatingCount . '</span>' : '' ?></button>
            </div>
        </div>
        <section class="admin-form-container">
            <div class="admin-tab-pane <?= $tab === 'stats' ? 'active' : '' ?>" id="stats">
                <div class="owner-workspace-stack">
                    <div class="owner-workspace-header">
                        <div>
                            <p class="owner-workspace-kicker">Аналитика</p>
                            <h2>Аналитика - <?= htmlspecialchars($report_title) ?></h2>
                        </div>
                        <p class="owner-workspace-copy">KPI, отчёт, графики и отчётные срезы собраны в одном рабочем пространстве.</p>
                        <div class="account-section-actions">
                            <a href="employee.php" class="back-to-menu-btn">Открыть заказы</a>
                            <form method="POST" action="/stale-order-cleanup.php" class="account-inline-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="return_to" value="owner.php">
                                <button type="submit" class="checkout-btn" <?= (int)($ownerOrderLifecycle['stale'] ?? 0) <= 0 ? 'disabled' : '' ?>>
                                    Закрыть просроченные<?= (int)($ownerOrderLifecycle['stale'] ?? 0) > 0 ? ' (' . (int)$ownerOrderLifecycle['stale'] . ')' : '' ?>
                                </button>
                            </form>
                        </div>
                    </div>

                <div class="owner-kpi-grid">
                    <article class="owner-kpi-card">
                        <div class="owner-kpi-label">Заказы сегодня</div>
                        <div class="owner-kpi-value"><?= (int)($ownerKpi['orders_today'] ?? 0) ?></div>
                    </article>
                    <article class="owner-kpi-card">
                        <div class="owner-kpi-label">Оплачено сегодня</div>
                        <div class="owner-kpi-value"><?= (int)($ownerKpi['paid_today'] ?? 0) ?></div>
                    </article>
                    <article class="owner-kpi-card">
                        <div class="owner-kpi-label">Отменено сегодня</div>
                        <div class="owner-kpi-value"><?= (int)($ownerKpi['cancelled_today'] ?? 0) ?></div>
                    </article>
                    <article class="owner-kpi-card">
                        <div class="owner-kpi-label">Требуют внимания</div>
                        <div class="owner-kpi-value"><?= (int)($ownerOrderLifecycle['attention'] ?? 0) ?></div>
                    </article>
                    <article class="owner-kpi-card">
                        <div class="owner-kpi-label">Просрочены</div>
                        <div class="owner-kpi-value"><?= (int)($ownerOrderLifecycle['stale'] ?? 0) ?></div>
                    </article>
                    <article class="owner-kpi-card">
                        <div class="owner-kpi-label">Средний чек (сегодня)</div>
                        <div class="owner-kpi-value"><?= number_format((float)($ownerKpi['aov_today'] ?? 0), 2, '.', ' ') ?> ₽</div>
                    </article>
                </div>

                <div class="owner-top-items-grid">
                    <article class="owner-top-items-card">
                        <h3>Топ позиций за день</h3>
                        <?php if (empty($topItemsToday)): ?>
                            <p class="owner-top-items-empty">Нет данных за текущий день.</p>
                        <?php else: ?>
                            <table class="owner-top-items-table">
                                <thead>
                                    <tr>
                                        <th>Позиция</th>
                                        <th>Кол-во</th>
                                        <th>Выручка</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($topItemsToday as $item): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string)($item['item_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= (int)($item['total_qty'] ?? 0) ?></td>
                                            <td><?= number_format((float)($item['total_revenue'] ?? 0), 2, '.', ' ') ?> ₽</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </article>

                    <article class="owner-top-items-card">
                        <h3>Топ позиций за 7 дней</h3>
                        <?php if (empty($topItemsWeek)): ?>
                            <p class="owner-top-items-empty">Нет данных за последние 7 дней.</p>
                        <?php else: ?>
                            <table class="owner-top-items-table">
                                <thead>
                                    <tr>
                                        <th>Позиция</th>
                                        <th>Кол-во</th>
                                        <th>Выручка</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($topItemsWeek as $item): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string)($item['item_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= (int)($item['total_qty'] ?? 0) ?></td>
                                            <td><?= number_format((float)($item['total_revenue'] ?? 0), 2, '.', ' ') ?> ₽</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </article>
                </div>
                <!-- Период и тип отчета -->
                <div class="owner-report-toolbar">
                    <div class="owner-report-tabs-card">
                        <div class="owner-report-toolbar-head">
                            <div>
                                <p class="owner-workspace-kicker">Отчёты</p>
                                <h3>Срезы и метрики</h3>
                            </div>
                            <p class="owner-report-toolbar-copy">Переключайте отчёт и период, не теряя общий контекст по KPI и графикам.</p>
                        </div>
                        <div class="menu-tabs-container owner-report-tabs-container">
                            <div class="menu-tabs">
                                <a href="?report=sales&period=<?= $period ?>" class="tab-btn <?= $report_type === 'sales' ? 'active' : '' ?>">Продажи</a>
                                <a href="?report=profit&period=<?= $period ?>" class="tab-btn <?= $report_type === 'profit' ? 'active' : '' ?>">Прибыль</a>
                                <a href="?report=efficiency&period=<?= $period ?>" class="tab-btn <?= $report_type === 'efficiency' ? 'active' : '' ?>">Оперативность</a>
                                <a href="?report=customers&period=<?= $period ?>" class="tab-btn <?= $report_type === 'customers' ? 'active' : '' ?>">Клиенты</a>
                                <a href="?report=dishes&period=<?= $period ?>" class="tab-btn <?= $report_type === 'dishes' ? 'active' : '' ?>">Топ блюд</a>
                                <a href="?report=load&period=<?= $period ?>" class="tab-btn <?= $report_type === 'load' ? 'active' : '' ?>">Загруженность</a>
                                <a href="?report=employees&period=<?= $period ?>" class="tab-btn <?= $report_type === 'employees' ? 'active' : '' ?>">Официанты</a>
                                <a href="?report=bottlenecks&period=<?= $period ?>" class="tab-btn <?= $report_type === 'bottlenecks' ? 'active' : '' ?>">Узкие места</a>
                            </div>
                        </div>
                    </div>

                    <div class="report-controls owner-report-controls-card">
                        <form method="GET" class="report-filter-form">
                            <input type="hidden" name="report" value="<?= htmlspecialchars($report_type) ?>">

                            <div class="form-group">
                                <label>Период:</label>
                                <select name="period" id="periodSelect">
                                    <option value="day" <?= $period === 'day' ? 'selected' : '' ?>>День</option>
                                    <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>Неделя</option>
                                    <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>Месяц</option>
                                    <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>Год</option>
                                </select>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Отчет -->
                <div class="report-content">
                    <?php if (empty($report_data)): ?>
                        <p>Нет данных за выбранный период</p>
                    <?php else: ?>
                        <?php $headers = array_keys($report_data[0]); ?>

                        <article class="owner-top-items-card owner-report-table-card desktop-table">
                            <div class="owner-report-card-head">
                                <div>
                                    <p class="owner-report-card-kicker">Отчёт</p>
                                    <h3><?= htmlspecialchars($report_title, ENT_QUOTES, 'UTF-8') ?></h3>
                                </div>
                                <span class="owner-report-card-meta"><?= count($report_data) ?> строк</span>
                            </div>
                            <div class="owner-report-table-wrap">
                                <table class="owner-top-items-table owner-report-table">
                                    <thead>
                                        <tr>
                                            <?php foreach ($headers as $header): ?>
                                                <th><?= htmlspecialchars(translateFieldName($header), ENT_QUOTES, 'UTF-8') ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_data as $row): ?>
                                            <tr>
                                                <?php foreach ($row as $key => $value): ?>
                                                    <td><?= renderReportValue($key, $value) ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </article>

                        <div class="owner-report-mobile-grid mobile-table-container">
                            <?php foreach ($report_data as $rowIndex => $row): ?>
                                <article class="owner-top-items-card owner-report-mobile-card mobile-table-item">
                                    <div class="owner-report-card-head owner-report-card-head-mobile">
                                        <div>
                                            <p class="owner-report-card-kicker">Запись <?= $rowIndex + 1 ?></p>
                                            <h3><?= htmlspecialchars($report_title, ENT_QUOTES, 'UTF-8') ?></h3>
                                        </div>
                                    </div>
                                    <div class="mobile-table owner-report-mobile-table">
                                        <?php foreach ($row as $key => $value): ?>
                                            <div class="mobile-table-row owner-report-mobile-row">
                                                <span class="mobile-table-label owner-report-mobile-label"><?= htmlspecialchars(translateFieldName($key), ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="mobile-table-value owner-report-mobile-value"><?= renderReportValue($key, $value) ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <section class="owner-charts-section">
                    <div class="owner-report-toolbar-head owner-report-toolbar-head--charts">
                        <div>
                            <p class="owner-workspace-kicker">Графики</p>
                            <h3>Визуализация текущего отчёта</h3>
                        </div>
                        <p class="owner-report-toolbar-copy">Графики показывают выбранный отчёт в текущем периоде и обновляются вместе с таблицей.</p>
                    </div>
                    <div class="charts-container" id="chartsContainer">
                        <!-- Графики будут созданы через JavaScript -->
                    </div>
                </section>
                </div>
            </div>
            <div class="admin-tab-pane <?= $tab === 'users' ? 'active' : '' ?>" id="users">
                <?php
                $roleLabels = [
                    'customer' => 'Клиент',
                    'employee' => 'Официант',
                    'admin' => 'Администратор',
                    'owner' => 'Владелец'
                ];
                ?>
                <!-- DESKTOP TABLE -->
                <div class="desktop-table">
                    <table>
                        <thead>
                            <tr>
                                <th class="first-col">ID</th>
                                <th>Имя</th>
                                <th>Email</th>
                                <th>Телефон</th>
                                <th>Активен</th>
                                <th class="last-col">Роль</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?= $user['id'] ?></td>
                                    <td><?= htmlspecialchars($user['name']) ?></td>
                                    <td><?= htmlspecialchars($user['email']) ?></td>
                                    <td><?= htmlspecialchars(formatPhoneNumber($user['phone'] ?? '')) ?></td>
                                    <td><?= $user['is_active']
                                            ? '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 256 256" fill="currentColor" class="user-status-active" aria-label="Активен"><path d="M173.66,98.34a8,8,0,0,1,0,11.32l-56,56a8,8,0,0,1-11.32,0l-24-24a8,8,0,0,1,11.32-11.32L112,148.69l50.34-50.35A8,8,0,0,1,173.66,98.34ZM232,128A104,104,0,1,1,128,24,104.11,104.11,0,0,1,232,128Zm-16,0a88,88,0,1,0-88,88A88.1,88.1,0,0,0,216,128Z"/></svg>'
                                            : '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 256 256" fill="currentColor" class="user-status-inactive" aria-label="Неактивен"><path d="M205.66,194.34a8,8,0,0,1-11.32,11.32L128,139.31l-66.34,66.35a8,8,0,0,1-11.32-11.32L116.69,128,50.34,61.66A8,8,0,0,1,61.66,50.34L128,116.69l66.34-66.35a8,8,0,0,1,11.32,11.32L139.31,128Z"/><path d="M232,128A104,104,0,1,1,128,24,104.11,104.11,0,0,1,232,128Zm-16,0a88,88,0,1,0-88,88A88.1,88.1,0,0,0,216,128Z"/></svg>'
                                        ?></td>
                                    <td>
                                        <select class="role-select" data-user-id="<?= $user['id'] ?>">
                                            <?php foreach ($roleLabels as $value => $label): ?>
                                                <option value="<?= $value ?>" <?= $user['role'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="save-role-btn" data-user-id="<?= $user['id'] ?>">Сохранить</button>
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
                        <?php foreach ($users as $user): ?>
                            <div class="mobile-table-item">
                                <div class="mobile-table-row">
                                    <span class="mobile-table-label">ID:</span>
                                    <span class="mobile-table-value"><?= $user['id'] ?></span>
                                </div>
                                <div class="mobile-table-row">
                                    <span class="mobile-table-label">Имя:</span>
                                    <span class="mobile-table-value"><?= htmlspecialchars($user['name']) ?></span>
                                </div>
                                <div class="mobile-table-row">
                                    <span class="mobile-table-label">Email:</span>
                                    <span class="mobile-table-value"><?= htmlspecialchars($user['email']) ?></span>
                                </div>
                                <div class="mobile-table-row">
                                    <span class="mobile-table-label">Телефон:</span>
                                    <span class="mobile-table-value"><?= htmlspecialchars(formatPhoneNumber($user['phone'] ?? '')) ?></span>
                                </div>
                                <div class="mobile-table-row">
                                    <span class="mobile-table-label">Активен:</span>
                                    <span class="mobile-table-value"><?= $user['is_active'] ? 'Да' : 'Нет' ?></span>
                                </div>
                                <div class="mobile-table-row">
                                    <span class="mobile-table-label">Роль:</span>
                                    <span class="mobile-table-value">
                                        <select class="role-select" data-user-id="<?= $user['id'] ?>">
                                            <?php foreach ($roleLabels as $value => $label): ?>
                                                <option value="<?= $value ?>" <?= $user['role'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="save-role-btn" data-user-id="<?= $user['id'] ?>">Сохранить</button>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="admin-tab-pane <?= $tab === 'reviews' ? 'active' : '' ?>" id="reviews">
                <div class="owner-reviews-workspace">
                    <div class="owner-workspace-header">
                        <div>
                            <p class="owner-workspace-kicker">Обратная связь</p>
                            <h2>Отзывы гостей</h2>
                        </div>
                        <p class="owner-workspace-copy">
                            Последние <?= (int)$reviewRatingCount ?> отзывов из максимально 50. Средняя оценка:
                            <strong><?= $reviewRatingCount > 0 ? number_format($reviewRatingAvg, 1, '.', '') : '—' ?></strong>
                            из 5. Отзывы сохраняются в режиме append-only и не редактируются из админки.
                        </p>
                    </div>
                    <?php if (empty($recentReviews)): ?>
                        <p class="owner-top-items-empty">Пока ни одного отзыва. Гости видят форму на странице трекинга заказа после того, как заказ завершён.</p>
                    <?php else: ?>
                        <div class="desktop-table owner-reviews-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th class="first-col">Дата</th>
                                        <th>Заказ</th>
                                        <th>Оценка</th>
                                        <th>Сумма</th>
                                        <th class="last-col">Комментарий</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentReviews as $review): ?>
                                        <?php
                                        $rv = max(0, min(5, (int)($review['rating'] ?? 0)));
                                        $stars = str_repeat('★', $rv) . str_repeat('☆', 5 - $rv);
                                        $createdAtStr = isset($review['created_at'])
                                            ? date('d.m.Y H:i', strtotime((string)$review['created_at']))
                                            : '';
                                        $comment = (string)($review['comment'] ?? '');
                                        $orderTotal = isset($review['order_total']) && $review['order_total'] !== null
                                            ? number_format((float)$review['order_total'], 2, '.', ' ') . ' ₽'
                                            : '—';
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($createdAtStr) ?></td>
                                            <td>
                                                <a href="/order-track.php?id=<?= (int)$review['order_id'] ?>" target="_blank" rel="noopener">
                                                    #<?= (int)$review['order_id'] ?>
                                                </a>
                                            </td>
                                            <td class="owner-review-stars" aria-label="<?= $rv ?>/5">
                                                <span class="owner-review-stars-glyph"><?= $stars ?></span>
                                            </td>
                                            <td><?= $orderTotal ?></td>
                                            <td>
                                                <?php if ($comment !== ''): ?>
                                                    <?= nl2br(htmlspecialchars($comment)) ?>
                                                <?php else: ?>
                                                    <span class="owner-review-no-comment">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mobile-table-container">
                            <div class="mobile-table">
                                <?php foreach ($recentReviews as $review): ?>
                                    <?php
                                    $rv = max(0, min(5, (int)($review['rating'] ?? 0)));
                                    $stars = str_repeat('★', $rv) . str_repeat('☆', 5 - $rv);
                                    $createdAtStr = isset($review['created_at'])
                                        ? date('d.m.Y H:i', strtotime((string)$review['created_at']))
                                        : '';
                                    $comment = (string)($review['comment'] ?? '');
                                    $orderTotal = isset($review['order_total']) && $review['order_total'] !== null
                                        ? number_format((float)$review['order_total'], 2, '.', ' ') . ' ₽'
                                        : '—';
                                    ?>
                                    <div class="mobile-table-item">
                                        <div class="mobile-table-row">
                                            <span class="mobile-table-label">Дата:</span>
                                            <span class="mobile-table-value"><?= htmlspecialchars($createdAtStr) ?></span>
                                        </div>
                                        <div class="mobile-table-row">
                                            <span class="mobile-table-label">Заказ:</span>
                                            <span class="mobile-table-value">
                                                <a href="/order-track.php?id=<?= (int)$review['order_id'] ?>" target="_blank" rel="noopener">
                                                    #<?= (int)$review['order_id'] ?>
                                                </a>
                                            </span>
                                        </div>
                                        <div class="mobile-table-row">
                                            <span class="mobile-table-label">Оценка:</span>
                                            <span class="mobile-table-value owner-review-stars-glyph"><?= $stars ?></span>
                                        </div>
                                        <div class="mobile-table-row">
                                            <span class="mobile-table-label">Сумма:</span>
                                            <span class="mobile-table-value"><?= $orderTotal ?></span>
                                        </div>
                                        <?php if ($comment !== ''): ?>
                                            <div class="mobile-table-row">
                                                <span class="mobile-table-label">Комментарий:</span>
                                                <span class="mobile-table-value"><?= nl2br(htmlspecialchars($comment)) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>
    <textarea id="owner-page-data" hidden><?= htmlspecialchars(json_encode([
                                                'chartData' => $chart_data,
                                                'currentPeriod' => $period,
                                                'currentReport' => $report_type,
                                                'rawReportData' => $report_data
                                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></textarea>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/owner.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/admin-tabs-repair.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/push-notifications.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>

</html>
