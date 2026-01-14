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

  var isAdminTheme = document.body && document.body.classList.contains('laas-theme-admin');
  if (!isAdminTheme) {
  document.body.addEventListener('laas:toast', function (event) {
    handleLaasToastPayload(event.detail);
  });
  }

  function parseTriggerHeader(xhr) {
    if (!xhr || !xhr.getResponseHeader) {
      return null;
    }
    var raw = xhr.getResponseHeader('HX-Trigger');
    if (!raw) {
      raw = xhr.getResponseHeader('HX-Trigger-After-Settle');
    }
    if (!raw) {
      raw = xhr.getResponseHeader('HX-Trigger-After-Swap');
    }
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
    if (payload[keyField]) {
      return String(payload[keyField]);
    }
    return '';
  }

  var handledToastRequests = {};

  function resolveToastTone(kind) {
    var normalized = (kind || 'info').toLowerCase();
    if (normalized === 'error') {
      normalized = 'danger';
    }
    var allowed = ['success', 'info', 'warning', 'danger'];
    if (allowed.indexOf(normalized) === -1) {
      normalized = 'info';
    }
    return normalized;
  }

  function handleLaasToastPayload(payload) {
    if (!payload || typeof payload !== 'object') {
      return false;
    }
    if (payload['laas:toast']) {
      payload = payload['laas:toast'];
    }
    var message = resolveToastMessage(payload, 'message');
    if (!message) {
      return false;
    }
    var requestId = (payload.request_id || '').toString();
    if (requestId !== '' && handledToastRequests[requestId]) {
      return true;
    }
    var ttl = null;
    if (payload.ttl_ms !== undefined && payload.ttl_ms !== null) {
      var parsed = parseInt(payload.ttl_ms, 10);
      if (!isNaN(parsed) && parsed > 0) {
        ttl = parsed;
      }
    }
    showToast(resolveToastTone(payload.type), message, ttl);
    if (requestId !== '') {
      handledToastRequests[requestId] = true;
    }
    return true;
  }

  function showToast(kind, message, ttl) {
    if (!message) {
      return;
    }
    if (!window.bootstrap || !window.bootstrap.Toast) {
      return;
    }
    var container = document.getElementById('laas-toasts');
    if (!container) {
      return;
    }

    var tone = resolveToastTone(kind);
    var toast = document.createElement('div');
    toast.className = 'toast align-items-center text-bg-' + tone + ' border-0';
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
    var delay = 2500;
    if (typeof ttl === 'number' && !isNaN(ttl) && ttl > 0) {
      delay = ttl;
    }
    var instance = new window.bootstrap.Toast(toast, { delay: delay });
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
    return handleLaasToastPayload(trigger);
  }

  if (!isAdminTheme) {
    document.body.addEventListener('htmx:afterRequest', function (e) {
      handleHtmxTriggers(e);
    });
  }
})();
