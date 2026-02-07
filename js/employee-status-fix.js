(function () {
  'use strict';

  function wrapBusyGuard(name) {
    var original = window[name];
    if (typeof original !== 'function') {
      return;
    }

    window[name] = async function (orderId, currentStatus, button) {
      if (button && button.dataset && button.dataset.reqBusy === '1') {
        return;
      }

      if (button && button.dataset) {
        button.dataset.reqBusy = '1';
      }

      try {
        return await original(orderId, currentStatus, button);
      } finally {
        if (button && button.dataset) {
          // Keep a small cooldown to swallow duplicated click handlers.
          setTimeout(function () {
            button.dataset.reqBusy = '0';
          }, 1200);
        }
      }
    };
  }

  function patchEmployeeApi() {
    if (!window.EmployeeAPI || typeof window.EmployeeAPI.update !== 'function') {
      return;
    }

    window.EmployeeAPI.update = async function (orderId, action) {
      var csrfToken = document.querySelector('input[name="csrf_token"]')?.value;
      if (!csrfToken) {
        throw new Error('CSRF token not found');
      }

      var response = await fetch('update_order_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          csrf_token: csrfToken,
          order_id: orderId,
          action: action
        })
      });

      var payload = null;
      try {
        payload = await response.json();
      } catch (error) {
        payload = null;
      }

      if (!response.ok) {
        var serverError = payload && (payload.error || payload.message);
        throw new Error(serverError || ('HTTP error! status: ' + response.status));
      }

      if (!payload || payload.success !== true) {
        throw new Error((payload && (payload.error || payload.message)) || 'Unknown error');
      }

      return payload;
    };
  }

  function init() {
    patchEmployeeApi();
    wrapBusyGuard('handleEmployeeStatusUpdate');
    wrapBusyGuard('handleEmployeeReject');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
