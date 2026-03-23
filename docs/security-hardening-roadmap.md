# Security Hardening Roadmap

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-23`
- Verified against published pages: `https://menu.labus.pro/phpinfo.php`, `https://menu.labus.pro/order_updates.php`, `https://menu.labus.pro/monitor.php`, `https://menu.labus.pro/opcache-status.php`, `https://menu.labus.pro/file-manager.php?action=get_fonts`
- Current implementation notes:
  - Phase 1 and Phase 4A outcomes are implemented and verified live.
  - Phase 2, Phase 3, and Phase 5 are not evidenced as completed from repo state or public verification.
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
  - baseline capture is still operator-driven

### Phase 1 - Critical quick wins

- Status: `Implemented`
- Verified:
  - `phpinfo.php` is no longer public
  - public verification returns `404`

### Phase 2 - Network hardening without lockout

- Status: `Not implemented`
- Notes:
  - inventory/runbook documents exist
  - repo and public pages do not prove enforced firewall or port policy

### Phase 3 - Access and brute-force protection

- Status: `Not implemented`
- Notes:
  - SSH hardening and fail2ban may exist operationally, but this is not evidenced by repo state or public verification

### Phase 4 - Low-risk web-layer hardening

- Status: `Partial`
- Notes:
  - selective web-layer hardening exists
  - this phase is not closed as a complete web hardening program

### Phase 4A - Menu-only exposure lock

- Status: `Implemented`
- Verified:
  - menu-only legacy/internal diagnostic endpoints are locked
  - `monitor.php`, `opcache-status.php`, and `file-manager.php` use auth gate behavior instead of public utility access
  - `clear-cache.php?scope=server` does not behave like a public reset endpoint

### Phase 5 - Patch cadence and operating discipline

- Status: `Not implemented`
- Notes:
  - monthly review and provider patch policy are not evidenced as complete process controls

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

1. Decide whether Phase 2 port restrictions are in scope for this host and document the real owner of each exposed service.
2. Document or implement SSH/fail2ban policy if it exists operationally.
3. Keep auth-gate and menu-only exposure locks in smoke coverage after each release.
