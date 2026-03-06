<script>
  import JsonTree from './JsonTree.svelte';

  let {
    fields = [],
    selected = $bindable([]),
    selectable = false,
    depth = 0,
  } = $props();

  let expanded = $state({});

  function toggle(name) {
    expanded = { ...expanded, [name]: !expanded[name] };
  }

  function isSelected(name) {
    return selected.includes(name);
  }

  function toggleSelect(name) {
    if (isSelected(name)) {
      selected = selected.filter((s) => s !== name);
    } else {
      selected = [...selected, name];
    }
  }

  function typeColor(type) {
    switch (type) {
      case 'number': return 'var(--type-number, #3b82f6)';
      case 'boolean': return 'var(--type-boolean, #8b5cf6)';
      case 'array': return 'var(--type-array, #f59e0b)';
      case 'object': return 'var(--type-object, #9ca3af)';
      default: return 'var(--text-muted)';
    }
  }

  function truncateValue(val) {
    if (val === null || val === undefined) return 'null';
    const str = String(val);
    return str.length > 60 ? str.slice(0, 60) + '...' : str;
  }
</script>

<div class="json-tree">
  {#each fields as field (field.name)}
    {@const hasChildren = field.children && field.children.length > 0}
    {@const isOpen = expanded[field.name]}

    <div class="tree-row" style="padding-left: {depth * 20}px">
      {#if hasChildren}
        <button class="tree-toggle" onclick={() => toggle(field.name)}>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            class:rotated={isOpen}>
            <polyline points="9 6 15 12 9 18"/>
          </svg>
        </button>
      {:else}
        <span class="tree-spacer"></span>
      {/if}

      {#if selectable}
        <label class="tree-checkbox">
          <input
            type="checkbox"
            checked={isSelected(field.name)}
            onchange={() => toggleSelect(field.name)}
          />
        </label>
      {/if}

      <span class="tree-name">{field.name}</span>
      <span class="tree-type" style="color: {typeColor(field.type)}">{field.type}</span>

      {#if field.sample !== undefined && !hasChildren}
        <span class="tree-sample">{truncateValue(field.sample)}</span>
      {/if}
    </div>

    {#if hasChildren && isOpen}
      <JsonTree
        fields={field.children}
        bind:selected={selected}
        {selectable}
        depth={depth + 1}
      />
    {/if}
  {/each}
</div>

<style>
  .json-tree {
    font-size: 13px;
  }

  .tree-row {
    display: flex;
    align-items: center;
    gap: 6px;
    padding-top: 4px;
    padding-bottom: 4px;
    padding-right: 8px;
    border-radius: var(--radius-sm);
    transition: background 0.1s;
  }

  .tree-row:hover {
    background: var(--bg-secondary);
  }

  .tree-toggle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    flex-shrink: 0;
    padding: 0;
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    transition: color 0.15s;
  }

  .tree-toggle:hover {
    color: var(--text);
  }

  .tree-toggle svg {
    transition: transform 0.15s ease;
  }

  .tree-toggle .rotated {
    transform: rotate(90deg);
  }

  .tree-spacer {
    width: 20px;
    flex-shrink: 0;
  }

  .tree-checkbox {
    display: flex;
    align-items: center;
    flex-shrink: 0;
    cursor: pointer;
  }

  .tree-checkbox input {
    cursor: pointer;
    accent-color: var(--accent);
  }

  .tree-name {
    font-weight: 500;
    color: var(--text);
    font-family: var(--font-mono);
    font-size: 12px;
  }

  .tree-type {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-weight: 600;
    flex-shrink: 0;
  }

  .tree-sample {
    margin-left: auto;
    font-size: 11px;
    color: var(--text-muted);
    font-family: var(--font-mono);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 240px;
  }
</style>
