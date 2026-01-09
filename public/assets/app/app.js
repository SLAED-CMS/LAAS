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

  function initDevtoolsToolbar(root) {
    var scope = root || document;
    var container = scope.querySelector('#devtools-root');
    if (!container && scope !== document) {
      container = document.getElementById('devtools-root');
    }
    if (!container || container.dataset.devtoolsToolbarBound === '1') {
      return;
    }
    container.dataset.devtoolsToolbarBound = '1';

    var copyBtn = container.querySelector('[data-devtools-action="copy"]');
    if (copyBtn) {
      copyBtn.addEventListener('click', function () {
        var dump = container.querySelector('#devtools-terminal-body');
        if (!dump || !navigator.clipboard) {
          return;
        }
        navigator.clipboard.writeText(dump.textContent || '');
      });
    }

    var expandBtn = container.querySelector('[data-devtools-action="expand"]');
    if (expandBtn && window.bootstrap && window.bootstrap.Collapse) {
      expandBtn.addEventListener('click', function () {
        var targets = container.querySelectorAll('.devtools-detail');
        if (!targets.length) {
          return;
        }
        var shouldShow = false;
        targets.forEach(function (el) {
          if (!el.classList.contains('show')) {
            shouldShow = true;
          }
        });
        targets.forEach(function (el) {
          var inst = window.bootstrap.Collapse.getOrCreateInstance(el, { toggle: false });
          if (shouldShow) {
            inst.show();
          } else {
            inst.hide();
          }
        });
      });
    }
  }

  function initDevtoolsErrors() {
    if (window.__laasDevtoolsErrorsBound) {
      return;
    }
    var root = document.getElementById('devtools-root');
    if (!root) {
      return;
    }
    window.__laasDevtoolsErrorsBound = true;

    var queue = [];
    var lastSent = 0;
    var maxQueue = 20;

    function sanitizeUrl(url) {
      try {
        var parsed = new URL(url);
        return parsed.protocol + '//' + parsed.host + parsed.pathname;
      } catch (e) {
        return url;
      }
    }

    function sendError(event) {
      var now = Date.now();
      if (now - lastSent < 1000) {
        if (queue.length < maxQueue) {
          queue.push(event);
        }
        return;
      }

      lastSent = now;

      var csrfToken = document.querySelector('meta[name="csrf-token"]');
      var headers = {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      };
      if (csrfToken) {
        headers['X-CSRF-Token'] = csrfToken.getAttribute('content');
      }

      fetch('/__devtools/js-errors/collect', {
        method: 'POST',
        headers: headers,
        credentials: 'same-origin',
        body: JSON.stringify(event)
      }).catch(function () {
        // Silent fail
      });

      if (queue.length > 0) {
        var nextEvent = queue.shift();
        setTimeout(function () {
          sendError(nextEvent);
        }, 1000);
      }
    }

    window.addEventListener('error', function (e) {
      var event = {
        type: 'error',
        message: e.message || 'Unknown error',
        source: e.filename || '',
        line: e.lineno || 0,
        column: e.colno || 0,
        stack: e.error && e.error.stack ? e.error.stack : '',
        url: sanitizeUrl(window.location.href),
        userAgent: navigator.userAgent || '',
        happened_at: Date.now()
      };
      sendError(event);
    });

    window.addEventListener('unhandledrejection', function (e) {
      var reason = e.reason;
      var message = 'Unhandled Promise Rejection';
      var stack = '';

      if (reason instanceof Error) {
        message = reason.message || message;
        stack = reason.stack || '';
      } else if (typeof reason === 'string') {
        message = reason;
      }

      var event = {
        type: 'unhandledrejection',
        message: message,
        source: '',
        line: 0,
        column: 0,
        stack: stack,
        url: sanitizeUrl(window.location.href),
        userAgent: navigator.userAgent || '',
        happened_at: Date.now()
      };
      sendError(event);
    });
  }

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
    initDevtoolsCollapse(root);
    initDevtoolsToolbar(root);
    initProgressBars(root);
  }

  document.addEventListener('DOMContentLoaded', function () {
    initAll(document);
    initDevtoolsErrors();
  });

  document.body.addEventListener('htmx:afterSwap', function (e) {
    initAll(e.target);
  });
})();
