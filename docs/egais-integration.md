# ЕГАИС (Phase 39, manual MVP)

Phase 39 закрывает legal blocker по 171-ФЗ для ресторанов с алкоголем. **Manual mode**: вручную регистрируем ТТН-накладные от поставщиков, актами вскрытия фиксируем налив бутылок, ежемесячный отчёт об остатках. Auto-mode через УТМ / Контур.ЕГАИС API — Phase 39.1.

## Архитектура

### Таблицы (`sql/egais-migration.sql`)

| Таблица / поле | Назначение |
|---|---|
| `alc_invoices` | ТТН-headers: ttn_number, ttn_date, supplier_inn/name, total_amount, status `pending/accepted/rejected`, accepted_at/by, notes |
| `alc_invoice_items` | позиции ТТН (FK→alc_invoices CASCADE): ingredient_id (nullable mapping), alc_code (АСНА), name, quantity, unit, price |
| `alc_openings` | акты вскрытия: ingredient_id, bottle_volume_ml, opened_at/by, shift_id (FK→cashier_shifts если в смене), notes |
| `ingredients.is_alcohol` (TINYINT 0/1) | toggle «является алкоголем» |
| `ingredients.alc_code` (VARCHAR 64) | АСНА код продукции |

INFORMATION_SCHEMA + PREPARE guards — для idempotent миграции на MySQL <8.0.29.

### Связь со складом

- При `acceptAlcInvoice($id, $userId)`: status → accepted + по каждой позиции с `ingredient_id != NULL` пишется `stock_movements` row reason=receipt с note `'ТТН №X (АСНА Y)'` и прибавляется к `ingredients.stock_qty`.
- При `saveAlcOpening($ingredientId, $volumeMl, $userId, $shiftId, $notes)`: row в `alc_openings`. Stock-decrement из бутылки на гран-mll основе **не выполняется** (объём бутылки не = stock unit; зависит от рецептов коктейлей). Это фиксация факта вскрытия для отчёта — потерянный объём вычисляется отдельно при бутылочной инвентаризации.

## API

`POST /api/save-egais.php` (CSRF):

| `action` | Кто | Параметры | Возврат |
|---|---|---|---|
| `create_invoice` | admin/owner | `ttn_number`, `ttn_date` (YYYY-MM-DD), `supplier_inn` (10/12 digits), `supplier_name?`, `total_amount?` (auto-computes if 0), `items[]` (ingredient_id?, alc_code, quantity, unit?, price_per_unit?), `notes?` | `{ success, id }` |
| `accept_invoice` | admin/owner | `id`, `apply_to_stock?: bool` | `{ success }`. 409 если не pending |
| `reject_invoice` | admin/owner | `id`, `reason?` | `{ success }` |
| `delete_invoice` | admin/owner | `id` | `{ success }`. 409 если уже не pending |
| `open_bottle` | **employee+** | `ingredient_id`, `bottle_volume_ml` (default 750), `notes?` | `{ success, id, shift_id }` |
| `toggle_alcohol` | admin/owner | `ingredient_id`, `is_alcohol: bool`, `alc_code?` | `{ success }` |

## UI

### `/admin/egais.php` (3 tabs)

- **Накладные** — таблица ТТН со статус-badge (pending=amber/accepted=green/rejected=red) + кнопки управления. Модал создания: header (ТТН №, дата, ИНН/название/notes) + dynamic items-list (можно добавить N позиций; каждая позиция = product mapping + АСНА + кол-во + цена; «×» удаляет строку).
- **Акты вскрытия** — список `alc_openings` (когда / продукт / АСНА / объём / привязка к смене / кто вскрыл).
- **Отчёт об остатках** — за диапазон дат: каждый алкогольный ингредиент → принято кг/л за период по ТТН (только accepted) + кол-во вскрытий из `alc_openings` + текущий `stock_qty`.

### Employee shift dock (`partials/employee_shift_dock.php`)

При открытой смене — новая кнопка **«Вскрыть бутылку»** рядом с «Инкассация» / «Закрыть смену». Открывает modal с выбором продукта из `listAlcoholIngredients()` (показывает `name · АСНА`) + объёмом (default 750мл) + notes. После submit — `alc_openings.shift_id` автоматически = открытой смене текущего кассира.

### `/admin/inventory.php` (edit-modal)

Toggle «Алкоголь (171-ФЗ)» + поле «АСНА код» (показывается только при включённом toggle). Сохраняется через дополнительный POST `/api/save-egais.php` action=`toggle_alcohol` после основного save_ingredient.

### Nav

`account-header.php` → «ЕГАИС» для owner/admin (рядом со «ВСД»).

## CSP / стиль

- Никаких inline-стилей / inline-скриптов. `css/admin-egais.css` (tab-strip + items-list grid) + `js/admin-egais.js` с nonce. `js/employee-shift.js` patch для bottle-open handler. `js/admin-inventory.js` patch для wiring is_alcohol/alc_code.
- Использует существующие классы: `.admin-section-card`, `.admin-pane-header`, `.design-modal`, `.checkout-btn`, `.admin-checkout-btn`+`.cancel`, `.inv-edit-form`/`.inv-edit-field`, `.inv-table`/`.vsd-status-badge`, `.recipe-save-msg-success/error`.
- Embedded JSON для алко-ингредиентов в admin/egais.php: `<script type="application/json" id="egaisAlcIngredients" nonce>` — статический data-payload, не выполняется как JS, CSP-compliant.

## Polish (Phase 39.1, v3.6.1)

Bottle-open modal изначально получал inline-layout (Phase 35.1 polish был scoped только на 4 ID; `#bottleOpenModal` забыл добавить). Расширил селекторы в `css/employee-shift.css` — теперь форма consistent.

## Verification

1. Включить «Алкоголь (171-ФЗ)» на ингредиенте «Виски Jameson» в /admin/inventory.php → edit. Указать АСНА код. Сохранить.
2. Зайти на `/admin/egais.php?tab=invoices` → «+ Принять ТТН». Заполнить: ТТН №ALC-TEST-001, дата сегодня, ИНН 7707083893, поставщик «Алкоопт», 1 позиция (продукт = Виски Jameson, АСНА авто-fill, кол-во 6 бут, цена 1200). Submit → row pending.
3. «Принять» (confirm). Status → accepted. Stock_qty Виски Jameson += 6.
4. Открыть смену в `/employee.php`. В dock появится «Вскрыть бутылку». Нажать → выбрать Виски Jameson, объём 700, submit. Запись в `alc_openings`.
5. Tab «Акты вскрытия» в /admin/egais.php → видно запись с привязкой к смене.
6. Tab «Отчёт об остатках» за этот месяц → Виски Jameson: принято 6 / вскрыто 1 / остаток 6.

## Rollback

Atomic commit. `git revert <sha>`. Таблицы остаются (idempotent CREATE), старый код их не читает. `ingredients.is_alcohol`/`alc_code` безвредны без UI.

## Что не входит (явно)

- Авто-интеграция с УТМ (требует Windows-сервиса локально на ПК клиента) или Контур.ЕГАИС API — Phase 39.1.
- Автоматический stock-decrement из вскрытой бутылки (зависит от рецептов коктейлей; пока вскрытие = факт-фиксация для отчёта).
- XML-выгрузки в формате ФСРАР — Phase 39.2 если попросят.
- Сканер QR-марок алкоголя — Phase 39.3.
