<script>
  import { onMount } from 'svelte';
  import { redirects as redirectsApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';
  import EmptyState from '$components/EmptyState.svelte';

  let redirectsList = $state([]);
  let loading = $state(true);
  let search = $state('');

  // Form state
  let showForm = $state(false);
  let editingId = $state(null);
  let formSource = $state('');
  let formTarget = $state('');
  let formType = $state(301);
  let formNotes = $state('');
  let formActive = $state(true);
  let saving = $state(false);

  // Import state
  let showImport = $state(false);
  let importCsv = $state('');
  let importing = $state(false);

  // Test state
  let showTest = $state(false);
  let testUrl = $state('');
  let testResult = $state(null);
  let testing = $state(false);

  let filtered = $derived(
    search
      ? redirectsList.filter(r =>
          r.source_url.toLowerCase().includes(search.toLowerCase()) ||
          r.target_url.toLowerCase().includes(search.toLowerCase()) ||
          (r.notes || '').toLowerCase().includes(search.toLowerCase())
        )
      : redirectsList
  );

  onMount(async () => {
    await loadRedirects();
  });

  async function loadRedirects() {
    loading = true;
    try {
      const data = await redirectsApi.list();
      redirectsList = data.redirects || [];
    } catch (e) {
      addToast(e.message, 'error');
    } finally {
      loading = false;
    }
  }

  function openNew() {
    editingId = null;
    formSource = '';
    formTarget = '';
    formType = 301;
    formNotes = '';
    formActive = true;
    showForm = true;
  }

  function openEdit(r) {
    editingId = r.id;
    formSource = r.source_url;
    formTarget = r.target_url;
    formType = Number(r.type);
    formNotes = r.notes || '';
    formActive = Number(r.active) === 1;
    showForm = true;
  }

  function closeForm() {
    showForm = false;
    editingId = null;
  }

  async function handleSave() {
    if (!formSource.trim() || !formTarget.trim()) {
      addToast('Source and target URLs are required', 'error');
      return;
    }
    saving = true;
    try {
      const payload = {
        source_url: formSource.trim(),
        target_url: formTarget.trim(),
        type: formType,
        notes: formNotes.trim(),
        active: formActive ? 1 : 0,
      };
      if (editingId) {
        await redirectsApi.update(editingId, payload);
        addToast('Redirect updated');
      } else {
        await redirectsApi.create(payload);
        addToast('Redirect created');
      }
      closeForm();
      await loadRedirects();
    } catch (e) {
      addToast(e.message, 'error');
    } finally {
      saving = false;
    }
  }

  async function handleDelete(id) {
    if (!confirm('Delete this redirect?')) return;
    try {
      await redirectsApi.delete(id);
      addToast('Redirect deleted');
      await loadRedirects();
    } catch (e) {
      addToast(e.message, 'error');
    }
  }

  async function handleToggleActive(r) {
    try {
      await redirectsApi.update(r.id, { active: Number(r.active) === 1 ? 0 : 1 });
      await loadRedirects();
    } catch (e) {
      addToast(e.message, 'error');
    }
  }

  async function handleImport() {
    if (!importCsv.trim()) {
      addToast('Paste CSV data first', 'error');
      return;
    }
    importing = true;
    try {
      const result = await redirectsApi.import(importCsv.trim());
      addToast(`Imported ${result.imported} redirects${result.skipped ? `, ${result.skipped} skipped` : ''}`);
      showImport = false;
      importCsv = '';
      await loadRedirects();
    } catch (e) {
      addToast(e.message, 'error');
    } finally {
      importing = false;
    }
  }

  async function handleTest() {
    if (!testUrl.trim()) return;
    testing = true;
    testResult = null;
    try {
      testResult = await redirectsApi.test(testUrl.trim());
    } catch (e) {
      addToast(e.message, 'error');
    } finally {
      testing = false;
    }
  }

  function typeLabel(type) {
    const t = Number(type);
    if (t === 301) return '301';
    if (t === 302) return '302';
    if (t === 307) return '307';
    return String(t);
  }

  function typeDesc(type) {
    const t = Number(type);
    if (t === 301) return 'Permanent';
    if (t === 302) return 'Temporary';
    if (t === 307) return 'Temporary (preserve method)';
    return '';
  }
</script>

<div class="page-container" style="max-width: var(--content-width-wide);">
  <div class="page-header">
    <div>
      <h1 class="page-title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20" style="vertical-align: -3px; margin-right: 6px;"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 00-4-4H4"/></svg>
        Redirects
      </h1>
      <p class="page-subtitle">Manage URL redirects for your site</p>
    </div>
    <div class="page-header-actions">
      <button class="btn btn-ghost btn-sm" onclick={() => { showTest = !showTest; showImport = false; }}>
        Test URL
      </button>
      <button class="btn btn-ghost btn-sm" onclick={() => { showImport = !showImport; showTest = false; }}>
        Import
      </button>
      <button class="btn btn-primary btn-sm" onclick={openNew}>
        Add Redirect
      </button>
    </div>
  </div>

  <!-- Test URL panel -->
  {#if showTest}
    <div class="redirects-panel">
      <div class="panel-header">
        <span class="panel-title">Test a URL</span>
        <button class="btn btn-ghost btn-xs" onclick={() => { showTest = false; testResult = null; }}>Close</button>
      </div>
      <div class="test-row">
        <input
          type="text"
          class="field-input"
          placeholder="/old-page or /blog/old-post"
          bind:value={testUrl}
          onkeydown={(e) => { if (e.key === 'Enter') handleTest(); }}
        />
        <button class="btn btn-secondary btn-sm" onclick={handleTest} disabled={testing}>
          {testing ? 'Testing...' : 'Test'}
        </button>
      </div>
      {#if testResult}
        <div class="test-result" class:test-match={testResult.matched} class:test-no-match={!testResult.matched}>
          {#if testResult.matched}
            <strong>Match found</strong> ({testResult.type}) — redirects to <code>{testResult.resolved_target}</code> ({testResult.redirect.type})
          {:else}
            <strong>No match</strong> — this URL will not be redirected.
          {/if}
        </div>
      {/if}
    </div>
  {/if}

  <!-- Import panel -->
  {#if showImport}
    <div class="redirects-panel">
      <div class="panel-header">
        <span class="panel-title">Import Redirects</span>
        <button class="btn btn-ghost btn-xs" onclick={() => { showImport = false; }}>Close</button>
      </div>
      <p class="panel-desc">Paste CSV data with columns: source, target, type (optional). One redirect per line.</p>
      <textarea
        class="field-input import-textarea"
        placeholder="/old-page,/new-page,301&#10;/blog/*,/articles/*,301"
        bind:value={importCsv}
        rows="6"
      ></textarea>
      <button class="btn btn-primary btn-sm" onclick={handleImport} disabled={importing} style="margin-top: 8px;">
        {importing ? 'Importing...' : 'Import'}
      </button>
    </div>
  {/if}

  <!-- Add/Edit form -->
  {#if showForm}
    <div class="redirects-panel redirects-form">
      <div class="panel-header">
        <span class="panel-title">{editingId ? 'Edit Redirect' : 'New Redirect'}</span>
        <button class="btn btn-ghost btn-xs" onclick={closeForm}>Cancel</button>
      </div>
      <div class="form-grid">
        <div class="form-field">
          <label class="field-label">Source URL</label>
          <input type="text" class="field-input" placeholder="/old-page" bind:value={formSource} />
          <span class="field-hint">Path on your site. Supports wildcards (/old/*) and regex (~^/post/(\d+)$)</span>
        </div>
        <div class="form-field">
          <label class="field-label">Target URL</label>
          <input type="text" class="field-input" placeholder="/new-page" bind:value={formTarget} />
          <span class="field-hint">Where to redirect. Can be a path or full URL.</span>
        </div>
        <div class="form-field">
          <label class="field-label">Type</label>
          <select class="field-input" bind:value={formType}>
            <option value={301}>301 — Permanent</option>
            <option value={302}>302 — Temporary</option>
            <option value={307}>307 — Temporary (preserve method)</option>
          </select>
        </div>
        <div class="form-field">
          <label class="field-label">Notes</label>
          <textarea class="field-input" placeholder="Optional notes..." bind:value={formNotes} rows="2"></textarea>
        </div>
        <div class="form-field">
          <label class="field-label toggle-label">
            <input type="checkbox" bind:checked={formActive} />
            Active
          </label>
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary btn-sm" onclick={handleSave} disabled={saving}>
          {saving ? 'Saving...' : (editingId ? 'Update' : 'Create')}
        </button>
      </div>
    </div>
  {/if}

  <!-- Search -->
  {#if redirectsList.length > 0}
    <div class="redirects-search">
      <input
        type="text"
        class="field-input"
        placeholder="Filter redirects..."
        bind:value={search}
      />
    </div>
  {/if}

  <!-- List -->
  {#if loading}
    <div class="loading-state">Loading...</div>
  {:else if redirectsList.length === 0}
    <EmptyState
      title="No redirects yet"
      description="Create URL redirects to keep your links working when pages move."
      action="Add Redirect"
      onaction={openNew}
    />
  {:else if filtered.length === 0}
    <div class="empty-filter">No redirects match your filter.</div>
  {:else}
    <div class="redirects-table">
      <div class="redirects-header-row">
        <span class="col-source">Source</span>
        <span class="col-arrow"></span>
        <span class="col-target">Target</span>
        <span class="col-type">Type</span>
        <span class="col-hits">Hits</span>
        <span class="col-actions">Actions</span>
      </div>
      {#each filtered as r (r.id)}
        <div class="redirect-row" class:inactive={Number(r.active) !== 1}>
          <span class="col-source redirect-url" title={r.source_url}>{r.source_url}</span>
          <span class="col-arrow">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
          </span>
          <span class="col-target redirect-url" title={r.target_url}>{r.target_url}</span>
          <span class="col-type">
            <span class="type-badge" class:type-301={Number(r.type) === 301} class:type-302={Number(r.type) === 302} class:type-307={Number(r.type) === 307}>
              {typeLabel(r.type)}
            </span>
          </span>
          <span class="col-hits">{r.hits ?? 0}</span>
          <span class="col-actions">
            <button
              class="btn btn-ghost btn-xs"
              onclick={() => handleToggleActive(r)}
              title={Number(r.active) === 1 ? 'Disable' : 'Enable'}
            >
              {Number(r.active) === 1 ? 'On' : 'Off'}
            </button>
            <button class="btn btn-ghost btn-xs" onclick={() => openEdit(r)}>Edit</button>
            <button class="btn btn-ghost btn-xs btn-danger-text" onclick={() => handleDelete(r.id)}>Delete</button>
          </span>
        </div>
        {#if r.notes}
          <div class="redirect-notes">{r.notes}</div>
        {/if}
      {/each}
    </div>
  {/if}
</div>

<style>
  .redirects-panel {
    background: var(--bg-elevated, var(--bg-secondary));
    border: 1px solid var(--border);
    border-radius: var(--radius-lg, 8px);
    padding: 16px 20px;
    margin-bottom: 20px;
  }

  .panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
  }

  .panel-title {
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-secondary);
  }

  .panel-desc {
    font-size: 13px;
    color: var(--text-tertiary);
    margin-bottom: 8px;
  }

  .test-row {
    display: flex;
    gap: 8px;
    align-items: center;
  }

  .test-row .field-input {
    flex: 1;
  }

  .test-result {
    margin-top: 10px;
    font-size: 13px;
    padding: 8px 12px;
    border-radius: var(--radius-md, 6px);
  }

  .test-result code {
    background: var(--bg-hover);
    padding: 1px 4px;
    border-radius: 3px;
    font-size: 12px;
  }

  .test-match {
    background: rgba(34, 197, 94, 0.1);
    color: var(--text-primary);
  }

  .test-no-match {
    background: var(--bg-hover);
    color: var(--text-secondary);
  }

  .import-textarea {
    width: 100%;
    font-family: var(--font-mono, monospace);
    font-size: 12px;
  }

  .form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px 16px;
  }

  .form-grid .form-field:nth-child(4),
  .form-grid .form-field:nth-child(5) {
    grid-column: 1 / -1;
  }

  .form-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .field-hint {
    font-size: 11px;
    color: var(--text-tertiary);
  }

  .toggle-label {
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    font-size: 13px;
  }

  .form-actions {
    margin-top: 12px;
    display: flex;
    justify-content: flex-end;
  }

  .redirects-search {
    margin-bottom: 16px;
  }

  .redirects-search .field-input {
    max-width: 320px;
  }

  .redirects-table {
    border: 1px solid var(--border);
    border-radius: var(--radius-lg, 8px);
    overflow: hidden;
  }

  .redirects-header-row {
    display: grid;
    grid-template-columns: 1fr 28px 1fr 64px 56px 140px;
    gap: 8px;
    padding: 8px 16px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-tertiary);
    border-bottom: 1px solid var(--border);
    background: var(--bg-secondary);
  }

  .redirect-row {
    display: grid;
    grid-template-columns: 1fr 28px 1fr 64px 56px 140px;
    gap: 8px;
    padding: 10px 16px;
    align-items: center;
    border-bottom: 1px solid var(--border-subtle, var(--border));
    font-size: 13px;
    transition: background 0.1s;
  }

  .redirect-row:last-child {
    border-bottom: none;
  }

  .redirect-row:hover {
    background: var(--bg-hover);
  }

  .redirect-row.inactive {
    opacity: 0.45;
  }

  .redirect-url {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-family: var(--font-mono, monospace);
    font-size: 12px;
  }

  .col-arrow {
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-tertiary);
  }

  .col-hits {
    text-align: right;
    font-variant-numeric: tabular-nums;
    color: var(--text-tertiary);
    font-size: 12px;
  }

  .col-actions {
    display: flex;
    gap: 4px;
    justify-content: flex-end;
  }

  .type-badge {
    font-size: 11px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    padding: 1px 6px;
    border-radius: var(--radius-sm, 4px);
    background: var(--bg-hover);
    color: var(--text-secondary);
  }

  .type-301 { color: var(--accent, #4A8B72); }
  .type-302 { color: #C4785C; }
  .type-307 { color: #7D9B8A; }

  .redirect-notes {
    padding: 2px 16px 8px 16px;
    font-size: 12px;
    color: var(--text-tertiary);
    border-bottom: 1px solid var(--border-subtle, var(--border));
  }

  .btn-danger-text {
    color: var(--color-error, #ef4444);
  }

  .loading-state, .empty-filter {
    padding: 40px;
    text-align: center;
    color: var(--text-tertiary);
    font-size: 14px;
  }

  @media (max-width: 768px) {
    .redirects-header-row,
    .redirect-row {
      grid-template-columns: 1fr;
      gap: 4px;
    }
    .col-arrow, .redirects-header-row .col-arrow {
      display: none;
    }
    .redirects-header-row {
      display: none;
    }
    .redirect-row {
      padding: 12px 16px;
    }
    .col-actions {
      justify-content: flex-start;
    }
    .form-grid {
      grid-template-columns: 1fr;
    }
  }
</style>
