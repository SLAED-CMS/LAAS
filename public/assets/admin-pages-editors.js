(function () {
  function normalizeFormat(value) {
    var raw = (value || '').toString().trim().toLowerCase();
    return raw === 'markdown' ? 'markdown' : 'html';
  }

  function getFormatInput(form) {
    return form.querySelector('[data-content-format="1"]') || form.querySelector('input[name="content_format"]');
  }

  function getEditorChoice(form) {
    var checked = form.querySelector('[data-editor-choice="1"]:checked');
    if (checked) {
      return normalizeFormat(checked.value);
    }
    var input = getFormatInput(form);
    return normalizeFormat(input ? input.value : 'html');
  }

  function setFormatValue(form, format) {
    var input = getFormatInput(form);
    if (input) {
      input.value = format;
    }
    var choices = form.querySelectorAll('[data-editor-choice="1"]');
    if (!choices.length) {
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

  function applyBlocksFormat(form, format) {
    if (format !== 'markdown') {
      return;
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
      if (block.data.format !== 'markdown') {
        block.data.format = 'markdown';
        changed = true;
      }
    });
    if (changed) {
      blocksField.value = JSON.stringify(parsed, null, 2);
    }
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
    var mode = getEditorChoice(form);
    setFormatValue(form, mode);

    var toastEditor = null;
    var tinyInit = false;

    function ensureToastUiEditor() {
      if (toastEditor || !markdownHost) {
        return toastEditor;
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
      var id = ensureTextareaId(textarea);
      if (window.tinymce.get(id)) {
        tinyInit = true;
        return;
      }
      window.tinymce.init({
        selector: '#' + id,
        height: 380,
        menubar: false,
        branding: false,
        statusbar: true,
        toolbar: 'undo redo | bold italic | bullist numlist | link'
      });
      tinyInit = true;
    }

    function setMode(nextMode) {
      var normalized = normalizeFormat(nextMode);
      setFormatValue(form, normalized);
      if (normalized === 'markdown') {
        if (window.tinymce && textarea.id) {
          var inst = window.tinymce.get(textarea.id);
          if (inst) {
            inst.remove();
            tinyInit = false;
          }
        }
        var editor = ensureToastUiEditor();
        if (editor) {
          editor.setMarkdown(textarea.value || '');
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
        ensureTinyMceEditor();
      }
    }

    var choices = form.querySelectorAll('[data-editor-choice="1"]');
    choices.forEach(function (choice) {
      choice.addEventListener('change', function () {
        setMode(getEditorChoice(form));
        applyBlocksFormat(form, getEditorChoice(form));
      });
    });

    form.addEventListener('submit', function () {
      var format = getEditorChoice(form);
      if (format === 'markdown') {
        var editor = ensureToastUiEditor();
        if (editor) {
          textarea.value = editor.getMarkdown();
        }
      } else if (window.tinymce) {
        window.tinymce.triggerSave();
      }
      applyBlocksFormat(form, format);
    });

    setMode(mode);

    if (formatInput && formatInput.value === '') {
      formatInput.value = mode;
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
    var format = getEditorChoice(form);
    var textarea = form.querySelector('textarea[name="content"]');
    if (format === 'markdown' && textarea) {
      var editor = form._pagesToastEditor;
      if (editor && editor.getMarkdown) {
        textarea.value = editor.getMarkdown();
      }
    } else if (window.tinymce) {
      window.tinymce.triggerSave();
    }
    applyBlocksFormat(form, format);
  }, true);
})();
