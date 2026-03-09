<script>
  let {
    tree = [],
    activePath = '',
    loading = false,
    onOpenFile,
    onPinFile,
    onRefresh,
    onCreateFile,
    onCreateFolder,
    onRenameItem,
    onDeleteItem,
  } = $props();

  let expanded    = $state({});
  let hoverPath   = $state(null);
  let contextMenu = $state(null); // { x, y, node }
  let inlineEdit  = $state(null); // { type, parentPath, node?, value }
  let deleteModal = $state(null); // { node }

  let inlineInputEl = $state(null);

  $effect(() => {
    if (inlineEdit && inlineInputEl) {
      inlineInputEl.focus();
      if (inlineEdit.type === 'rename') inlineInputEl.select();
    }
  });

  function toggleDir(path) {
    expanded = { ...expanded, [path]: !expanded[path] };
  }

  function showCtx(e, node) {
    e.preventDefault();
    e.stopPropagation();
    contextMenu = { x: e.clientX, y: e.clientY, node };
  }

  function closeCtx() { contextMenu = null; }

  function startCreateFile(parentPath, e) {
    e?.stopPropagation();
    if (parentPath) expanded = { ...expanded, [parentPath]: true };
    inlineEdit = { type: 'create-file', parentPath: parentPath || '', value: '' };
    contextMenu = null;
  }

  function startCreateFolder(parentPath, e) {
    e?.stopPropagation();
    if (parentPath) expanded = { ...expanded, [parentPath]: true };
    inlineEdit = { type: 'create-folder', parentPath: parentPath || '', value: '' };
    contextMenu = null;
  }

  function startRename(node, e) {
    e?.stopPropagation();
    const parts = node.path.split('/');
    const parentPath = parts.length > 1 ? parts.slice(0, -1).join('/') : '';
    inlineEdit = { type: 'rename', parentPath, node, value: node.name };
    contextMenu = null;
  }

  function confirmDelete(node, e) {
    e?.stopPropagation();
    deleteModal = { node };
    contextMenu = null;
  }

  function cancelInline() { inlineEdit = null; }

  async function commitInline() {
    if (!inlineEdit || !inlineEdit.value.trim()) { cancelInline(); return; }
    const val = inlineEdit.value.trim();

    if (inlineEdit.type === 'create-file') {
      const path = inlineEdit.parentPath ? `${inlineEdit.parentPath}/${val}` : val;
      await onCreateFile(path);
    } else if (inlineEdit.type === 'create-folder') {
      const path = inlineEdit.parentPath ? `${inlineEdit.parentPath}/${val}` : val;
      await onCreateFolder(path);
    } else if (inlineEdit.type === 'rename') {
      const pp = inlineEdit.parentPath;
      const newPath = pp ? `${pp}/${val}` : val;
      await onRenameItem(inlineEdit.node.path, newPath);
    }
    inlineEdit = null;
  }

  function handleInlineKey(e) {
    if (e.key === 'Enter')  { e.preventDefault(); commitInline(); }
    if (e.key === 'Escape') { e.preventDefault(); cancelInline(); }
  }

  function fileIcon(name) {
    const ext = name.split('.').pop().toLowerCase();
    return { php:'P', html:'H', htm:'H', css:'C', js:'J', json:'{}', svg:'S', md:'M', yml:'Y', yaml:'Y', xml:'X', txt:'T' }[ext] || 'F';
  }

  function fileBadgeClass(name) {
    return 'ft-badge ft-badge-' + name.split('.').pop().toLowerCase();
  }

  function copyPath(node, e) {
    e?.stopPropagation();
    navigator.clipboard.writeText(node.path).catch(() => {});
    contextMenu = null;
  }

  // Inline input depth level for create ops
  function inlineDepth(parentPath) {
    if (!parentPath) return 0;
    return parentPath.split('/').length;
  }
</script>

<svelte:window onclick={closeCtx} />

