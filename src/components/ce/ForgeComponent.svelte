<script>
  import { onMount } from 'svelte';
  import { components as componentsApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  let { visible = false, onInsert = () => {}, onClose = () => {} } = $props();

  let categories = $state([]);
  let loading = $state(true);
  let search = $state('');
  let selectedCategory = $state(null);
  let inserting = $state('');

  let filteredCategories = $derived.by(() => {
    if (!search.trim()) return categories;
    const q = search.toLowerCase();
    return categories
      .map(cat => ({
        ...cat,
        components: cat.components.filter(c =>
          c.name.toLowerCase().includes(q) ||
          c.description.toLowerCase().includes(q) ||
          cat.name.toLowerCase().includes(q)
        ),
      }))
      .filter(cat => cat.components.length > 0);
  });

  let activeComponents = $derived.by(() => {
    if (search.trim()) {
      return filteredCategories.flatMap(c => c.components.map(comp => ({ ...comp, category: c.name })));
    }
    if (selectedCategory) {
      const cat = categories.find(c => c.slug === selectedCategory);
      return cat ? cat.components : [];
    }
    return [];
  });

  onMount(async () => {
    try {
      const data = await componentsApi.list();
      categories = data.categories || [];
    } catch (e) {
      addToast('Failed to load components', 'error');
    } finally {
      loading = false;
    }
  });

  function goBack() {
    selectedCategory = null;
    search = '';
  }

  async function insertComponent(comp) {
    inserting = comp.slug;
    try {
      const data = await componentsApi.read(comp.file);
      if (data.html) {
        onInsert(data.html);
        addToast(`Inserted ${comp.name}`, 'success');
      }
    } catch (e) {
      addToast('Failed to load component', 'error');
    } finally {
      inserting = '';
    }
  }

  function handleKeydown(e) {
    if (e.key === 'Escape') {
      onClose();
    }
  }

  const categoryIcons = {
    star: 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z',
    grid: 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z',
    quote: 'M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V21z',
    tag: 'M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z',
    megaphone: 'M21.5 11.5c0 5.5-4 10-9 10s-9-4.5-9-10 4-10 9-10 9 4.5 9 10z',
    users: 'M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75',
    mail: 'M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z M22 6l-10 7L2 6',
    'file-text': 'M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z M14 2v6h6 M16 13H8 M16 17H8 M10 9H8',
    layout: 'M19 3H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2V5a2 2 0 00-2-2z M3 9h18 M9 21V9',
    menu: 'M3 12h18M3 6h18M3 18h18',
  };
</script>

<svelte:window onkeydown={handleKeydown} />

{#if visible}
  <div class="fc-overlay" onclick={onClose}>
    <div class="fc-panel" onclick={(e) => e.stopPropagation()}>
      <div class="fc-header">
        {#if selectedCategory && !search.trim()}
          <button class="fc-back" onclick={goBack}>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 18l-6-6 6-6"/></svg>
          </button>
        {/if}
        <span class="fc-title">
          {#if selectedCategory && !search.trim()}
            {categories.find(c => c.slug === selectedCategory)?.name ?? 'Components'}
          {:else}
            Components
          {/if}
        </span>
        <button class="fc-close" onclick={onClose}>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <div class="fc-search">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input
          type="text"
          placeholder="Search components..."
          bind:value={search}
          spellcheck="false"
        />
      </div>

      <div class="fc-body">
        {#if loading}
          <div class="fc-loading"><div class="spinner"></div></div>
        {:else if !selectedCategory && !search.trim()}
          <!-- Category list -->
          {#each categories as cat}
            <button class="fc-cat-row" onclick={() => { selectedCategory = cat.slug; }}>
              <svg class="fc-cat-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d={categoryIcons[cat.icon] || categoryIcons.grid} />
              </svg>
              <div class="fc-cat-info">
                <span class="fc-cat-name">{cat.name}</span>
                <span class="fc-cat-count">{cat.components.length} component{cat.components.length === 1 ? '' : 's'}</span>
              </div>
              <svg class="fc-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
            </button>
          {/each}
        {:else}
          <!-- Component list -->
          {#each activeComponents as comp}
            <button
              class="fc-comp-row"
              onclick={() => insertComponent(comp)}
              disabled={inserting === comp.slug}
            >
              <div class="fc-comp-info">
                <span class="fc-comp-name">{comp.name}</span>
                {#if comp.category}
                  <span class="fc-comp-cat">{comp.category}</span>
                {/if}
                <span class="fc-comp-desc">{comp.description}</span>
              </div>
              {#if inserting === comp.slug}
                <span class="fc-inserting">Inserting...</span>
              {:else}
                <span class="fc-insert-label">Insert</span>
              {/if}
            </button>
          {/each}
          {#if activeComponents.length === 0}
            <p class="fc-empty">No components match your search</p>
          {/if}
        {/if}
      </div>
    </div>
  </div>
{/if}

<style>
  .fc-overlay {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,.3);
    backdrop-filter: blur(2px);
  }

  .fc-panel {
    width: 420px;
    max-height: 520px;
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-xl, 0 20px 60px rgba(0,0,0,.2));
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }

  .fc-header {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 12px 14px;
    border-bottom: 1px solid var(--border-light);
    flex-shrink: 0;
  }

  .fc-title {
    flex: 1;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
  }

  .fc-back, .fc-close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    padding: 0;
    background: none;
    border: none;
    border-radius: var(--radius-sm);
    color: var(--text-tertiary);
    cursor: pointer;
    flex-shrink: 0;
  }

  .fc-back:hover, .fc-close:hover {
    background: var(--bg-secondary);
    color: var(--text-primary);
  }

  .fc-search {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    border-bottom: 1px solid var(--border-light);
    color: var(--text-tertiary);
    flex-shrink: 0;
  }

  .fc-search input {
    flex: 1;
    border: none;
    background: none;
    font-size: 13px;
    color: var(--text-primary);
    outline: none;
  }

  .fc-search input::placeholder {
    color: var(--text-tertiary);
  }

  .fc-body {
    flex: 1;
    overflow-y: auto;
    padding: 4px 0;
  }

  .fc-loading {
    display: flex;
    justify-content: center;
    padding: 32px;
  }

  /* Category rows */
  .fc-cat-row {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 10px 14px;
    background: none;
    border: none;
    cursor: pointer;
    text-align: left;
    color: var(--text-primary);
    transition: background 0.1s;
  }

  .fc-cat-row:hover {
    background: var(--bg-secondary);
  }

  .fc-cat-icon {
    flex-shrink: 0;
    color: var(--text-tertiary);
  }

  .fc-cat-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 1px;
  }

  .fc-cat-name {
    font-size: 13px;
    font-weight: 500;
  }

  .fc-cat-count {
    font-size: 11px;
    color: var(--text-tertiary);
  }

  .fc-chevron {
    color: var(--text-tertiary);
    flex-shrink: 0;
  }

  /* Component rows */
  .fc-comp-row {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 10px 14px;
    background: none;
    border: none;
    border-bottom: 1px solid var(--border-light);
    cursor: pointer;
    text-align: left;
    color: var(--text-primary);
    transition: background 0.1s;
  }

  .fc-comp-row:last-child {
    border-bottom: none;
  }

  .fc-comp-row:hover {
    background: var(--bg-secondary);
  }

  .fc-comp-row:disabled {
    opacity: 0.6;
    cursor: wait;
  }

  .fc-comp-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .fc-comp-name {
    font-size: 13px;
    font-weight: 500;
  }

  .fc-comp-cat {
    font-size: 10px;
    color: var(--text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }

  .fc-comp-desc {
    font-size: 11px;
    color: var(--text-tertiary);
  }

  .fc-insert-label {
    font-size: 11px;
    font-weight: 500;
    color: var(--accent);
    flex-shrink: 0;
    opacity: 0;
    transition: opacity 0.15s;
  }

  .fc-comp-row:hover .fc-insert-label {
    opacity: 1;
  }

  .fc-inserting {
    font-size: 11px;
    color: var(--text-tertiary);
    flex-shrink: 0;
  }

  .fc-empty {
    text-align: center;
    color: var(--text-tertiary);
    font-size: 13px;
    padding: 24px 14px;
  }
</style>
