<script>
  import { Search, Pencil, Copy, Trash2, Check, X } from 'lucide-svelte';

  let { editor } = $props();

  let search = $state('');
  let showUnused = $state(false);
  let editing = $state(null);
  let editValue = $state('');
  let confirmingDelete = $state(null);
  let renameInput = $state(null);

  let rows = $derived.by(() => {
    const usage = editor.classUsage;
    const q = search.trim().toLowerCase();
    return editor.classNames
      .map((name) => ({ name, count: usage[name] || 0, props: Object.keys(editor.classes[name] || {}).length }))
      .filter((r) => !q || r.name.toLowerCase().includes(q))
      .filter((r) => !showUnused || r.count === 0)
      .sort((a, b) => a.name.localeCompare(b.name));
  });

  function startRename(name) {
    confirmingDelete = null;
    editing = name;
    editValue = name;
    requestAnimationFrame(() => renameInput?.focus());
  }

  function commitRename() {
    if (editing && editValue.trim() && editValue.trim() !== editing) {
      editor.renameClass(editing, editValue.trim());
    }
    editing = null;
  }

  function onRenameKey(e) {
    if (e.key === 'Enter') { e.preventDefault(); commitRename(); }
    else if (e.key === 'Escape') { e.preventDefault(); editing = null; }
  }
</script>

<div class="selectors">
  <div class="head">
    <div class="search">
      <Search size={15} aria-hidden="true" />
      <input type="text" placeholder="Search classes" bind:value={search} aria-label="Search classes" />
    </div>
    <div class="filters" role="group" aria-label="Filter classes">
      <button class:on={!showUnused} aria-pressed={!showUnused} onclick={() => (showUnused = false)}>All</button>
      <button class:on={showUnused} aria-pressed={showUnused} onclick={() => (showUnused = true)}>Unused</button>
    </div>
  </div>

  <ul class="list" aria-label="Class library">
    {#each rows as row (row.name)}
      <li class="row" class:unused={row.count === 0}>
        {#if editing === row.name}
          <input
            class="rename"
            bind:this={renameInput}
            bind:value={editValue}
            onkeydown={onRenameKey}
            onblur={commitRename}
            aria-label="Rename class"
          />
        {:else if confirmingDelete === row.name}
          <span class="confirm">Delete <strong>.{row.name}</strong>?</span>
          <div class="actions">
            <button class="act danger" onclick={() => { editor.deleteClass(row.name); confirmingDelete = null; }} aria-label="Confirm delete">
              <Check size={14} aria-hidden="true" />
            </button>
            <button class="act" onclick={() => (confirmingDelete = null)} aria-label="Cancel delete">
              <X size={14} aria-hidden="true" />
            </button>
          </div>
        {:else}
          <div class="info">
            <span class="name">.{row.name}</span>
            <span class="meta">{row.props} {row.props === 1 ? 'prop' : 'props'} · {row.count === 0 ? 'Unused' : `Used ${row.count}×`}</span>
          </div>
          <div class="actions">
            <button class="act" onclick={() => startRename(row.name)} aria-label={`Rename ${row.name}`} title="Rename">
              <Pencil size={14} aria-hidden="true" />
            </button>
            <button class="act" onclick={() => editor.duplicateClass(row.name)} aria-label={`Duplicate ${row.name}`} title="Duplicate">
              <Copy size={14} aria-hidden="true" />
            </button>
            <button class="act danger" onclick={() => (confirmingDelete = row.name)} aria-label={`Delete ${row.name}`} title="Delete">
              <Trash2 size={14} aria-hidden="true" />
            </button>
          </div>
        {/if}
      </li>
    {/each}
    {#if rows.length === 0}
      <li class="empty">
        {#if editor.classNames.length === 0}
          No classes yet. Select an element and add a class to start your library.
        {:else}
          No matching classes.
        {/if}
      </li>
    {/if}
  </ul>
</div>

<style>
  .selectors {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }

  .head {
    padding: 12px 12px 8px;
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .search {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0 10px;
    border: 1px solid transparent;
    border-radius: 7px;
    background: var(--hover);
    color: var(--dim);
  }
  .search:focus-within { border-color: var(--purple); }
  .search input {
    flex: 1;
    min-width: 0;
    border: none;
    background: none;
    color: var(--text);
    font-size: 13px;
    padding: 8px 0;
    outline: none;
  }

  .filters {
    display: flex;
    gap: 4px;
  }
  .filters button {
    flex: 1;
    padding: 6px 10px;
    border: none;
    border-radius: 6px;
    background: transparent;
    color: var(--sec);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
  }
  .filters button.on { background: var(--hover); color: var(--text); }
  .filters button:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .list {
    list-style: none;
    margin: 0;
    padding: 4px 8px 16px;
    overflow-y: auto;
    flex: 1;
  }

  .row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 8px;
    border-radius: 7px;
    min-height: 38px;
  }
  .row:hover { background: var(--hover); }
  .row:hover .actions { opacity: 1; }

  .info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 1px;
  }
  .name {
    font-size: 13px;
    color: var(--text);
    font-family: var(--font-mono, ui-monospace, monospace);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .row.unused .name { color: var(--sec); }
  .meta {
    font-size: 11px;
    color: var(--dim);
  }

  .actions {
    display: flex;
    gap: 2px;
    opacity: 0;
    flex-shrink: 0;
  }
  .row:focus-within .actions { opacity: 1; }

  .act {
    display: inline-flex;
    padding: 6px;
    border: none;
    border-radius: 6px;
    background: transparent;
    color: var(--dim);
    cursor: pointer;
  }
  .act:hover { background: var(--bg-active); color: var(--text); }
  .act.danger:hover { color: var(--red); }
  .act:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; opacity: 1; }

  .rename {
    flex: 1;
    min-width: 0;
    padding: 6px 8px;
    border: 1px solid var(--purple);
    border-radius: 6px;
    background: var(--bg);
    color: var(--text);
    font-size: 13px;
    font-family: var(--font-mono, ui-monospace, monospace);
    outline: none;
  }

  .confirm {
    flex: 1;
    font-size: 12px;
    color: var(--sec);
  }
  .confirm strong {
    color: var(--text);
    font-family: var(--font-mono, ui-monospace, monospace);
    font-weight: 600;
  }

  .empty {
    padding: 16px 8px;
    font-size: 12px;
    color: var(--dim);
    line-height: 1.5;
  }
</style>
