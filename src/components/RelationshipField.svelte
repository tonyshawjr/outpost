<script>
  import { items as itemsApi } from '$lib/api.js';

  let {
    collection = '',
    multiple = true,
    max = 0,
    value = '[]',
    onchange = () => {},
  } = $props();

  let allItems = $state([]);
  let loading = $state(false);
  let searchQuery = $state('');
  let selectedIds = $state([]);
  let showResults = $state(false);
  let debounceTimer = $state(null);

  // Parse value into selectedIds
  $effect(() => {
    try {
      const parsed = typeof value === 'string' ? JSON.parse(value) : value;
      if (Array.isArray(parsed)) {
        selectedIds = parsed.map(Number);
      } else if (parsed && !isNaN(Number(parsed))) {
        selectedIds = [Number(parsed)];
      } else {
        selectedIds = [];
      }
    } catch {
      selectedIds = [];
    }
  });

  // Fetch items when collection changes
  $effect(() => {
    if (collection) {
      fetchItems();
    }
  });

  async function fetchItems() {
    loading = true;
    try {
      const res = await itemsApi.list(collection, 'published');
      allItems = res.items || [];
    } catch (e) {
      console.error('RelationshipField: failed to fetch items', e);
      allItems = [];
    } finally {
      loading = false;
    }
  }

  let filteredResults = $derived(() => {
    if (!searchQuery.trim()) return allItems;
    const q = searchQuery.toLowerCase();
    return allItems.filter((item) => {
      const title = item.data?.title || item.slug || '';
      return title.toLowerCase().includes(q);
    });
  });

  let availableResults = $derived(() => {
    return filteredResults().filter((item) => !selectedIds.includes(item.id));
  });

  let selectedItems = $derived(() => {
    return selectedIds
      .map((id) => allItems.find((item) => item.id === id))
      .filter(Boolean);
  });

  let maxReached = $derived(max > 0 && selectedIds.length >= max);

  function handleSearchInput(e) {
    const val = e.target.value;
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
      searchQuery = val;
    }, 300);
  }

  function selectItem(item) {
    if (maxReached) return;

    let newIds;
    if (multiple) {
      if (selectedIds.includes(item.id)) return;
      newIds = [...selectedIds, item.id];
    } else {
      newIds = [item.id];
    }
    selectedIds = newIds;
    emitChange(newIds);
    searchQuery = '';
    showResults = false;
  }

  function removeItem(id) {
    const newIds = selectedIds.filter((sid) => sid !== id);
    selectedIds = newIds;
    emitChange(newIds);
  }

  function moveUp(index) {
    if (index <= 0) return;
    const newIds = [...selectedIds];
    [newIds[index - 1], newIds[index]] = [newIds[index], newIds[index - 1]];
    selectedIds = newIds;
    emitChange(newIds);
  }

  function moveDown(index) {
    if (index >= selectedIds.length - 1) return;
    const newIds = [...selectedIds];
    [newIds[index], newIds[index + 1]] = [newIds[index + 1], newIds[index]];
    selectedIds = newIds;
    emitChange(newIds);
  }

  function emitChange(ids) {
    if (multiple) {
      onchange(JSON.stringify(ids));
    } else {
      onchange(ids.length > 0 ? String(ids[0]) : '');
    }
  }

  function handleFocus() {
    showResults = true;
  }

  function handleBlur() {
    // Delay to allow click on results
    setTimeout(() => {
      showResults = false;
    }, 200);
  }
</script>

