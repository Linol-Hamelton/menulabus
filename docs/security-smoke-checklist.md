# Security Smoke Checklist (Production)

## Implementation Status

- Status: `Implemented`
- Last reviewed: `2026-03-23`
- Verified against published pages: `https://menu.labus.pro/phpinfo.php`, `https://menu.labus.pro/order_updates.php`, `https://menu.labus.pro/monitor.php`, `https://menu.labus.pro/opcache-status.php`, `https://menu.labus.pro/file-manager.php?action=get_fonts`
- Current implementation notes:
  - This checklist validates currently deployed menu-only hardening, not future security phases.
  - Production `post-merge` now runs the same provider security smoke automatically after release pulls.

## 1. Core availability

```bash
curl -sS -o /dev/null -w "%{http_code}\n" https://menu.labus.pro/menu.php
curl -sS -o /dev/null -w "%{http_code}\n" https://menu.labus.pro/api/v1/menu.php
```

Expected: `200` for both.

## 2. Public endpoint exposure checks

```bash
for p in \
  /phpinfo.php \
  /db-indexes-optimizer.php \
  /db-indexes-optimizer-v2.php \
  /order_updates.php \
  /scripts/api-metrics-report.php \
  /scripts/api-smoke-runner.php
do
  echo "$p => $(curl -sS -o /dev/null -w "%{http_code}" "https://menu.labus.pro$p")"
done
```

Expected: `404` for all listed paths.

## 3. Security headers quick check

```bash
curl -sS -I https://menu.labus.pro/menu.php | egrep -i "strict-transport-security|content-security-policy|x-frame-options|x-content-type-options|referrer-policy|cross-origin"
curl -sS -I https://menu.labus.pro/api/v1/menu.php | egrep -i "cache-control|x-cache-status|x-content-type-options|x-frame-options"
```

Expected:

- `/menu.php` has strict security headers and CSP
- `/api/v1/menu.php` keeps expected cache and security headers

## 4. API cache/perf sanity

```bash
for i in {1..5}; do curl -sS -D - -o /dev/null https://menu.labus.pro/api/v1/menu.php | egrep -i "cache-control|x-cache-status"; done
```

Expected: stable `cache-control` and no abnormal status behavior.

## 5. Auth gate checks for ops/admin endpoints

```bash
curl -sS -I https://menu.labus.pro/monitor.php
curl -sS -I https://menu.labus.pro/opcache-status.php
curl -sS -I "https://menu.labus.pro/file-manager.php?action=get_fonts"
```

Expected:

- all listed endpoints return `302` redirect to `auth.php` when unauthenticated

## 6. Method guard check for `clear-cache.php?scope=server`

```bash
curl -sS -I "https://menu.labus.pro/clear-cache.php?scope=server"
```

Expected:

- `405 Method Not Allowed`

## 7. Session/cookie safety check for `clear-cache.php`

```bash
curl -sS -D - -o /dev/null https://menu.labus.pro/clear-cache.php | egrep -i "set-cookie|cache-control|pragma"
```

Expected:

- `cache-control` and `pragma` are present
- if `Set-Cookie` exists, it should include `HttpOnly`, `SameSite=Strict`, and `Secure` for HTTPS

## 8. Admin/business flow checks

Manual checks:

1. Admin login works.
2. CSV import with valid file works.
3. Create one test order and verify status endpoint.
4. SSE/poll path returns expected response.

## 9. Error and latency snapshot

Capture and compare with baseline:

- `5xx` count/rate
- p95 latency on `/menu.php` and `/api/v1/menu.php`
- CPU/RAM for `nginx`, `php-fpm`, `redis`, `mysql`

If any stop criterion is hit, rollback immediately.
