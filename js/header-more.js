// Header "Ещё ▾" dropdown — desktop only.
// On mobile (<= 1250px) the wrapper dissolves via `display: contents`,
// so all we need to do is wire the toggle button + outside-click + Escape.
//
// IMPORTANT: another global click handler (mobile burger / cart sync /
// app.min.js) calls e.stopPropagation() somewhere upstream — a document-
// level click handler never sees the toggle event. So we bind the open
// handler DIRECTLY to the button instead. Outside-click / ESC stay on
// document because they don't depend on the toggle's bubbling.
(function () {
  'use strict';

  function init() {
    var toggles = document.querySelectorAll('.nav-more-toggle');
    if (!toggles.length) return;

    function closeAll(except) {
      document.querySelectorAll('.nav-more.is-open').forEach(function (m) {
        if (m === except) return;
        m.classList.remove('is-open');
        var btn = m.querySelector('.nav-more-toggle');
        if (btn) btn.setAttribute('aria-expanded', 'false');
      });
    }

    function items(more) {
      return Array.prototype.slice.call(
        more.querySelectorAll('.nav-more-menu [role="menuitem"]')
      ).filter(function (n) { return !n.hasAttribute('disabled'); });
    }

    function focusItem(more, dir) {
      var list = items(more);
      if (!list.length) return;
      var current = list.indexOf(document.activeElement);
      var next;
      if (dir === 'first')      next = 0;
      else if (dir === 'last')  next = list.length - 1;
      else if (dir === 'next')  next = current < 0 ? 0 : (current + 1) % list.length;
      else if (dir === 'prev')  next = current <= 0 ? list.length - 1 : current - 1;
      list[next].focus();
    }

    toggles.forEach(function (toggle) {
      toggle.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var more = toggle.closest('.nav-more');
        var willOpen = !more.classList.contains('is-open');
        closeAll(more);
        more.classList.toggle('is-open', willOpen);
        toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        if (willOpen) focusItem(more, 'first');
      });

      toggle.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
        e.preventDefault();
        var more = toggle.closest('.nav-more');
        if (!more.classList.contains('is-open')) {
          more.classList.add('is-open');
          toggle.setAttribute('aria-expanded', 'true');
        }
        focusItem(more, e.key === 'ArrowDown' ? 'first' : 'last');
      });
    });

    // Click outside any open menu → close. Capture phase so we win against
    // other handlers that stop propagation.
    document.addEventListener('click', function (e) {
      var openMore = document.querySelector('.nav-more.is-open');
      if (!openMore) return;
      if (e.target.closest('.nav-more')) return; // click inside menu/toggle
      closeAll(null);
    }, true);

    document.addEventListener('keydown', function (e) {
      var openMore = document.querySelector('.nav-more.is-open');
      if (!openMore) return;
      if (e.key === 'Escape') {
        closeAll(null);
        var btn = openMore.querySelector('.nav-more-toggle');
        if (btn) btn.focus();
        return;
      }
      // Arrow nav inside the open menu.
      if (!openMore.contains(document.activeElement)) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); focusItem(openMore, 'next'); }
      else if (e.key === 'ArrowUp')   { e.preventDefault(); focusItem(openMore, 'prev'); }
      else if (e.key === 'Home')      { e.preventDefault(); focusItem(openMore, 'first'); }
      else if (e.key === 'End')       { e.preventDefault(); focusItem(openMore, 'last'); }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
