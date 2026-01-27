(function () {
  function buildStorageKey(form) {
    if (!form) {
      return 'laas.pages.editor.default';
    }
    var raw = (form.dataset.editorUserId || '').toString().trim();
    if (!raw) {
      return 'laas.pages.editor.default';
    }
    if (!/^\d+$/.test(raw)) {
      return 'laas.pages.editor.default';
    }
    return 'laas.pages.editor.' + raw;
  }

  function normalizeFormat(value) {
    var raw = (value || '').toString().trim().toLowerCase();
    return raw === 'markdown' ? 'markdown' : 'html';
  }

  function getFormatInput(form) {
    return form.querySelector('[data-content-format="1"]') || form.querySelector('input[name="content_format"]');
  }

  function getEditorIdInput(form) {
    return form.querySelector('[data-editor-id-input="1"]') || form.querySelector('input[name="editor_id"]');
  }

  function getMediaPickerUrl(form) {
    if (!form) {
      return '/admin/media/picker';
    }
    var url = (form.dataset.mediaPickerUrl || '').toString().trim();
    return url ? url : '/admin/media/picker';
  }

  function getMediaPickerHost(form) {
    if (!form) {
      return null;
    }
    return form.querySelector('#media-picker-modal') || document.getElementById('media-picker-modal');
  }

  function readEditorCaps(form) {
    var caps = {};
    var choices = form.querySelectorAll('[data-editor-choice="1"]');
    choices.forEach(function (choice) {
      var id = (choice.dataset.editorId || '').toString().trim();
      if (!id) {
        return;
      }
      caps[id] = choice.dataset.editorAvailable === '1';
    });
    return caps;
  }

  function getHintEl(form) {
    return form.querySelector('[data-editor-unavailable-hint="1"]');
  }

  function getEditorChoiceInput(form, id) {
    if (!id) {
      return null;
    }
    return form.querySelector('[data-editor-choice="1"][data-editor-id="' + id + '"]');
  }

  function buildChoiceFromInput(input, caps) {
    if (!input) {
      return null;
    }
    var choiceId = (input.dataset.editorId || '').toString().trim();
    var choice = {
      id: choiceId,
      format: normalizeFormat(input.value),
      available: input.dataset.editorAvailable === '1'
    };
    if (choiceId && caps && Object.prototype.hasOwnProperty.call(caps, choiceId)) {
      choice.available = caps[choiceId] === true;
    }
    return choice;
  }

  function isProviderReady(choice) {
    if (!choice) {
      return false;
    }
    if (choice.id === 'toastui') {
      return Boolean(window.toastui && window.toastui.Editor);
    }
    if (choice.id === 'tinymce') {
      return Boolean(window.tinymce);
    }
    return true;
  }

  function setUnavailableHint(form, visible) {
    var hint = getHintEl(form);
    if (!hint) {
      return;
    }
    if (visible) {
      hint.classList.remove('d-none');
    } else {
      hint.classList.add('d-none');
    }
  }

  function readStoredEditorId(storageKey) {
    try {
      return window.localStorage.getItem(storageKey) || '';
    } catch (err) {
      return '';
    }
  }

  function storeEditorId(storageKey, id) {
    if (!id || !storageKey) {
      return;
    }
    try {
      window.localStorage.setItem(storageKey, id);
    } catch (err) {
      // ignore storage failures
    }
  }

  function resolveChoiceFromStorage(form, caps, storageKey) {
    var storedId = readStoredEditorId(storageKey);
    if (!storedId) {
      return null;
    }
    return buildChoiceFromInput(getEditorChoiceInput(form, storedId), caps);
  }

  function readEditorChoice(form, caps) {
    var checked = form.querySelector('[data-editor-choice="1"]:checked');
    if (checked) {
      var fromInput = buildChoiceFromInput(checked, caps);
      if (fromInput && fromInput.id) {
        return fromInput;
      }
    }
    var input = getFormatInput(form);
    var fallbackFormat = normalizeFormat(input ? input.value : 'html');
    var fallbackId = form.dataset.editorSelectedId || '';
    if (!fallbackId) {
      fallbackId = fallbackFormat === 'markdown' ? 'toastui' : 'tinymce';
    }
    var available = true;
    if (caps && Object.prototype.hasOwnProperty.call(caps, fallbackId)) {
      available = caps[fallbackId] === true;
    }
    return {
      id: fallbackId,
      format: fallbackFormat,
      available: available
    };
  }

  function setFormatValue(form, format, selectedId) {
    var input = getFormatInput(form);
    if (input) {
      input.value = format;
    }
    var editorInput = getEditorIdInput(form);
    if (editorInput) {
      editorInput.value = selectedId || '';
    }
    var choices = form.querySelectorAll('[data-editor-choice="1"]');
    if (!choices.length) {
      return;
    }
    if (selectedId === 'textarea') {
      choices.forEach(function (choice) {
        choice.checked = false;
      });
      return;
    }
    choices.forEach(function (choice) {
      choice.checked = normalizeFormat(choice.value) === format;
    });
  }

  function ensureTextareaId(textarea) {
    if (!textarea) {
      return '';
    }
    if (!textarea.id) {
      textarea.id = 'page-content';
    }
    return textarea.id;
  }

  function readTinyMceConfig(textarea) {
    if (!textarea) {
      return null;
    }
    var raw = textarea.getAttribute('data-tinymce-config') || '';
    if (!raw) {
      return null;
    }
    try {
      var parsed = JSON.parse(raw);
      if (parsed && typeof parsed === 'object') {
        return parsed;
      }
    } catch (err) {
      return null;
    }
    return null;
  }

  function normalizeBlocksJsonForFormat(form, format) {
    if (format !== 'markdown') {
      return normalizeBlocksJsonToHtml(form);
    }
    var blocksField = form.querySelector('textarea[name="blocks_json"]');
    if (!blocksField) {
      return;
    }
    var raw = (blocksField.value || '').trim();
    if (raw === '') {
      return;
    }
    var parsed;
    try {
      parsed = JSON.parse(raw);
    } catch (err) {
      return;
    }
    if (!Array.isArray(parsed)) {
      return;
    }
    var changed = false;
    parsed.forEach(function (block) {
      if (!block || block.type !== 'rich_text' || !block.data || typeof block.data !== 'object') {
        return;
      }
      var hasContentField = typeof block.data.html === 'string' || typeof block.data.text === 'string';
      if (!hasContentField) {
        return;
      }
      if (block.data.format !== 'markdown') {
        block.data.format = 'markdown';
        changed = true;
      }
    });
    if (changed) {
      blocksField.value = JSON.stringify(parsed, null, 2);
    }
  }

  function normalizeBlocksJsonToHtml(form) {
    var blocksField = form.querySelector('textarea[name="blocks_json"]');
    if (!blocksField) {
      return;
    }
    var raw = (blocksField.value || '').trim();
    if (raw === '') {
      return;
    }
    var parsed;
    try {
      parsed = JSON.parse(raw);
    } catch (err) {
      return;
    }
    if (!Array.isArray(parsed)) {
      return;
    }
    var changed = false;
    parsed.forEach(function (block) {
      if (!block || block.type !== 'rich_text' || !block.data || typeof block.data !== 'object') {
        return;
      }
      if (Object.prototype.hasOwnProperty.call(block.data, 'format')) {
        delete block.data.format;
        changed = true;
      }
    });
    if (changed) {
      blocksField.value = JSON.stringify(parsed, null, 2);
    }
  }

  function syncToastUiToTextarea(textarea, editor) {
    if (!editor || !textarea) {
      return;
    }
    if (typeof editor.getMarkdown === 'function') {
      textarea.value = editor.getMarkdown();
    }
  }

  function syncTinyMceToTextarea(textarea) {
    if (!textarea || !window.tinymce) {
      return;
    }
    var id = ensureTextareaId(textarea);
    var instance = window.tinymce.get(id);
    if (instance && typeof instance.getContent === 'function') {
      textarea.value = instance.getContent();
    } else {
      window.tinymce.triggerSave();
    }
  }

  var pendingMediaPick = null;

  function closeMediaPicker(form) {
    var host = getMediaPickerHost(form);
    if (host) {
      host.innerHTML = '';
    }
  }

  function openMediaPicker(form, callback) {
    var host = getMediaPickerHost(form);
    if (!host) {
      return;
    }
    pendingMediaPick = callback;
    var url = getMediaPickerUrl(form);
    if (window.htmx && typeof window.htmx.ajax === 'function') {
      window.htmx.ajax('GET', url, { target: host, swap: 'innerHTML' });
      return;
    }
    fetch(url, { credentials: 'same-origin' })
      .then(function (response) { return response.text(); })
      .then(function (html) { host.innerHTML = html; })
      .catch(function () { closeMediaPicker(form); });
  }

  function resolveEffectiveChoice(choice, caps) {
    var resolved = choice;
    if (!resolved) {
      return {
        choice: {
          id: 'textarea',
          format: 'html',
          available: true
        },
        showHint: false
      };
    }
    if (resolved.id !== 'textarea') {
      var ready = resolved.available && isProviderReady(resolved);
      if (!ready) {
        return {
          choice: {
            id: 'textarea',
            format: 'html',
            available: true
          },
          showHint: true
        };
      }
      return {
        choice: resolved,
        showHint: false
      };
    }
    var showHint = false;
    if (resolved.format === 'markdown') {
      showHint = caps.toastui === false || !window.toastui || !window.toastui.Editor;
    } else {
      showHint = caps.tinymce === false || !window.tinymce;
    }
    return {
      choice: {
        id: 'textarea',
        format: normalizeFormat(resolved.format),
        available: true
      },
      showHint: showHint
    };
  }

  function initEditorForForm(form) {
    if (form.dataset.pagesEditorsReady === '1') {
      return;
    }
    form.dataset.pagesEditorsReady = '1';

    var textarea = form.querySelector('textarea[name="content"]');
    if (!textarea) {
      return;
    }
    var markdownHost = form.querySelector('[data-markdown-editor="1"]');
    var formatInput = getFormatInput(form);
    var caps = readEditorCaps(form);
    var selectionSource = (form.dataset.editorSelectionSource || 'content').toString().trim();
    var storageKey = buildStorageKey(form);
    var choice = readEditorChoice(form, caps);
    if (selectionSource === 'default') {
      var storedChoice = resolveChoiceFromStorage(form, caps, storageKey);
      if (storedChoice && storedChoice.id) {
        choice = storedChoice;
      }
    }
    var resolvedChoice = resolveEffectiveChoice(choice, caps);
    var appliedChoice = resolvedChoice.choice;
    setFormatValue(form, appliedChoice.format, appliedChoice.id);

    var toastEditor = null;
    var tinyInit = false;

    function ensureToastUiEditor() {
      if (toastEditor || !markdownHost) {
        return toastEditor;
      }
      if (caps.toastui === false) {
        return null;
      }
      if (!window.toastui || !window.toastui.Editor) {
        return null;
      }
      toastEditor = new window.toastui.Editor({
        el: markdownHost,
        height: '380px',
        initialEditType: 'markdown',
        previewStyle: 'vertical',
        usageStatistics: false,
        initialValue: textarea.value || ''
      });
      form._pagesToastEditor = toastEditor;
      return toastEditor;
    }

    function ensureTinyMceEditor() {
      if (tinyInit || !window.tinymce) {
        return;
      }
      if (caps.tinymce === false) {
        return;
      }
      var id = ensureTextareaId(textarea);
      if (window.tinymce.get(id)) {
        tinyInit = true;
        return;
      }
      var baseConfig = {
        height: 420,
        menubar: false,
        branding: false,
        statusbar: true
      };
      var providerConfig = readTinyMceConfig(textarea) || {};
      var merged = Object.assign({}, baseConfig, providerConfig);
      merged.selector = '#' + id;
      merged.file_picker_callback = function (callback, value, meta) {
        if (!meta || meta.filetype !== 'image') {
          return;
        }
        openMediaPicker(form, function (url, detail) {
          var alt = (detail && detail.original_name) ? detail.original_name : '';
          callback(url, { alt: alt });
        });
      };
      window.tinymce.init(merged);
      tinyInit = true;
    }

    function setMode(nextChoice) {
      var resolved = typeof nextChoice === 'string' ? readEditorChoice(form, caps) : nextChoice;
      var effective = resolveEffectiveChoice(resolved, caps);
      var applied = effective.choice;
      var normalized = normalizeFormat(applied.format);
      setFormatValue(form, normalized, applied.id);
      setUnavailableHint(form, effective.showHint);
      if (normalized === 'markdown') {
        if (window.tinymce && textarea.id) {
          var inst = window.tinymce.get(textarea.id);
          if (inst) {
            inst.remove();
            tinyInit = false;
          }
        }
        var editor = applied.id === 'toastui' ? ensureToastUiEditor() : null;
        if (editor) {
          if ((textarea.value || '') !== '') {
            editor.setMarkdown(textarea.value || '');
          }
          markdownHost.classList.remove('d-none');
          textarea.classList.add('d-none');
        } else {
          if (markdownHost) {
            markdownHost.classList.add('d-none');
          }
          textarea.classList.remove('d-none');
        }
      } else {
        if (toastEditor) {
          textarea.value = toastEditor.getMarkdown();
        }
        if (markdownHost) {
          markdownHost.classList.add('d-none');
        }
        textarea.classList.remove('d-none');
        if (applied.id === 'tinymce') {
          ensureTinyMceEditor();
        }
      }
      return applied;
    }

    var choices = form.querySelectorAll('[data-editor-choice="1"]');
    choices.forEach(function (choice) {
      choice.addEventListener('change', function () {
        var nextChoice = readEditorChoice(form, caps);
        var applied = setMode(nextChoice);
        storeEditorId(storageKey, nextChoice.id);
        normalizeBlocksJsonForFormat(form, applied.format);
      });
    });

    form.addEventListener('submit', function () {
      var currentChoice = readEditorChoice(form, caps);
      var applied = resolveEffectiveChoice(currentChoice, caps).choice;
      var format = applied.format;
      setFormatValue(form, format, applied.id);
      if (format === 'markdown') {
        var editor = ensureToastUiEditor();
        if (editor && applied.id === 'toastui') {
          syncToastUiToTextarea(textarea, editor);
        }
      } else if (window.tinymce && applied.id === 'tinymce') {
        syncTinyMceToTextarea(textarea);
      }
      normalizeBlocksJsonForFormat(form, format);
    });

    setMode(choice);

    if (formatInput && formatInput.value === '') {
      formatInput.value = appliedChoice.format;
    }
  }

  function initAll(root) {
    var scope = root || document;
    var forms = scope.querySelectorAll('[data-page-form="1"]');
    if (!forms.length) {
      return;
    }
    forms.forEach(initEditorForForm);
  }

  document.addEventListener('DOMContentLoaded', function () {
    initAll(document);
  });

  document.body.addEventListener('htmx:afterSwap', function (e) {
    initAll(e.target);
  });

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!target || !target.closest) {
      return;
    }
    var button = target.closest('[data-preview-blocks="1"]');
    if (!button) {
      return;
    }
    var form = button.closest('form');
    if (!form) {
      return;
    }
    var caps = readEditorCaps(form);
    var choice = readEditorChoice(form, caps);
    var format = choice.format;
    var textarea = form.querySelector('textarea[name="content"]');
    if (format === 'markdown' && textarea) {
      var editor = form._pagesToastEditor;
      if (editor && choice.available && isProviderReady(choice)) {
        syncToastUiToTextarea(textarea, editor);
      }
    } else if (window.tinymce && choice.available && isProviderReady(choice)) {
      syncTinyMceToTextarea(textarea);
    }
    normalizeBlocksJsonForFormat(form, format);
  }, true);

  document.body.addEventListener('media:selected', function (event) {
    if (!pendingMediaPick) {
      return;
    }
    var detail = event.detail || {};
    var url = detail.original_url || '';
    if (!url) {
      return;
    }
    var callback = pendingMediaPick;
    pendingMediaPick = null;
    callback(url, detail);
    closeMediaPicker(null);
  });
})();
