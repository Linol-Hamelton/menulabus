# Payments Integration

## Implementation Status

- Status: `Implemented`
- Last reviewed: `2026-04-11`
- Verified against code: [lib/TBank.php](../lib/TBank.php), [generate-payment-link.php](../generate-payment-link.php), [payment-return.php](../payment-return.php), [payment-webhook.php](../payment-webhook.php), [confirm-cash-payment.php](../confirm-cash-payment.php), [api/save/payment-settings.php](../api/save/payment-settings.php), [sql/payment-migration.sql](../sql/payment-migration.sql), [sql/sbp-migration.sql](../sql/sbp-migration.sql).

## Purpose

This document is the operator- and engineer-facing reference for the payment layer. It covers every provider currently wired into the codebase (`cash`, `online` via YooKassa, `sbp` via T-Bank), the settings keys that configure them, the webhook contract, how idempotency is enforced, and the common failure modes.

## Supported providers

| Provider | `payment_method` value | Use case |
|---|---|---|
| Cash at pickup / cash on delivery | `cash` | Default. Confirmed by an employee via `confirm-cash-payment.php`. |
| YooKassa (Юkassa) card payment | `online` | Default online card acquiring for the Russian market. Creates a hosted payment page and redirects the user there. |
| T-Bank (Тинькофф) acquiring via СБП | `sbp` | Fast-payment-system rail via T-Bank terminal. Signed with SHA-256 token; verified on webhook and on payment-return. |

## Data model

Applied via [sql/payment-migration.sql](../sql/payment-migration.sql) + [sql/sbp-migration.sql](../sql/sbp-migration.sql). Three columns are added to `orders`:

| Column | Type | Notes |
|---|---|---|
| `payment_method` | `ENUM('cash','online','sbp')` | Default `cash`. |
| `payment_id` | `VARCHAR(100)` | Provider payment UUID (YooKassa) or T-Bank `PaymentId`. Indexed via `idx_orders_payment_id`. |
| `payment_status` | `ENUM('not_required','pending','paid','failed','cancelled')` | State machine of the payment. |

Writes go through `Database::updateOrderPayment($orderId, $paymentId, $status, $method = null)` in [db.php](../db.php).

## Settings keys

All stored in the `settings` table, JSON-encoded (wrap values with `json_encode()`). Written via [api/save/payment-settings.php](../api/save/payment-settings.php), which accepts only the allowlist below and requires `admin`/`owner` role plus CSRF.

| Key | Kind | Meaning |
|---|---|---|
| `yookassa_enabled` | bool | Master switch for the YooKassa surface. |
| `yookassa_shop_id` | string | YooKassa shop identifier (numeric). |
| `yookassa_secret_key` | string | YooKassa secret key. Never log or echo. |
| `yookassa_return_url` | string (optional) | Override for return URL; if empty the endpoint builds it from the host + `/payment-return.php?order_id=…`. |
| `tbank_enabled` | bool | Master switch for the T-Bank SBP surface. |
| `tbank_terminal_key` | string | T-Bank terminal key. |
| `tbank_password` | string | T-Bank terminal password used to sign requests. |

> Secret values are validated by `api/save/payment-settings.php` against a conservative printable-char regex (`^\S{1,200}$`). Values above 200 characters are rejected with HTTP 422.

## Request flows

### YooKassa (`online`)

1. **Customer-initiated** — cart flow creates the order, then the customer is pushed to the YooKassa confirmation URL. The order starts with `payment_status='pending'`.
2. **Staff-initiated (Waiter link)** — [generate-payment-link.php](../generate-payment-link.php) is a staff-only endpoint. An employee/admin/owner calls it with `order_id`. The endpoint:
   - checks CSRF and role;
   - refuses if the order is already `paid`;
   - if the order already has a `pending` payment and valid credentials, it **reuses the existing YooKassa `confirmation_url`** instead of creating a duplicate payment (prevents double-charging and idempotently returns the same link to a re-pressed button);
   - otherwise, calls `POST https://api.yookassa.ru/v3/payments` with a fresh `Idempotence-Key` built from `'waiter_' + orderId + uniqid()`;
   - stores the resulting `payment_id` via `updateOrderPayment(..., 'pending', 'online')`;
   - responds with `{ success, paymentUrl, orderId, reused? }`.
3. **Payment webhook** — YooKassa posts to [payment-webhook.php](../payment-webhook.php). The endpoint:
   - accepts only `payment.succeeded` and `payment.canceled` events;
   - **verifies the payment** by re-fetching it from `GET /v3/payments/{id}` with Basic auth (more secure than IP-allowlisting);
   - updates `payment_status` accordingly;
   - if a payment is canceled on an order that is still in `принят`/`приём`, it additionally flips the order status to `отказ`.
4. **Payment return** — after redirect the user lands on [payment-return.php](../payment-return.php), which re-checks the payment state via the provider API and renders a success/cancel/pending screen with auto-refresh for the pending case.

