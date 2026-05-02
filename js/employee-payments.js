(function () {
  'use strict';

  function getCsrfToken() {
    return (
      (document.body && document.body.getAttribute('data-csrf-token')) ||
      (document.getElementById('payLinkModal') && document.getElementById('payLinkModal').getAttribute('data-csrf-token')) ||
      ((document.querySelector('input[name="csrf_token"]') || {}).value || '')
    );
  }

  function getActiveTab() {
    var active = document.querySelector('.tab-btn.active');
    return active ? active.getAttribute('data-tab') : '';
  }

  async function refreshOrdersPreserveTab() {
    var scrollY = window.scrollY;
    var activeTab = getActiveTab();

    if (typeof refreshEmployeeOrders === 'function') {
      await refreshEmployeeOrders();
    }

    if (activeTab) {
      var tabBtn = document.querySelector('.tab-btn[data-tab="' + activeTab + '"]');
      var panel = document.getElementById(activeTab);
      if (tabBtn) {
        tabBtn.classList.add('active');
      }
      if (panel) {
        panel.classList.add('active');
      }
    }

    setTimeout(function () {
      window.scrollTo(0, scrollY);
    }, 80);
  }

  function notify(message, type) {
    if (typeof showNotification === 'function') {
      showNotification(message, type || 'info');
      return;
    }

    window.alert(message);
  }

  function initPayModal() {
    var modal = document.getElementById('payLinkModal');
    if (!modal) {
      return null;
    }

    var spinner = document.getElementById('payLinkSpinner');
    var content = document.getElementById('payLinkContent');
    var errorEl = document.getElementById('payLinkError');
    var qrImg = document.getElementById('payLinkQr');
    var urlInput = document.getElementById('payLinkUrl');
    var copyBtn = document.getElementById('payLinkCopy');
    var copyMsg = document.getElementById('payLinkCopyMsg');
    var closeBtn = document.getElementById('payLinkClose');

    function open() {
      modal.classList.add('active');
      spinner.hidden = false;
      content.hidden = true;
      errorEl.hidden = true;
      errorEl.textContent = '';
      copyMsg.hidden = true;
      if (window.FocusTrap) {
        window.FocusTrap.activate(modal, { onEscape: close });
      }
    }

    function close() {
      modal.classList.remove('active');
      if (window.FocusTrap) {
        window.FocusTrap.deactivate(modal);
      }
    }

    function showError(message) {
      spinner.hidden = true;
      content.hidden = true;
      errorEl.textContent = message;
      errorEl.hidden = false;
    }

    function showLink(url) {
      spinner.hidden = true;
      content.hidden = false;
      errorEl.hidden = true;
      urlInput.value = url;
      qrImg.src = '/qr.php?url=' + encodeURIComponent(url);
    }

    closeBtn.addEventListener('click', close);
    modal.addEventListener('click', function (event) {
      if (event.target === modal) {
        close();
      }
    });

    copyBtn.addEventListener('click', function () {
      var url = urlInput.value;
      if (!url) {
        return;
      }

      function showCopied() {
        copyMsg.hidden = false;
        setTimeout(function () {
          copyMsg.hidden = true;
        }, 2000);
      }

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(showCopied);
        return;
      }

      urlInput.select();
      document.execCommand('copy');
      showCopied();
    });

    return {
      open: open,
      showError: showError,
      showLink: showLink
    };
  }

  async function handlePaymentLink(button, modalApi) {
    var orderId = parseInt(button.getAttribute('data-order-id'), 10);
    if (!orderId || !modalApi) {
      notify('Ссылка на оплату сейчас недоступна', 'error');
      return;
    }

    modalApi.open();

    try {
      var response = await fetch('/generate-payment-link.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': getCsrfToken()
        },
        body: JSON.stringify({ order_id: orderId })
      });
      var data = await response.json();

      if (data.success && data.paymentUrl) {
        modalApi.showLink(data.paymentUrl);
        return;
      }

      modalApi.showError(data.error || 'Не удалось создать ссылку');
    } catch (error) {
      modalApi.showError('Ошибка сети. Попробуйте ещё раз.');
    }
  }

  async function handleCashConfirm(button) {
    var orderId = parseInt(button.getAttribute('data-order-id'), 10);
    if (!orderId) {
      return;
    }

    if (!window.confirm('Подтвердить, что заказ #' + orderId + ' оплачен наличными?')) {
      return;
    }

    var originalHtml = button.innerHTML;
    var idempotencyKey = button.dataset.idempotencyKey || ('cash-confirm-' + orderId + '-' + Date.now());
    button.dataset.idempotencyKey = idempotencyKey;
    button.disabled = true;
    button.textContent = 'Подтверждаем…';

    try {
      var response = await fetch('/api/checkout/cash-payment.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': getCsrfToken(),
          'Idempotency-Key': idempotencyKey
        },
        body: JSON.stringify({ order_id: orderId })
      });
      var data = await response.json();

      if (!response.ok || !data.success) {
        throw new Error(data.error || 'Не удалось подтвердить оплату');
      }

      await refreshOrdersPreserveTab();
      notify(data.already_paid ? 'Оплата уже была подтверждена' : 'Наличная оплата подтверждена', 'success');
    } catch (error) {
      button.disabled = false;
      button.innerHTML = originalHtml;
      notify(error.message || 'Не удалось подтвердить оплату', 'error');
    }
  }

  function init() {
    if (!document.body || !document.body.classList.contains('employee-page')) {
      return;
    }

    var modalApi = initPayModal();

    document.addEventListener('click', function (event) {
      var button = event.target.closest('.pay-link-btn');
      if (!button) {
        return;
      }

      var action = button.getAttribute('data-payment-action') || 'generate-link';
      event.preventDefault();

      if (action === 'confirm-cash') {
        handleCashConfirm(button);
        return;
      }

      handlePaymentLink(button, modalApi);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
