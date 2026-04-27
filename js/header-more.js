// Header "Ещё ▾" dropdown — desktop only.
// On mobile (<= 1250px) the wrapper dissolves via `display: contents`,
// so all we need to do is wire the toggle button + outside-click + Escape.
(function () {
  'use strict';

  function closeMenu(more) {
    if (!more) return;
    more.classList.remove('is-open');
    var btn = more.querySelector('.nav-more-toggle');
    if (btn) btn.setAttribute('aria-expanded', 'false');
  }

  function openMenu(more) {
    if (!more) return;
    more.classList.add('is-open');
    var btn = more.querySelector('.nav-more-toggle');
    if (btn) btn.setAttribute('aria-expanded', 'true');
  }

  document.addEventListener('click', function (e) {
    var toggle = e.target.closest('.nav-more-toggle');
    var openMore = document.querySelector('.nav-more.is-open');

    if (toggle) {
      e.preventDefault();
      var more = toggle.closest('.nav-more');
      var willOpen = !more.classList.contains('is-open');
      if (openMore && openMore !== more) closeMenu(openMore);
      if (willOpen) openMenu(more); else closeMenu(more);
      return;
    }

    // click outside any open menu → close it
    if (openMore && !e.target.closest('.nav-more-menu')) {
      closeMenu(openMore);
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var openMore = document.querySelector('.nav-more.is-open');
    if (!openMore) return;
    closeMenu(openMore);
    var btn = openMore.querySelector('.nav-more-toggle');
    if (btn) btn.focus();
  });
})();
