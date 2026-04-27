import { test, expect } from '@playwright/test';

/**
 * Header layout regression — verifies the Phase 8.1 fix.
 *
 * On desktop (>= 1251px) the header MUST be a single row, with the
 * "Ещё ▾" dropdown collapsing the secondary nav items. On tablet
 * and mobile the burger menu pattern stays intact.
 */

test('homepage header — full screenshot', async ({ page }, testInfo) => {
  await page.goto('/');
  await page.waitForLoadState('domcontentloaded');
  // Header animation has a 1s color transition; settle it before snapshot.
  await page.waitForTimeout(200);

  const header = page.locator('.header');
  await expect(header).toBeVisible();
  await expect(header).toHaveScreenshot(`homepage-header-${testInfo.project.name}.png`);
});

test('desktop header is one row', async ({ page, isMobile }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1920', 'desktop-only assertion');

  await page.goto('/');
  await page.waitForLoadState('domcontentloaded');

  // The whole .header-inner must occupy a single row → its height should
  // be roughly the height of the logo (~60px + padding), NOT 2× that.
  // We allow up to 110px to absorb design changes; flag two-row regressions.
  const inner = page.locator('.header .header-inner');
  const box = await inner.boundingBox();
  expect(box, 'header-inner must render').not.toBeNull();
  expect(box!.height, 'desktop header must be one row').toBeLessThanOrEqual(110);

  // The "Ещё" toggle must be visible at desktop width.
  await expect(page.locator('.nav-more-toggle')).toBeVisible();
});

test('Ещё dropdown opens and closes', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1920', 'desktop-only interaction');

  await page.goto('/');
  await page.waitForLoadState('domcontentloaded');

  const toggle = page.locator('.nav-more-toggle');
  const menu = page.locator('.nav-more-menu');

  await expect(toggle).toBeVisible();
  await expect(menu).toBeHidden();

  await toggle.click();
  await expect(menu).toBeVisible();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');

  // Reservation link must live INSIDE the dropdown
  await expect(menu.locator('a[href="/reservation.php"]')).toBeVisible();
  // Menu link must live OUTSIDE the dropdown
  await expect(page.locator('.nav > ul > li > a[href="/menu.php"]')).toBeVisible();

  // Escape closes
  await page.keyboard.press('Escape');
  await expect(menu).toBeHidden();
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
});

test('mobile burger still shows secondary items inline', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'mobile-375', 'mobile-only assertion');

  await page.goto('/');
  await page.waitForLoadState('domcontentloaded');

  // On mobile, the .nav-more wrapper dissolves via display:contents so
  // reservation/group/lang-picker render as flat siblings inside the
  // burger nav. They MUST be visible after the burger is toggled.
  const burger = page.locator('.mobile-menu-btn');
  await expect(burger).toBeVisible();
  await burger.click();

  const nav = page.locator('.nav.active');
  await expect(nav).toBeVisible();
  await expect(nav.locator('a[href="/reservation.php"]')).toBeVisible();
  await expect(nav.locator('a[href="/group.php"]')).toBeVisible();
  await expect(nav.locator('.lang-picker-link').first()).toBeVisible();

  // The .nav-more-toggle button MUST NOT show up on mobile.
  await expect(page.locator('.nav-more-toggle')).toBeHidden();
});
