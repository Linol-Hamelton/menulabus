# Aggregator integration (Phase 36)

Yandex.Еда + Delivery Club inbound webhook + outbound status push. Закрывает revenue-channel blocker — для большинства ресторанов 30-50% выручки приходит через агрегаторов.

## Архитектура

### Таблицы (`sql/aggregator-migration.sql`)

| Таблица / поле | Назначение |
|---|---|
| `aggregator_settings` | provider UNIQUE, api_key, webhook_secret, enabled, last_webhook_at, last_push_at |
| `orders.aggregator_source` | `'yandex_eda' \| 'delivery_club' \| NULL` |
| `orders.aggregator_order_id` | внешний ID заказа партнёра (UNIQUE per provider) |
| `orders.aggregator_status` | `'new' / 'pushed' / 'delivered_pushed' / 'cancelled_pushed'` — bookkeeping для сравнения с partner side |
| `orders.aggregator_payload` (JSON) | оригинальный normalised payload (для аудита) |
| `menu_items.aggregator_yandex_id` | внешний product_id для маппинга |
| `menu_items.aggregator_dc_id` | внешний sku для DC |

INFORMATION_SCHEMA guards для idempotent миграции.

### Inbound flow

```
Partner POST → /api/aggregator/webhook.php?provider=yandex_eda
  ├─ Headers: X-YandexEda-Signature: hex(HMAC-SHA256(body, secret))
  ├─ Body: provider's JSON schema (см. ниже)
  ↓
[webhook.php] verify HMAC → 401 if mismatch
  ↓
[adapter::normalize($payload, $db)] → common shape
  {external_id, total, items[{id?, name, quantity, price}], customer_name?, customer_phone?, delivery_address?}
  ├─ items[].id заполняется через findMenuItemByAggregatorId(provider, externalProductId)
  ├─ Unmapped items: name=«Неопознанное блюдо», id=NULL
  ↓
[createOrderFromAggregator] → orders row
  ├─ Idempotent: дубликат external_id вернёт existing order_id (200, no insert)
  ├─ user_id=NULL, delivery_type='delivery', payment_status='paid'
  ├─ Привязка к открытой смене (getAnyOpenShift)
  ├─ aggregator_status='new'
  ↓
last_webhook_at touched → 200 OK
```

### Outbound flow

```
[cron every minute] scripts/aggregator-status-sync.php
  ↓
[getOrdersPendingAggregatorPush]
  WHERE aggregator_source IS NOT NULL
    AND aggregator_status NOT IN ('pushed', 'delivered_pushed', 'cancelled_pushed', 'final')
  ↓
[for each] adapter::mapStatusOutbound($order.status)
  internal status (Приём/Готовим/Доставляем/Завершён/Отказ)
  → provider's code (NEW/COOKING/IN_DELIVERY/DELIVERED/CANCELLED для Я.Еды,
                     accepted/preparing/shipping/delivered/cancelled для DC)
  ↓
[curl PATCH] adapter::statusPushUrl($externalId)
  Authorization: Bearer <api_key>
  Body: {"status":"..."}
  ↓
HTTP 2xx → setOrderAggregatorStatus(pushed | delivered_pushed | cancelled_pushed)
HTTP 4xx/5xx → error_log + skip (retry next tick)
```

## API

### `POST /api/aggregator/webhook.php?provider={yandex_eda|delivery_club}` (inbound)

Headers:
- `X-YandexEda-Signature` или `X-DC-Signature`: hex HMAC-SHA256(raw body, secret)
- `Content-Type: application/json`

Body для Яндекс.Еды:
```json
{
  "order_id": "string",
  "total": 1234.50,
  "items": [
    {"product_id": "string", "name": "...", "quantity": 1, "price": 100.00}
  ],
  "customer": {"name": "Иван", "phone": "+7..."},
  "delivery_address": "Москва, ..."
}
```

Body для Delivery Club:
```json
{
  "id": "string",
  "total_amount": 1234.50,
  "items": [
    {"sku": "string", "title": "...", "qty": 1, "amount": 100.00}
  ],
  "client": {"first_name": "Иван", "phone": "+7..."},
  "address": "Москва, ..."
}
```

Возвраты:
- `200 { ok: true, order_id, external_id }` — заказ создан (или вернулся уже существующий)
- `400 invalid_json / missing_external_id / unknown_provider` — bad payload
- `401 invalid_signature` — HMAC не совпал
- `403 provider_disabled` — настройки выключены (enabled=0)
- `503 webhook_secret_missing` — нет secret в settings
- `500 create_failed / internal_error`

### `POST /api/save-aggregator.php` (owner-only, CSRF)

