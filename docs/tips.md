# Tips

## Implementation Status

- Status: `Implemented`
- Last reviewed: `2026-04-11`
- Verified against code: [cart.php](../cart.php), [js/cart-tips.js](../js/cart-tips.js), [create_new_order.php](../create_new_order.php), [sql/tips-migration.sql](../sql/tips-migration.sql), [db.php](../db.php), [telegram-notifications.php](../telegram-notifications.php).

## Purpose

Engineer-facing reference for the optional tipping feature in the cart. Covers the UI, how the selected tip flows into order creation, how it combines with the payment provider amount, and the non-obvious rule about where tips live in the data model.

## UX

Tips live on `cart.php` under a dedicated `.tips-section`. Five buttons: `Без`, `5%`, `10%`, `15%`, `Своя`. The first four are percentage presets; `Своя` opens a numeric input for a custom ruble amount.

- `Без` is pre-selected (`active`) on load — **tipping is explicit opt-in**.
- Percentage buttons re-compute their value every time the cart changes, via a `cartUpdated` custom event listened for by [js/cart-tips.js](../js/cart-tips.js).
- The custom input is clamped to `0 ≤ x ≤ 9999` rubles client-side.
- The resolved integer amount is written to a hidden `<input id="selectedTip">`.
- A live label `tipsTotalDisplay` shows `Чаевые: N ₽` under the buttons when non-zero.

Rounding is `Math.round(total * pct / 100)` — always to the nearest whole ruble. The server does not re-apply rounding; whatever the client posts wins (bounded by the server-side floor below).

## Data model

Added by [sql/tips-migration.sql](../sql/tips-migration.sql):

```sql
ALTER TABLE orders
    ADD COLUMN `tips` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `total`;
```

Key rules:

- `tips` is a **separate column**, not rolled into `total`. The `total` stays the menu subtotal; tips is additive on top.
- `NOT NULL DEFAULT 0.00` — old orders predating the migration are backfilled to `0.00`. Reports can safely sum `tips` across historical rows.
- The column is on `orders` only. `order_items` has no per-line tip concept — tips attach to the whole order, not to a single dish.

## Order creation flow

1. Customer hits "Оформить" in the cart. The client-side cart JS reads `#selectedTip` and includes it in the JSON POST body as `tips`.
2. [create_new_order.php:63](../create_new_order.php) sanitizes it: `$tips = max(0.0, (float)($input['tips'] ?? 0));`. Negative values are clamped to 0. There is no upper bound on the server side — the client is trusted for the ceiling.
3. The order is created via `Database::createOrder($userId, $items, $total, $deliveryType, $deliveryDetail, $tips, $paymentMethod, 'pending')`. The `tips` parameter is the 6th positional argument — if you extend this method, do not rearrange these.
4. The fresh order row is passed to `sendOrderToTelegram()` (see [telegram-bot-setup.md](./telegram-bot-setup.md)) with `$orderRow['tips']` already populated; the bot card renders a `💝 Чаевые: N ₽` line whenever `tips > 0`, and omits the line entirely when the tip is zero.

## Payment provider amounts

Tips are included in the amount charged by both online providers — they are **not** settled separately.

### YooKassa (`online`)

`create_new_order.php:231` builds the YooKassa `amount.value` as `number_format((float)$total + (float)$tips, 2, '.', '')`. A single payment is created for `subtotal + tips`, and the customer sees one combined sum on the hosted payment page.

### T-Bank SBP (`sbp`)

`create_new_order.php:301` builds the T-Bank `Amount` field as `(int)(round(($total + $tips) * 100))` — kopecks, same `subtotal + tips` combination.

### Cash (`cash`)

No provider roundtrip. The tip is recorded in `orders.tips` and the restaurant is expected to collect `total + tips` in person. The staff view should display both so nothing is forgotten.

## Reporting

Because `tips` is a first-class column, tips revenue is recoverable with:

```sql
SELECT SUM(tips) AS tips_total, COUNT(*) AS order_count
FROM orders
WHERE created_at >= ? AND status = 'завершён';
```

There is no dedicated "tips share" block in the owner report today — if you want it, add a card to [owner.php](../owner.php) that mirrors the revenue breakdown and joins against this column. No schema change needed.

## Common failure modes

| Symptom | Likely cause | Where to look |
|---|---|---|
| Tip button is visually selected but the hidden input stays at 0 | `cart-tips.js` didn't load (CSP violation, wrong nonce, or 404) | DevTools → Network; CSP errors in console |
| Custom input accepts more than 9999 | Client clamp was bypassed (old cached JS or manual form submit) — there is no server ceiling, so the value goes through as-is | `create_new_order.php:63`; consider adding a server-side clamp if this becomes a support issue |
| Payment provider page shows the wrong amount | YooKassa/T-Bank amount was built from `$total` only, ignoring `$tips`. Usually means someone edited one provider branch without updating the other | `create_new_order.php` around lines 231 and 301 |
| Telegram card shows a random `💝 Чаевые` line on zero-tip orders | `tips > 0` check was dropped from `telegram-notifications.php` | [telegram-notifications.php](../telegram-notifications.php) line 80 |
| Historical orders show `NULL` tips | Migration was not applied on an older tenant DB — the column wouldn't exist at all, so this symptom actually means the column is there but the rows predate the migration | Re-run [sql/tips-migration.sql](../sql/tips-migration.sql); the `DEFAULT 0.00` handles backfill |
| Percentage tip doesn't update when items are added | `cartUpdated` event is not dispatched by the cart module after the add, or `cart-tips.js` initialized before the cart module | Verify both scripts are loaded and `cartUpdated` fires on add/remove |

## Related docs

- [payments-integration.md](./payments-integration.md) — YooKassa and T-Bank amount contracts that consume `total + tips`.
- [telegram-bot-setup.md](./telegram-bot-setup.md) — the order card that renders the tip line.
- [schema-and-migrations.md](./schema-and-migrations.md) — the `orders.tips` column as part of the tenant schema.
- [order-lifecycle-contract.md](./order-lifecycle-contract.md) — order status transitions (tips are orthogonal to status).
