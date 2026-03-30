<script>
  import { pages, collections, items } from '../lib/api.js';

  let {
    value = $bindable(''),
    placeholder = 'Search pages or enter URL...',
    onchange = () => {},
  } = $props();

  let inputEl = $state(null);
  let query = $state(value || '');
  let open = $state(false);
  let results = $state([]);
  let loading = $state(false);
  let debounceTimer = $state(null);
  let collectionsList = $state([]);
  let collectionsLoaded = $state(false);

  // Sync query when value changes externally
  $effect(() => {
    query = value || '';
  });

  // Determine if current value is internal
  let isInternal = $derived(
    value && value.startsWith('/') && !value.startsWith('//')
  );

  async function loadCollections() {
    if (collectionsLoaded) return;
    try {
      const res = await collections.list();
      collectionsList = res.collections || [];
      collectionsLoaded = true;
    } catch {
      collectionsList = [];
    }
  }

  async function doSearch(q) {
    if (!q || q.length < 1) {
      results = [];
      return;
    }

    loading = true;
    const searchResults = [];

    try {
      // Search pages
      const pageRes = await pages.list(q);
      const pageList = pageRes.pages || [];
      for (const p of pageList.slice(0, 5)) {
        searchResults.push({
          type: 'page',
          title: p.title || p.path || 'Untitled',
          path: p.path || '/',
          label: p.path || '/',
        });
      }

      // Search collection items
      await loadCollections();
      for (const col of collectionsList.slice(0, 3)) {
        try {
          const itemRes = await items.list(col.slug);
          const itemList = itemRes.items || [];
          const matching = itemList.filter(item => {
            const title = item.title || item.slug || '';
            return title.toLowerCase().includes(q.toLowerCase());
          });
          for (const item of matching.slice(0, 3)) {
            const itemPath = `/${col.slug}/${item.slug}`;
            searchResults.push({
              type: 'item',
              title: item.title || item.slug,
              path: itemPath,
              label: `${col.name || col.slug} — ${itemPath}`,
            });
          }
        } catch {
          // skip failed collection
        }
      }
    } catch {
      // search failed silently
    }

    results = searchResults.slice(0, 8);
    loading = false;
  }

  function handleInputChange(e) {
    query = e.target.value;
    open = true;

    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
      doSearch(query);
    }, 300);
  }

  function selectResult(result) {
    query = result.path;
    value = result.path;
    onchange(result.path);
    open = false;
  }

  function selectCustom() {
    value = query;
    onchange(query);
    open = false;
  }

  function handleFocus() {
    if (query) {
      open = true;
      doSearch(query);
    }
  }

  function handleKeydown(e) {
    if (e.key === 'Escape') {
      open = false;
    } else if (e.key === 'Enter') {
      e.preventDefault();
      selectCustom();
    }
  }

  function handleBlur(e) {
    // Delay to allow click on dropdown item
    setTimeout(() => {
      open = false;
    }, 200);
  }
</script>

<div class="lp-wrap">
  <div class="lp-input-wrap">
    <div class="lp-icon">
      {#if isInternal}
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke-linecap="round" stroke-linejoin="round"/>
          <polyline points="14 2 14 8 20 8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      {:else}
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      {/if}
    </div>
    <input
      bind:this={inputEl}
      class="lp-input"
      type="text"
      value={query}
      oninput={handleInputChange}
      onfocus={handleFocus}
      onblur={handleBlur}
      onkeydown={handleKeydown}
      {placeholder}
    />
  </div>

  {#if open && (results.length > 0 || query.length > 0)}
    <div class="lp-dropdown">
      {#if loading}
        <div class="lp-loading">Searching...</div>
      {/if}

      {#each results as result}
        <button
          class="lp-option"
          type="button"
          onmousedown={() => selectResult(result)}
        >
          <span class="lp-option-icon">
            {#if result.type === 'page'}
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke-linecap="round" stroke-linejoin="round"/>
                <polyline points="14 2 14 8 20 8" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            {:else}
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <rect x="3" y="3" width="7" height="7" rx="1"/>
                <rect x="14" y="3" width="7" height="7" rx="1"/>
                <rect x="3" y="14" width="7" height="7" rx="1"/>
                <rect x="14" y="14" width="7" height="7" rx="1"/>
              </svg>
            {/if}
          </span>
          <span class="lp-option-text">
            <span class="lp-option-title">{result.title}</span>
            <span class="lp-option-path">{result.label}</span>
          </span>
        </button>
      {/each}

      {#if query && !loading}
        <button
          class="lp-option lp-option-custom"
          type="button"
          onmousedown={selectCustom}
        >
          <span class="lp-option-icon">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
              <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke-linecap="round" stroke-linejoin="round"/>
              <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </span>
          <span class="lp-option-text">
            <span class="lp-option-title">Use custom URL</span>
            <span class="lp-option-path">{query}</span>
          </span>
        </button>
      {/if}

      {#if !loading && results.length === 0 && query.length > 0}
        <div class="lp-empty">No pages found</div>
      {/if}
    </div>
  {/if}
</div>

<style>
  .lp-wrap {
    position: relative;
  }

  .lp-input-wrap {
    position: relative;
    display: flex;
    align-items: center;
  }

  .lp-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-tertiary, #9CA3AF);
    display: flex;
    align-items: center;
    pointer-events: none;
    z-index: 1;
  }

  .lp-input {
    width: 100%;
    padding: 10px 14px 10px 34px;
    border: 1px solid transparent;
    border-radius: 8px;
    background: var(--bg-tertiary, #F3F4F6);
    font-size: 15px;
    color: var(--text-primary, #111);
    font-family: var(--font-sans, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif);
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
  }

  .lp-input:hover {
    border-color: var(--border-secondary, #E5E7EB);
  }

  .lp-input:focus {
    border-color: var(--accent, #2D5A47);
    box-shadow: 0 0 0 3px var(--accent-soft, rgba(45, 90, 71, 0.1));
  }

  .lp-input::placeholder {
    color: var(--text-light, #D1D5DB);
  }

  .lp-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: var(--bg-primary, #fff);
    border: 1px solid var(--border-secondary, #E5E7EB);
    border-radius: 10px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
    z-index: 100;
    overflow: hidden;
    max-height: 320px;
    overflow-y: auto;
  }

  .lp-option {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 10px 14px;
    border: none;
    background: none;
    cursor: pointer;
    text-align: left;
    font-family: var(--font-sans, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif);
    transition: background 0.1s;
  }

  .lp-option:hover {
    background: var(--bg-secondary, #F9FAFB);
  }

  .lp-option-custom {
    border-top: 1px solid var(--border-secondary, #E5E7EB);
  }

  .lp-option-icon {
    flex-shrink: 0;
    color: var(--text-tertiary, #9CA3AF);
    display: flex;
    align-items: center;
  }

  .lp-option-text {
    display: flex;
    flex-direction: column;
    gap: 1px;
    min-width: 0;
  }

  .lp-option-title {
    font-size: 14px;
    color: var(--text-primary, #111);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .lp-option-path {
    font-size: 12px;
    color: var(--text-tertiary, #9CA3AF);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .lp-loading,
  .lp-empty {
    padding: 12px 14px;
    font-size: 13px;
    color: var(--text-tertiary, #9CA3AF);
  }
</style>
