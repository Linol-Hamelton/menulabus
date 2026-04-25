# Enhanced Analytics (v2)

## Implementation Status

- Status: `Implemented` (Phase 6.4 MVP).
- Last reviewed: `2026-04-23`
- Current state:
  - **DB layer:** [db.php](../db.php) — `getDishMargins`, `getCustomerCohorts`, `getHourlyHeatmap`, `forecastNextWeekRevenue`.
  - **Owner surface:** new tab in [/owner.php](../owner.php) → `?tab=analytics-v2`. Four cards: Margins, Cohorts, Heatmap, Forecast.
  - **API:** [api/analytics-v2.php](../api/analytics-v2.php) — single `action=bundle` call returns all four datasets in one roundtrip; individual `margins` / `cohorts` / `heatmap` / `forecast` actions available for scripted consumers.
  - **Frontend:** [js/owner-analytics-v2.js](../js/owner-analytics-v2.js) — vanilla canvas bar chart for margins, color-graded heatmap table, cohort retention grid, EWMA sparkline. Zero external JS dependencies (no Chart.js) — keeps strict CSP intact.
  - **Tests:** [tests/AnalyticsV2Test.php](../tests/AnalyticsV2Test.php) — MySQL-gated, requires MySQL 8 (for `JSON_TABLE` and CTEs).

## Purpose

The existing `/owner.php?tab=stats` gives the basics (revenue, orders, ABC). Tenants at scale (≥ 50 orders/day) need more:

- **Margin visibility.** Which dishes actually make money vs. which look like revenue but have thin margin once you deduct cost.
- **Retention.** First-time customers are expensive; a cohort matrix shows whether the platform is producing repeat business or just funneling one-off visits.
- **Capacity planning.** A 7×24 heatmap of order counts reveals when the kitchen is at peak vs. idle; drives staffing and stop-list timing.
- **Directional forecast.** A simple EWMA signal for next-week revenue — not a serious forecast, but enough to spot a sudden drop.

All four reports are owner-only and run on-demand; no aggregate tables, no cron precompute. Ok at ≤ 100k orders/tenant; larger tenants will want materialized views in a follow-up.

## Data Model

All four methods read existing tables. No new schema.

- `getDishMargins($fromDt, $toDt, $limit)` — JOINs `menu_items.cost` against the `orders.items` JSON blob via `JSON_TABLE`. Price × quantity is read out of the blob (history-accurate at order time); cost is the current `menu_items.cost` (mutates after edits — freezing cogs per order is a future refinement).
- `getCustomerCohorts($cohortsLimit)` — CTE in two stages: `first_orders` (min(created_at) per user) + `activity` (months-since-first per order). Returns a normalized 13-column (0..12) retention array per cohort.
- `getHourlyHeatmap($days)` — `GROUP BY WEEKDAY(created_at), HOUR(created_at)`. Returns a dense 7×24 grid plus `max` for color scaling.
- `forecastNextWeekRevenue($weeksBack)` — `GROUP BY YEARWEEK(created_at, 3)` over the last N weeks, then EWMA (alpha=0.5).

All four exclude orders with `status = 'отказ'` — rejected orders shouldn't pollute business analytics.

## Requirements

- MySQL 8.0.4+ (`JSON_TABLE`, CTEs). Earlier versions will error; `AnalyticsV2Test` self-skips on older servers.
- Owner or admin role (server-enforced in [api/analytics-v2.php](../api/analytics-v2.php)).

## UI Layout

One tab, four cards in a 2×2 grid (1×4 on mobile):

1. **Маржа по блюдам.** Horizontal bar chart (custom canvas) + sortable table with margin % pill (green ≥ 50%, yellow < 50%).
2. **Когорты клиентов.** Retention grid with HSL-graded cells; hover tooltip shows absolute active-user count.
3. **Heatmap час × день.** 7×24 table, cells graded by order count; empty cells blank.
4. **Прогноз выручки.** Big number + tiny sparkline of the last N weeks with an amber forecast tick to the right.

Window controls at the top: `С` date, `По` date, `Heatmap days`, `Пересчитать` button. Initial load is automatic when the tab becomes active (mutation-observer on the CSS tab state).

## API

```
GET /api/analytics-v2.php?action=bundle&from=2026-04-01&to=2026-04-23&heat_days=30
```

