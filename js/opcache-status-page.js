(function () {
  const resultDiv = document.getElementById('result');
  const configSection = document.getElementById('configSection');
  const actionButtons = document.querySelectorAll('[data-opcache-action]');

  function setProgressWidths() {
    document.querySelectorAll('.progress-fill[data-progress]').forEach((node) => {
      const raw = parseFloat(node.dataset.progress || '0');
      const value = Number.isFinite(raw) ? Math.max(0, Math.min(100, raw)) : 0;
      node.style.width = value + '%';
    });
  }

  function showResult(message, isSuccess) {
    resultDiv.className = 'result ' + (isSuccess ? 'success' : 'error');
    resultDiv.textContent = message;
    resultDiv.hidden = false;

    window.setTimeout(() => {
      resultDiv.hidden = true;
    }, 5000);
  }

  function withTemporaryDisable(button, fn) {
    button.disabled = true;
    const finalize = () => {
      window.setTimeout(() => {
        button.disabled = false;
      }, 2000);
    };
    Promise.resolve(fn()).finally(finalize);
  }

  function handleReset(button) {
    if (!window.confirm('Вы уверены? Это сбросит весь кэш OPcache.')) {
      return;
    }

    return fetch('?action=reset')
      .then((response) => response.json())
      .then((data) => {
        showResult(data.message, data.success);
        if (data.success) {
          window.setTimeout(() => window.location.reload(), 2000);
        }
      })
      .catch((error) => {
        showResult('Ошибка: ' + error.message, false);
      });
  }

  function handleRevalidate() {
    return fetch('?action=revalidate')
      .then((response) => response.json())
      .then((data) => {
        showResult(data.message, data.success);
      })
      .catch((error) => {
        showResult('Ошибка: ' + error.message, false);
      });
  }

  function handleAction(button) {
    const action = button.dataset.opcacheAction;

    switch (action) {
      case 'reset':
        return withTemporaryDisable(button, () => handleReset(button));
      case 'revalidate':
        return withTemporaryDisable(button, handleRevalidate);
      case 'reload':
        return withTemporaryDisable(button, () => window.location.reload());
      case 'toggle-config':
        return withTemporaryDisable(button, () => {
          configSection.hidden = !configSection.hidden;
        });
      default:
        return undefined;
    }
  }

  actionButtons.forEach((button) => {
    button.addEventListener('click', () => {
      handleAction(button);
    });
  });

  window.setInterval(() => {
    if (document.querySelector('.btn:disabled')) {
      return;
    }

    fetch('?action=get_stats')
      .then((response) => response.json())
      .catch((error) => console.error('Error updating stats:', error));
  }, 30000);

  setProgressWidths();
})();