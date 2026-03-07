(function () {
  'use strict';

  function countLabel(count) {
    var n = Number(count) || 0;
    var mod10 = n % 10;
    var mod100 = n % 100;

    if (mod10 === 1 && mod100 !== 11) return n + ' заказ';
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return n + ' заказа';
    return n + ' заказов';
  }

  function applyBoardFilter(board) {
    var search = board.querySelector('[data-employee-search]');
    var activeChip = board.querySelector('.employee-filter-chip.active');
    var empty = board.querySelector('[data-employee-empty]');
    var countNode = board.querySelector('[data-employee-visible-count]');
    var statusName = board.getAttribute('data-status-name') || '';
    var query = ((search && search.value) || '').trim().toLowerCase();
    var typeFilter = activeChip ? activeChip.getAttribute('data-filter-type') : 'all';
    var cards = Array.prototype.slice.call(board.querySelectorAll('.employee-order-card'));
    var visible = 0;

    cards.forEach(function (card) {
      var haystack = card.getAttribute('data-order-search') || '';
      var deliveryType = card.getAttribute('data-delivery-type') || 'unknown';
      var matchesQuery = !query || haystack.indexOf(query) !== -1;
      var matchesType = typeFilter === 'all' || deliveryType === typeFilter;
      var shouldShow = matchesQuery && matchesType;

      card.hidden = !shouldShow;
      if (shouldShow) visible += 1;
    });

    if (countNode) {
      countNode.textContent = countLabel(visible) + (statusName ? ' · ' + statusName : '');
    }

    if (empty) {
      empty.hidden = visible !== 0;
    }
  }

  function initBoard(board) {
    if (!board || board.dataset.employeeTriageBound === '1') {
      if (board) applyBoardFilter(board);
      return;
    }

    board.dataset.employeeTriageBound = '1';

    var search = board.querySelector('[data-employee-search]');
    if (search) {
      search.addEventListener('input', function () {
        applyBoardFilter(board);
      });
    }

    board.querySelectorAll('.employee-filter-chip').forEach(function (chip) {
      chip.addEventListener('click', function () {
        board.querySelectorAll('.employee-filter-chip').forEach(function (btn) {
          btn.classList.remove('active');
        });
        chip.classList.add('active');
        applyBoardFilter(board);
      });
    });

    applyBoardFilter(board);
  }

  function initBoards(root) {
    (root || document).querySelectorAll('[data-employee-board]').forEach(initBoard);
  }

  function observeBoardRefresh() {
    var observer = new MutationObserver(function (mutations) {
      for (var i = 0; i < mutations.length; i += 1) {
        var mutation = mutations[i];
        if (mutation.type !== 'childList') continue;

        mutation.addedNodes.forEach(function (node) {
          if (!(node instanceof HTMLElement)) return;
          if (node.matches && node.matches('.account-sections')) {
            initBoards(node);
          } else if (node.querySelector && node.querySelector('[data-employee-board]')) {
            initBoards(node);
          }
        });
      }
    });

    observer.observe(document.body, { childList: true, subtree: true });
  }

  function init() {
    if (!document.body || !document.body.classList.contains('employee-page')) {
      return;
    }

    initBoards(document);
    observeBoardRefresh();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
