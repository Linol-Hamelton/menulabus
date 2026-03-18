# Project Reference

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-17`
- Verified against published pages: `https://menu.labus.pro/`, `https://test.milyidom.com/`, `https://test.milyidom.com/menu.php`
- Current implementation notes:
  - Hostname-aware runtime and control-plane tenant resolution are implemented.
  - Provider and tenant public surfaces are live.
  - Branding is settings-driven, including separate address text and dedicated map URL fields.

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
- tenant seed and provisioning scripts target isolated tenant databases

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

- `/` => tenant public entry, currently capable of rendering a restaurant homepage
- `/menu.php` => primary transactional menu
- `/cart.php`
- `/create_new_order.php`, `/create_guest_order.php`
- `/order-track.php`, `/order-status.php`

Current implementation gap:

- tenant public entry is not configurable per deployment yet

## 6. Backoffice and Operations

- `/auth.php`, `/logout.php`, `/password-reset.php`
- `/account.php`
- `/owner.php`
- `/employee.php`
- `/admin-menu.php`
- `/monitor.php`
- `/qr-print.php`

## 7. API v1 Surface

Current API files:

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

## 8. Branding Surface

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

Current implementation gap:

- the product model expects `address + map link`
- current runtime and public UI now expose separate address text and dedicated map URL fields

## 9. Security and Deploy References

- [`docs/security-hardening-roadmap.md`](./security-hardening-roadmap.md)
- [`docs/security-smoke-checklist.md`](./security-smoke-checklist.md)
- [`docs/deployment-workflow.md`](./deployment-workflow.md)
- [`docs/deploy/nginx-pool-split.md`](./deploy/nginx-pool-split.md)
- [`docs/deploy/php-fpm-pool-split.md`](./deploy/php-fpm-pool-split.md)

## 10. Documentation Policy

- active docs stay under `docs/`
- historical snapshots move to `docs/archive/`
- API contract source of truth: `docs/openapi.yaml`
- product model source of truth: `docs/product-model.md`
- tenant launch runbook: `docs/tenant-launch-checklist.md`
