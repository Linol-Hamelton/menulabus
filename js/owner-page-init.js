(function () {
  'use strict';

  function parseOwnerData() {
    var dataNode = document.getElementById('owner-page-data');
    if (!dataNode) {
      return;
    }

    try {
      var payload = JSON.parse(dataNode.value || '{}');
      window.chartData = Array.isArray(payload.chartData) ? payload.chartData : [];
      window.currentPeriod = payload.currentPeriod || 'day';
      window.currentReport = payload.currentReport || 'sales';
      window.rawReportData = Array.isArray(payload.rawReportData) ? payload.rawReportData : [];
    } catch (error) {
      console.error('Failed to parse owner page data:', error);
      window.chartData = [];
      window.currentPeriod = 'day';
      window.currentReport = 'sales';
      window.rawReportData = [];
    }
  }

  function initAdminTabs() {
    var tabButtons = document.querySelectorAll('.admin-tabs .admin-tab-btn');
    var panes = document.querySelectorAll('.admin-tab-pane');
    if (!tabButtons.length || !panes.length) {
      return;
    }

    var storageKey = 'ownerAdminTab';

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

  function initRoleSaveButtons() {
    var buttons = document.querySelectorAll('.save-role-btn[data-user-id]');
    if (!buttons.length) {
      return;
    }

    function findSelect(userId, clickedButton) {
      var sameRow = clickedButton.closest('tr, .mobile-table-row, .mobile-table-item');
      if (sameRow) {
        var localSelect = sameRow.querySelector('.role-select[data-user-id="' + userId + '"]');
        if (localSelect) {
          return localSelect;
        }
      }

      var all = document.querySelectorAll('.role-select[data-user-id="' + userId + '"]');
      return all.length ? all[0] : null;
    }

    function setButtonState(button, loading, label) {
      button.disabled = loading;
      button.classList.toggle('loading', loading);
      if (label) {
        button.textContent = label;
      }
    }

    buttons.forEach(function (button) {
      button.addEventListener('click', async function () {
        var userId = button.getAttribute('data-user-id');
        var select = findSelect(userId, button);
        if (!select) {
          alert('Не найден селект роли для пользователя ' + userId);
          return;
        }

        var role = select.value;
        var originalLabel = button.textContent;
        setButtonState(button, true, 'Сохранение...');

        try {
          var response = await fetch('/update_user_role.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
              user_id: Number(userId),
              role: role
            }),
            credentials: 'same-origin'
          });

          var data = null;
          try {
            data = await response.json();
          } catch (error) {
            data = null;
          }

          if (!response.ok || !data || data.success !== true) {
            var message = (data && (data.error || data.message)) || ('HTTP ' + response.status);
            throw new Error(message);
          }

          setButtonState(button, false, 'Сохранено');
          setTimeout(function () {
            setButtonState(button, false, originalLabel);
          }, 1000);
        } catch (error) {
          console.error('Role update failed:', error);
          setButtonState(button, false, originalLabel);
          alert('Ошибка при сохранении роли: ' + error.message);
        }
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    parseOwnerData();
    initAdminTabs();
    initRoleSaveButtons();
  });
})();
