# Project Reference

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-24`
- Verified against published pages: `https://menu.labus.pro/`, `https://test.milyidom.com/`, `https://test.milyidom.com/menu.php`
- Current implementation notes:
  - Hostname-aware runtime and control-plane tenant resolution are implemented.
  - Provider and tenant public surfaces are live.
  - Branding is settings-driven, including separate address text and dedicated map URL fields.
  - Tenant public entry is now settings-driven via `public_entry_mode`.
  - Auth-gated ops/admin utility endpoints now include `monitor.php`, `opcache-status.php`, `clear-cache.php`, and `file-manager.php` with stable root URLs and delegated module implementations.
  - Stale-order cleanup now has both UI and CLI operator flows.
  - Tenant go-live is scriptable on the target host through `scripts/tenant/go-live.sh`.

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

- `/` => provider B2B landing
- `/index.php` => provider landing
- `/menu.php` => demo menu / transactional surface

### 5.2 Tenant deployment

- `/` => tenant public entry, configurable as restaurant homepage or redirect to `/menu.php`
- `/menu.php` => primary transactional menu
- `/cart.php`
- `/create_new_order.php`, `/create_guest_order.php`
- `/order-track.php`, `/order-status.php`

## 6. Backoffice

- `/auth.php`, `/logout.php`, `/password-reset.php`
- `/account.php`
- `/help.php`
- `/owner.php`
- `/employee.php`
- `/admin-menu.php`
- `/qr-print.php`
- `/stale-order-cleanup.php`

## 7. Operations and Diagnostics

- `/monitor.php`
- `/opcache-status.php`
- `/clear-cache.php`
- `/file-manager.php`
- `scripts/api-smoke-runner.php`
- `scripts/api-metrics-report.php`
- `scripts/orders/cleanup-stale.php`
- `scripts/tenant/smoke.php`
- `scripts/tenant/launch.php`
- `scripts/tenant/go-live.sh`

These tools are retained as ops/security helpers and are not part of the normal public product surface.
Root URLs stay stable, while the implementation for `monitor.php` and `opcache-status.php`
is delegated to `lib/ops/monitor-page.php` and `lib/ops/opcache-status-page.php`.
`clear-cache.php` and `file-manager.php` follow the same wrapper pattern via
`lib/ops/clear-cache-endpoint.php` and `lib/admin/file-manager-endpoint.php`.

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
