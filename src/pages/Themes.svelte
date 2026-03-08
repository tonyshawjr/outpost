<script>
  import { onMount } from 'svelte';
  import { themes as themesApi } from '$lib/api.js';
  import { addToast, navigate } from '$lib/stores.js';

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
  </div>

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
              <button class="theme-delete-btn" onclick={() => { deleteTarget = theme.slug; }}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
              </button>
            </div>
          </div>
        {/each}
      </div>
    </div>
  {/if}

  {#if themesList.length === 0}
    <div class="empty-state">
      <p>No themes found. Create a theme directory with a <code>theme.json</code> manifest.</p>
    </div>
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

  .empty-state {
    text-align: center;
    padding: var(--space-3xl);
    color: var(--text-tertiary);
  }

  .empty-state code {
    font-size: var(--font-size-sm);
    background: var(--bg-hover);
    padding: 2px 6px;
    border-radius: var(--radius-sm);
  }

  @media (max-width: 768px) {
    .themes-grid {
      grid-template-columns: 1fr;
    }
  }
</style>