| action | payload | возврат |
|---|---|---|
| `save_settings` | provider, api_key, webhook_secret?, enabled | `{ success, settings: {enabled, webhook_secret} }` — secret auto-generates если пуст |
| `rotate_secret` | provider | `{ success, webhook_secret }` — новый secret, старый сразу мёртв |
| `save_mapping` | provider, mappings: [{menu_item_id, external_id}, ...] | `{ success, saved: N }` |

## UI

### `/owner.php?tab=integrations`

Над 1С-секцией добавлены 2 aggregator-карточки (Яндекс.Еда + Delivery Club), каждая:
- Status badge (включено/выключено/не настроено)
- Webhook URL (read-only, copy-friendly)
- Signature header (read-only)
- last_webhook_at / last_push_at (если есть)
- Форма: API ключ + webhook secret + toggle «Принимать и пушить» + «Сохранить»
- Collapsible `<details>` «Маппинг товаров» — table со всеми menu_items + полем «Внешний ID партнёра» + кнопкой «Сохранить маппинг»

### `/employee.php` (карточки заказов)

В header заказа сразу после `#ID` появляется бэйдж:
- **Я.Еда** (жёлтый) для `aggregator_source='yandex_eda'`
- **DC** (розовый) для `aggregator_source='delivery_club'`

## Cron

Добавить в crontab под `labus_pro_usr`:
```cron
* * * * * /usr/bin/php /var/www/labus_pro_usr/data/www/menu.labus.pro/scripts/aggregator-status-sync.php >/dev/null 2>&1
```

Соблюдать pattern из `reference_prod_environment.md`: не делать `echo ... | crontab -u user -`, а пайпить `crontab -l | awk ... | crontab -` (см. deployment-workflow Deploy Pitfalls).

## Testing (mock webhook)

```bash
# 1. Сгенерировать тестовый payload + HMAC
SECRET="ваш_webhook_secret_из_UI"
PAYLOAD='{"order_id":"TEST-001","total":1500,"items":[{"product_id":"yandex_pizza_1","name":"Маргарита","quantity":1,"price":1500}],"customer":{"name":"Test","phone":"+79000000000"},"delivery_address":"Москва"}'
SIG=$(printf '%s' "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" -hex | sed 's/^.* //')

# 2. POST на webhook
curl -X POST \
  -H "Content-Type: application/json" \
  -H "X-YandexEda-Signature: $SIG" \
  --data "$PAYLOAD" \
  "https://menu.labus.pro/api/aggregator/webhook.php?provider=yandex_eda"
# → { "ok": true, "order_id": N, "external_id": "TEST-001" }

# 3. Повторный POST (idempotency)
# → { "ok": true, "order_id": N (тот же), "external_id": "TEST-001" }

# 4. Wrong signature
curl -X POST \
  -H "X-YandexEda-Signature: deadbeef" \
  --data "$PAYLOAD" \
  "https://menu.labus.pro/api/aggregator/webhook.php?provider=yandex_eda"
# → 401 invalid_signature
```

## CSP / стиль

- Никаких inline-стилей / inline-скриптов. CSS — `css/owner-integrations.css` (extended), JS — `js/owner-integrations.js` (extended) с nonce.
- Использует существующие классы `.account-section`/`.admin-section-card`, `.checkout-btn`, `.admin-checkout-btn`, `.inv-edit-form`/`.inv-edit-field`, `.recipe-save-msg-*`.

## Verification

1. /owner.php?tab=integrations → секция «Яндекс.Еда»: «не настроено» → ввести API key + Save (secret сгенерируется) → reload → «включено».
2. Скопировать webhook secret. curl (см. Testing) → 200 + order_id. Зайти на /employee.php — карточка с бэйджем «Я.Еда».
3. Повторно POST с тем же external_id → возврат того же order_id (idempotency).
4. POST с wrong signature → 401.
5. Открыть в /admin/menu.php блюдо «Пицца Маргарита» — добавить через UI mapping `yandex_pizza_1`. Повторный webhook → name/price подставится из menu_items, items[].id заполнен.

## Что не входит (явно)

- **Реальные sandbox credentials** от Яндекс.Еды и Delivery Club — требуют partner-agreement через partner-channel. MVP протестирован через self-signed mock webhook.
- Refund/cancellation flow от агрегатора → cancellation в нашей системе (только наш cancel пушится наружу).
- Аналитика по агрегаторам (revenue split by source, рейтинги) — Phase 36.1 в analytics-v2.
- Realtime push (websocket) от агрегатора — Phase 36.2 (текущий — polling).
- Расширение `mapStatusOutbound` для частичных статусов («partially_delivered», «delayed»).
- Множественные точки (location-aware webhook routing) — текущий MVP single-location.
- Sentry/audit log для failed webhooks — пока только `error_log`.
