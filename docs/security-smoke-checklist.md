# Security Smoke Checklist (Production)

Run after each security step.

## 1) Core availability

```bash
curl -sS -o /dev/null -w "%{http_code}\n" https://menu.labus.pro/menu.php
curl -sS -o /dev/null -w "%{http_code}\n" https://menu.labus.pro/api/v1/menu.php
```

Expected: `200` for both.

## 2) `phpinfo` exposure check

```bash
curl -sS -I https://menu.labus.pro/phpinfo.php | egrep -i "HTTP/|content-type|x-cache-status"
```

Expected: `404`.

## 3) Security headers quick check

```bash
curl -sS -I https://menu.labus.pro/menu.php | egrep -i "strict-transport-security|content-security-policy|x-frame-options|x-content-type-options|referrer-policy|cross-origin"
curl -sS -I https://menu.labus.pro/api/v1/menu.php | egrep -i "cache-control|x-cache-status|x-content-type-options|x-frame-options"
```

Expected:

- `/menu.php` has strict security headers and CSP.
- `/api/v1/menu.php` keeps expected cache behavior and headers.

## 4) API cache/perf sanity

```bash
for i in {1..5}; do curl -sS -D - -o /dev/null https://menu.labus.pro/api/v1/menu.php | egrep -i "cache-control|x-cache-status"; done
```

Expected: stable `cache-control` and no abnormal status behavior.

## 5) Admin/business flow checks

Manual checks:

1. Admin login works.
2. CSV import with valid file works.
3. Create one test order (site/API path), verify status endpoint.
4. SSE/poll endpoint returns expected response.

## 6) Error and latency snapshot

Capture and compare with baseline:

- `5xx` count/rate
- p95 latency on `/menu.php` and `/api/v1/menu.php`
- CPU/RAM for `nginx`, `php-fpm`, `redis`, `mysql`

If any stop criterion is hit, rollback immediately.
