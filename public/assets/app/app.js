(function () {
  function initProgressBars(root) {
    var scope = root || document;
    var bars = scope.querySelectorAll('[data-progress-pct]');
    if (!bars.length) {
      return;
    }
    bars.forEach(function (bar) {
      var raw = bar.getAttribute('data-progress-pct');
      var pct = parseFloat(raw);
      if (isNaN(pct)) {
        return;
      }
      pct = Math.max(0, Math.min(100, pct));
      bar.style.width = pct + '%';
    });
  }

  function initAll(root) {
    initProgressBars(root);
  }

  document.addEventListener('DOMContentLoaded', function () {
    initAll(document);
  });

  document.body.addEventListener('htmx:afterSwap', function (e) {
    initAll(e.target);
  });
})();
