# 54-ФЗ Fiscal Receipts (Phase 7.2)

## Implementation Status

- Status: `Partial` (adapter + hook wired; admin UI + production verification pending)
- Last reviewed: `2026-04-27`
- Provider: АТОЛ Онлайн (cloud OFD-compliant fiscalisation)

## Overview

Russian businesses are legally required by 54-ФЗ to issue a fiscal receipt for every retail transaction. CleanMenu integrates with [АТОЛ Онлайн](https://online.atol.ru) — a cloud fiscal service that wraps the OFD (Operator of Fiscal Data) protocol, accepts a normalised receipt JSON, and returns a permalink to the OFD-published receipt that the customer can show, download, or receive by email.

Flow on `order.paid`:

1. `lib/OrderPaidHook.php` `cleanmenu_on_order_paid` fires (already wired from yookassa, t-bank, sbp, cash payment paths).
2. New helper `cleanmenu_emit_fiscal_receipt` checks the tenant's fiscal config and, if `fiscal_provider = 'atol'`, calls `\Cleanmenu\Fiscal\AtolOnline::emitSaleReceipt` with the order items.
3. АТОЛ returns a `uuid` (status usually `wait`). The helper stamps `orders.fiscal_receipt_uuid`.
4. `scripts/fiscal-receipt-worker.php` (cron `*/2 * * * *`) polls АТОЛ for pending uuids. When status flips to `done`, it stamps `orders.fiscal_receipt_url`. On `fail` it clears the uuid so the next retry can fire from scratch.
5. Customer-facing surfaces (cart/account/order-track) link to `fiscal_receipt_url` when present.

The fiscalisation step is **best-effort** — failures are logged but never block payment confirmation. Real-world AOL outages must not bounce customer payments.

## Files

| File | Role |
|---|---|
| [`sql/fiscal-receipt-migration.sql`](../sql/fiscal-receipt-migration.sql) | Adds `orders.fiscal_receipt_uuid`, `orders.fiscal_receipt_url`, supporting index. INFORMATION_SCHEMA-guarded so re-run is safe. |
| [`lib/Fiscal/AtolOnline.php`](../lib/Fiscal/AtolOnline.php) | Adapter: `getToken`, `emitSaleReceipt`, `fetchReceiptUrl`. Inject custom HTTP client for tests via `setHttpClient`. |
| [`lib/OrderPaidHook.php`](../lib/OrderPaidHook.php) | `cleanmenu_emit_fiscal_receipt(Database, array $order)` reads tenant config, instantiates the adapter, persists uuid. |
| [`scripts/fiscal-receipt-worker.php`](../scripts/fiscal-receipt-worker.php) | Cron worker. Polls 50 oldest pending receipts per run. |

## Tenant Configuration (settings keys)

All values are JSON-encoded in `settings` (per project convention).

| Key | Required | Notes |
|---|---|---|
| `fiscal_provider` | yes | `"atol"` to enable. Empty disables fiscalisation. |
| `fiscal_atol_login` | yes | АТОЛ Онлайн API login |
| `fiscal_atol_password` | yes | API password (consider server-side encryption at rest) |
| `fiscal_atol_group_code` | yes | "Касса" code from the АТОЛ admin panel |
| `fiscal_atol_inn` | yes | Tenant ИНН (10 or 12 digits) |
| `fiscal_atol_payment_address` | yes | URL or street address of the till (e.g. `https://menu.labus.pro`) |
| `fiscal_atol_sno` | yes | One of `osn` / `usn_income` / `usn_income_outcome` / `envd` / `esn` / `patent`. Default `usn_income`. |
| `fiscal_atol_sandbox` | yes | `"1"` for test (`testonline.atol.ru`), `"0"` for prod (`online.atol.ru`) |

## Cron

```cron
*/2 * * * * cd /var/www/.../menu.labus.pro && php scripts/fiscal-receipt-worker.php >> data/logs/fiscal-receipt-worker.log 2>&1
```

Add inside the `# >>> cleanmenu cron >>>` marker block alongside webhook-worker, marketing-worker, purge-soft-deleted, reservation-reminder.

## Verification (sandbox)

1. Apply migration: `mysql … < sql/fiscal-receipt-migration.sql`.
2. Set tenant config: `fiscal_provider=atol`, `fiscal_atol_sandbox=1`, plus АТОЛ Sandbox creds.
3. Place a test order, complete payment via the YooKassa test gateway.
4. Within ~1 minute, `orders.fiscal_receipt_uuid` should populate.
5. Within ~5 minutes, `orders.fiscal_receipt_url` should populate; opening the URL reveals the test receipt at АТОЛ Sandbox.
6. Tail the worker log: `tail -f data/logs/fiscal-receipt-worker.log` — expected `checked=N updated≥1 failed=0 pending=*`.

## Frontend (Phase 13A.3, 2026-04-28)

`/owner.php?tab=fiscal` (owner-only) renders the partial
`partials/owner_fiscal_section.php` with:

- Provider radio (`""` disabled / `"atol"` АТОЛ Онлайн).
- АТОЛ field grid: login, password (with placeholder "сохранён,
  перезапишите чтобы сменить" if already stored), group code, ИНН
  (10/12 digits), payment address, СНО dropdown (osn / usn_income /
  usn_income_outcome / envd / esn / patent), sandbox toggle.
- "Сохранить" → POST `/api/save-fiscal-settings.php` writes all
  `fiscal_*` setting keys via `Database::setSetting` (JSON-encoded).
  Empty password preserves the existing stored value so other fields
  can be edited without re-typing the secret.
- "Тест соединения" → POST `?test=1`. Calls `AtolOnline::ensureToken()`
  with the supplied (or stored) credentials without saving; returns a
  token prefix on success or the verbatim АТОЛ error.
- Manual receipt re-emit: order_id input + button → POST
  `?reemit=<order_id>`. Drops any existing uuid for the order, then
  runs `cleanmenu_emit_fiscal_receipt()` to fire a fresh sale request.

CSS in `css/owner-fiscal.css` is token-based (uses `--ui-surface`,
`--ui-border`, `--ui-success`, `--ui-danger`). Inline status pills for
save/test/reemit feedback.

## Known Gaps / Future Work
- **No customer-facing receipt link rendering.** Once URLs land, account.php / order-track.php should add a "Чек" link.
- **No password encryption at rest.** The settings table is plaintext; Phase 9 compliance pack will introduce KMS-backed encryption for credential-bearing settings.
- **No support for refunds** (АТОЛ "возврат прихода"). Planned for Phase 7.2.1 once the happy-path is live in production.
- **No support for advance/partial payments** ("аванс" / "предоплата"). The current adapter sets `payment_method = full_payment` for every line, which is fine for a restaurant cart but wrong for deposits.
- **Other providers** (Чек Онлайн, Эвотор, Ferma) — the adapter API is small enough that swapping providers is a `lib/Fiscal/<Vendor>.php` away. Tracked in feature-audit-matrix §7.
