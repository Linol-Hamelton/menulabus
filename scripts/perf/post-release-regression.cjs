const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const ROOT_DIR = process.cwd();
const STAMP = new Date().toISOString().replace(/[:]/g, '').replace(/\..+/, '').replace('T', '-');
const OUT_DIR = path.resolve(process.env.CLEANMENU_REGRESSION_OUT_DIR || path.join(ROOT_DIR, 'data', 'logs', 'release-regression', `run-${STAMP}`));

fs.mkdirSync(OUT_DIR, { recursive: true });

const CONFIG = {
  providerDomain: process.env.CLEANMENU_PROVIDER_DOMAIN || 'menu.labus.pro',
  tenantDomain: process.env.CLEANMENU_TENANT_DOMAIN || 'test.milyidom.com',
  providerOwnerEmail: process.env.CLEANMENU_PROVIDER_OWNER_EMAIL || '',
  providerOwnerPassword: process.env.CLEANMENU_PROVIDER_OWNER_PASSWORD || '',
  tenantAdminEmail: process.env.CLEANMENU_TENANT_ADMIN_EMAIL || 'demo.admin@tenant.local',
  tenantAdminPassword: process.env.CLEANMENU_TENANT_ADMIN_PASSWORD || 'DemoTenant2026!',
  tenantEmployeeEmail: process.env.CLEANMENU_TENANT_EMPLOYEE_EMAIL || 'demo.employee@tenant.local',
  tenantEmployeePassword: process.env.CLEANMENU_TENANT_EMPLOYEE_PASSWORD || 'DemoTenant2026!',
  tenantCustomerEmail: process.env.CLEANMENU_TENANT_CUSTOMER_EMAIL || 'guest.anna@tenant.local',
  tenantCustomerPassword: process.env.CLEANMENU_TENANT_CUSTOMER_PASSWORD || 'DemoTenant2026!',
  runOrderRegression: process.env.CLEANMENU_RUN_ORDER_REGRESSION === '1',
  requireProviderOwnerAuth: process.env.CLEANMENU_REQUIRE_PROVIDER_OWNER_AUTH === '1',
};

const report = {
  startedAt: new Date().toISOString(),
  ok: false,
  config: {
    providerDomain: CONFIG.providerDomain,
    tenantDomain: CONFIG.tenantDomain,
    runOrderRegression: CONFIG.runOrderRegression,
    requireProviderOwnerAuth: CONFIG.requireProviderOwnerAuth,
    providerOwnerAuthProvided: Boolean(CONFIG.providerOwnerEmail && CONFIG.providerOwnerPassword),
  },
  steps: [],
  visualChecks: [],
  notes: [],
  orders: {},
  artifacts: [],
};

function addStep(name, ok, details = {}) {
  report.steps.push({ name, ok, ...details });
  console.log(`[${ok ? 'OK' : 'FAIL'}] ${name}${details.summary ? ` :: ${details.summary}` : ''}`);
}

function addNote(note) {
  report.notes.push(note);
  console.log(`[NOTE] ${note}`);
}

function addVisualCheck(name, ok, details = {}) {
  report.visualChecks.push({ name, ok, ...details });
  console.log(`[VISUAL ${ok ? 'OK' : 'FAIL'}] ${name}${details.summary ? ` :: ${details.summary}` : ''}`);
}

async function screenshot(page, name) {
  const file = path.join(OUT_DIR, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true }).catch(() => {});
  report.artifacts.push(file);
  return file;
}

async function withContext(options, fn) {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, ...options });
  try {
    return await fn(context);
  } finally {
    await context.close().catch(() => {});
    await browser.close().catch(() => {});
  }
}

async function bodyText(page) {
  return page.locator('body').innerText().catch(() => '');
}

async function visualMeta(page) {
  return page.evaluate(() => {
    const tabRail = document.querySelector('.menu-tabs-container');
    const railStyle = tabRail ? window.getComputedStyle(tabRail) : null;
    const activeControl = document.querySelector('.menu-tabs .tab-btn.active, .admin-tabs .admin-tab-btn.active');
    const railRect = tabRail ? tabRail.getBoundingClientRect() : null;
    return {
      title: document.title,
      bodyClass: document.body.className,
      hasTabRail: Boolean(tabRail),
      tabRailPosition: railStyle ? railStyle.position : null,
      tabRailTop: railStyle ? railStyle.top : null,
      tabRailBottom: railStyle ? railStyle.bottom : null,
      tabRailViewportTop: railRect ? Math.round(railRect.top) : null,
      tabRailViewportBottom: railRect ? Math.round(railRect.bottom) : null,
      activeControlLabel: activeControl ? activeControl.textContent.trim() : null,
    };
  }).catch(() => ({
    title: '',
    bodyClass: '',
    hasTabRail: false,
    tabRailPosition: null,
    tabRailTop: null,
    tabRailBottom: null,
    tabRailViewportTop: null,
    tabRailViewportBottom: null,
    activeControlLabel: null,
  }));
}

