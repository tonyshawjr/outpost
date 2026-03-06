// Save manager — batches field changes and saves via the Outpost API
let pending = {};
let timer = null;
let statusEl = null;

export function init() {
  statusEl = document.getElementById('_ope_status');
}

export function queueSave(field) {
  pending[field.key] = field;
  showStatus('unsaved');
  clearTimeout(timer);
  timer = setTimeout(flush, 400);
}

async function flush() {
  const entries = Object.values(pending);
  if (!entries.length) return;
  pending = {};
  showStatus('saving');

  const ctx = window.__OPE || {};
  const headers = {
    'Content-Type': 'application/json',
    'X-CSRF-Token': ctx.csrf || '',
  };

  const pageFields = entries.filter(e => e.id && !e.itemId);
  const itemFields = entries.filter(e => e.itemId);
  const promises = [];

  if (pageFields.length) {
    const pageVersions = {};
    for (const f of pageFields) {
      if (f.global) {
        pageVersions[ctx.globalPageId] = ctx.globalVersion;
      } else {
        pageVersions[f.pageId || ctx.pageId] = ctx.pageVersion;
      }
    }
    promises.push(
      fetch(ctx.apiUrl + '?action=fields/bulk', {
        method: 'PUT', headers, credentials: 'include',
        body: JSON.stringify({
          fields: pageFields.map(f => ({ id: f.id, content: f.content })),
          _page_versions: pageVersions,
        }),
      }).then(async r => {
        const data = await r.json();
        if (!r.ok) throw new Error(data.error || 'Save failed');
        if (data.updated_at) {
          for (const f of pageFields) {
            if (f.global) ctx.globalVersion = data.updated_at;
            else ctx.pageVersion = data.updated_at;
          }
        }
      })
    );
  }

  if (itemFields.length) {
    const byItem = {};
    for (const f of itemFields) {
      if (!byItem[f.itemId]) byItem[f.itemId] = {};
      byItem[f.itemId][f.fieldName] = f.content;
    }
    for (const [itemId, fields] of Object.entries(byItem)) {
      promises.push(
        fetch(ctx.apiUrl + '?action=items/inline', {
          method: 'PUT', headers, credentials: 'include',
          body: JSON.stringify({
            id: parseInt(itemId),
            fields,
            _version: ctx.itemContext?.updated_at || null,
          }),
        }).then(async r => {
          const res = await r.json();
          if (!r.ok) throw new Error(res.error || 'Save failed');
          if (res.updated_at && ctx.itemContext) ctx.itemContext.updated_at = res.updated_at;
        })
      );
    }
  }

  try {
    await Promise.all(promises);
    showStatus('saved');
    setTimeout(() => showStatus(''), 2000);
  } catch (err) {
    showStatus('error', err.message);
    for (const e of entries) {
      if (!pending[e.key]) pending[e.key] = e;
    }
  }
}

function showStatus(state, msg) {
  if (!statusEl) return;
  switch (state) {
    case 'saving':
      statusEl.textContent = 'Saving\u2026';
      statusEl.classList.add('ope-visible');
      break;
    case 'saved':
      statusEl.textContent = 'Saved';
      statusEl.classList.add('ope-visible');
      break;
    case 'unsaved':
      statusEl.classList.remove('ope-visible');
      break;
    case 'error':
      statusEl.textContent = msg || 'Error';
      statusEl.style.color = '#f87171';
      statusEl.classList.add('ope-visible');
      setTimeout(() => { if (statusEl) statusEl.style.color = ''; }, 3000);
      break;
    default:
      statusEl.classList.remove('ope-visible');
  }
}
