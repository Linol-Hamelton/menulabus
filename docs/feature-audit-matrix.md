# Feature Audit Matrix

## Implementation Status

- Status: `Implemented`
- Last reviewed: `2026-04-28` (Phase 13B.5 sweep — Phase 7 + 8 features marked shipped, Phase 13B.3 file-move reflected)
- Current implementation notes:
  - This document captures the current `repo + live` audit baseline.
  - The audit is docs-first: runtime mismatches are recorded here and in roadmaps, not fixed in this cycle.
  - No whole active doc files met deletion criteria after the audit; stale content was removed from retained docs instead.
  - Full-scope production coverage now has a dedicated one-shot runner, `scripts/perf/full-ui-audit.cjs`, which reuses the release baseline and emits route-level findings plus a prioritized fix plan.

## Audit Baseline

- Provider live target: `https://menu.labus.pro`
- Tenant live target: `https://test.milyidom.com`
- Auth-gated live audit: `account`, `help`, `admin-menu`, `owner`, `employee`, `customer_orders`, `qr-print`, `monitor`, `opcache-status`
- Repo-first audit: `api/v1/*`, OAuth routes, payment/webhook routes, SSE/long-poll, `file-manager.php`, `clear-cache.php`, `scripts/tenant/*`

## 1. Provider Public

| Route / feature | Current behavior | Source in repo | Doc coverage | Roadmap status |
|---|---|---|---|---|
| `/` | live provider landing, title `labus \| Меню ресторана`, B2B CTA `Оставить заявку` present | `index.php`, `header.php`, `session_init.php` | `README`, `product-model`, `public-layer-guidelines`, `project-reference` | implemented |
| `/index.php` | same provider landing behavior as `/` | `index.php` | partially explicit | implemented |
| `/menu.php` | live provider demo / transactional surface with provider catalog semantics (`SEO`, `Контент`, `Пакеты`) | `menu.php`, `menu-content*.php` | `project-reference`, `public-layer-guidelines` | implemented |
| `/cart.php`, `/auth.php` | shared public adjunct pages remain reachable on provider domain | `cart.php`, `auth.php` | partial | implemented |

## 2. Tenant Public

| Route / feature | Current behavior | Source in repo | Doc coverage | Roadmap status |
|---|---|---|---|---|
| `/` | live restaurant-facing homepage, title `DOM \| Пицца, гриль и завтраки весь день` | `index.php`, `tenant_runtime.php`, `session_init.php` | `README`, `product-model`, `public-layer-guidelines`, `tenant-demo-seed` | implemented |
| `/menu.php` | live restaurant catalog and ordering surface (`Пицца`, `Гриль`, `Завтраки`, `Десерты`) | `menu.php`, tenant seed/runtime | same as above | implemented |
| `/cart.php`, `/auth.php` | reachable and tenant-branded adjunct public pages | `cart.php`, `auth.php` | partial | implemented |
| public entry config | tenant `/` is configurable per deployment: `homepage` keeps the restaurant landing, `menu` redirects straight to `/menu.php` | `index.php`, `session_init.php`, `api/save/brand.php`, `lib/tenant/launch-contract.php`, `scripts/tenant/launch.php` | `product-model`, `public-layer-guidelines`, `tenant-launch-checklist`, `project-reference` | implemented |

## 3. Auth / Account / Backoffice

