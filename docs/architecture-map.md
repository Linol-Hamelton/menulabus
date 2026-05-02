# Architecture Map (Phase 13B.4)

Functional-feature map of the project: every shipped capability, the surfaces (URLs / files) that expose it, the API endpoints that drive it, and the DB tables it touches. Cross-reference for onboarding, refactoring, and audit.

> Updated 2026-04-28 after Phase 13B.3 root-PHP refactor.

## Top-level layout (after Phase 13B.3)

```
/                                         repo root
├── admin/                                admin pages (9)
├── api/
│   ├── v1/                               bearer-auth REST (mobile clients)
│   ├── reservations/                     session-auth reservation endpoints
│   ├── checkout/                         cash/sbp/cash-confirm flows
│   └── save/                             tenant-config saves (brand/fonts/payment/...)
├── auth/oauth/                           Google/VK/Yandex callback + start
├── kds/                                  Kitchen Display System surface
├── lib/                                  reusable PHP classes (Csrf, Webhook, Fiscal/, ...)
├── partials/                             render fragments included from shells
├── scripts/                              CLI workers + ops tools
├── sql/                                  DB migrations
├── tests/                                PHPUnit + Playwright visual
├── docs/                                 design + ops + plan docs
├── css/  js/  fonts/  images/  icons/    static assets
├── locales/                              ru/en/kk JSON
├── deploy/  vendor/  node_modules/       3rd-party + deploy artefacts
└── ~63 root .php singletons              shells + cross-cutting handlers
```

## Feature → file map

### Customer ordering (cart → order → kitchen)
- **UI**: [`menu.php`](../menu.php), [`menu-public.php`](../menu-public.php), [`cart.php`](../cart.php), [`order-track.php`](../order-track.php), [`order-status.php`](../order-status.php)
- **API**: `create_new_order.php`, `create_guest_order.php`, [`api/checkout/cash-payment.php`](../api/checkout/cash-payment.php), [`generate-payment-link.php`](../generate-payment-link.php), [`payment-webhook.php`](../payment-webhook.php), `api/v1/menu.php`, `api/v1/orders/*.php`
- **DB**: `orders`, `menu_items`, `users`, `modifier_groups`, `modifier_options`
- **Hooks**: `cleanmenu_on_order_paid` ([`lib/OrderPaidHook.php`](../lib/OrderPaidHook.php)) → loyalty + fiscal + webhook

### Kitchen Display System (Phase 6.1)
- **UI**: [`kds/index.php`](../kds/index.php), [`admin/kitchen.php`](../admin/kitchen.php) for station setup
- **API**: [`kds/action.php`](../kds/action.php), [`kds/sse.php`](../kds/sse.php), [`api/save-kitchen-station.php`](../api/save-kitchen-station.php)
- **DB**: `kitchen_stations`, `menu_item_stations`, `order_item_status`
- **Hooks**: `routeOrderItemsToStations` on `order.created`; `order.ready` webhook + Telegram when all positions go ready

### Inventory (Phase 6.2)
- **UI**: [`admin/inventory.php`](../admin/inventory.php), recipe section in [`admin/menu.php`](../admin/menu.php)
- **API**: [`api/save-inventory.php`](../api/save-inventory.php)
- **DB**: `ingredients`, `suppliers`, `recipes`, `stock_movements`
- **Hooks**: `deductIngredientsForOrder` on `order.created`; `inventory.stock_low` webhook + Telegram

### Loyalty (Phase 6.3)
- **UI**: [`admin/loyalty.php`](../admin/loyalty.php), [`partials/account_loyalty_card.php`](../partials/account_loyalty_card.php), `apply-promo.php`
- **API**: [`api/save-loyalty.php`](../api/save-loyalty.php)
- **DB**: `loyalty_accounts`, `loyalty_transactions`, `loyalty_tiers`, `promo_codes`
- **Hooks**: `accrueLoyaltyPoints` on `order.paid`

### Multi-location (Phase 6.5)
- **UI**: [`admin/locations.php`](../admin/locations.php) + per-location selector in shells
- **API**: [`api/save-location.php`](../api/save-location.php)
- **DB**: `locations`; nullable `location_id` on `orders` / `menu_items` / `reservations`

