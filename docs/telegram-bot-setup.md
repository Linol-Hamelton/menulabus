# Telegram Bot Setup

## Implementation Status

- Status: `Implemented`
- Last reviewed: `2026-04-11`
- Verified against code: [telegram-notifications.php](../telegram-notifications.php), [telegram-webhook.php](../telegram-webhook.php), [create_new_order.php](../create_new_order.php), [toggle-available.php](../toggle-available.php), [config.php](../config.php).

## Purpose

Operator-facing reference for the Telegram bot that pushes new-order cards to restaurant staff and lets them `accept`/`reject` orders directly from the chat. Also covers the stop-list alert channel that shares the same bot token.

## What the bot does

| Event | Trigger | Message | Buttons |
|---|---|---|---|
| New order created | [create_new_order.php:212](../create_new_order.php) calls `sendOrderToTelegram()` after a successful insert | HTML card: order id, item list with quantities and line totals, grand total, optional tips line, delivery type + detail, payment method | `✅ Принять`, `❌ Отказать` (inline keyboard) |
| Item moved to stop-list | [toggle-available.php:56](../toggle-available.php) calls `sendTelegramMessage()` when an item is toggled off | `⛔ Стоп-лист: «<name>» снято с продажи` | none |
| Staff pressed Accept | [telegram-webhook.php](../telegram-webhook.php) `callback_query` handler, `accept_{id}` | Original card is edited to `✅ Заказ #{id} принят — готовим`, buttons removed | — |
| Staff pressed Reject | same handler, `reject_{id}` | Original card is edited to `❌ Заказ #{id} отклонён`, buttons removed | — |

Delivery labels rendered in the order card: `🚶 Самовывоз`, `🛵 Доставка`, `🪑 Стол`, `🍸 Бар`. Payment labels: `💵 Наличные`, `💳 Карта`, `⚡ СБП`.

Status transitions triggered by button presses:

- `accept_{id}` → order status `готовим` (only if the order is not already in `завершён`/`отказ`, otherwise a `"Заказ уже обработан"` alert is returned to the clicker).
- `reject_{id}` → order status `отказ`.

Both transitions go through `Database::updateOrderStatus()` and follow the lifecycle described in [order-lifecycle-contract.md](./order-lifecycle-contract.md).

## Configuration

Two pieces of configuration are required — one at the deployment level, one per tenant.

### 1. `TELEGRAM_BOT_TOKEN` constant (deployment-level, `config.php`)

The bot token is stored as a PHP constant in [config.php](../config.php), not in the `settings` table. It is shared across all tenants on the same deployment — a single bot proxies orders for every restaurant, with each tenant's `telegram_chat_id` routing messages to its own chat.

```php
define('TELEGRAM_BOT_TOKEN', '123456789:AA...');
```

If `TELEGRAM_BOT_TOKEN` is undefined or empty, both `sendTelegramMessage()` and `sendOrderToTelegram()` become no-ops — the order still gets created and persisted, nothing breaks, there's just no Telegram notification. This makes the bot opt-in at the deployment level.

### 2. `telegram_chat_id` setting (per-tenant, `settings` table)

JSON-encoded string. Written through the owner/admin panel. Without this setting the order hook silently returns — no message is sent even if the bot token is configured.

The chat id can be:

- A negative integer — a group chat id (recommended for multi-staff kitchens).
- A positive integer — a single user's DM with the bot.
- A channel id — for read-only broadcast use cases.

Get the id by sending any message to `@RawDataBot` from the target chat, or by hitting `https://api.telegram.org/bot{TOKEN}/getUpdates` after posting a message in the chat.

## Bot registration (one-time per deployment)

1. Talk to `@BotFather` in Telegram, create a new bot with `/newbot`, copy the token.
2. Put the token into `config.php` as `TELEGRAM_BOT_TOKEN`.
3. Register the webhook so Telegram knows where to deliver button presses:
   ```
   curl "https://api.telegram.org/bot{TOKEN}/setWebhook?url=https://<domain>/telegram-webhook.php"
   ```
   The response must be `{"ok":true,"result":true,"description":"Webhook was set"}`.
