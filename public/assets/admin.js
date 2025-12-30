(function () {
  function scheduleAutoHide(root) {
    var nodes = root.querySelectorAll('[data-auto-hide="1"]');
    if (!nodes.length) {
      return;
    }
    nodes.forEach(function (node) {
      if (node.dataset.autoHideReady === '1') {
        return;
      }
      node.dataset.autoHideReady = '1';
      var delay = parseInt(node.getAttribute('data-hide-ms') || '3000', 10);
      node.classList.add('fade', 'show');
      setTimeout(function () {
        node.classList.add('d-none');
      }, isNaN(delay) ? 3000 : delay);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    scheduleAutoHide(document);
  });

  document.body.addEventListener('htmx:afterSwap', function (e) {
    scheduleAutoHide(e.target);
  });

  function extractErrorText(e) {
    var text = '';
    if (e.detail && e.detail.xhr && e.detail.xhr.responseText) {
      try {
        var data = JSON.parse(e.detail.xhr.responseText);
        if (data && data.error) {
          text = String(data.error);
        }
      } catch (err) {
        text = String(e.detail.xhr.responseText);
      }
    }
    return text || 'error';
  }

  function showAlert(text) {
    var alerts = document.getElementById('admin-alerts');
    if (!alerts) {
      return;
    }

    alerts.innerHTML = '<div class="alert alert-danger" role="alert" data-auto-hide="1" data-hide-ms="4000"></div>';
    alerts.firstChild.textContent = text;
    scheduleAutoHide(alerts);
  }

  document.body.addEventListener('htmx:responseError', function (e) {
    showAlert(extractErrorText(e));
  });

  document.body.addEventListener('htmx:afterRequest', function (e) {
    if (!e.detail || !e.detail.xhr) {
      return;
    }
    var status = e.detail.xhr.status;
    if (status >= 400) {
      showAlert(extractErrorText(e));
    }
  });
})();
