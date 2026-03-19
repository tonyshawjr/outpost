<script>
  import { onMount, tick } from 'svelte';

  let { fields = [], selectedIndex = -1, onSelect, onReorder, onRemove } = $props();
  let listEl = $state(null);
  let sortableInstance = $state(null);

  onMount(async () => {
    if (!listEl) return;
    const { default: Sortable } = await import('sortablejs');
    sortableInstance = new Sortable(listEl, {
      animation: 150,
      handle: '.field-drag-handle',
      ghostClass: 'sortable-ghost',
      onEnd(evt) {
        if (evt.oldIndex !== evt.newIndex) {
          onReorder(evt.oldIndex, evt.newIndex);
        }
      },
    });
    return () => sortableInstance?.destroy();
  });

  function typeIcon(type) {
    const icons = {
      text: 'T', textarea: '¶', number: '#', email: '@', phone: '☎', url: '⌘',
      select: '▾', radio: '◉', checkbox: '☑', date: '📅', time: '⏰',
      section: '—', html: '</>', hidden: '◌',
    };
    return icons[type] || '?';
  }
</script>

<div class="field-list" bind:this={listEl}>
  {#if fields.length === 0}
    <div class="field-list-empty">
      <p>Add fields from the palette on the left</p>
    </div>
  {/if}
  {#each fields as field, i (field.id)}
    <div
      class="field-list-item"
      class:selected={selectedIndex === i}
      onclick={() => onSelect(i)}
    >
      <span class="field-drag-handle" title="Drag to reorder">⠿</span>
      <span class="field-type-icon">{typeIcon(field.type)}</span>
      <div class="field-list-info">
        <span class="field-list-label">{field.label || '(untitled)'}</span>
        <span class="field-list-meta">{field.type}{field.required ? ' *' : ''}</span>
      </div>
      <button class="field-remove-btn" onclick={(e) => { e.stopPropagation(); onRemove(i); }} title="Remove field">&times;</button>
    </div>
  {/each}
</div>

<style>
  .field-list {
    padding: 16px;
    min-height: 200px;
    flex: 1;
    overflow-y: auto;
  }

  .field-list-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 200px;
    color: var(--text-tertiary);
    font-size: 14px;
  }

  .field-list-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-md, 6px);
    margin-bottom: 6px;
    cursor: pointer;
    background: var(--bg-card);
    transition: all 0.1s;
  }

  .field-list-item:hover {
    border-color: var(--border-primary);
  }

  .field-list-item.selected {
    border-color: var(--accent);
    background: var(--accent-soft);
  }

  .field-drag-handle {
    cursor: grab;
    color: var(--text-tertiary);
    font-size: 14px;
    user-select: none;
    flex-shrink: 0;
    line-height: 1;
  }

  .field-type-icon {
    width: 20px;
    text-align: center;
    font-size: 13px;
    color: var(--text-tertiary);
    flex-shrink: 0;
  }

  .field-list-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 1px;
  }

  .field-list-label {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .field-list-meta {
    font-size: 11px;
    color: var(--text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.03em;
  }

  .field-remove-btn {
    flex-shrink: 0;
    background: none;
    border: none;
    color: var(--text-tertiary);
    font-size: 18px;
    cursor: pointer;
    padding: 0 4px;
    line-height: 1;
    opacity: 0;
    transition: opacity 0.1s, color 0.1s;
  }

  .field-list-item:hover .field-remove-btn {
    opacity: 1;
  }

  .field-remove-btn:hover {
    color: var(--danger);
  }

  :global(.sortable-ghost) {
    opacity: 0.3;
  }
</style>
