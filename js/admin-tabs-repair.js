(function () {
  'use strict';

  function getStorageKey() {
    return document.body.classList.contains('owner-page') ? 'ownerAdminTab' : 'adminMenuTab';
  }

  function initAdminTabs() {
    var buttons = Array.prototype.slice.call(document.querySelectorAll('.admin-tabs .admin-tab-btn'));
    var panes = Array.prototype.slice.call(document.querySelectorAll('.admin-tab-pane'));
    if (!buttons.length || !panes.length) {
      return;
    }

    var validTabs = buttons.map(function (button) {
      return button.getAttribute('data-tab');
    }).filter(Boolean);

    var key = getStorageKey();

    function activate(tabId, persist) {
      if (validTabs.indexOf(tabId) === -1) {
        return;
      }

      buttons.forEach(function (button) {
        button.classList.toggle('active', button.getAttribute('data-tab') === tabId);
      });

      panes.forEach(function (pane) {
        pane.classList.toggle('active', pane.id === tabId);
      });

      if (persist) {
        try {
          localStorage.setItem(key, tabId);
        } catch (error) {
          // no-op
        }
      }
    }

    var activeButton = buttons.find(function (button) {
      return button.classList.contains('active');
    });
    var defaultTab = activeButton ? activeButton.getAttribute('data-tab') : validTabs[0];
    var initialTab = defaultTab;

    try {
      var storedTab = localStorage.getItem(key);
      if (storedTab && validTabs.indexOf(storedTab) !== -1) {
        initialTab = storedTab;
      }
    } catch (error) {
      // no-op
    }

    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        activate(button.getAttribute('data-tab'), true);
      });
    });

    if (initialTab) {
      activate(initialTab, false);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdminTabs);
  } else {
    initAdminTabs();
  }
})();