4. Verify registration any time with:
   ```
   curl "https://api.telegram.org/bot{TOKEN}/getWebhookInfo"
   ```
5. Add the bot to the staff chat and promote it to admin (not strictly required, but avoids group-permission edge cases for button callbacks).

For **multi-tenant deployments with custom domains**, all tenants can share one bot and one webhook URL (on the provider domain) if the webhook implementation is modified to route on chat id — today each tenant registers its own webhook under its own `/telegram-webhook.php`, because the webhook does not currently know which tenant the callback belongs to beyond the chat id lookup. Current pattern: one webhook URL per tenant domain.

## Webhook contract

- Endpoint: `POST /telegram-webhook.php`
- Body: Telegram `Update` object (JSON).
- Response: always `{"ok":true}` with HTTP 200 unless the method is wrong (405).
- Only `callback_query` updates are handled. Regular messages and inline queries are ignored — the bot is a one-way staff-notification surface, not a conversational assistant.
- Callback data pattern: `^(accept|reject)_(\d+)$`. Anything that doesn't match is silently dropped.
- The webhook file lives at the project root, so it is **not** affected by the nginx `location ^~ /scripts/ { return 404; }` scope lock.

## Stop-list alerts

When an employee or admin toggles a menu item to unavailable via [toggle-available.php](../toggle-available.php), the endpoint:

1. Flips `menu_items.available` (persisted).
2. Reads the tenant's `telegram_chat_id` from settings.
3. Calls `sendTelegramMessage()` with a plain text "stop-list" notice and **no inline keyboard** — alerts are informational only.

There is no reverse notification when an item is re-enabled; if you need it, add a `$newState === 1` branch mirroring the same call.

## Test flow

1. Register the bot and set the webhook.
2. Open the owner/admin panel on a tenant, set `telegram_chat_id` to your test chat, save.
3. Place a test order through `cart.php` — expect a card in the Telegram chat with two buttons.
4. Press `✅ Принять` — expect the card to be edited (buttons removed) and the order to flip to `готовим` in `employee.php`.
5. Place another order, press `❌ Отказать` — expect status `отказ` in the order list.
6. Open `admin-menu.php`, click "В стоп-лист" on an item — expect a `⛔ Стоп-лист` alert in the chat.
7. Press Accept on an already-processed order — expect a pop-up "Заказ уже обработан" and no status change.

## Common failure modes

| Symptom | Likely cause | Where to look |
|---|---|---|
| No messages arrive at all | `TELEGRAM_BOT_TOKEN` undefined in `config.php`, or `telegram_chat_id` not set for this tenant | config.php; `settings` row `telegram_chat_id` |
| Messages arrive but buttons do nothing | Webhook not registered, or registered under the wrong URL, or `telegram-webhook.php` is behind basic-auth / IP allowlist | `getWebhookInfo`; nginx error log |
| Buttons work in private DM but not in a group | Bot has no permission to see non-command messages in the group. Promote it to admin, or talk to BotFather → `/setprivacy` → `Disable` | BotFather settings |
| Stop-list alerts flood the chat | Someone is bulk-toggling items from `admin-menu.php` — each toggle is one message. There is no batching | toggle-available.php caller |
| New order card shows `payment_method` value literally (e.g. `cash`) instead of a label | A new provider was added to the enum without a matching entry in `$paymentLabels` in telegram-notifications.php:67 | telegram-notifications.php |
| "Заказ уже обработан" on first press | Race condition — another client or the web UI already moved the order out of `принят`/`приём` | Order history; check who touched the order first |

## Related docs

- [payments-integration.md](./payments-integration.md) — payment methods rendered in the order card (`cash`, `online`, `sbp`).
- [order-lifecycle-contract.md](./order-lifecycle-contract.md) — status transitions triggered by accept/reject presses.
