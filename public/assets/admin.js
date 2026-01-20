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

  function copyText(text) {
    if (!text) {
      return;
    }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text);
      return;
    }
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'absolute';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    try {
      document.execCommand('copy');
    } catch (err) {
    }
    document.body.removeChild(textarea);
  }

  function initCopyButtons(root) {
    var buttons = root.querySelectorAll('[data-copy-target], [data-copy-value]');
    if (!buttons.length) {
      return;
    }
    buttons.forEach(function (btn) {
      if (btn.dataset.copyReady === '1') {
        return;
      }
      btn.dataset.copyReady = '1';
      btn.addEventListener('click', function () {
        var target = btn.getAttribute('data-copy-target');
        var value = btn.getAttribute('data-copy-value');
        if (target) {
          var node = document.querySelector(target);
          if (node) {
            value = node.textContent || '';
          }
        }
        copyText(value || '');
      });
    });
  }

  function setDetailsLoading(button, loading) {
    if (!button) {
      return;
    }
    var spinner = button.querySelector('[data-details-spinner]');
    if (spinner && spinner.classList) {
      if (loading) {
        spinner.classList.remove('invisible');
      } else {
        spinner.classList.add('invisible');
      }
    }
    if (button.classList) {
      if (loading) {
        button.classList.add('disabled');
      } else {
        button.classList.remove('disabled');
      }
    }
    button.setAttribute('aria-disabled', loading ? 'true' : 'false');
    button.setAttribute('aria-busy', loading ? 'true' : 'false');
    button.dataset.detailsLoading = loading ? '1' : '0';
  }

  function getDetailsRow(button) {
    if (!button) {
      return null;
    }
    var selector = button.getAttribute('data-details-row') || '';
    if (!selector) {
      return null;
    }
    return document.querySelector(selector);
  }

  function getDetailsTarget(button) {
    if (!button) {
      return null;
    }
    var selector = button.getAttribute('data-details-target') || '';
    if (!selector) {
      return null;
    }
    return document.querySelector(selector);
  }

  function findDetailsButtonForRow(row) {
    if (!row || !row.id) {
      return null;
    }
    return document.querySelector('[data-details-row="#' + row.id + '"]');
  }

  function closeDetailsRow(row) {
    if (!row) {
      return;
    }
    row.classList.remove('is-open');
    var button = findDetailsButtonForRow(row);
    setDetailsLoading(button, false);
  }

  function closeAllDetailsRows(exceptRow) {
    var openRows = document.querySelectorAll('.module-details-row.is-open');
    if (!openRows.length) {
      return;
    }
    openRows.forEach(function (row) {
      if (exceptRow && row === exceptRow) {
        return;
      }
      closeDetailsRow(row);
    });
  }

  function highlightModuleRow(moduleId) {
    if (!moduleId) {
      return;
    }
    var row = document.querySelector('[data-module-row="1"][data-module-id="' + moduleId + '"]') || document.getElementById('module-' + moduleId);
    if (!row || !row.classList) {
      return;
    }
    row.classList.add('module-row-highlight');
    setTimeout(function () {
      row.classList.remove('module-row-highlight');
    }, 800);
  }

  function scrollToModuleRow(moduleId) {
    var row = document.querySelector('[data-module-row="1"][data-module-id="' + moduleId + '"]') || document.getElementById('module-' + moduleId);
    if (!row || !row.scrollIntoView) {
      return;
    }
    row.scrollIntoView({ behavior: 'smooth', block: 'start' });
    setTimeout(function () {
      window.scrollBy(0, -80);
    }, 150);
  }

  function requestDetails(button, target) {
    if (!button || !target || !window.htmx || !window.htmx.ajax) {
      return;
    }
    var url = button.getAttribute('hx-get') || button.getAttribute('data-hx-get') || button.getAttribute('href');
    if (!url) {
      return;
    }
    setDetailsLoading(button, true);
    window.htmx.ajax('GET', url, { target: target, swap: 'innerHTML' });
  }

  var moduleDetailsReady = false;

  function initModuleDetailsHandlers() {
    if (moduleDetailsReady) {
      return;
    }
    moduleDetailsReady = true;

    document.addEventListener('click', function (event) {
      var target = event.target;
      if (!target || !target.closest) {
        return;
      }

      var closeLink = target.closest('[data-details-close="1"]');
      if (closeLink) {
        if (typeof window.htmx === 'undefined') {
          return;
        }
        event.preventDefault();
        event.stopPropagation();
        var closeId = closeLink.getAttribute('data-module-id') || '';
        var closeRow = document.getElementById('module-details-row-' + closeId);
        closeDetailsRow(closeRow);
        var focusButton = document.querySelector('[data-details-btn="1"][data-module-id="' + closeId + '"]');
        if (focusButton && focusButton.focus) {
          focusButton.focus();
        }
        return;
      }

      var button = target.closest('[data-details-btn="1"]');
      if (!button) {
        return;
      }
      if (typeof window.htmx === 'undefined') {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      if (button.dataset.detailsLoading === '1') {
        return;
      }

      var moduleId = button.getAttribute('data-module-id') || '';
      var row = getDetailsRow(button);
      var detailsTarget = getDetailsTarget(button);
      if (!row || !detailsTarget) {
        return;
      }

      if (row.classList.contains('is-open')) {
        closeDetailsRow(row);
        return;
      }

      closeAllDetailsRows(row);
      row.classList.add('is-open');
      highlightModuleRow(moduleId);
      scrollToModuleRow(moduleId);

      if (detailsTarget.innerHTML.trim() === '') {
        requestDetails(button, detailsTarget);
      } else {
        setDetailsLoading(button, false);
      }
    });
  }

  function initTooltips(root) {
    if (!window.bootstrap || !window.bootstrap.Tooltip) {
      return;
    }
    var scope = root || document;
    var nodes = scope.querySelectorAll('[data-bs-toggle="tooltip"]');
    if (!nodes.length) {
      return;
    }
    nodes.forEach(function (node) {
      if (node.dataset.tooltipReady === '1') {
        return;
      }
      node.dataset.tooltipReady = '1';
      new window.bootstrap.Tooltip(node, { container: 'body' });
    });
  }

  function initModulesNavSearch(root) {
    var scope = root || document;
    var input = scope.querySelector('#modules-nav-q');
    if (!input) {
      return;
    }
    if (input.dataset.modulesNavReady === '1') {
      return;
    }
    input.dataset.modulesNavReady = '1';

    var menu = input.closest('.nav-modules-menu') || document;
    var items = menu.querySelectorAll('[data-modules-nav-item="1"]');
    var sections = menu.querySelectorAll('[data-modules-nav-section]');
    var emptyRow = menu.querySelector('[data-modules-nav-empty="1"]');

    function normalizeQuery(value) {
      return (value || '').toString().trim().toLowerCase();
    }

    function applyFilter(query) {
      var q = normalizeQuery(query);
      var matches = 0;
      var sectionCounts = {};

      items.forEach(function (item) {
        var haystack = (item.getAttribute('data-search') || '').toLowerCase();
        var visible = q === '' || haystack.indexOf(q) !== -1;
        item.classList.toggle('d-none', !visible);
        if (visible) {
          matches++;
          var sectionKey = item.getAttribute('data-section') || '';
          if (sectionKey) {
            sectionCounts[sectionKey] = (sectionCounts[sectionKey] || 0) + 1;
          }
        }
      });

      sections.forEach(function (section) {
        var key = section.getAttribute('data-modules-nav-section') || '';
        if (q === '') {
          section.classList.remove('d-none');
          return;
        }
        if (key === 'pinned') {
          section.classList.toggle('d-none', (sectionCounts[key] || 0) === 0);
          return;
        }
        section.classList.toggle('d-none', (sectionCounts[key] || 0) === 0);
      });

      if (emptyRow) {
        emptyRow.classList.toggle('d-none', q === '' || matches > 0);
      }
    }

    input.addEventListener('input', function () {
      applyFilter(input.value);
    });

    input.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        input.value = '';
        applyFilter('');
        input.blur();
      }
    });

    applyFilter(input.value);
  }

  document.addEventListener('DOMContentLoaded', function () {
    scheduleAutoHide(document);
    initCopyButtons(document);
    initModuleDetailsHandlers();
    initTooltips(document);
    initModulesNavSearch(document);
    var htmxBadge = document.getElementById('htmx-missing');
    if (htmxBadge && document.body && document.body.getAttribute('data-app-debug') === '1') {
      if (typeof window.htmx === 'undefined') {
        htmxBadge.classList.remove('d-none');
        if (window.console && window.console.warn) {
          var expected = htmxBadge.getAttribute('data-htmx-expected') || '/assets/vendor/htmx/1.9.12/htmx.min.js';
          window.console.warn('[admin] HTMX missing: hx-* interactions disabled. Expected ' + expected);
        }
      }
    }
  });

  document.body.addEventListener('htmx:afterSwap', function (e) {
    scheduleAutoHide(e.target);
    initCopyButtons(e.target);
    initTooltips(e.target);
    initModulesNavSearch(e.target);
    if (e.target && e.target.getAttribute && e.target.getAttribute('data-details-body') === '1') {
      var detailsRow = e.target.closest('.module-details-row');
      if (detailsRow) {
        if (e.target.innerHTML.trim() === '') {
          detailsRow.classList.remove('is-open');
        } else {
          detailsRow.classList.add('is-open');
        }
      }
      var detailsButton = document.querySelector('[data-details-target="#' + e.target.id + '"]');
      setDetailsLoading(detailsButton, false);
    }
  });

  document.body.addEventListener('htmx:beforeRequest', function (e) {
    var target = e.detail && e.detail.target ? e.detail.target : null;
    if (!target || !target.getAttribute || target.getAttribute('data-details-body') !== '1') {
      return;
    }
    var detailsButton = document.querySelector('[data-details-target="#' + target.id + '"]');
    setDetailsLoading(detailsButton, true);
  });

  document.body.addEventListener('htmx:afterRequest', function (e) {
    var target = e.detail && e.detail.target ? e.detail.target : null;
    if (!target || !target.getAttribute || target.getAttribute('data-details-body') !== '1') {
      return;
    }
    var detailsButton = document.querySelector('[data-details-target="#' + target.id + '"]');
    setDetailsLoading(detailsButton, false);
  });

  document.addEventListener('laas:toast', function (event) {
    handleLaasToastPayload(event.detail);
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

  function laasInitBlocksPreview(root) {
    var scope = root || document;
    var button = scope.querySelector('[data-preview-blocks="1"]');
    if (!button || button.dataset.previewReady === '1') {
      return;
    }
    var form = button.closest('form');
    if (!form) {
      return;
    }
    button.dataset.previewReady = '1';

    button.addEventListener('click', function () {
      var action = button.getAttribute('data-preview-action') || '/admin/pages/preview-blocks';
      var target = button.getAttribute('data-preview-target') || '_blank';
      var previewForm = document.createElement('form');
      previewForm.method = 'post';
      previewForm.action = action;
      previewForm.target = target;
      var data = new FormData(form);
      data.forEach(function (value, key) {
        if (value instanceof File) {
          return;
        }
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = String(value);
        previewForm.appendChild(input);
      });
      document.body.appendChild(previewForm);
      previewForm.submit();
      document.body.removeChild(previewForm);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    laasInitBlocksPreview(document);
  });

  document.body.addEventListener('htmx:afterSwap', function (e) {
    laasInitBlocksPreview(e.target);
  });

  window.laasInitBlocksPreview = laasInitBlocksPreview;

  function laasInitAiAssist(root) {
    var scope = root || document;
    var form = scope.querySelector('[data-page-form="1"]');
    var panel = scope.querySelector('[data-ai-assist="1"]');
    if (!form || !panel) {
      return;
    }

    var fieldLabel = panel.querySelector('#ai-field-label');
    var fieldInput = panel.querySelector('#ai_field');
    var valueInput = panel.querySelector('#ai_value');
    var pageIdInput = panel.querySelector('#ai_page_id');
    var urlInput = panel.querySelector('#ai_url');
    if (!fieldLabel || !fieldInput || !valueInput) {
      return;
    }

    var allowlist = {
      'title': 'Title',
      'slug': 'Slug',
      'content': 'Content'
    };

    function getPageId() {
      var input = form.querySelector('input[name="id"]');
      if (!input) {
        return '';
      }
      return input.value || '';
    }

    function getSlugValue() {
      var input = form.querySelector('input[name="slug"]');
      if (!input) {
        return '';
      }
      return input.value || '';
    }

    function updateUrlFromSlug() {
      if (!urlInput) {
        return;
      }
      var slug = getSlugValue().trim();
      urlInput.value = slug ? '/' + slug : '';
    }

    function updateContext(target) {
      if (!target || !target.name || !allowlist[target.name]) {
        return;
      }
      fieldInput.value = target.name;
      valueInput.value = target.value || '';
      fieldLabel.textContent = allowlist[target.name] || target.name;
      if (pageIdInput) {
        pageIdInput.value = getPageId();
      }
      updateUrlFromSlug();
    }

    if (panel.dataset.aiReady === '1') {
      return;
    }
    panel.dataset.aiReady = '1';

    form.addEventListener('focusin', function (event) {
      updateContext(event.target);
    });

    form.addEventListener('input', function (event) {
      if (document.activeElement === event.target) {
        updateContext(event.target);
      }
    });

    updateUrlFromSlug();
  }

  document.addEventListener('DOMContentLoaded', function () {
    laasInitAiAssist(document);
  });

  document.body.addEventListener('htmx:afterSwap', function (e) {
    laasInitAiAssist(e.target);
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
    var alerts = document.getElementById('page-messages');
    if (!alerts) {
      return;
    }

    alerts.innerHTML = '<div class="alert alert-danger" role="alert" data-auto-hide="1" data-hide-ms="4000"></div>';
    alerts.firstChild.textContent = text;
    scheduleAutoHide(alerts);
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

  var toastContainer = document.getElementById('laas-toasts');
  var toastTemplate = document.getElementById('laas-toast-template');
  var handledToastRequests = {};
  var lastToastByKey = {};
  var toastDedupeWindowMs = 2000;
  var toastQueueLimit = 5;
  var toastEventsLimit = 3;
  var debugEnabled = document.body && document.body.getAttribute('data-app-debug') === '1';

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
    if (Array.isArray(payload)) {
      var handled = false;
      payload.slice(0, toastEventsLimit).forEach(function (entry) {
        handled = handleLaasToastPayload(entry) || handled;
      });
      return handled;
    }

    var message = resolveToastMessage(payload, 'message');
    if (!message) {
      return false;
    }

    var requestId = (payload.request_id || '').toString();
    if (requestId !== '' && handledToastRequests[requestId]) {
      return true;
    }

    var ttl = resolveToastTtl(payload.ttl_ms);
    var tone = resolveToastTone(payload.type);
    var title = resolveToastTitle(payload, tone);
    var code = resolveToastMessage(payload, 'code');
    var dedupeKey = resolveToastMessage(payload, 'dedupe_key') || code || message;

    var sanitized = sanitizeToastMessage(message);
    if (sanitized.redacted && debugEnabled) {
      console.debug('Toast message redacted', { code: code });
    }

    if (shouldSkipToast(dedupeKey)) {
      return true;
    }

    showToast(tone, sanitized.message, title, requestId, ttl, dedupeKey);

    if (requestId !== '') {
      handledToastRequests[requestId] = true;
    }
    return true;
  }

  function sanitizeToastMessage(message) {
    var value = (message || '').toString();
    value = value.replace(/[<>]/g, '');
    var redacted = /password|secret|dsn|bearer\\s/i.test(value);
    if (redacted) {
      return { message: 'redacted', redacted: true };
    }
    return { message: value, redacted: false };
  }

  function resolveToastTitle(payload, tone) {
    if (!toastContainer) {
      return '';
    }
    var title = resolveToastMessage(payload, 'title');
    if (title) {
      return title.replace(/[<>]/g, '');
    }
    var fallback = toastContainer.getAttribute('data-title-' + tone);
    return fallback ? String(fallback) : '';
  }

  function resolveToastTtl(ttlValue) {
    if (ttlValue === undefined || ttlValue === null) {
      return 5000;
    }
    var parsed = parseInt(ttlValue, 10);
    return !isNaN(parsed) && parsed > 0 ? parsed : 5000;
  }

  function shouldSkipToast(dedupeKey) {
    if (!dedupeKey) {
      return false;
    }
    var now = Date.now();
    var lastSeen = lastToastByKey[dedupeKey];
    if (lastSeen && now - lastSeen < toastDedupeWindowMs) {
      return true;
    }
    lastToastByKey[dedupeKey] = now;
    return false;
  }

  function showToast(kind, message, title, requestId, ttl, dedupeKey) {
    if (!message) {
      return;
    }
    if (!window.bootstrap || !window.bootstrap.Toast) {
      return;
    }
    if (!toastContainer || !toastTemplate) {
      return;
    }

    var tone = resolveToastTone(kind);
    var fragment = toastTemplate.content ? toastTemplate.content.cloneNode(true) : null;
    if (!fragment) {
      return;
    }

    var toast = fragment.querySelector('.toast');
    var header = fragment.querySelector('.toast-header');
    var titleEl = fragment.querySelector('[data-toast-title]');
    var messageEl = fragment.querySelector('[data-toast-message]');
    var requestIdEl = fragment.querySelector('[data-toast-request-id]');
    var copyButton = fragment.querySelector('[data-action=\"copy-request-id\"]');

    if (!toast || !header || !messageEl || !titleEl || !requestIdEl || !copyButton) {
      return;
    }

    toast.classList.add('text-bg-' + tone);
    header.classList.add('text-bg-' + tone);
    titleEl.textContent = title || '';
    messageEl.textContent = message;
    requestIdEl.textContent = requestId || '';
    copyButton.textContent = toastContainer.getAttribute('data-copy-label') || 'Copy request id';
    copyButton.setAttribute('data-request-id', requestId || '');

    while (toastContainer.children.length >= toastQueueLimit) {
      toastContainer.removeChild(toastContainer.firstElementChild);
    }

    toastContainer.appendChild(fragment);
    var instance = new window.bootstrap.Toast(toast, { delay: ttl || 5000 });
    toast.addEventListener('hidden.bs.toast', function () {
      toast.remove();
    });
    instance.show();
  }

  function handleHtmxTriggers(event) {
    var xhr = event.detail && event.detail.xhr ? event.detail.xhr : null;
    var trigger = parseTriggerHeader(xhr);
    if (trigger) {
      handleLaasToastPayload(trigger);
    }

    if (!xhr) {
      return false;
    }
    var contentType = xhr.getResponseHeader ? (xhr.getResponseHeader('Content-Type') || '') : '';
    var body = xhr.responseText || '';
    if (contentType.indexOf('application/json') !== -1 || body.trim().charAt(0) === '{') {
      var json = parseJsonBody(body);
      if (json && json.meta && Array.isArray(json.meta.events)) {
        json.meta.events.slice(0, toastEventsLimit).forEach(function (evt) {
          handleLaasToastPayload(evt);
        });
      }
    }
    return true;
  }

  function parseJsonBody(raw) {
    if (!raw) {
      return null;
    }
    try {
      return JSON.parse(raw);
    } catch (err) {
      return null;
    }
  }

  document.body.addEventListener('htmx:responseError', function (e) {
    var target = e.detail && e.detail.target ? e.detail.target : null;
    if (target && target.getAttribute && target.getAttribute('data-details-body') === '1') {
      var detailsButton = document.querySelector('[data-details-target="#' + target.id + '"]');
      setDetailsLoading(detailsButton, false);
    }
    if (handleHtmxTriggers(e)) {
      return;
    }
    showAlert(extractErrorText(e));
  });

  document.body.addEventListener('htmx:afterOnLoad', function (e) {
    handleHtmxTriggers(e);
  });

  if (toastContainer) {
    toastContainer.addEventListener('click', function (event) {
      var target = event.target;
      if (!target || !target.getAttribute) {
        return;
      }
      if (target.getAttribute('data-action') !== 'copy-request-id') {
        return;
      }
      var requestId = target.getAttribute('data-request-id') || '';
      if (!requestId) {
        return;
      }
      copyRequestId(requestId);
    });
  }

  function copyRequestId(value) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(value).then(function () {
        showCopiedToast();
      }).catch(function () {
        fallbackCopy(value);
        showCopiedToast();
      });
      return;
    }

    fallbackCopy(value);
    showCopiedToast();
  }

  function fallbackCopy(value) {
    var input = document.createElement('textarea');
    input.value = value;
    input.setAttribute('readonly', 'readonly');
    input.style.position = 'absolute';
    input.style.left = '-9999px';
    document.body.appendChild(input);
    input.select();
    try {
      document.execCommand('copy');
    } catch (err) {
    }
    document.body.removeChild(input);
  }

  function showCopiedToast() {
    if (!toastContainer) {
      return;
    }
    var label = toastContainer.getAttribute('data-copied-label') || 'Copied.';
    showToast('info', label, resolveToastTitle({ title: '' }, 'info'), '', 2000, 'ui.toast.copied');
  }
})();
