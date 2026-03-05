# Security Hardening Roadmap (Preventive-First, Low-Risk)

## Goal

Implement security improvements only via reversible, testable, single-step changes:

- `1 change = 1 release step = 1 verification = 1 observation window`
- no mass refactoring
- no bundled risky changes

## Scope

- In scope: Nginx/FastPanel, PHP-FPM/runtime settings, exposed endpoints/files, operational rollout process.
- Out of scope: architecture rewrite, broad code refactor, migration to new platform.

## Success Criteria

- No production incidents after each step.
- Smoke checks pass after each step.
- `5xx` does not increase versus baseline.
- `p95` on key routes does not degrade more than 10%.
- Rollback can be completed within 5–10 minutes.

## Mandatory Change Contract

For every change, fill all six fields before rollout:

1. Exact objective (single objective only)
2. Negative consequences (specific)
3. Preventive checks before enablement
4. Post-change checks
5. Stop criteria (immediate rollback trigger)
6. Rollback command/action

## Phase Plan (strict order)

### Phase 0 — Baseline and rollback kit (no production mutation)

1. Record release commit hash and active config versions.
2. Capture baseline: key URLs, headers, API smoke, current p95 and error-rate.
3. Prepare rollback kit:
   - git rollback flow from `docs/deployment-workflow.md`
   - OPcache reset procedure
   - post-rollback smoke checklist
4. Start security change log with: date, owner, step, result, commit, rollback status.

### Phase 1 — Critical quick wins (low-risk)

1. Remove public `phpinfo.php` from repository.
2. Keep a defensive Nginx block for `/phpinfo.php` => `404`.
3. Validate:
   - `curl -I https://menu.labus.pro/phpinfo.php` returns `404`
   - key pages/API still return expected `200`

### Phase 2 — Network hardening without lockout

1. Inventory ports and owning services (`ss -lntp`, FastPanel UI).
2. Port `3306`:
   - allow only localhost/trusted admin IPs (firewall + MySQL policy)
   - validate application DB connectivity
   - validate external `3306` is closed/filtered
3. Ports `21/8080/8443`:
   - identify business need first
   - if unused: close
   - if used: restrict by IP allowlist + strong auth
4. Firewall changes only with a second active SSH session.

### Phase 3 — Access and brute-force protection

1. SSH hardening in stages:
   - verify key-based login using dedicated admin user
   - only then disable password auth
2. Enable fail2ban for SSH and auth endpoints.
3. Validate admin access from two independent sessions.

### Phase 4 — Low-risk web-layer hardening

1. Hide Nginx version (`server_tokens off`).
2. Do not globally block HTTP methods; restrict only in explicit locations if needed.
3. Set explicit `client_max_body_size` not below real CSV upload need.
4. Keep strict CSP/security headers as-is (no weakening).

### Phase 5 — Patch cadence and operating discipline

1. Nginx/PHP updates only in dedicated maintenance window with rollback plan.
2. If versions are provider-managed, keep a provider update policy/SLA record.
3. Run monthly security review by short checklist.

## Risk Matrix

| Change | Possible Negative Effect | Preventive Control | Stop Criteria | Rollback |
|---|---|---|---|---|
| Block/remove `phpinfo.php` | Reduced diagnostics convenience | Capture needed diagnostics in private scope beforehand | Urgent diagnostics blocked without alternative | Temporary IP-restricted + basic-auth diagnostic endpoint |
| Restrict `3306` | App loses DB connectivity | Validate DSN/connectivity before external lock-down | Any DB connection errors | Restore previous firewall rule set |
| Close `21/8080/8443` | Break panel/FTP integrations | Service-owner inventory + usage validation | Critical admin tool becomes inaccessible | Re-open port only for admin IP allowlist |
| SSH key-only | Admin lockout risk | 2 active sessions + tested key login | New key-based login fails | Re-enable `PasswordAuthentication yes`, reload sshd |
| Nginx hardening tweaks | Unexpected `4xx/5xx` | `nginx -t` + pre-smoke | `5xx` growth or key endpoint failure | Restore previous vhost config |
| Upload size limit change | CSV import fails | Test representative CSV before/after | Upload errors in admin | Restore previous limit |

## Public Interface Impact

- JSON/API contracts: unchanged.
- Behavioral changes:
  - `GET /phpinfo.php` must return `404`.
  - service ports become restricted by policy.
- Menu/order business logic remains unchanged.

## Required Checks After Each Step

### Functional Smoke

1. `GET /menu.php` => `200`
2. `GET /api/v1/menu.php` => `200`
3. Admin login and core admin actions
4. CSV upload (valid file) completes
5. Order creation (site and API path)
6. SSE/poll path returns expected response

### Security Smoke

1. `GET /phpinfo.php` => `404`
2. Security headers on `/menu.php` and `/api/v1/menu.php`
3. External check: `3306` closed/filtered
4. Auth endpoint rate-limit behavior

### Performance Smoke

1. 5 sequential calls to `/api/v1/menu.php` with `x-cache-status` and `cache-control` checks
2. p95 and error-rate compared to baseline
3. No unusual CPU/RAM spikes on nginx/php-fpm/redis/mysql

## Release Procedure (each step)

1. Run pre-check and baseline capture.
2. Apply exactly one change.
3. Validate config syntax (`nginx -t`, etc.).
4. Reload only required service.
5. Run functional + security + performance mini-smoke.
6. Observe for 30 minutes.
7. Mark success in change log or rollback immediately.

## Assumptions and Defaults

- Deployment flow follows `docs/deployment-workflow.md`.
- 5–10 minute maintenance window is acceptable.
- If uncertain, rollback wins over in-place troubleshooting in production.
- No mass refactoring.
- Security rollout is phased, never bundled.

## Definition of Done

- Critical exposures are remediated (`phpinfo`, externally reachable `3306`).
- Availability/performance remain within target thresholds.
- Every completed step has record: what changed, checks, rollback path.
- Procedure is reusable for future hardening iterations.