async function waitForVisualReady(page) {
  await page.waitForLoadState('domcontentloaded').catch(() => {});
  await page.waitForLoadState('networkidle').catch(() => {});

  await page.waitForFunction(() => {
    const link = [...document.querySelectorAll('link[rel="stylesheet"]')]
      .find(node => (node.href || '').includes('/css/ui-ux-polish.css'));
    const sheet = [...document.styleSheets]
      .find(node => (node.href || '').includes('/css/ui-ux-polish.css'));
    return Boolean(link && sheet);
  }, { timeout: 10000 }).catch(() => {});

  await page.evaluate(async () => {
    if (document.fonts && document.fonts.ready) {
      await document.fonts.ready.catch(() => {});
    }
  }).catch(() => {});

  let previousKey = '';
  let stableFrames = 0;

  for (let attempt = 0; attempt < 12; attempt += 1) {
    const meta = await visualMeta(page);
    const currentKey = meta.hasTabRail
      ? [
          meta.tabRailPosition,
          meta.tabRailTop,
          meta.tabRailBottom,
          meta.tabRailViewportTop,
          meta.tabRailViewportBottom,
        ].join('|')
      : 'no-rail';

    if (currentKey === previousKey) {
      stableFrames += 1;
    } else {
      previousKey = currentKey;
      stableFrames = 0;
    }

    if (!meta.hasTabRail || stableFrames >= 2) {
      return meta;
    }

    await page.waitForTimeout(220);
  }

  return visualMeta(page);
}

async function captureVisualSnapshot({ url, name, storageState, viewport, prepare }) {
  return withContext({ storageState, viewport }, async (context) => {
    const page = await context.newPage();
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await waitForVisualReady(page);
    if (prepare) {
      await prepare(page, context);
      await waitForVisualReady(page);
    }
    const meta = await waitForVisualReady(page);
    const artifact = await screenshot(page, name);
    const ok = !meta.hasTabRail || meta.tabRailPosition !== 'fixed';
    addVisualCheck(name, ok, {
      summary: `${new URL(url).pathname} @ ${viewport.width}x${viewport.height}${meta.hasTabRail ? `, tabs=${meta.tabRailPosition}` : ''}`,
      artifact,
      route: new URL(url).pathname,
      viewport: `${viewport.width}x${viewport.height}`,
      meta,
    });
  });
}

function baseUrl(domain) {
  return domain.startsWith('http') ? domain : `https://${domain}`;
}

async function loginToDomain(base, email, password, statePath) {
  return withContext({}, async (context) => {
    const page = await context.newPage();
    await page.goto(`${base}/auth.php?mode=login`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null),
      page.click('button[type="submit"]'),
    ]);
    await page.waitForTimeout(1000);
    if (page.url().includes('/auth.php')) {
      await screenshot(page, `${email.replace(/[^a-z0-9]+/gi, '_').toLowerCase()}-login-failed`);
      throw new Error(`Login failed for ${email}`);
    }
    if (statePath) {
      await context.storageState({ path: statePath });
    }
    return page.url();
  });
}

