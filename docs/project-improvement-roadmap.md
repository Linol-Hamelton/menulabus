# Project Improvement Roadmap (Low-Risk, High-Impact)

## Goal

Increase business value for venue owners and customer convenience without risky refactoring or cross-project infrastructure changes.

## Constraints (fixed)

- Menu-only scope on shared host: no changes to Docker/services/ports of other projects.
- One production change per rollout step.
- Mandatory post-change smoke checks (`scripts/perf/security-smoke.sh` + business smoke).
- API contract source of truth remains `docs/openapi.yaml`.

## Success Metrics

Track baseline before each phase and compare after rollout:

- Conversion: `menu_view -> order_create_success`
- Checkout quality: order create error-rate and abandonment on checkout screens
- Owner operations: average time to process new order, missed/late order count
- Reliability: `5xx`, p95 for `/menu.php` and `/api/v1/menu.php`
- Retention signals: repeat orders per customer (7/30 day)

## Phase 1 (1-2 weeks): Reliability and conversion quick wins

### 1) Daily automated smoke + log retention

- Value: faster incident detection after deploys.
- Change:
  - add daily server cron for `scripts/perf/security-smoke.sh`
  - store logs under `/var/www/labus_pro_usr/data/logs/security-smoke-<UTC>.log` with 14-day retention
- Risk: low.
- Done when: scheduled run exists, logs rotate, failures are visible to operator.

### 2) OpenAPI parity gate in release flow

- Value: prevents mobile/client regressions and undocumented API drift.
- Change:
  - run `npm run openapi:validate` before every release merge to `main`
  - add short checklist item to `docs/deployment-workflow.md`
- Risk: low.
- Done when: release checklist explicitly blocks deploy on failed validation.

### 3) Checkout failure observability (menu-only)

- Value: direct conversion uplift through faster bug localization.
- Change:
  - log structured reasons for failed order creation (`validation`, `auth`, `db`, `idempotency`)
  - keep existing response contract unchanged
- Risk: low-medium (logging only, no contract changes).
- Done when: failures are grouped by reason in logs and can be reviewed daily.

## Phase 2 (2-4 weeks): Owner-facing business boost

### 4) Owner KPI snapshot page (no schema rewrite)

- Value: immediate visibility for revenue and operational decisions.
- Change:
  - add lightweight KPIs in owner/admin area:
    - orders today
    - paid/cancelled counts
    - average чек (AOV)
    - top items (day/week)
- Risk: low-medium (read queries only).
- Done when: KPI block is visible to owner and loads within target p95.

### 5) Order flow bottleneck report

- Value: reduce delays and improve fulfillment speed.
- Change:
  - add simple aggregation by status transition times (created -> accepted -> ready -> completed)
  - expose as owner-only table/export
- Risk: medium (new read queries; no core flow change).
- Done when: owner can identify slow stages by day and by shift.

### 6) Safer cache-clear and monitor operations

- Value: fewer operator mistakes during peak hours.
- Change:
  - add explicit confirmation text + timestamp/user trace in admin monitor actions
  - keep current auth guards (`require_auth.php`) intact
- Risk: low.
- Done when: each cache/maintenance action is auditable.

## Phase 3 (3-6 weeks): Customer experience and repeat orders

### 7) Reorder shortcut from previous order

- Value: faster repeat purchase, higher retention.
- Change:
  - add "repeat order" action for authenticated users using existing order items data
  - no change to create-order API contract
- Risk: medium (UI + server-side data mapping).
- Done when: user can recreate a past order in one action.

### 8) Delivery address quality guard using geocode endpoint

- Value: fewer failed deliveries and operator callbacks.
- Change:
  - on checkout, validate coordinates/address consistency through `/api/v1/geocode.php`
  - show non-blocking correction hints
- Risk: low-medium.
- Done when: address correction hints are shown and manual corrections decrease.

### 9) Push subscription health checks

- Value: better order status communication and fewer support contacts.
- Change:
  - periodic validation of stale push subscriptions
  - owner-facing counter: active vs invalid subscriptions
- Risk: medium.
- Done when: invalid subscription ratio trend is visible and decreasing.

## Phase 4 (continuous): Performance without risky behavior changes

### 10) API menu endpoint cache behavior audit

- Value: lower API latency and backend load under peak.
- Change:
  - verify intended behavior of `/api/v1/menu.php` (`cache-control`, `x-cache-status`, no-store policy)
  - if business-safe, run controlled microcache experiment (short TTL, instant rollback path)
- Risk: medium (must preserve correctness and no-store requirements).
- Done when: p95 improves without data consistency regressions.

### 11) Nginx/PHP-FPM capacity tuning from real metrics

- Value: stable peak handling for menu and order creation.
- Change:
  - tune pool limits and keepalive/cache parameters based on measured load
  - apply one parameter group per release step
- Risk: medium.
- Done when: peak-hour p95 and error-rate stay within target thresholds.

## Rollout rules for every roadmap item

1. Baseline capture (5xx/p95 + business metric for the item).
2. One change per release.
3. Config syntax/service checks.
4. Smoke + business verification.
5. 30-minute observation window.
6. Immediate rollback on stop criteria.

## Recommended execution order

1. Phase 1 items 1-3
2. Phase 2 items 4-6
3. Phase 3 items 7-9
4. Phase 4 items 10-11

This order maximizes near-term value while keeping rollout risk low.