### Analytics v2 (Phase 6.4)
- **UI**: [`owner.php?tab=analytics-v2`](../owner.php), driven by [`js/owner-analytics-v2.js`](../js/owner-analytics-v2.js)
- **API**: `api/analytics-v2.php`
- **DB**: read-only against `orders`, `menu_items`, `loyalty_transactions`

### Reservations (Phase 2 → Polish 12.2.3 + 12.2.4)
- **UI**: [`reservation.php`](../reservation.php), staff board in [`employee.php`](../employee.php) "Брони" tab
- **API**: bearer — `api/v1/reservations/*.php`; session — [`api/reservations/create.php`](../api/reservations/create.php), [`api/reservations/update-status.php`](../api/reservations/update-status.php), [`api/reservations/availability.php`](../api/reservations/availability.php)
- **DB**: `reservations` (with `reminder_sent_at` from 12.2.3)
- **Cron**: [`scripts/reservation-reminder-worker.php`](../scripts/reservation-reminder-worker.php) — 2h pre-arrival Telegram

### Webhooks (Phase 4.1)
- **UI**: [`admin/webhooks.php`](../admin/webhooks.php)
- **API**: [`api/save-webhook.php`](../api/save-webhook.php)
- **DB**: `webhook_subscriptions`, `webhook_deliveries`
- **Cron**: [`scripts/webhook-worker.php`](../scripts/webhook-worker.php) — 1m

### Marketing (Phase 8.1)
- **UI**: [`admin/marketing.php`](../admin/marketing.php)
- **API**: [`api/save-campaign.php`](../api/save-campaign.php)
- **DB**: `marketing_campaigns`, `marketing_sends`, `marketing_segments`, `push_subscriptions`
- **Cron**: [`scripts/marketing-worker.php`](../scripts/marketing-worker.php) — 1m

### Reviews (Phase 8.5)
- **UI**: customer side in [`partials/published_reviews_section.php`](../partials/published_reviews_section.php), moderation in [`owner.php?tab=reviews`](../owner.php)
- **API**: [`api/save/review.php`](../api/save/review.php), `api/v1/reviews/*.php`
- **DB**: `reviews`, `review_ratings`, `review_moderation_log`

### Group orders + split-bill (Phase 8.3 + 7.5 + 13A.1)
- **UI**: [`group.php`](../group.php) — host create, guest join, submit, split-bill payment block
- **API**: [`api/save-group-order.php`](../api/save-group-order.php), [`api/group-create-payment-intent.php`](../api/group-create-payment-intent.php)
- **DB**: `group_orders`, `group_order_items`, `group_payment_intents`
- **Hooks**: webhook reconciliation in [`payment-webhook.php`](../payment-webhook.php) for `metadata.kind=group_intent`

### Waitlist (Phase 8.4)
- **UI**: [`admin/waitlist.php`](../admin/waitlist.php)
- **API**: [`api/save-waitlist.php`](../api/save-waitlist.php)
- **DB**: `waitlist_entries`

### Staff Management v1+v2 (Phase 7.4 + 13A.2)
- **UI**: [`admin/staff.php`](../admin/staff.php) (shifts + tip splits + swap inbox)
- **API**: [`api/save-staff.php`](../api/save-staff.php), [`api/shift-swap-action.php`](../api/shift-swap-action.php)
- **DB**: `shifts`, `time_entries`, `tip_splits`, `shift_swap_requests`, `tips_distribution_rules`, `tips_manual_overrides`
- **CLI**: [`scripts/payroll-export.php`](../scripts/payroll-export.php) — CSV per pay period

### 54-ФЗ fiscal receipts (Phase 7.2 + 13A.3)
- **UI**: [`owner.php?tab=fiscal`](../owner.php) — credentials + test connection + manual re-emit
- **Adapter**: [`lib/Fiscal/AtolOnline.php`](../lib/Fiscal/AtolOnline.php)
- **API**: [`api/save-fiscal-settings.php`](../api/save-fiscal-settings.php)
- **DB**: `orders.fiscal_receipt_uuid`, `orders.fiscal_receipt_url`
- **Hooks**: `cleanmenu_emit_fiscal_receipt` on `order.paid` (best-effort)
- **Cron**: [`scripts/fiscal-receipt-worker.php`](../scripts/fiscal-receipt-worker.php) — 2m

