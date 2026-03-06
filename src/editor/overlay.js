// Overlay — hover outlines and click handlers for editable fields
import { getFields } from './scanner.js';

let labelEl = null;
let editing = false; // suppress hover while editing

export function init() {
  labelEl = document.createElement('div');
  labelEl.className = 'ope-label';
  labelEl.style.display = 'none';
  document.body.appendChild(labelEl);
}

export function enable() {
  const fields = getFields();
  for (const f of fields) {
    f.el.classList.add('ope-editable');
    f.el.addEventListener('mouseenter', onEnter);
    f.el.addEventListener('mouseleave', onLeave);
  }
}

export function setEditing(on) {
  editing = on;
  if (on && labelEl) labelEl.style.display = 'none';
}

function onEnter(e) {
  if (editing) return;
  const el = e.currentTarget;
  el.classList.add('ope-hover');

  const field = getFields().find(f => f.el === el);
  if (field && labelEl) {
    const typeIcons = { text: 'T', textarea: 'T', richtext: 'RT', image: 'IMG' };
    labelEl.textContent = (typeIcons[field.type] || 'T') + '  ' + field.fieldName.replace(/_/g, ' ');
    labelEl.style.display = 'block';
    positionLabel(el);
  }
}

function onLeave(e) {
  e.currentTarget.classList.remove('ope-hover');
  if (labelEl) labelEl.style.display = 'none';
}

function positionLabel(el) {
  if (!labelEl) return;
  const rect = el.getBoundingClientRect();
  const lw = labelEl.offsetWidth;
  let left = rect.left + window.scrollX;
  let top = rect.top + window.scrollY - 24;
  if (top < window.scrollY + 4) top = rect.bottom + window.scrollY + 4;
  if (left + lw > window.innerWidth) left = window.innerWidth - lw - 8;
  labelEl.style.left = left + 'px';
  labelEl.style.top = top + 'px';
}

export function setClickHandler(handler) {
  const fields = getFields();
  for (const f of fields) {
    f.el._opeClick = (e) => {
      e.preventDefault();
      e.stopPropagation();
      handler(f, e);
    };
    f.el.addEventListener('click', f.el._opeClick);
  }
}
