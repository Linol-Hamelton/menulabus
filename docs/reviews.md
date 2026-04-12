# Reviews / Feedback Loop

## Implementation Status

- Status: `Implemented`
- Last reviewed: `2026-04-12`
- Current state:
  - **Submission:** [order-track.php](../order-track.php) renders a 1–5 star form and optional comment textarea on any completed (`завершён`) order that has no existing review. Guests and logged-in users both submit through [save-review.php](../save-review.php).
  - **Storage:** [sql/reviews-migration.sql](../sql/reviews-migration.sql) — append-only `reviews` table, one row per order, enforced by a `UNIQUE (order_id)` index.
  - **Owner surface:** `/owner.php?tab=reviews` — read-only last-50 list with average rating, per-order deep-link back to the tracker. No moderation, no replies, no deletion from the admin in this iteration.
  - **5-star deep-link:** when `google_review_url` is set in brand settings, 5-star submissions surface a "Поделиться в Google" link after the thank-you state. Empty setting = button hidden.

## Purpose

Close the feedback loop between the moment a guest finishes a meal and the next order. Two goals:

1. Give the restaurant a lightweight signal ("was the last hour good or bad") without a separate survey tool or third-party integration.
2. Capture 5-star moments and redirect the most-motivated customers to a public Google review — the single highest-leverage marketing action for a small restaurant.

The scope is deliberately narrow: append-only data, no moderation layer, no public display of reviews on the menu. If any of those become needed, they become a new iteration.

## Data Model

Table `reviews` — see [sql/reviews-migration.sql](../sql/reviews-migration.sql):

| Column       | Type                    | Notes                                                                   |
|--------------|-------------------------|-------------------------------------------------------------------------|
| `id`         | `INT UNSIGNED AUTO_INCREMENT` | primary key                                                        |
| `order_id`   | `INT UNSIGNED NOT NULL`       | `UNIQUE` — one review per order; FK to `orders(id) ON DELETE CASCADE` |
| `user_id`    | `INT NULL`              | nullable — guest orders still get a row                                  |
| `rating`     | `TINYINT UNSIGNED`      | 1..5, enforced by `CHECK` constraint                                     |
| `comment`    | `TEXT NULL`             | optional, hard-trimmed to 2000 chars server-side                        |
| `ip_hash`    | `CHAR(64) NULL`         | `sha256(REMOTE_ADDR + session_id)` — rate-limit / abuse signal without PII |
| `created_at` | `DATETIME`              | defaults to `NOW()`                                                      |

Indexes: unique `(order_id)`, regular `(created_at)`, regular `(rating)`. The unique key is the core correctness invariant — even under a racing double-submit the DB refuses the second insert and [db.php::createReview()](../db.php) catches SQLSTATE 23000 and returns null.

## Submission Flow

1. Guest finishes the flow and lands on `/order-track.php?id=N`.
2. When the poll (or initial server render) sees `status === 'завершён'`, the PHP branch renders the `#reviewSection` block with stars + textarea.
3. `order-track.php` also writes `N` into `$_SESSION['reviewable_orders']` (cap 20). This is the session-scoped ownership proof — you cannot POST a review for an order you never viewed from this session.
4. The form posts JSON to `/save-review.php` with `order_id`, `rating`, `comment`, and the session CSRF token.
5. [save-review.php](../save-review.php) validates in order:
   - `POST` method
   - CSRF token matches `$_SESSION['csrf_token']`
   - `order_id > 0`, `rating ∈ {1..5}`
   - `order_id` is in `$_SESSION['reviewable_orders']`
   - Order exists and is in `завершён` state
   - No existing review for this order (short-circuit; also defended by unique index)
   - If session has `user_id`, it must match the order's `user_id` (guests skip this check)
6. On success the endpoint returns `{ success: true, review_id, rating, google_review_url }`. `google_review_url` is populated only when `rating === 5` AND the tenant has set a valid URL in brand settings.
7. [js/reviews.js](../js/reviews.js) hides the form, shows the thank-you panel, and (for 5-star submissions with a Google URL) reveals the "Поделиться в Google" link.

## Owner View

`/owner.php?tab=reviews` — new tab alongside "Статистика" and "Пользователи". Shows:

- Average rating across the currently-loaded window (last 50 entries)
- Desktop table: date, order #, star row, order total, comment
- Mobile card stack with the same fields
- Each order number links back to `/order-track.php?id=N` so the owner can see the context of the order that was rated

