const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const { chromium } = require('playwright');

const ROOT_DIR = process.cwd();
const STAMP = new Date().toISOString().replace(/[:]/g, '').replace(/\..+/, '').replace('T', '-');
const OUT_DIR = path.resolve(process.env.CLEANMENU_FULL_AUDIT_OUT_DIR || path.join(ROOT_DIR, 'data', 'logs', 'full-ui-audit', `run-${STAMP}`));
const RELEASE_REGRESSION_DIR = path.join(ROOT_DIR, 'data', 'logs', 'release-regression');

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
};

const VIEWPORTS = {
  desktop: {
    width: 1440,
    height: 1000,
  },
  mobile: {
    width: 390,
    height: 844,
    isMobile: true,
    hasTouch: true,
    deviceScaleFactor: 3,
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
  },
};

const report = {
  startedAt: new Date().toISOString(),
  ok: false,
  config: {
    providerDomain: CONFIG.providerDomain,
    tenantDomain: CONFIG.tenantDomain,
    mode: 'safe + test orders',
  },
  baseline: {},
  pages: [],
  findings: [],
  fixPlan: [],
  notes: [],
  artifacts: [],
  stats: {},
};

const FINDING_KEYS = new Set();

function baseUrl(domain) {
  return domain.startsWith('http') ? domain : `https://${domain}`;
}

