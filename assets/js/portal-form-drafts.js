(function () {
  'use strict';

  var role = String(window.DCVS_PORTAL_ROLE || 'portal');
  var userId = String(window.DCVS_PORTAL_USER_ID || 'user');
  var prefix = role === 'staff'
    ? 'dcvsStaffDraft:' + userId + ':' + window.location.pathname + ':'
    : 'dcvsPortalDraft:' + role + ':' + userId + ':' + window.location.pathname + ':';
  var restoredForms = new WeakSet();
  var restoringForms = new WeakSet();
  var formKeys = new WeakMap();

  function isDraftIgnored(root) {
    return !!(root && root.hasAttribute('data-draft-ignore'));
  }

  function logStorageError(error) {
    console.error('Portal form draft storage failed:', error);
  }

  function storageKey(root) {
    if (formKeys.has(root)) return prefix + formKeys.get(root);

    var roots = Array.prototype.slice.call(document.querySelectorAll('form, [data-draft-form]'));
    var occurrence = roots.indexOf(root);
    var identity = root.getAttribute('data-draft-form') || root.id ||
      root.getAttribute('name') || root.getAttribute('action') || 'form';
    var key = identity + ':' + occurrence;
    formKeys.set(root, key);
    return prefix + key;
  }

  function fieldsFor(root) {
    var candidates = root.tagName && root.tagName.toLowerCase() === 'form'
      ? Array.prototype.slice.call(root.elements)
      : Array.prototype.slice.call(root.querySelectorAll('input, select, textarea'));
    return candidates.filter(function (field) {
      if (!field || !field.tagName) return false;
      var tag = field.tagName.toLowerCase();
      if (tag !== 'input' && tag !== 'select' && tag !== 'textarea') return false;
      if (field.hasAttribute('data-draft-state')) return true;
      if (field.disabled || field.type === 'password' || field.type === 'file' ||
          field.type === 'hidden' || field.type === 'submit' || field.type === 'button' ||
          field.type === 'reset' || field.type === 'image') return false;
      return true;
    });
  }

  function fieldIdentity(field, fields, index) {
    if (field.id) return 'id:' + field.id;
    var name = field.name || '';
    var type = field.type || field.tagName.toLowerCase();
    var sameFields = fields.filter(function (candidate) {
      return !candidate.id && (candidate.name || '') === name &&
        (candidate.type || candidate.tagName.toLowerCase()) === type;
    });
    return 'field:' + name + ':' + type + ':' + sameFields.indexOf(field) + ':' + index;
  }

  function dialogFor(root) {
    return root.closest('.modal, [role="dialog"], .dialog-overlay, .modal-backdrop, .ab-modal-backdrop, .drawer, [data-dialog]');
  }

  function dialogState(root) {
    var dialog = dialogFor(root);
    if (!dialog) return null;

    var style = window.getComputedStyle(dialog);
    var classes = ['show', 'open', 'active', 'visible'].filter(function (name) {
      return dialog.classList.contains(name);
    });
    var inlineDisplay = dialog.style.display;
    var isOpen = classes.length > 0 || dialog.hasAttribute('open') ||
      (inlineDisplay !== '' && inlineDisplay !== 'none') ||
      (style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0' &&
       dialog.matches('[role="dialog"]') && dialog.getClientRects().length > 0);

    if (!dialog.id) {
      var currentKey = storageKey(root).slice(prefix.length).replace(/[^a-zA-Z0-9_-]/g, '_');
      dialog.setAttribute('data-staff-draft-dialog', currentKey);
    }

    return {
      open: isOpen,
      id: dialog.id || '',
      key: dialog.getAttribute('data-staff-draft-dialog') || '',
      classes: classes,
      display: dialog.style.display || ''
    };
  }

  function saveForm(root) {
    if (isDraftIgnored(root)) return;
    if (root.dataset.draftSubmitted === 'true') {
      clearForm(root);
      return;
    }
    var key = storageKey(root);
    var existing = null;
    try {
      existing = JSON.parse(sessionStorage.getItem(key) || 'null');
    } catch (error) {
      logStorageError(error);
    }

    var fields = fieldsFor(root);
    var values = {};
    fields.forEach(function (field, index) {
      var identity = fieldIdentity(field, fields, index);
      if (field.type === 'checkbox' || field.type === 'radio') {
        values[identity] = { checked: field.checked, value: field.value };
      } else if (field.tagName.toLowerCase() === 'select' && field.multiple) {
        values[identity] = Array.prototype.filter.call(field.options, function (option) {
          return option.selected;
        }).map(function (option) { return option.value; });
      } else {
        values[identity] = field.value;
      }
    });

    var dialog = dialogState(root);
    if (!existing && !Object.keys(values).length && !(dialog && dialog.open)) return;

    try {
      sessionStorage.setItem(key, JSON.stringify({ fields: values, dialog: dialog }));
    } catch (error) {
      logStorageError(error);
    }
  }

  function restoreDialog(root, state) {
    if (!state || !state.open) return;
    var dialog = state.id ? document.getElementById(state.id) : null;
    if (!dialog && state.key) {
      dialog = Array.prototype.find.call(
        document.querySelectorAll('[data-staff-draft-dialog]'),
        function (candidate) {
          return candidate.getAttribute('data-staff-draft-dialog') === state.key;
        }
      );
    }
    if (!dialog) dialog = dialogFor(root);
    if (!dialog) return;

    (state.classes || []).forEach(function (name) { dialog.classList.add(name); });
    if (!(state.classes || []).length && state.display) dialog.style.display = state.display;
    if (dialog.matches('.modal, .modal-backdrop, .ab-modal-backdrop, .dialog-overlay, [role="dialog"]')) {
      document.body.style.overflow = 'hidden';
    }
  }

  function restoreForm(root) {
    if (isDraftIgnored(root)) {
      clearForm(root);
      return;
    }
    if (restoredForms.has(root)) return;
    restoredForms.add(root);

    var saved;
    try {
      saved = JSON.parse(sessionStorage.getItem(storageKey(root)) || 'null');
    } catch (error) {
      logStorageError(error);
      return;
    }
    if (!saved) return;

    var fields = fieldsFor(root);
    restoringForms.add(root);
    fields.forEach(function (field, index) {
      var identity = fieldIdentity(field, fields, index);
      if (!Object.prototype.hasOwnProperty.call(saved.fields || {}, identity)) return;
      var value = saved.fields[identity];
      if (field.type === 'checkbox' || field.type === 'radio') {
        field.checked = !!(value && value.checked);
      } else if (field.tagName.toLowerCase() === 'select' && field.multiple && Array.isArray(value)) {
        Array.prototype.forEach.call(field.options, function (option) {
          option.selected = value.indexOf(option.value) !== -1;
        });
      } else if (typeof value === 'string') {
        field.value = value;
      }
      field.dispatchEvent(new Event('change', { bubbles: true }));
    });
    restoringForms.delete(root);

    restoreDialog(root, saved.dialog);
    document.dispatchEvent(new CustomEvent('dcvs:draft-restored', {
      detail: { root: root, fields: saved.fields || {}, dialog: saved.dialog || null }
    }));
  }

  function processForms(root) {
    var forms = [];
    if (root && root.matches && root.matches('form, [data-draft-form]')) forms.push(root);
    if (root && root.querySelectorAll) {
      forms = forms.concat(Array.prototype.slice.call(root.querySelectorAll('form, [data-draft-form]')));
    }
    forms.forEach(restoreForm);
  }

  function clearForm(root) {
    try {
      sessionStorage.removeItem(storageKey(root));
    } catch (error) {
      logStorageError(error);
    }
  }

  function initialize() {
    processForms(document);

    document.addEventListener('input', function (event) {
      var root = event.target.closest('form, [data-draft-form]');
      if (root && !restoringForms.has(root)) saveForm(root);
    }, true);
    document.addEventListener('change', function (event) {
      var root = event.target.closest('form, [data-draft-form]');
      if (root && !restoringForms.has(root)) saveForm(root);
    }, true);
    document.addEventListener('reset', function (event) {
      clearForm(event.target);
    }, true);
    document.addEventListener('submit', function (event) {
      var form = event.target;
      window.setTimeout(function () {
        if (!event.defaultPrevented) clearForm(form);
      }, 0);
    }, true);

    var clickTimer;
    document.addEventListener('click', function () {
      window.clearTimeout(clickTimer);
      clickTimer = window.setTimeout(function () {
        Array.prototype.forEach.call(document.querySelectorAll('form, [data-draft-form]'), saveForm);
      }, 80);
    }, true);
    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') return;
      window.setTimeout(function () {
        Array.prototype.forEach.call(document.querySelectorAll('form, [data-draft-form]'), saveForm);
      }, 80);
    }, true);

    var observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        Array.prototype.forEach.call(mutation.addedNodes, function (node) {
          if (node.nodeType === 1) processForms(node);
        });
      });
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      window.setTimeout(initialize, 0);
    }, { once: true });
  } else {
    initialize();
  }

  window.DCVSClearDraft = function (root) {
    if (root) clearForm(root);
  };
})();
