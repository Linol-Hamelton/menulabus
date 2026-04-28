# Project Reference

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-04-12`
- Verified against published pages: `https://menu.labus.pro/`, `https://test.milyidom.com/`, `https://test.milyidom.com/menu.php`
- Current implementation notes:
  - Hostname-aware runtime and control-plane tenant resolution are implemented.
  - Provider and tenant public surfaces are live.
  - Branding is settings-driven, including separate address text and dedicated map URL fields.
  - Tenant public entry is now settings-driven via `public_entry_mode`.
  - Auth-gated ops/admin utility endpoints now include `monitor.php`, `opcache-status.php`, `clear-cache.php`, and `file-manager.php` with stable root URLs and delegated module implementations.
  - Stale-order cleanup now has both UI and CLI operator flows.
  - Tenant go-live is scriptable on the target host through `scripts/tenant/go-live.sh`.
  - Shared visual polish is now delivered through `css/ui-ux-polish.css`, while post-release browser regression captures deterministic desktop/mobile visual sign-off for public, internal, and ops surfaces.
  - Menu-catalog spacing now keeps the discovery/search strip lightweight and reduces dead space between the last visible catalog cards, footer copy, and the bottom dock.
  - Account pages may present release/version information through `js/version-checker.min.js`, but the shared account shell contract now requires that update notices stay non-blocking and not cover the account chrome.
  - The account header action row on narrow screens now stacks as a normal shell section instead of forcing a horizontally scrolling action strip.
  - The shared tab-rail contract now keeps `menu-tabs-container` docked edge-to-edge at the bottom of the viewport across provider/tenant menu and internal shell surfaces, with a full-width plate, zero rail radius, centered desktop alignment, and horizontal-scroll mobile/tablet alignment. Owner report tabs keep a separate owner-specific presentation layer in `css/owner-styles.min.css` without redefining the shared rail geometry.

## 1. Project Summary

`Menu Labus` is a white-label PHP-based restaurant menu and ordering platform.

The same codebase serves two modes:

- `provider` deployment on `menu.labus.pro` for B2B promotion and demo flows
- `tenant` deployments on client domains for real restaurant public sites and ordering

The platform includes:

- public menu and order flow
- role-based backoffice (`owner`, `admin`, `employee`, `customer`)
- mobile-oriented REST API (`/api/v1/*`)
- realtime order status updates (SSE / long-poll)
- security, deploy, and launch runbooks

Primary host model today: shared server with Nginx, PHP-FPM, MySQL, and optional Redis-backed cache helpers.

## 2. Tenant Isolation Model

Hard rule:

- one client = one separate database

Recommended DB naming:

- `menu_<brand_slug>`

Isolation assumptions:

- each client has its own DB, brand settings, users, orders, and menu data
- provider marketing content must not leak into tenant domains
- code is shared, production data is not

Current implementation:

- control-plane tables resolve tenant by hostname
- tenant seed, provisioning, and launch scripts target isolated tenant databases

## 3. Runtime Stack

- PHP application code
- MySQL persistence
- cache abstractions and optional Redis-backed helpers
- Nginx frontend with FastCGI and selective cache/microcache configuration
- PHP-FPM with documented pool-split templates (`web` / `api` / `sse`)
- PWA shell and push-related code paths
- Composer-managed third-party libraries under `vendor/` for the Web Push pipeline (Minishlink WebPush + transitive Symfony / web-token / Guzzle deps). PHPMailer is currently mid-migration: it is declared in [composer.json](../composer.json) (`phpmailer/phpmailer ^6.10`) **and** still ships as a vendored copy under `phpmailer/`. [mailer.php](../mailer.php) loads the Composer autoloader when `vendor/composer/autoload_real.php` is present and falls back to the vendored copy otherwise. Phase 2 of the migration (removing the vendored `phpmailer/` directory) is gated on every deploy host running `composer install` reliably — see audit B.10/E.5.2.

## 4. Request Architecture

### 4.1 Web context

- browser requests public pages (`/`, `/menu.php`, account and backoffice pages)
- session/cookie auth and CSRF/CSP handling are initialized by `session_init.php`

