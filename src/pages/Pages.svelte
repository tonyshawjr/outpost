<script>
  import { onMount } from 'svelte';
  import { pages as pagesApi } from '$lib/api.js';
  import { pagesList, navigate, addToast } from '$lib/stores.js';
  import { timeAgo } from '$lib/utils.js';

  let search = $state('');
  let loading = $state(true);
  let items = $derived($pagesList);

  let filtered = $derived(
    search
      ? items.filter(
          (p) =>
            p.path.toLowerCase().includes(search.toLowerCase()) ||
            p.title.toLowerCase().includes(search.toLowerCase())
        )
      : items
  );

  onMount(() => {
    loadPages();
  });

  async function loadPages() {
    loading = true;
    try {
      const data = await pagesApi.list();
      pagesList.set(data.pages || []);
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  function editPage(page) {
    navigate('page-editor', { pageId: page.id });
  }

  async function toggleStatus(e, page) {
    e.stopPropagation();
    const newStatus = (page.status || 'published') === 'published' ? 'draft' : 'published';
    try {
      await pagesApi.update(page.id, { status: newStatus });
      pagesList.update(list => list.map(p => p.id === page.id ? { ...p, status: newStatus } : p));
    } catch (err) {
      addToast(err.message, 'error');
    }
  }
</script>

<div class="ghost-list-view">
  <!-- Page Header -->
  <div class="page-header">
    <div class="page-header-icon green">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    </div>
    <div class="page-header-content">
      <h1 class="page-title">Pages</h1>
      <p class="page-subtitle">{items.length} {items.length === 1 ? 'page' : 'pages'}</p>
    </div>
    <div class="page-header-actions">
      <div class="search-input">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input
          class="input"
          type="text"
          placeholder="Search pages..."
          bind:value={search}
        />
      </div>
    </div>
  </div>

  {#if loading}
    <div class="loading-overlay">
      <div class="spinner"></div>
    </div>
  {:else if filtered.length === 0}
    <div class="list-empty-state">
      <div class="list-empty-icon">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      </div>
      <div class="list-empty-title">
        {search ? 'No pages match your search' : 'No pages discovered yet'}
      </div>
      <p class="list-empty-desc">
        {search ? 'Try a different search term' : 'Add CMS tags to your pages and visit them to auto-discover'}
      </p>
    </div>
  {:else}
    <!-- Column Headers -->
    <div class="list-col-headers">
      <span>TITLE</span>
      <span style="margin-left:auto;">STATUS</span>
      <span style="min-width:90px;text-align:right;">LAST UPDATED</span>
    </div>

    <!-- Rows -->
    <div class="list-rows">
      {#each filtered as page (page.id)}
        <div
          class="list-row"
          onclick={() => editPage(page)}
          role="button"
          tabindex="0"
          onkeydown={(e) => e.key === 'Enter' && editPage(page)}
        >
          <div class="list-row-left">
            <div class="list-row-title">
              {page.title || 'Untitled'}
              {#if page.visibility === 'members' || page.visibility === 'paid'}
                <svg class="list-row-lock" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
              {/if}
            </div>
            <div class="list-row-subtitle">{page.path}</div>
          </div>
          <div class="list-row-right">
            <button
              class="status-badge"
              class:status-published={(page.status || 'published') === 'published'}
              class:status-draft={page.status === 'draft'}
              onclick={(e) => toggleStatus(e, page)}
              title="Click to toggle draft / published"
            >
              {page.status || 'published'}
            </button>
            <span class="list-row-time">{timeAgo(page.updated_at)}</span>
          </div>
        </div>
      {/each}
    </div>
  {/if}
</div>

<style>
  .ghost-list-view {
    max-width: var(--content-width);
  }

  /* ── Column Headers ───────────────────────────────── */
  .list-col-headers {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-sm) var(--space-lg);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.05em;
    color: var(--text-tertiary);
    border-bottom: 1px solid var(--border-primary);
    user-select: none;
  }

  /* ── Rows ─────────────────────────────────────────── */
  .list-rows {
    display: flex;
    flex-direction: column;
  }

  .list-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-md) var(--space-lg);
    cursor: pointer;
    transition: background var(--transition-fast);
    border-radius: 0;
  }

  .list-row:hover {
    background: var(--bg-hover);
  }

  .list-row-left {
    flex: 1;
    min-width: 0;
  }

  .list-row-title {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
    font-size: 15px;
    color: var(--text-primary);
    line-height: 1.3;
  }

  .list-row-lock {
    color: var(--text-tertiary);
    flex-shrink: 0;
  }

  .list-row-subtitle {
    font-size: var(--font-size-sm);
    color: var(--text-tertiary);
    font-family: var(--font-mono);
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 600px;
  }

  .list-row-right {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    flex-shrink: 0;
    margin-left: var(--space-xl);
  }

  .list-row-time {
    font-size: var(--font-size-xs);
    color: var(--text-tertiary);
    white-space: nowrap;
  }

  /* ── Empty State ──────────────────────────────────── */
  .list-empty-state {
    text-align: center;
    padding: var(--space-3xl) var(--space-xl);
  }

  .list-empty-icon {
    color: var(--text-tertiary);
    opacity: 0.4;
    margin-bottom: var(--space-lg);
  }

  .list-empty-title {
    font-family: var(--font-serif);
    font-size: var(--font-size-lg);
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: var(--space-xs);
  }

  .list-empty-desc {
    font-size: var(--font-size-sm);
    color: var(--text-tertiary);
    margin: 0;
  }

  /* ── Search in header ─────────────────────────────── */
  .page-header-actions .search-input {
    min-width: 220px;
  }

  .page-header-actions .search-input .input {
    font-size: var(--font-size-sm);
  }

  /* ── Status badge ─────────────────────────────────── */
  .status-badge {
    display: inline-flex;
    align-items: center;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 3px 10px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    transition: opacity var(--transition-fast);
    line-height: 1.4;
    flex-shrink: 0;
  }
  .status-badge:hover { opacity: 0.75; }

  .status-published {
    background: rgba(91, 140, 90, 0.15);
    color: var(--success);
  }

  .status-draft {
    background: rgba(196, 154, 61, 0.15);
    color: var(--warning);
  }

  @media (max-width: 768px) {
    .list-row-subtitle {
      max-width: 100%;
      word-break: break-all;
    }

    .page-header-actions {
      flex-wrap: wrap;
    }

    .page-header-actions .search-input {
      min-width: 0;
      width: 100%;
    }

    .list-col-headers {
      display: none;
    }

    .list-row {
      flex-wrap: wrap;
    }

    .list-row-right {
      margin-left: 0;
      flex-wrap: wrap;
      gap: var(--space-sm);
    }
  }
</style>