### T-Bank SBP (`sbp`)

1. Orders created with `payment_method='sbp'` go through a T-Bank `Init` call (see `tBankRequest('Init', …)` in [lib/TBank.php](../lib/TBank.php)); the response contains a `PaymentURL` to redirect the user to.
2. **Webhook** — same endpoint [payment-webhook.php](../payment-webhook.php); the handler detects a T-Bank callback either by `Content-Type: application/x-www-form-urlencoded` or by presence of `TerminalKey` in `$_POST` / body.
3. **Token verification** — `handleTBankWebhook()` recomputes the SHA-256 token with `tBankToken()` and `hash_equals()`-compares it against the `Token` field from the request. Mismatch → HTTP 400.
4. **Status map** — `CONFIRMED` → `paid`; `REJECTED`, `AUTH_FAIL`, `REVERSED` → `cancelled` (with auto-transition of order status to `отказ` when still pending).
5. **Return flow** — [payment-return.php](../payment-return.php) branches on `payment_method==='tbank_sbp'` and calls `tBankRequest('GetState', …)` to resolve the payment state when the user lands.

### Cash (`cash`)

1. Order is created with `payment_status='not_required'` (or `pending` if a cash-on-delivery flag is configured at cart level).
2. Employee confirms cash receipt via [confirm-cash-payment.php](../confirm-cash-payment.php), which calls `Database::confirmCashPayment($orderId)`.
3. The confirm endpoint is **idempotent**: it reads the `Idempotency-Key` header via `Idempotency::getHeaderKey()`, hashes the request payload, and replays the cached response for the same key+hash. Payload conflict on the same key returns HTTP 409. See [api-smoke.md](./api-smoke.md) for the header contract.

## Idempotency

- Library: [lib/Idempotency.php](../lib/Idempotency.php).
- Table: `api_idempotency_keys` (see [sql/mobile-api-tables.sql](../sql/mobile-api-tables.sql)).
- Used today by: `confirm-cash-payment.php`, `api/v1/orders/create.php` (order creation), and mobile API POSTs. `generate-payment-link.php` relies on YooKassa's own `Idempotence-Key` header for the upstream call and additionally reuses existing pending payments to stay idempotent at the business level.
- Key TTL: enforced by the row's `expires_at` column.

## Webhook URL registration

Register these in the respective provider consoles **per deployment** (provider + each tenant with a custom domain):

- YooKassa → `https://<domain>/payment-webhook.php` — events: `payment.succeeded`, `payment.canceled`.
- T-Bank → same URL, same file, branched internally by content type / body.

Both run under the nginx scope lock: the `location ^~ /scripts/ { return 404; }` rule does not affect them because they live at the project root.

## Admin test flow

1. In the owner/admin brand panel, open the payments section and enter provider credentials (see settings keys above).
2. Toggle `yookassa_enabled` / `tbank_enabled` to `true`.
3. Create a test order in `cart.php` with a small amount, confirm the correct payment method is offered and the redirect succeeds.
4. Re-check `payment-return.php` → should show "Оплата прошла успешно" within 3 seconds for succeeded payments.
5. Re-check the order in `employee.php` — `payment_status` should be `paid`.
6. For cash: confirm the "Confirm cash" button in the staff view updates the order without creating a duplicate row (idempotent on retry).

## Common failure modes

| Symptom | Likely cause | Where to look |
|---|---|---|
| `Онлайн-оплата не настроена` (HTTP 503) on `generate-payment-link.php` | `yookassa_enabled` is `false` or `yookassa_shop_id`/`yookassa_secret_key` is empty | `api/save/payment-settings.php` / `settings` table |
| `Ошибка создания платежа в ЮKassa` (HTTP 502) | Wrong credentials or YooKassa rejected the amount/currency | `error_log` entries from `generate-payment-link.php` |
| Webhook accepted but order not updated | `getOrderByPaymentId()` returned null — `payment_id` was never persisted, or persisted against the wrong order | DB row for the order; `updateOrderPayment()` call trace |
| T-Bank webhook HTTP 400 "Invalid token" | `tbank_password` setting mismatches the terminal password | Admin panel; re-enter terminal credentials |
| Double-charge after retry of "Waiter link" button | Pending payment lookup failed to reuse — check that `payment_id` is present on the order row before the second click | DB row + `generate-payment-link.php` error log |
| `Idempotency-Key уже использован с другим payload` (HTTP 409) on cash confirm | Client re-used a key for a different order_id | Have the client generate a fresh key per logical action |

## Related docs

- [order-lifecycle-contract.md](./order-lifecycle-contract.md) — status transitions that payment events can trigger (`отказ` on cancelled).
- [api-smoke.md](./api-smoke.md) — how to exercise the order-create + idempotency flow with `scripts/api-smoke-runner.php --run-order=1`.
- [openapi.yaml](./openapi.yaml) — mobile contract for `POST /api/v1/orders/create.php` (the mobile path that also consumes idempotency).
