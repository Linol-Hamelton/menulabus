# labus - Документация проекта

**Версия:** 1.3.7 (2026-02-08)
**Домен:** https://menu.labus.pro
**Стек:** PHP 7.4+, MySQL 5.7+, Redis (опционально), PWA
**Ветка:** `actual`

---

## Оглавление

1. [Архитектура](#архитектура)
2. [Переменные окружения](#переменные-окружения)
3. [Структура файлов](#структура-файлов)
4. [Инфраструктурный слой](#инфраструктурный-слой)
5. [Аутентификация и авторизация](#аутентификация-и-авторизация)
6. [Страницы (Web)](#страницы-web)
7. [REST API v1 (Mobile)](#rest-api-v1-mobile)
8. [Заказы](#заказы)
9. [Дизайн-система (шрифты и цвета)](#дизайн-система)
10. [Файловый менеджер](#файловый-менеджер)
11. [Push-уведомления и SSE](#push-уведомления-и-sse)
12. [PWA и Service Worker](#pwa-и-service-worker)
13. [JavaScript](#javascript)
14. [CSS](#css)
15. [База данных](#база-данных)
16. [Безопасность](#безопасность)
17. [Кэширование и производительность](#кэширование-и-производительность)
18. [Deploy-конфигурации](#deploy-конфигурации)
19. [CLI-скрипты и утилиты](#cli-скрипты-и-утилиты)
20. [Внешние зависимости](#внешние-зависимости)

---

## Архитектура

```
Браузер/PWA ──► Nginx ──► PHP-FPM (3 пула: web / api / sse)
                                │
                          session_init.php  (все запросы)
                                │
                 ┌──────────────┼──────────────────┐
                 │              │                   │
            Web-страницы   REST API v1       SSE/Push
            (cookie/session) (JWT bearer)   (order_updates.php)
                 │              │                   │
                 └──────────────┼──────────────────┘
                                │
                     db.php (Singleton + RedisCache)
                                │
                             MySQL 5.7+
```

**Два контекста аутентификации:**
- **Web:** cookie + session (CSRF, nonce CSP)
- **API:** JWT access/refresh tokens (`MobileTokenAuth`)

---

## Переменные окружения

Файл `.env.example`. Все переменные читаются через `getenv()` или `$_ENV`:

| Переменная | Назначение |
|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Подключение MySQL |
| `DB_PDO_PERSISTENT` | Persistent PDO (true/false) |
| `GOOGLE_OAUTH_CLIENT_IDS` | Client ID Google (через запятую для мультиапп) |
| `GOOGLE_OAUTH_CLIENT_SECRET` | Secret Google OAuth |
| `YANDEX_OAUTH_CLIENT_ID`, `YANDEX_OAUTH_CLIENT_SECRET` | Yandex ID |
| `VK_OAUTH_CLIENT_ID`, `VK_OAUTH_CLIENT_SECRET` | VK ID |
| `MOBILE_TOKEN_SECRET` | HMAC-ключ для JWT (авто-генерация из DB credentials если не задан) |
| `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID` | Telegram-бот для уведомлений о заказах/бронях |

---

## Структура файлов

### Корневой каталог

#### Инфраструктура
| Файл | Назначение |
|---|---|
| `session_init.php` | Центральная инициализация: сессия, CSP, CORS, CSRF, cookie |
| `db.php` | Singleton БД, все CRUD, кэширование, Redis-fallback |
| `RedisCache.php` | Redis-обёртка с fallback на in-memory кэш |
| `Queue.php` | Файловая очередь задач (email, верификация). Хранение: `data/queues/` |
| `mailer.php` | PHPMailer через SMTP Yandex (smtp.yandex.ru:465) |
| `ImageOptimizer.php` | WebP-оптимизация изображений при загрузке (используется в file-manager.php) |
| `AssetPipeline.php` | CSS/JS бандлинг с хешированием (не активирован) |
| `require_auth.php` | Middleware: проверка роли, редирект на auth.php |
| `check-auth.php` | AJAX-эндпоинт: JSON со статусом сессии + remember-me восстановление |
| `config_copy.php` | Константы БД (не в репо, генерируется при деплое) |

#### Аутентификация
| Файл | Назначение |
|---|---|
| `auth.php` | Логин/регистрация (вкладки), OAuth-кнопки |
| `logout.php` | Уничтожение сессии, чистка cookie remember/session/csrf |
| `verify.php` | Подтверждение email по токену из GET |
| `password-reset.php` | Запрос сброса + установка нового пароля |
| `google-oauth-start.php` | Редирект на Google OAuth с state+cookie привязкой |
| `google-oauth-callback.php` | Обмен code→id_token, верификация JWT, upsert пользователя |
| `vk-oauth-start.php` | Редирект на VK OAuth |
| `vk-oauth-callback.php` | Обмен code→access_token→userinfo, upsert пользователя |
| `yandex-oauth-start.php` | Редирект на Yandex OAuth |
| `yandex-oauth-callback.php` | Обмен code→access_token→userinfo, upsert пользователя |

#### Основные страницы
| Файл | Назначение |
|---|---|
| `index.php` | Лендинг: hero, about, форма бронирования, контакты |
| `header.php` | Шапка сайта: лого, навигация, счётчик корзины |
| `account-header.php` | Навигация аккаунта (роль-зависимые ссылки) |
| `menu.php` | Меню: выбор шаблона по `users.menu_view`, категории |
| `menu-content.php` | Шаблон: список с карточками и addToCart |
| `menu-content-info.php` | Шаблон: карточки + модалка состава/КБЖУ |
| `menu-alt.php` | Шаблон: карточки без состава |
| `menu-public.php` | Публичное меню (без авторизации) |
| `cart.php` | Корзина + модалка доставки + QR-сканер для стола |
| `account.php` | Профиль, смена пароля, предпочтения меню, повтор заказа |
| `employee.php` | Дашборд сотрудника: управление статусами заказов |
| `owner.php` | Дашборд владельца: аналитика, управление пользователями |
| `admin-menu.php` | Админка: CRUD товаров, CSV-импорт, дизайн, файлы |
| `customer_orders.php` | История заказов клиента с повтором |

#### Заказы и API (web)
| Файл | Назначение |
|---|---|
| `create_new_order.php` | POST: создание заказа (авторизованный) с идемпотентностью |
| `create_guest_order.php` | POST: гостевой заказ (телефон обязателен) |
| `update_order_status.php` | POST: смена статуса + Web Push уведомление |
| `order_updates.php` | SSE: стрим обновлений заказов (25 сек, poll 3 сек) |
| `customer_orders.php` | Частичный рендер через `?partial=account-sections` |
| `send_message.php` | POST→Telegram API: уведомления о заказах/бронях |

#### Дизайн-система
| Файл | Назначение |
|---|---|
| `auto-colors.php` | CSS: `:root` с 12 цветовыми переменными из DB |
| `auto-fonts.php` | CSS: `@font-face` из `/fonts/` + `:root` с `--font-logo/text/heading` из DB |
| `dynamic-fonts.php` | CSS: одиночный `@font-face` для динамической подгрузки шрифта |
| `save-colors.php` | POST: сохранение 12 цветов в `settings` (валидация hex) |
| `save-fonts.php` | POST: сохранение 3 шрифтов в `settings` (валидация font-family) |
| `save-project-name.php` | POST: сохранение названия проекта |

#### Утилиты
| Файл | Назначение |
|---|---|
| `file-manager.php` | API: list/upload/delete/create_folder + get_fonts для дизайн-менеджера |
| `monitor.php` | Дашборд мониторинга: сервер, PHP, БД, OPcache |
| `clear-cache.php` | Сброс кэша: client (localStorage) или server (OPcache/Redis) |
| `download-sample.php` | Скачивание CSV-шаблона для импорта товаров |
| `db-indexes-optimizer-v2.php` | Одноразовый скрипт оптимизации индексов БД |

#### PWA
| Файл | Назначение |
|---|---|
| `sw.js` | Service Worker v13: offline-fallback для навигации, push-уведомления |
| `manifest.webmanifest` | PWA-манифест: иконки, screenshot, standalone |
| `offline.html` | Fallback-страница при отсутствии сети |
| `version.json` | Текущая версия для автообновления клиентов |

### /lib/ — Библиотеки

| Файл | Назначение |
|---|---|
| `ApiResponse.php` | Утилита: `json()`, `success()`, `error()`, `readJsonBody()` |
| `MobileTokenAuth.php` | JWT: `issueTokenPair()`, `verifyToken()`, `rotateRefreshToken()`, `revokeRefreshToken()` |
| `Idempotency.php` | Идемпотентность: `find()`, `store()`, `ensureTable()`. TTL 900 сек. Ключ: SHA256 |
| `OAuthGoogle.php` | Верификация Google id_token (RS256 + JWKS кэш 6ч в `data/oauth/`) |
| `OAuthYandex.php` | Yandex OAuth: обмен кода, получение профиля |
| `OAuthVK.php` | VK OAuth: обмен кода, получение профиля |

### /api/v1/ — REST API для мобильного приложения

| Эндпоинт | Метод | Auth | Назначение |
|---|---|---|---|
| `bootstrap.php` | — | — | Инициализация API-контекста, подключение зависимостей |
| `auth/login.php` | POST | — | Логин по email/password → access+refresh tokens |
| `auth/refresh.php` | POST | — | Ротация refresh token → новая пара |
| `auth/me.php` | GET | Bearer | Текущий пользователь (id, email, name, phone, role) |
| `auth/logout.php` | POST | Bearer | Revoke refresh token |
| `auth/oauth/google.php` | POST | — | Google OAuth для мобильного приложения |
| `menu.php` | GET | — | Список товаров, фильтр `?category=` |
| `orders/create.php` | POST | Bearer | Создание заказа (идемпотентность через заголовок) |
| `orders/status.php` | GET | Bearer | Статус заказа по `?order_id=` |
| `profile/phone.php` | POST | Bearer | Обновление телефона |
| `push/subscribe.php` | POST | Bearer/Guest | Подписка на Web Push (поддержка гостей по phone+order_id) |
| `geocode.php` | GET | — | Reverse geocoding: `?lat=&lon=` → адрес (Nominatim OSM) |

### /scripts/ — CLI-утилиты

| Файл | Назначение |
|---|---|
| `api-smoke-runner.php` | Smoke-тесты API (login→me→refresh→push→order). Флаги: `--run-order`, `--insecure` |
| `api-metrics-report.php` | Парсинг `data/logs/api-performance.log` → p50/p95/p99 |
| `db/backfill-order-items.php` | Миграция JSON `orders.items` → таблица `order_items`. Флаги: `--dry-run`, `--chunk`, `--from-id` |
| `fix_encoding.php` | Одноразовая починка двойной UTF-8 в БД |

### /deploy/ — Конфигурации деплоя

**Nginx:**
- `pool-split-upstreams.conf` — upstream-блоки для 3 PHP-FPM пулов
- `server-locations-pool-split.conf` — роутинг: `/api/v1/*`→api, SSE→sse, остальное→web
- `api-microcache.conf` — микрокэш для `/api/v1/menu.php`

**PHP-FPM:**
- `pool-menu.labus.pro-web.conf` — пул для веб-страниц
- `pool-menu.labus.pro-api.conf` — пул для API
- `pool-menu.labus.pro-sse.conf` — пул для SSE (долгоживущие запросы до 25с)

### /data/ — Рантайм-данные (не в git)

| Путь | Содержимое |
|---|---|
| `data/logs/api-performance.log` | Логи API: request_id, method, uri, status, duration_ms |
| `data/oauth/google-jwks.json` | Кэш Google JWKS (6ч TTL) |
| `data/queues/` | Файлы очередей (Queue.php) |
| `data/vapid-keys.json` | VAPID-ключи для Web Push |

### /fonts/, /images/, /icons/

- `/fonts/` — загруженные шрифты (.woff2, .ttf, .otf). Сканируются `auto-fonts.php`
- `/images/` — баннеры (HDR_*.webp), скриншоты PWA, подпапки категорий (Cocktail/, Pizza/, Sets/, Snaks/)
- `/icons/` — PWA-иконки: favicon.ico, icon-128 до icon-512 (.png)

---

## Инфраструктурный слой

### session_init.php (616 строк)

Включается **первым** во всех файлах. Три контекста: `web`, `api`, `sse`.

**Что делает:**
- Устанавливает security-заголовки: CSP (nonce), X-Frame-Options: DENY, HSTS, Permissions-Policy
- Настраивает сессию: cookie 30 дней, SameSite=Strict, HttpOnly, Secure
- Генерирует CSRF-токен (ротация каждые 24ч)
- Синхронизирует данные пользователя из БД (каждые 5 мин)
- Детектирует смену IP/UA (suspicious activity)
- Регенерирует session ID для анонимов (каждые 30 мин)
- Отдаёт статику с кэшем 1 год (exit early для .css/.js/.woff2/.webp)
- CORS: `menu.labus.pro`, `capacitor://localhost`, `ionic://localhost`
- API-контекст: без сессии, без CSP, без CSRF
- Логирует API-запросы в `data/logs/api-performance.log`

**Сессионные переменные (создаются/обновляются):**

| Переменная | Описание |
|---|---|
| `user_id` | ID пользователя |
| `user_email`, `user_name`, `user_role` | Профиль |
| `csrf_token`, `csrf_token_created` | CSRF-защита |
| `csp_nonce[script]`, `csp_nonce[style]` | Nonce для CSP |
| `app_version` | Версия для cache-busting |
| `project_name` | Название проекта |
| `last_activity` | Таймстамп последнего запроса |
| `last_regeneration` | Таймстамп регенерации session ID |
| `security_check[ip]`, `security_check[ua]` | Привязка к устройству |
| `user_last_sync` | Последняя синхронизация с БД |

### db.php — Database (1820 строк, Singleton)

**Подключение:** PDO MySQL, UTF8MB4, persistent (опционально), компрессия, timezone +03:00.
Кэш prepared statements в `$preparedStatements[]`.

**Публичные методы:**

**Подключение:**
- `getInstance()` — Singleton
- `getConnection()` — сырой PDO
- `close()` — уничтожение
- `prepare($sql)` — кэшированный prepared statement

**Товары:**
- `getProductById($id)` — кэш 1800с
- `getMenuItems($category = null)` — кэш 1800с
- `getUniqueCategories()` — кэш 3600с
- `addMenuItem(name, description, composition, price, image, cal, prot, fat, carbs, category, available)`
- `updateMenuItems(id, ...)` — обновление + инвалидация кэша
- `bulkUpdateMenu($csvHandle, $delimiter)` — CSV-импорт в транзакции

**Пользователи:**
- `createUser(email, hash, name, phone, token)`
- `getUserById($id)`, `getUserByEmail($email)`, `getAllUsers()`
- `updateUser(id, name, phone)`, `updateUserPhone(id, phone)`
- `updatePassword(id, hash)`, `updateMenuView(id, view)`, `updateUserRole(id, role)`

**Аутентификация:**
- `verifyUser($token)` — подтверждение email (транзакция)
- `setPasswordResetToken(email, token)` — токен сброса (1ч)
- `resetPassword(token, hash)` — сброс пароля (транзакция)
- `saveRememberToken(userId, selector, hash, expires)`, `getRememberToken($selector)`, `updateRememberToken(...)`, `deleteRememberToken($selector)`, `deleteExpiredTokens()`

**Заказы:**
- `createOrder(userId, items, total, deliveryType, deliveryDetail)` — транзакция: orders + order_items + status_history
- `createGuestOrder(items, total, deliveryType, deliveryDetail)` — guest user ID 999999
- `getOrderById($id)`, `getOrderStatus($id)`
- `updateOrderStatus(orderId, status, userId)` — транзакция + history
- `getAllOrders()`, `getUserOrders($userId)`
- `getOrderUpdatesSince($ts)`, `getUserOrderUpdatesSince($userId, $ts)`, `getOrdersLastUpdateTs()`

**Корзина:**
- `getCartTotalCountForUser($userId)`

**Аналитика (owner.php):**
- `getSalesReport($period)`, `getProfitReport($period)`, `getEfficiencyReport($period)`
- `getTopCustomers($period, $limit)`, `getTopDishes($period, $limit)`
- `getHourlyLoad($period)`, `getEmployeeStats($period, $limit)`

**Настройки:**
- `getSetting($key)`, `setSetting($key, $value, $updatedBy)` — INSERT ON DUPLICATE KEY UPDATE
- `getAllSettings()`

**Статусы:**
- `getUniqueOrderStatuses()` — упорядоченные: Приём → готовим → доставляем → завершён → отказ

---

## Аутентификация и авторизация

### Роли
| Роль | Доступ |
|---|---|
| `customer` | Меню, корзина, заказы, профиль |
| `employee` | + управление статусами заказов |
| `admin` | + CRUD товаров, дизайн, файлы, мониторинг |
| `owner` | + аналитика, управление ролями |

### Web-аутентификация (auth.php)

**Регистрация:** email + пароль (8+ символов, 1 заглавная) → `Queue::push('send_verification_email')` → verify.php по токену.

**Логин:** email + password → `password_verify()` → сессия + опционально remember-me cookie (30 дней, selector:validator).

**OAuth:** Google / Yandex / VK. Каждый провайдер:
1. `*-start.php` — генерация state с HMAC-подписью, cookie привязка (SameSite=Lax, 5 мин), редирект
2. `*-callback.php` — валидация state, обмен code→token, верификация, upsert `oauth_identities` + `users`, создание сессии

### Mobile API аутентификация (MobileTokenAuth)

`POST /api/v1/auth/login.php` → access_token (1ч) + refresh_token (30д).
`POST /api/v1/auth/refresh.php` → ротация пары.
Хранение refresh-токенов: таблица `mobile_refresh_tokens`.

---

## Страницы (Web)

### Лендинг (index.php)
Hero-баннер (responsive WebP: 320/640/1024/1440), about-секция, форма бронирования (name/phone/date/time/guests → send_message.php → Telegram), контакты (Махачкала, Олега Кошевого 46а, +7-964-002-02-00), соцсети.

### Меню (menu.php → шаблоны)
Выбор шаблона по `users.menu_view`:
- `default` → `menu-content.php` — список
- `info` → `menu-content-info.php` — карточки + модалка КБЖУ
- `alt` → `menu-alt.php` — карточки без состава

Категории через вкладки, cookie `activeMenuCategory`. Кэш: 10 мин.

### Корзина (cart.php)
Хранение: localStorage. Типы доставки: бар / стол (QR-сканер или ввод) / вынос / доставка (адрес + геолокация). Для гостей: модалка с телефоном или приглашением залогиниться.

### Аккаунт (account.php)
Профиль, смена пароля, предпочтения вида меню (3 варианта), повтор заказа из истории.

### Сотрудник (employee.php)
Заказы по статусам (вкладки), смена статуса одним кликом, push-уведомления клиенту. Профиль + смена пароля.

### Владелец (owner.php)
Вкладка «Статистика»: 7 типов отчётов (продажи, прибыль, эффективность, клиенты, блюда, нагрузка, сотрудники) по периодам (день/неделя/месяц/год) + Chart.js графики. Вкладка «Пользователи»: список с назначением ролей.

### Админка (admin-menu.php)
Вкладка «Блюда»: одиночное добавление/редактирование + CSV-импорт.
Вкладка «Дизайн»: название проекта, файловый менеджер, шрифты (3 слота), цвета (12 переменных).
Вкладка «Система»: ссылка на monitor.php.

---

## REST API v1 (Mobile)

**Инициализация:** `api/v1/bootstrap.php` — подключает `ApiResponse`, `MobileTokenAuth`, `Idempotency`, устанавливает `LABUS_CTX = 'api'`.

Хелперы:
- `api_v1_require_method($method)` — валидация HTTP-метода
- `api_v1_auth_user_from_bearer()` — извлечение пользователя из Bearer-токена

**Подробности эндпоинтов:**

`POST /api/v1/auth/login.php` — вход: `{email, password, device_name?}` → `{access_token, refresh_token, user}`. Статусы: 200/401/403.

`POST /api/v1/auth/refresh.php` — ротация: `{refresh_token, device_name?}` → новая пара. Старый refresh аннулируется.

`GET /api/v1/auth/me.php` — Bearer → `{id, email, name, phone, role}`.

`GET /api/v1/menu.php` — `?category=Пицца` → массив товаров.

`POST /api/v1/orders/create.php` — `{items, total, delivery_type, delivery_details}` + заголовок `Idempotency-Key`. Ответ: `{order_id, status}` (201 или 200 при replay).

`GET /api/v1/orders/status.php` — `?order_id=123` → текущий статус.

`POST /api/v1/push/subscribe.php` — Web Push подписка. Поддержка гостей по phone+order_id.

`GET /api/v1/geocode.php` — `?lat=42.95&lon=47.51` → адрес (прокси к Nominatim OSM, таймаут 5с).

**Документация smoke-тестов:** `docs/api-smoke.md`.

---

## Заказы

### Поток создания
1. Клиент: `cart.min.js` → POST `/create_new_order.php` (или `create_guest_order.php`)
2. Сервер: валидация CSRF + payload → `$db->createOrder()` (транзакция: orders + order_items + status_history)
3. Идемпотентность: `Idempotency-Key` header → SHA256 хеш payload → кэш 15 мин
4. `send_message.php` → Telegram API (уведомление)

### Поток статусов
```
Приём → готовим → доставляем → завершён
                              → отказ
```

Смена: `update_order_status.php` (POST, employee/owner/admin) → `$db->updateOrderStatus()` → Web Push → SSE.

### Типы доставки
| Тип | delivery_details |
|---|---|
| `bar` | — (опционально) |
| `table` | Номер стола (обязательно) |
| `takeaway` | — (опционально) |
| `delivery` | Адрес (обязательно) |

Для гостевого заказа: телефон дописывается в delivery_details как `; Телефон: +7XXXXXXXXXX`.

---

## Дизайн-система

### Цвета (работает через DB)
- **Интерфейс:** 12 color-picker'ов в admin-menu.php, вкладка «Дизайн»
- **Сохранение:** `saveColors()` JS → POST `/save-colors.php` → `settings` таблица (`color_{varname}` = JSON hex)
- **Применение:** `auto-colors.php` генерирует CSS `:root { --primary-color: #cd1719; ... }`, подключается через `@import` в `fa-styles.min.css`
- **Кэш:** no-cache (динамический CSS)

**12 CSS-переменных:**
`--primary-color`, `--secondary-color`, `--primary-dark`, `--accent-color`, `--text-color`, `--acception`, `--light-text`, `--bg-light`, `--white`, `--agree`, `--procces`, `--brown`

### Шрифты (работает через DB)
- **Интерфейс:** 3 select'а + checkbox'ы override в admin-menu.php
- **Доступные шрифты:** сканируются из `/fonts/` через `file-manager.php?action=get_fonts`
- **Сохранение:** `saveFonts()` JS → POST `/save-fonts.php` → `settings` (`font_logo/text/heading` = JSON font-family)
- **Применение:** `auto-fonts.php` генерирует `@font-face` + `:root { --font-logo: ...; --font-text: ...; --font-heading: ...; }`
- **Динамическая подгрузка:** `loadFontFace()` → `dynamic-fonts.php?font=X&file=Y` — одиночный `@font-face`
- **Fallback:** admin-menu.php пробрасывает DB-значения в localStorage через inline-скрипт

**Дефолтные шрифты:** Magistral (logo), proxima-nova (text), Inter (heading)

---

## Файловый менеджер

**Эндпоинт:** `file-manager.php`

| Action | Метод | Назначение |
|---|---|---|
| `list` | GET | Листинг файлов/папок. Params: `folder`, `subfolder` |
| `get_fonts` | GET | Список шрифтов из `/fonts/` (имя→файл) |
| `upload` | POST | Загрузка файлов. CSRF. FormData: file, folder, subfolder |
| `delete` | POST | Удаление файла/папки. JSON: folder, path, type, csrf_token |
| `create_folder` | POST | Создание папки. JSON: folder, subfolder, name (regex: `[a-zA-Z0-9_ -]`), csrf_token |

**Допустимые корневые папки:** `images`, `fonts`, `icons`.

При загрузке изображений вызывается `ImageOptimizer` для WebP-конвертации.

---

## Push-уведомления и SSE

### Web Push
- **Подписка:** `/api/v1/push/subscribe.php` — сохраняет endpoint+keys в `push_subscriptions`
- **Отправка:** `update_order_status.php` → `sendPushNotificationsForOrder()` через `minishlink/web-push` (VAPID)
- **VAPID-ключи:** `data/vapid-keys.json`
- **Клиент:** `push-notifications.min.js` — регистрация и обработка нотификаций

### SSE (Server-Sent Events)
- **Эндпоинт:** `order_updates.php`
- **Протокол:** `text/event-stream`
- **Длительность:** ~25 секунд на подключение
- **Poll-интервал:** 3 секунды (запрос к БД)
- **События:** `hello`, `order_update` (JSON заказа), `ping`, `bye`
- **Изоляция:** отдельный PHP-FPM пул `sse` (не блокирует web/api)

### Telegram
- **Эндпоинт:** `send_message.php`
- **Типы:** `order`, `reservation`
- **Внешний API:** `https://api.telegram.org/bot{TOKEN}/sendMessage`
- **Конфиг:** `/../config.php` (TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID)

---

## PWA и Service Worker

### manifest.webmanifest
- `display: standalone`, `orientation: portrait-primary`
- Иконки: 128–512px PNG (maskable)
- Скриншоты: 1080x1920 (phone), 1920x1080 (tablet)
- `start_url: /?source=pwa`

### sw.js (v13)
- Прекэш: `offline.html`, `manifest.webmanifest`, `favicon.ico`
- Перехватывает **только HTML-навигацию** (не API, не ассеты)
- При offline → `offline.html`
- Push-обработчик: показ нотификации → клик → переход на `/menu.php` или `data.url`
- Сообщения: `SKIP_WAITING`, `CLEAR_CACHE`

### offline-queue.js
- Перехватывает POST на `/create_new_order.php`, `/create_guest_order.php`
- При offline: сохраняет в localStorage (`offline_order_queue_v1`), возвращает 202
- При online: проигрывает очередь

### version-checker.min.js
- Проверяет `/version.json` на изменения
- При `critical: true` — принудительная перезагрузка

---

## JavaScript

### Основные скрипты (/js/)

| Файл | Назначение | Вызываемые эндпоинты |
|---|---|---|
| `app.min.js` | Меню: вкладки, поиск, addToCart, бронирование | `send_message.php`, `check-auth.php` |
| `cart.min.js` | Корзина: CRUD, модалка доставки, QR-сканер, checkout | `create_new_order.php`, `create_guest_order.php` |
| `security.min.js` | CSRF-утилиты, XSS-защита | — |
| `auth.min.js` | Формы входа/регистрации, toggle пароля | — |
| `account.min.js` | Профиль, повтор заказа, preferences | `customer_orders.php` |
| `owner.min.js` | Аналитика: Chart.js графики, таблицы, управление пользователями | — |
| `file-manager.min.js` | Файлы, дизайн (шрифты+цвета+проект), вкладки | `file-manager.php`, `save-fonts.php`, `save-colors.php`, `save-project-name.php`, `dynamic-fonts.php` |
| `admin-tabs-repair.js` | Починка/инициализация вкладок admin-menu.php + owner.php | — |
| `ws-orders.min.js` | WebSocket/polling обновлений заказов | `order_updates.php` |
| `push-notifications.min.js` | Регистрация Push, обработка уведомлений | `api/v1/push/subscribe.php` |
| `pwa-install.min.js` | Промпт установки PWA | — |
| `version-checker.min.js` | Проверка версии → перезагрузка | `version.json` |
| `offline-queue.js` | Офлайн-очередь заказов (localStorage) | `create_new_order.php`, `create_guest_order.php` |
| `employee-status-fix.js` | Фикс отображения статусов у сотрудника | — |
| `preloader-manager.min.js` | Управление индикаторами загрузки | — |
| `preloader-csp.min.js` | CSP-совместимый прелоадер | — |
| `qr-scanner.min.js` | QR-сканер (обёртка) | — |
| `qr-scanner-lazy.min.js` | Ленивая загрузка QR-сканера | — |
| `html5-qrcode.min.js` | Библиотека QR-детекции (367KB) | — |
| `jquery.min.js` | jQuery (legacy, 88KB) | — |

---

## CSS

Все файлы подключают динамический CSS через `@import`:
```css
/* fa-styles.min.css, строки 1-2 */
@import "../auto-fonts.php";
@import "../auto-colors.php";
```

| Файл | Назначение | Размер |
|---|---|---|
| `fa-styles.min.css` | FontAwesome 6 + импорт auto-fonts/colors | 44KB |
| `fa-purged.min.css` | FontAwesome (урезанный набор) | 11KB |
| `account-styles.min.css` | Аккаунт, формы, профиль, auth | 11KB |
| `admin-menu-polish.css` | Админка, таблицы, файл-менеджер, дизайн | 30KB |
| `owner-styles.min.css` | Дашборд владельца, графики | 4.6KB |
| `menu-alt.min.css` | Альтернативная сетка меню | 1.1KB |
| `menu-content-info.min.css` | Меню с КБЖУ-модалкой | 603B |
| `preloader.min.css` | Спиннеры, анимации загрузки | 2KB |
| `version.min.css` | Индикатор версии | 2.5KB |

**Ключевые CSS-переменные (используются во всех стилях):**
- Цвета: `--primary-color`, `--secondary-color`, `--primary-dark`, `--accent-color`, `--text-color`, `--acception`, `--light-text`, `--bg-light`, `--white`, `--agree`, `--procces`, `--brown`
- Шрифты: `--font-logo`, `--font-text`, `--font-heading`

---

## База данных

### Схема таблиц

```sql
users (
  id INT PK AUTO_INCREMENT,
  email VARCHAR UNIQUE,
  password_hash VARCHAR,
  name VARCHAR,
  phone VARCHAR,
  is_active TINYINT DEFAULT 0,
  role ENUM('customer','employee','admin','owner'),
  created_at DATETIME,
  email_verified_at DATETIME,
  menu_view VARCHAR DEFAULT 'default',
  verification_token VARCHAR,
  verification_token_expires_at DATETIME,
  reset_token VARCHAR,
  reset_token_expires_at DATETIME
)

menu_items (
  id INT PK AUTO_INCREMENT,
  name VARCHAR,
  description TEXT,
  composition TEXT,
  price DECIMAL,
  image VARCHAR,
  calories INT,
  protein INT,
  fat INT,
  carbs INT,
  category VARCHAR,
  available TINYINT DEFAULT 1
)

orders (
  id INT PK AUTO_INCREMENT,
  user_id INT FK→users,
  items JSON,
  total DECIMAL,
  status ENUM('Приём','готовим','доставляем','завершён','отказ'),
  delivery_type ENUM('bar','table','takeaway','delivery'),
  delivery_details TEXT,
  created_at DATETIME,
  updated_at DATETIME,
  last_updated_by INT
)

order_items (
  id INT PK AUTO_INCREMENT,
  order_id INT FK→orders,
  item_id INT,
  item_name VARCHAR,
  quantity INT,
  price DECIMAL,
  created_at DATETIME
)

order_status_history (
  id INT PK AUTO_INCREMENT,
  order_id INT FK→orders,
  status VARCHAR,
  changed_by INT,
  changed_at DATETIME
)

auth_tokens (
  id INT PK AUTO_INCREMENT,
  user_id INT FK→users,
  selector VARCHAR UNIQUE,
  hashed_validator VARCHAR,
  expires_at DATETIME
)

oauth_identities (
  provider VARCHAR,
  provider_subject VARCHAR,
  user_id INT FK→users,
  UNIQUE(provider, provider_subject)
)

mobile_refresh_tokens (
  id INT PK AUTO_INCREMENT,
  user_id INT FK→users,
  token_hash VARCHAR,
  device_name VARCHAR,
  expires_at DATETIME,
  revoked TINYINT DEFAULT 0
)

push_subscriptions (
  id INT PK AUTO_INCREMENT,
  user_id INT NULL,
  endpoint TEXT,
  p256dh VARCHAR,
  auth_key VARCHAR,
  phone VARCHAR NULL,
  order_id INT NULL
)

settings (
  key VARCHAR PK,
  value TEXT,
  updated_by INT,
  updated_at DATETIME
)

api_idempotency_keys (
  id BIGINT PK AUTO_INCREMENT,
  idempotency_key VARCHAR(128),
  scope VARCHAR(64),
  request_hash CHAR(64),
  response_json JSON,
  created_at DATETIME,
  expires_at DATETIME,
  UNIQUE(scope, idempotency_key),
  KEY(expires_at)
)

cart_items (
  user_id INT,
  item_id INT,
  quantity INT
)
```

### Гостевой пользователь
Системный user ID `999999` для гостевых заказов. Создаётся автоматически через `ensureGuestUserExists()`.

---

## Безопасность

| Механизм | Реализация |
|---|---|
| Пароли | `password_hash(PASSWORD_DEFAULT)` — bcrypt |
| CSRF | Токен в сессии, ротация 24ч, проверка на всех POST |
| CSP | Nonce-based для script и style, настройка в session_init.php |
| XSS | `htmlspecialchars()` на всём выводе, CSP |
| SQL Injection | Prepared statements везде |
| Session Fixation | `session_regenerate_id(true)` при логине |
| Session Hijacking | Привязка к IP+UA, регенерация каждые 30 мин |
| OAuth CSRF | State с HMAC-подписью + cookie привязка |
| Remember-me | Selector/validator паттерн (не raw token в cookie) |
| Идемпотентность | SHA256 хеш payload + 15-мин кэш |
| CORS | Whitelist: `menu.labus.pro`, `capacitor://localhost`, `ionic://localhost` |
| Headers | X-Frame-Options: DENY, X-Content-Type-Options: nosniff, Referrer-Policy: strict-origin-when-cross-origin |

---

## Кэширование и производительность

### Redis (RedisCache.php)
- Методы: `get()`, `set()`, `mget()`, `mset()`, `delete()`, `invalidate()`, `clear()`, `getStats()`
- TTL: товары 1800с, категории 3600с, заказы 86400с
- Fallback: in-memory массив при отсутствии Redis

### Браузерный кэш
- Статика (CSS/JS/fonts/images): `max-age=31536000` с версионированием `?v=`
- HTML: `max-age=600` (10 мин)
- Динамические CSS (`auto-colors.php`, `auto-fonts.php`): `no-cache`

### OPcache
- Мониторинг через `monitor.php`
- Сброс через `clear-cache.php?scope=server`

### PHP-FPM пулы (deploy/)
- `web` — обычные страницы (dynamic)
- `api` — REST API (изолированный)
- `sse` — SSE (долгоживущие, pm.max_children 10-30)

### Nginx microcache
- `api-microcache.conf` — кэш `/api/v1/menu.php` (bypass при Authorization)

### БД
- Persistent PDO connections
- MySQL compression
- Prepared statement cache
- `session_write_close()` для снятия блокировки при чтении меню

---

## Deploy-конфигурации

### Nginx (/deploy/nginx/)
| Файл | Назначение |
|---|---|
| `pool-split-upstreams.conf` | Upstream-блоки: web (9000), api (9001), sse (9002) |
| `server-locations-pool-split.conf` | Роутинг: `/api/v1/*` → api, SSE → sse, остальное → web |
| `api-microcache.conf` | Microcache для публичного API меню |

### PHP-FPM (/deploy/php-fpm/)
| Файл | Назначение |
|---|---|
| `pool-menu.labus.pro-web.conf` | Пул для веб-страниц |
| `pool-menu.labus.pro-api.conf` | Пул для API |
| `pool-menu.labus.pro-sse.conf` | Пул для SSE (slowlog, request_slowlog_timeout) |

---

## CLI-скрипты и утилиты

| Скрипт | Назначение | Пример |
|---|---|---|
| `scripts/api-smoke-runner.php` | Smoke-тесты всех API-эндпоинтов | `php scripts/api-smoke-runner.php --base=https://menu.labus.pro --email=x --password=y --run-order=1` |
| `scripts/api-metrics-report.php` | p50/p95/p99 из логов API | `php scripts/api-metrics-report.php` |
| `scripts/db/backfill-order-items.php` | Миграция orders.items JSON → order_items | `php scripts/db/backfill-order-items.php --dry-run` |
| `scripts/fix_encoding.php` | Починка двойной UTF-8 | одноразовый |
| `db-indexes-optimizer-v2.php` | Анализ и добавление индексов БД | одноразовый |
| `Queue.php` (CLI-режим) | Воркер очереди email | `php Queue.php` |

---

## Внешние зависимости

### Composer (vendor/)
| Пакет | Назначение |
|---|---|
| `guzzlehttp/guzzle` | HTTP-клиент (OAuth, API-вызовы) |
| `web-token/jwt-library` | JWT верификация/создание |
| `minishlink/web-push` | Web Push уведомления (VAPID) |
| `spomky-labs/pki-framework` | Криптография для JWT |
| `brick/math` | Математика для крипто-операций |

### npm (node_modules/)
Используется для PostCSS/cssnano (минификация CSS), SVG-оптимизация.

### Внешние API
| Сервис | Использование |
|---|---|
| Google OAuth | Авторизация (JWKS кэш: `data/oauth/google-jwks.json`) |
| Yandex OAuth | Авторизация |
| VK OAuth | Авторизация |
| Nominatim (OSM) | Reverse geocoding для доставки |
| Telegram Bot API | Уведомления о заказах/бронях |
| Yandex SMTP | Отправка email (верификация, сброс пароля) |

---

## Cookies

| Cookie | TTL | Flags | Назначение |
|---|---|---|---|
| `PHPSESSID` | 30д | Secure, HttpOnly, SameSite=Strict | Сессия |
| `remember` | 30д | Secure, HttpOnly, SameSite=Strict | Persistent login (selector:validator) |
| `g_oauth_state` | 5 мин | Secure, SameSite=Lax | Google OAuth state |
| `vk_oauth_state` | 5 мин | Secure, SameSite=Lax | VK OAuth state |
| `yandex_oauth_state` | 5 мин | Secure, SameSite=Lax | Yandex OAuth state |
| `activeMenuCategory` | session | — | Текущая вкладка категории |
| `activeOrderTab` | session | — | Текущая вкладка статуса заказов |
