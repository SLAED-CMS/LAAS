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

  function laasTransliterateCyrillic(value) {
    var map = {
      '\u0430': 'a',  '\u0431': 'b',   '\u0432': 'v',   '\u0433': 'g',  '\u0434': 'd',
      '\u0435': 'e',  '\u0451': 'e',   '\u0436': 'zh',  '\u0437': 'z',  '\u0438': 'i',
      '\u0439': 'y',  '\u043a': 'k',   '\u043b': 'l',   '\u043c': 'm',  '\u043d': 'n',
      '\u043e': 'o',  '\u043f': 'p',   '\u0440': 'r',   '\u0441': 's',  '\u0442': 't',
      '\u0443': 'u',  '\u0444': 'f',   '\u0445': 'h',   '\u0446': 'ts', '\u0447': 'ch',
      '\u0448': 'sh', '\u0449': 'shch','\u044a': '',   '\u044b': 'y',  '\u044c': '',
      '\u044d': 'e',  '\u044e': 'yu',  '\u044f': 'ya',  '\u0456': 'i',  '\u0457': 'yi',
      '\u0454': 'ye', '\u0491': 'g'
    };

    var out = '';
    for (var i = 0; i < value.length; i++) {
      var ch = value[i];
      var lower = ch.toLowerCase();
      if (Object.prototype.hasOwnProperty.call(map, lower)) {
        out += map[lower];
      } else {
        out += ch;
      }
    }

    return out;
  }

  function laasSlugify(value) {
    return laasTransliterateCyrillic(value)
      .toLowerCase()
      .normalize('NFKD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .replace(/-{2,}/g, '-');
  }

  function laasInitSlugForm(root) {
    var scope = root || document;
    var form = scope.querySelector('[data-slug-form="1"]');
    if (!form) {
      return;
    }
    var titleInput = form.querySelector('#page-title') || form.querySelector('input[name="title"]');
    var slugInput = form.querySelector('#page-slug') || form.querySelector('input[name="slug"]');
    var previewLink = form.querySelector('#page-preview');
    if (!titleInput || !slugInput) {
      return;
    }

    var mode = form.getAttribute('data-mode') || 'edit';
    if (!slugInput.dataset.userEdited) {
      slugInput.dataset.userEdited = slugInput.value.trim() === '' ? '0' : '1';
    }

    function isValidSlug(value) {
      return /^[a-z0-9-]+$/.test(value);
    }

    function updatePreview() {
      if (!previewLink) {
        return;
      }
      var slug = slugInput.value.trim();
      if (slug !== '' && isValidSlug(slug)) {
        previewLink.classList.remove('disabled');
        previewLink.setAttribute('aria-disabled', 'false');
        previewLink.removeAttribute('tabindex');
        previewLink.href = '/' + slug;
        return;
      }
      previewLink.classList.add('disabled');
      previewLink.setAttribute('aria-disabled', 'true');
      previewLink.setAttribute('tabindex', '-1');
      previewLink.href = '#';
    }

    slugInput.addEventListener('input', function () {
      slugInput.dataset.userEdited = slugInput.value.trim() === '' ? '0' : '1';
      slugInput.value = laasSlugify(slugInput.value);
      updatePreview();
    });

    titleInput.addEventListener('input', function () {
      if (mode !== 'create') {
        return;
      }
      if (slugInput.dataset.userEdited === '1') {
        return;
      }
      if (slugInput.value.trim() !== '') {
        return;
      }
      slugInput.value = laasSlugify(titleInput.value);
      updatePreview();
    });

    updatePreview();
  }

  document.addEventListener('DOMContentLoaded', function () {
    laasInitSlugForm(document);
  });

  document.body.addEventListener('htmx:afterSwap', function (e) {
    laasInitSlugForm(e.target);
  });

  window.laasTransliterateCyrillic = laasTransliterateCyrillic;
  window.laasSlugify = laasSlugify;
  window.laasInitSlugForm = laasInitSlugForm;

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
    var alerts = document.getElementById('page-messages');
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
