<?php
$required_role = 'employee';
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/require_auth.php';

$appVersion = htmlspecialchars($_SESSION['app_version'] ?? '1.0.0');
$role = (string)($user['role'] ?? ($_SESSION['user_role'] ?? 'employee'));
$canOpenAdmin = in_array($role, ['admin', 'owner'], true);
$canOpenOwner = $role === 'owner';
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="manifest" href="/manifest.php?v=<?= $appVersion ?>">
    <link rel="stylesheet" href="/css/fa-purged.min.css?v=<?= $appVersion ?>">
    <link rel="stylesheet" href="/css/fa-styles.min.css?v=<?= $appVersion ?>">
    <link rel="stylesheet" href="/css/account-styles.min.css?v=<?= $appVersion ?>">
    <link rel="stylesheet" href="/css/admin-menu-polish.css?v=<?= $appVersion ?>">
    <link rel="stylesheet" href="/auto-fonts.php?v=<?= $appVersion ?>">
    <title>Центр помощи | <?= htmlspecialchars($GLOBALS['siteName'] ?? 'labus') ?></title>
</head>

<body class="employee-page account-page help-page">
    <?php $GLOBALS['header_css_in_head'] = true; require_once __DIR__ . '/header.php'; ?>
    <?php require_once __DIR__ . '/account-header.php'; ?>

    <div class="account-container">
        <section class="account-section">
            <h2>Центр помощи</h2>
            <p>Одна страница для персонала, администратора и владельца: что открывать, в какой последовательности работать и как быстро показать возможности цифрового меню.</p>

            <div class="menu-tabs">
                <a href="#staff-helper" class="tab-btn active">Персонал</a>
                <a href="#admin-helper" class="tab-btn">Администратор</a>
                <a href="#owner-helper" class="tab-btn">Владелец</a>
                <a href="#menu-presentation" class="tab-btn">Возможности</a>
            </div>

            <div class="form-actions">
                <a href="/employee.php" class="checkout-btn">Открыть заказы</a>
                <?php if ($canOpenAdmin): ?>
                    <a href="/admin-menu.php" class="checkout-btn">Открыть админку</a>
                <?php endif; ?>
                <?php if ($canOpenOwner): ?>
                    <a href="/owner.php" class="checkout-btn">Открыть аналитику</a>
                <?php endif; ?>
                <a href="/menu.php" class="back-to-menu-btn">Открыть меню</a>
            </div>
        </section>

        <section class="account-section" id="staff-helper">
            <h2>Хелпер для персонала</h2>
            <div class="admin-form-container">
                <h3>Ежедневный маршрут</h3>
                <ol>
                    <li>Откройте <a href="/employee.php">панель заказов</a> и начните со вкладки «Приём».</li>
                    <li>Проверьте быстрый поиск и фильтр по типу заказа, чтобы выделить доставку, самовывоз, стол или бар.</li>
                    <li>Откройте карточку заказа только когда нужен состав, детали доставки или история обновления.</li>
                    <li>Переводите заказ по этапам только после фактического действия кухни или зала.</li>
                    <li>Если гость просит оплату по ссылке, используйте кнопку генерации ссылки из карточки заказа.</li>
                    <li>Для оплаты наличными подтверждайте оплату только после фактического расчёта.</li>
                </ol>
            </div>

            <div class="admin-form-container">
                <h3>Что важно не забывать</h3>
                <ul>
                    <li>Верхняя строка карточки заказа должна оставаться короткой: номер, статус, тип получения, клиент, время, количество позиций, сумма.</li>
                    <li>Длинные детали, адрес или номер стола смотрите только в раскрытии карточки.</li>
                    <li>QR-коды для столов печатаются на <a href="/qr-print.php">странице QR-печати</a>.</li>
                    <li>Если заказ не должен идти дальше по цепочке, используйте «Отказ», а не искусственный перевод по статусам.</li>
                </ul>
            </div>

            <div class="admin-form-container">
                <h3>Быстрый сценарий для новой смены</h3>
                <ul>
                    <li>Открыть «Заказы».</li>
                    <li>Проверить «Приём» и «Готовим».</li>
                    <li>Убедиться, что QR-коды для зала распечатаны и доступны.</li>
                    <li>Проверить, что гость может оплатить по ссылке или наличными.</li>
                </ul>
            </div>
        </section>

        <section class="account-section" id="admin-helper">
            <h2>Хелпер для администратора</h2>
            <div class="admin-form-container">
                <h3>Каталог и наполнение</h3>
                <ul>
                    <li>Во вкладке «Блюда» можно загружать позиции CSV-файлом, редактировать их вручную, архивировать и восстанавливать.</li>
                    <li>Если нужен быстрый массовый апдейт, используйте CSV. Если нужна точечная правка, открывайте карточку позиции вручную.</li>
                    <li>Модификаторы держите только у тех позиций, где они реально нужны гостю при заказе.</li>
                </ul>
            </div>

            <div class="admin-form-container">
                <h3>Бренд и витрина</h3>
                <ul>
                    <li>Во вкладке «Дизайн» сосредоточены бренд, файлы, логотип, favicon, шрифты и цвета.</li>
                    <li>Поле «Ссылка на карту» отвечает только за кнопку «Приехать», а поле «Адрес» отвечает за видимый текст адреса.</li>
                    <li>Если карта не нужна, оставляйте «Ссылка на карту» пустой: публичная кнопка не появится.</li>
                    <li>Если включён white-label режим, проверяйте tenant homepage и public menu на отсутствие provider-смыслов.</li>
                </ul>
            </div>

            <div class="admin-form-container">
                <h3>Платежи и система</h3>
                <ul>
                    <li>Во вкладке «Оплата» настраиваются ЮKassa и Т-Банк.</li>
                    <li>Во вкладке «Система» держите Telegram-уведомления и служебные инструменты.</li>
                    <li>После важных правок в платёжных или системных настройках проверяйте public flow до корзины и оплаты.</li>
                </ul>
            </div>
        </section>

        <section class="account-section" id="owner-helper">
            <h2>Хелпер для владельца</h2>
            <div class="admin-form-container">
                <h3>На что смотреть ежедневно</h3>
                <ul>
                    <li>Откройте <a href="/owner.php">аналитику</a> и начните с KPI-блока: заказы, оплаченные, отменённые, средний чек.</li>
                    <li>Затем проверьте «Топ позиций за день» и «Топ позиций за неделю».</li>
                    <li>Если нужна оперативная реакция по процессу, переходите в отчёт «Узкие места».</li>
                </ul>
            </div>

            <div class="admin-form-container">
                <h3>Как читать отчёты</h3>
                <ul>
                    <li>«Продажи» показывают объём и структуру выручки.</li>
                    <li>«Прибыль» и «Оперативность» помогают оценить реальную эффективность кухни и сервиса.</li>
                    <li>«Клиенты» и «Топ блюд» полезны для маркетинговых решений и пересборки каталога.</li>
                    <li>«Официанты» и «Узкие места» нужны для контроля команды и сценариев обслуживания.</li>
                </ul>
            </div>

            <div class="admin-form-container">
                <h3>Роль владельца в white-label запуске</h3>
                <ul>
                    <li>Проверить бренд, контакты и карту на tenant homepage.</li>
                    <li>Проверить public menu, cart, owner/admin/employee страницы на новом домене.</li>
                    <li>Принять релиз только после короткого provider/tenant smoke и ручной проверки ключевых сценариев.</li>
                </ul>
            </div>
        </section>

        <section class="account-section" id="menu-presentation">
            <h2>Презентация возможностей меню</h2>
            <div class="admin-form-container">
                <h3>Что получает гость</h3>
                <ul>
                    <li>Публичное меню с категориями, описаниями, составом и БЖУ.</li>
                    <li>Корзину и оформление заказа под зал, бар, самовывоз или доставку.</li>
                    <li>Повторный заказ, историю заказов и вход через обычную авторизацию или OAuth.</li>
                    <li>QR-сценарий для заказов по столам.</li>
                </ul>
            </div>

            <div class="admin-form-container">
                <h3>Что получает команда</h3>
                <ul>
                    <li>Панель персонала с очередью заказов, поиском, фильтрами и быстрым переводом по этапам.</li>
                    <li>Генерацию ссылок на оплату и подтверждение наличных платежей.</li>
                    <li>Печать QR-кодов для зала.</li>
                </ul>
            </div>

            <div class="admin-form-container">
                <h3>Что получает управление</h3>
                <ul>
                    <li>Админ-панель для каталога, бренда, файлов, цветов, шрифтов, оплаты и системных настроек.</li>
                    <li>Панель владельца с KPI, отчётами, графиками, пользователями и узкими местами.</li>
                    <li>White-label режим с отдельными tenant-доменами и restaurant-facing homepage.</li>
                </ul>
            </div>

            <div class="admin-form-container">
                <h3>Как показать систему за 5 минут</h3>
                <ol>
                    <li>Открыть tenant homepage и public menu.</li>
                    <li>Показать корзину, сценарии заказа и QR-заказ за стол.</li>
                    <li>Показать панель персонала и движение заказа по статусам.</li>
                    <li>Показать админку: бренд, каталог, архивирование, настройки оплаты.</li>
                    <li>Завершить owner-аналитикой и white-label разделением provider/tenant.</li>
                </ol>
            </div>
        </section>
    </div>
</body>

</html>
