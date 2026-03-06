// Image picker — lightweight media picker modal for image fields
import { queueSave } from './save-manager.js';

let modal = null;
let grid = null;
let activeField = null;
let mediaCache = null;

export function init() {
  modal = document.createElement('div');
  modal.className = 'ope-modal-overlay';
  modal.style.display = 'none';
  modal.innerHTML = `
    <div class="ope-modal">
      <div class="ope-modal-header">
        <span class="ope-modal-title">Select Image</span>
        <button class="ope-modal-close">&times;</button>
      </div>
      <div class="ope-modal-actions">
        <label class="ope-upload-btn">
          Upload
          <input type="file" accept="image/*" style="display:none">
        </label>
      </div>
      <div class="ope-media-grid"></div>
    </div>
  `;
  document.body.appendChild(modal);

  grid = modal.querySelector('.ope-media-grid');
  modal.querySelector('.ope-modal-close').addEventListener('click', close);
  modal.addEventListener('click', (e) => {
    if (e.target === modal) close();
  });
  modal.querySelector('input[type="file"]').addEventListener('change', onUpload);
}

export function activate(field) {
  activeField = field;
  modal.style.display = 'flex';
  loadMedia();
}

export function isActive() {
  return modal && modal.style.display !== 'none';
}

function close() {
  modal.style.display = 'none';
  activeField = null;
}

async function loadMedia() {
  grid.innerHTML = '<div class="ope-media-loading">Loading\u2026</div>';
  const ctx = window.__OPE || {};
  try {
    if (!mediaCache) {
      const r = await fetch(ctx.apiUrl + '?action=media', {
        credentials: 'include',
        headers: { 'X-CSRF-Token': ctx.csrf || '' },
      });
      const data = await r.json();
      mediaCache = (data.media || []).filter(m => m.mime_type && m.mime_type.startsWith('image/'));
    }
    renderGrid(mediaCache);
  } catch {
    grid.innerHTML = '<div class="ope-media-loading">Failed to load media</div>';
  }
}

function renderGrid(items) {
  grid.innerHTML = '';
  for (const item of items) {
    const thumb = document.createElement('div');
    thumb.className = 'ope-media-thumb';
    const imgSrc = item.thumb_path || item.path;
    thumb.innerHTML = `<img src="/outpost/uploads/${imgSrc}" alt="${item.alt_text || ''}" loading="lazy">`;
    thumb.addEventListener('click', () => selectImage(item));
    grid.appendChild(thumb);
  }
  if (!items.length) {
    grid.innerHTML = '<div class="ope-media-loading">No images found</div>';
  }
}

function selectImage(item) {
  if (!activeField) return;

  const newSrc = '/outpost/uploads/' + item.path;

  // Update the <img> element
  if (activeField.el.tagName === 'IMG') {
    activeField.el.src = newSrc;
  }

  queueSave({
    key: activeField.key,
    type: 'image',
    id: activeField.id,
    pageId: activeField.pageId,
    fieldName: activeField.fieldName,
    content: newSrc,
    itemId: activeField.itemId || 0,
    collection: activeField.collection || '',
    global: activeField.global,
  });

  close();
}

async function onUpload(e) {
  const file = e.target.files[0];
  if (!file) return;
  e.target.value = '';

  const ctx = window.__OPE || {};
  const formData = new FormData();
  formData.append('file', file);

  try {
    const r = await fetch(ctx.apiUrl + '?action=media', {
      method: 'POST',
      credentials: 'include',
      headers: { 'X-CSRF-Token': ctx.csrf || '' },
      body: formData,
    });
    const data = await r.json();
    if (data.media) {
      if (mediaCache) mediaCache.unshift(data.media);
      else mediaCache = [data.media];
      renderGrid(mediaCache);
    }
  } catch {
    // silently fail — user can retry
  }
}
