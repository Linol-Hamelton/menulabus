/**
 * onboarding.js — first-launch wizard logic for /onboarding.php.
 *
 * Was previously inline `<script nonce>` inside onboarding.php (Phase 4
 * MVP). Phase 24 extracted to a dedicated file for consistency with the
 * rest of the project (all other JS lives in /js/*.js, never inline) and
 * to make the wizard easier to extend with new steps without touching
 * the PHP template.
 *
 * CSRF token is read from <meta name="csrf-token"> instead of the old
 * inline JSON-encoded literal — same pattern used everywhere else in the
 * codebase. Behavior is byte-identical to the previous inline version.
 */
(function () {
  'use strict';

  function readCsrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') || '' : '';
  }

  var csrf = readCsrf();

  function postBrand(data) {
    return fetch('/api/save/brand.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf
      },
      body: JSON.stringify({ brand: data, csrf_token: csrf })
    });
  }

  function postColors(colors) {
    return fetch('/api/save/colors.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf
      },
      body: JSON.stringify({ colors: colors, csrf_token: csrf })
    });
  }

  function setBusy(btn, busyLabel) {
    btn.dataset.originalLabel = btn.textContent;
    btn.textContent = busyLabel || '...';
    btn.disabled = true;
  }

  function clearBusy(btn) {
    if (btn.dataset.originalLabel) {
      btn.textContent = btn.dataset.originalLabel;
      delete btn.dataset.originalLabel;
    }
    btn.disabled = false;
  }

  async function saveNameAndNext() {
    var input = document.getElementById('restaurantName');
    if (!input) return;
    var name = input.value.trim();
    if (!name) { input.focus(); return; }
    var btn = document.getElementById('nextBtn');
    setBusy(btn);
    try {
      await postBrand({ app_name: name });
      window.location.href = '?step=2';
    } catch (e) {
      clearBusy(btn);
    }
  }

  async function saveLogoAndNext() {
    var input = document.getElementById('obLogoUrl');
    var btn = document.getElementById('logoNextBtn');
    if (!input || !btn) return;
    var url = input.value.trim();
    setBusy(btn);
    try {
      if (url) await postBrand({ logo_url: url });
      window.location.href = '?step=3';
    } catch (e) {
      clearBusy(btn);
    }
  }

  async function saveColorsAndNext() {
    var btn = document.getElementById('colorsNextBtn');
    if (!btn) return;
    var primary   = document.getElementById('obPrimaryColor')?.value   || '#cd1719';
    var secondary = document.getElementById('obSecondaryColor')?.value || '#121212';
    setBusy(btn, 'Сохраняем…');
    try {
      await postColors({ 'primary-color': primary, 'secondary-color': secondary });
      window.location.href = '?step=4';
    } catch (e) {
      clearBusy(btn);
    }
  }

  function bindLogoPreview() {
    var input = document.getElementById('obLogoUrl');
    if (!input) return;
    input.addEventListener('input', function () {
      var img = document.getElementById('obLogoPreview');
      if (!img) return;
      var v = this.value.trim();
      if (v) {
        img.src = v;
        img.classList.add('visible');
      } else {
        img.classList.remove('visible');
      }
    });
  }

  function init() {
    bindLogoPreview();
    document.getElementById('nextBtn')?.addEventListener('click', saveNameAndNext);
    document.getElementById('logoNextBtn')?.addEventListener('click', saveLogoAndNext);
    document.getElementById('colorsNextBtn')?.addEventListener('click', saveColorsAndNext);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