<div class="rf">
  {#if loading}
    <div class="rf-loading">Loading...</div>
  {:else}
    <!-- Search input -->
    {#if maxReached}
      <div class="rf-max-msg">max {max} reached</div>
    {:else}
      <div class="rf-search-wrap">
        <svg class="rf-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input
          class="rf-search"
          type="text"
          placeholder="Search {collection}..."
          value={searchQuery}
          oninput={handleSearchInput}
          onfocus={handleFocus}
          onblur={handleBlur}
        />
      </div>

      <!-- Search results dropdown -->
      {#if showResults && availableResults().length > 0}
        <div class="rf-dropdown">
          {#each availableResults() as item (item.id)}
            <button
              class="rf-result"
              onmousedown={(e) => { e.preventDefault(); selectItem(item); }}
            >
              <span class="rf-result-title">{item.data?.title || item.slug}</span>
              <span class="rf-result-slug">{item.slug}</span>
            </button>
          {/each}
        </div>
      {/if}
    {/if}

    <!-- Selected items -->
    {#if selectedItems().length > 0}
      <div class="rf-selected">
        {#each selectedItems() as item, i (item.id)}
          <div class="rf-item">
            {#if multiple && selectedIds.length > 1}
              <div class="rf-reorder">
                <button
                  class="rf-arrow"
                  onclick={() => moveUp(i)}
                  disabled={i === 0}
                  title="Move up"
                >
                  <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg>
                </button>
                <button
                  class="rf-arrow"
                  onclick={() => moveDown(i)}
                  disabled={i === selectedIds.length - 1}
                  title="Move down"
                >
                  <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
              </div>
            {/if}
            <div class="rf-item-info">
              <span class="rf-item-title">{item.data?.title || item.slug}</span>
              <span class="rf-item-slug">{item.slug}</span>
            </div>
            <button
              class="rf-remove"
              onclick={() => removeItem(item.id)}
              title="Remove"
            >
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
        {/each}
      </div>
    {:else}
      <div class="rf-empty">No items selected</div>
    {/if}
  {/if}
</div>

<style>
  .rf {
    display: flex;
    flex-direction: column;
    gap: 8px;
    position: relative;
  }

  .rf-loading {
    font-size: 12px;
    color: var(--text-tertiary);
    padding: 8px 0;
  }

  /* Search */
  .rf-search-wrap {
    position: relative;
    display: flex;
    align-items: center;
  }

  .rf-search-icon {
    position: absolute;
    left: 8px;
    color: var(--text-tertiary);
    pointer-events: none;
  }

  .rf-search {
    width: 100%;
    padding: 7px 10px 7px 28px;
    font-size: 13px;
    color: var(--text-primary);
    background: transparent;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    outline: none;
    transition: border-color 0.15s;
  }

  .rf-search:hover {
    border-color: var(--border-primary);
  }

  .rf-search:focus {
    border-color: var(--border-secondary);
  }

  .rf-search::placeholder {
    color: var(--text-tertiary);
  }

  /* Dropdown */
  .rf-dropdown {
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-sm);
    background: var(--bg-primary);
    max-height: 200px;
    overflow-y: auto;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
  }

  .rf-result {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 8px 12px;
    background: none;
    border: none;
    cursor: pointer;
    text-align: left;
    font-size: 13px;
    color: var(--text-primary);
    transition: background 0.1s;
  }

  .rf-result:hover {
    background: var(--bg-secondary);
  }

  .rf-result-title {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .rf-result-slug {
    font-size: 11px;
    color: var(--text-tertiary);
    flex-shrink: 0;
  }

  /* Max reached message */
  .rf-max-msg {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-tertiary);
    padding: 4px 0;
  }

  /* Selected items */
  .rf-selected {
    display: flex;
    flex-direction: column;
    gap: 0;
  }

  .rf-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 0;
    border-bottom: 1px solid var(--border-primary);
  }

  .rf-item:last-child {
    border-bottom: none;
  }

  .rf-reorder {
    display: flex;
    flex-direction: column;
    gap: 1px;
    flex-shrink: 0;
  }

  .rf-arrow {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 14px;
    background: none;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    color: var(--text-tertiary);
    transition: background 0.1s, color 0.1s;
    padding: 0;
  }

  .rf-arrow:hover:not(:disabled) {
    background: var(--bg-secondary);
    color: var(--text-primary);
  }

  .rf-arrow:disabled {
    opacity: 0.25;
    cursor: default;
  }

  .rf-item-info {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: baseline;
    gap: 8px;
  }

  .rf-item-title {
    font-size: 13px;
    color: var(--text-primary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .rf-item-slug {
    font-size: 11px;
    color: var(--text-tertiary);
    flex-shrink: 0;
  }

  .rf-remove {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    background: none;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    color: var(--text-tertiary);
    flex-shrink: 0;
    transition: background 0.1s, color 0.1s;
    padding: 0;
  }

  .rf-remove:hover {
    background: var(--bg-secondary);
    color: var(--danger);
  }

  /* Empty state */
  .rf-empty {
    font-size: 12px;
    color: var(--text-tertiary);
    padding: 8px 0;
  }
</style>