### 4.2 API context

- API endpoints live in `api/v1/*`
- mobile/API auth is bearer-token based, not cookie-session dependent

### 4.3 Realtime updates

- active paths: `/orders-sse.php` and `/ws-poll.php`
- long-lived requests should be isolated from normal web/API traffic

## 5. Public Entry Points

### 5.1 Provider deployment

- `/` → provider B2B landing (`index.php`)
- `/index.php` → provider landing
- `/menu.php` → demo menu / transactional surface

### 5.2 Tenant deployment

Public + customer flow:

- `/` → tenant public entry; controlled by `public_entry_mode` (`homepage` or `menu`)
- `/menu.php` → primary transactional menu
- `/cart.php` → cart + tips UI (see [tips.md](./tips.md))
- `/create_new_order.php` → order create endpoint for authenticated customers
- `/create_guest_order.php` → guest order create endpoint
- `/order-track.php` → customer-facing order status page; on completed orders, renders the 1–5 star feedback submission block (see [reviews.md](./reviews.md))
- `/order-status.php` → short-poll status fetch used by the tracker
- `/api/save/review.php` → customer review submission endpoint (CSRF + session-scoped ownership; append-only). See [reviews.md](./reviews.md)
- `/orders-sse.php` → server-sent events stream for live updates
- `/ws-poll.php` → long-poll fallback for live updates
- `/payment-return.php` → redirect landing after YooKassa / T-Bank payment
- `/payment-webhook.php` → provider webhook receiver (YooKassa + T-Bank). See [payments-integration.md](./payments-integration.md)
- `/confirm-cash-payment.php` → staff cash confirmation (idempotent). Token via header `Idempotency-Key`
- `/generate-payment-link.php` → staff "Waiter link" generator (admin/owner/employee)
- `/telegram-webhook.php` → bot callback handler for accept/reject buttons. See [telegram-bot-setup.md](./telegram-bot-setup.md)
- `/telegram-notifications.php` → library include, not a standalone endpoint
- `/download-sample.php`, `/qr.php` → auxiliary guest surfaces
- `/manifest.php` → dynamic PWA manifest, rewritten from `/manifest.webmanifest`. See [pwa-and-push.md](./pwa-and-push.md)
- `/sw.js`, `/offline.html` → service worker + offline fallback
- `/dynamic-fonts.php`, `/auto-fonts.php` → brand font delivery

## 6. Backoffice

Auth and account:

- `/auth.php`, `/logout.php`, `/password-reset.php`, `/verify-email.php`
- `/google-oauth-start.php`, `/google-oauth-callback.php`
- `/vk-oauth-start.php`, `/vk-oauth-callback.php`
- `/yandex-oauth-start.php`, `/yandex-oauth-callback.php`
- `/account.php`
- `/help.php`

Owner / admin / employee surfaces:

- `/owner.php` — dashboard, reports, ABC analysis, read-only "Отзывы" tab (last 50, see [reviews.md](./reviews.md))
- `/employee.php` — live order board, cash confirmation
- `/admin-menu.php` — catalog editor, categories, modifier editor (see [modifiers.md](./modifiers.md))
- `/qr-print.php` — table QR code print sheet
- `/stale-order-cleanup.php` — stale-order cleanup UI
- `/onboarding.php` — 5-step wizard for first-run brand setup. See Section 4 of [tenant-launch-checklist.md](./tenant-launch-checklist.md)

Settings writers (CSRF + role-gated):

- `/api/save/brand.php`, `/api/save/colors.php`, `/api/save/fonts.php`, `/save-contact.php`
- `/api/save/payment-settings.php` — YooKassa + T-Bank credentials allowlist (see [payments-integration.md](./payments-integration.md))
- `/save-telegram-settings.php` — `telegram_chat_id` per tenant
- `/toggle-available.php` — stop-list toggle; also fires Telegram alert
- `/update_order_status.php` — employee state-machine transition, also triggers push (see [pwa-and-push.md](./pwa-and-push.md))
- `/api/save-modifiers.php` — modifier group/option CRUD
- `/api/save-push-subscription.php` — push subscription persistence

