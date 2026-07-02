<script>
  import { onMount } from 'svelte';
  import { X, Search, Loader2, ExternalLink } from 'lucide-svelte';
  import { stock as stockApi } from '$lib/api.js';

  let { onclose, onselect } = $props();

  const LABELS = { pexels: 'Pexels', unsplash: 'Unsplash' };

  let providers = $state([]);
  let provider = $state('');
  let query = $state('');
  let results = $state([]);
  let page = $state(1);
  let hasMore = $state(false);
  let loading = $state(false);
  let loadingMore = $state(false);
  let importingId = $state(null);
  let error = $state('');
  let ready = $state(false);
  let inputEl = $state(null);

  let configured = $derived(providers.filter((p) => p.configured));

  onMount(async () => {
    try {
      const data = await stockApi.providers();
      providers = data.providers || [];
      provider = (configured[0] || {}).id || '';
    } catch (e) {
      error = e?.message || 'Could not load providers.';
    }
    ready = true;
    inputEl?.focus();
  });

  async function run(reset = true) {
    if (!provider || !query.trim()) return;
    if (reset) { page = 1; results = []; loading = true; } else { loadingMore = true; }
    error = '';
    try {
      const data = await stockApi.search(provider, query.trim(), page);
      results = reset ? (data.results || []) : [...results, ...(data.results || [])];
      hasMore = !!data.has_more;
    } catch (e) {
      error = e?.message || 'Search failed.';
    } finally {
      loading = false;
      loadingMore = false;
    }
  }

  function switchProvider(id) {
    if (id === provider) return;
    provider = id;
    results = [];
    if (query.trim()) run(true);
  }

  async function loadMore() {
    if (!hasMore || loadingMore) return;
    page += 1;
    await run(false);
  }

  async function choose(item) {
    if (importingId) return;
    importingId = item.id;
    error = '';
    try {
      const res = await stockApi.import(item.provider, item.id, item.alt || '');
      onselect?.(res);
      onclose?.();
    } catch (e) {
      error = e?.message || 'Could not use that photo.';
    } finally {
      importingId = null;
    }
  }

  function onKey(e) {
    if (e.key === 'Escape') { e.stopPropagation(); onclose?.(); }
  }
</script>

