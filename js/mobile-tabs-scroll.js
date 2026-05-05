/**
 * mobile-tabs-scroll.js — Phase 18
 *
 * On mobile (≤ 768 px) .menu-tabs becomes a horizontal-scroll lane
 * (mobile-polish.css). When a page loads with an active tab that's
 * off-screen to the right (e.g. on /owner.php?tab=billing — 6 tabs,
 * "Подписка" is the 6th), the user wouldn't see which tab is current.
 *
 * On DOMContentLoaded — for every .menu-tabs, find its active .tab-btn
 * and scroll it into view horizontally so the user can see "you are
 * here". On click, browser's scroll-snap takes care of the rest.
 */
(function () {
  'use strict';

  var isMobile = function () {
    return !!(window.matchMedia && window.matchMedia('(max-width: 768px)').matches);
  };

  // helpactivetabchange — fired by /js/help-page.js when scroll-spy moves
  // .tab-btn.active in the bottom-dock. Re-centre the new active tab in
  // the horizontal-scroll lane on mobile so users always see "you are here".
  document.addEventListener('helpactivetabchange', function (event) {
    if (!isMobile()) return;
    var tab = event && event.detail && event.detail.tab;
    if (!tab) return;
    var container = tab.closest('.menu-tabs');
    if (container) scrollActiveIntoView(container);
  });

  if (!isMobile()) {
    // Only the page-load auto-centre below is mobile-only — the event
    // listener above is registered on every viewport but no-ops on desktop.
    return;
  }

  function scrollActiveIntoView(scrollContainer) {
    if (!scrollContainer) return;
    var active = scrollContainer.querySelector('.tab-btn.active');
    if (!active) return;
    try {
      // Prefer horizontal centring; some Safari versions ignore options
      // and fall back to default behaviour, which is fine.
      active.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
    } catch (e) {
      // Older browsers don't accept ScrollIntoViewOptions — manual fallback.
      var cRect = scrollContainer.getBoundingClientRect();
      var aRect = active.getBoundingClientRect();
      var delta = (aRect.left + aRect.width / 2) - (cRect.left + cRect.width / 2);
      scrollContainer.scrollBy({ left: delta, behavior: 'smooth' });
    }
  }

  function init() {
    document.querySelectorAll('.menu-tabs').forEach(function (tabs) {
      // Defer one frame so layout is ready (mask-image / overflow applied).
      requestAnimationFrame(function () { scrollActiveIntoView(tabs); });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