<div class="ft-root">
  <div class="ft-header">
    <span class="ft-label">THEME FILES</span>
    <div class="ft-header-actions">
      <button class="ft-icon-btn" onclick={() => startCreateFile('', null)} title="New File">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
      </button>
      <button class="ft-icon-btn" onclick={() => startCreateFolder('', null)} title="New Folder">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
      </button>
      <button class="ft-icon-btn" onclick={onRefresh} title="Refresh">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
      </button>
    </div>
  </div>

  <div class="ft-nav">
    {#if loading}
      <div class="ft-loading"><div class="spinner"></div></div>
    {:else if tree.length === 0 && !inlineEdit}
      <div class="ft-empty">
        <p>No theme files found.</p>
        <p style="font-size:11px;margin-top:4px;color:var(--text-light)">Create a <code>themes/</code> directory.</p>
      </div>
    {:else}
      {#if inlineEdit && inlineEdit.parentPath === ''}
        {@render inlineRow(inlineEdit, 0)}
      {/if}
      {#each tree as node}
        {@render treeNode(node, 0)}
      {/each}
    {/if}
  </div>
</div>

<!-- Delete confirm modal -->
{#if deleteModal}
  <div class="ft-overlay" onclick={() => deleteModal = null}>
    <div class="ft-modal" onclick={(e) => e.stopPropagation()}>
      <p class="ft-modal-title">Delete {deleteModal.node.type === 'directory' ? 'folder' : 'file'}?</p>
      <p class="ft-modal-path">{deleteModal.node.path}</p>
      <p class="ft-modal-warn">This cannot be undone.</p>
      <div class="ft-modal-btns">
        <button class="btn btn-secondary" onclick={() => deleteModal = null}>Cancel</button>
        <button class="btn btn-danger" onclick={async () => { await onDeleteItem(deleteModal.node.path); deleteModal = null; }}>Delete</button>
      </div>
    </div>
  </div>
{/if}

<!-- Context menu -->
{#if contextMenu}
  <div class="ft-ctx" style="left:{contextMenu.x}px;top:{contextMenu.y}px" onclick={(e) => e.stopPropagation()}>
    {#if contextMenu.node.type === 'directory'}
      <button class="ft-ctx-item" onclick={() => startCreateFile(contextMenu.node.path)}>New File</button>
      <button class="ft-ctx-item" onclick={() => startCreateFolder(contextMenu.node.path)}>New Folder</button>
      <div class="ft-ctx-sep"></div>
    {/if}
    <button class="ft-ctx-item" onclick={(e) => startRename(contextMenu.node, e)}>Rename</button>
    <button class="ft-ctx-item ft-ctx-danger" onclick={(e) => confirmDelete(contextMenu.node, e)}>Delete</button>
    <div class="ft-ctx-sep"></div>
    <button class="ft-ctx-item" onclick={(e) => copyPath(contextMenu.node, e)}>Copy Path</button>
  </div>
{/if}

{#snippet inlineRow(edit, depth)}
  <div class="ft-inline-row" style="padding-left:{12 + depth * 14}px">
    {#if edit.type === 'create-folder'}
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.5"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
    {:else}
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.5"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    {/if}
    <input
      class="ft-inline-input"
      bind:this={inlineInputEl}
      bind:value={inlineEdit.value}
      onkeydown={handleInlineKey}
      onblur={cancelInline}
      placeholder={edit.type === 'create-folder' ? 'folder-name' : 'file.html'}
    />
  </div>
{/snippet}

{#snippet treeNode(node, depth)}
  {#if node.type === 'directory'}
    <div>
      <button
        class="ft-row ft-dir"
        style="padding-left:{12 + depth * 14}px"
        onclick={() => toggleDir(node.path)}
        oncontextmenu={(e) => showCtx(e, node)}
        onmouseenter={() => hoverPath = node.path}
        onmouseleave={() => hoverPath = null}
      >
        <svg class="ft-chevron" class:open={expanded[node.path]} width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        <svg class="ft-folder-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
        <span class="ft-name">{node.name}</span>
        {#if hoverPath === node.path}
          <span class="ft-actions" onclick={(e) => e.stopPropagation()}>
            <span class="ft-act" title="New File" onclick={(e) => startCreateFile(node.path, e)}>
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
            </span>
            <span class="ft-act" title="New Folder" onclick={(e) => startCreateFolder(node.path, e)}>
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
            </span>
            <span class="ft-act" title="Rename" onclick={(e) => startRename(node, e)}>
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </span>
            <span class="ft-act ft-act-danger" title="Delete" onclick={(e) => confirmDelete(node, e)}>
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
            </span>
          </span>
        {/if}
      </button>

      {#if expanded[node.path]}
        {#if inlineEdit && inlineEdit.parentPath === node.path}
          {@render inlineRow(inlineEdit, depth + 1)}
        {/if}
        {#if node.children}
          {#each node.children as child}
            {@render treeNode(child, depth + 1)}
          {/each}
        {/if}
      {/if}
    </div>

  {:else}
    {#if inlineEdit?.type === 'rename' && inlineEdit.node?.path === node.path}
      <div class="ft-inline-row" style="padding-left:{12 + depth * 14 + 14}px">
        <input
          class="ft-inline-input"
          bind:this={inlineInputEl}
          bind:value={inlineEdit.value}
          onkeydown={handleInlineKey}
          onblur={cancelInline}
          onclick={(e) => e.stopPropagation()}
        />
      </div>
    {:else}
      <button
        class="ft-row ft-file"
        class:active={activePath === node.path}
        style="padding-left:{12 + depth * 14 + 14}px"
        onclick={() => onOpenFile(node)}
        ondblclick={() => { if (onPinFile) onPinFile(node); }}
        oncontextmenu={(e) => showCtx(e, node)}
        onmouseenter={() => hoverPath = node.path}
        onmouseleave={() => hoverPath = null}
      >
        <span class={fileBadgeClass(node.name)}>{fileIcon(node.name)}</span>
        <span class="ft-name">{node.name}</span>
        {#if hoverPath === node.path}
          <span class="ft-actions" onclick={(e) => e.stopPropagation()}>
            <span class="ft-act" title="Rename" onclick={(e) => startRename(node, e)}>
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </span>
            <span class="ft-act ft-act-danger" title="Delete" onclick={(e) => confirmDelete(node, e)}>
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
            </span>
          </span>
        {/if}
      </button>
    {/if}
  {/if}
{/snippet}

<style>
  .ft-root {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
  }

  .ft-header {
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 12px 0 16px;
    border-bottom: 1px solid var(--border-light);
    flex-shrink: 0;
  }

  .ft-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--text-light);
  }

  .ft-header-actions {
    display: flex;
    align-items: center;
    gap: 2px;
  }

  .ft-icon-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    background: none;
    border: none;
    border-radius: 5px;
    color: var(--text-muted);
    cursor: pointer;
    transition: background var(--transition-fast), color var(--transition-fast);
  }
  .ft-icon-btn:hover { background: var(--bg-hover); color: var(--text); }

  .ft-nav {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
  }

  .ft-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 32px;
  }

  .ft-empty {
    padding: 24px 16px;
    color: var(--text-muted);
    font-size: 13px;
    text-align: center;
  }

  /* Tree rows */
  .ft-row {
    display: flex;
    align-items: center;
    gap: 6px;
    width: 100%;
    height: 30px;
    background: none;
    border: none;
    font-size: 12.5px;
    color: var(--text);
    cursor: pointer;
    text-align: left;
    font-family: var(--font-sans);
    white-space: nowrap;
    overflow: hidden;
    transition: background var(--transition-fast);
    position: relative;
  }
  .ft-row:hover { background: var(--bg-hover); }
  .ft-file.active {
    background: var(--forest-light);
    color: var(--forest);
    font-weight: 500;
  }

  .ft-chevron {
    flex-shrink: 0;
    transition: transform .15s;
    opacity: .45;
  }
  .ft-chevron.open { transform: rotate(90deg); }

  .ft-folder-icon {
    flex-shrink: 0;
    opacity: .5;
  }

  .ft-name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  /* File badge */
  .ft-badge {
    flex-shrink: 0;
    width: 16px;
    height: 16px;
    border-radius: 3px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 9px;
    font-weight: 700;
    color: #fff;
    background: #737373;
  }
  .ft-badge-php  { background: #777bb3; }
  .ft-badge-html, .ft-badge-htm { background: #e34c26; }
  .ft-badge-css  { background: #1572b6; }
  .ft-badge-js   { background: #f7df1e; color: #000; }
  .ft-badge-json { background: #f7df1e; color: #000; }
  .ft-badge-svg  { background: #ffb13b; color: #000; }
  .ft-badge-md   { background: #083fa1; }
  .ft-badge-yml, .ft-badge-yaml { background: #cb171e; }

  /* Hover action icons */
  .ft-actions {
    display: flex;
    align-items: center;
    gap: 1px;
    margin-left: auto;
    padding-right: 6px;
    flex-shrink: 0;
  }

  .ft-act {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border-radius: 3px;
    color: var(--text-muted);
    cursor: pointer;
    transition: background var(--transition-fast), color var(--transition-fast);
  }
  .ft-act:hover { background: var(--bg-hover); color: var(--text); }
  .ft-act-danger:hover { color: #ef4444; }

  /* Inline input */
  .ft-inline-row {
    display: flex;
    align-items: center;
    gap: 6px;
    height: 30px;
    padding-right: 8px;
  }

  .ft-inline-input {
    flex: 1;
    min-width: 0;
    height: 22px;
    padding: 0 6px;
    font-size: 12.5px;
    font-family: var(--font-sans);
    color: var(--text);
    background: var(--bg-hover);
    border: 1px solid var(--forest);
    border-radius: 4px;
    outline: none;
  }

  /* Context menu */
  .ft-ctx {
    position: fixed;
    z-index: 1000;
    min-width: 160px;
    background: var(--bg-primary);
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: 0 4px 24px rgba(0,0,0,.15);
    padding: 4px;
  }

  .ft-ctx-item {
    display: block;
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
  .ft-ctx-item:hover { background: var(--bg-hover); }
  .ft-ctx-danger { color: #ef4444; }

  .ft-ctx-sep {
    height: 1px;
    background: var(--border-light);
    margin: 4px 0;
  }

  /* Delete modal */
  .ft-overlay {
    position: fixed;
    inset: 0;
    z-index: 500;
    background: rgba(0,0,0,.4);
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .ft-modal {
    background: var(--bg-primary);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    width: 360px;
    box-shadow: 0 8px 40px rgba(0,0,0,.2);
  }

  .ft-modal-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--text);
    margin: 0 0 8px;
  }

  .ft-modal-path {
    font-size: 12px;
    font-family: var(--font-mono);
    color: var(--text-secondary);
    background: var(--bg-secondary);
    border-radius: 5px;
    padding: 6px 10px;
    margin: 0 0 12px;
  }

  .ft-modal-warn {
    font-size: 13px;
    color: var(--text-muted);
    margin: 0 0 20px;
  }

  .ft-modal-btns {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
  }

  .btn-danger {
    background: #ef4444;
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 6px 14px;
    font-size: 13px;
    cursor: pointer;
  }
  .btn-danger:hover { background: #dc2626; }
</style>