No actions — this is a read surface. Deleting a review is deliberately not exposed; if a bad-faith review needs to go, it is removed with a direct SQL statement against the tenant DB, and that path is documented per-tenant.

## Google Review Deep-Link

Setting: `google_review_url`, managed in [admin-menu.php](../admin-menu.php) alongside other brand fields. Validated as a URL in [save-brand.php](../save-brand.php) with a relaxed 500-char limit (default is 200) because Google's `writereview?placeid=…` URLs can be long.

Behavior:

- Empty → `js/reviews.js` never renders the share button.
- Set → after a 5-star submission, the thank-you panel includes a primary CTA linking to the configured URL with `target="_blank" rel="noopener"`.

The setting is deliberately not exposed to non-5-star submissions. Low-rated feedback stays private to the owner dashboard; only the motivated top-rated guests get nudged toward public posting. This keeps the tenant's Google profile aligned with their best moments without pressuring unhappy customers to vent publicly.

## Security Notes

- **CSRF:** Standard site pattern — token in session, header `X-CSRF-Token` or body `csrf_token`, validated with `hash_equals`. Works for guests because the CSRF token is seeded for anonymous sessions too.
- **Order ownership:** The session-scoped `reviewable_orders` allow-list is the practical "ownership" proof for guests. A malicious actor with a raw order ID cannot POST from a fresh session — they would need to first load the tracker page, which means they already know about the order.
- **Rate limiting:** Not implemented in the first iteration. The unique-per-order index provides structural protection against mass spam on a single order. Per-IP abuse is visible post-hoc via `ip_hash` in the owner DB.
- **PII:** The `ip_hash` is salted with `session_id()` so the same IP across sessions hashes differently. Raw IPs are never stored.
- **XSS:** All owner-side rendering uses `htmlspecialchars` + `nl2br`. The customer-side thank-you panel only renders strings the server generated.

## Test Flow

1. Place a guest order (no login) from `/menu.php` → `/cart.php` → checkout.
2. Note the order number, open `/order-track.php?id=N` as the same browser session.
3. On a different browser / incognito, open `/order-track.php?id=N` and confirm:
   - No form visible (order is not yet `завершён`).
4. Log in as an employee, move the order through statuses to `завершён`.
5. Refresh the tracker on the first browser. The star block appears.
6. Submit a 3-star review with a comment. Expect: form hides, thank-you shows, no Google link.
7. Refresh the page. Expect: read-only thank-you block (not the form again).
8. In a second session, place and complete another order. Submit a 5-star review.
9. Expect: thank-you panel + "Поделиться в Google" link (only if `google_review_url` is configured in brand settings).
10. Open `/owner.php?tab=reviews`. Expect: both reviews listed, newest first, average rating shown.
11. Attempt `curl -X POST /save-review.php` with a valid order ID from a fresh session. Expect: `403 Order not accessible from this session`.
12. Attempt a second POST for the same order from the session that already reviewed it. Expect: `409 Review already submitted`.

## Known Gaps / Future Work

- **No moderation UI:** a bad-faith review must be removed via SQL.
- **No public display:** reviews do not feed back into the tenant menu or homepage in this iteration.
- **No owner reply:** the data model supports it (add a `reply_text` column) but the first iteration is read-only.
- **No push to owner:** a new 1-star review does not trigger a Telegram alert. `toggle-available.php` + `telegram-notifications.php` show the pattern if this is added later.
- **No per-item rating:** the rating is whole-order, not per-dish. Adding it would require a separate `review_items` table and a rework of the submission UI.

## Related Docs

- [project-reference.md](./project-reference.md) — section 5.2 lists the public routes exposed by each tenant; `save-review.php` and the updated `order-track.php` are part of that surface.
- [feature-audit-matrix.md](./feature-audit-matrix.md) — the "reviews / feedback loop" row in §3 is the audit entry for this module.
- [menu-capabilities-presentation.md](./menu-capabilities-presentation.md) — the guest-facing and owner-facing bullets describe the feature to non-engineers.
- [public-layer-guidelines.md](./public-layer-guidelines.md) — CSP rules the submission UI must honor (no inline styles/scripts; external css + js only).
- [api-smoke.md](./api-smoke.md) — the smoke runner does not currently exercise `/save-review.php`; adding a case there is a followup if the feature becomes load-bearing.
