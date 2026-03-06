// Text editor — contenteditable for text and textarea fields
import { queueSave } from './save-manager.js';
import { setEditing } from './overlay.js';

let activeField = null;
let originalContent = '';

export function init() {}

export function activate(field) {
  if (activeField) deactivate(activeField, false);

  activeField = field;
  const el = field.el;
  originalContent = field.type === 'textarea' ? el.innerHTML : el.textContent;

  setEditing(true);
  el.classList.remove('ope-hover');
  el.classList.add('ope-editing');
  el.setAttribute('contenteditable', 'plaintext-only');
  el.focus();

  // Place cursor at end (instead of selecting all, which can feel jarring)
  const sel = window.getSelection();
  sel.selectAllChildren(el);
  sel.collapseToEnd();

  el.addEventListener('blur', onBlur);
  el.addEventListener('keydown', onKeydown);
}

export function isActive() {
  return activeField !== null;
}

function onBlur() {
  if (activeField) deactivate(activeField, true);
}

function onKeydown(e) {
  if (e.key === 'Escape') {
    if (activeField) {
      if (activeField.type === 'textarea') {
        activeField.el.innerHTML = originalContent;
      } else {
        activeField.el.textContent = originalContent;
      }
      deactivate(activeField, false);
    }
    e.preventDefault();
  }
  if (e.key === 'Enter' && activeField && activeField.type === 'text') {
    e.preventDefault();
    deactivate(activeField, true);
  }
}

function deactivate(field, save) {
  const el = field.el;
  el.removeAttribute('contenteditable');
  el.classList.remove('ope-editing');
  el.removeEventListener('blur', onBlur);
  el.removeEventListener('keydown', onKeydown);
  setEditing(false);

  if (save) {
    const newContent = field.type === 'textarea'
      ? el.innerHTML.replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]+>/g, '')
      : el.textContent;

    if (newContent !== originalContent) {
      queueSave({
        key: field.key,
        type: field.type,
        id: field.id,
        pageId: field.pageId,
        fieldName: field.fieldName,
        content: newContent,
        itemId: field.itemId || 0,
        collection: field.collection || '',
        global: field.global,
      });
    }
  }

  activeField = null;
  originalContent = '';
}
