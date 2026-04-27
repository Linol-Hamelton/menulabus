# Visual Regression Suite (Phase 8.4 + 10.10 baselines)

> **Status:** 42 baseline screenshots committed under `__snapshots__/`
> after the Phase 10 audit. Any future change that visually drifts from
> these baselines will be flagged by `npm run visual` with a pixel diff
> in the HTML report. Update intentionally with `npm run visual:update`.



Playwright-based visual snapshot suite covering the Phase 6-8 admin /
customer surfaces, run against a live tenant (default
`https://menu.labus.pro`).

## Setup (one-time)

1. `npm install` (Playwright is already in `devDependencies`)
2. `npx playwright install chromium` (downloads the browser engine)
3. Copy the env template and fill it in:
   ```bash
   cp tests/visual/.env.local.example .env.local
   # then edit .env.local — add real owner email + password
   ```

`.env.local` is `.gitignore`d. Owner credentials never leave the dev
machine. The first test run also writes
`tests/visual/.auth/owner.json` (an authenticated session) — also
ignored.

## Running

```bash
# Headless run, all viewports
npm run visual

# Headed (browser visible) — useful when triaging a failing snapshot
npm run visual:headed

# Update baseline snapshots after an intentional visual change
npm run visual:update

# Open the HTML report from the last run
npm run visual:report

# Clear stored auth (next run re-logs-in)
rm -rf tests/visual/.auth
```

## What the suite covers

- **Header layout** (`header.spec.ts`) — 4 tests × 3 viewports = 12
  - Single-row desktop header (`flex-wrap: nowrap` ≥1251px after Phase 8.1)
  - "Ещё ▾" dropdown opens/closes via JS toggle (Phase 8 hot-fix)
  - Mobile burger still surfaces reservation / group / lang picker
  - One `homepage-header-{viewport}.png` baseline per viewport
- **Admin / customer walk-through** (`admin-pages.spec.ts`) — 13 pages × 3 viewports = 39
  - Phase 6-8 surfaces: KDS picker, admin-{kitchen,inventory,loyalty,
    locations,marketing,staff,webhooks,waitlist}, owner analytics-v2,
    account loyalty + security tabs, group-order
  - CSP-violation guard at the end: console errors must be 0 across
    every route walk

**Total baselines:** 42 PNGs in `__snapshots__/`. Pixel-diff threshold
is 0.5% per snapshot (`maxDiffPixelRatio: 0.005` in
`playwright.config.ts`).

All viewports run on chromium (`Desktop Chrome` / `Pixel 5` device
profiles) — no webkit dependency, so `npx playwright install chromium`
is enough on a fresh machine.

## When a test fails

1. Open the HTML report: `npm run visual:report`
2. The report shows the expected (committed) snapshot, the actual
   render, and a pixel diff highlighter
3. **If the change is intentional** (e.g. you reshaped an admin
   table): `npm run visual:update`, review the new snapshots in
   `tests/visual/__snapshots__/`, commit them with a clear message
4. **If the change is unintentional**: fix the regression, re-run

## Regression workflow recommendation

- **Before every push that touches CSS / HTML / template PHP:** run
  `npm run visual` locally. Any unexpected diff = early signal.
- **After an intentional UX change:** `npm run visual:update`, eyeball
  the regenerated baselines (HTML report shows side-by-side old/new),
  commit the snapshot delta as part of the same PR.
- **Pre-push hook integration (future):** wire `npm run visual` into
  `.githooks/pre-push` similarly to the existing PHP lint / docs-drift
  guards. Skipped on machines without Playwright installed (graceful
  fallback message). Tracked in [docs/ux-ui-improvement-roadmap.md].
- **CI integration (future):** GitHub Actions runner with
  `actions/setup-node` + `npx playwright install chromium` + `npm run
  visual`. Failures block merge to `main`; baselines updated
  intentionally are part of the PR review.

## Adding a new surface

Append to the `PAGES` array in
[`admin-pages.spec.ts`](./admin-pages.spec.ts):

```ts
{ name: 'admin-new-thing', url: '/admin-new-thing.php' }
```

Run `npm run visual:update` to capture baselines for the new entry, then
commit them.
