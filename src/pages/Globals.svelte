<script>
  import { onMount } from 'svelte';
  import { globals as globalsApi, fields as fieldsApi, ConflictError } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';
  import FieldRenderer from '$components/FieldRenderer.svelte';
  import ContextualTip from '$components/ContextualTip.svelte';
  import { tips } from '$lib/tips.js';

  let fields = $state([]);
  let loading = $state(true);
  let saving = $state(false);
  let dirty = $state(false);
  let changes = $state({});
  let globalPageId = $state(null);
  let globalVersion = $state(null);
  let conflict = $state(null);

  onMount(load);

  async function load() {
    loading = true;
    conflict = null;
    try {
      const data = await globalsApi.list();
      fields = data.fields || [];
      globalPageId = data.page_id || null;
      globalVersion = data.updated_at || null;
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  function handleFieldChange(fieldId, value) {
    changes[fieldId] = value;
    dirty = true;
    fields = fields.map((f) =>
      f.id === fieldId ? { ...f, content: value } : f
    );
  }

  async function handleSave() {
    saving = true;
    try {
      const updates = Object.entries(changes)
        .filter(([id]) => id && id !== 'null')
        .map(([id, content]) => ({ id: parseInt(id), content }));

      if (updates.length > 0) {
        const pv = {};
        if (globalPageId && globalVersion) pv[globalPageId] = globalVersion;
        const result = await fieldsApi.bulkUpdate(updates, pv);
        if (result.updated_at) globalVersion = result.updated_at;
      }

      changes = {};
      dirty = false;
      conflict = null;
      addToast('Globals saved', 'success');
    } catch (err) {
      if (err instanceof ConflictError) {
        conflict = {
          message: err.message,
          reload: () => { conflict = null; load(); },
          force: () => { conflict = null; globalVersion = null; handleSave(); },
        };
      } else {
        addToast('Failed to save: ' + err.message, 'error');
      }
    } finally {
      saving = false;
    }
  }

  function handleGlobalKeydown(e) {
    if ((e.metaKey || e.ctrlKey) && e.key === 's') {
      e.preventDefault();
      if (dirty && !saving) handleSave();
    }
  }
</script>

<svelte:window onkeydown={handleGlobalKeydown} />

<div class="gl">
  <div class="page-header">
    <div class="page-header-icon sage">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>
    </div>
    <div class="page-header-content">
      <h1 class="page-title">Globals</h1>
      <p class="page-subtitle">Fields shared across all pages via <code class="gl-code">&#123;&#123; @field &#125;&#125;</code></p>
    </div>
    <div class="page-header-actions">
      <button
        class="btn btn-primary"
        onclick={handleSave}
        disabled={!dirty || saving}
      >
        {saving ? 'Saving...' : 'Save'}
      </button>
    </div>
  </div>

  <ContextualTip tipKey="globals" message={tips.globals} />

  {#if conflict}
    <div class="gl-conflict-banner">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      <span class="gl-conflict-msg">{conflict.message}</span>
      <button class="gl-conflict-btn" onclick={conflict.reload}>Reload</button>
      <button class="gl-conflict-btn gl-conflict-btn--force" onclick={conflict.force}>Save anyway</button>
    </div>
  {/if}

  {#if loading}
    <div class="loading-overlay"><div class="spinner"></div></div>
  {:else if fields.length === 0}
    <div class="gl-empty">
      <p class="gl-empty-title">No global fields yet</p>
      <p class="gl-empty-hint">Use <code>&#123;&#123; @field_name &#125;&#125;</code> in your theme templates, then re-activate the theme to scaffold these fields.</p>
    </div>
  {:else}
    <div class="gl-fields">
      {#each fields as field (field.id)}
        <FieldRenderer {field} onchange={handleFieldChange} />
      {/each}
    </div>
  {/if}
</div>

<style>
  .gl {
    max-width: var(--content-width);
  }

  .gl-code {
    font-family: var(--font-mono);
    font-size: 0.85em;
    background: var(--bg-secondary);
    padding: 1px 5px;
    border-radius: 3px;
  }

  .gl-fields {
    border-top: 1px solid var(--border-primary);
  }

  .gl-empty {
    padding: var(--space-3xl) 0;
    color: var(--text-tertiary);
  }

  .gl-empty-title {
    font-size: var(--text-base);
    font-weight: 500;
    margin: 0 0 6px;
    color: var(--text-secondary);
  }

  .gl-empty-hint {
    font-size: var(--text-sm);
    margin: 0;
  }

  .gl-empty-hint code {
    font-family: var(--font-mono);
    font-size: 0.85em;
    background: var(--bg-secondary);
    padding: 1px 5px;
    border-radius: 3px;
  }

  /* ── Conflict banner ── */
  .gl-conflict-banner {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    margin-bottom: 16px;
    background: #fffbeb;
    border: 1px solid #f5e6b8;
    border-radius: var(--radius-sm);
    font-size: 13px;
    color: #92400e;
  }
  :global(.dark) .gl-conflict-banner {
    background: #332b10;
    border-color: #4a3f1a;
    color: #fbbf24;
  }
  .gl-conflict-msg { flex: 1; }
  .gl-conflict-btn {
    padding: 4px 12px;
    border-radius: var(--radius-sm);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    border: 1px solid #e5d5a0;
    background: #fff;
    color: #92400e;
    font-family: var(--font-sans);
    white-space: nowrap;
  }
  .gl-conflict-btn:hover { background: #fef3c7; }
  :global(.dark) .gl-conflict-btn {
    background: #4a3f1a;
    border-color: #5c4f22;
    color: #fbbf24;
  }
  :global(.dark) .gl-conflict-btn:hover { background: #5c4f22; }
  .gl-conflict-btn--force {
    background: none;
    border-color: transparent;
    color: #b45309;
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  :global(.dark) .gl-conflict-btn--force {
    background: none;
    border-color: transparent;
    color: #d97706;
  }
</style>