Response:

```json
{
  "success": true,
  "margins": [...],
  "cohorts": [...],
  "heatmap": { "grid": [[...24 ints...], ...7 rows...], "max": 12, "days": 30 },
  "forecast": { "weekly": [{"week":"202614","revenue":123}, ...], "forecast": 234.5, "alpha": 0.5 }
}
```

Individual actions (`margins`, `cohorts`, `heatmap`, `forecast`) return only the named key — useful for a cron export script that only needs one slice.

## Margin Calculation Caveats

- Cost is **current**, not frozen. Raising `menu_items.cost` today retroactively lowers yesterday's margin. For a faithful historical COGS, freeze `cost_per_unit × quantity` into the order on insert; a follow-up track can add `orders.items_cost` alongside `orders.items`.
- Modifier options are not yet part of the margin calculation — if a modifier adds 50 ₽ to price, that 50 ₽ is part of `orders.total` but not part of the per-item revenue in this report. Expect a Phase 6.4+ follow-up once modifiers write their own line items into the cart.
- Recipe-based COGS (Phase 6.2 inventory) is a more accurate alternative. Future iteration: `getDishMarginsFromRecipes()` that sums `ingredient.cost_per_unit × recipe.quantity` instead of using the flat `menu_items.cost`.

## Cohort Semantics

- Cohort = YYYY-MM of the user's first non-refused order.
- Month 0 = the cohort month itself (active users = cohort size).
- Month N = users from that cohort who placed ≥ 1 order in month N.
- Capped at 13 columns (0..12 months) and the last 12 cohorts by default.
- Guest orders (no `user_id`) are excluded — cohorts only make sense for identifiable customers.

## Heatmap Semantics

- Cells are **order counts**, not revenue. The pattern (when are we busy?) is what drives staffing decisions; add a revenue-heatmap later if someone asks.
- Day 0 = Monday (MySQL `WEEKDAY()` convention).
- Window defaults to 30 days; adjustable 7..365.

## Forecast Semantics

- Pure EWMA with α=0.5 over completed `YEARWEEK()` buckets.
- Not seasonality-aware, not holiday-aware. Good for "is this week trending down vs. stable".
- Phase 8.2 AI recommendations will replace this with something properly modelled.

## Security

- Role gate: `owner` or `admin`. Reject on any other.
- GET endpoint, no CSRF (read-only). Session cookie is the auth.
- Dates are validated against `YYYY-MM-DD`, then expanded server-side to half-open DATETIME intervals.

## Test Flow

1. Apply any outstanding migrations (analytics uses existing tables — no new migration).
2. Open `/owner.php?tab=analytics-v2` as owner.
3. Verify the four cards render with the current tenant's data.
4. Change window to last 7 days and click «Пересчитать» — counts shift accordingly.
5. For CI/MySQL-gated coverage, run:

```bash
CLEANMENU_TEST_MYSQL_DSN=mysql:host=127.0.0.1;dbname=cleanmenu_test;charset=utf8mb4 \
  composer test
```

## Known Gaps / Future Work

- **No conversion funnel.** Original plan mentioned a `menu views → cart → order → paid` funnel. That needs a `menu_page_views` log table (not shipped); deferred.
- **No export.** CSV / XLSX of margins or cohorts would be useful for offline modelling. Add on the existing [download-sample.php](../download-sample.php) pattern.
- **No drill-down.** Clicking a heatmap cell does not filter the rest of the tab. Interactive drill-down is a Phase 8.2+ polish.
- **Hardcoded α=0.5.** A tenant with highly seasonal traffic may prefer α=0.3; not a control today.
- **MySQL 8 only.** Ship-blocker for tenants on 5.7. If we hit that, rewrite `JSON_TABLE` as a `json_extract` + application-side aggregate.
- **Cost history.** See "Margin Calculation Caveats" above.

## Related Docs

- [inventory.md](./inventory.md) — ingredient-level cost data that a future recipe-based margin report will consume.
- [loyalty.md](./loyalty.md) — `total_spent` / cohort membership are relevant inputs once Phase 8 personalization kicks in.
- [kds.md](./kds.md) — `started_at` / `ready_at` timestamps unlock cook-time analytics in a later revision.
- [product-vision-2027.md](./product-vision-2027.md) §3 — Phase 6 scope.