## 7. Operations and Diagnostics

Admin/ops web endpoints (auth-gated, root URLs stable, implementations delegated under `lib/ops/` or `lib/admin/`):

- `/monitor.php` → `lib/ops/monitor-page.php`
- `/opcache-status.php` → `lib/ops/opcache-status-page.php`
- `/clear-cache.php` → `lib/ops/clear-cache-endpoint.php`
- `/file-manager.php` → `lib/admin/file-manager-endpoint.php`

Release + API scripts (CLI only, blocked from web by `location ^~ /scripts/ { return 404; }`):

- `scripts/api-smoke-runner.php` — mobile API v1 end-to-end smoke (see [api-smoke.md](./api-smoke.md))
- `scripts/api-metrics-report.php` — API traffic / latency summary
- `scripts/validate-openapi.mjs` — OpenAPI contract validator (runs in `pre-push`)
- `scripts/check-mojibake.php` — CP1251/Latin-1 encoding scanner
- `scripts/fix_encoding.php` — one-shot encoding repair tool
- `scripts/docs/check-doc-drift.sh` — docs freshness check (runs in `pre-push`)

Orders / maintenance:

- `scripts/orders/cleanup-stale.php` — CLI counterpart to `/stale-order-cleanup.php`
- `scripts/db/backfill-order-items.php` — one-shot `order_items` backfill (see [db/backfill-order-items.md](./db/backfill-order-items.md))

Tenant lifecycle:

- `scripts/tenant/launch.php` — new tenant provisioning + schema bootstrap + seed + launch artifact
- `scripts/tenant/go-live.sh` — one-shot server-side go-live wrapper around `launch.php` (see [deploy/custom-domain-go-live.md](./deploy/custom-domain-go-live.md))
- `scripts/tenant/provision.php` — lower-level DB/runtime mapping
- `scripts/tenant/seed.php`, `scripts/tenant/seed_profiles.php`, `scripts/tenant/data/restaurant_demo.php` — demo content seed profiles
- `scripts/tenant/smoke.php` — provider+tenant post-deploy smoke

Perf / regression:

- `scripts/perf/load_test.py` — synthetic load driver
- `scripts/perf/run-baseline.sh` — perf baseline capture
- `scripts/perf/phase2-port-inventory.sh` — Phase 2 network hardening inventory
- `scripts/perf/checkout-error-top.php` — top checkout errors report
- `scripts/perf/security-smoke.sh`, `scripts/perf/security-smoke-daily.sh`, `scripts/perf/install-security-smoke-cron.sh`
- `scripts/perf/post-release-regression.cjs`, `scripts/perf/post-release-regression.sh` — Playwright regression, wired into `post-merge` and `go-live.sh`
- `scripts/perf/full-ui-audit.cjs` — exhaustive companion runner for full production audits across roles, routes, viewports, and order lifecycle

Security:

- `scripts/security/capture-baseline.sh` — HTTP header + response baseline capture for go-live diffs
- `scripts/security/apply-network-policy.sh` — network-policy apply helper
- `scripts/security/harden-ssh-fail2ban.sh` — SSH + fail2ban hardening
- `scripts/security/monthly-review.sh` — monthly security review helper

Deploy templates:

- `deploy/nginx/custom-domain-http-bootstrap.conf` — HTTP-only vhost for ACME
- `deploy/nginx/custom-domain-template.conf` — full HTTPS vhost template
- `deploy/nginx/server-locations-pool-split.conf` — shared location fragment for the FPM pool split
- `nginx-optimized.conf` — reference config

## 8. API v1 Surface

Current API files:

- internal helper: `/api/v1/bootstrap.php` (not a public contract endpoint)
- `/api/v1/menu.php`
- `/api/v1/geocode.php`
- `/api/v1/auth/login.php`
- `/api/v1/auth/logout.php`
- `/api/v1/auth/me.php`
- `/api/v1/auth/refresh.php`
- `/api/v1/auth/oauth/google.php`
- `/api/v1/profile/phone.php`
- `/api/v1/orders/create.php`
- `/api/v1/orders/status.php`
- `/api/v1/push/subscribe.php`

