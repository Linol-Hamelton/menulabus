# Reservations / Table Bookings

## Implementation Status

- Status: `Implemented` (with deferred polish — see Known Gaps).
- Last reviewed: `2026-04-23`
- Current state:
  - **Storage:** [sql/reservations-migration.sql](../sql/reservations-migration.sql) — append-only `reservations` table, conflict check at the application layer.
  - **DB layer:** [db.php](../db.php) — `createReservation`, `getReservationById`, `getReservationsByRange`, `getUpcomingReservationsByUser`, `updateReservationStatus`, `checkTableAvailable`.
  - **Mobile API:** `POST /api/v1/reservations/create.php`, `GET /api/v1/reservations/availability.php`, `POST /api/v1/reservations/cancel.php` — bearer-authenticated, idempotency-aware on create.
  - **Web (session) endpoints:** `POST /create_reservation.php` (customer), `POST /update_reservation_status.php` (staff). Both use [lib/Csrf.php](../lib/Csrf.php) and the staff endpoint enforces a `pending → confirmed → seated` transition table.
  - **Customer UI:** [reservation.php](../reservation.php) — standalone page with form, "my upcoming reservations" list for logged-in users, prefill via `?table=N` or `qr_table` session value. Linked from [header.php](../header.php) "Бронь" nav item.
  - **Staff UI:** new "Брони" tab in [employee.php](../employee.php) → [partials/employee_account_sections.php](../partials/employee_account_sections.php) — week-long board grouped by day, status badges, action buttons (Подтвердить / Рассадить / Не пришёл / Отменить).
  - **Telegram:** `sendReservationToTelegram()` in [telegram-notifications.php](../telegram-notifications.php) sends a card with inline-keyboard *Подтвердить* / *Отклонить* on every new booking. Callback handler `reserve_(confirm|reject)_N` in [telegram-webhook.php](../telegram-webhook.php) updates status and edits the original message.
  - **Tests:** [tests/ReservationsTest.php](../tests/ReservationsTest.php) — MySQL-gated coverage for overlap, back-to-back, cancelled-reuse, confirmed_at stamping, guest reservations, range queries.

## Purpose

Move table reservations from "phone call to the host stand" into the same surface guests use to order food. Two goals:

1. Let a guest reserve a specific table for a specific time without leaving the menu app.
2. Give the staff a single board for upcoming bookings, replacing paper notebooks and Telegram threads.

The scope is a minimum viable booking loop. Yield management, deposits, and table-layout drag-and-drop are explicitly out of scope for this iteration.

## Data Model

Table `reservations` — see [sql/reservations-migration.sql](../sql/reservations-migration.sql):

| Column         | Type                  | Notes                                                                        |
|----------------|-----------------------|------------------------------------------------------------------------------|
| `id`           | `INT UNSIGNED AUTO_INCREMENT` | primary key                                                          |
| `table_label`  | `VARCHAR(64)`         | free-text identifier; matches the convention used in `orders.delivery_details`. No FK to a `tables` table — that table does not exist in the schema; tables are addressed by label/QR slug. |
| `user_id`      | `INT NULL`            | nullable — guest reservations are first-class.                               |
| `guest_name`   | `VARCHAR(255) NULL`   | required for guest bookings; ignored when `user_id` is set.                  |
| `guest_phone`  | `VARCHAR(32) NULL`    | required for guest bookings (callback channel).                              |
| `guests_count` | `TINYINT UNSIGNED`    | bounded `1..50` by `CHECK` constraint.                                       |
| `starts_at`    | `DATETIME`            | tenant-local time, same convention as `orders.created_at`.                   |
| `ends_at`      | `DATETIME`            | must be `> starts_at`, enforced by `CHECK` constraint.                       |
| `status`       | `VARCHAR(32)`         | English keys; UI is responsible for display localization.                    |
| `note`         | `TEXT NULL`           | optional, server-trimmed to 1000 chars.                                      |
| `created_at`   | `DATETIME`            | defaults to `NOW()`.                                                          |
| `confirmed_at` | `DATETIME NULL`       | set by `updateReservationStatus($id, 'confirmed')`; powers "confirmed N min ago" UI. |

