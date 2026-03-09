(function () {
  const countInput = document.getElementById('tableCount');
  const applyButton = document.getElementById('applyCount');
  const printButton = document.getElementById('printQrBtn');

  function getCount() {
    const raw = parseInt(countInput?.value || '10', 10) || 10;
    return Math.max(1, Math.min(50, raw));
  }

  applyButton?.addEventListener('click', function () {
    const count = getCount();
    window.location.href = '/qr-print.php?count=' + count;
  });

  printButton?.addEventListener('click', function () {
    window.print();
  });
})();
