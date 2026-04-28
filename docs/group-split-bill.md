# Group Split-Bill Payments (Phase 7.5)

## Implementation Status

- Status: `Partial` (data model + API + webhook reconciliation; UI picker pending)
- Last reviewed: `2026-04-28`

## Overview

A "group order" (Phase 8.3) is a shared tab opened by the host scanning a table QR. Other guests scan the same code, land at `/group/<code>`, and add their own picks to their own seat. When the host clicks "send to kitchen", `Database::submitGroupOrder` freezes the items into one or more real `orders` rows.

Phase 7.5 adds **multi-payer settlement** of that group:

| Mode | Behaviour |
|---|---|
| `host` (default) | Single payer covers the entire group total. Legacy. |
| `per_seat` | Each guest pays a YooKassa intent for their seat's items only. |
| `equal` | Each guest pays an equal share (total / N). |

The mode is stored on `group_orders.split_mode`. Per-payer intents live in the new `group_payment_intents` table. The group transitions to `paid` automatically when `SUM(intents.amount WHERE status='paid') >= SUM(group_order_items.unit_price * quantity)`.

## Data Model

### `group_payment_intents`

| Column | Notes |
|---|---|
| `id` | PK |
| `group_order_id` | FK to `group_orders` (CASCADE delete) |
| `payer_label` | "Маша", "Я", "Гость 3" — free-form |
| `seat_label` | nullable; if set, intent covers exactly that seat |
| `amount` | DECIMAL(10,2) — provider-displayed RUB |
| `payment_method` | `card` / `sbp` / `cash` (cash flows skip YK) |
| `yk_payment_id` | YooKassa UUID once `/v3/payments` responds |
| `status` | `pending` → `paid` / `failed` / `cancelled` |
| `paid_at` | populated by the webhook on success |

### `group_orders.split_mode`

`VARCHAR(16) NOT NULL DEFAULT 'host'`. Values: `host` / `per_seat` / `equal`.

## API

### `POST /api/group-create-payment-intent.php`

Creates an intent + initiates a YooKassa payment for one payer's share.

```jsonc
// Request body
{
  "group_code":   "abc12def",
  "payer_label":  "Маша",
  "seat_label":   "Маша",          // optional — pay exactly this seat
  "share_count":  3,                // optional — for "equal split" mode
  "csrf_token":   "<token>"
}

// Response (200)
{
  "success":    true,
  "intent_id":  17,
  "paymentUrl": "https://yoomoney.ru/checkout/payments/v2/contract?orderId=...",
  "amount":     580.00,
  "remaining":  120.00              // group total minus all paid intents (incl. this)
}
```

Error responses: `400 missing_fields`, `404 group_not_found`, `409 group_not_payable | amount_zero`, `502 yookassa_failed`, `503 yookassa_not_configured`.

### Amount calculation

- If `seat_label` is non-empty: amount = `getGroupSeatTotal(group, seat)`.
- Else: amount = `(getGroupOrderTotal(group) - getGroupPaidTotal(group)) / share_count`.

## Webhook reconciliation

`payment-webhook.php` recognises group intents by `metadata.kind === 'group_intent'`:

1. On `payment.succeeded`: `Database::markGroupPaymentIntentPaid($ykId)` flips the intent to `paid` (atomic, only matches `pending`). If `getGroupPaidTotal(group) >= getGroupOrderTotal(group)`, `markGroupOrderPaid(group)` runs and `cleanmenu_on_order_paid` fires for every underlying order (loyalty, fiscal receipt, `payment.received` webhook).
2. On `payment.canceled`: intent goes to `failed`. Other intents on the same group are unaffected — the user can retry.

## Files

| File | Role |
|---|---|
| [`sql/group-split-payments-migration.sql`](../sql/group-split-payments-migration.sql) | Adds `group_payment_intents` + `group_orders.split_mode`. |
| [`db.php`](../db.php) | New methods: `getGroupOrderTotal`, `getGroupSeatTotal`, `listGroupPaymentIntents`, `createGroupPaymentIntent`, `attachYkPaymentIdToGroupIntent`, `markGroupPaymentIntentPaid`, `getGroupPaidTotal`, `setGroupOrderSplitMode`, `markGroupOrderPaid`. |
| [`api/group-create-payment-intent.php`](../api/group-create-payment-intent.php) | Per-payer intent creation. |
| [`payment-webhook.php`](../payment-webhook.php) | Group-intent reconciliation branch. |

## Verification (sandbox)

1. Apply migration: `mysql … < sql/group-split-payments-migration.sql`.
2. Open a group at `/group.php`, scan with two devices, each adds a few items at distinct seats.
3. Host clicks "send to kitchen" — submits the group as `per_seat` orders.
4. Each guest taps "Pay my share". Frontend calls `/api/group-create-payment-intent.php` with their seat label, follows the redirect to YooKassa sandbox.
5. After both payments confirm, the group transitions to `paid`, fiscal receipts emit per order, loyalty points accrue per user_id where present.

## Frontend (Phase 13A.1, 2026-04-28)

The split-bill payment block is rendered on `/group.php` once
`group_orders.status` becomes `submitted`. State is computed server-side
in `group.php` (`$paymentState` block: total / paid / remaining /
split_mode / intents / per-seat totals). Markup includes:

- A radiobutton fieldset for the three modes (host / per_seat / equal),
  preselected from `group_orders.split_mode`.
- A status line ("Оплачено: X из Y · Осталось: Z").
- Mode-specific action area:
  - **host** — single full-width "Оплатить весь заказ — N ₽" button.
  - **per_seat** — one button per seat with remaining seat amount
    (disabled when seat is fully paid; shows ✅ instead).
  - **equal** — payer name input + share count + pay button.
- A history table of all intents with status pills (paid/pending/
  failed/cancelled).

`js/group-split-pay.js` wires the radios to `POST /api/save-group-order.php
{action:"set_split_mode"}` and the pay buttons to `POST
/api/group-create-payment-intent.php`. Successful intent creation
redirects the user to YK's `confirmation_url`. While at least one
intent is `pending` and the group isn't yet `paid`, the page reloads
every 8s so the user sees their payment land without manual refresh.

When `group_orders.status` flips to `paid`, the action area is replaced
with a green "✅ Заказ полностью оплачен" banner.

## Known Gaps / Future Work
- **No SBP support** for partial payments (only card). YooKassa accepts SBP but we don't expose it for splits yet.
- **No "tip pool" line** — tips on a split bill currently lump into the largest payer's share. Tracked separately.
- **No refund flow** for individual intents (e.g. one guest cancels). Possible via YK refund API → `intent.status = 'cancelled'`; not implemented.

## Cross-Cutting

- Phase 6.3 (loyalty): `cleanmenu_on_order_paid` runs once per underlying order, so each guest with a logged-in `user_id` accrues their own points based on their seat's order total. No double-accrual.
- Phase 7.2 (54-ФЗ fiscal): each underlying order gets its own fiscal receipt — guests get their own receipt URL on `account.php`.
