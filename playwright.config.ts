import { defineConfig, devices } from '@playwright/test';
import { readFileSync, existsSync } from 'node:fs';

// Tiny .env.local loader (avoids pulling dotenv as a dep).
// Lines like `KEY=value` populate process.env if not already set.
const envPath = '.env.local';
if (existsSync(envPath)) {
  for (const line of readFileSync(envPath, 'utf8').split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
    if (!m || line.trim().startsWith('#')) continue;
    if (process.env[m[1]] === undefined) process.env[m[1]] = m[2].replace(/^["'](.*)["']$/, '$1');
  }
}

/**
 * Playwright config for CleanMenu visual regression (Phase 8.4).
 *
 * Three viewport projects mirror the breakpoints we actually care about:
 *   - desktop-1920: large desktop, header should stay one row
 *   - tablet-768:   transition zone where the burger menu kicks in
 *   - mobile-375:   smallest realistic phone viewport
 *
 * Owner login is performed once in tests/visual/global.setup.ts and the
 * authenticated storage state is reused across every spec — keeps the
 * suite fast and avoids hammering /auth.php on every run.
 *
 * Credentials read from .env.local (NOT committed):
 *   CLEANMENU_OWNER_EMAIL=...
 *   CLEANMENU_OWNER_PASSWORD=...
 *   CLEANMENU_BASE_URL=https://menu.labus.pro    (override for staging)
 */

const BASE_URL = process.env.CLEANMENU_BASE_URL ?? 'https://menu.labus.pro';
const STORAGE_STATE = 'tests/visual/.auth/owner.json';

export default defineConfig({
  testDir: 'tests/visual',
  // Snapshot dir lives next to the spec — easy to spot diffs in PR review.
  snapshotPathTemplate: '{testDir}/__snapshots__/{testFilePath}/{arg}{ext}',
  fullyParallel: true,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: [['html', { open: 'never' }], ['list']],

  use: {
    baseURL: BASE_URL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    // Default per-test timeout — visual regression runs are mostly waits.
    actionTimeout: 10_000,
    navigationTimeout: 30_000,
    // Block third-party scripts by default — keeps snapshots stable across
    // runs (no analytics/ads jiggle pixels).
    extraHTTPHeaders: { 'X-Visual-Regression': '1' },
  },

  expect: {
    // Pixel-perfect for these surfaces; bump to 0.02 if anti-aliasing flakes.
    toHaveScreenshot: {
      maxDiffPixelRatio: 0.005,
      animations: 'disabled',
    },
  },

  projects: [
    // ── auth setup ─────────────────────────────────────────────────
    {
      name: 'setup',
      testMatch: /global\.setup\.ts/,
    },

    // ── viewports (depend on setup so storageState exists) ─────────
    {
      name: 'desktop-1920',
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1920, height: 1080 },
        storageState: STORAGE_STATE,
      },
      dependencies: ['setup'],
    },
    // tablet-768 / mobile-375 use Pixel 5 / Pixel 7 (Chrome on Android,
     // chromium-based) instead of iPad/iPhone (webkit). The breakpoint
     // behavior is what matters for visual regression — touch caps and
     // user-agent are secondary, and not requiring webkit keeps the
     // browser footprint at chromium-only on dev machines and CI.
    {
      name: 'tablet-768',
      use: {
        ...devices['Pixel 5'],
        viewport: { width: 768, height: 1024 },
        storageState: STORAGE_STATE,
      },
      dependencies: ['setup'],
    },
    {
      name: 'mobile-375',
      use: {
        ...devices['Pixel 5'],
        viewport: { width: 375, height: 667 },
        storageState: STORAGE_STATE,
      },
      dependencies: ['setup'],
    },
  ],
});