| Route / feature | Current behavior | Source in repo | Doc coverage | Roadmap status |
|---|---|---|---|---|
| `/account.php` | live account shell, auth-gated | `account.php`, `account-header.php` | `project-reference` | implemented |
| `/help.php` | live in-app helper for `employee/admin/owner` | `help.php` | `backoffice-role-helpers`, `menu-capabilities-presentation`, `project-reference` | implemented |
| `/admin-menu.php` | live admin catalog/branding/files/payments surface | `admin-menu.php`, `js/admin-menu-page.js` | `project-reference`, `tenant-launch-checklist`, helper docs | implemented |
| `/owner.php` | live owner analytics surface | `owner.php`, `js/owner.min.js` | `project-reference`, helper docs | implemented |
| `/employee.php` | live employee queue / payment / QR flow | `employee.php`, `js/employee-*.js` | `project-reference`, helper docs | implemented |
| `/customer_orders.php` | live order-history surface | `customer_orders.php` | partial | implemented |
| `/qr-print.php` | live QR print surface | `qr-print.php` | partial | implemented |
| reviews / feedback loop | completed-order tracker shows a 1–5 star submission block; owner page has read-only "Отзывы" tab with last 50 entries; 5-star submissions surface a Google review deep-link when `google_review_url` is set | `order-track.php`, `api/save/review.php`, `css/reviews.css`, `js/reviews.js`, `owner.php`, `sql/reviews-migration.sql`, `db.php::createReview/getRecentReviews/getReviewByOrderId` | `reviews` | implemented |
| table reservations | customer form on `/reservation.php` (linked from header), week-long staff board in `employee.php` "Брони" tab with status transitions, Telegram inline-keyboard accept/reject, mobile API and session endpoints both supported | `sql/reservations-migration.sql`, `db.php::createReservation/getReservationById/getReservationsByRange/getUpcomingReservationsByUser/updateReservationStatus/checkTableAvailable`, `api/v1/reservations/*`, `api/reservations/create.php`, `api/reservations/update-status.php`, `reservation.php`, `employee.php`, `partials/employee_account_sections.php`, `js/reservation-form.js`, `js/employee-reservations.js`, `css/reservation-page.css`, `css/employee-reservations.css`, `telegram-notifications.php::sendReservationToTelegram`, `telegram-webhook.php`, `tests/ReservationsTest.php` | `reservations` | implemented |
| outgoing webhooks | admin CRUD at `/admin-webhooks.php` (list, create, toggle active, rotate secret, delete, history); HMAC-signed POST to subscriber URLs with exponential-backoff retries via `scripts/webhook-worker.php`; event catalogue covers `order.created`, `order.ready`, and the full `reservation.*` lifecycle | `sql/webhooks-migration.sql`, `db.php::listWebhooks/getActiveWebhooksForEvent/createWebhook/updateWebhook/deleteWebhook/enqueueWebhookDelivery/claimDueWebhookDeliveries/markWebhookDelivered/markWebhookFailed/getRecentWebhookDeliveries`, `lib/WebhookDispatcher.php`, `api/save-webhook.php`, `scripts/webhook-worker.php`, `admin-webhooks.php`, `js/admin-webhooks.js`, `css/admin-webhooks.css`, hook points in `create_new_order.php` / `api/reservations/create.php` / `api/v1/reservations/create.php` / `api/reservations/update-status.php` / `kds-action.php` | `webhook-integration` | implemented |
| kitchen display system (KDS) | per-station live board at `/kds/index.php` with one-shot session picker, SSE feed, action buttons (`Начать` / `Готово`); admin panel at `/admin-kitchen.php` with station CRUD and item-to-station routing matrix; auto-routing hook on `order.created`; `order.ready` webhook + Telegram ping when all non-cancelled slots are ready | `sql/kds-migration.sql`, `db.php::listKitchenStations/getKitchenStationById/saveKitchenStation/deleteKitchenStation/getMenuItemStations/setMenuItemStations/routeOrderItemsToStations/getKdsBoardForStation/advanceKdsItemStatus/isOrderFullyReady/getKdsLastUpdateTs`, `kds.php`, `kds-sse.php`, `kds-action.php`, `admin-kitchen.php`, `api/save-kitchen-station.php`, `js/kds.js`, `js/admin-kitchen.js`, `css/kds.css`, `css/admin-kitchen.css`, hook points in `create_new_order.php` / `api/v1/orders/create.php`, `tests/KdsTest.php`, account-header link added | `kds` | implemented |
| inventory MVP | ingredient stock + recipes + append-only `stock_movements` audit log, suppliers contact book, auto-deduction hook on `order.created` (aggregates duplicate dishes into one UPDATE), throttled low-stock alerts (Telegram + `inventory.stock_low` webhook), `/admin-inventory.php` with inline editing + `±` adjust + history drawer, per-menu-item recipe editor embedded in `/admin-menu.php` | `sql/inventory-migration.sql`, `db.php::listIngredients/getIngredientById/saveIngredient/archiveIngredient/restoreIngredient/adjustIngredientStock/listSuppliers/saveSupplier/getRecipeForMenuItem/setRecipeForMenuItem/deductIngredientsForOrder/listLowStockIngredients/markIngredientsAlerted/getStockMovementsForIngredient`, `admin-inventory.php`, `api/save-inventory.php`, `js/admin-inventory.js`, `js/admin-recipe.js`, `css/admin-inventory.css`, `css/admin-recipe.css`, recipe section in `admin-menu.php`, hook points in `create_new_order.php` / `api/v1/orders/create.php`, `tests/InventoryTest.php` | `inventory` | implemented |
| loyalty program | tiered cashback (tiers configurable per tenant) + promo codes (% or fixed ₽), `/admin-loyalty.php` inline editors for tiers and codes, customer widget on `/account.php?tab=profile` with balance / tier / progress-to-next / last 10 history rows, `POST /apply-promo.php` pure read-and-math endpoint, shared `cleanmenu_on_order_paid()` hook in `payment-webhook.php` + `api/checkout/cash-payment.php` dispatches `payment.received` webhook and accrues points idempotently per (user, order) | `sql/loyalty-migration.sql`, `db.php::listLoyaltyTiers/saveLoyaltyTier/archiveLoyaltyTier/resolveTierForSpent/getOrCreateLoyaltyAccount/accrueLoyaltyPoints/redeemLoyaltyPoints/getUserLoyaltyState/getUserLoyaltyHistory/listPromoCodes/savePromoCode/archivePromoCode/evaluatePromoCode/incrementPromoCodeUsage`, `admin-loyalty.php`, `api/save-loyalty.php`, `apply-promo.php`, `partials/account_loyalty_card.php`, `js/admin-loyalty.js`, `css/admin-loyalty.css`, `css/loyalty-card.css`, hooks in `payment-webhook.php` / `api/checkout/cash-payment.php`, `tests/LoyaltyTest.php` | `loyalty` | implemented |
| enhanced analytics (v2) | new tab `/owner.php?tab=analytics-v2` with four cards (dish margins / cohort retention / 7×24 heatmap / EWMA forecast), single `/api/analytics-v2.php?action=bundle` endpoint, vanilla canvas charts (no external JS deps, strict CSP preserved), MySQL 8 `JSON_TABLE` + CTE queries in `db.php` | `db.php::getDishMargins/getCustomerCohorts/getHourlyHeatmap/forecastNextWeekRevenue`, `api/analytics-v2.php`, `js/owner-analytics-v2.js`, `css/owner-analytics-v2.css`, tab content appended to `owner.php`, `tests/AnalyticsV2Test.php` | `analytics` | implemented |
| multi-location (MVP) | `locations` table + `location_id` columns on `orders`, `menu_items`, `reservations` (all nullable for backward-compat), `/admin-locations.php` CRUD with 30-day revenue summary and legacy "Без локации" bucket, soft-delete on deactivation | `sql/multi-location-migration.sql`, `db.php::listLocations/getLocationById/saveLocation/deleteLocation/getOrdersByLocationSummary`, `admin-locations.php`, `api/save-location.php`, `js/admin-locations.js`, `css/admin-locations.css`, `tests/MultiLocationTest.php` | `multi-location` | implemented |
| internal shell normalization | shared shell primitives now cover section heads, KPI cards and lifecycle badges; account pages also require version/update notices to stay non-blocking relative to shell controls | account/admin/owner/employee/cart/qr-print stack, `css/admin-menu-polish.css`, `css/version.min.css` | `ux-ui-improvement-roadmap`, `order-lifecycle-contract`, `project-reference` | partial |

