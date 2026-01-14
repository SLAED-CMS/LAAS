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

  function parseTriggerHeader(xhr) {
    if (!xhr || !xhr.getResponseHeader) {
      return null;
    }
    var raw = xhr.getResponseHeader('HX-Trigger');
    if (!raw) {
      return null;
    }
    try {
      return JSON.parse(raw);
    } catch (err) {
      return null;
    }
  }

  function resolveToastMessage(payload, keyField) {
    if (!payload) {
      return '';
    }
    if (payload.message) {
      return String(payload.message);
    }
    if (payload[keyField]) {
      return String(payload[keyField]);
    }
    return '';
  }

  function showToast(kind, message) {
    if (!message) {
      return;
    }
    if (!window.bootstrap || !window.bootstrap.Toast) {
      return;
    }
    var container = document.getElementById('laas-toast-container');
    if (!container) {
      return;
    }

    var toast = document.createElement('div');
    toast.className = 'toast align-items-center text-bg-' + (kind === 'error' ? 'danger' : 'success') + ' border-0';
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');

    var body = document.createElement('div');
    body.className = 'toast-body';
    body.textContent = message;

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close btn-close-white me-2 m-auto';
    close.setAttribute('data-bs-dismiss', 'toast');
    close.setAttribute('aria-label', 'Close');

    var flex = document.createElement('div');
    flex.className = 'd-flex';
    flex.appendChild(body);
    flex.appendChild(close);
    toast.appendChild(flex);

    container.appendChild(toast);
    var instance = new window.bootstrap.Toast(toast, { delay: 3500 });
    toast.addEventListener('hidden.bs.toast', function () {
      toast.remove();
    });
    instance.show();
  }

  function handleHtmxTriggers(event) {
    var trigger = parseTriggerHeader(event.detail && event.detail.xhr ? event.detail.xhr : null);
    if (!trigger) {
      return false;
    }
    var success = trigger['laas:success'];
    var error = trigger['laas:error'];
    if (success) {
      showToast('success', resolveToastMessage(success, 'message_key'));
    }
    if (error) {
      showToast('error', resolveToastMessage(error, 'error_key'));
    }
    return !!(success || error);
  }

  document.body.addEventListener('htmx:afterRequest', function (e) {
    handleHtmxTriggers(e);
  });
})();
