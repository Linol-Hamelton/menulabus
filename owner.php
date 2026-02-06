<?php
// owner.php
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/require_auth.php';
$required_role = 'owner';

$db = Database::getInstance();

// Получаем пользователей для вкладки "Пользователи"
$users = $db->getAllUsers();

// Определяем активную вкладку
$tab = $_GET['tab'] ?? 'stats';

// Получаем данные для отчетов
$period = $_GET['period'] ?? 'day';
$report_type = $_GET['report'] ?? 'sales';

// Валидация периода
$valid_periods = ['day', 'week', 'month', 'year'];
$period = in_array($period, $valid_periods) ? $period : 'day';

// Валидация типа отчета
$valid_reports = ['sales', 'profit', 'efficiency', 'customers', 'dishes', 'load', 'employees'];
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
        'processing_time' => 'Время (мин)'
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
        $report_title = 'Топ блюд';
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
}

// Функция для определения, какие поля показывать в графиках
function getChartFields($report_type, $period)
{
    switch ($report_type) {
        case 'sales':
            $fields = $period === 'day'
                ? ['order_total', 'item_count']
                : ['order_count', 'total_revenue', 'avg_order_value'];
            break;
        case 'profit':
            $fields = $period === 'day'
                ? ['total_revenue', 'order_expenses', 'order_profit', 'profitability_percent']
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
    <title>Панель владельца | labus</title>
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
    <link rel="stylesheet" href="/css/owner-styles.min.css?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>">
</head>

<body class="owner-page">
    <?php require_once __DIR__ . '/header.php'; ?>
    <?php require_once __DIR__ . '/account-header.php'; ?>

    <div class="account-container">
        <section class="admin-form-container">
            <div class="admin-tabs-container">
                <div class="admin-tabs">
                    <button class="admin-tab-btn <?= $tab === 'stats' ? 'active' : '' ?>" data-tab="stats">Статистика</button>
                    <button class="admin-tab-btn <?= $tab === 'users' ? 'active' : '' ?>" data-tab="users">Пользователи</button>
                </div>
            </div>

            <div class="admin-tab-pane <?= $tab === 'stats' ? 'active' : '' ?>" id="stats">
            <h2>Аналитика - <?= htmlspecialchars($report_title) ?></h2>

            <!-- Период и тип отчета -->
            <div class="report-controls">
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

            <!-- Отчет -->
            <div class="report-content">
                <?php if (empty($report_data)): ?>
                    <p>Нет данных за выбранный период</p>
                <?php else: ?>

                    <!-- Desktop Table -->
                    <div class="desktop-table">
                        <table>
                            <thead>
                                <tr>
                                    <?php
                                    $headers = array_keys($report_data[0]);
                                    foreach ($headers as $header):
                                    ?>
                                        <th><?= htmlspecialchars(translateFieldName($header)) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report_data as $index => $row): ?>
                                    <tr>
                                        <?php foreach ($row as $key => $value): ?>
                                            <td>
                                                <?php
                                                if ($key === 'delivery_type') {
                                                    echo htmlspecialchars(translateDeliveryType($value));
                                                } else if ($key === 'phone') {
                                                    echo htmlspecialchars(formatPhoneNumber($value));
                                                } else if (is_numeric($value) && $key !== 'avg_time_minutes' && $key !== 'avg_processing_time') {
                                                    // Форматируем числа, кроме времени (уже округлено в БД)
                                                    if (strpos($value, '.') !== false) {
                                                        echo number_format($value, 2);
                                                    } else {
                                                        echo number_format($value, 0);
                                                    }
                                                } else {
                                                    echo htmlspecialchars($value);
                                                }
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile Table -->
                    <div class="mobile-table-container">
                        <div class="mobile-table">
                            <?php foreach ($report_data as $row): ?>
                                <div class="mobile-table-item">
                                    <?php foreach ($row as $key => $value): ?>
                                        <div class="mobile-table-row">
                                            <span class="mobile-table-label"><?= htmlspecialchars(translateFieldName($key)) ?>:</span>
                                            <span class="mobile-table-value">
                                                <?php
                                                if ($key === 'delivery_type') {
                                                    echo htmlspecialchars(translateDeliveryType($value));
                                                } else if ($key === 'phone') {
                                                    echo htmlspecialchars(formatPhoneNumber($value));
                                                } else if (is_numeric($value) && $key !== 'avg_time_minutes' && $key !== 'avg_processing_time') {
                                                    if (strpos($value, '.') !== false) {
                                                        echo number_format($value, 2);
                                                    } else {
                                                        echo number_format($value, 0);
                                                    }
                                                } else {
                                                    echo htmlspecialchars($value);
                                                }
                                                ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
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
                                    <td><?= $user['is_active'] ? '✅' : '❌' ?></td>
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
        </section>
    </div>
    <!-- Графики -->
    <div class="charts-container" id="chartsContainer">
        <!-- Графики будут созданы через JavaScript -->
    </div>

    <!-- Табы для переключения отчетов -->
    <div class="menu-tabs-container">
        <div class="menu-tabs">
            <a href="?report=sales&period=<?= $period ?>" class="tab-btn <?= $report_type === 'sales' ? 'active' : '' ?>">Продажи</a>
            <a href="?report=profit&period=<?= $period ?>" class="tab-btn <?= $report_type === 'profit' ? 'active' : '' ?>">Прибыль</a>
            <a href="?report=efficiency&period=<?= $period ?>" class="tab-btn <?= $report_type === 'efficiency' ? 'active' : '' ?>">Оперативность</a>
            <a href="?report=customers&period=<?= $period ?>" class="tab-btn <?= $report_type === 'customers' ? 'active' : '' ?>">Клиенты</a>
            <a href="?report=dishes&period=<?= $period ?>" class="tab-btn <?= $report_type === 'dishes' ? 'active' : '' ?>">Топ блюд</a>
            <a href="?report=load&period=<?= $period ?>" class="tab-btn <?= $report_type === 'load' ? 'active' : '' ?>">Загруженность</a>
            <a href="?report=employees&period=<?= $period ?>" class="tab-btn <?= $report_type === 'employees' ? 'active' : '' ?>">Официанты</a>
        </div>
    </div>

    <!-- Передача данных в JavaScript -->
    <script nonce="<?= $scriptNonce ?>">
        window.chartData = <?= json_encode($chart_data) ?>;
        window.currentPeriod = '<?= $period ?>';
        window.currentReport = '<?= $report_type ?>';
        window.rawReportData = <?= json_encode($report_data) ?>;

        // Переключение вкладок администратора
        document.addEventListener('DOMContentLoaded', function() {
            const tabBtns = document.querySelectorAll('.admin-tab-btn');
            const tabPanes = document.querySelectorAll('.admin-tab-pane');

            function activateTab(tabId) {
                tabBtns.forEach(btn => btn.classList.toggle('active', btn.dataset.tab === tabId));
                tabPanes.forEach(pane => pane.classList.toggle('active', pane.id === tabId));
                localStorage.setItem('adminActiveTab', tabId);
            }

            tabBtns.forEach(btn => {
                btn.addEventListener('click', () => activateTab(btn.dataset.tab));
            });

            // Load saved tab
            const savedTab = localStorage.getItem('adminActiveTab') || 'stats';
            if (savedTab === 'update') {
                activateTab('stats'); // Redirect old 'update' to 'stats'
            } else {
                activateTab(savedTab);
            }
        });

        // Обработка сохранения роли пользователя
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.save-role-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const userId = this.dataset.userId;
                    const select = this.parentElement.querySelector('.role-select');
                    const newRole = select.value;

                    // Показываем индикатор загрузки
                    const originalText = this.textContent;
                    this.textContent = 'Сохранение...';
                    this.disabled = true;

                    fetch('/update_user_role.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ user_id: userId, role: newRole })
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('HTTP error ' + response.status);
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            // Успех
                            this.textContent = 'Сохранено!';
                            setTimeout(() => {
                                this.textContent = originalText;
                                this.disabled = false;
                            }, 1500);
                        } else {
                            alert('Ошибка: ' + (data.error || 'Неизвестная ошибка'));
                            this.textContent = originalText;
                            this.disabled = false;
                        }
                    })
                    .catch(error => {
                        console.error('Ошибка сети', error);
                        alert('Сетевая ошибка: ' + error.message);
                        this.textContent = originalText;
                        this.disabled = false;
                    });
                });
            });
        });
    </script>
    <script src="/js/app.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/owner.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
    <script src="/js/push-notifications.min.js?v=<?= htmlspecialchars($_SESSION['app_version'] ?? '1.0.0') ?>" defer nonce="<?= $scriptNonce ?>"></script>
</body>

</html>