## 4. Ops / Diagnostics

| Route / feature | Current behavior | Source in repo | Doc coverage | Roadmap status |
|---|---|---|---|---|
| `/monitor.php` | unauthenticated `302 -> auth.php`, auth-gated monitoring page | `monitor.php`, `lib/ops/monitor-page.php` | partial before audit | implemented |
| `/opcache-status.php` | unauthenticated `302 -> auth.php`, auth-gated OPcache page | `opcache-status.php`, `lib/ops/opcache-status-page.php` | documented in security docs | implemented |
| `/clear-cache.php?scope=server` | returns `405`, no public server-reset behavior | `clear-cache.php`, `lib/ops/clear-cache-endpoint.php` | partial before audit | implemented |
| `/file-manager.php?action=get_fonts` | unauthenticated `302 -> auth.php`, auth-gated JSON surface | `file-manager.php`, `lib/admin/file-manager-endpoint.php` | partial before audit | implemented |
| modularized ops/admin endpoints | root URLs remain stable while logic moved to `lib/ops/*` and `lib/admin/*` | wrappers + delegated modules | partial before audit | implemented |

## 5. API / Mobile Surface

| Route / feature | Current behavior | Source in repo | Doc coverage | Roadmap status |
|---|---|---|---|---|
| `/api/v1/menu.php`, auth, orders, push, geocode | implemented; OpenAPI validation passes | `api/v1/*`, `docs/openapi.yaml` | `project-reference`, `api-smoke`, OpenAPI | implemented |
| `api/v1/bootstrap.php` | internal API bootstrap helper, not public contract endpoint | `api/v1/bootstrap.php` | missing before audit | implemented internal helper |
| mobile wrapper | buildable but still provider-centric (`menu.labus.pro/menu.php`) | `mobile/*`, `capacitor-wrapper.md` | current docs already mark gap | partial |

