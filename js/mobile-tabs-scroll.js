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

  if (window.matchMedia && !window.matchMedia('(max-width: 768px)').matches) {
    // Only run on mobile viewports — desktop tabs already wrap normally.
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