<div class="overlay" role="presentation" onclick={(e) => { if (e.target === e.currentTarget) onclose?.(); }}>
  <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="sp-title" onkeydown={onKey}>
    <header class="head">
      <h2 id="sp-title">Stock photos</h2>
      {#if configured.length > 1}
        <div class="providers" role="group" aria-label="Photo source">
          {#each configured as p (p.id)}
            <button class="prov" class:on={provider === p.id} onclick={() => switchProvider(p.id)} aria-pressed={provider === p.id}>{LABELS[p.id] || p.id}</button>
          {/each}
        </div>
      {/if}
      <button class="close" onclick={() => onclose?.()} aria-label="Close"><X size={18} aria-hidden="true" /></button>
    </header>

    {#if ready && configured.length === 0}
      <div class="empty">
        <p>No stock photo provider is connected yet.</p>
        <p class="dim">Add a Pexels or Unsplash API key in <strong>Settings → Integrations</strong> to search photos here.</p>
      </div>
    {:else}
      <form class="searchbar" onsubmit={(e) => { e.preventDefault(); run(true); }}>
        <Search size={16} aria-hidden="true" />
        <input
          bind:this={inputEl}
          bind:value={query}
          type="search"
          placeholder="Search {LABELS[provider] || 'photos'}…"
          aria-label="Search photos"
        />
        <button class="go" type="submit" disabled={!query.trim() || loading}>Search</button>
      </form>

      {#if error}
        <p class="error" role="alert">{error}</p>
      {/if}

      <div class="body">
        {#if loading}
          <div class="center"><Loader2 size={22} class="spin" aria-hidden="true" /></div>
        {:else if results.length === 0 && query.trim()}
          <p class="center dim">No photos found.</p>
        {:else if results.length === 0}
          <p class="center dim">Search {LABELS[provider] || 'photos'} to get started.</p>
        {:else}
          <ul class="grid">
            {#each results as item (item.provider + item.id)}
              <li>
                <button class="tile" onclick={() => choose(item)} disabled={!!importingId} title={item.alt || 'Insert photo'}>
                  <img src={item.thumb} alt={item.alt || ''} loading="lazy" style:aspect-ratio={item.width && item.height ? `${item.width}/${item.height}` : '4/3'} />
                  {#if importingId === item.id}
                    <span class="tile-busy"><Loader2 size={18} class="spin" aria-hidden="true" /></span>
                  {/if}
                  <span class="credit">{item.author}</span>
                </button>
              </li>
            {/each}
          </ul>
          {#if hasMore}
            <div class="more">
              <button onclick={loadMore} disabled={loadingMore}>{loadingMore ? 'Loading…' : 'Load more'}</button>
            </div>
          {/if}
        {/if}
      </div>

      <footer class="foot">
        <span class="attr">
          Photos from <a href="https://www.pexels.com" target="_blank" rel="noopener">Pexels</a>
          and <a href="https://unsplash.com" target="_blank" rel="noopener">Unsplash <ExternalLink size={11} aria-hidden="true" /></a>. Please credit the photographer.
        </span>
      </footer>
    {/if}
  </div>
</div>

<style>
  .overlay {
    position: fixed;
    inset: 0;
    z-index: 210;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(0, 0, 0, 0.5);
  }
  .dialog {
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 860px;
    max-height: 88vh;
    background: var(--raised);
    border: 1px solid var(--border);
    border-radius: 14px;
    box-shadow: var(--shadow-lg, 0 24px 60px rgba(0, 0, 0, 0.35));
    overflow: hidden;
  }
  .head {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 18px;
    border-bottom: 1px solid var(--border);
  }
  .head h2 { margin: 0; font-size: 16px; font-weight: 600; color: var(--text); }
  .providers { display: inline-flex; gap: 2px; background: var(--hover); border-radius: 8px; padding: 2px; }
  .prov {
    padding: 5px 12px;
    border: none;
    border-radius: 6px;
    background: transparent;
    color: var(--sec);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
  }
  .prov.on { background: var(--raised); color: var(--text); }
  .prov:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
  .close {
    margin-left: auto;
    display: inline-flex;
    padding: 6px;
    border: none;
    border-radius: 7px;
    background: transparent;
    color: var(--sec);
    cursor: pointer;
  }
  .close:hover { background: var(--hover); color: var(--text); }
  .close:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .searchbar {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 14px 18px 0;
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--bg);
    color: var(--sec);
  }
  .searchbar:focus-within { border-color: var(--purple); }
  .searchbar input {
    flex: 1;
    min-width: 0;
    border: none;
    background: transparent;
    color: var(--text);
    font-size: 14px;
    font-family: inherit;
  }
  .searchbar input:focus { outline: none; }
  .go {
    padding: 7px 14px;
    border: none;
    border-radius: 8px;
    background: var(--purple);
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
  }
  .go:disabled { opacity: 0.4; cursor: default; }
  .go:focus-visible { outline: 2px solid var(--purple-soft, var(--purple)); outline-offset: 2px; }

  .error { margin: 12px 18px 0; padding: 9px 12px; border-radius: 8px; background: var(--red-bg, rgba(220,60,60,0.12)); color: var(--red, #d64545); font-size: 12.5px; }

  .body { flex: 1; min-height: 0; overflow-y: auto; padding: 14px 18px; }
  .center { display: flex; align-items: center; justify-content: center; padding: 48px 0; color: var(--dim); }
  .dim { color: var(--dim); font-size: 13px; }

  .grid {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 10px;
  }
  .tile {
    position: relative;
    display: block;
    width: 100%;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 8px;
    overflow: hidden;
    background: var(--hover);
    cursor: pointer;
  }
  .tile:hover:not(:disabled) { border-color: var(--purple); }
  .tile:disabled { opacity: 0.7; cursor: default; }
  .tile:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
  .tile img { display: block; width: 100%; height: auto; object-fit: cover; }
  .tile-busy {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.35);
    color: #fff;
  }
  .credit {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    padding: 12px 8px 5px;
    font-size: 11px;
    color: #fff;
    text-align: left;
    background: linear-gradient(transparent, rgba(0, 0, 0, 0.6));
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .more { display: flex; justify-content: center; padding: 16px 0 4px; }
  .more button {
    padding: 8px 16px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: transparent;
    color: var(--sec);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
  }
  .more button:hover:not(:disabled) { background: var(--hover); color: var(--text); }

  .empty { padding: 48px 24px; text-align: center; }
  .empty p { margin: 0 0 6px; font-size: 14px; color: var(--text); }
  .empty .dim { font-size: 13px; }

  .foot { padding: 10px 18px; border-top: 1px solid var(--border); }
  .attr { font-size: 11.5px; color: var(--dim); }
  .attr a { color: var(--sec); text-decoration: none; }
  .attr a:hover { color: var(--text); text-decoration: underline; }

  :global(.spin) { animation: sp-spin 0.8s linear infinite; }
  @keyframes sp-spin { to { transform: rotate(360deg); } }
</style>
