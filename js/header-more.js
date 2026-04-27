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

    toggles.forEach(function (toggle) {
      toggle.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var more = toggle.closest('.nav-more');
        var willOpen = !more.classList.contains('is-open');
        closeAll(more);
        more.classList.toggle('is-open', willOpen);
        toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
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
      if (e.key !== 'Escape') return;
      var openMore = document.querySelector('.nav-more.is-open');
      if (!openMore) return;
      closeAll(null);
      var btn = openMore.querySelector('.nav-more-toggle');
      if (btn) btn.focus();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
