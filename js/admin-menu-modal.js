/**
 * admin-menu-modal.js — Phase 16
 *
 * Lifecycle for the dish editor modal (#dishEditorModal). Handles:
 *  - opening (with itemId or null for create);
 *  - tab switching (Основное / Изображение / Модификаторы / Рецепт / Превью);
 *  - AJAX save through /api/save-menu-item.php;
 *  - live-preview update on input;
 *  - catalog row update / insert without reload;
 *  - lazy init of image picker, modifiers, recipe sub-components.
 *
 * No external deps. Reuses window.FocusTrap (focus-trap.js) and
 * window.AdminImagePicker (admin-image-picker.js).
 */
(function () {
  'use strict';

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function getCsrf() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) return meta.content;
    var body = document.body;
    if (body && body.dataset && body.dataset.csrfToken) return body.dataset.csrfToken;
    return '';
  }

  function fmtPrice(value) {
    var n = parseFloat(value);
    if (!isFinite(n)) return '0.00 ₽';
    return n.toFixed(2).replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1') + ' ₽';
  }

  function escHtml(text) {
    var div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
  }

  // ===========================================================================
  // Modal API
  // ===========================================================================
  function initModal() {
    var modal = document.getElementById('dishEditorModal');
    if (!modal || typeof modal.showModal !== 'function') return null;

    var titleEl    = $('.modal-title', modal);
    var subtitleEl = $('.modal-subtitle', modal);
    var statusEl   = $('.modal-status', modal);
    var form       = $('form.dish-form', modal);
    var tabsBar    = $('.modal-tabs', modal);
    var tabBtns    = $$('.modal-tab-btn', modal);
    var panes      = $$('.modal-pane', modal);
    var saveBtn    = $('.btn-save', modal);
    var cancelBtn  = $('.modal-close', modal);
    var deleteBtn  = $('.btn-archive', modal);
    var imageHidden = $('input[name="image"]', modal);
    var imageSummary = $('.image-summary', modal);

    var state = {
      itemId: null,
      open: false,
      pristine: true,
      modifiersInited: false,
      recipeInited: false,
      pickerInited: false,
    };

    function setStatus(msg, kind) {
      if (!statusEl) return;
      statusEl.textContent = msg || '';
      statusEl.className = 'modal-status' + (kind ? ' ' + kind : '');
    }

    function setTab(name) {
      tabBtns.forEach(function (btn) {
        var active = btn.dataset.tab === name;
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      panes.forEach(function (pane) {
        pane.hidden = pane.dataset.pane !== name;
      });
      // Toggle preview side-rail
      var body = $('.modal-body', modal);
      if (body) body.classList.toggle('with-preview', name === 'main');

      // Lazy-init heavy tabs
      if (name === 'image' && !state.pickerInited && window.AdminImagePicker) {
        window.AdminImagePicker.mount(modal.querySelector('[data-pane="image"]'), {
          getValue: function () { return imageHidden.value || ''; },
          setValue: function (v) {
            imageHidden.value = v || '';
            updateImageSummary();
            updatePreview();
          },
        });
        state.pickerInited = true;
      }
      if (name === 'modifiers' && !state.modifiersInited && state.itemId && window.AdminModifiers) {
        try {
          window.AdminModifiers.init(modal.querySelector('[data-pane="modifiers"] [data-modifier-root]'), state.itemId);
          state.modifiersInited = true;
        } catch (e) { console.warn('modifiers init failed', e); }
      }
      if (name === 'recipe' && !state.recipeInited && state.itemId && window.AdminRecipe) {
        try {
          window.AdminRecipe.init(modal.querySelector('[data-pane="recipe"] [data-recipe-root]'), state.itemId);
          state.recipeInited = true;
        } catch (e) { console.warn('recipe init failed', e); }
      }
    }

    function updateImageSummary() {
      if (!imageSummary) return;
      var path = (imageHidden.value || '').trim();
      var imgEl = imageSummary.querySelector('img');
      var placeholderEl = imageSummary.querySelector('.placeholder');
      var pathEl = imageSummary.querySelector('code');
      if (path) {
        if (imgEl) {
          imgEl.src = path.replace(/^\.\//, '/');
          imgEl.hidden = false;
        }
        if (placeholderEl) placeholderEl.hidden = true;
        if (pathEl) pathEl.textContent = path;
      } else {
        if (imgEl) imgEl.hidden = true;
        if (placeholderEl) placeholderEl.hidden = false;
        if (pathEl) pathEl.textContent = 'Изображение не выбрано';
      }
    }

    function updatePreview() {
      var data = collectFormData();
      // There are TWO .preview-card instances: side-rail aside (visible
      // on desktop tab=main) and the dedicated «Превью» tab pane. Update
      // both so they stay in sync regardless of which one the user sees.
      var cards = modal.querySelectorAll('.preview-card');
      cards.forEach(function (card) {
        var imgEl = card.querySelector('.preview-card-img');
        if (imgEl) {
          if (data.image) {
            imgEl.hidden = false;
            imgEl.src = data.image.replace(/^\.\//, '/');
          } else {
            imgEl.hidden = true;
          }
        }
        var nameEl = card.querySelector('.preview-card-name');
        if (nameEl) nameEl.textContent = data.name || 'Название блюда';
        var catEl = card.querySelector('.preview-card-cat');
        if (catEl) catEl.textContent = data.category || 'Категория';
        var descEl = card.querySelector('.preview-card-desc');
        if (descEl) descEl.textContent = data.description || 'Описание появится здесь.';
        var priceEl = card.querySelector('.preview-card-price');
        if (priceEl) priceEl.textContent = fmtPrice(data.price || 0);
        var stopEl = card.querySelector('.preview-card-stop');
        if (stopEl) stopEl.hidden = !!data.available;
        var bjuEl = card.querySelector('.preview-card-bju');
        if (bjuEl) {
          var parts = [];
          if (data.calories) parts.push('<span>' + escHtml(data.calories) + ' ккал</span>');
          if (data.protein)  parts.push('<span>Б ' + escHtml(data.protein) + '</span>');
          if (data.fat)      parts.push('<span>Ж ' + escHtml(data.fat) + '</span>');
          if (data.carbs)    parts.push('<span>У ' + escHtml(data.carbs) + '</span>');
          bjuEl.innerHTML = parts.join('');
        }
      });
    }

    function collectFormData() {
      var data = {};
      $$('input[name], textarea[name]', form).forEach(function (el) {
        if (el.type === 'checkbox') {
          data[el.name] = el.checked ? 1 : 0;
        } else if (el.type === 'number') {
          data[el.name] = el.value === '' ? null : parseFloat(el.value);
        } else {
          data[el.name] = el.value;
        }
      });
      return data;
    }

    function fillForm(item) {
      // Reset all
      $$('input[name], textarea[name]', form).forEach(function (el) {
        if (el.type === 'checkbox') el.checked = false;
        else el.value = '';
      });
      if (!item) return;
      var fields = ['name','description','composition','price','image','calories','protein','fat','carbs','category'];
      fields.forEach(function (f) {
        var el = form.querySelector('[name="' + f + '"]');
        if (el) el.value = item[f] == null ? '' : item[f];
      });
      var avail = form.querySelector('[name="available"]');
      if (avail) avail.checked = !!Number(item.available != null ? item.available : 1);
      updateImageSummary();
      updatePreview();
    }

    function open(itemId) {
      state.itemId = itemId ? Number(itemId) : null;
      state.pristine = true;
      state.modifiersInited = false;
      state.recipeInited = false;
      state.pickerInited = false;
      setStatus('');
      modal.classList.remove('is-busy');

      // Title
      titleEl.textContent = state.itemId ? ('Редактировать блюдо #' + state.itemId) : 'Создать блюдо';
      subtitleEl.textContent = state.itemId ? '' : 'Заполните основные поля и сохраните, чтобы привязать модификаторы и рецепт.';

      // Hide modifiers/recipe tabs for new (no id yet)
      tabBtns.forEach(function (btn) {
        if (btn.dataset.tab === 'modifiers' || btn.dataset.tab === 'recipe') {
          btn.hidden = !state.itemId;
        }
      });

      // Show modal first (so picker can measure on init), then load data
      try { modal.showModal(); } catch (e) { /* already open */ }
      if (window.FocusTrap) window.FocusTrap.activate(modal, { onEscape: close });

      setTab('main');

      if (state.itemId) {
        setStatus('Загрузка…');
        fetch('/api/save-menu-item.php?action=get&id=' + state.itemId, { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (json) {
            if (!json.success) throw new Error(json.error || 'load_failed');
            fillForm(json.item);
            setStatus('');
          })
          .catch(function (err) {
            setStatus('Ошибка загрузки: ' + (err.message || err), 'error');
          });
      } else {
        fillForm({ available: 1 });
      }
    }

    function close() {
      if (!modal.open) return;
      if (window.FocusTrap) window.FocusTrap.deactivate(modal);
      try { modal.close(); } catch (e) { /* noop */ }
      state.open = false;
    }

    function submit() {
      var data = collectFormData();
      if (state.itemId) data.id = state.itemId;
      data.action = 'save';
      data.csrf_token = getCsrf();

      // Local validation parity
      if (!data.name || !data.category) {
        setStatus('Название и категория обязательны.', 'error');
        return;
      }
      if (data.price == null || isNaN(data.price) || data.price < 0) {
        setStatus('Цена должна быть числом ≥ 0.', 'error');
        return;
      }

      modal.classList.add('is-busy');
      setStatus('Сохранение…');
      saveBtn.disabled = true;

      fetch('/api/save-menu-item.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
        body: JSON.stringify(data),
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
        .then(function (out) {
          modal.classList.remove('is-busy');
          saveBtn.disabled = false;
          if (!out.ok || !out.json.success) {
            setStatus('Ошибка: ' + (out.json && out.json.error || 'save_failed'), 'error');
            return;
          }
          var item = out.json.item || {};
          state.itemId = (item && item.id) ? Number(item.id) : state.itemId;
          setStatus('Сохранено ✓', 'ok');
          // Refresh row in table without reload
          if (window.AdminMenuRows && typeof window.AdminMenuRows.upsert === 'function') {
            window.AdminMenuRows.upsert(item);
          }
          // For new items: reveal modifiers/recipe tabs now that we have an id
          if (out.json.created) {
            tabBtns.forEach(function (btn) {
              if (btn.dataset.tab === 'modifiers' || btn.dataset.tab === 'recipe') {
                btn.hidden = false;
              }
            });
            titleEl.textContent = 'Редактировать блюдо #' + state.itemId;
            subtitleEl.textContent = '';
          }
          // Auto-close after a brief beat unless the user keeps editing
          setTimeout(function () {
            if (!state.itemId) return; // shouldn't happen
            // Close on quick save; keep open for further edits if user clicks again.
          }, 0);
        })
        .catch(function (err) {
          modal.classList.remove('is-busy');
          saveBtn.disabled = false;
          setStatus('Сетевая ошибка: ' + (err.message || err), 'error');
        });
    }

    function archive() {
      if (!state.itemId) return;
      if (!confirm('Архивировать это блюдо?')) return;
      fetch('/api/save-menu-item.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
        body: JSON.stringify({ action: 'archive', id: state.itemId, csrf_token: getCsrf() }),
      })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          if (!json.success) {
            setStatus('Не удалось архивировать.', 'error');
            return;
          }
          // Remove row from list
          if (window.AdminMenuRows && typeof window.AdminMenuRows.remove === 'function') {
            window.AdminMenuRows.remove(state.itemId);
          }
          close();
        })
        .catch(function () { setStatus('Сетевая ошибка', 'error'); });
    }

    // ----- Wiring -----------------------------------------------------------
    tabBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (btn.hidden) return;
        setTab(btn.dataset.tab);
      });
    });

    if (saveBtn)   saveBtn.addEventListener('click', submit);
    if (cancelBtn) cancelBtn.addEventListener('click', close);
    if (deleteBtn) deleteBtn.addEventListener('click', archive);

    // Live preview updates on every input
    form.addEventListener('input', updatePreview);
    form.addEventListener('change', updatePreview);

    // Backdrop click closes
    modal.addEventListener('click', function (e) {
      if (e.target === modal) close();
    });

    // Submit on Ctrl+Enter
    form.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); submit(); }
    });

    return { open: open, close: close };
  }

  // ===========================================================================
  // Catalog row helpers — global API used by modal AJAX response
  // ===========================================================================
  function rowCellByLabel(row, headerText) {
    // Fallback: index-based; used only if data-attrs missing.
    return null;
  }

  function rebuildRow(row, item) {
    if (!row || !item) return;
    var nameCell = row.querySelector('.dish-name-cell');
    if (nameCell) {
      var img = nameCell.querySelector('.dish-thumb');
      var nameSpan = nameCell.querySelector('.dish-name');
      if (img) {
        var src = (item.image || '').trim();
        img.src = src ? src.replace(/^\.\//, '/') : '/images/icons/dish-placeholder.svg';
      }
      if (nameSpan) nameSpan.textContent = item.name || '';
    }
    var catCell = row.querySelector('[data-col="category"]');
    if (catCell) catCell.textContent = item.category || '';
    var priceCell = row.querySelector('.js-inline-price');
    if (priceCell) priceCell.textContent = fmtPrice(item.price);
    row.dataset.category = item.category || '';
  }

  window.AdminMenuRows = {
    upsert: function (item) {
      if (!item || !item.id) return;
      var tbody = document.querySelector('.menu-items-table tbody');
      if (!tbody) return;
      var existing = tbody.querySelector('tr[data-item-id="' + item.id + '"]');
      if (existing) {
        rebuildRow(existing, item);
        return;
      }
      // Inserting new row would require knowing all columns; for v1, reload page
      // gently to reflect the newly-created item with full server-side
      // formatting (drag handle, bulk-checkbox, action buttons).
      window.location.reload();
    },
    remove: function (id) {
      var row = document.querySelector('.menu-items-table tbody tr[data-item-id="' + id + '"]');
      if (row && row.parentNode) row.parentNode.removeChild(row);
    },
  };

  // ===========================================================================
  // Public open trigger
  // ===========================================================================
  function bindOpenTriggers(api) {
    if (!api) return;
    document.addEventListener('click', function (e) {
      var editBtn = e.target.closest('.js-edit-dish');
      if (editBtn) {
        e.preventDefault();
        var id = parseInt(editBtn.dataset.itemId || '0', 10);
        api.open(id || null);
        return;
      }
      var newBtn = e.target.closest('.js-new-dish');
      if (newBtn) {
        e.preventDefault();
        api.open(null);
        return;
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var api = initModal();
    if (!api) return;
    bindOpenTriggers(api);
    window.AdminMenuModal = api;
  });
})();
