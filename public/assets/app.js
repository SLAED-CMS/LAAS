(function () {
  function initDevtoolsCollapse(root) {
    var scope = root || document;
    if (!window.bootstrap || !window.bootstrap.Collapse) {
      return;
    }
    var nodes = scope.querySelectorAll('[data-devtools-id]');
    if (!nodes.length) {
      return;
    }
    nodes.forEach(function (el) {
      if (el.dataset.devtoolsBound === '1') {
        return;
      }
      el.dataset.devtoolsBound = '1';
      var id = el.getAttribute('data-devtools-id');
      var key = 'laas_devtools_' + id;
      var stored = localStorage.getItem(key);
      if (stored === '1') {
        el.classList.add('show');
      } else {
        el.classList.remove('show');
      }
      var instance = window.bootstrap.Collapse.getOrCreateInstance(el, { toggle: false });
      if (stored === '1') {
        instance.show();
      } else {
        instance.hide();
      }
      el.addEventListener('shown.bs.collapse', function () {
        localStorage.setItem(key, '1');
      });
      el.addEventListener('hidden.bs.collapse', function () {
        localStorage.setItem(key, '0');
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initDevtoolsCollapse(document);
  });

  document.body.addEventListener('htmx:afterSwap', function (e) {
    initDevtoolsCollapse(e.target);
  });
})();
