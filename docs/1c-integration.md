# 1С интеграция (Phase 37)

Phase 37 экспонирует read-only OData v3 endpoint для подключения 1С Конфигуратора как «Внешний источник данных». Закрывает blocker для 80%+ ресторанов которые ведут учёт в 1С Бухгалтерии / УНФ.

## Архитектура

### Таблица (`sql/odata-credentials-migration.sql`)

Single-row table per tenant:

| Поле | Назначение |
|---|---|
| `username` (VARCHAR 64 UNIQUE) | стабильный логин (генерится при первом rotate как `odata_<random>`) |
| `api_key_hash` (VARCHAR 255) | `password_hash()` result; plaintext в БД не хранится |
| `enabled` (TINYINT 0/1) | toggle для быстрого on/off без удаления ключа |
| `last_used_at` (DATETIME NULL) | best-effort timestamp обновляется при каждом успешном auth |

### Auth flow

1. Клиент (1С Конфигуратор) шлёт `Authorization: Basic base64(username:api_key)`.
2. `BasicAuth::require($db)` декодирует, дёргает `Database::verifyOdataAuth()`.
3. `password_verify()` сравнивает plaintext key с stored hash; enabled=1 обязателен.
4. На success — `last_used_at` обновляется (best-effort, не блокирует ответ).
5. На fail — 401 + `WWW-Authenticate: Basic realm="CleanMenu OData"`.

### OData query grammar

Поддерживается deliberate subset (достаточно для 1С внешнего источника):

| Option | Значение | Пример |
|---|---|---|
| `$top` | 1..1000, default 100 | `?$top=50` |
| `$skip` | 0+, max 1M | `?$skip=100` |
| `$count` | `true/1/yes` → добавляет `__count` в envelope | `?$count=true` |
| `$select` | whitelist полей через `,` | `?$select=Id,Total,CreatedAt` |
| `$filter` | мини-grammar (см. ниже) | `?$filter=Total gt 1000` |

Filter grammar:

- `<field> eq|ne|gt|ge|lt|le <literal>` — поля только из whitelist (иначе 400)
- `substringof('text', Field)` → SQL `LIKE '%text%'`
- `<term> and <term> ...` — AND only (без OR / NOT в MVP)

Literals: `'string'`, `datetime'2026-05-12T10:00:00'` → MySQL DATETIME, числа, `null`, `true`/`false`.

Все литералы попадают в SQL через PDO-параметры — SQL-injection невозможна.

### Envelope

```json
{
  "d": {
    "results": [
      { "Id": 1, "Total": 1500.00, "CreatedAt": "2026-05-12 10:00:00" },
      ...
    ],
    "__count": "42"   // только если $count=true
  }
}
```

`Content-Type: application/json; odata=verbose; charset=utf-8` + `Cache-Control: no-store`.

## API

`GET /api/v1/odata/orders.php` — Orders entity. Поля: `Id, UserId, Total, Tips, Status, DeliveryType, DeliveryDetails, PaymentMethod, PaymentStatus, ShiftId, CreatedAt, UpdatedAt`.

`GET /api/v1/odata/menu_items.php` — MenuItems entity. Поля: `Id, Name, Description, Price, Category, IsAvailable, Cost, CreatedAt, UpdatedAt`.

`GET /api/v1/odata/customers.php` — Customers entity (auto-filtered `role='customer'`). Поля: `Id, Email, Name, Phone, IsActive, CreatedAt, UpdatedAt`.

### Credentials management

`POST /api/save-odata-creds.php` (owner only, CSRF):

| `action` | Параметры | Возврат |
|---|---|---|
| `rotate` | — | `{ success, username, api_key }` (plaintext key показывается ОДИН РАЗ) |
| `enable` | — | `{ success }` или 409 если creds не созданы |
| `disable` | — | `{ success }` |

При первом `rotate` auto-enable=true (чтобы новый key сразу работал).

## UI

`/owner.php?tab=integrations` → секция «1С OData»:

- Статус-бейдж: `включено` (green) / `выключено` (red) / `не настроено` (gray)
- Endpoint URL (read-only, монопространственный)
- Логин (read-only)
- `last_used_at` если есть
- Кнопка «Сгенерировать новый ключ» → confirm dialog → POST rotate → amber-banner с plaintext key + «Копировать»
- Кнопка «Включить/Выключить интеграцию» (toggles enabled)
- `<details>` блок «Как подключить 1С Конфигуратор» с пошаговой инструкцией

## CSP / стиль

- Никаких inline-стилей / inline-скриптов. `css/owner-integrations.css` + `js/owner-integrations.js` с nonce.
- Использует существующие классы `.account-section`/`.admin-section-card`, `.owner-workspace-stack/header/kicker/copy`, `.checkout-btn`, `.admin-checkout-btn`, `.recipe-save-msg-success/error`.

## Тестирование

### curl examples

Получить первые 10 заказов:
```bash
curl -u odata_xxx:plaintext_key \
  "https://menu.labus.pro/api/v1/odata/orders.php?\$top=10&\$count=true"
```

Фильтр по статусу + сумме:
```bash
curl -u odata_xxx:plaintext_key \
  "https://menu.labus.pro/api/v1/odata/orders.php?\$filter=Status eq 'paid' and Total gt 1000"
```

Полнотекстовый поиск:
```bash
curl -u odata_xxx:plaintext_key \
  "https://menu.labus.pro/api/v1/odata/menu_items.php?\$filter=substringof('паста', Name)"
```

### 1С Конфигуратор

1. «Общие» → «Внешние источники данных» → «Добавить».
2. Тип источника — OData.
3. URL: `https://menu.labus.pro/api/v1/odata/`
4. Аутентификация: HTTP Basic; имя = `username`, пароль = `api_key`.
5. После подключения 1С автоматически дискаверит 3 сущности: `orders.php`, `menu_items.php`, `customers.php`.

### Verification

1. /owner.php?tab=integrations → «Сгенерировать новый ключ» → confirm → amber-banner с key.
2. Скопировать key. curl с username + key + `$top=1` → JSON envelope, status 200.
3. curl с wrong password → 401, `WWW-Authenticate: Basic realm="CleanMenu OData"`.
4. Toggle «Выключить интеграцию». curl с правильными creds → 401 (enabled=0).
5. Toggle обратно. Curl снова работает. last_used_at обновляется в UI.

## Rollback

Atomic commit. `git revert <sha>`. Таблица `odata_credentials` остаётся в схеме безвредно.

## Что не входит (явно)

- Write-back из 1С в CleanMenu (PATCH/POST/DELETE) — Phase 37.1 если попросят. OData spec позволяет это, но 1С обычно использует read-only для отчётности.
- OR-композиция в `$filter` — Phase 37.2 (требует proper expression-parser).
- `$expand` для join'ов между сущностями — Phase 37.3.
- IP whitelist — Phase 37.4 (BasicAuth + enabled-flag достаточно для MVP).
- Audit log запросов в БД — пока только error_log на failures. Phase 37.5.
- Полная OData v4 (новый JSON-формат с `@odata.context`) — 1С поддерживает v3, поэтому пока в этом нет нужды.
