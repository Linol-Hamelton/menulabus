# Security Change Log Template

Use one entry per production change step.

## Entry

- Date (UTC):
- Environment:
- Step/Phase:
- Owner:
- Change objective (single objective):
- Commit hash:
- Related config/file:
- Risk summary:
- Preventive checks (pre):
- Deployment command(s):
- Verification checks (post):
- Metrics delta (`5xx`, p95, error-rate):
- Observation window:
- Result: `success` | `rolled_back`
- Stop criteria triggered: `yes/no`
- Rollback action performed:
- Notes/next step:

---

## Entry (2026-03-05, Phase 1 completed)

- Date (UTC): 2026-03-05 21:55
- Environment: production (`menu.labus.pro`)
- Step/Phase: Phase 1 (critical quick wins)
- Owner: ops/admin
- Change objective (single objective): close public diagnostics surface (`phpinfo.php`) and deploy preventive security runbook artifacts
- Commit hash: `1cafc0c`
- Related config/file: `nginx-optimized.conf`, `phpinfo.php` (deleted), `docs/security-*`, `scripts/perf/security-smoke.sh`
- Risk summary: low-risk change; potential impact limited to diagnostics endpoint only
- Preventive checks (pre): `git pull --ff-only`, `nginx -t`
- Deployment command(s):
  - `runuser -u "$WEBUSER" -- git -C "$PROJECT" fetch --prune origin`
  - `runuser -u "$WEBUSER" -- git -C "$PROJECT" checkout main`
  - `runuser -u "$WEBUSER" -- git -C "$PROJECT" pull --ff-only origin main`
  - `nginx -t && systemctl reload nginx`
- Verification checks (post):
  - `curl -I https://menu.labus.pro/phpinfo.php` => `HTTP/2 404`
  - `bash scripts/perf/security-smoke.sh https://menu.labus.pro` => passed
  - Core availability: `/menu.php` and `/api/v1/menu.php` => `200`
- Metrics delta (`5xx`, p95, error-rate): no degradation observed in smoke checks; detailed p95/5xx baseline not captured in this step
- Observation window: initial post-deploy validation completed
- Result: `success`
- Stop criteria triggered: `no`
- Rollback action performed: not required
- Notes/next step: start Phase 2 inventory (ports/services ownership) before any firewall changes; keep 2 active SSH sessions for subsequent hardening.

---

## Entry (2026-03-05, Phase 2 inventory completed)

- Date (UTC): 2026-03-05 22:12
- Environment: production (`menu.labus.pro`, shared host)
- Step/Phase: Phase 2 (port/service inventory, read-only)
- Owner: ops/admin
- Change objective (single objective): identify exposed ports and ownership without mutating firewall/network policy
- Commit hash: `5c64c1e` (runbook artifacts)
- Related config/file: inventory run output (`ss`, `lsof`, `iptables/nft`, `docker ps`)
- Risk summary: shared host has multiple non-menu services exposed; changes may affect other projects
- Preventive checks (pre): no firewall changes applied, inventory-only mode respected
- Deployment command(s): `bash scripts/perf/phase2-port-inventory.sh menu.labus.pro`
- Verification checks (post):
  - system MySQL `3306` bound to `127.0.0.1` (not exposed)
  - external `3307` is open and mapped to non-menu docker MySQL
  - external `96`, `3000`, `3001`, `3033`, `3282`, `9230`, `38080`, `7777`, `8888` are open and belong to other stack/panel services
- Metrics delta (`5xx`, p95, error-rate): N/A (read-only phase)
- Observation window: N/A
- Result: `success`
- Stop criteria triggered: `no`
- Rollback action performed: not required
- Notes/next step: **confirmed policy** - do not touch docker images/ports of other sites. Continue only menu-specific hardening steps.

---

## Entry (2026-03-05, menu-only endpoint lock)

- Date (UTC): 2026-03-05 22:46
- Environment: production (`menu.labus.pro`, shared host)
- Step/Phase: menu-only hardening (post-Phase 2)
- Owner: ops/admin
- Change objective (single objective): close public access to `db-indexes-optimizer-v2.php` at vhost level
- Commit hash: `4e0b7f8`
- Related config/file: `nginx-optimized.conf` (`location = /db-indexes-optimizer-v2.php { return 404; }`)
- Risk summary: low risk, endpoint is diagnostic/admin-only and should never be public
- Preventive checks (pre):
  - `nginx -t` syntax check
  - shared-host scope lock respected (no Docker/other-site ports touched)
- Deployment command(s):
  - `runuser -u "$WEBUSER" -- git -C "$PROJECT" fetch --prune origin`
  - `runuser -u "$WEBUSER" -- git -C "$PROJECT" checkout main`
  - `runuser -u "$WEBUSER" -- git -C "$PROJECT" pull --ff-only origin main`
  - `nginx -t && systemctl reload nginx`
- Verification checks (post):
  - `curl -I https://menu.labus.pro/db-indexes-optimizer-v2.php` => `HTTP/2 404`
  - repeated check => `HTTP/2 404`
- Metrics delta (`5xx`, p95, error-rate): no degradation observed during post-change checks
- Observation window: immediate post-deploy verification completed
- Result: `success`
- Stop criteria triggered: `no`
- Rollback action performed: not required
- Notes/next step:
  - Post-check completed:
    - `bash /var/www/labus_pro_usr/data/www/menu.labus.pro/scripts/perf/security-smoke.sh https://menu.labus.pro | tee "/root/security-smoke-$(date -u +%F-%H%M).log"`
    - result: `Security smoke passed for https://menu.labus.pro`
    - core availability: `/menu.php` and `/api/v1/menu.php` returned `200`
    - exposure checks: `/phpinfo.php` and `/db-indexes-optimizer-v2.php` returned `404`

