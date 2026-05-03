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
                        <div class="account-section-head">
                <div class="account-section-heading">
                    <p class="account-section-kicker">Help</p>
                    <h2>Центр помощи</h2>
                    <p class="account-section-copy">Одна страница для персонала, администратора и владельца: что открывать, в какой последовательности работать и как быстро показать возможности цифрового меню.</p>
                </div>
                <div class="account-section-actions">
                    <a href="/employee.php" class="checkout-btn">Открыть заказы</a>
                    <?php if ($canOpenAdmin): ?>
                        <a href="/admin/menu.php" class="checkout-btn">Открыть админку</a>
                    <?php endif; ?>
                    <?php if ($canOpenOwner): ?>
                        <a href="/owner.php" class="checkout-btn">Открыть аналитику</a>
                    <?php endif; ?>
                    <a href="/menu.php" class="back-to-menu-btn">Открыть меню</a>
                </div>
            </div>

            <div class="menu-tabs">
                <a href="#staff-helper" class="tab-btn active">Персонал</a>
                <a href="#admin-helper" class="tab-btn">Администратор</a>
                <a href="#owner-helper" class="tab-btn">Владелец</a>
                <a href="#operations-helper" class="tab-btn">Операции</a>
                <a href="#billing-helper" class="tab-btn">Подписка</a>
                <a href="#menu-presentation" class="tab-btn">Возможности</a>
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

            <div class="admin-form-container">
                <h3>Кухонный дисплей (KDS)</h3>
                <ul>
                    <li>Откройте <a href="/kds/index.php">KDS</a> на планшете на кухне — позиции автоматически приходят с заказов и группируются по станциям.</li>
                    <li>Клик «Готово» меняет статус позиции; когда вся позиция готова, заказ автоматически переходит в «Готов» и официант получает Telegram.</li>
                    <li>Если станций нет — admin или owner создаёт их в <a href="/admin/kitchen.php">/admin/kitchen.php</a> и привязывает блюда к станциям.</li>
                </ul>
            </div>

            <div class="admin-form-container">
                <h3>Замена смены (если не можете выйти)</h3>
                <ul>
                    <li>Откройте <a href="/admin/staff.php">«Персонал»</a> — в блоке «Мои предстоящие смены» нажмите «Запросить замену» рядом с нужной сменой.</li>
                    <li>Опишите причину коротким комментарием — менеджер увидит её в своём списке.</li>
                    <li>Когда другой сотрудник возьмёт смену, статус сменится на «волонтёр найден». После одобрения менеджером смена переназначится автоматически.</li>
                    <li>Можно отменить запрос пока он ещё открыт.</li>
                </ul>
            </div>
        </section>

        <section class="account-section" id="admin-helper">
            <h2>Хелпер для администратора</h2>
            <div class="admin-form-container">
                <h3>Каталог и наполнение</h3>
                <ul>
                    <li>В <a href="/admin/menu.php">/admin/menu.php</a> можно загружать позиции CSV-файлом, редактировать их вручную, архивировать и восстанавливать.</li>
                    <li>Если нужен быстрый массовый апдейт, используйте CSV. Если нужна точечная правка, открывайте карточку позиции вручную.</li>
                    <li>Модификаторы держите только у тех позиций, где они реально нужны гостю при заказе.</li>
                    <li>Drag-n-drop меняет порядок позиций в категории, hotkey'и (см. кнопку «?» в правом верхнем углу) ускоряют bulk-операции.</li>
                </ul>
            </div>

            <div class="admin-form-container">
                <h3>Бренд и витрина</h3>
                <ul>
                    <li>В <a href="/owner.php?tab=brand">/owner.php?tab=brand</a> сосредоточены бренд, логотип, favicon, шрифты и цвета.</li>
                    <li>Поле «Ссылка на карту» отвечает только за кнопку «Приехать», а поле «Адрес» отвечает за видимый текст адреса.</li>
                    <li>Если карта не нужна, оставляйте «Ссылка на карту» пустой: публичная кнопка не появится.</li>
                    <li>Если включён white-label режим, проверяйте tenant homepage и public menu на отсутствие provider-смыслов.</li>
                </ul>
            </div>

            <div class="admin-form-container">
                <h3>Платежи и фискализация</h3>
                <ul>
                    <li>В <a href="/owner.php?tab=payments">/owner.php?tab=payments</a> настраиваются ЮKassa и Т-Банк (СБП).</li>
                    <li>В <a href="/owner.php?tab=fiscal">/owner.php?tab=fiscal</a> — 54-ФЗ через АТОЛ Онлайн: login, password, group_code, ИНН, СНО, sandbox toggle, кнопка «Тест соединения». После настройки чек выписывается автоматически на оплату.</li>
                    <li>После важных правок проверяйте public flow до корзины и оплаты.</li>
                </ul>
            </div>

            <div class="admin-form-container">
                <h3>Кухня, склад, лояльность, маркетинг</h3>
                <ul>
                    <li><a href="/admin/kitchen.php">Кухня</a> — станции и маршрутизация позиций для KDS.</li>
                    <li><a href="/admin/inventory.php">Склад</a> — ингредиенты, поставщики, рецепты. Списания идут автоматически на оплату.</li>
                    <li><a href="/admin/loyalty.php">Лояльность</a> — тиры (Bronze/Silver/Gold), промокоды, история.</li>
                    <li><a href="/admin/marketing.php">Маркетинг</a> — кампании email/SMS/push с сегментацией.</li>
                    <li><a href="/admin/staff.php">Персонал</a> — смены, time clock, чаевые, замены смен.</li>
                    <li><a href="/admin/locations.php">Локации</a> — мульти-локационная конфигурация (Pro+).</li>
                    <li><a href="/admin/webhooks.php">Вебхуки</a> — outgoing webhook subscriptions для интеграций.</li>
                    <li><a href="/admin/waitlist.php">Очередь</a> — wait-list для бронирования при загруженности.</li>
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

        <section class="account-section" id="operations-helper">
            <h2>Операционный хелпер</h2>

            <div class="admin-form-container">
                <h3>Бронирования</h3>
                <ul>
                    <li>Гость бронит на <a href="/reservation.php">/reservation.php</a> — availability picker сразу показывает занятые слоты для выбранного стола и даты.</li>
                    <li>Менеджер видит борд броней в <a href="/employee.php">«Брони»</a> employee-панели; одна кнопка переводит между статусами pending → confirmed → seated / no-show / cancelled.</li>
                    <li>Telegram дублирует приходящие брони с inline-кнопками «Подтвердить» / «Отклонить».</li>
                    <li>За 2 часа до начала брони автоматически приходит Telegram-напоминание (cron */5).</li>
                </ul>
            </div>

            <div class="admin-form-container">
                <h3>Группа за общим столом (split-bill)</h3>
                <ul>
                    <li>Хост открывает <a href="/group.php?action=new">/group.php?action=new</a> — система создаёт shared-tab с QR-кодом стола.</li>
                    <li>Гости сканируют код → попадают на свою сессию → добавляют позиции под своим именем (seat label).</li>
                    <li>Хост жмёт «Отправить на кухню» — позиции замораживаются в реальные orders + появляется блок оплаты с тремя режимами: «Один за всех» / «Каждый за свои» / «Поровну».</li>
                    <li>Каждый платёж — отдельный YK invoice. Когда сумма всех paid intents покрывает total группы, заказы автоматически переходят в paid и идут на кухню.</li>
                </ul>
            </div>

            <div class="admin-form-container">
                <h3>Склад и low-stock</h3>
                <ul>
                    <li>В <a href="/admin/inventory.php">/admin/inventory.php</a> заведите ингредиенты и привяжите рецепты к блюдам через «Рецепт» в карточке позиции в admin-menu.</li>
                    <li>На каждую оплату ингредиенты списываются автоматически (`stock_movements`).</li>
                    <li>Когда `stock_qty` падает ниже `reorder_threshold` — приходит Telegram-алерт + webhook `inventory.stock_low`.</li>
                </ul>
            </div>

            <div class="admin-form-container">
                <h3>Маркетинг (email / SMS / push)</h3>
                <ul>
                    <li><a href="/admin/marketing.php">/admin/marketing.php</a> — кампании по сегментам (новые гости / активные / спящие).</li>
                    <li>Создаёте кампанию → выбираете канал (email/push/Telegram) → шаблон → cron-worker (1 минута) рассылает в фоне.</li>
                    <li>Failed-доставки видны в delivery log; ретраи — экспоненциальный backoff.</li>
                </ul>
            </div>
        </section>

        <section class="account-section" id="billing-helper">
            <h2>Подписка на платформу</h2>

            <div class="admin-form-container">
                <h3>Текущий тариф и оплата</h3>
                <ul>
                    <li>Откройте <a href="/owner.php?tab=billing">/owner.php?tab=billing</a> — там видно: текущий план (Trial / Starter / Pro / Enterprise), статус (trial/active/past_due/suspended/cancelled), дата следующего списания, сохранённая карта, история инвойсов.</li>
                    <li>Trial длится 14 дней с момента регистрации. Полный доступ ко всем функциям.</li>
                    <li>Чтобы продолжить после trial — кнопка «Добавить карту» открывает безопасную форму YooKassa. После сохранения карта используется для автоматических списаний.</li>
                    <li>«Сменить тариф» — переключение между планами. Upgrade применяется сразу (новая цена с следующего цикла); downgrade — на конец оплаченного периода.</li>
                </ul>
            </div>

            <div class="admin-form-container">
                <h3>Что делать при ошибке списания</h3>
                <ul>
                    <li>День 1 fail — система автоматически попробует снова через 24 часа (вы получите email).</li>
                    <li>День 4 — повторная попытка через 3 дня.</li>
                    <li>День 7 — последняя автоматическая попытка.</li>
                    <li>День 8+ — статус становится <strong>past_due</strong>: витрина продолжает работать в read-only, в админке висит баннер «Платёж не прошёл — обновите карту».</li>
                    <li>День 30 — статус <strong>suspended</strong>: витрина возвращает 503 для гостей. Auth и /owner.php?tab=billing остаются открыты, чтобы вы могли возобновить.</li>
                    <li>Решение: на /owner.php?tab=billing нажмите «Заменить карту» → введите новую → следующее же списание попробует с неё.</li>
                </ul>
            </div>

            <div class="admin-form-container">
                <h3>Отмена подписки</h3>
                <ul>
                    <li>Кнопка «Отменить подписку» внизу таба. Подтверждение → статус становится <strong>cancelled</strong>, но подписка работает до конца оплаченного периода.</li>
                    <li>В период «cancelled» появляется кнопка «Восстановить подписку» — отмена откатывается без штрафа.</li>
                    <li>После окончания оплаченного периода status переходит в <em>cancelled</em> и доступ закрывается. Данные сохраняются 90 дней, потом удаляются.</li>
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
                    <li>Бронь столов с availability picker (видны занятые слоты сразу).</li>
                    <li>Программу лояльности: баллы за заказы, тиры, промокоды.</li>
                    <li>Групповой заказ за общим столом со split-bill (один платит за всех / каждый за свои / поровну).</li>
                    <li>Повторный заказ, историю заказов, вход через email или OAuth (Google / VK / Yandex).</li>
                    <li>QR-сценарий для заказов по столам.</li>
                    <li>Отзывы 1-5 звёзд после завершённого заказа.</li>
                    <li>54-ФЗ фискальный чек по email (если включён).</li>
                </ul>
            </div>

            <div class="admin-form-container">
                <h3>Что получает команда</h3>
                <ul>
                    <li>Панель заказов с очередью, фильтрами, быстрым переводом по этапам.</li>
                    <li>KDS на кухне: real-time борд позиций по станциям, click «Готово» → автоматический Telegram официанту.</li>
                    <li>Борд бронирований с Telegram-уведомлениями.</li>
                    <li>Генерацию ссылок на оплату, подтверждение наличных, печать QR-кодов.</li>
                    <li>Замены смен через UI — без обзвона коллег.</li>
                    <li>Прозрачные чаевые: пул tips распределяется по отработанным минутам.</li>
                </ul>
            </div>

            <div class="admin-form-container">
                <h3>Что получает управление</h3>
                <ul>
                    <li>Админ-панель для каталога, бренда, файлов, цветов, шрифтов, оплаты, фискализации, кухни, склада, лояльности, маркетинга, персонала, локаций.</li>
                    <li>Панель владельца с KPI, отчётами, аналитикой v2 (heatmap / cohorts / margins / EWMA-прогноз).</li>
                    <li>White-label режим с отдельными tenant-доменами.</li>
                    <li>Outgoing webhooks для интеграции со сторонними системами (CRM, аналитика, BI).</li>
                    <li>Mobile API v1 (Capacitor wrapper) для собственных мобильных приложений.</li>
                    <li>i18n: ru/en/kk локали для customer-flow.</li>
                    <li>Self-service подписка с тремя тирами + Enterprise.</li>
                </ul>
            </div>

            <div class="admin-form-container">
                <h3>Как показать систему за 5 минут</h3>
                <ol>
                    <li>Открыть tenant homepage и public menu — посмотреть брендирование.</li>
                    <li>Показать корзину + чек-аут, QR-заказ за стол, групповой стол со split-bill.</li>
                    <li>Показать панель персонала и движение заказа → KDS на кухонном планшете.</li>
                    <li>Показать админку: бренд, каталог, склад/рецепты с автосписанием, лояльность.</li>
                    <li>Завершить owner-аналитикой v2 (heatmap + cohorts) и /owner.php?tab=billing.</li>
                </ol>
            </div>
        </section>
    </div>
</body>

</html>