### i18n (Phase 7.3)
- **Helper**: [`lib/I18n.php`](../lib/I18n.php) — `t($key, $params)`
- **Locales**: [`locales/ru.json`](../locales/ru.json), [`locales/en.json`](../locales/en.json), [`locales/kk.json`](../locales/kk.json)
- **CLI**: [`scripts/i18n-extract-strings.php`](../scripts/i18n-extract-strings.php) — surface migration aid

### Auth + OAuth + Sessions
- **UI**: [`auth.php`](../auth.php), [`password-reset.php`](../password-reset.php)
- **OAuth callbacks**: [`auth/oauth/{google,vk,yandex}-{start,callback}.php`](../auth/oauth/)
- **Init**: [`session_init.php`](../session_init.php) (CSRF token, locale resolution, role enforcement)

### Tenant config + saves (Phase 5)
- **UI**: [`owner.php`](../owner.php) tab panes
- **API**: [`api/save/{brand,colors,fonts,menu-order,payment-settings,project-name,review}.php`](../api/save/), plus the api/save-*.php flat-namespace endpoints (campaign, fiscal-settings, group-order, inventory, kitchen-station, location, loyalty, modifiers, push-subscription, staff, waitlist, webhook)

### Background workers (cron)
| Worker | Cadence | Purpose |
|---|---|---|
| [scripts/webhook-worker.php](../scripts/webhook-worker.php) | `* * * * *` | Outgoing webhook delivery |
| [scripts/marketing-worker.php](../scripts/marketing-worker.php) | `* * * * *` | Email/push/Telegram broadcast |
| [scripts/orders/purge-soft-deleted.php](../scripts/orders/purge-soft-deleted.php) | `30 3 * * *` | Daily archive sweep |
| [scripts/reservation-reminder-worker.php](../scripts/reservation-reminder-worker.php) | `*/5 * * * *` | 2h-pre-arrival reservation Telegram |
| [scripts/fiscal-receipt-worker.php](../scripts/fiscal-receipt-worker.php) | `*/2 * * * *` | АТОЛ Онлайн receipt status poll |

## Cross-cutting subsystems

| Subsystem | Files |
|---|---|
| CSRF | [`lib/Csrf.php`](../lib/Csrf.php) (token, verify, requireValid, submitted) |
| CSP / security headers | [`security-headers.php`](../security-headers.php) |
| Tenant runtime | [`tenant_runtime.php`](../tenant_runtime.php), [`session_init.php`](../session_init.php) |
| Brand colors | [`auto-colors.php`](../auto-colors.php) (emits `--ui-*` tokens + `--{key}-rgb` triplets) |
| Brand fonts | [`auto-fonts.php`](../auto-fonts.php) |
| OpenAPI contract | [`docs/openapi.yaml`](openapi.yaml) |
| Smoke runner | [`scripts/api-smoke-runner.php`](../scripts/api-smoke-runner.php) |
| Visual regression | [`tests/visual/`](../tests/visual/) — 49+ Playwright snapshots |
| Pre-push hook | [`.githooks/pre-push`](../.githooks/pre-push) — lint + mojibake + PHPUnit + docs-drift + visual + OpenAPI |
| Post-merge hook | [`.githooks/post-merge`](../.githooks/post-merge) — runs on production checkout (smoke + capture-baseline + browser-regression) |

## See also

- [`docs/file-index.md`](file-index.md) — flat catalog of every root-level PHP, what it does, who calls it (Phase 13B sibling artefact).
- [`docs/feature-audit-matrix.md`](feature-audit-matrix.md) — feature-status snapshot.
- [`docs/ui-patterns.md`](ui-patterns.md) — canonical block compositions to reuse.
- [`docs/project-improvement-roadmap.md`](project-improvement-roadmap.md) — Phase 0..9 roadmap.
- [`docs/product-vision-2027.md`](product-vision-2027.md) — north-star.
