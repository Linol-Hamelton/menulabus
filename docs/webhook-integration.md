# Outgoing Webhooks

## Implementation Status

- Status: `Implemented`
- Last reviewed: `2026-04-23`
- Current state:
  - **Storage:** [sql/webhooks-migration.sql](../sql/webhooks-migration.sql) — `outgoing_webhooks` (subscriptions) + `webhook_deliveries` (attempt log).
  - **DB layer:** [db.php](../db.php) — `listWebhooks`, `getActiveWebhooksForEvent`, `createWebhook`, `updateWebhook`, `deleteWebhook`, `enqueueWebhookDelivery`, `claimDueWebhookDeliveries`, `markWebhookDelivered`, `markWebhookFailed`, `getRecentWebhookDeliveries`.
  - **Dispatcher:** [lib/WebhookDispatcher.php](../lib/WebhookDispatcher.php) — `dispatch()` (enqueue per active subscription), `send()` (HTTP POST with HMAC-SHA256), `generateSecret()`.
  - **Worker:** [scripts/webhook-worker.php](../scripts/webhook-worker.php) — atomic claim via `SELECT ... FOR UPDATE`, runs once or in a loop.
  - **Admin UI:** [admin-webhooks.php](../admin-webhooks.php) — list, create, toggle active, rotate secret, delete, view delivery history. CRUD endpoint: [api/save-webhook.php](../api/save-webhook.php).
  - **Hook points (current):** `order.created` ([create_new_order.php](../create_new_order.php)), `reservation.created` ([api/v1/reservations/create.php](../api/v1/reservations/create.php), [create_reservation.php](../create_reservation.php)), `reservation.confirmed` / `reservation.seated` / `reservation.cancelled` / `reservation.no_show` ([update_reservation_status.php](../update_reservation_status.php)).

## Purpose

Stop bolting third-party integrations directly into the order/reservation code paths. Each new sink (CRM push, analytics ingest, kitchen printer relay) used to mean a new `try { ... } catch { error_log }` block in the hot path; that pattern doesn't scale and silently breaks under load.

The webhook hub does three things:

1. Decouples integrations: the order-create code knows nothing about who is listening.
2. Survives consumer flakiness: a 500 from the consumer doesn't surface as a user-facing error; the delivery is retried with backoff and dropped after 5 failures.
3. Gives operators a paper trail: every attempt is in `webhook_deliveries` with status, response code, and an excerpt of the body.

## Data Model

### `outgoing_webhooks` — subscriptions

| Column        | Type                  | Notes                                                      |
|---------------|-----------------------|------------------------------------------------------------|
| `id`          | `INT UNSIGNED AUTO_INCREMENT` | primary key                                       |
| `event_type`  | `VARCHAR(64)`         | dotted key, e.g. `order.created` (see catalogue below)     |
| `target_url`  | `VARCHAR(512)`        | http(s) endpoint of the consumer                           |
| `secret`      | `VARCHAR(128)`        | opaque HMAC key — shown once on create / rotate            |
| `active`      | `TINYINT`             | soft on/off; preserved across status flips                 |
| `description` | `VARCHAR(255) NULL`   | free-form note for operators                               |
| `created_at` / `updated_at` | `DATETIME` | timestamps                                            |

Index `idx_outgoing_webhooks_event_active (event_type, active)` makes the per-event lookup at dispatch time a single index hit.

### `webhook_deliveries` — attempt log

| Column            | Type                  | Notes                                                          |
|-------------------|-----------------------|----------------------------------------------------------------|
| `id`              | `BIGINT UNSIGNED AUTO_INCREMENT` | primary key                                         |
| `webhook_id`      | `INT UNSIGNED`        | FK → `outgoing_webhooks(id) ON DELETE CASCADE`                |
| `event_type`      | `VARCHAR(64)`         | denormalized for searching when a subscription is later edited |
| `payload_json`    | `JSON`                | full envelope as sent (frozen — survives subscription edits)  |
| `status`          | `VARCHAR(16)`         | `queued`, `sending`, `delivered`, `failed`, `dropped`         |
| `response_code`   | `SMALLINT NULL`       | HTTP status from the consumer                                  |
| `response_excerpt`| `VARCHAR(2048) NULL`  | first 2000 chars of the response body or curl error            |
| `attempts`        | `TINYINT`             | incremented on every `send()` try                              |
| `next_retry_at`   | `DATETIME NULL`       | scheduled retry; `NULL` once delivered or dropped              |
| `created_at`      | `DATETIME`            | enqueue time                                                   |
| `delivered_at`    | `DATETIME NULL`       | success time                                                   |