## 6. Integrations / Callbacks / Webhooks

| Route / feature | Current behavior | Source in repo | Doc coverage | Roadmap status |
|---|---|---|---|---|
| Google / VK / Yandex OAuth | implemented in code, runtime-config dependent | `*oauth*.php`, `lib/OAuth*.php` | OAuth setup docs | partial |
| payment return / webhook / link generation | present in repo, repo-first audited only | `payment-*.php`, `generate-payment-link.php`, `api/checkout/cash-payment.php` | scattered / partial | partial |
| Telegram webhook / notifications | present in repo, repo-first audited only | `telegram-*.php` | weak explicit coverage | partial |
| SSE / long-poll | live runtime path remains `orders-sse.php` + `ws-poll.php` | `orders-sse.php`, `ws-poll.php` | `project-reference`, security/docs | implemented |

## Docs-First Findings

1. `deployment-workflow` was stale about production deploy branch strategy and needed release-branch aware commands.
2. `project-reference` under-described current ops/admin utility endpoints and integration entrypoints.
3. `ux-ui-improvement-roadmap` still treated the address/map-link track as if the model itself were open, while the real remaining gap is QA/validation.
4. `security` docs did not fully capture the current auth-gated state of `monitor.php` and `file-manager.php`.
5. `api/v1/bootstrap.php` existed in code but was undocumented as an internal helper.

## Current Backlog Produced By This Audit

- keep the unified internal shell contract stable and covered by smoke/manual acceptance
- keep launch automation and launch artifact generation aligned with real deployment operations
- document payment and Telegram integration surfaces more explicitly
- execute host-level security rollout for firewall, SSH/fail2ban, and patch cadence using the new repo-owned scripts

## 7. Planned Feature Surface (Phases 6-9, 2026-04 → 2027-04)

This section tracks features not yet built but explicitly committed via [product-vision-2027.md](./product-vision-2027.md) and [project-improvement-roadmap.md](./project-improvement-roadmap.md). Rows graduate into the sections above as tracks ship.

### Phase 6 — Restaurant Operations Core (Q2 2026) — ✅ SHIPPED

All five tracks (KDS, Inventory, Loyalty, Analytics v2, Multi-location) now appear in the "Implemented" sections above. Phase 6 is closed at the code layer; operational backlog items (migrations, cron setup, per-tenant rollout) tracked separately in [product-improvement-roadmap.md](./project-improvement-roadmap.md).

### Phase 7 — Platform & Integration (Q3 2026)

