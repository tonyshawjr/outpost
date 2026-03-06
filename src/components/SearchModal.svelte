<script>
  import { search } from '$lib/api.js';
  import { searchOpen } from '$lib/stores.js';
  import { navigate } from '$lib/stores.js';

  let query = $state('');
  let results = $state([]);
  let selectedIdx = $state(-1);
  let loading = $state(false);
  let open = $derived($searchOpen);

  let debounceTimer;

  $effect(() => {
    if (!open) {
      query = '';
      results = [];
      selectedIdx = -1;
    }
  });

  $effect(() => {
    clearTimeout(debounceTimer);
    if (query.length < 2) {
      results = [];
      selectedIdx = -1;
      return;
    }
    loading = true;
    debounceTimer = setTimeout(async () => {
      try {
        const data = await search.content(query);
        results = data.results || [];
        selectedIdx = results.length > 0 ? 0 : -1;
      } catch (e) {
        results = [];
      } finally {
        loading = false;
      }
    }, 300);
  });

  function close() {
    searchOpen.set(false);
  }

  function goToResult(r) {
    if (r.type === 'page') {
      navigate('page-editor', { pageId: r.id });
    } else if (r.type === 'item') {
      navigate('collection-editor', { itemId: r.id, collectionSlug: r.meta.collection_slug });
    } else if (r.type === 'media') {
      navigate('media');
    }
    close();
  }

  function handleKeydown(e) {
    if (!open) return;
    if (e.key === 'Escape') { close(); return; }
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      selectedIdx = Math.min(selectedIdx + 1, results.length - 1);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      selectedIdx = Math.max(selectedIdx - 1, 0);
    } else if (e.key === 'Enter' && selectedIdx >= 0) {
      e.preventDefault();
      goToResult(results[selectedIdx]);
    }
  }

  // Group results by type
  let grouped = $derived(() => {
    const pages = results.filter(r => r.type === 'page');
    const items = results.filter(r => r.type === 'item');
    const media = results.filter(r => r.type === 'media');
    return { pages, items, media };
  });

  // Flat index mapping for keyboard nav
  function flatIndex(r) {
    return results.indexOf(r);
  }
</script>

<svelte:window onkeydown={handleKeydown} />

{#if open}
  <!-- svelte-ignore a11y_click_events_have_key_events -->
  <!-- svelte-ignore a11y_no_static_element_interactions -->
  <div class="search-backdrop" onclick={close}>
    <!-- svelte-ignore a11y_click_events_have_key_events -->
    <!-- svelte-ignore a11y_no_static_element_interactions -->
    <div class="search-modal" onclick={(e) => e.stopPropagation()}>
      <div class="search-input-row">
        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <!-- svelte-ignore a11y_autofocus -->
        <input
          class="search-input"
          type="text"
          placeholder="Search pages, posts, media..."
          bind:value={query}
          autofocus
        />
        {#if loading}
          <div class="search-spinner"></div>
        {/if}
      </div>

      {#if results.length > 0}
        <div class="search-results">
          {#if grouped().pages.length > 0}
            <div class="search-group-label">Pages</div>
            {#each grouped().pages as r}
              {@const idx = flatIndex(r)}
              <button
                class="search-result"
                class:selected={idx === selectedIdx}
                onclick={() => goToResult(r)}
                onmouseenter={() => selectedIdx = idx}
              >
                <svg class="result-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                  <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                  <polyline points="14 2 14 8 20 8"/>
                </svg>
                <span class="result-title">{r.title}</span>
                <span class="result-subtitle">{r.subtitle}</span>
              </button>
            {/each}
          {/if}

          {#if grouped().items.length > 0}
            <div class="search-group-label">Collection Items</div>
            {#each grouped().items as r}
              {@const idx = flatIndex(r)}
              <button
                class="search-result"
                class:selected={idx === selectedIdx}
                onclick={() => goToResult(r)}
                onmouseenter={() => selectedIdx = idx}
              >
                <svg class="result-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                  <path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>
                </svg>
                <span class="result-title">{r.title}</span>
                <span class="result-subtitle">{r.subtitle}</span>
                {#if r.status && r.status !== 'published'}
                  <span class="result-status">{r.status}</span>
                {/if}
              </button>
            {/each}
          {/if}

          {#if grouped().media.length > 0}
            <div class="search-group-label">Media</div>
            {#each grouped().media as r}
              {@const idx = flatIndex(r)}
              <button
                class="search-result"
                class:selected={idx === selectedIdx}
                onclick={() => goToResult(r)}
                onmouseenter={() => selectedIdx = idx}
              >
                <svg class="result-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                  <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                  <circle cx="8.5" cy="8.5" r="1.5"/>
                  <polyline points="21 15 16 10 5 21"/>
                </svg>
                <span class="result-title">{r.title}</span>
                <span class="result-subtitle">{r.subtitle}</span>
              </button>
            {/each}
          {/if}
        </div>
      {:else if query.length >= 2 && !loading}
        <div class="search-empty">No results for "{query}"</div>
      {/if}
    </div>
  </div>
{/if}

<style>
  .search-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.4);
    z-index: 1000;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding-top: 80px;
  }

  .search-modal {
    width: 100%;
    max-width: 560px;
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
  }

  .search-input-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 16px;
    border-bottom: 1px solid var(--border-color);
  }

  .search-icon {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    color: var(--text-muted);
  }

  .search-input {
    flex: 1;
    border: none;
    background: none;
    outline: none;
    font-size: 15px;
    color: var(--text-primary);
    font-family: inherit;
  }

  .search-input::placeholder {
    color: var(--text-muted);
  }

  .search-spinner {
    width: 14px;
    height: 14px;
    border: 2px solid var(--border-color);
    border-top-color: var(--text-muted);
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
    flex-shrink: 0;
  }

  @keyframes spin {
    to { transform: rotate(360deg); }
  }

  .search-results {
    padding: 8px 0;
    max-height: 400px;
    overflow-y: auto;
  }

  .search-group-label {
    font-size: var(--font-size-xs, 11px);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted);
    padding: 8px 16px 4px;
  }

  .search-result {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 8px 16px;
    background: none;
    border: none;
    text-align: left;
    cursor: pointer;
    transition: background 0.1s;
    color: var(--text-primary);
    font-size: 14px;
  }

  .search-result:hover,
  .search-result.selected {
    background: var(--bg-hover);
  }

  .result-icon {
    width: 14px;
    height: 14px;
    flex-shrink: 0;
    color: var(--text-muted);
  }

  .result-title {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .result-subtitle {
    flex-shrink: 0;
    font-size: 12px;
    color: var(--text-muted);
    max-width: 160px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .result-status {
    flex-shrink: 0;
    font-size: 11px;
    color: var(--text-muted);
    text-transform: capitalize;
  }

  .search-empty {
    padding: 24px 16px;
    text-align: center;
    color: var(--text-muted);
    font-size: 14px;
  }

  @media (max-width: 768px) {
    .search-modal {
      max-width: 100vw;
      width: 100vw;
      height: 100vh;
      max-height: 100vh;
      border-radius: 0;
      border: none;
      display: flex;
      flex-direction: column;
    }

    .search-results {
      flex: 1;
      max-height: none;
    }
  }
</style>
