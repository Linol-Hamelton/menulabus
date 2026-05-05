/**
 * admin-design-modals.js — Phase 17
 *
 * Lifecycle for the 5 design-tab modals (brand / fonts / colors / files / launch).
 * Plate clicks open the corresponding <dialog>; Esc / [data-modal-close] / .modal-close /
 * backdrop close. Reuses window.FocusTrap.
 *
 * Existing save handlers (saveBrandBtn, saveFontsBtn, saveColorsBtn, saveProjectNameBtn)
 * remain in DOM with the same IDs and continue to work without modification —
 * we only relocate the markup into modal panes.
 *
 * Additional features:
 *   - Brand-modal: 3 sub-tabs + side-rail live preview (logo, name, tagline,
 *     phone, address, map link).
 *   - Fonts-modal: side-rail preview rendering 3 strings each in current font.
 *   - Colors-modal: 4 preset palette swatches, 3 key colors visible, advanced
 *     <details> for the remaining 9, side-rail palette + sample card, "Свой"
 *     auto-active when user manually edits any picker.
 *   - Plate summary refresh after each save (brand name, fonts list,
 *     primary-color hex, launch warnings count).
 */
(function () {
  'use strict';

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  // -------------------------------------------------------------------------
  // Generic modal lifecycle
  // -------------------------------------------------------------------------
  function makeModalApi(modalId) {
    var modal = document.getElementById(modalId);
    if (!modal || typeof modal.showModal !== 'function') return null;

    function open() {
      try { modal.showModal(); } catch (e) { /* already open */ }
      if (window.FocusTrap) window.FocusTrap.activate(modal, { onEscape: close });
    }
    function close() {
      if (window.FocusTrap) window.FocusTrap.deactivate(modal);
      try { modal.close(); } catch (e) { /* noop */ }
    }
    // Close triggers
    modal.addEventListener('click', function (e) {
      if (e.target === modal) { close(); return; } // backdrop click
      var trigger = e.target.closest('.modal-close, [data-modal-close]');
      if (trigger) { e.preventDefault(); close(); }
    });
    return { open: open, close: close, modal: modal };
  }

  // -------------------------------------------------------------------------
  // Plates: open trigger + summary refresh
  // -------------------------------------------------------------------------
  function bindPlates(modalApis) {
    document.addEventListener('click', function (e) {
      var plate = e.target.closest('.design-plate[data-design-modal]');
      if (!plate) return;
      var key = plate.getAttribute('data-design-modal');
      var api = modalApis[key];
      if (api) api.open();
    });
  }

  function refreshBrandSummary() {
    var name = ($('#brandName') || {}).value || '';
    var tagline = ($('#brandTagline') || {}).value || '';
    var summary = (name + (tagline ? ' · ' + tagline : '')).trim() || '—';
    var el = $('.js-summary-brand');
    if (el) el.textContent = summary;
  }

  function refreshFontsSummary() {
    var pick = function (id) {
      var sel = $('#' + id);
      if (!sel) return '';
      var opt = sel.options[sel.selectedIndex];
      return opt ? (opt.textContent || '').replace(/\s*\(по умолчанию\)\s*$/, '').trim() : '';
    };
    var parts = [pick('fontLogo'), pick('fontText'), pick('fontHeading')].filter(Boolean);
    var el = $('.js-summary-fonts');
    if (el) el.textContent = parts.join(' · ') || 'по умолчанию';
  }

  function refreshColorsSummary() {
    var primary = ($('#colorPrimarycolor') || {}).value || '';
    var el = $('.js-summary-colors');
    if (el) el.textContent = primary || '—';
    // Update icon swatch as well
    var icon = $('.design-plate[data-design-modal="colors"] .design-plate-icon');
    if (icon && primary) {
      icon.style.setProperty('--design-plate-c1', primary);
      var accent = ($('#colorAccentcolor') || {}).value || primary;
      icon.style.setProperty('--design-plate-c2', accent);
    }
  }

  function applyPlateIconColors() {
    // Plate icon for "Цвета" gets gradient swatch from data attributes
    // (CSP-safe: no inline style attribute, set via JS after load).
    var icon = document.querySelector('.design-plate-icon-color');
    if (!icon) return;
    var c1 = icon.dataset.colorPrimary || '#cd1719';
    var c2 = icon.dataset.colorAccent  || '#db3a34';
    icon.style.setProperty('--design-plate-c1', c1);
    icon.style.setProperty('--design-plate-c2', c2);
  }

  function paintPresetSwatches() {
    // Render the 4 swatch <span>s inside each .color-preset button from
    // COLOR_PRESETS data (no inline style attributes in HTML — CSP-clean).
    document.querySelectorAll('.color-preset[data-preset]').forEach(function (btn) {
      var name = btn.dataset.preset;
      var swatches = btn.querySelectorAll('.color-preset-swatches > span');
      if (swatches.length !== 4) return;
      if (name === 'custom') {
        var p = (document.querySelector('input[type="color"][data-var="primary-color"]') || {}).value || '#cd1719';
        var s = (document.querySelector('input[type="color"][data-var="secondary-color"]') || {}).value || '#121212';
        var a = (document.querySelector('input[type="color"][data-var="accent-color"]') || {}).value || '#db3a34';
        var w = (document.querySelector('input[type="color"][data-var="white"]') || {}).value || '#ffffff';
        swatches[0].style.background = p;
        swatches[1].style.background = s;
        swatches[2].style.background = a;
        swatches[3].style.background = w;
        return;
      }
      var palette = COLOR_PRESETS[name];
      if (!palette) return;
      swatches[0].style.background = palette['primary-color'];
      swatches[1].style.background = palette['secondary-color'];
      swatches[2].style.background = palette['accent-color'];
      swatches[3].style.background = palette['bg-light'] || '#f9f9f9';
    });
  }

  // -------------------------------------------------------------------------
  // Brand modal: sub-tabs + side-rail preview
  // -------------------------------------------------------------------------
  function initBrandModal() {
    var modal = document.getElementById('brandModal');
    if (!modal) return;

    var tabBtns = $$('.modal-tab-btn', modal);
    var panes = $$('.modal-pane', modal);

    function setTab(name) {
      tabBtns.forEach(function (btn) {
        btn.setAttribute('aria-selected', btn.dataset.tab === name ? 'true' : 'false');
      });
      panes.forEach(function (p) { p.hidden = p.dataset.pane !== name; });
    }
    tabBtns.forEach(function (btn) {
      btn.addEventListener('click', function () { setTab(btn.dataset.tab); });
    });
    // Default open tab
    setTab('identity');

    function readField(id) {
      var el = $('#' + id);
      if (!el) return '';
      if (el.type === 'checkbox') return el.checked ? '1' : '';
      return (el.value || '').trim();
    }

    function updatePreview() {
      var name    = readField('brandName')         || 'Название';
      var tagline = readField('brandTagline')      || '';
      var desc    = readField('brandDesc')         || '';
      var logo    = readField('brandLogoUrl')      || '';
      var phone   = readField('brandPhone')        || '';
      var address = readField('brandAddress')      || '';
      var mapUrl  = readField('brandMapUrl')       || '';

      var preview = modal.querySelector('.brand-preview-card');
      if (!preview) return;

      var logoEl = preview.querySelector('.brand-preview-logo');
      if (logoEl) {
        if (logo) {
          logoEl.src = logo;
          logoEl.hidden = false;
        } else {
          logoEl.hidden = true;
        }
      }
      var nameEl = preview.querySelector('.brand-preview-name');
      if (nameEl) nameEl.textContent = name;

      var taglineEl = preview.querySelector('.brand-preview-tagline');
      if (taglineEl) {
        taglineEl.textContent = tagline;
        taglineEl.hidden = !tagline;
      }
      var descEl = preview.querySelector('.brand-preview-desc');
      if (descEl) {
        descEl.textContent = desc;
        descEl.hidden = !desc;
      }
      var phoneEl = preview.querySelector('.brand-preview-phone');
      if (phoneEl) phoneEl.textContent = phone;
      var contactRow = preview.querySelector('.brand-preview-contact');
      if (contactRow) contactRow.hidden = !phone;

      var addrEl = preview.querySelector('.brand-preview-address-text');
      if (addrEl) addrEl.textContent = address;
      var mapEl = preview.querySelector('.brand-preview-map');
      if (mapEl) {
        mapEl.hidden = !mapUrl;
        if (mapUrl) mapEl.href = mapUrl;
      }
      var addrRow = preview.querySelector('.brand-preview-address');
      if (addrRow) addrRow.hidden = !address && !mapUrl;
    }

    // Wire input listeners on every field that affects preview
    ['brandName','brandTagline','brandDesc','brandLogoUrl','brandPhone',
     'brandAddress','brandMapUrl'].forEach(function (id) {
      var el = $('#' + id);
      if (el) el.addEventListener('input', updatePreview);
    });

    // Save observation: when saveBrandBtn produces a success status, refresh summary
    var saveBtn = $('#saveBrandBtn');
    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        // Existing handler in admin-menu-page.js fires a fetch and updates
        // #brandStatus. Watch for the success path via MutationObserver and
        // refresh the plate summary + close after a brief delay.
        var status = $('#brandStatus');
        if (!status) return;
        var observer = new MutationObserver(function () {
          var txt = (status.textContent || '').toLowerCase();
          if (txt.includes('сохран') || txt.includes('saved') || txt.includes('✓')) {
            refreshBrandSummary();
            updatePreview();
            // Auto-close after a second
            setTimeout(function () {
              var api = window.AdminDesignModals && window.AdminDesignModals.brand;
              if (api) api.close();
            }, 800);
            observer.disconnect();
          }
        });
        observer.observe(status, { childList: true, characterData: true, subtree: true });
        // Stop observing after 8s if no change
        setTimeout(function () { observer.disconnect(); }, 8000);
      });
    }

    // Initial preview render
    updatePreview();
  }

  // -------------------------------------------------------------------------
  // Fonts modal: live preview rail
  // -------------------------------------------------------------------------
  function initFontsModal() {
    var modal = document.getElementById('fontsModal');
    if (!modal) return;

    function updateFontsPreview() {
      ['logo', 'text', 'heading'].forEach(function (target) {
        var sel = $('#font' + target.charAt(0).toUpperCase() + target.slice(1));
        if (!sel) return;
        var row = modal.querySelector('.fonts-preview-row[data-target="' + target + '"]');
        if (!row) return;
        var textEl = row.querySelector('.fonts-preview-row-text');
        if (textEl) textEl.style.fontFamily = sel.value || '';
      });
    }

    ['fontLogo', 'fontText', 'fontHeading'].forEach(function (id) {
      var el = $('#' + id);
      if (el) el.addEventListener('change', updateFontsPreview);
    });

    // Refresh plate summary after save
    var saveBtn = $('#saveFontsBtn');
    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        setTimeout(function () { refreshFontsSummary(); updateFontsPreview(); }, 600);
      });
    }

    updateFontsPreview();
  }

  // -------------------------------------------------------------------------
  // Colors modal: presets + advanced details + side-rail preview
  // -------------------------------------------------------------------------
  var COLOR_PRESETS = {
    classic: {
      'primary-color':  '#cd1719',
      'secondary-color':'#121212',
      'primary-dark':   '#000000',
      'accent-color':   '#db3a34',
      'text-color':     '#333333',
      'acception':      '#2c83c2',
      'light-text':     '#555555',
      'bg-light':       '#f9f9f9',
      'white':          '#ffffff',
      'agree':          '#4caf50',
      'procces':        '#ff9321',
      'brown':          '#712121',
    },
    dark: {
      'primary-color':  '#9b1c1c',
      'secondary-color':'#1f2937',
      'primary-dark':   '#0f172a',
      'accent-color':   '#7c3aed',
      'text-color':     '#e5e7eb',
      'acception':      '#3b82f6',
      'light-text':     '#9ca3af',
      'bg-light':       '#111827',
      'white':          '#1f2937',
      'agree':          '#10b981',
      'procces':        '#f59e0b',
      'brown':          '#7c2d12',
    },
    fresh: {
      'primary-color':  '#16a34a',
      'secondary-color':'#0f766e',
      'primary-dark':   '#064e3b',
      'accent-color':   '#f97316',
      'text-color':     '#1f2937',
      'acception':      '#0ea5e9',
      'light-text':     '#6b7280',
      'bg-light':       '#f0fdf4',
      'white':          '#ffffff',
      'agree':          '#22c55e',
      'procces':        '#eab308',
      'brown':          '#854d0e',
    },
  };

  function pickerByVar(varName) {
    return $('input[type="color"][data-var="' + varName + '"]');
  }

  function initColorsModal() {
    var modal = document.getElementById('colorsModal');
    if (!modal) return;

    var presetButtons = $$('.color-preset', modal);

    function setActivePreset(name) {
      presetButtons.forEach(function (btn) {
        btn.classList.toggle('is-active', btn.dataset.preset === name);
      });
    }

    function applyPreset(name) {
      var palette = COLOR_PRESETS[name];
      if (!palette) return;
      Object.keys(palette).forEach(function (varName) {
        var picker = pickerByVar(varName);
        if (picker) {
          picker.value = palette[varName];
          // Update sibling .color-value text
          var sibling = picker.parentElement && picker.parentElement.querySelector('.color-value');
          if (sibling) sibling.textContent = palette[varName];
          // Trigger input event so existing listeners (live recolor) fire
          picker.dispatchEvent(new Event('input', { bubbles: true }));
        }
      });
      setActivePreset(name);
      updatePreview();
    }

    presetButtons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var name = btn.dataset.preset;
        if (name === 'custom') { setActivePreset('custom'); return; }
        applyPreset(name);
      });
    });

    function detectPreset() {
      // If current values match a preset exactly → mark that preset active.
      // Otherwise → "custom".
      var current = {};
      $$('input[type="color"][data-var]', modal).forEach(function (p) {
        current[p.dataset.var] = (p.value || '').toLowerCase();
      });
      var match = Object.keys(COLOR_PRESETS).find(function (presetName) {
        var palette = COLOR_PRESETS[presetName];
        return Object.keys(palette).every(function (k) {
          return (palette[k] || '').toLowerCase() === current[k];
        });
      });
      setActivePreset(match || 'custom');
    }

    function updatePreview() {
      var rail = $('.colors-preview-rail', modal);
      if (!rail) return;

      var primary   = (pickerByVar('primary-color') || {}).value || '#cd1719';
      var secondary = (pickerByVar('secondary-color') || {}).value || '#121212';
      var accent    = (pickerByVar('accent-color') || {}).value || '#db3a34';
      var bgLight   = (pickerByVar('bg-light') || {}).value || '#f9f9f9';
      var white     = (pickerByVar('white') || {}).value || '#ffffff';

      var palette = $('.colors-palette-row', rail);
      if (palette) {
        var spans = palette.querySelectorAll('span');
        if (spans[0]) spans[0].style.background = primary;
        if (spans[1]) spans[1].style.background = secondary;
        if (spans[2]) spans[2].style.background = accent;
        if (spans[3]) spans[3].style.background = bgLight;
      }

      var card = $('.colors-sample-card', rail);
      if (card) {
        card.style.setProperty('--colors-primary', primary);
        card.style.setProperty('--colors-secondary', secondary);
        card.style.setProperty('--colors-bg', bgLight);
        card.style.setProperty('--colors-text', white);
      }
    }

    // Listen to manual edits to:
    //  (a) update the .color-value span next to each picker;
    //  (b) update the side-rail preview;
    //  (c) re-detect preset (manual edit → "custom").
    $$('input[type="color"][data-var]', modal).forEach(function (picker) {
      picker.addEventListener('input', function () {
        var sibling = picker.parentElement && picker.parentElement.querySelector('.color-value');
        if (sibling) sibling.textContent = picker.value;
        updatePreview();
        detectPreset();
        // Plate icon swatch
        refreshColorsSummary();
      });
    });

    // Refresh plate summary after save
    var saveBtn = $('#saveColorsBtn');
    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        setTimeout(function () { refreshColorsSummary(); }, 600);
      });
    }

    detectPreset();
    updatePreview();
  }

  // -------------------------------------------------------------------------
  // Bootstrap
  // -------------------------------------------------------------------------
  document.addEventListener('DOMContentLoaded', function () {
    var apis = {
      brand:  makeModalApi('brandModal'),
      fonts:  makeModalApi('fontsModal'),
      colors: makeModalApi('colorsModal'),
      files:  makeModalApi('filesModal'),
      launch: makeModalApi('launchModal'),
    };
    if (Object.values(apis).every(function (a) { return a === null; })) return;

    bindPlates(apis);
    initBrandModal();
    initFontsModal();
    initColorsModal();

    // Initial summary spans
    refreshBrandSummary();
    refreshFontsSummary();
    refreshColorsSummary();

    // CSP-safe styling of dynamic colour swatches
    applyPlateIconColors();
    paintPresetSwatches();
    // Re-paint "custom" swatch on any color edit so it tracks user edits
    document.querySelectorAll('input[type="color"][data-var]').forEach(function (p) {
      p.addEventListener('input', paintPresetSwatches);
    });

    window.AdminDesignModals = apis;
  });
})();