| Route / feature | Intended behavior | Planned files | Doc target | Roadmap status |
|---|---|---|---|---|
| iiko adapter | Two-way sync for menu / orders / stop-list / stock, cron-based reconciliation, conflict rules (iiko as source of truth for prices) | `lib/integrations/Iiko.php`, `scripts/integrations/iiko-sync.php`, `admin/integrations.php` tab | `integrations/iiko.md` (new) | **planned (deferred — explicit user decision Phase 13)** |
| 54-ФЗ фискальный | Electronic receipt emission on `order.paid` via АТОЛ Онлайн (alt: Evotor Chek Online) | `lib/Fiscal/AtolOnline.php`, `lib/OrderPaidHook.php` `cleanmenu_emit_fiscal_receipt`, `scripts/fiscal-receipt-worker.php`, `orders.fiscal_receipt_uuid`/`url`, `owner.php?tab=fiscal` | [`docs/fiscal-54fz.md`](fiscal-54fz.md) | **Implemented** (scaffold + admin UI, cron `*/2`) |
| full i18n | `/locales/{ru,en,kk}.json`, `lib/I18n.php` with `t()`, customer-facing pages first | `lib/I18n.php`, `locales/*.json`, `scripts/i18n-extract-strings.php`; surface migration ongoing | [`docs/i18n.md`](i18n.md) | **Implemented (helper + 80 keys; surface migration progressive)** |
| staff management | Shifts, time-tracking, tip distribution, swap-requests, payroll CSV | `sql/staff-management-migration.sql` + `sql/staff-v2-migration.sql`, `shifts`/`time_entries`/`tip_splits`/`shift_swap_requests` tables, `admin/staff.php`, `api/shift-swap-action.php`, `scripts/payroll-export.php` | [`docs/staff-v2.md`](staff-v2.md) | **Implemented** (v2 UI shipped 13A.2) |
| advanced payments | Split bill per seat / per item / equal share, multi-payer YK reconciliation | `group.php` payment block, `api/group-create-payment-intent.php`, `group_payment_intents` table, `payment-webhook.php` group-intent branch | [`docs/group-split-bill.md`](group-split-bill.md) | **Implemented** (UI shipped 13A.1) |

### Phase 8 — Growth & Retention (Q4 2026)

| Route / feature | Intended behavior | Planned files | Doc target | Roadmap status |
|---|---|---|---|---|
| marketing automation | Email / SMS / Push campaigns with trigger scenarios (abandoned cart, birthday, win-back) | `admin/marketing.php`, `api/save-campaign.php`, `marketing_campaigns`/`marketing_sends`/`marketing_segments`, `scripts/marketing-worker.php` | `marketing-automation.md` (planned for full doc) | **Implemented** |
| AI recommendations | Smart upsell in the cart, menu optimization hints in owner reports, demand forecast | `lib/ai/Recommender.php`, possibly external OpenAI / local model proxy, recommendations cache table | `ai-recommendations.md` (new) | planned |
| group ordering | Multi-guest single-table flow, each guest adds items via per-seat QR, consolidated or split bill | `group.php`, `api/save-group-order.php`, `api/group-create-payment-intent.php`, `group_orders`/`group_order_items`/`group_payment_intents` | [`group-split-bill.md`](group-split-bill.md) | **Implemented** |
| waitlists | "Встать в очередь" form when fully booked, SMS ping on seat available | `admin/waitlist.php`, `api/save-waitlist.php`, `waitlist_entries` table | `waitlists.md` (planned) | **Implemented** |
| review moderation | Owner replies to reviews, publication of best reviews on tenant site, moderation via Telegram | `reviews.reply_text` column, `owner.php?tab=reviews` write UI, published flag | [`reviews.md`](reviews.md) | **Implemented** |

### Phase 9 — Enterprise & Platform (Q1 2027)

| Route / feature | Intended behavior | Planned files | Doc target | Roadmap status |
|---|---|---|---|---|
| SaaS billing engine | Starter/Pro/Enterprise plans, usage-based billing, 14-day trial, Stripe/Paddle | `lib/billing/*`, `subscriptions`/`invoices` tables, `provider/billing/*` admin routes | `billing.md` (new) | planned |
| developer platform | Public API with rate-limits + API keys, JS/Python SDKs, extension marketplace, Zapier | `api/public/v1/*`, `api_keys` table, `sdk/` directory, marketplace UI | `developer-platform.md` (new) | planned |
| compliance pack | GDPR + 152-ФЗ (data export / right to delete), ЕГАИС for alcohol, Меркурий for meat/fish, 2FA for admin, audit log | `lib/compliance/*`, `audit_log` table, `admin-security.php` | `compliance.md` (new) | planned |
| multi-region / HA | DB replication, read replicas for analytics, CDN for assets, automatic failover | deployment-level changes, `deploy/` updates, runbook for failover | `deploy/multi-region.md` (new) | planned |
| onboarding 2.0 | Template library (fast-food / fine-dining / cafe / bar / pizzeria), interactive demo, in-app help center, chat support | `scripts/tenant/templates/*`, `onboarding.php` overhaul, `help.php` knowledge-base | `onboarding-2.md` (new) | planned |

Each planned row carries an explicit doc target so the docs-drift guard (see [pre-push hook](../.githooks/pre-push)) will flag if a track ships without its documentation.
