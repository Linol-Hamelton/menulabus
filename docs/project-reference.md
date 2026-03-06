# Project Reference

## 1. Project Summary

`menu.labus.pro` is a PHP-based restaurant menu and ordering platform with:

- public web menu and order flow
- role-based backoffice (`owner`, `admin`, `employee`)
- mobile-oriented REST API (`/api/v1/*`)
- realtime order status updates (SSE/long-poll)
- security hardening runbooks and smoke checks

Primary host: shared server (FastPanel + Nginx + PHP-FPM + MySQL, optional Redis).

## 2. Runtime Stack

- PHP (project codebase, API, web pages)
- MySQL (main persistence)
- Redis/memory cache abstractions in code (`RedisCache.php`, query/cache helpers)
- Nginx frontend with FastCGI, microcache for selected routes
- PHP-FPM pool split: web / api / sse

## 3. Request Architecture

### 3.1 Web context

- Browser requests web pages (`/menu.php`, account/admin pages).
- Session/cookie auth and CSRF/CSP handling are initialized by `session_init.php` (web context).

### 3.2 API context

- API endpoints live in `api/v1/*` and use `api/v1/bootstrap.php`.
- API uses `LABUS_CTX='api'` lightweight init path.
- Mobile auth is token-based (`Authorization: Bearer ...`), not cookie-session dependent.

### 3.3 Realtime updates

- Active paths: `/orders-sse.php` and `/ws-poll.php`.
- They are routed to dedicated SSE PHP-FPM pool in optimized Nginx config.

### 3.4 Blocked legacy/internal endpoints

In production vhost config (`nginx-optimized.conf`), the following are intentionally blocked (`404`):

- `/phpinfo.php`
- `/db-indexes-optimizer.php`
- `/db-indexes-optimizer-v2.php`
- `/order_updates.php` (legacy)
- `/scripts/api-metrics-report.php` (internal CLI/reporting)
- `/scripts/api-smoke-runner.php` (internal CLI runner)

## 4. Key App Entry Points

### 4.1 Public/customer flow

- `/menu.php` (main entry)
- `/cart.php`
- `/create_new_order.php`, `/create_guest_order.php`
- `/order-track.php`, `/order-status.php`

### 4.2 Account/admin flow

- `/auth.php`, `/logout.php`, `/password-reset.php`
- `/account.php`, `/owner.php`, `/employee.php`, `/admin-menu.php`
- `/monitor.php` (operational dashboard)

### 4.3 Realtime and push

- `/orders-sse.php`
- `/ws-poll.php`
- `/api/v1/push/subscribe.php`

## 5. API v1 Surface (source of truth in OpenAPI)

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

Contract file: [`docs/openapi.yaml`](./openapi.yaml)

## 6. Security State (current)

Implemented controls include:

- strict security headers and CSP strategy
- explicit blocking of sensitive diagnostics/internal endpoints
- menu-only hardening scope for shared host safety
- security smoke script and runbook:
  - `scripts/perf/security-smoke.sh`
  - `docs/security-smoke-checklist.md`

Current security status and roadmap:

- [`docs/security-hardening-status.md`](./security-hardening-status.md)
- [`docs/security-hardening-roadmap.md`](./security-hardening-roadmap.md)

## 7. Deployment and Operations

Main deployment flow (Git pull on server):

- [`docs/deployment-workflow.md`](./deployment-workflow.md)

Infra templates/docs:

- [`docs/deploy/nginx-pool-split.md`](./deploy/nginx-pool-split.md)
- [`docs/deploy/php-fpm-pool-split.md`](./deploy/php-fpm-pool-split.md)

DB maintenance docs:

- [`docs/db/backfill-order-items.md`](./db/backfill-order-items.md)

## 8. Mobile Wrapper

Capacitor wrapper notes:

- [`docs/mobile/capacitor-wrapper.md`](./mobile/capacitor-wrapper.md)

## 9. Documentation Policy

- All project docs are kept under `docs/`.
- API contract source of truth: `docs/openapi.yaml`.
