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