Contract file:

- [`docs/openapi.yaml`](./openapi.yaml)

## 9. Branding Surface

Settings-driven surface currently includes:

- app name
- tagline
- description
- contact phone
- contact address
- social links
- logo
- favicon
- colors
- fonts
- custom domain
- hide-provider-branding flag
- public-entry mode
- Google review deep-link URL (`google_review_url`) — surfaces on 5-star review submissions (see [reviews.md](./reviews.md))

Current implementation gap:

- runtime and public UI now expose separate address text and dedicated map URL fields
- tenant launch acceptance is now explicit and artifact-driven; remaining live sign-off depends on release ownership after deploy

## 10. Integrations and Callbacks

- OAuth start/callback routes:
  - `/google-oauth-start.php`, `/google-oauth-callback.php`
  - `/vk-oauth-start.php`, `/vk-oauth-callback.php`
  - `/yandex-oauth-start.php`, `/yandex-oauth-callback.php`
- payment-related routes:
  - `/generate-payment-link.php`
  - `/confirm-cash-payment.php`
  - `/payment-return.php`
  - `/payment-webhook.php`
- messaging / external callbacks:
  - `/telegram-webhook.php`
  - `/telegram-notifications.php`

These surfaces are implemented in code but are audited repo-first unless a safe live verification path exists.

## 11. Security and Deploy References

- [`docs/security-hardening-roadmap.md`](./security-hardening-roadmap.md)
- [`docs/security-smoke-checklist.md`](./security-smoke-checklist.md)
- [`docs/deployment-workflow.md`](./deployment-workflow.md)
- [`docs/deploy/nginx-pool-split.md`](./deploy/nginx-pool-split.md)
- [`docs/deploy/php-fpm-pool-split.md`](./deploy/php-fpm-pool-split.md)

## 12. Development and Perf Utilities

- `scripts/perf/load_test.py`
- `scripts/perf/run-baseline.sh`
- `scripts/perf/phase2-port-inventory.sh`
- `scripts/perf/security-smoke.sh`
- `scripts/perf/security-smoke-daily.sh`
- `scripts/perf/install-security-smoke-cron.sh`
- `scripts/perf/checkout-error-top.php`
- `scripts/perf/post-release-regression.cjs`
- `scripts/perf/post-release-regression.sh`
- `scripts/security/capture-baseline.sh`
- `scripts/security/apply-network-policy.sh`
- `scripts/security/harden-ssh-fail2ban.sh`
- `scripts/security/monthly-review.sh`
- `scripts/docs/check-doc-drift.sh`

## 13. Documentation Policy

- active docs stay under `docs/`
- API contract source of truth: `docs/openapi.yaml`
- product model source of truth: `docs/product-model.md`
- tenant launch runbook: `docs/tenant-launch-checklist.md`
- current audit baseline: `docs/feature-audit-matrix.md`

## 14. Shared Shell Notes

- `admin-menu.php` now uses one shared inline shell-width contract for the account header actions, the admin tab rail, and the main editor/catalog cards.
- That contract lives in `css/ui-ux-polish.css` and keeps the same left/right offsets on desktop, tablet, and mobile without changing markup or button-level styling.
- In the `dishes` workspace, the catalog list now renders before the update/editor card so operators see the active catalog immediately after the top admin tabs.
- In the `admin-menu` catalog desktop table, the actions column now switches to a centered stacked-button layout in the `769px–978px` range so `Редактировать` and `Архивировать` stay readable instead of collapsing into an awkward inline cluster.
- `admin-modifiers.js` now resolves CSRF the same way as the other admin JS surfaces: `meta[name=\"csrf-token\"]` first, then hidden `input[name=\"csrf_token\"]`, then `body[data-csrf-token]`.
- `admin-menu.php` now versions `admin-modifiers.js` by filemtime instead of the coarse app version so CSP-safe immutable caching does not keep stale edit-mode JS after deploy.
