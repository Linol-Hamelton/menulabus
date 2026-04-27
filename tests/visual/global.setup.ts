import { test as setup, expect } from '@playwright/test';
import { existsSync, mkdirSync } from 'node:fs';
import path from 'node:path';

/**
 * One-time owner login. Saves cookies + localStorage to disk so every
 * other spec can reuse the session without re-typing credentials at
 * `/auth.php`. Re-runs whenever the storage state file is missing
 * (e.g. first run, or after `npm run visual:reset`).
 *
 * Credentials live in .env.local (NOT committed). If they're missing
 * the suite fails fast with a clear message rather than silently
 * running un-authenticated.
 */

const STORAGE_PATH = 'tests/visual/.auth/owner.json';

setup('authenticate as owner', async ({ page, baseURL }) => {
  const email = process.env.CLEANMENU_OWNER_EMAIL;
  const password = process.env.CLEANMENU_OWNER_PASSWORD;
  if (!email || !password) {
    throw new Error(
      'Missing CLEANMENU_OWNER_EMAIL / CLEANMENU_OWNER_PASSWORD. Add them to .env.local.'
    );
  }

  // Make sure the auth directory exists before storageState() writes to it.
  const dir = path.dirname(STORAGE_PATH);
  if (!existsSync(dir)) mkdirSync(dir, { recursive: true });

  await page.goto(`${baseURL}/auth.php`);

  // The form selectors are intentionally permissive — the auth page has
  // gone through a few revisions; this matches the current shape but
  // tolerates whitespace / casing changes.
  await page.locator('input[type="email"], input[name="email"]').first().fill(email);
  await page.locator('input[type="password"], input[name="password"]').first().fill(password);
  await page.locator('button[type="submit"], input[type="submit"]').first().click();

  // After successful login the user lands on /account.php. Wait for that
  // navigation rather than a fixed sleep — keeps the suite resilient.
  await page.waitForURL(/\/(account|owner|admin-)/, { timeout: 15_000 });

  // Sanity: confirm we have an authenticated session.
  await expect(page.locator('body')).not.toContainText(/неверный|invalid|incorrect/i);

  await page.context().storageState({ path: STORAGE_PATH });
});
