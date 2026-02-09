(function () {
  'use strict';

  function initAdminTabs() {
    var tabButtons = document.querySelectorAll('.admin-tabs .admin-tab-btn');
    var panes = document.querySelectorAll('.admin-tab-pane');
    if (!tabButtons.length || !panes.length) {
      return;
    }

    var storageKey = 'adminMenuTab';

    function activate(tabId, persist) {
      tabButtons.forEach(function (button) {
        button.classList.toggle('active', button.getAttribute('data-tab') === tabId);
      });

      panes.forEach(function (pane) {
        pane.classList.toggle('active', pane.id === tabId);
      });

      if (persist) {
        try {
          localStorage.setItem(storageKey, tabId);
        } catch (error) {
          // no-op
        }
      }
    }

    var defaultTab = null;
    tabButtons.forEach(function (button) {
      if (button.classList.contains('active') && !defaultTab) {
        defaultTab = button.getAttribute('data-tab');
      }

      button.addEventListener('click', function () {
        activate(button.getAttribute('data-tab'), true);
      });
    });

    var initialTab = defaultTab;
    try {
      initialTab = localStorage.getItem(storageKey) || defaultTab;
    } catch (error) {
      initialTab = defaultTab;
    }

    if (initialTab) {
      activate(initialTab, false);
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    initAdminTabs();
  });
})();
