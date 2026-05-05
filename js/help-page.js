/**
 * help-page.js — Phase 19
 *
 * Three jobs on /help.php:
 *
 *  1. Scroll-spy via IntersectionObserver — keep .tab-btn.active in sync
 *     with the section the user is currently reading. Without this the
 *     hardcoded "active" class on the first tab never moved as the user
 *     scrolled.
 *  2. Live text-filter — the input in the hero filters <li> instructions
 *     by substring; cards with zero visible <li>s hide; sections with
 *     zero visible cards hide. Empty-state status is announced via
 *     role="status" + aria-live.
 *  3. Re-centre the active tab in the bottom-dock horizontal scroll lane
 *     whenever scroll-spy moves the active class. Done by emitting
 *     `helpactivetabchange` — mobile-tabs-scroll.js listens.
 */
(function () {
  'use strict';

  function debounce(fn, delay) {
    var t;
    return function () {
      var ctx = this;
      var args = arguments;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(ctx, args); }, delay);
    };
  }

  function normalize(s) {
    return (s || '').toString().toLowerCase().replace(/ё/g, 'е').trim();
  }

  // ----- Scroll-spy --------------------------------------------------------
  function initScrollSpy() {
    var sections = Array.prototype.slice.call(
      document.querySelectorAll('.account-container > section[id]')
    );
    if (sections.length === 0) return;

    var dock = document.querySelector('.help-tabs-dock');
    if (!dock) return;

    var tabsByHash = new Map();
    dock.querySelectorAll('.tab-btn').forEach(function (tab) {
      tabsByHash.set(tab.getAttribute('href'), tab);
    });
    if (tabsByHash.size === 0) return;

    if (typeof IntersectionObserver !== 'function') return;

    function setActive(targetId) {
      var hash = '#' + targetId;
      var nextTab = tabsByHash.get(hash);
      if (!nextTab || nextTab.classList.contains('active')) return;
      dock.querySelectorAll('.tab-btn.active').forEach(function (tab) {
        tab.classList.remove('active');
      });
      nextTab.classList.add('active');
      try {
        document.dispatchEvent(new CustomEvent('helpactivetabchange', {
          detail: { sectionId: targetId, tab: nextTab }
        }));
      } catch (e) {
        // Older browsers without CustomEvent constructor — silently skip.
      }
    }

    var observer = new IntersectionObserver(function (entries) {
      // From entries currently intersecting, pick the one closest to the
      // top of the spy band (rootMargin's top edge sits at 30% of viewport).
      var visible = entries.filter(function (e) { return e.isIntersecting; });
      if (visible.length === 0) return;
      visible.sort(function (a, b) {
        return a.boundingClientRect.top - b.boundingClientRect.top;
      });
      setActive(visible[0].target.id);
    }, {
      rootMargin: '-30% 0px -60% 0px',
      threshold: 0
    });

    sections.forEach(function (s) { observer.observe(s); });
  }

  // ----- Live filter -------------------------------------------------------
  function initFilter() {
    var input = document.getElementById('helpFilter');
    if (!input) return;

    var clearBtn = document.getElementById('helpFilterClear');
    var statusEl = document.getElementById('helpFilterStatus');
    var statusQ = statusEl ? statusEl.querySelector('.js-filter-q') : null;

    var sections = Array.prototype.slice.call(
      document.querySelectorAll('body.help-page .account-container > section[id]')
    );
    var cards = Array.prototype.slice.call(
      document.querySelectorAll('body.help-page .account-section .admin-form-container')
    );
    var listItems = Array.prototype.slice.call(
      document.querySelectorAll('body.help-page .account-section li')
    );
    if (listItems.length === 0) return;

    // Pre-compute normalized card titles + section titles so a query that
    // matches "Бренд" inside an <h3> highlights the whole card.
    var liNormCache = listItems.map(function (li) {
      return normalize(li.textContent);
    });
    var cardNormCache = cards.map(function (c) {
      var h3 = c.querySelector('h3');
      return h3 ? normalize(h3.textContent) : '';
    });
    var sectionNormCache = sections.map(function (s) {
      var h2 = s.querySelector('h2');
      return h2 ? normalize(h2.textContent) : '';
    });

    function reset() {
      document.body.classList.remove('is-filtering');
      listItems.forEach(function (li) { li.hidden = false; });
      cards.forEach(function (c) { c.hidden = false; });
      sections.forEach(function (s) { s.hidden = false; });
      if (statusEl) statusEl.hidden = true;
      if (clearBtn) clearBtn.hidden = true;
    }

    function apply(qRaw) {
      var q = normalize(qRaw);
      if (!q) { reset(); return; }

      document.body.classList.add('is-filtering');
      if (clearBtn) clearBtn.hidden = false;

      var anyMatch = false;

      // Pass 1: <li> items — direct substring match.
      listItems.forEach(function (li, i) {
        var match = liNormCache[i].indexOf(q) !== -1;
        li.hidden = !match;
        if (match) anyMatch = true;
      });

      // Pass 2: cards — show if any visible <li> OR card heading matches.
      cards.forEach(function (card, i) {
        var hasVisibleLi = card.querySelector('li:not([hidden])') !== null;
        var headingMatch = cardNormCache[i] && cardNormCache[i].indexOf(q) !== -1;
        if (headingMatch && !hasVisibleLi) {
          // Heading matched but all <li>s were filtered out — show all <li>s
          // back so user actually sees content of the matched card.
          card.querySelectorAll('li').forEach(function (li) { li.hidden = false; });
          hasVisibleLi = true;
        }
        var visible = hasVisibleLi || headingMatch;
        card.hidden = !visible;
        if (visible) anyMatch = true;
      });

      // Pass 3: sections — show if any visible card OR section heading matches.
      sections.forEach(function (sec, i) {
        var hasVisibleCard = sec.querySelector('.admin-form-container:not([hidden])') !== null;
        var headingMatch = sectionNormCache[i] && sectionNormCache[i].indexOf(q) !== -1;
        if (headingMatch && !hasVisibleCard) {
          sec.querySelectorAll('.admin-form-container').forEach(function (c) {
            c.hidden = false;
            c.querySelectorAll('li').forEach(function (li) { li.hidden = false; });
          });
          hasVisibleCard = true;
        }
        sec.hidden = !(hasVisibleCard || headingMatch);
        if (!sec.hidden) anyMatch = true;
      });

      if (statusEl) {
        statusEl.hidden = anyMatch;
        if (statusQ) statusQ.textContent = qRaw.trim();
      }
    }

    var debounced = debounce(function () { apply(input.value); }, 120);
    input.addEventListener('input', debounced);

    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        input.value = '';
        reset();
        input.focus();
      });
    }

    // Esc clears the filter when the input has focus.
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && input.value !== '') {
        e.preventDefault();
        input.value = '';
        reset();
      }
    });
  }

  function init() {
    initScrollSpy();
    initFilter();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
