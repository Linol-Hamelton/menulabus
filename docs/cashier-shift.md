# Кассовая смена + Refund / чек коррекции (Phase 35)

Phase 35 закрывает cash management loop: кассир открывает смену, принимает заказы (уже было), может оформить возврат (новое) и в конце смены закрывает её с Z-отчётом + инкассацией. Все возвраты опционально пробиваются через АТОЛ Онлайн как чек коррекции (54-ФЗ).

## Архитектура

### Таблицы (миграция: `sql/cashier-shifts-migration.sql`)

| Таблица | Назначение |
|---|---|
| `cashier_shifts` | один ряд на смену кассира: `cashier_id`, `opened_at`, `closed_at`, `opening_cash`, `closing_cash`, `encashment_total`, `notes` |
| `shift_encashments` | история инкассаций внутри смены (FK на `cashier_shifts.id` с `ON DELETE CASCADE`) |
| `order_refunds` | возвраты по заказам: `order_id`, `amount`, `is_partial`, `reason`, `fiscal_receipt_uuid/status/url`, `shift_id` |
| `orders.shift_id` (новая nullable колонка) | привязка к смене; заполняется автоматически при создании заказа, если есть открытая смена |

Колонка `orders.shift_id` добавляется через `INFORMATION_SCHEMA + PREPARE` (idempotent), без FK — для дешёвой миграции на больших таблицах. Целостность поддерживается на уровне db.php.

### Связь со заказами

При вызове `Database::createOrder()` мы вызываем `getAnyOpenShift()` и записываем `shift_id` в новый заказ. Это работает и для customer-cart (когда заказ сам приходит из кафе): любая открытая смена «ловит» новые заказы. Если открытой смены нет — `shift_id` остаётся `NULL`.

### X / Z отчёты

Виртуальные — считаются по `orders` + `order_refunds` за период смены, без отдельной таблицы. `Database::getShiftReport($shiftId)` возвращает:
- `orders_count`, `gross_total`, `tips_total`
- `by_method[]` — split по `payment_method`
- `refund_total`, `refunds_count`
- `opening_cash`, `encashment_total`
- `expected_cash` = `opening_cash` + `cash_sales` − `encashment_total` − `refund_total`

X-отчёт — снимок открытой смены, отображается в dock (поверх tabs). Z-отчёт — тот же отчёт в момент закрытия + поле «факт наличных».

## API

### `POST /api/save-cashier-shift.php`

| `action` | Кто может | Параметры | Возврат |
|---|---|---|---|
| `open` | employee/admin/owner | `opening_cash` (≥0), `notes?` | `{ success, shift_id }` |
| `close` | свой кассир ИЛИ admin/owner | `shift_id`, `closing_cash`, `notes?` | `{ success, report }` |
| `encash` | свой кассир ИЛИ admin/owner | `shift_id`, `amount` (>0), `reason` (`bank_deposit\|safe\|other`) | `{ success, report }` |
| `status` | любая authed роль на странице | — | `{ success, shift, report }` (или `shift:null`) |

Конфликты: 409 на `shift_already_open`, `shift_already_closed`, `shift_closed`. 403 на `not_your_shift`.

### `POST /api/save-refund.php`

```jsonc
{
  "action": "create",
  "order_id": 123,
  "mode": "full" | "partial",
  "amount": 250.00,            // обязателен только при partial
  "reason": "брак",            // опционально
  "csrf_token": "..."
}
```

Возвращает `{ success, refund_id, amount, is_partial, remaining, fiscal: { uuid, status } }`. `fiscal.status` — `wait | done | fail | null` (null если фискализация выключена).

Логика:
1. Заказ должен быть `payment_status = 'paid'`.
2. `available = total − Σ(уже возвращено)`. Запрос больше — отклоняем (409).
3. Привязываем возврат к `getAnyOpenShift()` (если есть).
4. Если оставшаяся сумма после возврата = 0 → `orders.payment_status` → `refunded`.
5. Best-effort: при `fiscal_provider='atol'` вызываем `AtolOnline::sendCorrectionReceipt()` (endpoint `/sell_refund`) и сохраняем `uuid + status` в `order_refunds`. Ошибки фискализации не блокируют операцию; пишутся в error_log + `fiscal_receipt_status='fail'`.

## UI

### Dock (`partials/employee_shift_dock.php`)

Sticky `<section class="shift-dock">` над меню табов `employee.php`. Открытая смена: dot + «Смена открыта · Кассир · с DD.MM HH:MM» + метрики (размен / выручка / заказов / возвраты) + кнопки «Инкассация» и «Закрыть смену». Закрытая: «Смена закрыта» + caption + кнопка «Открыть смену».

### Модалы (всё через `.design-modal`)

- `#shiftOpenModal` — поле размена + примечание.
- `#shiftCloseModal` — Z-отчёт (read-only) + факт наличных (предзаполнен `expected_cash`) + примечание.
- `#shiftEncashModal` — сумма + причина (`bank_deposit / safe / other`).
- `#orderRefundModal` — radio `full/partial` + опциональная сумма + причина.

### Триггер «Возврат»

`employee-refund.js` слушает клик на любом элементе `.js-refund-trigger` с атрибутами `data-order-id` + `data-order-total`. Чтобы добавить кнопку «Возврат» на карточку заказа в любом месте — достаточно дать ей этот класс и атрибуты. (Phase 35.1 — добавить кнопку в карточку оплаченного заказа.)

## CSP / стиль

- Никаких inline-стилей. Всё в `/css/employee-shift.css` + переиспользует `admin-design-modals.css` chrome.
- Никаких inline-скриптов. Два файла с `nonce` атрибутом: `js/employee-shift.js` и `js/employee-refund.js`.
- Использует существующие классы: `.checkout-btn`, `.admin-checkout-btn` + `cancel`, `.recipe-save-msg-success/error`, `.modal-card/head/body/foot`, `.inv-edit-form` / `.inv-edit-field`.

## Verification

1. Откройте `/employee.php`, нажмите «Открыть смену» с размером 5000 ₽. Dock переключается на статус «открыто».
2. Сделайте тестовый заказ → проверить `SELECT shift_id FROM orders ORDER BY id DESC LIMIT 1;` — должен совпасть с открытой сменой.
3. Нажмите «Инкассация», введите 1000 ₽ → запись в `shift_encashments` + `cashier_shifts.encashment_total += 1000`.
4. Откройте оплаченный заказ → кнопка «Возврат» → «Полный» → `order_refunds.amount = total`, `orders.payment_status='refunded'`. Если АТОЛ настроен — uuid сохранён, дальше worker заполнит url.
5. «Закрыть смену» → Z-отчёт показывает все метрики, факт ≈ expected_cash. После — dock переходит в «Смена закрыта».

## Rollback

Atomic commit. Откатить: `git revert <sha>`. Таблицы остаются (idempotent `CREATE TABLE IF NOT EXISTS`); старый код их не читает, новые заказы → `shift_id = NULL` (колонка остаётся в схеме безвредно).

## Что не входит (явно)

- Возврат для cash-only без чека коррекции (УСН без НДС) — отдельный edge case.
- Hardware-открытие денежного ящика (Phase 35.1 если нужно).
- Перенос refund-кнопки в каждую карточку оплаченного заказа — Phase 35.1 (триггер `.js-refund-trigger` уже слушает делегировано).
