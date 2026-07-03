<script>
  import { onMount } from 'svelte';
  import { pages as pagesApi } from '$lib/api.js';
  import { navigate, currentPageId, addToast } from '$lib/stores.js';
  import { FileText, Plus, Lock, Search, FileCode, Trash2, Check, X } from 'lucide-svelte';

  let loading = $state(true);
  let pages = $state([]);
  let search = $state('');
  let confirmingId = $state(null);
  let deletingId = $state(null);

  const HIDDEN = new Set(['__global__', '/sync-api']);

  let filtered = $derived.by(() => {
    const q = search.trim().toLowerCase();
    return pages
      .filter((p) => !HIDDEN.has(p.path))
      .filter((p) => !q || (p.title || '').toLowerCase().includes(q) || (p.path || '').toLowerCase().includes(q));
  });

  async function refresh() {
    loading = true;
    try {
      const res = await pagesApi.list();
      pages = res.pages || [];
    } catch (e) {
      addToast(e.message || 'Failed to load pages', 'error');
    } finally {
      loading = false;
    }
  }

  onMount(refresh);

  function open(page) {
    currentPageId.set(page.id);
    navigate('node-builder', { pageId: page.id });
  }

  function createPage() {
    navigate('page-new');
  }

  async function remove(page) {
    deletingId = page.id;
    try {
      await pagesApi.delete(page.id);
      pages = pages.filter((p) => p.id !== page.id);
      addToast('Page deleted', 'success');
    } catch (e) {
      addToast(e.message || 'Could not delete page', 'error');
    } finally {
      deletingId = null;
      confirmingId = null;
    }
  }
</script>

<div class="page">
  <div class="page-header">
    <div class="ph-icon"><FileText size={20} aria-hidden="true" /></div>
    <div class="ph-text">
      <h1 class="page-title">Pages</h1>
      <p class="page-subtitle">Standalone pages, built in the visual editor.</p>
    </div>
    <div class="page-header-actions">
      <button class="btn btn-secondary" onclick={() => navigate('page-import')}>
        <FileCode size={16} aria-hidden="true" />
        <span>Import</span>
      </button>
      <button class="btn btn-primary" onclick={createPage}>
        <Plus size={16} aria-hidden="true" />
        <span>New page</span>
      </button>
    </div>
  </div>

  <div class="search">
    <Search size={16} aria-hidden="true" />
    <input type="text" placeholder="Search pages" bind:value={search} aria-label="Search pages" />
  </div>

  {#if loading}
    <div class="muted">Loading…</div>
  {:else if filtered.length === 0}
    <div class="empty">
      {#if search}No pages match “{search}”.{:else}No pages yet. Create your first one.{/if}
    </div>
  {:else}
    <ul class="list">
      {#each filtered as page (page.id)}
        <li class="row">
          <button class="row-open" onclick={() => open(page)}>
            <FileText size={17} aria-hidden="true" />
            <span class="title">{page.title || page.path}</span>
            <span class="path">{page.path}</span>
            {#if page.status && page.status !== 'published'}
              <span class="badge">{page.status}</span>
            {/if}
            {#if page.visibility && page.visibility !== 'public'}
              <Lock size={13} aria-hidden="true" class="lock" />
            {/if}
          </button>
          {#if page.path !== '/'}
            <div class="row-actions">
              {#if confirmingId === page.id}
                <button class="act danger" onclick={() => remove(page)} disabled={deletingId === page.id} aria-label="Confirm delete">
                  <Check size={15} aria-hidden="true" />
                </button>
                <button class="act" onclick={() => (confirmingId = null)} aria-label="Cancel delete">
                  <X size={15} aria-hidden="true" />
                </button>
              {:else}
                <button class="act" onclick={() => (confirmingId = page.id)} aria-label={`Delete ${page.title || page.path}`} title="Delete">
                  <Trash2 size={15} aria-hidden="true" />
                </button>
              {/if}
            </div>
          {/if}
        </li>
      {/each}
    </ul>
  {/if}
</div>

<style>
  .page { max-width: var(--content-width, 900px); margin: 0 auto; padding: 32px 24px 80px; }

  .page-header { display: flex; align-items: center; gap: 14px; margin-bottom: 24px; }
  .ph-icon { display: inline-flex; color: var(--sec); }
  .ph-text { flex: 1; min-width: 0; }
  .page-title { font-size: 22px; font-weight: 700; color: var(--text); margin: 0; }
  .page-subtitle { font-size: 13px; color: var(--dim); margin: 2px 0 0; }

  .search {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 12px;
    border: 1px solid transparent;
    border-radius: 9px;
    background: var(--raised);
    color: var(--dim);
    margin-bottom: 16px;
  }
  .search:focus-within { border-color: var(--purple); }
  .search input { flex: 1; border: none; background: none; color: var(--text); font-size: 14px; padding: 11px 0; outline: none; }

  .list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 2px; }

  .row {
    display: flex;
    align-items: center;
    gap: 4px;
    border-radius: 9px;
    background: var(--raised);
  }
  .row:hover { background: var(--hover); }
  .row:hover .row-actions { opacity: 1; }

  .row-open {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    border: none;
    border-radius: 9px;
    background: none;
    color: var(--sec);
    font-size: 14px;
    text-align: left;
    cursor: pointer;
  }
  .row-open:hover { color: var(--text); }
  .row-open:focus-visible { outline: 2px solid var(--purple); outline-offset: -2px; }

  .title { color: var(--text); font-weight: 500; }
  .path { color: var(--dim); font-size: 13px; font-family: var(--font-mono, ui-monospace, monospace); margin-left: 4px; }

  .badge {
    margin-left: auto;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--amber);
    background: var(--amber-bg);
    padding: 2px 7px;
    border-radius: 5px;
  }
  .row-open :global(.lock) { margin-left: auto; color: var(--dim); }
  .badge + :global(.lock) { margin-left: 8px; }

  .row-actions { display: flex; gap: 2px; padding-right: 8px; opacity: 0; flex-shrink: 0; }
  .row-actions:focus-within { opacity: 1; }
  .act {
    display: inline-flex;
    padding: 7px;
    border: none;
    border-radius: 7px;
    background: transparent;
    color: var(--dim);
    cursor: pointer;
  }
  .act:hover { background: var(--bg-active); color: var(--text); }
  .act.danger:hover { color: var(--red); }
  .act:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; opacity: 1; }
  .act:disabled { opacity: 0.4; cursor: default; }

  .muted, .empty { color: var(--dim); font-size: 14px; padding: 24px 4px; }
</style>
