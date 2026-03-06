// RichText editor — floating TipTap toolbar for richtext fields
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Image from '@tiptap/extension-image';
import { queueSave } from './save-manager.js';
import { setEditing } from './overlay.js';

let editor = null;
let activeField = null;
let toolbar = null;
let originalContent = '';

export function init() {
  // Create floating toolbar
  toolbar = document.createElement('div');
  toolbar.className = 'ope-toolbar';
  toolbar.style.display = 'none';
  toolbar.innerHTML = `
    <button data-cmd="bold" title="Bold"><b>B</b></button>
    <button data-cmd="italic" title="Italic"><i>I</i></button>
    <button data-cmd="strike" title="Strikethrough"><s>S</s></button>
    <span class="ope-toolbar-sep"></span>
    <button data-cmd="heading2" title="Heading 2">H2</button>
    <button data-cmd="heading3" title="Heading 3">H3</button>
    <span class="ope-toolbar-sep"></span>
    <button data-cmd="bulletList" title="Bullet list">&bull;</button>
    <button data-cmd="orderedList" title="Ordered list">1.</button>
    <button data-cmd="blockquote" title="Blockquote">&ldquo;</button>
    <span class="ope-toolbar-sep"></span>
    <button data-cmd="link" title="Link">&#128279;</button>
    <button data-cmd="done" title="Done">&#10003;</button>
  `;
  document.body.appendChild(toolbar);

  toolbar.addEventListener('mousedown', (e) => {
    e.preventDefault(); // prevent blur
  });
  toolbar.addEventListener('click', onToolbarClick);
}

function onToolbarClick(e) {
  const btn = e.target.closest('button');
  if (!btn || !editor) return;

  const cmd = btn.dataset.cmd;
  const chain = editor.chain().focus();

  switch (cmd) {
    case 'bold': chain.toggleBold().run(); break;
    case 'italic': chain.toggleItalic().run(); break;
    case 'strike': chain.toggleStrike().run(); break;
    case 'heading2': chain.toggleHeading({ level: 2 }).run(); break;
    case 'heading3': chain.toggleHeading({ level: 3 }).run(); break;
    case 'bulletList': chain.toggleBulletList().run(); break;
    case 'orderedList': chain.toggleOrderedList().run(); break;
    case 'blockquote': chain.toggleBlockquote().run(); break;
    case 'link': {
      const url = prompt('Link URL:', editor.getAttributes('link').href || 'https://');
      if (url === null) break;
      if (url === '') { chain.unsetLink().run(); }
      else { chain.setLink({ href: url }).run(); }
      break;
    }
    case 'done':
      deactivate(true);
      break;
  }
  updateActiveButtons();
}

function updateActiveButtons() {
  if (!toolbar || !editor) return;
  toolbar.querySelectorAll('button[data-cmd]').forEach(btn => {
    const cmd = btn.dataset.cmd;
    let isActive = false;
    switch (cmd) {
      case 'bold': isActive = editor.isActive('bold'); break;
      case 'italic': isActive = editor.isActive('italic'); break;
      case 'strike': isActive = editor.isActive('strike'); break;
      case 'heading2': isActive = editor.isActive('heading', { level: 2 }); break;
      case 'heading3': isActive = editor.isActive('heading', { level: 3 }); break;
      case 'bulletList': isActive = editor.isActive('bulletList'); break;
      case 'orderedList': isActive = editor.isActive('orderedList'); break;
      case 'blockquote': isActive = editor.isActive('blockquote'); break;
      case 'link': isActive = editor.isActive('link'); break;
    }
    btn.classList.toggle('ope-toolbar-active', isActive);
  });
}

export function activate(field) {
  if (activeField) deactivate(false);

  activeField = field;
  const el = field.el;
  originalContent = el.innerHTML;

  setEditing(true);
  el.classList.remove('ope-hover');
  el.classList.add('ope-editing');

  // Clear element before TipTap init — TipTap appends its own view inside the element,
  // so leaving existing HTML causes duplication
  el.innerHTML = '';

  editor = new Editor({
    element: el,
    extensions: [
      StarterKit,
      Link.configure({ openOnClick: false }),
      Image,
    ],
    content: originalContent,
    onUpdate: () => updateActiveButtons(),
    onSelectionUpdate: () => updateActiveButtons(),
  });

  // Show toolbar
  positionToolbar(el);
  toolbar.style.display = 'flex';

  // Close on click outside
  setTimeout(() => {
    document.addEventListener('mousedown', onOutsideClick);
  }, 50);
}

export function isActive() {
  return activeField !== null;
}

function onOutsideClick(e) {
  if (!activeField) return;
  const el = activeField.el;
  if (el.contains(e.target) || toolbar.contains(e.target)) return;
  deactivate(true);
}

function deactivate(save) {
  document.removeEventListener('mousedown', onOutsideClick);

  if (editor && save && activeField) {
    const html = editor.getHTML();
    if (html !== originalContent) {
      queueSave({
        key: activeField.key,
        type: 'richtext',
        id: activeField.id,
        pageId: activeField.pageId,
        fieldName: activeField.fieldName,
        content: html,
        itemId: activeField.itemId || 0,
        collection: activeField.collection || '',
        global: activeField.global,
      });
    }
  }

  if (editor) {
    // Get final HTML before destroying
    const finalHtml = editor.getHTML();
    editor.destroy();
    editor = null;
    // Restore the element's content (TipTap replaces the inner DOM)
    if (activeField) {
      activeField.el.innerHTML = save ? finalHtml : originalContent;
    }
  }

  if (activeField) activeField.el.classList.remove('ope-editing');
  if (toolbar) toolbar.style.display = 'none';
  setEditing(false);
  activeField = null;
  originalContent = '';
}

function positionToolbar(el) {
  if (!toolbar) return;
  const rect = el.getBoundingClientRect();
  const tw = toolbar.offsetWidth || 400;
  let left = rect.left + window.scrollX;
  let top = rect.top + window.scrollY - 44;
  if (top < window.scrollY + 4) top = rect.bottom + window.scrollY + 4;
  if (left + tw > window.innerWidth) left = window.innerWidth - tw - 8;
  if (left < 8) left = 8;
  toolbar.style.left = left + 'px';
  toolbar.style.top = top + 'px';
}