Indexes:

- `idx_reservations_table_time (table_label, starts_at, ends_at)` — supports the OVERLAP query used by `checkTableAvailable()`.
- `idx_reservations_status_time (status, starts_at)` — supports the staff board "today's confirmed".
- `idx_reservations_user_starts (user_id, starts_at)` — supports the customer "my upcoming reservations" view.

## Status Lifecycle

Allowed values for `status`, in transition order:

| Status      | Set by             | Meaning                                                            |
|-------------|--------------------|--------------------------------------------------------------------|
| `pending`   | `createReservation` | Customer or guest just submitted; awaiting staff confirmation.    |
| `confirmed` | Staff (admin/employee) | Staff acknowledged; `confirmed_at` is stamped.                  |
| `seated`    | Staff              | Guest arrived and was seated.                                       |
| `cancelled` | Customer or staff  | Cancelled before the start of the slot.                             |
| `no_show`   | Staff              | Slot started but guest never arrived; tracked for repeat-offender visibility. |

`checkTableAvailable()` ignores `cancelled` and `no_show` rows — a cancelled booking must not block a new one for the same window. `pending`, `confirmed`, and `seated` all count as "occupied" for conflict detection.

## API Surface (Mobile / SPA)

All endpoints require a valid bearer token via `Authorization: Bearer <access_token>` (same auth as `/api/v1/orders/*`). Responses follow the [ApiResponse](../lib/ApiResponse.php) envelope — `{"success": true, "data": {...}}` or `{"success": false, "error": "...", "meta": {...}}`.

### `POST /api/v1/reservations/create.php`

Create a `pending` reservation.

Body (JSON):

```json
{
  "table_label": "T3",
  "guests_count": 4,
  "starts_at": "2099-04-25 19:00:00",
  "ends_at":   "2099-04-25 21:00:00",
  "note": "у окна, если получится",
  "guest_name":  "Иван",
  "guest_phone": "+7 700 000 00 00"
}
```

`guest_name` / `guest_phone` are optional when the bearer token resolves to a real user; they are required for guest tokens (when those become a real flow).

Headers (optional but recommended):

- `Idempotency-Key: <opaque>` — re-sending the same payload with the same key returns the original response; a different payload returns `409`.

Responses:

- `201 Created` — `{ reservation_id, status, table_label, starts_at, ends_at }`.
- `400 Bad Request` — validation error (missing field, inverted window, past `starts_at`, etc.).
- `409 Conflict` — slot taken (`{ table_label, starts_at, ends_at }` returned in `meta`) or `Idempotency-Key` reused with a different payload.
- `500 Internal Server Error` — DB write failed.

### `GET /api/v1/reservations/availability.php`

List the busy windows for a single table on a single day. Used by the customer UI to disable taken slots in the time picker.

Query string:

- `table_label` — required.
- `date` — required, `YYYY-MM-DD`.

Response:

```json
{
  "success": true,
  "data": {
    "table_label": "T3",
    "date": "2099-04-25",
    "busy": [
      { "starts_at": "2099-04-25 12:00:00", "ends_at": "2099-04-25 14:00:00", "status": "confirmed" },
      { "starts_at": "2099-04-25 19:00:00", "ends_at": "2099-04-25 21:00:00", "status": "pending" }
    ]
  }
}
```

Only `pending`, `confirmed`, and `seated` rows are returned. The client is free to derive available slots from this list and the tenant's opening hours.

### `POST /api/v1/reservations/cancel.php`

Body (JSON):

```json
{ "reservation_id": 123 }
```

- Customers can only cancel their own bookings (`user_id` match).
- `employee` / `admin` / `owner` can cancel any booking.
- Only `pending` and `confirmed` rows can be cancelled. `seated`, `cancelled`, and `no_show` return `409` with the current status in `meta`.

Response: `{ reservation_id, status: "cancelled" }`.

## Conflict Check

The OVERLAP query lives in [`Database::checkTableAvailable()`](../db.php). The condition is the standard half-open-interval test:

```sql
WHERE table_label = :label
  AND status IN ('pending','confirmed','seated')
  AND starts_at < :ends
  AND ends_at   > :starts
```

This treats `[starts_at, ends_at)` as a half-open interval, which means a booking that *starts exactly when another one ends* is allowed — the back-to-back case. [tests/ReservationsTest.php](../tests/ReservationsTest.php) locks that contract.

## Security Notes

- **Bearer auth only.** No CSRF is required because there is no session-cookie path to these endpoints; all writes go through `MobileTokenAuth::verifyToken()`.
- **Ownership.** Cancellation enforces `user_id` match for non-privileged callers. There is no separate "share booking" flow yet.
- **Idempotency.** `create.php` integrates with [lib/Idempotency.php](../lib/Idempotency.php) under the scope `api_v1_reservation_create`. A retry from a flaky network is safe; a second attempt with a different payload but the same `Idempotency-Key` returns `409`.
- **Past-date guard.** `create.php` rejects `starts_at` more than 60 seconds in the past. Server-side guard, not just client-side.

## Test Flow

Once the customer UI ships:

1. Bearer-auth as a regular user.
2. `POST /api/v1/reservations/create.php` for a fresh `(table_label, starts_at, ends_at)`. Expect `201`, `status: "pending"`.
3. Same call again with the same `Idempotency-Key`. Expect identical `data` (and the same `reservation_id`).
4. Same call with a *different* payload but the same `Idempotency-Key`. Expect `409`.
5. Different bearer token + same window + same table. Expect `409` with the conflicting slot in `meta`.
6. Bearer-auth as `employee` and `updateReservationStatus($id, 'confirmed')` via the staff board. Expect `confirmed_at` to populate.
7. Bearer-auth as the original user and `POST /api/v1/reservations/cancel.php` with the booking id. Expect `200`, `status: "cancelled"`.
8. Re-issue the original `create.php` call. Expect `201` — the cancelled slot must be reusable.

For DB-level coverage, run the MySQL-gated suite:

```bash
CLEANMENU_TEST_MYSQL_DSN=mysql:host=127.0.0.1;dbname=cleanmenu_test;charset=utf8mb4 \
CLEANMENU_TEST_MYSQL_USER=... \
CLEANMENU_TEST_MYSQL_PASS=... \
composer test
```

## Known Gaps / Future Work

- **No reminder.** [Queue.php](../Queue.php) supports delayed jobs; a `send_reservation_reminder` job 2 hours before `starts_at` is the minimal addition.
- **No OpenAPI entries.** [docs/openapi.yaml](./openapi.yaml) has not been updated; mobile clients should rely on this doc until that lands. Pre-push hook validates OpenAPI, so the diff must be clean.
- **No availability picker on customer form.** The current form lets the user pick any datetime; on submit the server returns `409 slot_taken` if conflicting. A future iteration can call `availability.php` (or a session-based equivalent) to disable taken slots in the time picker.
- **Staff board is a flat list, not a calendar grid.** Week-long list grouped by day, no drag-to-reschedule, no overlap visualization across tables. Sufficient for a 5–20 table venue; revisit at scale.
- **No deposit / no-show penalty.** Out of scope for this iteration.
- **No reservation_enabled tenant toggle.** The "Бронь" header link is shown for every tenant. If a tenant does not take reservations they currently rely on hiding the link via custom CSS or by simply not promoting the page.
- **No smoke test in `scripts/api-smoke-runner.php`.** Adding a positive create + cancel case is a low-risk follow-up.

## Related Docs

- [project-reference.md](./project-reference.md) — section 5 (API surface) needs a new row for `/api/v1/reservations/*` once this leaves `Partial`.
- [feature-audit-matrix.md](./feature-audit-matrix.md) — add a "table reservations" row in §3 once the staff board ships.
- [order-lifecycle-contract.md](./order-lifecycle-contract.md) — the lifecycle pattern for orders is the model the reservation status transitions follow.
- [api-smoke.md](./api-smoke.md) — `scripts/api-smoke-runner.php` should grow a reservation create + cancel case once the staff board exists to clean up test rows.