---

## Entry (2026-03-05, Phase 4A completed)

- Date (UTC): 2026-03-05 23:38
- Environment: production (`menu.labus.pro`, shared host)
- Step/Phase: Phase 4A (menu-only exposure lock + auth/session hardening)
- Owner: ops/admin
- Change objective (single objective): close remaining menu-only public diagnostic/legacy endpoints and align auth/session guards without touching host-wide ports/services
- Commit hash: `44e2ade`
- Related config/file:
  - `nginx-optimized.conf`
  - `opcache-status.php`
  - `clear-cache.php`
  - `scripts/perf/security-smoke.sh`
  - `docs/security-smoke-checklist.md`
- Risk summary:
  - potential break of legacy order update path if any client still calls `/order_updates.php`
  - potential admin UX change around `opcache-status.php`/`clear-cache.php`
- Preventive checks (pre):
  - `nginx -t`
  - grep/code-search for `order_updates.php` references in active frontend
  - keep scope lock: no Docker/other-site port modifications
- Deployment command(s):
  - `runuser -u "$WEBUSER" -- git -C "$PROJECT" fetch --prune origin`
  - `runuser -u "$WEBUSER" -- git -C "$PROJECT" checkout main`
  - `runuser -u "$WEBUSER" -- git -C "$PROJECT" pull --ff-only origin main`
  - `nginx -t && systemctl reload nginx`
- Verification checks (post):
  - `bash /var/www/labus_pro_usr/data/www/menu.labus.pro/scripts/perf/security-smoke.sh https://menu.labus.pro | tee "/root/security-smoke-$(date -u +%F-%H%M).log"` => passed
  - smoke log path: `/root/security-smoke-2026-03-05-2338.log`
  - endpoint exposure checks returned `404`: `/phpinfo.php`, `/db-indexes-optimizer.php`, `/db-indexes-optimizer-v2.php`, `/order_updates.php`, `/scripts/api-metrics-report.php`, `/scripts/api-smoke-runner.php`
  - auth gate check: `curl -I https://menu.labus.pro/opcache-status.php` => `HTTP/2 302` with `location: auth.php` (verified from both shared host and external host)
  - manual admin flow: login, clear-cache, CSV import, one order status update path
- Metrics delta (`5xx`, p95, error-rate): no degradation observed during post-deploy checks and 30-minute observation window; no `5xx` growth detected, p95 remained within accepted range for key routes
- Observation window: `30m` closed successfully
- Result: `success`
- Stop criteria triggered: `no`
- Rollback action performed: not required
- Notes/next step:
  - proceed with next menu-only hardening iteration; keep host-wide port/Docker scope lock in place

---

## Entry (2026-03-06, Phase 1 observability rollout completed)

- Date (UTC): 2026-03-06 01:10
- Environment: production (`menu.labus.pro`, shared host)
- Step/Phase: Phase 1 (reliability/conversion instrumentation rollout)
- Owner: ops/admin
- Change objective (single objective): enable daily smoke retention and expose checkout-failure diagnostics in admin monitor without changing runtime contracts
- Commit hash: `26e3e82`
- Related config/file:
  - `monitor.php`
  - `scripts/perf/security-smoke-daily.sh`
  - `scripts/perf/install-security-smoke-cron.sh`
  - `scripts/perf/checkout-error-top.php`
  - `docs/security-phase-commands.md`, `docs/archive/security-hardening-status-2026-03-06.md`, `docs/project-improvement-roadmap.md`
- Risk summary: low risk; monitoring/observability only, no API schema or business-flow behavior change
- Preventive checks (pre):
  - repository fast-forward update on server to `26e3e82`
  - post-merge PHP lint hook passed
- Deployment command(s):
  - `runuser -u "$WEBUSER" -- git -C "$PROJECT" fetch --prune origin`
  - `runuser -u "$WEBUSER" -- git -C "$PROJECT" checkout main`
  - `runuser -u "$WEBUSER" -- git -C "$PROJECT" pull --ff-only origin main`
  - `cd /var/www/labus_pro_usr/data/www/menu.labus.pro && bash scripts/perf/install-security-smoke-cron.sh`
  - `php scripts/perf/checkout-error-top.php --hours=24 --top=3`
- Verification checks (post):
  - installed cron line includes `LOG_DIR=/var/www/labus_pro_usr/data/logs` and `RETENTION_DAYS=14`
  - checkout report works without `--log` and resolves `/var/www/labus_pro_usr/data/logs/menu.labus.pro-php.log`
  - monitor UI shows:
    - `Security Smoke (Daily)`
    - `Checkout Errors (24h)`
  - current 24h top reason snapshot: `validation / invalid_order_payload => 1`
- Metrics delta (`5xx`, p95, error-rate): no degradation reported during rollout checks; no runtime behavior change introduced
- Observation window: rollout verification completed; continue passive daily observation via cron logs
- Result: `success`
- Stop criteria triggered: `no`
- Rollback action performed: not required
- Notes/next step:
  - next step is Phase 2 item 4 (owner KPI snapshot, read-only queries only)