Index `idx_webhook_deliveries_status_retry (status, next_retry_at)` is what the worker hits on every poll.

## Event Catalogue

Initial set (see [lib/WebhookDispatcher.php](../lib/WebhookDispatcher.php) `dispatch()` call sites for the source of truth):

| Event                    | Fires from                                                                                              | Payload `data` shape                                  |
|--------------------------|---------------------------------------------------------------------------------------------------------|--------------------------------------------------------|
| `order.created`          | [create_new_order.php](../create_new_order.php) after successful insert                                 | full row from `Database::getOrderById()`              |
| `reservation.created`    | [api/v1/reservations/create.php](../api/v1/reservations/create.php) and [create_reservation.php](../create_reservation.php) | full row from `Database::getReservationById()` |
| `reservation.confirmed`  | [update_reservation_status.php](../update_reservation_status.php) after staff/Telegram action           | full reservation row                                  |
| `reservation.seated`     | same as above                                                                                            | full reservation row                                  |
| `reservation.cancelled`  | same as above                                                                                            | full reservation row                                  |
| `reservation.no_show`    | same as above                                                                                            | full reservation row                                  |

Adding a new event is a one-liner:

```php
WebhookDispatcher::dispatch('payment.received', $paymentRow, $db);
```

The new event will not error if no subscription matches — it just enqueues zero rows.

## Wire Format

Every delivery is a `POST` with `Content-Type: application/json; charset=utf-8`. The body is a JSON envelope:

```json
{
  "event": "reservation.created",
  "created_at": "2026-04-23T18:42:11+00:00",
  "data": {
    "id": 17,
    "table_label": "T3",
    "user_id": 42,
    "guests_count": 4,
    "starts_at": "2026-04-25 19:00:00",
    "ends_at":   "2026-04-25 21:00:00",
    "status": "pending",
    "...": "..."
  }
}
```

Headers:

| Header                  | Value                                                                                          |
|-------------------------|-------------------------------------------------------------------------------------------------|
| `X-Webhook-Event`       | `reservation.created` (same as `event` in the body — convenient for routing without parsing)   |
| `X-Webhook-Timestamp`   | unix epoch seconds at send time                                                                 |
| `X-Webhook-Signature`   | `v1=` + hex of `HMAC_SHA256(secret, "{timestamp}.{raw_body}")`                                 |
| `X-Webhook-Delivery`    | the `webhook_deliveries.id` — useful for idempotency / debugging                                |

## HMAC Verification (consumer side)

The consumer must reject any request whose signature does not match. Reference recipe in PHP:

```php
$timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$rawBody   = file_get_contents('php://input');

if (!preg_match('/^v1=([a-f0-9]{64})$/', $signature, $m)) {
    http_response_code(400); exit;
}
if (abs(time() - (int)$timestamp) > 300) {
    http_response_code(400); exit;  // stale, possibly replayed
}

$expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $sharedSecret);
if (!hash_equals($expected, $m[1])) {
    http_response_code(401); exit;
}

// Safe to parse json_decode($rawBody) at this point.
http_response_code(200); echo 'ok';
```

Same recipe in Node:

```js
const crypto = require('crypto');
const [, sig] = (req.headers['x-webhook-signature'] || '').split('=');
const ts = req.headers['x-webhook-timestamp'] || '';
const expected = crypto.createHmac('sha256', sharedSecret)
    .update(`${ts}.${req.rawBody}`)
    .digest('hex');
if (!sig || !crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expected))) {
    return res.status(401).end();
}
```

## Retry Policy

- A delivery is considered successful on any 2xx response.
- On non-2xx or transport error, `attempts` increments and `next_retry_at` is rescheduled with backoff: `60s, 300s, 1800s, 7200s`.
- After the 5th failed attempt the row moves to `status='dropped'` and the worker stops touching it.
- The HTTP timeout per attempt is 5 seconds (connect + read combined). A consumer that consistently takes longer is effectively unsubscribed under load.

