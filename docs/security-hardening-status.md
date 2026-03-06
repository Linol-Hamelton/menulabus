# Security Hardening Status

## Implemented in repository

- Added roadmap document with preventive rollout model:
  - `docs/security-hardening-roadmap.md`
- Added change-log template:
  - `docs/security-change-log-template.md`
- Added smoke checklist:
  - `docs/security-smoke-checklist.md`
- Added server command runbook:
  - `docs/security-phase-commands.md`
- Added automation helper script:
  - `scripts/perf/security-smoke.sh`
- Extended deployment workflow with security rollout section:
  - `docs/deployment-workflow.md`
- Removed public diagnostic file:
  - `phpinfo.php` (deleted)
- Added defensive Nginx controls in `nginx-optimized.conf`:
  - `server_tokens off;`
  - `client_max_body_size 20m;`
  - `location = /phpinfo.php { return 404; }`
  - `location = /db-indexes-optimizer.php { return 404; }`
  - `location = /db-indexes-optimizer-v2.php { return 404; }`
  - `location = /order_updates.php { return 404; }`
  - `location = /scripts/api-metrics-report.php { return 404; }`
  - `location = /scripts/api-smoke-runner.php { return 404; }`
- Hardened PHP access/session points:
  - `opcache-status.php` now uses `require_auth.php` with admin-role guard
  - `clear-cache.php` session startup now enforces strict cookie flags
- Extended smoke automation:
  - `scripts/perf/security-smoke.sh` now validates locked endpoints and `clear-cache.php` header snapshot
  - `docs/security-smoke-checklist.md` updated accordingly
- Phase 1 reliability/conversion instrumentation implemented (pending prod rollout):
  - daily smoke runner with retention: `scripts/perf/security-smoke-daily.sh`
  - cron installer: `scripts/perf/install-security-smoke-cron.sh`
  - checkout error top report: `scripts/perf/checkout-error-top.php`
  - structured checkout error events: `lib/CheckoutErrorLog.php` + order-create endpoints
  - OpenAPI gate on push to `main`: `.githooks/pre-push`

## Requires manual server execution

- Firewall and port policy (`3306`, `21`, `8080`, `8443`) for menu-owned services only.
- SSH staged hardening and fail2ban rollout.
- Nginx/FastPanel production config apply + reload.
- Post-change observation and metrics comparison.

## Recommended next execution order

1. Deploy current commit to production.
2. Apply Nginx config in FastPanel and reload safely (`nginx -t && systemctl reload nginx`).
3. Run `docs/security-smoke-checklist.md` (or `bash scripts/perf/security-smoke.sh https://menu.labus.pro`) and save output to `/root/security-smoke-<UTC>.log`.
4. Verify admin flows (`clear-cache`, admin login, order updates via `/orders-sse.php`/`/ws-poll.php`).
5. Continue phase-by-phase from `docs/security-phase-commands.md`.

## Scope lock after Phase 2

- Confirmed by operations: do not modify Docker images/containers and network ports that belong to other sites/services on the shared host.
- Therefore, host-level firewall tightening for non-menu ports is excluded from this project scope.
- Continue with menu-only hardening steps (application/vhost level) to avoid cross-project impact.

## Production snapshot before next rollout (curl audit, 2026-03-06)

- `GET /order_updates.php` => `200`
- `GET /scripts/api-metrics-report.php` => `200`
- `GET /scripts/api-smoke-runner.php` => `200`
- `GET /opcache-status.php` => `200` with body `{"isLoggedIn":false}`
- `GET /clear-cache.php` => `200` with `Set-Cookie` observed without strict flags
- method probe on `/menu.php`: `TRACE => 405`, `PUT/DELETE/OPTIONS => 200` (deferred intentionally to avoid functional regressions)

Planned fix path: deploy current repository changes from this iteration, reload Nginx safely, run updated smoke, observe 30 minutes.
