import { test, expect } from '@playwright/test';

/**
 * Walk-through screenshots for every Phase 6-8 admin / customer surface
 * shipped without design-token alignment. Each surface gets one full-page
 * snapshot per viewport project, named so visual diffs in PR review are
 * unambiguous.
 *
 * Add new surfaces to PAGES below. Skip rules per page (`skipOn`) let us
 * exclude routes that 404 on a given form factor (none currently — the
 * shell renders on all three viewport sizes).
 */

type Page = { name: string; url: string; skipOn?: string[] };

const PAGES: Page[] = [
  // Owner dashboards
  { name: 'admin-kitchen',   url: '/admin/kitchen.php' },
  { name: 'admin-inventory', url: '/admin/inventory.php' },
  { name: 'admin-loyalty',   url: '/admin/loyalty.php' },
  { name: 'admin-locations', url: '/admin/locations.php' },
  { name: 'admin-marketing', url: '/admin/marketing.php' },
  { name: 'admin-staff',     url: '/admin/staff.php' },
  { name: 'admin-webhooks',  url: '/admin/webhooks.php' },
  { name: 'admin-waitlist',  url: '/admin/waitlist.php' },

  // KDS
  { name: 'kds-station-picker', url: '/kds.php' },

  // Owner analytics v2
  { name: 'owner-analytics-v2', url: '/owner.php?tab=analytics-v2' },

  // Customer surfaces
  { name: 'account-loyalty',  url: '/account.php?tab=loyalty' },
  { name: 'account-security', url: '/account.php?tab=security' },
  { name: 'group-order',      url: '/group.php' },
];

for (const p of PAGES) {
  test(`${p.name} — full page screenshot`, async ({ page }, testInfo) => {
    if (p.skipOn?.includes(testInfo.project.name)) {
      test.skip(true, `skipped on ${testInfo.project.name}`);
    }

    await page.goto(p.url);
    await page.waitForLoadState('domcontentloaded');
    // Most admin pages defer-load JS that builds tables; give it a moment.
    await page.waitForTimeout(500);

    // owner-analytics-v2 renders forecast/cohort cards whose total
    // height jitters by a few pixels run-to-run (the daily revenue
    // forecast computes from "today" — a day boundary crossing during
    // the run shifts a row). fullPage screenshots fail with a height
    // mismatch (e.g. 1566 vs 1560) which can't be tolerated by
    // maxDiffPixelRatio at all, since that only applies when both
    // dimensions match. Clip to viewport for this one spec — top of
    // page is what changes most rarely and what Playwright reports
    // already capture well.
    const opts: { fullPage: boolean; maxDiffPixelRatio?: number } = { fullPage: true };
    if (p.name === 'owner-analytics-v2') {
      opts.fullPage = false;
      opts.maxDiffPixelRatio = 0.015;
    }

    await expect(page).toHaveScreenshot(
      `${p.name}-${testInfo.project.name}.png`,
      opts
    );
  });
}

test('no CSP violations on any admin surface', async ({ page }) => {
  const violations: string[] = [];
  page.on('console', (msg) => {
    const text = msg.text();
    if (msg.type() === 'error' && /content security policy/i.test(text)) {
      violations.push(text);
    }
  });

  for (const p of PAGES) {
    await page.goto(p.url, { waitUntil: 'domcontentloaded' });
  }

  expect(violations, 'CSP must not fire on any walk-through page').toEqual([]);
});
