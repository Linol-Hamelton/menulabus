# Staff Management v2 (Phase 7.4 v2)

## Implementation Status

- Status: `Partial` (data model + API + payroll CSV; staff dashboard UI pending)
- Last reviewed: `2026-04-28`

## Overview

Phase 7.4 v1 shipped the baseline `shifts`, `time_entries`, `tip_splits` tables and a flat list view in `admin-staff.php`. v2 layers two operationally-critical features:

1. **Shift swap requests** — employees can ask "I can't make my shift, who'll take it?", another employee volunteers, manager approves/denies. On approval the `shifts.user_id` is reassigned atomically.
2. **Payroll CSV export** — `scripts/payroll-export.php --period=YYYY-MM` (or `--from=YYYY-MM-DD --to=YYYY-MM-DD`) emits a CSV with hours, base pay, pooled tips, manual tip overrides, and total per employee for accounting hand-off.
3. **Per-employee tip distribution rules** — `tips_distribution_rules` lets the owner override the default equal-by-hours split with `equal | by_hours | by_orders | manual`. Manual rules write per-user amounts in `tips_manual_overrides`.

## Data

| Table | Role |
|---|---|
| `shift_swap_requests` | id, shift_id, requester_id, volunteer_id, status `open`/`volunteer_offered`/`approved`/`denied`/`cancelled`, note, requested_at, decided_at, decided_by |
| `tips_distribution_rules` | period_start, period_end, rule_type, notes |
| `tips_manual_overrides` | rule_id, user_id, amount |

Migration: [`sql/staff-v2-migration.sql`](../sql/staff-v2-migration.sql). Idempotent.

## API

### `POST /api/shift-swap-action.php`

Single endpoint covering all five lifecycle transitions:

```jsonc
// request a swap (any employee)
{ "action": "request", "shift_id": 42, "note": "болею", "csrf_token": "..." }
// volunteer to cover (any employee, not the requester)
{ "action": "offer",   "swap_id": 17, "csrf_token": "..." }
// approve (admin/owner only) → reassigns shift atomically
{ "action": "approve", "swap_id": 17, "csrf_token": "..." }
// deny (admin/owner only)
{ "action": "deny",    "swap_id": 17, "csrf_token": "..." }
// cancel (requester only, before any decision)
{ "action": "cancel",  "swap_id": 17, "csrf_token": "..." }
```

Responses: `{success:true, swap_id?}` or `400/401/403/409` with `{success:false, error}`.

### DB methods (Database)

`createShiftSwapRequest`, `listShiftSwapRequests`, `offerToTakeShift`, `approveShiftSwap` (transactional reassignment), `denyShiftSwap`, `cancelShiftSwap`.

## Payroll Export

```
$ php scripts/payroll-export.php --period=2026-04
# CSV to stdout

$ php scripts/payroll-export.php --period=2026-04 --out=/tmp/payroll-2026-04.csv
[payroll-export] period=2026-04-01..2026-04-30 rows=12 out=/tmp/payroll-2026-04.csv
```

Columns:

```
period_from, period_to,
user_id, name, email, role,
hours, hourly_rate, base_pay,
tips_pooled, tips_manual, tips_total,
total
```

Calculation:
- `hours` = SUM of completed `time_entries` (clocked_in_at .. clocked_out_at) within the window. In-flight entries are skipped.
- `base_pay` = hours × `users.hourly_rate` (0 if rate not set on the user).
- `tips_pooled` = SUM of `tip_splits.amount` for the user where `period_start..period_end` falls within the window.
- `tips_manual` = SUM of `tips_manual_overrides.amount` for any rule whose period falls within the window.
- `tips_total` = `tips_pooled + tips_manual`.
- `total` = `base_pay + tips_total`.

## Verification (sandbox)

1. Apply migration: `mysql … < sql/staff-v2-migration.sql`.
2. Create two employee users, give them shifts on the same date.
3. From employee A: `POST /api/shift-swap-action.php {"action":"request","shift_id":<A's shift>,"note":"..."}` — returns swap_id.
4. From employee B: `POST /api/shift-swap-action.php {"action":"offer","swap_id":<id>}`.
5. From owner: `POST /api/shift-swap-action.php {"action":"approve","swap_id":<id>}`.
6. Confirm `shifts.user_id` for that row is now B's id (atomic via transaction).
7. Run `php scripts/payroll-export.php --period=YYYY-MM` and verify CSV opens cleanly in Excel/Numbers.

## Known Gaps / Future Work

- **No staff dashboard UI** for the swap-request inbox. v3 will add a panel inside `admin-staff.php` listing open requests + manager actions, plus an employee-side "my open requests" list.
- **No Telegram notification** when a request is opened or a volunteer offers. Should reuse `lib/telegram-notifications.php` to ping admin/owner chats.
- **No shift conflict check** — the volunteer might already have an overlapping shift. v3 should call a `Database::hasOverlappingShift($userId, $startsAt, $endsAt)` guard before the reassignment commits.
- **No payroll filter by location** — the CSV totals across all locations of a multi-location tenant. Add `--location-id=N` flag in v3.
- **No frontend for tips_distribution_rules** — manual overrides are currently SQL-only.