function slug(value) {
  return String(value || '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 80);
}

function rel(file) {
  return path.relative(ROOT_DIR, file).replace(/\\/g, '/');
}

function addNote(note) {
  report.notes.push(note);
  console.log(`[NOTE] ${note}`);
}

function addArtifact(file) {
  report.artifacts.push(rel(file));
}

function addFinding(finding) {
  const key = [finding.severity, finding.route, finding.role, finding.viewport, finding.component].join('|');
  if (FINDING_KEYS.has(key)) {
    return;
  }
  FINDING_KEYS.add(key);
  const entry = {
    severity: finding.severity,
    route: finding.route,
    role: finding.role,
    viewport: finding.viewport,
    component: finding.component,
    actual: finding.actual,
    expected: finding.expected,
    reproduction: finding.reproduction || '',
    artifact: finding.artifact ? rel(finding.artifact) : null,
    owner: finding.owner || '',
    recommendedFix: finding.recommendedFix || '',
  };
  report.findings.push(entry);
  console.log(`[FINDING ${entry.severity}] ${entry.route} :: ${entry.component}`);
}

function runCommand(command, args, extraEnv = {}, outputFile = null) {
  const result = spawnSync(command, args, {
    cwd: ROOT_DIR,
    env: { ...process.env, ...extraEnv },
    encoding: 'utf8',
    shell: false,
  });

  if (outputFile) {
    fs.writeFileSync(outputFile, `COMMAND: ${command} ${args.join(' ')}\n\nSTDOUT:\n${result.stdout || ''}\n\nSTDERR:\n${result.stderr || ''}\n`, 'utf8');
    addArtifact(outputFile);
  }

  return {
    ok: result.status === 0,
    code: result.status,
    stdout: result.stdout || '',
    stderr: result.stderr || '',
  };
}

function readJsonSafe(file) {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (error) {
    return null;
  }
}

async function httpStatus(url, options = {}) {
  let lastError = null;
  for (let attempt = 0; attempt < 3; attempt += 1) {
    try {
      const response = await fetch(url, {
        method: options.method || 'GET',
        headers: options.headers || {},
        redirect: options.redirect || 'manual',
        signal: AbortSignal.timeout(20000),
      });
      const text = options.readText ? await response.text() : '';
      return { status: response.status, headers: Object.fromEntries(response.headers.entries()), text };
    } catch (error) {
      lastError = error;
    }
  }

  const curlArgs = ['-sS', '-D', '-', '-o', 'NUL'];
  if ((options.method || 'GET').toUpperCase() === 'HEAD') {
    curlArgs.push('-I');
  }
  curlArgs.push(url);
  const curlResult = spawnSync('curl.exe', curlArgs, {
    cwd: ROOT_DIR,
    encoding: 'utf8',
    shell: false,
  });
  if (curlResult.status === 0) {
    const lines = (curlResult.stdout || '').split(/\r?\n/).filter(Boolean);
    const statusLine = lines.find(line => /^HTTP\//i.test(line)) || '';
    const status = Number((statusLine.match(/\s(\d{3})\s/) || [])[1] || 0);
    const headers = {};
    for (const line of lines) {
      const idx = line.indexOf(':');
      if (idx > 0) {
        headers[line.slice(0, idx).trim().toLowerCase()] = line.slice(idx + 1).trim();
      }
    }
    return { status, headers, text: '' };
  }

  throw lastError || new Error(`Failed to fetch ${url}`);
}

async function runSecurityBaseline(providerBase) {
  const lockedPaths = [
    '/phpinfo.php',
    '/db-indexes-optimizer.php',
    '/db-indexes-optimizer-v2.php',
    '/order_updates.php',
    '/scripts/api-metrics-report.php',
    '/scripts/api-smoke-runner.php',
  ];
  const authGatedPaths = [
    '/monitor.php',
    '/opcache-status.php',
    '/file-manager.php?action=get_fonts',
  ];

  const details = {
    menu: await httpStatus(`${providerBase}/menu.php`),
    apiMenu: await httpStatus(`${providerBase}/api/v1/menu.php`),
    locked: {},
    authGates: {},
    clearCache: await httpStatus(`${providerBase}/clear-cache.php?scope=server`),
    menuHeaders: await httpStatus(`${providerBase}/menu.php`, { method: 'HEAD' }),
    apiHeaders: await httpStatus(`${providerBase}/api/v1/menu.php`, { method: 'HEAD' }),
  };

  for (const p of lockedPaths) {
    details.locked[p] = await httpStatus(`${providerBase}${p}`);
  }
  for (const p of authGatedPaths) {
    details.authGates[p] = await httpStatus(`${providerBase}${p}`);
  }

  const ok = details.menu.status === 200
    && details.apiMenu.status === 200
    && Object.values(details.locked).every(entry => entry.status === 404)
    && Object.values(details.authGates).every(entry => entry.status === 302 || entry.status === 401 || entry.status === 403)
    && details.clearCache.status === 405;

  const artifact = path.join(OUT_DIR, 'baseline-security-smoke.json');
  fs.writeFileSync(artifact, JSON.stringify(details, null, 2), 'utf8');
  addArtifact(artifact);

  report.baseline.securitySmoke = {
    ok,
    artifact: rel(artifact),
    summary: {
      menu: details.menu.status,
      apiMenu: details.apiMenu.status,
      clearCache: details.clearCache.status,
    },
  };

  if (!ok) {
    addFinding({
      severity: 'P1',
      route: '/monitor.php /opcache-status.php /clear-cache.php',
      role: 'provider-public',
      viewport: 'http',
      component: 'security smoke contract',
      actual: JSON.stringify(report.baseline.securitySmoke.summary),
      expected: 'menu/api 200, locked paths 404, auth-gated endpoints 302/401/403, clear-cache 405',
      artifact,
      owner: 'ops/security',
      recommendedFix: 'Reconcile endpoint guards with the documented security smoke contract before the next release.',
    });
  }
}

async function verifyStoredAuthState(browser, base, statePath) {
  const verifyContext = await browser.newContext({
    storageState: statePath,
    viewport: VIEWPORTS.desktop,
  });
  try {
    const verifyPage = await verifyContext.newPage();
    await verifyPage.goto(`${base}/account.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await verifyPage.waitForTimeout(800);
    return !verifyPage.url().includes('/auth.php');
  } finally {
    await verifyContext.close().catch(() => {});
  }
}

async function loginToDomain(base, email, password, statePath) {
  const browser = await chromium.launch({ headless: true });
  try {
    for (let attempt = 1; attempt <= 2; attempt += 1) {
      const context = await browser.newContext({ viewport: VIEWPORTS.desktop });
      try {
        const page = await context.newPage();
        await page.goto(`${base}/auth.php?mode=login`, { waitUntil: 'domcontentloaded', timeout: 60000 });
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', password);
        await Promise.all([
          page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null),
          page.click('button[type="submit"]'),
        ]);
        await page.waitForTimeout(1200);
        if (page.url().includes('/auth.php')) {
          throw new Error(`Login failed for ${email}`);
        }

        await page.goto(`${base}/account.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
        await waitReady(page);
        if (page.url().includes('/auth.php')) {
          throw new Error(`Account bootstrap failed for ${email}`);
        }

        await context.storageState({ path: statePath });
        const isValidState = await verifyStoredAuthState(browser, base, statePath);
        if (isValidState) {
          return page.url();
        }

        if (fs.existsSync(statePath)) {
          fs.unlinkSync(statePath);
        }

        if (attempt === 2) {
          throw new Error(`Stored auth state is invalid for ${email}`);
        }
      } finally {
        await context.close().catch(() => {});
      }
    }

    throw new Error(`Unable to create auth state for ${email}`);
  } finally {
    await browser.close().catch(() => {});
  }
}

function attachCollectors(page) {
  const collector = {
    consoleErrors: [],
    pageErrors: [],
    requestFailures: [],
    responseErrors: [],
  };

  page.on('console', message => {
    if (message.type() === 'error') {
      const text = message.text();
      if (!/favicon/i.test(text)) {
        collector.consoleErrors.push(text);
      }
    }
  });

  page.on('pageerror', error => {
    collector.pageErrors.push(String(error.message || error));
  });

  page.on('requestfailed', request => {
    collector.requestFailures.push({
      url: request.url(),
      method: request.method(),
      failure: request.failure() ? request.failure().errorText : 'unknown',
      resourceType: request.resourceType(),
    });
  });

  page.on('response', response => {
    const status = response.status();
    if (status >= 500) {
      collector.responseErrors.push({
        url: response.url(),
        status,
        resourceType: response.request().resourceType(),
      });
    }
  });

  return collector;
}

async function collectInventory(page) {
  return page.evaluate(() => {
    const dangerPattern = /(save|сохран|удал|delete|replace|upload|загруз|замен|reset|сброс|clear|очист|logout|выйти|оплат|payment|заказать|подтверд|confirm)/i;
    const nodes = Array.from(document.querySelectorAll('a[href], button, input:not([type="hidden"]), select, textarea, [role="button"]'));

    const items = nodes.map((node, index) => {
      const style = window.getComputedStyle(node);
      const rect = node.getBoundingClientRect();
      const visible = rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      if (!visible) {
        return null;
      }
      const text = (node.textContent || node.value || node.getAttribute('aria-label') || node.getAttribute('placeholder') || '').replace(/\s+/g, ' ').trim();
      const href = node.getAttribute('href') || '';
      const classes = node.className || '';
      const kind = node.tagName.toLowerCase();
      const safe = !dangerPattern.test(`${text} ${href} ${classes}`);
      return {
        index,
        tag: kind,
        type: node.getAttribute('type') || '',
        text,
        href,
        classes,
        safe,
      };
    }).filter(Boolean);

    return {
      count: items.length,
      safeCount: items.filter(item => item.safe).length,
      items,
    };
  });
}

async function visualMeta(page) {
  return page.evaluate(() => {
    const rail = document.querySelector('.menu-tabs-container');
    const railRect = rail ? rail.getBoundingClientRect() : null;
    const railStyle = rail ? window.getComputedStyle(rail) : null;
    const header = document.querySelector('header');
    const headerRect = header ? header.getBoundingClientRect() : null;
    const firstContent = document.querySelector('.account-section, .employee-order-card, .customer-order-card, .admin-section-card, .empty-cart-card, .qr-item, .landing-entry-card, .cart-item, .menu-item, .monitor-page.container, .opcache-page.container');
    const firstContentRect = firstContent ? firstContent.getBoundingClientRect() : null;
    const topHeading = document.querySelector('.section-header-menu h1, .section-header-menu h2, .hero-content h1, .monitor-header h1, .opcache-header h1');
    const topHeadingRect = topHeading ? topHeading.getBoundingClientRect() : null;

    return {
      title: document.title,
      bodyClass: document.body.className,
      hasRail: Boolean(rail),
      railPosition: railStyle ? railStyle.position : null,
      railBottom: railRect ? Math.round(railRect.bottom) : null,
      headerBottom: headerRect ? Math.round(headerRect.bottom) : null,
      firstContentTop: firstContentRect ? Math.round(firstContentRect.top) : null,
      topHeadingTop: topHeadingRect ? Math.round(topHeadingRect.top) : null,
    };
  });
}

async function updateModalMeta(page) {
  return page.evaluate(() => {
    const modal = document.querySelector('.version-update-modal');
    if (!modal) {
      return {
        visible: false,
        blocking: false,
      };
    }

    const style = window.getComputedStyle(modal);
    const rect = modal.getBoundingClientRect();
    const visible = style.display !== 'none'
      && style.visibility !== 'hidden'
      && rect.width > 0
      && rect.height > 0;

    if (!visible) {
      return {
        visible: false,
        blocking: false,
      };
    }

    const coversViewport = rect.top <= 2
      && rect.left <= 2
      && rect.width >= window.innerWidth - 4
      && rect.height >= window.innerHeight - 4;

    return {
      visible: true,
      blocking: style.pointerEvents !== 'none' && coversViewport,
      pointerEvents: style.pointerEvents,
      backgroundColor: style.backgroundColor,
      rect: {
        top: Math.round(rect.top),
        left: Math.round(rect.left),
        width: Math.round(rect.width),
        height: Math.round(rect.height),
      },
    };
  });
}

async function waitReady(page) {
  await page.waitForLoadState('domcontentloaded').catch(() => {});
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(350);
}

async function capturePageScreenshot(page, name) {
  const file = path.join(OUT_DIR, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  addArtifact(file);
  return file;
}

async function clickAll(page, selector, label, limit = 8) {
  const results = [];
  const count = await page.locator(selector).count();
  const max = Math.min(count, limit);
  for (let index = 0; index < max; index += 1) {
    const locator = page.locator(selector).nth(index);
    if (!(await locator.isVisible().catch(() => false))) {
      continue;
    }
    const text = (await locator.innerText().catch(() => '')).replace(/\s+/g, ' ').trim();
    await locator.click({ timeout: 10000 }).catch(error => {
      results.push({ ok: false, label, text, error: error.message });
    });
    await page.waitForTimeout(350);
    if (!results.find(item => item.label === label && item.text === text)) {
      results.push({ ok: true, label, text });
    }
  }
  return results;
}

async function exercisePage(page, spec) {
  const results = [];

  if (spec.kind === 'menu') {
    const search = page.locator('input[type="search"], .menu-discovery-search-input, input[name="search"]').first();
    if (await search.count()) {
      await search.fill(spec.searchTerm || 'menu').catch(() => {});
      await page.waitForTimeout(300);
      results.push({ ok: true, label: 'search', text: spec.searchTerm || 'menu' });
    }
    results.push(...await clickAll(page, '.menu-tabs .tab-btn', 'tab-btn', 6));
    return results;
  }

  if (spec.kind === 'account') {
    results.push(...await clickAll(page, '.menu-tabs .tab-btn', 'tab-btn', 8));
    return results;
  }

  if (spec.kind === 'help') {
    results.push(...await clickAll(page, '.menu-tabs .tab-btn', 'tab-btn', 8));
    return results;
  }

  if (spec.kind === 'owner') {
    results.push(...await clickAll(page, '.menu-tabs .tab-btn', 'tab-btn', 8));
    return results;
  }

  if (spec.kind === 'admin-menu') {
    results.push(...await clickAll(page, '.admin-tab-btn', 'admin-tab', 6));
    results.push(...await clickAll(page, '.menu-tabs .tab-btn', 'menu-tab', 8));
    return results;
  }

  if (spec.kind === 'employee') {
    results.push(...await clickAll(page, '.tab-btn[data-tab]', 'employee-tab', 8));
    return results;
  }

  if (spec.kind === 'customer-orders') {
    results.push(...await clickAll(page, '.customer-orders-switch-btn', 'orders-view', 4));
    results.push(...await clickAll(page, '.customer-order-expand', 'order-expand', 3));
    return results;
  }

  if (spec.kind === 'qr-print') {
    const safeButtons = page.locator('button').filter({ hasText: /Применить|Назад|Распечатать/i });
    const count = await safeButtons.count();
    for (let index = 0; index < Math.min(count, 3); index += 1) {
      const locator = safeButtons.nth(index);
      const text = (await locator.innerText().catch(() => '')).trim();
      await locator.click({ timeout: 10000 }).catch(error => {
        results.push({ ok: false, label: 'qr-button', text, error: error.message });
      });
      await page.waitForTimeout(350);
      if (!results.find(item => item.label === 'qr-button' && item.text === text)) {
        results.push({ ok: true, label: 'qr-button', text });
      }
    }
    return results;
  }

  if (spec.kind === 'monitor') {
    const buttons = page.locator('button').filter({ hasText: /Обновить|API Endpoint/i });
    const count = await buttons.count();
    for (let index = 0; index < count; index += 1) {
      const locator = buttons.nth(index);
      const text = (await locator.innerText().catch(() => '')).trim();
      await locator.click({ timeout: 10000 }).catch(error => {
        results.push({ ok: false, label: 'monitor-button', text, error: error.message });
      });
      await page.waitForTimeout(600);
      if (!results.find(item => item.label === 'monitor-button' && item.text === text)) {
        results.push({ ok: true, label: 'monitor-button', text });
      }
    }
    return results;
  }

  if (spec.kind === 'opcache') {
    const buttons = page.locator('button').filter({ hasText: /Обновить|Показать/i });
    const count = await buttons.count();
    for (let index = 0; index < count; index += 1) {
      const locator = buttons.nth(index);
      const text = (await locator.innerText().catch(() => '')).trim();
      await locator.click({ timeout: 10000 }).catch(error => {
        results.push({ ok: false, label: 'opcache-button', text, error: error.message });
      });
      await page.waitForTimeout(600);
      if (!results.find(item => item.label === 'opcache-button' && item.text === text)) {
        results.push({ ok: true, label: 'opcache-button', text });
      }
    }
    return results;
  }

  if (spec.kind === 'auth') {
    results.push(...await clickAll(page, 'a[href*="mode="], button[type="button"]', 'auth-toggle', 4));
    return results;
  }

  if (spec.kind === 'homepage') {
    results.push(...await clickAll(page, 'a[href="#contact"], a[href="#menu"], .hero-actions a', 'homepage-link', 4));
    return results;
  }

  return results;
}

function filterCollectorNoise(spec, collector) {
  const authNoiseHosts = ['accounts.youtube.com', 'play.google.com', 'accounts.google.com'];
  const requestFailures = collector.requestFailures.filter(item => {
    try {
      const host = new URL(item.url).host;
      if (spec.kind === 'auth' && authNoiseHosts.includes(host)) {
        return false;
      }
    } catch (error) {
      return true;
    }
    return true;
  });

  const consoleErrors = collector.consoleErrors.filter(item => {
    if (spec.kind === 'auth' && /Failed to load resource: the server responded with a status of 401/i.test(item)) {
      return false;
    }
    return true;
  });

  return {
    ...collector,
    requestFailures,
    consoleErrors,
  };
}

function roleLabel(role) {
  return role.replace(/-/g, ' ');
}

async function auditPage(spec) {
  const viewports = spec.viewports || ['desktop', 'mobile'];

  for (const viewportName of viewports) {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
      storageState: spec.storageState || undefined,
      viewport: VIEWPORTS[viewportName],
      userAgent: VIEWPORTS[viewportName].userAgent,
      isMobile: VIEWPORTS[viewportName].isMobile || false,
      hasTouch: VIEWPORTS[viewportName].hasTouch || false,
      deviceScaleFactor: VIEWPORTS[viewportName].deviceScaleFactor || 1,
    });

    const page = await context.newPage();
    const collector = attachCollectors(page);
    const fullUrl = `${spec.base}${spec.route}`;
    let screenshotFile = null;

    try {
      const response = await page.goto(fullUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await waitReady(page);

      const inventory = await collectInventory(page);
      const meta = await visualMeta(page);
      const modalMeta = await updateModalMeta(page);
      let interactions = [];
      const normalizedRoute = spec.route.startsWith('/account.php') ? '/account.php*' : spec.route;
      const blockingModalFinding = modalMeta.blocking
        ? {
            severity: 'P1',
            route: normalizedRoute,
            role: spec.role,
            viewport: viewportName,
            component: 'release update modal blocks page controls',
            actual: `A visible version-update modal covers the viewport with pointer-events=${modalMeta.pointerEvents}.`,
            expected: 'Account shell controls should stay accessible without a blocking overlay.',
            owner: 'shared-shell/account',
            recommendedFix: 'Demote the modal to a non-blocking notice or ensure it can be dismissed without covering header controls.',
          }
        : null;
      if (!modalMeta.blocking) {
        interactions = await exercisePage(page, spec);
      }
      await waitReady(page);

      const filteredCollector = filterCollectorNoise(spec, collector);

      screenshotFile = await capturePageScreenshot(page, `${slug(spec.label)}-${viewportName}`);

      const entry = {
        label: spec.label,
        route: spec.route,
        finalUrl: page.url(),
        role: spec.role,
        viewport: viewportName,
        status: response ? response.status() : 0,
        screenshot: rel(screenshotFile),
        inventory,
        interactions,
        meta,
        modalMeta,
        consoleErrors: filteredCollector.consoleErrors,
        pageErrors: filteredCollector.pageErrors,
        requestFailures: filteredCollector.requestFailures,
        responseErrors: filteredCollector.responseErrors,
        blockingModal: modalMeta.blocking,
      };
      report.pages.push(entry);

      if (blockingModalFinding) {
        addFinding({
          ...blockingModalFinding,
          artifact: screenshotFile,
        });
      }

      if (spec.requireAuth && entry.finalUrl.includes('/auth.php')) {
        addFinding({
          severity: 'P0',
          route: spec.route,
          role: spec.role,
          viewport: viewportName,
          component: 'auth gate / page access',
          actual: `Redirected to ${entry.finalUrl}`,
          expected: 'Authenticated route should open its target page',
          artifact: screenshotFile,
          owner: roleLabel(spec.role),
          recommendedFix: 'Restore authenticated access for this route and verify session/cookie handling.',
        });
      }

      if (entry.status >= 500) {
        addFinding({
          severity: 'P0',
          route: spec.route,
          role: spec.role,
          viewport: viewportName,
          component: 'document response',
          actual: `HTTP ${entry.status}`,
          expected: 'No 5xx document response',
          artifact: screenshotFile,
          owner: roleLabel(spec.role),
          recommendedFix: 'Stabilize page rendering before the next release sign-off.',
        });
      }

      if (filteredCollector.pageErrors.length) {
        addFinding({
          severity: 'P1',
          route: spec.route,
          role: spec.role,
          viewport: viewportName,
          component: 'pageerror',
          actual: filteredCollector.pageErrors.join(' | '),
          expected: 'No uncaught page errors',
          artifact: screenshotFile,
          owner: roleLabel(spec.role),
          recommendedFix: 'Remove uncaught runtime exceptions on page load or interaction.',
        });
      }

      if (filteredCollector.responseErrors.length) {
        addFinding({
          severity: 'P1',
          route: spec.route,
          role: spec.role,
          viewport: viewportName,
          component: 'network 5xx',
          actual: filteredCollector.responseErrors.map(item => `${item.status} ${item.url}`).join(' | '),
          expected: 'No 5xx network responses',
          artifact: screenshotFile,
          owner: roleLabel(spec.role),
          recommendedFix: 'Stabilize failing backend endpoints used by this page.',
        });
      }

      if (filteredCollector.requestFailures.length) {
        addFinding({
          severity: 'P2',
          route: spec.route,
          role: spec.role,
          viewport: viewportName,
          component: 'requestfailed',
          actual: filteredCollector.requestFailures.map(item => `${item.resourceType} ${item.failure} ${item.url}`).join(' | '),
          expected: 'No failed browser requests',
          artifact: screenshotFile,
          owner: roleLabel(spec.role),
          recommendedFix: 'Remove broken asset or XHR requests from the page.',
        });
      }

      if (filteredCollector.consoleErrors.length) {
        addFinding({
          severity: 'P2',
          route: spec.route,
          role: spec.role,
          viewport: viewportName,
          component: 'console.error',
          actual: filteredCollector.consoleErrors.join(' | '),
          expected: 'No console errors',
          artifact: screenshotFile,
          owner: roleLabel(spec.role),
          recommendedFix: 'Clean console errors or suppress only known-safe diagnostics.',
        });
      }

      if (meta.hasRail && meta.railPosition === 'fixed' && meta.firstContentTop !== null && meta.railBottom !== null && meta.firstContentTop < meta.railBottom - 2) {
        addFinding({
          severity: 'P2',
          route: spec.route,
          role: spec.role,
          viewport: viewportName,
          component: 'tab rail overlap',
          actual: `firstContentTop=${meta.firstContentTop}, railBottom=${meta.railBottom}, railPosition=${meta.railPosition}`,
          expected: 'No overlap between sticky/fixed rail and first content row',
          artifact: screenshotFile,
          owner: 'shared-shell',
          recommendedFix: 'Reconcile rail positioning with page top spacing through the shared visual contract.',
        });
      }

      for (const interaction of interactions.filter(item => item.ok === false)) {
        addFinding({
          severity: 'P1',
          route: spec.route,
          role: spec.role,
          viewport: viewportName,
          component: `safe interaction: ${interaction.label}`,
          actual: interaction.error || 'interaction failed',
          expected: 'Safe interactive controls should react without error',
          artifact: screenshotFile,
          owner: roleLabel(spec.role),
          recommendedFix: 'Repair the affected safe control and verify it against the route-level audit.',
        });
      }
    } finally {
      await context.close().catch(() => {});
      await browser.close().catch(() => {});
    }
  }
}

function buildFixPlan() {
  const bySeverity = {
    P0: [],
    P1: [],
    P2: [],
    P3: [],
  };

  for (const finding of report.findings) {
    bySeverity[finding.severity].push(finding);
  }

  const plan = [];

  if (bySeverity.P0.length) {
    plan.push({
      priority: '1',
      title: 'Fix blocking route or auth regressions',
      summary: 'Any P0 failures must be closed before the next release sign-off.',
      routes: [...new Set(bySeverity.P0.map(item => item.route))],
    });
  }

  if (bySeverity.P1.length) {
    plan.push({
      priority: '2',
      title: 'Stabilize interactive/runtime failures',
      summary: 'Resolve page errors, 5xx responses, and broken safe controls on audited routes.',
      routes: [...new Set(bySeverity.P1.map(item => item.route))],
    });
  }

  if (bySeverity.P2.length) {
    plan.push({
      priority: '3',
      title: 'Close shared shell and visual polish drift',
      summary: 'Remove remaining overlap, spacing, and console/network noise on otherwise working pages.',
      routes: [...new Set(bySeverity.P2.map(item => item.route))],
    });
  }

  if (!plan.length) {
    plan.push({
      priority: '1',
      title: 'Keep regression coverage green',
      summary: 'No blocking findings were detected in this audit run; keep the current release gate and screenshot sign-off as the baseline.',
      routes: [],
    });
  }

  report.fixPlan = plan;
}

function writeReportFiles() {
  report.ok = report.findings.every(item => item.severity !== 'P0' && item.severity !== 'P1');
  report.stats = {
    totalPages: report.pages.length,
    totalFindings: report.findings.length,
    findingsBySeverity: report.findings.reduce((acc, finding) => {
      acc[finding.severity] = (acc[finding.severity] || 0) + 1;
      return acc;
    }, {}),
    artifacts: report.artifacts.length,
  };

  const jsonFile = path.join(OUT_DIR, 'report.json');
  fs.writeFileSync(jsonFile, JSON.stringify(report, null, 2), 'utf8');
  addArtifact(jsonFile);

  const lines = [];
  lines.push('# Full UI Audit');
  lines.push('');
  lines.push(`- started: ${report.startedAt}`);
  lines.push(`- ok: ${report.ok}`);
  lines.push(`- provider: ${report.config.providerDomain}`);
  lines.push(`- tenant: ${report.config.tenantDomain}`);
  lines.push(`- mode: ${report.config.mode}`);
  lines.push(`- pages audited: ${report.stats.totalPages}`);
  lines.push(`- findings: ${report.stats.totalFindings}`);
  lines.push('');
  lines.push('## Baseline');
  lines.push('');
  for (const [name, value] of Object.entries(report.baseline)) {
    lines.push(`- ${name}: ${value.ok ? 'OK' : 'FAIL'}${value.artifact ? ` (${value.artifact})` : ''}`);
  }
  lines.push('');
  lines.push('## Findings Matrix');
  lines.push('');
  if (!report.findings.length) {
    lines.push('- No blocking findings were detected.');
  } else {
    lines.push('| Severity | Route | Role | Viewport | Component | Actual | Expected | Artifact |');
    lines.push('| --- | --- | --- | --- | --- | --- | --- | --- |');
    for (const finding of report.findings) {
      lines.push(`| ${finding.severity} | ${finding.route} | ${finding.role} | ${finding.viewport} | ${finding.component} | ${finding.actual.replace(/\|/g, '\\|')} | ${finding.expected.replace(/\|/g, '\\|')} | ${finding.artifact || ''} |`);
    }
  }
  lines.push('');
  lines.push('## Fix Plan');
  lines.push('');
  for (const item of report.fixPlan) {
    lines.push(`- ${item.priority}. ${item.title}: ${item.summary}${item.routes.length ? ` Routes: ${item.routes.join(', ')}` : ''}`);
  }
  lines.push('');
  lines.push('## Notes');
  lines.push('');
  for (const note of report.notes) {
    lines.push(`- ${note}`);
  }

  const mdFile = path.join(OUT_DIR, 'report.md');
  fs.writeFileSync(mdFile, `${lines.join('\n')}\n`, 'utf8');
  addArtifact(mdFile);
}

async function main() {
  const providerBase = baseUrl(CONFIG.providerDomain);
  const tenantBase = baseUrl(CONFIG.tenantDomain);

  addNote('Running baseline tenant smoke.');
  const tenantSmokeArtifact = path.join(OUT_DIR, 'baseline-tenant-smoke.log');
  report.baseline.tenantSmoke = runCommand('php', ['scripts/tenant/smoke.php', `--provider-domain=${CONFIG.providerDomain}`, `--tenant-domain=${CONFIG.tenantDomain}`], {}, tenantSmokeArtifact);
  report.baseline.tenantSmoke.artifact = rel(tenantSmokeArtifact);

  addNote('Running baseline provider security checks.');
  await runSecurityBaseline(providerBase);

  addNote('Running baseline safe release regression.');
  const safeBaselineDir = path.join(RELEASE_REGRESSION_DIR, `run-${STAMP}-full-audit-safe`);
  fs.mkdirSync(safeBaselineDir, { recursive: true });
  const safeBaseline = runCommand(process.execPath, ['scripts/perf/post-release-regression.cjs'], {
    CLEANMENU_PROVIDER_OWNER_EMAIL: CONFIG.providerOwnerEmail,
    CLEANMENU_PROVIDER_OWNER_PASSWORD: CONFIG.providerOwnerPassword,
    CLEANMENU_REQUIRE_PROVIDER_OWNER_AUTH: '1',
    CLEANMENU_REGRESSION_OUT_DIR: safeBaselineDir,
  }, path.join(OUT_DIR, 'baseline-release-safe.log'));
  report.baseline.releaseSafe = {
    ...safeBaseline,
    artifact: rel(path.join(OUT_DIR, 'baseline-release-safe.log')),
    report: rel(path.join(safeBaselineDir, 'report.json')),
  };

  addNote('Running baseline mutating order lifecycle regression.');
  const orderBaselineDir = path.join(RELEASE_REGRESSION_DIR, `run-${STAMP}-full-audit-orders`);
  fs.mkdirSync(orderBaselineDir, { recursive: true });
  const orderBaseline = runCommand(process.execPath, ['scripts/perf/post-release-regression.cjs'], {
    CLEANMENU_PROVIDER_OWNER_EMAIL: CONFIG.providerOwnerEmail,
    CLEANMENU_PROVIDER_OWNER_PASSWORD: CONFIG.providerOwnerPassword,
    CLEANMENU_REQUIRE_PROVIDER_OWNER_AUTH: '1',
    CLEANMENU_RUN_ORDER_REGRESSION: '1',
    CLEANMENU_REGRESSION_OUT_DIR: orderBaselineDir,
  }, path.join(OUT_DIR, 'baseline-release-orders.log'));
  const orderJson = readJsonSafe(path.join(orderBaselineDir, 'report.json'));
  report.baseline.releaseOrders = {
    ...orderBaseline,
    artifact: rel(path.join(OUT_DIR, 'baseline-release-orders.log')),
    report: rel(path.join(orderBaselineDir, 'report.json')),
    orders: orderJson ? orderJson.orders : {},
  };

  const completedOrderId = orderJson && orderJson.orders && orderJson.orders.complete ? orderJson.orders.complete.id : null;
  const rejectedOrderId = orderJson && orderJson.orders && orderJson.orders.reject ? orderJson.orders.reject.id : null;

  addNote('Creating authenticated storage states for exhaustive audit.');
  const states = {
    providerOwner: path.join(OUT_DIR, 'provider-owner-state.json'),
    tenantAdmin: path.join(OUT_DIR, 'tenant-admin-state.json'),
    tenantEmployee: path.join(OUT_DIR, 'tenant-employee-state.json'),
    tenantCustomer: path.join(OUT_DIR, 'tenant-customer-state.json'),
  };

  await loginToDomain(providerBase, CONFIG.providerOwnerEmail, CONFIG.providerOwnerPassword, states.providerOwner);
  await loginToDomain(tenantBase, CONFIG.tenantAdminEmail, CONFIG.tenantAdminPassword, states.tenantAdmin);
  await loginToDomain(tenantBase, CONFIG.tenantEmployeeEmail, CONFIG.tenantEmployeePassword, states.tenantEmployee);
  await loginToDomain(tenantBase, CONFIG.tenantCustomerEmail, CONFIG.tenantCustomerPassword, states.tenantCustomer);

  const pageSpecs = [
    { label: 'Provider root', base: providerBase, route: '/', role: 'provider-public', kind: 'homepage' },
    { label: 'Provider menu', base: providerBase, route: '/menu.php', role: 'provider-public', kind: 'menu' },
    { label: 'Provider cart', base: providerBase, route: '/cart.php', role: 'provider-public', kind: 'cart' },
    { label: 'Provider auth', base: providerBase, route: '/auth.php', role: 'provider-public', kind: 'auth' },
    { label: 'Provider owner account', base: providerBase, route: '/account.php', role: 'provider-owner', kind: 'account', storageState: states.providerOwner, requireAuth: true },
    { label: 'Provider owner account security', base: providerBase, route: '/account.php?tab=security', role: 'provider-owner', kind: 'account', storageState: states.providerOwner, requireAuth: true },
    { label: 'Provider owner account menu', base: providerBase, route: '/account.php?tab=menu', role: 'provider-owner', kind: 'account', storageState: states.providerOwner, requireAuth: true },
    { label: 'Provider owner account updates', base: providerBase, route: '/account.php?tab=updates', role: 'provider-owner', kind: 'account', storageState: states.providerOwner, requireAuth: true },
    { label: 'Provider owner help', base: providerBase, route: '/help.php', role: 'provider-owner', kind: 'help', storageState: states.providerOwner, requireAuth: true },
    { label: 'Provider owner page', base: providerBase, route: '/owner.php', role: 'provider-owner', kind: 'owner', storageState: states.providerOwner, requireAuth: true },
    { label: 'Provider admin menu', base: providerBase, route: '/admin-menu.php', role: 'provider-owner', kind: 'admin-menu', storageState: states.providerOwner, requireAuth: true },
    { label: 'Provider employee', base: providerBase, route: '/employee.php', role: 'provider-owner', kind: 'employee', storageState: states.providerOwner, requireAuth: true },
    { label: 'Provider customer orders', base: providerBase, route: '/customer_orders.php', role: 'provider-owner', kind: 'customer-orders', storageState: states.providerOwner, requireAuth: true },
    { label: 'Provider qr-print', base: providerBase, route: '/qr-print.php', role: 'provider-owner', kind: 'qr-print', storageState: states.providerOwner, requireAuth: true },
    { label: 'Provider monitor', base: providerBase, route: '/monitor.php', role: 'provider-owner', kind: 'monitor', storageState: states.providerOwner, requireAuth: true },
    { label: 'Provider opcache', base: providerBase, route: '/opcache-status.php', role: 'provider-owner', kind: 'opcache', storageState: states.providerOwner, requireAuth: true },
    { label: 'Tenant homepage', base: tenantBase, route: '/', role: 'tenant-public', kind: 'homepage' },
    { label: 'Tenant menu', base: tenantBase, route: '/menu.php', role: 'tenant-public', kind: 'menu' },
    { label: 'Tenant cart', base: tenantBase, route: '/cart.php', role: 'tenant-public', kind: 'cart' },
    { label: 'Tenant auth', base: tenantBase, route: '/auth.php', role: 'tenant-public', kind: 'auth' },
    ...(completedOrderId ? [{ label: 'Tenant order track', base: tenantBase, route: `/order-track.php?id=${completedOrderId}`, role: 'tenant-public', kind: 'order-track' }] : []),
    { label: 'Tenant admin account', base: tenantBase, route: '/account.php', role: 'tenant-admin', kind: 'account', storageState: states.tenantAdmin, requireAuth: true },
    { label: 'Tenant admin help', base: tenantBase, route: '/help.php', role: 'tenant-admin', kind: 'help', storageState: states.tenantAdmin, requireAuth: true },
    { label: 'Tenant admin menu', base: tenantBase, route: '/admin-menu.php', role: 'tenant-admin', kind: 'admin-menu', storageState: states.tenantAdmin, requireAuth: true },
    { label: 'Tenant admin qr-print', base: tenantBase, route: '/qr-print.php', role: 'tenant-admin', kind: 'qr-print', storageState: states.tenantAdmin, requireAuth: true },
    { label: 'Tenant employee', base: tenantBase, route: '/employee.php', role: 'tenant-employee', kind: 'employee', storageState: states.tenantEmployee, requireAuth: true },
    { label: 'Tenant customer account', base: tenantBase, route: '/account.php', role: 'tenant-customer', kind: 'account', storageState: states.tenantCustomer, requireAuth: true },
    { label: 'Tenant customer account security', base: tenantBase, route: '/account.php?tab=security', role: 'tenant-customer', kind: 'account', storageState: states.tenantCustomer, requireAuth: true },
    { label: 'Tenant customer account menu', base: tenantBase, route: '/account.php?tab=menu', role: 'tenant-customer', kind: 'account', storageState: states.tenantCustomer, requireAuth: true },
    { label: 'Tenant customer account updates', base: tenantBase, route: '/account.php?tab=updates', role: 'tenant-customer', kind: 'account', storageState: states.tenantCustomer, requireAuth: true },
    { label: 'Tenant customer orders', base: tenantBase, route: '/customer_orders.php', role: 'tenant-customer', kind: 'customer-orders', storageState: states.tenantCustomer, requireAuth: true },
  ];

  addNote(`Auditing ${pageSpecs.length} routes across desktop and mobile.`);
  for (const spec of pageSpecs) {
    await auditPage(spec);
  }

  if (completedOrderId === null || rejectedOrderId === null) {
    addFinding({
      severity: 'P1',
      route: '/order-track.php /employee.php /customer_orders.php',
      role: 'tenant-lifecycle',
      viewport: 'workflow',
      component: 'baseline order ids',
      actual: `completed=${completedOrderId}, rejected=${rejectedOrderId}`,
      expected: 'Baseline mutating order regression should produce completed and rejected order ids',
      artifact: path.join(orderBaselineDir, 'report.json'),
      owner: 'order-lifecycle',
      recommendedFix: 'Stabilize the mutating baseline before relying on lifecycle audit evidence.',
    });
  }

  buildFixPlan();
  writeReportFiles();
}

main()
  .then(() => {
    console.log(`[full-ui-audit] report: ${path.join(OUT_DIR, 'report.md')}`);
  })
  .catch(error => {
    report.ok = false;
    addNote(`Fatal error: ${error.stack || error.message}`);
    buildFixPlan();
    writeReportFiles();
    console.error(error);
    process.exitCode = 1;
  });
