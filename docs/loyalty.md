# Loyalty Program

## Implementation Status

- Status: `Implemented` (Phase 6.3).
- Last reviewed: `2026-04-23`
- Current state:
  - **Storage:** [sql/loyalty-migration.sql](../sql/loyalty-migration.sql) — `loyalty_tiers`, `loyalty_accounts`, `loyalty_transactions`, `promo_codes`.
  - **DB layer:** [db.php](../db.php) — `listLoyaltyTiers`, `saveLoyaltyTier`, `archiveLoyaltyTier`, `resolveTierForSpent`, `getOrCreateLoyaltyAccount`, `accrueLoyaltyPoints`, `redeemLoyaltyPoints`, `getUserLoyaltyState`, `getUserLoyaltyHistory`, `listPromoCodes`, `savePromoCode`, `archivePromoCode`, `evaluatePromoCode`, `incrementPromoCodeUsage`.
  - **Admin surface:** [/admin-loyalty.php](../admin-loyalty.php) — inline table editors for tiers and promo codes, CRUD via [api/save-loyalty.php](../api/save-loyalty.php).
  - **Customer surface:** [partials/account_loyalty_card.php](../partials/account_loyalty_card.php) — balance / tier / progress-to-next-tier / last 10 transactions. Rendered on `/account.php?tab=profile` only if the user has any activity (zero-state hidden).
  - **Cart integration:** [apply-promo.php](../apply-promo.php) — pure read-and-math endpoint. The counter is incremented only when the order lands, not at validation time.
  - **Order hooks:** `cleanmenu_on_order_paid()` in [payment-webhook.php](../payment-webhook.php) is called from YooKassa + T-Bank branches and from [confirm-cash-payment.php](../confirm-cash-payment.php). Accrues points transactionally and dispatches the `payment.received` webhook.
  - **Tests:** [tests/LoyaltyTest.php](../tests/LoyaltyTest.php) — MySQL-gated, 11 cases: tier resolution, accrual idempotency, tier transition, redeem floor, promo rejection paths (empty/not_found/min_total), pct + fixed-amount math with clamp, both-discount refusal, usage-limit enforcement, tier validation.

## Purpose

Turn one-off guest orders into repeat business. Two mechanisms:

1. **Tiered cashback.** Lifetime spend determines a tier; each tier has a cashback percentage that converts paid orders into points. 1 point = 1 ₽ when redeemed.
2. **Promo codes.** Ad-hoc campaigns (birthday, launch week, winback). Either a percentage or a fixed ₽ amount, with optional min-order and time windows.

Both mechanisms share one ledger (`loyalty_transactions`) and one wallet (`loyalty_accounts.points_balance`). There is no "bonus balance" separated from "promo discount" — promo discounts apply directly to the order total; points redemption is a separate cart-level operation.

## Data Model

### `loyalty_tiers`

| Column         | Type                  | Notes                                                       |
|----------------|-----------------------|-------------------------------------------------------------|
| `id`           | `INT UNSIGNED`        | primary key                                                 |
| `name`         | `VARCHAR(64)`         | display name ("Bronze", "Silver", "Gold")                    |
| `min_spent`    | `DECIMAL(12,2)`       | lifetime spend threshold (inclusive)                         |
| `cashback_pct` | `DECIMAL(5,2)`        | `0..100`, points awarded = `order_total × cashback_pct / 100` |
| `sort_order`   | `INT`                 | tie-break when two tiers share `min_spent`                   |
| `archived_at`  | `DATETIME NULL`       | soft-delete                                                  |

`resolveTierForSpent($spent)` returns the tier with the **highest** `min_spent` ≤ `$spent`. Ties break by `sort_order DESC` then `id DESC`.

### `loyalty_accounts`

| Column           | Type                | Notes                                        |
|------------------|---------------------|----------------------------------------------|
| `user_id`        | `INT` (PK)          | FK to `users(id) ON DELETE CASCADE`           |
| `points_balance` | `DECIMAL(12,2)`     | authoritative current balance                 |
| `total_spent`    | `DECIMAL(12,2)`     | lifetime spend (drives tier resolution)       |
| `tier_id`        | `INT UNSIGNED NULL` | snapshot-cached tier; FK `ON DELETE SET NULL` |

### `loyalty_transactions`

