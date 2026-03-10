(() => {
  const OK_ICON =
    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M173.66,98.34a8,8,0,0,1,0,11.32l-56,56a8,8,0,0,1-11.32,0l-24-24a8,8,0,0,1,11.32-11.32L112,148.69l50.34-50.35A8,8,0,0,1,173.66,98.34ZM232,128A104,104,0,1,1,128,24,104.11,104.11,0,0,1,232,128Zm-16,0a88,88,0,1,0-88,88A88.1,88.1,0,0,0,216,128Z"/></svg>';
  const ERR_ICON =
    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M205.66,194.34a8,8,0,0,1-11.32,11.32L128,139.31l-66.34,66.35a8,8,0,0,1-11.32-11.32L116.69,128,50.34,61.66A8,8,0,0,1,61.66,50.34L128,116.69l66.34-66.35a8,8,0,0,1,11.32,11.32L139.31,128Z"/><path d="M232,128A104,104,0,1,1,128,24,104.11,104.11,0,0,1,232,128Zm-16,0a88,88,0,1,0-88,88A88.1,88.1,0,0,0,216,128Z"/></svg>';
  const SCROLL_KEY = 'admin-menu:scroll-y';

  function saveScrollPosition() {
    try {
      sessionStorage.setItem(SCROLL_KEY, String(window.scrollY || window.pageYOffset || 0));
    } catch (error) {
      // Ignore storage failures and keep navigation working.
    }
  }

  function restoreScrollPosition() {
    try {
      const raw = sessionStorage.getItem(SCROLL_KEY);
      if (raw === null) return;

      sessionStorage.removeItem(SCROLL_KEY);
      const top = Number.parseInt(raw, 10);
      if (!Number.isFinite(top) || top < 0) return;

      if ('scrollRestoration' in history) {
        history.scrollRestoration = 'manual';
      }

      const apply = () => window.scrollTo(0, top);
      apply();
      window.requestAnimationFrame(apply);
      window.setTimeout(apply, 120);
    } catch (error) {
      // Ignore storage failures and keep navigation working.
    }
  }

  function getCsrfToken() {
    return (
      document.querySelector('meta[name="csrf-token"]')?.content ||
      document.querySelector('input[name="csrf_token"]')?.value ||
      ''
    );
  }

  function renderStatus(statusEl, kind, message) {
    if (!statusEl) return;
    statusEl.classList.remove('is-success', 'is-error', 'is-loading');

    if (kind === 'loading') {
      statusEl.classList.add('is-loading');
      statusEl.textContent = message;
      return;
    }

    const icon = kind === 'success' ? OK_ICON : ERR_ICON;
    statusEl.classList.add(kind === 'success' ? 'is-success' : 'is-error');
    statusEl.innerHTML = `${icon}<span>${message}</span>`;
  }

  function syncSavedFonts() {
    const raw = document.body?.dataset.adminFontSettings;
    if (!raw) return;

    let saved = {};
    let current = {};

    try {
      saved = JSON.parse(raw);
    } catch (error) {
      return;
    }

    try {
      current = JSON.parse(localStorage.getItem('fontSettings') || '{}');
    } catch (error) {
      current = {};
    }

    Object.keys(saved).forEach((key) => {
      if (saved[key] !== null) current[key] = saved[key];
    });

    localStorage.setItem('fontSettings', JSON.stringify(current));
  }

  async function postJson(url, body) {
    const csrf = getCsrfToken();
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf,
      },
      body: JSON.stringify({
        csrf_token: csrf,
        ...body,
      }),
    });
    return response.json();
  }

  async function toggleAvailable(id, btn) {
    const row = btn.closest('tr') || btn.closest('.mobile-table-item');
    btn.disabled = true;

    try {
      const data = await postJson('/toggle-available.php', { id });

      if (!data.success) {
        alert(data.error || 'Ошибка');
        return;
      }

      const isAvailable = Number(data.available) === 1;
      btn.textContent = isAvailable ? 'СТОП' : 'Вернуть';
      btn.title = isAvailable ? 'Снять с продажи' : 'Вернуть в продажу';
      btn.classList.toggle('stop-btn--active', !isAvailable);
      row?.classList.toggle('admin-item-row--paused', !isAvailable);
    } catch (error) {
      alert('Ошибка сети');
    } finally {
      btn.disabled = false;
    }
  }

  async function savePaymentSettings() {
    const status = document.getElementById('paymentStatus');
    renderStatus(status, 'loading', 'Сохраняю...');

    try {
      const data = await postJson('/save-payment-settings.php', {
        payment: {
          yookassa_enabled: document.getElementById('ykEnabled')?.checked ?? false,
          yookassa_shop_id: document.getElementById('ykShopId')?.value.trim() || '',
          yookassa_secret_key: document.getElementById('ykSecretKey')?.value.trim() || '',
        },
      });

      renderStatus(status, data.success ? 'success' : 'error', data.success ? 'Сохранено' : (data.error || 'Ошибка'));
    } catch (error) {
      renderStatus(status, 'error', 'Ошибка сети');
    }
  }

  async function saveTBankSettings() {
    const status = document.getElementById('tbankStatus');
    renderStatus(status, 'loading', 'Сохраняю...');

    try {
      const data = await postJson('/save-payment-settings.php', {
        payment: {
          tbank_enabled: document.getElementById('tbEnabled')?.checked ?? false,
          tbank_terminal_key: document.getElementById('tbTerminalKey')?.value.trim() || '',
          tbank_password: document.getElementById('tbPassword')?.value.trim() || '',
        },
      });

      renderStatus(status, data.success ? 'success' : 'error', data.success ? 'Сохранено' : (data.error || 'Ошибка'));
    } catch (error) {
      renderStatus(status, 'error', 'Ошибка сети');
    }
  }

  async function saveTelegramChatId() {
    const button = document.getElementById('saveTgChatIdBtn');
    const status = document.getElementById('tgChatIdStatus');
    const value = document.getElementById('tgChatId')?.value.trim() || '';

    if (!button) return;

    button.disabled = true;
    renderStatus(status, 'loading', 'Сохраняю...');

    try {
      const data = await postJson('/save-brand.php', {
        brand: {
          telegram_chat_id: value,
        },
      });

      renderStatus(status, data.success ? 'success' : 'error', data.success ? 'Сохранено' : (data.error || 'Ошибка'));
    } catch (error) {
      renderStatus(status, 'error', 'Ошибка сети');
    } finally {
      button.disabled = false;
    }
  }

  function bindBrandControls() {
    document.getElementById('saveBrandBtn')?.addEventListener('click', () => {
      if (typeof window.saveBrand === 'function') window.saveBrand();
    });

    document.getElementById('brandLogoUrl')?.addEventListener('input', (event) => {
      if (typeof window.updateBrandLogoPreview === 'function') {
        window.updateBrandLogoPreview(event.target.value);
      }
    });
  }

  function bindAdminActions() {
    document.getElementById('savePaymentBtn')?.addEventListener('click', savePaymentSettings);
    document.getElementById('saveTBankBtn')?.addEventListener('click', saveTBankSettings);
    document.getElementById('saveTgChatIdBtn')?.addEventListener('click', saveTelegramChatId);

    document.addEventListener('click', (event) => {
      const navLink = event.target.closest('a[href]');
      if (!navLink) return;

      const href = navLink.getAttribute('href') || '';
      if (href.startsWith('admin-menu.php')) {
        saveScrollPosition();
      }
    });

    document.addEventListener('submit', (event) => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement)) return;

      const action = form.getAttribute('action') || 'admin-menu.php';
      if (action === '' || action.startsWith('admin-menu.php')) {
        saveScrollPosition();
      }
    });

    document.addEventListener('click', (event) => {
      const stopBtn = event.target.closest('.stop-btn');
      if (!stopBtn) return;

      const id = parseInt(stopBtn.getAttribute('data-item-id') || '', 10);
      if (id) toggleAvailable(id, stopBtn);
    });
  }

  syncSavedFonts();
  document.addEventListener('DOMContentLoaded', () => {
    restoreScrollPosition();
    bindBrandControls();
    bindAdminActions();
  });
})();
