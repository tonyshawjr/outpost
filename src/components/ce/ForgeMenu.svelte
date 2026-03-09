<script>
  let { menu = null, showForgeTheme = false, onSelect, onForgeTheme, onClose } = $props();

  const allItems = [
    { key: 'editable',    label: 'Make Editable',    icon: 'pencil' },
    { key: 'loop',        label: 'Collection Loop',  icon: 'list' },
    { key: 'menu',        label: 'Menu Loop',         icon: 'menu' },
    { key: 'conditional', label: 'Conditional',       icon: 'branch' },
    { key: 'partial',     label: 'Extract Partial',   icon: 'scissors' },
    { key: 'meta',        label: 'Meta Tag',          icon: 'tag' },
    { key: 'form',        label: 'Form',              icon: 'form' },
  ];

  let orderedItems = $derived.by(() => {
    if (!menu?.detection?.menuOrder) return allItems;
    const order = menu.detection.menuOrder;
    return [...allItems].sort((a, b) => order.indexOf(a.key) - order.indexOf(b.key));
  });

  let suggestedKey = $derived(menu?.detection?.suggestedAction ?? 'editable');
</script>

<svelte:window onclick={onClose} />

{#if menu}
  <div class="forge-ctx" style="left:{menu.x}px;top:{menu.y}px" onclick={(e) => e.stopPropagation()}>
    <div class="forge-ctx-header">Forge</div>
    {#each orderedItems as item, i}
      {#if i === 4}
        <div class="forge-ctx-sep"></div>
      {/if}
      <button
        class="forge-ctx-item"
        class:suggested={item.key === suggestedKey}
        onclick={() => onSelect(item.key)}
      >
        <span class="forge-ctx-icon">
          {#if item.icon === 'pencil'}
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          {:else if item.icon === 'list'}
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
          {:else if item.icon === 'menu'}
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
          {:else if item.icon === 'branch'}
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="6" y1="3" x2="6" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 01-9 9"/></svg>
          {:else if item.icon === 'scissors'}
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg>
          {:else if item.icon === 'tag'}
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
          {:else if item.icon === 'form'}
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="9" x2="15" y2="9"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="12" y2="17"/></svg>
          {/if}
        </span>
        <span class="forge-ctx-label">{item.label}</span>
        {#if item.key === suggestedKey}
          <span class="forge-ctx-suggested">suggested</span>
        {/if}
      </button>
    {/each}
    {#if showForgeTheme}
      <div class="forge-ctx-sep"></div>
      <button class="forge-ctx-item" onclick={onForgeTheme}>
        <span class="forge-ctx-icon">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
        </span>
        <span class="forge-ctx-label">Forge Theme&hellip;</span>
      </button>
    {/if}
  </div>
{/if}

<style>
  .forge-ctx {
    position: fixed;
    z-index: 1000;
    min-width: 200px;
    background: var(--bg-primary);
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: 0 4px 24px rgba(0,0,0,.15);
    padding: 4px;
  }

  .forge-ctx-header {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--text-muted);
    padding: 6px 10px 4px;
  }

  .forge-ctx-item {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 6px 10px;
    background: none;
    border: none;
    border-radius: 5px;
    font-size: 13px;
    font-family: var(--font-sans);
    color: var(--text);
    text-align: left;
    cursor: pointer;
    transition: background var(--transition-fast);
  }
  .forge-ctx-item:hover { background: var(--bg-hover); }
  .forge-ctx-item.suggested { color: var(--forest); font-weight: 500; }

  .forge-ctx-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    opacity: .6;
    flex-shrink: 0;
  }
  .forge-ctx-item.suggested .forge-ctx-icon { opacity: 1; }

  .forge-ctx-suggested {
    margin-left: auto;
    font-size: 10px;
    font-weight: 500;
    color: var(--forest);
    opacity: .7;
  }

  .forge-ctx-label { flex: 1; }

  .forge-ctx-sep {
    height: 1px;
    background: var(--border-light);
    margin: 4px 0;
  }
</style>
