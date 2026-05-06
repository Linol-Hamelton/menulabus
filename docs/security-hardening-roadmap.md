# Security Hardening Roadmap

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-23`
- Verified against published pages: `https://menu.labus.pro/phpinfo.php`, `https://menu.labus.pro/order_updates.php`, `https://menu.labus.pro/monitor.php`, `https://menu.labus.pro/opcache-status.php`, `https://menu.labus.pro/file-manager.php?action=get_fonts`
- Current implementation notes:
  - Phase 0, Phase 1, and Phase 4A outcomes are implemented and verified live or in the release workflow.
  - Repo-owned execution scripts now exist for Phase 2, Phase 3, and Phase 5, but host rollout still requires an operator on the target server.
  - Auth-gated ops/admin utility endpoints are part of the current hardened menu-only surface.

## Goal

Implement security improvements via reversible, testable, single-step changes:

- `1 change = 1 release step = 1 verification = 1 observation window`
- no bundled risky changes
- no mass refactor under the label of hardening

## Scope

- In scope: Nginx/FastPanel, PHP runtime settings, exposed endpoints/files, rollout process
- Out of scope: platform rewrite, broad application rewrite, infrastructure that is not controlled by this repo

## Verified Current State

Live checks on `2026-03-23` confirm:

- `GET /phpinfo.php` => `404`
- `GET /db-indexes-optimizer.php` => `404`
- `GET /db-indexes-optimizer-v2.php` => `404`
- `GET /order_updates.php` => `404`
- `GET /scripts/api-metrics-report.php` => `404`
- `GET /scripts/api-smoke-runner.php` => `404`
- `GET /monitor.php` => `302` redirect to `auth.php` when unauthenticated
- `GET /opcache-status.php` => `302` redirect to `auth.php` when unauthenticated
- `GET /file-manager.php?action=get_fonts` => `302` redirect to `auth.php` when unauthenticated
- `GET /clear-cache.php?scope=server` => `405`

## Phase Status

### Phase 0 - Baseline and rollback kit

- Status: `Implemented`
- Notes:
  - release workflow, rollback flow, and change-log template exist
  - baseline capture now exists as `scripts/security/capture-baseline.sh` and runs automatically from production `post-merge`

### Phase 1 - Critical quick wins

- Status: `Implemented`
- Verified:
  - `phpinfo.php` is no longer public
  - public verification returns `404`

### Phase 2 - Network hardening without lockout

- Status: `Implemented (repo) · Pending host rollout`
- Notes:
  - inventory/runbook documents exist
  - repo-owned apply script `scripts/security/apply-network-policy.sh` is complete
  - **operational** rollout (live firewall enforcement on prod hosts) is the only remaining step; tracked separately as a deployment task, not as a code-incomplete item

### Phase 3 - Access and brute-force protection

- Status: `Implemented (repo) · Pending host rollout`
- Notes:
  - repo-owned SSH/fail2ban hardening script `scripts/security/harden-ssh-fail2ban.sh` is complete
  - **operational** rollout (run script on prod host, verify fail2ban state) is the only remaining step

### Phase 4 - Low-risk web-layer hardening

- Status: `Selective hardening implemented (Phase 4A) · Comprehensive program scope TBD`
- Notes:
  - **Implemented**: auth gates for `monitor.php`, `opcache-status.php`, `file-manager.php`; CSRF coverage on state-mutating endpoints; CSP nonce-strict policy; X-Frame/X-Content-Type/Referrer-Policy headers shipped
  - **Open**: a comprehensive web-hardening "program" (full threat model, all-endpoints audit, formal SLA on findings) is not yet defined as a single artifact. This is a documentation gap rather than a code gap — tracking as Phase 4B if it becomes a priority.

### Phase 4A - Menu-only exposure lock

- Status: `Implemented`
- Verified:
  - menu-only legacy/internal diagnostic endpoints are locked
  - `monitor.php`, `opcache-status.php`, and `file-manager.php` use auth gate behavior instead of public utility access
  - `clear-cache.php?scope=server` does not behave like a public reset endpoint

### Phase 5 - Patch cadence and operating discipline

- Status: `Implemented (repo) · Pending operational adoption`
- Notes:
  - repo-owned monthly review runner `scripts/security/monthly-review.sh` is complete
  - **operational** adoption (cron schedule, owner assignment, evidence retention store) — to be set up by ops, not a code task

## Public Interface Impact

Already implemented:

- `GET /phpinfo.php` returns `404`
- `GET /order_updates.php` returns `404`
- `GET /monitor.php` redirects unauthenticated users to `auth.php`
- `GET /opcache-status.php` redirects unauthenticated users to `auth.php`
- `GET /file-manager.php?action=get_fonts` redirects unauthenticated users to `auth.php`
- `GET /clear-cache.php?scope=server` returns `405`

Still outside completed scope:

- host-wide port restriction policy
- full SSH hardening policy
- documented recurring patch cadence

## Next Security Priorities

1. Execute the Phase 2 network policy on the target host and record the retained open ports.
2. Execute and verify the SSH/fail2ban rollout on the target host.
3. Keep auth-gate and menu-only exposure locks in smoke coverage after each release.
4. Start a monthly security review cadence and store artifacts from `scripts/security/monthly-review.sh`.

## CSRF Coverage (Polish 12.2 — 2026-04-27)

`api/save/project-name.php` and `send_message.php` now route through `Csrf::requireValid()` like `password-reset.php`. These were the last two state-mutating endpoints reachable from the authenticated UI that did not validate CSRF tokens — `api/save/project-name.php` accepts a session-scoped project name from `admin-menu.php`'s file manager, and `send_message.php` is the Telegram-bridge for the legacy reservation form on `index.php`. The two minified callers (`js/file-manager.min.js` `saveProjectName` and `js/app.min.js` `initReservationForm`) read the page-level `<meta name="csrf-token">` and forward it as `X-CSRF-Token` header plus `csrf_token` body field; both `admin-menu.php` and `index.php` now emit the meta tag in `<head>`. Server returns `403 {"success": false, "error": "csrf_mismatch"}` on missing/mismatched token.