| Column         | Type                | Notes                                                         |
|----------------|---------------------|---------------------------------------------------------------|
| `id`           | `BIGINT UNSIGNED`   | primary key                                                   |
| `user_id`      | `INT`               | FK to `users(id) ON DELETE CASCADE`                           |
| `points_delta` | `DECIMAL(12,2)`     | positive = earn, negative = redeem                             |
| `reason`       | `VARCHAR(32)`       | CHECK allowlist: `accrual`, `redeem`, `manual`, `expire`, `birthday`, `refund` |
| `order_id`     | `INT NULL`          | FK to `orders(id) ON DELETE SET NULL`                          |
| `note`         | `VARCHAR(255) NULL` | free text                                                      |

### `promo_codes`

| Column              | Type                  | Notes                                                  |
|---------------------|-----------------------|--------------------------------------------------------|
| `id`                | `INT UNSIGNED`        | primary key                                            |
| `code`              | `VARCHAR(64) UNIQUE`  | uppercase, `[A-Z0-9_-]{2,64}`                         |
| `discount_pct`      | `DECIMAL(5,2) NULL`   | either this OR `discount_amount` — app-layer invariant |
| `discount_amount`   | `DECIMAL(12,2) NULL`  | fixed ₽ discount                                       |
| `min_order_total`   | `DECIMAL(12,2)`       | `0` = no minimum                                        |
| `valid_from` / `valid_to` | `DATETIME NULL` | time window, both optional                             |
| `usage_limit`       | `INT UNSIGNED`        | `0` = unlimited                                         |
| `used_count`        | `INT UNSIGNED`        | incremented by `incrementPromoCodeUsage()` at order time |

## Accrual Contract

`accrueLoyaltyPoints($userId, $orderId, $orderTotal)` runs inside a single transaction:

1. `SELECT id FROM loyalty_transactions WHERE user_id=? AND order_id=? AND reason='accrual'` — if a row exists, return 0 (idempotent).
2. `INSERT IGNORE INTO loyalty_accounts (user_id) VALUES (?)` — lazy-create the wallet.
3. `SELECT total_spent FROM loyalty_accounts WHERE user_id=? FOR UPDATE` — lock the row.
4. Recompute `total_spent = prev + orderTotal`; resolve the new tier; store `tier_id`.
5. Compute points = `round(orderTotal × cashbackPct / 100, 2)`; `UPDATE` the wallet.
6. If points > 0, insert one `loyalty_transactions` row with `reason='accrual'`.

Called from every code path that flips an order to paid — see "Order hooks" above. A tenant without any tiers defined simply sees 0 points awarded; there's no error, the ledger stays empty.

## Redemption Contract

`redeemLoyaltyPoints($userId, $amount, $orderId = null, $note = null)`:

1. `SELECT points_balance FROR UPDATE` on the wallet row.
2. If `balance < amount` → rollback, return `false`.
3. `UPDATE points_balance = points_balance - amount`.
4. `INSERT` ledger row with `reason='redeem'`, `points_delta = -amount`.

This primitive is exposed for future cart integration (not wired in cart.php yet — Phase 6.3 ships the wallet + promo UX; full point-redeem checkout UI lands as a follow-up).

## Promo Evaluation

`evaluatePromoCode($code, $orderTotal)` is a **pure** check:

- Returns `{ok: true, promo_id, code, discount, new_total}` or `{ok: false, error: <slug>, meta?}`.
- Error slugs: `empty`, `not_found`, `not_yet_valid`, `expired`, `limit_reached`, `below_min_total`, `db_error`.
- `below_min_total` also returns `min` so the cart UI can show "add ₽N to unlock this code".
- Does NOT increment `used_count`. Counter is bumped by `incrementPromoCodeUsage($promoId)` once the order is created.

Clamp rules:

- Discount is clamped to `[0, orderTotal]` — a 1000 ₽ fixed-amount code against a 400 ₽ cart becomes a 400 ₽ discount, new_total 0.
- Pct discount is rounded to 2 decimal places.

## Customer Widget

`/account.php?tab=profile` renders the widget only when the user has:
- any balance, OR
- any `total_spent` > 0, OR
- an assigned `tier_id`, OR
- any `loyalty_transactions` rows.

Hidden otherwise — a brand-new customer doesn't see a zero-state promo they haven't opted into.

Content:
- Three stat cards: Balance / Tier (with cashback %) / Total spent.
- Progress bar to the next tier (computed from `listLoyaltyTiers`).
- Last 10 transactions with reason labels and signed deltas.

## Admin Surface

`/admin-loyalty.php` — role `admin`/`owner`. Two inline tables:

1. **Tiers.** Inline-editable rows, plus a "new tier" row at the bottom. Archiving is soft-delete (preserves historical `tier_id` on accounts).
2. **Promo codes.** Inline-editable. The form rejects submissions where both `discount_pct` and `discount_amount` are set (same invariant enforced in `savePromoCode`). `used_count` is read-only.

