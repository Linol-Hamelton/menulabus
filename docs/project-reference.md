# Project Reference

## 1. Project Summary

`Menu Labus` is a white-label PHP-based restaurant menu and ordering platform.

The same codebase serves two modes:

- `provider` deployment on the company domain (`menu.labus.pro`) for B2B promotion and demo flows
- `tenant` deployments on client domains for real restaurant public sites and ordering

The platform includes:

- public menu and order flow
- role-based backoffice (`owner`, `admin`, `employee`, `customer`)
- mobile-oriented REST API (`/api/v1/*`)
- realtime order status updates (SSE/long-poll)
- security/deploy/runbook documentation

Primary host model today: shared server (FastPanel + Nginx + PHP-FPM + MySQL, optional Redis).

## 2. Tenant Isolation Model

Hard rule:

- one client = one separate database

Recommended DB naming convention:

- `menu_<brand_slug>`

Examples:

- `menu_labus_demo`
- `menu_kultura_bar`
- `menu_bon_pizza`

Isolation assumptions:

- each client has their own DB, brand settings, users, orders, and menu data
- provider marketing content must not leak into tenant domains
- code is shared, production data is not

## 3. Runtime Stack

- PHP (project codebase, API, public pages)
- MySQL (main persistence; separate DB per tenant)
- Redis/memory cache abstractions in code (`RedisCache.php`, cache helpers)
- Nginx frontend with FastCGI, selected caching/microcache
- PHP-FPM pool split: web / api / sse
- PWA + push notifications

## 4. Request Architecture

### 4.1 Web context

- browser requests public pages (`/menu.php`, `index.php`, account/admin pages)
- session/cookie auth and CSRF/CSP handling are initialized by `session_init.php`

### 4.2 API context

- API endpoints live in `api/v1/*` and use `api/v1/bootstrap.php`
- mobile auth is token-based (`Authorization: Bearer ...`), not cookie-session dependent

### 4.3 Realtime updates

- active paths: `/orders-sse.php` and `/ws-poll.php`
- long-lived requests should be isolated to the SSE pool

## 5. Public Entry Points

### 5.1 Provider deployment

- `/` => provider B2B landing
- `/index.php` => provider landing
- `/menu.php` => demo menu / transactional surface

### 5.2 Tenant deployment

- `/` => tenant public entry (`menu.php` directly or tenant homepage)
- `/menu.php` => main transactional menu
- `/cart.php`
- `/create_new_order.php`, `/create_guest_order.php`
- `/order-track.php`, `/order-status.php`

## 6. Backoffice and Operations

- `/auth.php`, `/logout.php`, `/password-reset.php`
- `/account.php`
- `/owner.php`
- `/employee.php`
- `/admin-menu.php`
- `/monitor.php`

## 7. API v1 Surface

Current API files (excluding bootstrap):

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

## 8. Branding and White-Label Surface

Brand-dependent public settings should come from tenant settings, not from hard-coded provider defaults.

The codebase already exposes a branding surface for:

- app name
- tagline
- description
- contact phone
- contact address / map link
- social links
- logo
- favicon
- colors
- fonts
- custom domain
- hide-provider-branding flag

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
- product-mode source of truth: `docs/product-model.md`
- tenant launch runbook: `docs/tenant-launch-checklist.md`
