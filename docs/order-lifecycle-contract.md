# Order Lifecycle Contract

## Implementation Status

- Status: `Implemented`
- Last reviewed: `2026-03-24`
- Current implementation notes:
  - This document defines the shared stale-order contract used by `employee`, `customer_orders`, and owner-facing KPI summaries.
  - The contract is implemented in `lib/orders/lifecycle.php`.
  - Automatic stale-order cleanup is now implemented via `stale-order-cleanup.php` and `scripts/orders/cleanup-stale.php`.

## Status Buckets

- Open statuses: `Приём`, `готовим`, `доставляем`
- Closed statuses: `завершён`, `отказ`
- Board order: open statuses first, then closed statuses

## Age Thresholds

- `0-19` minutes: `fresh`
- `20-44` minutes: `warning`
- `45+` minutes: `critical`
- closed orders: `quiet`

## Rendered Labels

- `fresh` => `В норме`
- `warning` => `Требует внимания`
- `critical` => `Просрочен`
- `quiet` => `Закрыт`

## Shared UI Usage

- `employee` shows queue KPI cards, lifecycle badges, and next-action hints from the shared contract.
- `customer_orders` shows active/history split plus lifecycle badges on current orders.
- `owner` uses lifecycle summary counts for attention and stale KPI cards.
- `owner` and privileged `employee` flows now expose a stale-cleanup action for orders older than the shared threshold.

## Cleanup Mechanics

- HTTP operator action: `POST /stale-order-cleanup.php`
- CLI operator action: `php scripts/orders/cleanup-stale.php --apply`
- default stale threshold: `45` minutes
- stale orders are closed as `отказ` and recorded in `order_status_history`

## Follow-up Backlog

- Decide whether stale thresholds need per-tenant configurability.
- Extend post-release smoke to assert at least one lifecycle-aware render on internal order pages.
