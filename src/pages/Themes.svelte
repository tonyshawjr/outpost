<script>
  import { onMount } from 'svelte';
  import { themes as themesApi, code as codeApi } from '$lib/api.js';
  import { addToast, navigate } from '$lib/stores.js';
  import EmptyState from '$components/EmptyState.svelte';
  import ContextualTip from '$components/ContextualTip.svelte';
  import { tips } from '$lib/tips.js';

  let themesList = $state([]);
  let activeSlug = $state('');
  let loading = $state(true);

  // Duplicate modal
  let showDuplicate = $state(false);
  let duplicateSource = $state('');
  let duplicateName = $state('');
  let duplicating = $state(false);

  // Delete confirm
  let deleteTarget = $state(null);
  let deleting = $state(false);

  // Activating
  let activating = $state('');

  // Upload
  let uploading = $state(false);
  let uploadInput;

  // New theme modal
  let showNewTheme = $state(false);
  let newThemeMode = $state('blank'); // 'blank' or 'duplicate'
  let newThemeName = $state('');
  let newThemeSource = $state('');
  let creatingTheme = $state(false);

  let activeTheme = $derived(themesList.find(t => t.active));
  let draftThemes = $derived(themesList.filter(t => !t.active));

  onMount(() => {
    loadThemes();
  });

  async function loadThemes() {
    loading = true;
    try {
      const data = await themesApi.list();
      themesList = data.themes || [];
      activeSlug = data.active || '';
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  async function activateTheme(slug) {
    activating = slug;
    try {
      await themesApi.activate(slug);
      addToast('Theme activated', 'success');
      await loadThemes();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      activating = '';
    }
  }

  let resetting = $state('');
  let confirmReset = $state('');

  async function resetTheme(slug) {
    resetting = slug;
    try {
      await codeApi.reset(slug);
      addToast('Theme reset to original state', 'success');
      confirmReset = '';
      await loadThemes();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      resetting = '';
    }
  }

  function openDuplicate(sourceSlug) {
    duplicateSource = sourceSlug;
    duplicateName = '';
    showDuplicate = true;
  }

  async function handleDuplicate() {
    if (!duplicateName.trim()) return;
    duplicating = true;
    try {
      await themesApi.duplicate(duplicateSource, duplicateName.trim());
      addToast('Theme duplicated', 'success');
      showDuplicate = false;
      await loadThemes();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      duplicating = false;
    }
  }

  async function handleDelete() {
    if (!deleteTarget) return;
    deleting = true;
    try {
      await themesApi.delete(deleteTarget);
      addToast('Theme deleted', 'success');
      deleteTarget = null;
      await loadThemes();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      deleting = false;
    }
  }

  async function handleUpload(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    uploading = true;
    try {
      const result = await themesApi.upload(file);
      addToast(`Theme "${result.name}" installed`, 'success');
      await loadThemes();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      uploading = false;
      if (uploadInput) uploadInput.value = '';
    }
  }

  function exportTheme(slug) {
    window.location.href = themesApi.exportUrl(slug);
  }

  function openNewTheme() {
    newThemeMode = 'blank';
    newThemeName = '';
    newThemeSource = themesList[0]?.slug || '';
    showNewTheme = true;
  }

  async function handleCreateTheme() {
    if (!newThemeName.trim()) return;
    creatingTheme = true;
    try {
      if (newThemeMode === 'blank') {
        await themesApi.create(newThemeName.trim());
      } else {
        await themesApi.duplicate(newThemeSource, newThemeName.trim());
      }
      addToast('Theme created', 'success');
      showNewTheme = false;
      await loadThemes();
      navigate('code-editor');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      creatingTheme = false;
    }
  }
</script>

<div class="themes-page">
{#if loading}
  <div class="loading-overlay"><div class="spinner"></div></div>
{:else}
  <!-- Page Header -->
  <div class="page-header">
    <div class="page-header-icon sage">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
    </div>
    <div class="page-header-content">
      <h1 class="page-title">Themes</h1>
      <p class="page-subtitle">Manage your site's appearance</p>
    </div>
    <div class="page-header-actions">
      <button class="btn btn-secondary" onclick={() => openNewTheme()}>New Theme</button>
      <button class="btn btn-secondary" onclick={() => uploadInput?.click()} disabled={uploading}>
        {uploading ? 'Uploading...' : 'Upload Theme'}
      </button>
      <input
        type="file"
        accept=".zip"
        class="sr-only"
        bind:this={uploadInput}
        onchange={handleUpload}
      />
    </div>
  </div>

  <ContextualTip tipKey="themes" message={tips.themes} />

  <!-- Active Theme -->
  {#if activeTheme}
    <div class="themes-section">
      <div class="themes-section-label">LIVE THEME</div>
      <div class="active-theme">
        <div class="active-theme-info">
          <div class="active-theme-name">{activeTheme.name}</div>
          <div class="active-theme-meta">
            {#if activeTheme.version}<span>v{activeTheme.version}</span>{/if}
            {#if activeTheme.author}<span class="meta-dot"></span><span>{activeTheme.author}</span>{/if}
            {#if activeTheme.managed}<span class="meta-dot"></span><span class="managed-label">Managed by Outpost</span>{/if}
          </div>
          {#if activeTheme.description}
            <div class="active-theme-desc">{activeTheme.description}</div>
          {/if}
        </div>
        <div class="active-theme-actions">
          <button class="btn btn-primary btn-sm" onclick={() => navigate('theme-customizer')}>
            Customize
          </button>
          <button class="btn btn-secondary btn-sm" onclick={() => openDuplicate(activeTheme.slug)}>
            Duplicate
          </button>
          <button class="btn btn-secondary btn-sm" onclick={() => exportTheme(activeTheme.slug)}>
            Export
          </button>
          {#if activeTheme.has_snapshot}
            {#if confirmReset === activeTheme.slug}
              <button class="btn btn-sm" style="background: #EB5757; color: white;" onclick={() => resetTheme(activeTheme.slug)} disabled={resetting === activeTheme.slug}>
                {resetting === activeTheme.slug ? 'Resetting...' : 'Confirm Reset'}
              </button>
              <button class="btn btn-secondary btn-sm" onclick={() => { confirmReset = ''; }}>Cancel</button>
            {:else}
              <button class="btn btn-secondary btn-sm" onclick={() => { confirmReset = activeTheme.slug; }}>
                Reset
              </button>
            {/if}
          {/if}
        </div>
      </div>
    </div>
  {/if}

  <!-- Draft Themes -->
  {#if draftThemes.length > 0}
    <div class="themes-section">
      <div class="themes-section-label">THEME LIBRARY</div>
      <div class="themes-grid">
        {#each draftThemes as theme}
          <div class="theme-card">
            <div class="theme-card-body">
              <div class="theme-card-name">{theme.name}</div>
              <div class="theme-card-meta">
                {#if theme.version}<span>v{theme.version}</span>{/if}
                {#if theme.author}<span class="meta-dot"></span><span>{theme.author}</span>{/if}
                {#if theme.managed}<span class="meta-dot"></span><span class="managed-label">Managed by Outpost</span>{/if}
              </div>
              {#if theme.description}
                <div class="theme-card-desc">{theme.description}</div>
              {/if}
            </div>
            <div class="theme-card-actions">
              <button
                class="btn btn-primary btn-sm"
                onclick={() => activateTheme(theme.slug)}
                disabled={activating === theme.slug}
              >
                {activating === theme.slug ? 'Publishing...' : 'Publish'}
              </button>
              <button class="btn btn-secondary btn-sm" onclick={() => openDuplicate(theme.slug)}>
                Duplicate
              </button>
              <button class="btn btn-secondary btn-sm" onclick={() => exportTheme(theme.slug)}>
                Export
              </button>
              {#if theme.has_snapshot}
                {#if confirmReset === theme.slug}
                  <button class="btn btn-sm" style="background: #EB5757; color: white;" onclick={() => resetTheme(theme.slug)} disabled={resetting === theme.slug}>
                    {resetting === theme.slug ? 'Resetting...' : 'Confirm Reset'}
                  </button>
                  <button class="btn btn-secondary btn-sm" onclick={() => { confirmReset = ''; }}>Cancel</button>
                {:else}
                  <button class="btn btn-secondary btn-sm" onclick={() => { confirmReset = theme.slug; }}>
                    Reset
                  </button>
                {/if}
              {/if}
              {#if !theme.managed}
                <button class="theme-delete-btn" onclick={() => { deleteTarget = theme.slug; }}>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                </button>
              {/if}
            </div>
          </div>
        {/each}
      </div>
    </div>
  {/if}

  {#if themesList.length === 0}
    <EmptyState
      title="No themes found"
      description="Create a new theme or upload a theme zip to get started."
    />
  {/if}
{/if}
</div>

<!-- Duplicate Modal -->
{#if showDuplicate}
  <div class="modal-overlay" onclick={() => { showDuplicate = false; }} role="dialog">
    <div class="modal" onclick={(e) => e.stopPropagation()}>
      <div class="modal-header">
        <h3 class="modal-title">Duplicate Theme</h3>
        <button class="modal-close" onclick={() => { showDuplicate = false; }}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">New theme name</label>
          <input
            class="input"
            type="text"
            bind:value={duplicateName}
            placeholder="My Custom Theme"
            onkeydown={(e) => { if (e.key === 'Enter') handleDuplicate(); }}
          />
        </div>
        <p style="font-size: var(--font-size-xs); color: var(--text-tertiary); margin-top: var(--space-xs);">
          Copying from <strong>{duplicateSource}</strong>
        </p>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" onclick={() => { showDuplicate = false; }}>Cancel</button>
        <button class="btn btn-primary" onclick={handleDuplicate} disabled={duplicating || !duplicateName.trim()}>
          {duplicating ? 'Duplicating...' : 'Duplicate'}
        </button>
      </div>
    </div>
  </div>
{/if}

<!-- New Theme Modal -->
{#if showNewTheme}
  <div class="modal-overlay" onclick={() => { showNewTheme = false; }} role="dialog">
    <div class="modal" onclick={(e) => e.stopPropagation()}>
      <div class="modal-header">
        <h3 class="modal-title">New Theme</h3>
        <button class="modal-close" onclick={() => { showNewTheme = false; }}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Starting point</label>
          <div class="new-theme-options">
            <button
              class="new-theme-option"
              class:selected={newThemeMode === 'blank'}
              onclick={() => { newThemeMode = 'blank'; }}
            >
              <span class="new-theme-option-label">Blank theme</span>
              <span class="new-theme-option-desc">Start from scratch</span>
            </button>
            <button
              class="new-theme-option"
              class:selected={newThemeMode === 'duplicate'}
              onclick={() => { newThemeMode = 'duplicate'; }}
            >
              <span class="new-theme-option-label">Duplicate existing</span>
              <span class="new-theme-option-desc">Copy an existing theme</span>
            </button>
          </div>
        </div>
        {#if newThemeMode === 'duplicate'}
          <div class="form-group">
            <label class="form-label">Source theme</label>
            <select class="input" bind:value={newThemeSource}>
              {#each themesList as t}
                <option value={t.slug}>{t.name}</option>
              {/each}
            </select>
          </div>
        {/if}
        <div class="form-group">
          <label class="form-label">Theme name</label>
          <input
            class="input"
            type="text"
            bind:value={newThemeName}
            placeholder="My New Theme"
            onkeydown={(e) => { if (e.key === 'Enter') handleCreateTheme(); }}
          />
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" onclick={() => { showNewTheme = false; }}>Cancel</button>
        <button class="btn btn-primary" onclick={handleCreateTheme} disabled={creatingTheme || !newThemeName.trim()}>
          {creatingTheme ? 'Creating...' : 'Create & Open Editor'}
        </button>
      </div>
    </div>
  </div>
{/if}

<!-- Delete Confirm -->
{#if deleteTarget}
  <div class="modal-overlay" onclick={() => { deleteTarget = null; }} role="dialog">
    <div class="modal" onclick={(e) => e.stopPropagation()}>
      <div class="modal-header">
        <h3 class="modal-title">Delete Theme</h3>
        <button class="modal-close" onclick={() => { deleteTarget = null; }}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="modal-body">
        <p>Are you sure you want to delete <strong>{deleteTarget}</strong>? This cannot be undone.</p>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" onclick={() => { deleteTarget = null; }}>Cancel</button>
        <button class="btn btn-danger" onclick={handleDelete} disabled={deleting}>
          {deleting ? 'Deleting...' : 'Delete'}
        </button>
      </div>
    </div>
  </div>
{/if}

<style>
  .themes-page {
    max-width: var(--content-width-wide);
  }

  .themes-section {
    margin-bottom: var(--space-2xl);
  }

  .themes-section-label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-tertiary);
    margin-bottom: var(--space-md);
  }

  /* Active theme — prominent card */
  .active-theme {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--space-lg);
    padding: var(--space-xl);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    background: var(--bg-surface);
  }

  .active-theme-name {
    font-family: var(--font-display);
    font-size: 22px;
    font-weight: 600;
    color: var(--text-primary);
  }

  .active-theme-meta {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    font-size: var(--font-size-sm);
    color: var(--text-tertiary);
    margin-top: 2px;
  }

  .meta-dot::before {
    content: '\00B7';
    font-weight: 700;
  }

  .managed-label {
    color: var(--accent);
    font-weight: 500;
  }

  .active-theme-desc {
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    margin-top: var(--space-sm);
    line-height: 1.5;
  }

  .active-theme-actions {
    flex-shrink: 0;
  }

  /* Draft themes grid */
  .themes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: var(--space-lg);
  }

  .theme-card {
    display: flex;
    flex-direction: column;
    padding: var(--space-lg);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    background: var(--bg-surface);
    transition: border-color 0.15s;
  }

  .theme-card:hover {
    border-color: var(--border-color-strong);
  }

  .theme-card-body {
    flex: 1;
    min-height: 0;
  }

  .theme-card-name {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-primary);
  }

  .theme-card-meta {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    font-size: var(--font-size-xs);
    color: var(--text-tertiary);
    margin-top: 2px;
  }

  .theme-card-desc {
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    margin-top: var(--space-sm);
    line-height: 1.5;
  }

  .theme-card-actions {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin-top: var(--space-lg);
    padding-top: var(--space-md);
    border-top: 1px solid var(--border-color);
  }

  .theme-delete-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    margin-left: auto;
    background: none;
    border: none;
    border-radius: var(--radius-md);
    color: var(--text-tertiary);
    cursor: pointer;
    transition: color 0.15s, background 0.15s;
  }

  .theme-delete-btn:hover {
    color: var(--color-danger);
    background: var(--bg-hover);
  }

  .theme-delete-btn svg {
    width: 16px;
    height: 16px;
  }

  /* Screen-reader only utility for hidden file input */
  .sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
  }

  /* New theme modal options */
  .new-theme-options {
    display: flex;
    gap: var(--space-sm);
  }

  .new-theme-option {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: var(--space-md);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    background: var(--bg-surface);
    cursor: pointer;
    text-align: left;
    transition: border-color 0.15s;
  }

  .new-theme-option:hover {
    border-color: var(--border-color-strong);
  }

  .new-theme-option.selected {
    border-color: var(--accent);
  }

  .new-theme-option-label {
    font-size: var(--font-size-sm);
    font-weight: 600;
    color: var(--text-primary);
  }

  .new-theme-option-desc {
    font-size: var(--font-size-xs);
    color: var(--text-tertiary);
  }

  @media (max-width: 768px) {
    .themes-grid {
      grid-template-columns: 1fr;
    }
  }
</style>