## Operations

### Running the worker

One-shot from cron (recommended starting point):

```cron
* * * * *  cd /var/www/cleanmenu && php scripts/webhook-worker.php --batch=20 >> data/logs/webhook-worker.log 2>&1
```

Daemon (under supervisord / systemd):

```bash
php scripts/webhook-worker.php --loop --sleep=2 --batch=10
```

`SIGTERM` / `SIGINT` stop the loop after the current row finishes. The worker is idempotent at the row level — `SELECT ... FOR UPDATE` plus the `status='sending'` flip means two workers cannot pick the same row twice.

### Rotating a secret

Click "Сменить ключ" in the admin UI. The new secret is shown exactly once via a `prompt()` dialog — copy it into the consumer config. The old secret stops working immediately.

### Pausing a subscription without losing it

Click the active toggle. The subscription stays in the table; new events are not enqueued; previously-queued deliveries continue retrying until they succeed or are dropped.

## Security Notes

- **CSRF.** [api/save-webhook.php](../api/save-webhook.php) goes through [lib/Csrf.php](../lib/Csrf.php) `requireValid()`. Admin / owner role gate via `require_auth.php`.
- **Secret never echoed in `list`.** It's only returned on `create` and `rotate_secret`. If an operator loses the secret, rotate it.
- **HTTP scheme allowlist.** Only `http` and `https` URLs are accepted. No `file://`, `ftp://`, etc. (`http` is allowed for local dev consumers; production should always be HTTPS.)
- **HMAC over timestamp+body.** Replay protection is the consumer's job — they should reject anything older than ~5 minutes.
- **No body trimming on send.** Consumers see the exact bytes signed.

## Test Flow

1. Stand up a tiny local consumer (`ngrok` or Cloudflare Tunnel pointing at `http://localhost:4000/sink`).
2. In `/admin-webhooks.php` create a subscription for `reservation.created` with the tunnel URL. Copy the secret from the success message.
3. Hit `POST /create_reservation.php` with a valid CSRF token (or use the `/reservation.php` form).
4. Run the worker once: `php scripts/webhook-worker.php --batch=10`.
5. Confirm the consumer received the request and the HMAC validates.
6. Click "История" for the subscription in the admin UI — expect a row with `status=delivered`, `response_code=200`.
7. Stop the consumer; create another reservation; run the worker.
8. Expect `status=failed`, `next_retry_at` populated. Re-run the worker after the backoff window — expect `status=dropped` after 5 attempts (or `delivered` if you bring the consumer back up).

## Known Gaps / Future Work

- **No event filtering by tenant inside the dispatcher.** Webhooks live in the tenant DB, so per-tenant scoping is implicit; cross-tenant fan-out (provider-level webhooks across many tenant DBs) would need a different surface.
- **No payload schema enforcement.** Right now `data` is whatever the source row looks like — schema drift between releases will surface as quietly broken consumer parsers. A future iteration should version the payload (`event` becomes `reservation.created.v1`).
- **No batch send.** One HTTP call per delivery. For very high throughput that's wasteful; an `events` array shape with cursor would help.
- **No dead-letter export.** `dropped` rows live in the table but there's no UI to export / re-queue them. Operators can re-queue manually with `UPDATE webhook_deliveries SET status='queued', next_retry_at=NULL WHERE id=N`.
- **No worker health metric in [scripts/api-metrics-report.php](../scripts/api-metrics-report.php).** Adding a `webhook_lag_seconds` row (oldest queued delivery's age) is the minimal observability win.
- **`order.status_changed`, `payment.received` not yet wired.** Easy follow-up — drop the `dispatch()` call into [update_order_status.php](../update_order_status.php) and [payment-webhook.php](../payment-webhook.php).

## Related Docs

- [reservations.md](./reservations.md) — the source of half of the current event catalogue.
- [order-lifecycle-contract.md](./order-lifecycle-contract.md) — defines the order statuses that future `order.status_changed` events will carry.
- [feature-audit-matrix.md](./feature-audit-matrix.md) — should grow a `webhooks` row once this is exercised in production.