CRUD endpoint: [api/save-loyalty.php](../api/save-loyalty.php) with 6 actions.

## Order Hooks

Three call sites run the same logic through `cleanmenu_on_order_paid()`:

| Site | When |
|---|---|
| [payment-webhook.php](../payment-webhook.php) — YooKassa branch | `apiStatus === 'succeeded'` |
| [payment-webhook.php](../payment-webhook.php) — T-Bank branch | `Status === 'CONFIRMED'` |
| [confirm-cash-payment.php](../confirm-cash-payment.php) | cash flip from staff UI |

Each call:

1. `$db->accrueLoyaltyPoints($userId, $orderId, $total)` — idempotent.
2. `WebhookDispatcher::dispatch('payment.received', $order)` — a webhook subscriber to `payment.received` now gets notified too. This closes the `order.status_changed` / `payment.received` gap noted in earlier plan-docs.

## Promo Apply API

`POST /apply-promo.php` — public-ish endpoint (session CSRF required, but works for guest sessions as well as logged-in).

```
POST /apply-promo.php
Content-Type: application/json
X-CSRF-Token: <token>

{ "code": "SUMMER", "order_total": 1000, "csrf_token": "<token>" }
```

Response on success:

```json
{
  "success": true,
  "promo_id": 5,
  "code": "SUMMER",
  "discount": 100,
  "new_total": 900
}
```

On failure returns `400` with `{success:false, error:<slug>}`.

Cart integration is minimal in this iteration — the endpoint and `evaluatePromoCode` are wired, but the UI adjustment of `cart.php` to surface a promo field is a Phase 6.3+ polish task (captured in the "Known Gaps" section below).

## Test Flow

1. Apply `sql/loyalty-migration.sql`.
2. Open `/admin-loyalty.php`:
   - Create tiers: Bronze (0 ₽, 1%), Silver (1000, 3%), Gold (5000, 5%).
   - Create promo `SUMMER`, 10% discount, no window, unlimited usage.
3. Log in as a test customer, place + pay an order for 1200 ₽ in cash.
4. Staff confirms the cash payment at `/employee.php`.
5. Open `/account.php?tab=profile` as the customer:
   - Widget appears. Balance = 36.00 (3% of 1200 because lifetime crossed 1000 into Silver). Tier = Silver.
   - Progress bar shows distance to Gold.
   - Last transaction: "Начисление · заказ #N · +36".
6. Place another cash order; payment is flipped. Balance bumps again; no duplicate row if you re-run the cash-confirm hook.
7. `curl -X POST /apply-promo.php` with `SUMMER` and 800 ₽ → `success:true, discount:80, new_total:720`.
8. `curl` same with `SUMMER` and 100 ₽ → `success:true, discount:10, new_total:90`.
9. Make `SUMMER` archived → `curl` returns `not_found`.

Run the MySQL-gated suite:

```bash
CLEANMENU_TEST_MYSQL_DSN=mysql:host=127.0.0.1;dbname=cleanmenu_test;charset=utf8mb4 \
  composer test
```

## Known Gaps / Future Work

- **Cart UI for promo + points redeem not yet shipped.** Backend is ready (`apply-promo.php` + `redeemLoyaltyPoints`). A future PR updates `cart.php` / `cart.min.js` with a promo input and a points-toggle.
- **No expire policy.** `expire` is in the reason allowlist, but no cron fires "expire points older than N months" yet.
- **No birthday automation.** Reason `birthday` is allowed but not triggered. A Phase 8.1 marketing-automation job will fire on user birthdays.
- **No referral program.** Every referral-style feature shares this ledger; Phase 8.1 can add a `reason='referral_bonus'`.
- **Tiers do not back-fill.** Adding a new tier later doesn't retro-upgrade existing accounts. If needed, run a one-shot script that recomputes `tier_id = resolveTierForSpent(total_spent)` for each row.
- **No per-location tiering.** Works against the tenant DB, not against `location_id` (Phase 6.5). A chain with "special gold status at downtown only" isn't representable.
- **Points ratio is hard-coded at 1:1.** Tenants that want 1 point = 0.5 ₽ can't set it without a schema change.

## Related Docs

- [webhook-integration.md](./webhook-integration.md) — `payment.received` event added via this track.
- [kds.md](./kds.md) — parallel Phase 6 module; both share the admin-header navigation.
- [inventory.md](./inventory.md) — another Phase 6 module; owners typically configure all three at tenant launch.
- [product-vision-2027.md](./product-vision-2027.md) §3 — Phase 6 scope.
