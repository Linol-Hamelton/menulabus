# Меркурий / ВСД (Phase 38, manual MVP)

Phase 38 закрывает legal blocker для ресторанов с мясной/рыбной/молочной продукцией по 243-ФЗ. **Manual mode**: вручную регистрируем ВСД (ветеринарные сопроводительные документы) от поставщиков и гасим их при приёмке товара. Auto-mode через Vetis API — Phase 38.1 (требует ЭЦП клиента, нет в MVP).

## Архитектура

### Таблицы (миграция: `sql/mercury-migration.sql`)

| Таблица / поле | Назначение |
|---|---|
| `vsd_records` | ingredient_id, vsd_number, vsd_date, supplier_inn/name, quantity, unit, status `pending\|accepted\|rejected`, accepted_at/by, notes |
| `ingredients.requires_vsd` (TINYINT NOT NULL DEFAULT 0) | toggle «требует ВСД» для каждого ингредиента — добавлен через `INFORMATION_SCHEMA + PREPARE` guard |

### Связь со складом

При гашении (`status: pending → accepted`) автоматически:
- `ingredients.stock_qty += vsd_records.quantity`
- запись в `stock_movements` с `reason='receipt'` и `note='ВСД №...'`
- `accepted_at`/`accepted_by` фиксируются

Отклонение (`status: pending → rejected`) — товар на склад **не попадает**, причина дописывается в `notes` с префиксом `Отклонено: ...`.

## API

`POST /api/save-vsd.php` (role: admin/owner, CSRF):

| `action` | Параметры | Возврат |
|---|---|---|
| `create` | `ingredient_id`, `vsd_number`, `vsd_date` (YYYY-MM-DD), `supplier_inn`, `supplier_name`, `quantity`, `unit`, `notes` | `{ success, id }` |
| `update` | те же + `id` | `{ success, id }` |
| `accept` | `id`, `apply_to_stock?: bool` (default true) | `{ success }`. 409 если запись не в pending |
| `reject` | `id`, `reason?` | `{ success }` |
| `delete` | `id` | `{ success }`. 409 если запись уже не pending |
| `toggle_requires` | `ingredient_id`, `requires: bool` | `{ success }` — флаг `ingredients.requires_vsd` |

## UI

### `/admin/vsd.php` (новая страница)

- `admin-section-card` + `admin-pane-header` (kicker «Меркурий» / title / caption)
- 3 summary-card: ожидает гашения (warn-tone) / гашено / отклонено
- Filter-bar: статус, ингредиент (★ — required-vsd), диапазон дат
- Таблица: дата, ВСД №, ингредиент, поставщик, кол-во, статус-бейдж (`pending`=жёлтый / `accepted`=зелёный / `rejected`=красный), действия
- На `pending` строках: кнопки «Гашение» + «Отклонить»
- На `accepted` / `rejected`: показывает кто и когда обработал
- 2 модала на основе `.design-modal`: создание (полный набор полей) + отклонение (только причина)

### Расширение `/admin/inventory.php`

В edit-modal ингредиента добавлен toggle «Меркурий: требует ВСД (мясо/рыба/молочка по 243-ФЗ)». Сохраняется через дополнительный API call после save_ingredient. Звёздочка ★ в фильтре /admin/vsd.php помечает ингредиенты с `requires_vsd=1`.

### Nav

В `account-header.php` для `owner` + `admin` добавлен пункт «ВСД» (рядом со «Склад»).

## CSP / стиль

- Никаких inline-стилей. Все стили — `/css/admin-vsd.css` + переиспользует `admin-inventory.css` (table, summary, edit-form) + `admin-design-modals.css` (modal chrome).
- Никаких inline-скриптов. `/js/admin-vsd.js` с `nonce` атрибутом.
- Использует существующие классы: `.admin-section-card`, `.admin-pane-header`, `.checkout-btn`, `.admin-checkout-btn`+`.cancel`, `.inv-edit-form`, `.inv-edit-field`, `.recipe-save-msg-success/error`, `.modal-card/head/body/foot`.

## Role-gating

- Страница `/admin/vsd.php`: `$required_role = 'admin'` (admin + owner). За billing-фичей `inventory`.
- API endpoint: те же роли.
- Nav link: только owner/admin видят.
- Сотрудники (`employee`) не имеют доступа.

## Verification

1. Включить «требует ВСД» на ингредиенте «Говядина» (через /admin/inventory.php → edit). После save — звёздочка ★ появляется в фильтре /admin/vsd.php.
2. Зайти на `/admin/vsd.php` → «+ Принять ВСД» → заполнить (ингредиент Говядина, №ABC123, дата сегодня, 5 кг, поставщик ООО Х, ИНН 7707083893) → Сохранить. Появляется row со статусом `pending`.
3. Нажать «Гашение» → confirm. Запись становится `accepted`. На странице /admin/inventory.php у Говядины stock_qty += 5.
4. Создать ещё один ВСД → «Отклонить» с причиной «брак партии». Status `rejected`, на склад не попадает.
5. Filter-bar по `accepted` показывает только гашёные записи.

## Rollback

Atomic commit. Откатить: `git revert <sha>`. Таблицы остаются (idempotent `CREATE TABLE IF NOT EXISTS`); старый код их не читает. `ingredients.requires_vsd` остаётся в схеме безвредно.

## Что не входит (явно)

- Авто-интеграция с Vetis REST API (Меркурий API) — требует ЭЦП клиента + windows-service / прокси для подписи запросов. Phase 38.1.
- Bulk-import ВСД из XML/CSV выгрузок Меркурия. Phase 38.2 если попросят.
- Связь ВСД с конкретным `stock_movements.id` для traceability — пока только текст в `note`. Phase 38.2.