async function providerPublicAndAuthGates(providerBase) {
  await withContext({}, async (context) => {
    const page = await context.newPage();
    await page.goto(`${providerBase}/`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    addStep('Provider root', (await bodyText(page)).toLowerCase().includes('labus'), { summary: 'provider landing loads' });

    await page.goto(`${providerBase}/menu.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const menuText = await bodyText(page);
    addStep('Provider menu', menuText.includes('SEO оптимизация') || menuText.includes('Пакеты'), { summary: 'provider catalog visible' });

    await page.goto(`${providerBase}/cart.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    addStep('Provider cart page', (await bodyText(page)).includes('Заказ'), { summary: 'cart shell opens' });

    await page.goto(`${providerBase}/auth.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    addStep('Provider auth page', (await bodyText(page)).includes('Вход'), { summary: 'auth page reachable' });

    for (const route of ['monitor.php', 'opcache-status.php', 'file-manager.php?action=get_fonts']) {
      const resp = await page.goto(`${providerBase}/${route}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
      const finalUrl = page.url();
      const status = resp ? resp.status() : 0;
      addStep(`Provider auth-gate ${route}`, finalUrl.includes('/auth.php') || status === 302 || status === 401 || status === 403, {
        summary: `status=${status}, final=${finalUrl}`,
      });
    }

    const clearResp = await page.goto(`${providerBase}/clear-cache.php?scope=server`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const clearText = await bodyText(page);
    addStep('Provider clear-cache method guard', (clearResp ? clearResp.status() : 0) === 405 || clearText.includes('Method Not Allowed'), {
      summary: `status=${clearResp ? clearResp.status() : 0}`,
    });
  });
}

async function tenantRoleLoginStates(tenantBase) {
  const adminState = path.join(OUT_DIR, 'tenant-admin-state.json');
  const employeeState = path.join(OUT_DIR, 'tenant-employee-state.json');
  const customerState = path.join(OUT_DIR, 'tenant-customer-state.json');

  const adminUrl = await loginToDomain(tenantBase, CONFIG.tenantAdminEmail, CONFIG.tenantAdminPassword, adminState);
  addStep('Tenant admin login', true, { summary: adminUrl });
  const employeeUrl = await loginToDomain(tenantBase, CONFIG.tenantEmployeeEmail, CONFIG.tenantEmployeePassword, employeeState);
  addStep('Tenant employee login', true, { summary: employeeUrl });
  const customerUrl = await loginToDomain(tenantBase, CONFIG.tenantCustomerEmail, CONFIG.tenantCustomerPassword, customerState);
  addStep('Tenant customer login', true, { summary: customerUrl });

  return { adminState, employeeState, customerState };
}

async function tenantSurfaceChecks(tenantBase, states) {
  await withContext({}, async (context) => {
    const page = await context.newPage();
    await page.goto(`${tenantBase}/`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const homepageText = await bodyText(page);
    addStep('Tenant homepage', homepageText.includes('DOM') && !homepageText.includes('SEO оптимизация'), { summary: 'restaurant-facing homepage' });
    await screenshot(page, 'tenant-homepage');

    await page.goto(`${tenantBase}/menu.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.fill('#menuQuickSearch', 'шашлык').catch(() => {});
    await page.waitForTimeout(400);
    const menuText = await bodyText(page);
    addStep('Tenant menu search and categories', menuText.includes('Куриный шашлык') && menuText.includes('Пицца'), { summary: 'menu search and categories work' });
    await screenshot(page, 'tenant-menu');

    await page.goto(`${tenantBase}/auth.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    addStep('Tenant auth page', (await bodyText(page)).includes('Вход'), { summary: 'auth page reachable' });
  });

  await withContext({ storageState: states.adminState }, async (context) => {
    const page = await context.newPage();

    await page.goto(`${tenantBase}/admin-menu.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    for (const tab of ['design', 'dishes']) {
      const locator = page.locator(`.admin-tab-btn[data-tab="${tab}"]`);
      if (await locator.count()) {
        await locator.click();
        await page.waitForTimeout(250);
      }
    }
    addStep('Tenant admin menu', (await bodyText(page)).includes('Блюда') && (await bodyText(page)).includes('Дизайн'), { summary: 'admin tabs work' });
    await screenshot(page, 'tenant-admin-menu');

    await page.goto(`${tenantBase}/help.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const helpTab = page.locator('a[href="#menu-presentation"]').first();
    if (await helpTab.count()) {
      await helpTab.click();
      await page.waitForTimeout(300);
    }
    addStep('Tenant help page', (await bodyText(page)).includes('Центр помощи'), { summary: 'help page reachable' });

    await page.goto(`${tenantBase}/qr-print.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const qrText = await bodyText(page);
    addStep('Tenant qr-print page', qrText.includes('QR-коды'), { summary: 'qr shell reachable' });
  });

  await withContext({ storageState: states.employeeState }, async (context) => {
    const page = await context.newPage();
    await page.goto(`${tenantBase}/employee.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    for (const tab of ['готовим', 'доставляем', 'завершён', 'отказ', 'столы']) {
      const locator = page.locator(`.tab-btn[data-tab="${tab}"]`);
      if (await locator.count()) {
        await locator.click();
        await page.waitForTimeout(250);
      }
    }
    addStep('Tenant employee page', (await bodyText(page)).includes('Столы'), { summary: 'employee tabs clickable' });
    await screenshot(page, 'tenant-employee');
  });

  await withContext({ storageState: states.customerState }, async (context) => {
    const page = await context.newPage();
    await page.goto(`${tenantBase}/account.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    for (const tab of ['security', 'menu', 'updates']) {
      await page.goto(`${tenantBase}/account.php?tab=${tab}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await page.waitForTimeout(250);
      addStep(`Tenant customer account tab ${tab}`, (await bodyText(page)).length > 0, { summary: page.url() });
    }
    addStep('Tenant customer account', true, { summary: 'account tabs reachable' });
    await page.goto(`${tenantBase}/customer_orders.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    addStep('Tenant customer orders page', (await bodyText(page)).includes('Ваши заказы'), { summary: 'customer orders page opens' });
  });
}

async function providerOwnerChecks(providerBase) {
  if (!CONFIG.providerOwnerEmail || !CONFIG.providerOwnerPassword) {
    const message = 'Provider owner credentials were not provided; authenticated provider-owner branch skipped.';
    if (CONFIG.requireProviderOwnerAuth) {
      addStep('Provider owner credentials present', false, { summary: message });
      throw new Error(message);
    }
    addNote(message);
    return null;
  }

  const statePath = path.join(OUT_DIR, 'provider-owner-state.json');
  const loginUrl = await loginToDomain(providerBase, CONFIG.providerOwnerEmail, CONFIG.providerOwnerPassword, statePath);
  addStep('Provider owner login', true, { summary: loginUrl });

  await withContext({ storageState: statePath }, async (context) => {
    const page = await context.newPage();
    page.on('dialog', async dialog => { await dialog.accept(); });

    await page.goto(`${providerBase}/menu.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const menuText = await bodyText(page);
    addStep('Provider owner menu page', menuText.includes('SEO оптимизация') || menuText.includes('Пакеты'), { summary: page.url() });
    await page.fill('#menuQuickSearch', 'seo').catch(() => {});
    await page.waitForTimeout(250);
    addStep('Provider owner menu quick search', (await bodyText(page)).toLowerCase().includes('seo'), { summary: 'search interaction' });

    const buyBtn = page.locator('[data-product-id]').first();
    if (await buyBtn.count()) {
      await buyBtn.evaluate(node => node.click());
      await page.waitForTimeout(400);
      await page.goto(`${providerBase}/cart.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
      const cartText = await bodyText(page);
      addStep('Provider owner cart after add-to-cart', cartText.includes('Итого') || cartText.includes('Заказ'), { summary: 'cart shell populated' });
    } else {
      addStep('Provider owner cart after add-to-cart', false, { summary: 'no buy button found' });
    }
    await screenshot(page, 'provider-owner-menu');

    await page.goto(`${providerBase}/account.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    addStep('Provider owner account', (await bodyText(page)).includes('Аккаунт') || (await bodyText(page)).includes('Профиль'), { summary: page.url() });
    for (const tab of ['security', 'menu', 'updates']) {
      await page.goto(`${providerBase}/account.php?tab=${tab}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await page.waitForTimeout(250);
      addStep(`Provider owner account tab ${tab}`, (await bodyText(page)).length > 0, { summary: page.url() });
    }
    await screenshot(page, 'provider-owner-account');

    await page.goto(`${providerBase}/help.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const helpTab = page.locator('a[href="#menu-presentation"]').first();
    if (await helpTab.count()) {
      await helpTab.click();
      await page.waitForTimeout(250);
    }
    addStep('Provider owner help page', (await bodyText(page)).includes('Центр помощи'), { summary: page.url() });

    for (const route of ['owner.php', 'admin-menu.php', 'employee.php', 'customer_orders.php', 'qr-print.php', 'monitor.php', 'opcache-status.php']) {
      await page.goto(`${providerBase}/${route}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await page.waitForTimeout(500);
      addStep(`Provider owner page ${route}`, page.url().includes(route), { summary: page.url() });
    }

    await page.goto(`${providerBase}/admin-menu.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    for (const tab of ['design', 'dishes']) {
      const locator = page.locator(`.admin-tab-btn[data-tab="${tab}"]`);
      if (await locator.count()) {
        await locator.click();
        await page.waitForTimeout(250);
        addStep(`Provider owner admin-menu tab ${tab}`, true, { summary: 'tab clicked' });
      }
    }

    await page.goto(`${providerBase}/employee.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    for (const tab of ['готовим', 'доставляем', 'завершён', 'отказ', 'столы']) {
      const locator = page.locator(`.tab-btn[data-tab="${tab}"]`);
      if (await locator.count()) {
        await locator.click();
        await page.waitForTimeout(250);
        addStep(`Provider owner employee tab ${tab}`, true, { summary: 'tab clicked' });
      }
    }

    await page.goto(`${providerBase}/customer_orders.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const historyBtn = page.locator('.customer-orders-switch-btn[data-order-view="history"]').first();
    if (await historyBtn.count()) {
      await historyBtn.click();
      await page.waitForTimeout(250);
      addStep('Provider owner customer orders history toggle', true, { summary: 'toggle clicked' });
    }

    await page.goto(`${providerBase}/qr-print.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const qrApply = page.locator('button:has-text("Применить")').first();
    if (await qrApply.count()) {
      await qrApply.click().catch(() => {});
      await page.waitForTimeout(250);
      addStep('Provider owner qr-print apply button', true, { summary: 'button clicked' });
    }

    await page.goto(`${providerBase}/monitor.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const monitorRefresh = page.locator('button:has-text("Обновить метрики")').first();
    if (await monitorRefresh.count()) {
      await monitorRefresh.click();
      await page.waitForTimeout(800);
      addStep('Provider owner monitor refresh metrics button', true, { summary: 'button clicked' });
    }
    const monitorApi = page.locator('button:has-text("API Endpoint")').first();
    if (await monitorApi.count()) {
      await monitorApi.click();
      await page.waitForTimeout(500);
      addStep('Provider owner monitor API endpoint button', true, { summary: 'button clicked' });
    }

    await page.goto(`${providerBase}/opcache-status.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const opcacheRefresh = page.locator('button:has-text("Обновить статистику")').first();
    if (await opcacheRefresh.count()) {
      await opcacheRefresh.click();
      await page.waitForTimeout(800);
      addStep('Provider owner opcache refresh button', true, { summary: 'button clicked' });
    }
    const opcacheConfig = page.locator('button:has-text("Показать конфигурацию")').first();
    if (await opcacheConfig.count()) {
      await opcacheConfig.click();
      await page.waitForTimeout(500);
      addStep('Provider owner opcache show config button', true, { summary: 'button clicked' });
    }

    const endpointPage = await context.newPage();
    const fileManagerResp = await endpointPage.goto(`${providerBase}/file-manager.php?action=get_fonts`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const fileManagerText = await bodyText(endpointPage);
    addStep('Provider owner file-manager get_fonts', (fileManagerResp ? fileManagerResp.status() : 0) === 200 && fileManagerText.trim().startsWith('{') && fileManagerText.includes('fonts'), {
      summary: `status=${fileManagerResp ? fileManagerResp.status() : 0}`,
    });

    const clearResp = await endpointPage.goto(`${providerBase}/clear-cache.php?scope=server`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const clearText = await bodyText(endpointPage);
    addStep('Provider owner clear-cache method guard', (clearResp ? clearResp.status() : 0) === 405 || clearText.includes('Method Not Allowed'), {
      summary: `status=${clearResp ? clearResp.status() : 0}`,
    });
  });

  return statePath;
}

async function captureVisualSignoff(providerBase, tenantBase, tenantStates, providerOwnerState) {
  addNote('Capturing mandatory visual sign-off screenshots for desktop and mobile.');

  const desktop = { width: 1440, height: 1000 };
  const mobile = { width: 390, height: 844 };

  await captureVisualSnapshot({
    url: `${providerBase}/menu.php`,
    name: 'visual-provider-menu-desktop',
    viewport: desktop,
  });
  await captureVisualSnapshot({
    url: `${providerBase}/menu.php`,
    name: 'visual-provider-menu-mobile',
    viewport: mobile,
  });
  await captureVisualSnapshot({
    url: `${tenantBase}/`,
    name: 'visual-tenant-home-desktop',
    viewport: desktop,
  });
  await captureVisualSnapshot({
    url: `${tenantBase}/`,
    name: 'visual-tenant-home-mobile',
    viewport: mobile,
  });
  await captureVisualSnapshot({
    url: `${tenantBase}/menu.php`,
    name: 'visual-tenant-menu-desktop',
    viewport: desktop,
  });
  await captureVisualSnapshot({
    url: `${tenantBase}/menu.php`,
    name: 'visual-tenant-menu-mobile',
    viewport: mobile,
  });
  await captureVisualSnapshot({
    url: `${tenantBase}/cart.php`,
    name: 'visual-tenant-cart-desktop',
    viewport: desktop,
  });
  await captureVisualSnapshot({
    url: `${tenantBase}/customer_orders.php`,
    name: 'visual-tenant-customer-orders-desktop',
    storageState: tenantStates.customerState,
    viewport: desktop,
  });
  await captureVisualSnapshot({
    url: `${tenantBase}/employee.php`,
    name: 'visual-tenant-employee-mobile',
    storageState: tenantStates.employeeState,
    viewport: mobile,
  });
  await captureVisualSnapshot({
    url: `${tenantBase}/admin-menu.php`,
    name: 'visual-tenant-admin-mobile',
    storageState: tenantStates.adminState,
    viewport: mobile,
  });
  await captureVisualSnapshot({
    url: `${tenantBase}/help.php`,
    name: 'visual-tenant-help-desktop',
    storageState: tenantStates.adminState,
    viewport: desktop,
    prepare: async (page) => {
      const tab = page.locator('a[href="#menu-presentation"]').first();
      if (await tab.count()) {
        await tab.click();
      }
    },
  });
  await captureVisualSnapshot({
    url: `${tenantBase}/qr-print.php`,
    name: 'visual-tenant-qr-print-desktop',
    storageState: tenantStates.adminState,
    viewport: desktop,
  });

  if (providerOwnerState) {
    await captureVisualSnapshot({
      url: `${providerBase}/account.php`,
      name: 'visual-provider-account-desktop',
      storageState: providerOwnerState,
      viewport: desktop,
    });
    await captureVisualSnapshot({
      url: `${providerBase}/employee.php`,
      name: 'visual-provider-employee-mobile',
      storageState: providerOwnerState,
      viewport: mobile,
    });
    await captureVisualSnapshot({
      url: `${providerBase}/admin-menu.php`,
      name: 'visual-provider-admin-mobile',
      storageState: providerOwnerState,
      viewport: mobile,
    });
    await captureVisualSnapshot({
      url: `${providerBase}/monitor.php`,
      name: 'visual-provider-monitor-desktop',
      storageState: providerOwnerState,
      viewport: desktop,
    });
    await captureVisualSnapshot({
      url: `${providerBase}/opcache-status.php`,
      name: 'visual-provider-opcache-desktop',
      storageState: providerOwnerState,
      viewport: desktop,
    });
  }
}

async function createTenantOrder(tenantBase, statePath, label, options) {
  return withContext({ storageState: statePath }, async (context) => {
    const page = await context.newPage();
    page.on('dialog', async dialog => { await dialog.accept(); });
    await page.goto(`${tenantBase}/menu.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(700);
    await page.locator('[data-product-id]').nth(options.productIndex ?? 0).click();
    await page.waitForTimeout(400);
    await page.goto(`${tenantBase}/cart.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(500);

    await page.click('#checkout-btn');
    if (options.exerciseOptions) {
      await page.click('.delivery-option[data-type="bar"]').catch(() => {});
      await page.waitForTimeout(120);
      await page.click('.delivery-option[data-type="table"]').catch(() => {});
      await page.fill('#manualTableNumber', '12').catch(() => {});
      await page.click('#manualTableBtn').catch(() => {});
      await page.waitForTimeout(120);
      await page.click('.delivery-option[data-type="delivery"]').catch(() => {});
      if (options.address) {
        await page.fill('#deliveryAddress', options.address).catch(() => {});
      }
      await page.waitForTimeout(120);
    }

    await page.click(`.delivery-option[data-type="${options.deliveryType}"]`);
    if (options.deliveryType === 'delivery' && options.address) {
      await page.fill('#deliveryAddress', options.address);
    }
    await page.click(`.payment-option[data-method="${options.paymentMethod || 'cash'}"]`);
    if (options.tipPct) {
      await page.click(`.tips-option[data-pct="${options.tipPct}"]`).catch(() => {});
    }
    await page.click('#confirmDeliveryBtn');
    await page.waitForTimeout(400);

    const urlPromise = page.waitForURL(/order-track\.php\?id=\d+/, { timeout: 60000 });
    await page.locator('#checkout-btn').evaluate(button => button.click());
    await urlPromise;

    const orderId = Number((page.url().match(/id=(\d+)/) || [])[1] || 0);
    addStep(`Create ${label}`, orderId > 0, { summary: `orderId=${orderId}, type=${options.deliveryType}` });
    await screenshot(page, `${label}-track`);
    return orderId;
  });
}

async function completeOrder(tenantBase, employeeState, orderId) {
  await withContext({ storageState: employeeState }, async (context) => {
    const page = await context.newPage();
    page.on('dialog', async dialog => { await dialog.accept(); });

    async function openTab(tab) {
      await page.goto(`${tenantBase}/employee.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await page.waitForTimeout(900);
      const tabBtn = page.locator(`.tab-btn[data-tab="${tab}"]`);
      if (await tabBtn.count()) {
        await tabBtn.click();
        await page.waitForTimeout(350);
      }
    }

    await openTab('Приём');
    addStep(`Employee sees order #${orderId}`, await page.locator(`article.employee-order-card[data-order-id="${orderId}"]`).count() > 0, { summary: 'visible in intake queue' });

    const cashBtn = page.locator(`button.confirm-cash-btn[data-order-id="${orderId}"]`).first();
    if (await cashBtn.count()) {
      const cashResponse = page.waitForResponse(response => response.url().includes('/api/checkout/cash-payment.php') && response.request().method() === 'POST', { timeout: 60000 }).catch(() => null);
      await cashBtn.click();
      await cashResponse;
      addStep(`Confirm cash for order #${orderId}`, true, { summary: 'cash confirmation submitted' });
      await page.waitForTimeout(600);
    }

    for (const [from, to] of [['Приём', 'готовим'], ['готовим', 'доставляем'], ['доставляем', 'завершён']]) {
      await openTab(from);
      const statusResp = page.waitForResponse(response => response.url().includes('/update_order_status.php') && response.request().method() === 'POST', { timeout: 60000 }).catch(() => null);
      await page.locator(`button.status-btn[data-order-id="${orderId}"]`).first().click();
      const response = await statusResp;
      const payload = response ? await response.json().catch(() => null) : null;
      addStep(`Order #${orderId} ${from} -> ${to}`, Boolean(response) && response.status() === 200 && payload?.success === true, {
        summary: `new_status=${payload?.new_status || 'unknown'}`,
      });
      await page.waitForTimeout(800);
    }

    await openTab('завершён');
    addStep(`Completed tab contains order #${orderId}`, await page.locator(`article.employee-order-card[data-order-id="${orderId}"]`).count() > 0, { summary: 'order moved to completed tab' });
  });
}

async function rejectOrder(tenantBase, employeeState, orderId) {
  await withContext({ storageState: employeeState }, async (context) => {
    const page = await context.newPage();
    page.on('dialog', async dialog => { await dialog.accept(); });
    await page.goto(`${tenantBase}/employee.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(900);
    addStep(`Employee sees reject-order #${orderId}`, await page.locator(`article.employee-order-card[data-order-id="${orderId}"]`).count() > 0, { summary: 'visible in active queue' });

    const responsePromise = page.waitForResponse(response => response.url().includes('/update_order_status.php') && response.request().method() === 'POST', { timeout: 60000 }).catch(() => null);
    await page.locator(`button.status-btn-r[data-order-id="${orderId}"]`).first().click();
    const response = await responsePromise;
    const payload = response ? await response.json().catch(() => null) : null;
    addStep(`Reject order #${orderId}`, Boolean(response) && response.status() === 200 && payload?.success === true, {
      summary: `new_status=${payload?.new_status || 'unknown'}`,
    });
    await page.waitForTimeout(900);

    const rejectTab = page.locator('.tab-btn[data-tab="отказ"]').first();
    if (await rejectTab.count()) {
      await rejectTab.click();
      await page.waitForTimeout(400);
    }
    addStep(`Rejected tab contains order #${orderId}`, await page.locator(`article.employee-order-card[data-order-id="${orderId}"]`).count() > 0, { summary: 'order moved to rejected tab' });
  });
}

async function customerVerifyOrders(tenantBase, customerState, completedId, rejectedId) {
  await withContext({ storageState: customerState }, async (context) => {
    const page = await context.newPage();
    page.on('dialog', async dialog => { await dialog.accept(); });

    await page.goto(`${tenantBase}/order-track.php?id=${completedId}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(1000);
    let text = await bodyText(page);
    addStep(`Track completed order #${completedId}`, text.includes('Заказ уже завершён') || text.includes('Заказ получен'), { summary: 'completed status visible' });

    await page.goto(`${tenantBase}/order-track.php?id=${rejectedId}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(1000);
    text = await bodyText(page);
    addStep(`Track rejected order #${rejectedId}`, text.includes('Заказ не будет выполнен') || text.includes('Заказ отменён'), { summary: 'rejected status visible' });

    await page.goto(`${tenantBase}/customer_orders.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(1000);
    const historyBtn = page.locator('.customer-orders-switch-btn[data-order-view="history"]').first();
    if (await historyBtn.count()) {
      await historyBtn.click();
      await page.waitForTimeout(400);
    }

    const completedCard = page.locator('.customer-order-card').filter({ hasText: `#${completedId}` }).first();
    const rejectedCard = page.locator('.customer-order-card').filter({ hasText: `#${rejectedId}` }).first();
    addStep('Customer history shows completed order', await completedCard.count() > 0, { summary: `orderId=${completedId}` });
    addStep('Customer history shows rejected order', await rejectedCard.count() > 0, { summary: `orderId=${rejectedId}` });

    if (await completedCard.count()) {
      const expand = completedCard.locator('.customer-order-expand').first();
      if (await expand.count()) {
        await expand.click();
        await page.waitForTimeout(300);
        addStep('Customer order expand button', true, { summary: `expanded orderId=${completedId}` });
      }

      const repeatBtn = completedCard.locator('button.customer-order-action').first();
      if (await repeatBtn.count()) {
        const repeatResp = page.waitForResponse(response => response.url().includes('/customer_orders.php') && response.request().method() === 'POST', { timeout: 60000 }).catch(() => null);
        await repeatBtn.click();
        await repeatResp;
        await page.waitForTimeout(1000);
        await page.goto(`${tenantBase}/cart.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
        const cartText = await bodyText(page);
        addStep('Customer repeat order button', cartText.includes('Куриный шашлык') || cartText.includes('Заказ'), { summary: 'repeat order populated cart' });
      } else {
        addStep('Customer repeat order button', false, { summary: 'repeat button missing' });
      }
    }
  });
}

async function fullOrderRegression(tenantBase, states) {
  const completedId = await createTenantOrder(tenantBase, states.customerState, 'tenant-order-complete', {
    productIndex: 0,
    deliveryType: 'takeaway',
    paymentMethod: 'cash',
    exerciseOptions: true,
    tipPct: '0',
  });
  const rejectedId = await createTenantOrder(tenantBase, states.customerState, 'tenant-order-reject', {
    productIndex: 1,
    deliveryType: 'delivery',
    address: 'Москва, Цветной б-р, 24',
    paymentMethod: 'cash',
    tipPct: '5',
  });

  report.orders.complete = { id: completedId };
  report.orders.reject = { id: rejectedId };

  await completeOrder(tenantBase, states.employeeState, completedId);
  await rejectOrder(tenantBase, states.employeeState, rejectedId);
  await customerVerifyOrders(tenantBase, states.customerState, completedId, rejectedId);
}

async function main() {
  const providerBase = baseUrl(CONFIG.providerDomain);
  const tenantBase = baseUrl(CONFIG.tenantDomain);

  try {
    await providerPublicAndAuthGates(providerBase);
    const tenantStates = await tenantRoleLoginStates(tenantBase);
    await tenantSurfaceChecks(tenantBase, tenantStates);
    const providerOwnerState = await providerOwnerChecks(providerBase);
    await captureVisualSignoff(providerBase, tenantBase, tenantStates, providerOwnerState);

    if (CONFIG.runOrderRegression) {
      addNote('Running mutating tenant order lifecycle regression.');
      await fullOrderRegression(tenantBase, tenantStates);
    } else {
      addNote('Order lifecycle regression skipped; set CLEANMENU_RUN_ORDER_REGRESSION=1 or use --orders to enable.');
    }

    report.ok = report.steps.every(step => step.ok) && report.visualChecks.every(check => check.ok);
  } catch (error) {
    report.ok = false;
    report.fatal = {
      message: error.message,
      stack: error.stack,
    };
    console.error(error);
    process.exitCode = 1;
  } finally {
    report.finishedAt = new Date().toISOString();
    fs.writeFileSync(path.join(OUT_DIR, 'report.json'), JSON.stringify(report, null, 2), 'utf8');

    const lines = [
      '# Post-Release Regression Report',
      '',
      `- ok: ${report.ok}`,
      `- started: ${report.startedAt}`,
      `- finished: ${report.finishedAt}`,
      `- provider: ${report.config.providerDomain}`,
      `- tenant: ${report.config.tenantDomain}`,
      `- order regression: ${report.config.runOrderRegression}`,
      '',
      '## Notes',
      ...report.notes.map(note => `- ${note}`),
      '',
      '## Steps',
      ...report.steps.map(step => `- [${step.ok ? 'x' : ' '}] ${step.name}${step.summary ? ` — ${step.summary}` : ''}`),
      '',
      '## Visual Sign-Off',
      ...report.visualChecks.map(check => `- [${check.ok ? 'x' : ' '}] ${check.name}${check.summary ? ` — ${check.summary}` : ''}${check.artifact ? ` (${path.basename(check.artifact)})` : ''}`),
      '',
      '## Visual Checklist',
      '- [ ] screenshot set reviewed for desktop and mobile',
      '- [ ] no sticky tab/filter rail overlaps or clipped first cards',
      '- [ ] no broken mobile density or accidental narrow-column form collapse',
      '- [ ] public hierarchy reads clearly within 2-3 seconds on key pages',
      '- [ ] internal pages still feel like one shared shell, not isolated fixes',
    ];

    if (report.orders.complete) {
      lines.push('', `- completed order: #${report.orders.complete.id}`);
    }
    if (report.orders.reject) {
      lines.push(`- rejected order: #${report.orders.reject.id}`);
    }
    if (report.fatal) {
      lines.push('', '## Fatal', '```text', report.fatal.stack || report.fatal.message, '```');
    }

    fs.writeFileSync(path.join(OUT_DIR, 'report.md'), lines.join('\n'), 'utf8');
  }
}

main();
