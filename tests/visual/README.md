# Visual Regression Suite (Phase 8.4)

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

- **Header layout** (`header.spec.ts`)
  - Single-row desktop header, "Ещё ▾" toggle behavior
  - Mobile burger still surfaces reservation / group / lang picker
- **Admin walk-through** (`admin-pages.spec.ts`)
  - Full-page screenshots of every Phase 6-8 surface across desktop /
    tablet / mobile (3 viewports × 13 pages = 39 snapshots)
  - CSP-violation guard: console errors must be empty across all routes

## When a test fails

1. Open the HTML report: `npm run visual:report`
2. The report shows the expected (committed) snapshot, the actual
   render, and a pixel diff highlighter
3. **If the change is intentional** (e.g. you reshaped an admin
   table): `npm run visual:update`, review the new snapshots in
   `tests/visual/__snapshots__/`, commit them with a clear message
4. **If the change is unintentional**: fix the regression, re-run

## Adding a new surface

Append to the `PAGES` array in
[`admin-pages.spec.ts`](./admin-pages.spec.ts):

```ts
{ name: 'admin-new-thing', url: '/admin-new-thing.php' }
```

Run `npm run visual:update` to capture baselines for the new entry, then
commit them.
