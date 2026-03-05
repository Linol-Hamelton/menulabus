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

## Requires manual server execution

- Firewall and port policy (`3306`, `21`, `8080`, `8443`).
- SSH staged hardening and fail2ban rollout.
- Nginx/FastPanel production config apply + reload.
- Post-change observation and metrics comparison.

## Recommended next execution order

1. Deploy current commit to production.
2. Apply Nginx config in FastPanel and reload safely (`nginx -t && systemctl reload nginx`).
3. Run `docs/security-smoke-checklist.md` (or `bash scripts/perf/security-smoke.sh https://menu.labus.pro`).
4. Start phase-by-phase server hardening from `docs/security-phase-commands.md`.

## Scope lock after Phase 2

- Confirmed by operations: do not modify Docker images/containers and network ports that belong to other sites/services on the shared host.
- Therefore, host-level firewall tightening for non-menu ports is excluded from this project scope.
- Continue with menu-only hardening steps (application/vhost level) to avoid cross-project impact.
