(function () {
  function activateLazyCss() {
    var links = document.querySelectorAll('link[data-css-lazy]');
    if (!links.length) {
      return;
    }
    links.forEach(function (link) {
      if (link.rel !== 'stylesheet') {
        link.rel = 'stylesheet';
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', activateLazyCss);
  } else {
    activateLazyCss();
  }
})();
