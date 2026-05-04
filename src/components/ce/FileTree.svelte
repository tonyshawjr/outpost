<script>
  import { Layout, Blocks, Paintbrush, FileCode, ChevronRight, Plus, RefreshCw, FilePlus, FolderPlus } from 'lucide-svelte';

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
    onCreateBlock,
  } = $props();

  let expanded    = $state({});
  let hoverPath   = $state(null);
  let contextMenu = $state(null); // { x, y, node }
  let inlineEdit  = $state(null); // { type, parentPath, node?, value }
  let deleteModal = $state(null); // { node }
  let newBlockInput = $state(false);
  let newBlockName  = $state('');

  // Section collapse state — persisted in memory, Layout + Blocks open by default
  let sections = $state({
    layout: true,
    blocks: true,
    styles: false,
    templates: false,
    config: true,
  });

  let inlineInputEl    = $state(null);
  let newBlockInputEl  = $state(null);

  $effect(() => {
    if (inlineEdit && inlineInputEl) {
      inlineInputEl.focus();
      if (inlineEdit.type === 'rename') inlineInputEl.select();
    }
  });

  $effect(() => {
    if (newBlockInput && newBlockInputEl) {
      newBlockInputEl.focus();
    }
  });

  // ── Categorize files from the tree ────────────────────────────
  // The tree comes back as: [ { name: 'theme-name', type: 'directory', children: [...] } ]
  // We need to find the theme root and categorize its contents.

  let themeRoot = $derived.by(() => {
    // The tree IS the theme root (API returns theme directory contents directly)
    return { name: 'theme', type: 'directory', children: tree };
  });

  let themeName = $derived(themeRoot?.name ?? '');

  // Collect all files recursively from a node
  function collectFiles(node, basePath = '') {
    const files = [];
    if (!node?.children) return files;
    for (const child of node.children) {
      if (child.type === 'file') {
        files.push(child);
      } else if (child.type === 'directory') {
        files.push(...collectFiles(child, child.path));
      }
    }
    return files;
  }

  // Find a subdirectory in the theme root
  function findSubdir(name) {
    if (!themeRoot?.children) return null;
    return themeRoot.children.find(n => n.type === 'directory' && n.name === name) || null;
  }

  // ── Layout files ──────────────────────────────────────────────
  // Anything in partials/ is layout, plus header/footer/head at root.
  const LAYOUT_ROOT_FILES = ['header.html', 'footer.html', 'head.html'];

  let layoutFiles = $derived.by(() => {
    if (!themeRoot?.children) return [];
    const files = [];

    // Header/footer/head at theme root
    for (const child of themeRoot.children) {
      if (child.type === 'file' && LAYOUT_ROOT_FILES.includes(child.name.toLowerCase())) {
        files.push(child);
      }
    }

    // Everything inside partials/ (nav, mobile-menu, custom partials, etc.)
    const partials = findSubdir('partials');
    if (partials?.children) {
      for (const child of partials.children) {
        if (child.type === 'file' && /\.html?$/i.test(child.name)) {
          files.push(child);
        }
      }
    }

    return files.sort((a, b) => a.name.localeCompare(b.name));
  });

  // ── Block folders ─────────────────────────────────────────────
  let blockFolders = $derived.by(() => {
    const blocksDir = findSubdir('blocks');
    if (!blocksDir?.children) return [];
    return blocksDir.children
      .filter(n => n.type === 'directory')
      .sort((a, b) => a.name.localeCompare(b.name));
  });

  // ── Style files ───────────────────────────────────────────────
  // CSS anywhere in the theme — root, styles/, assets/, assets/css/, etc.
  let styleFiles = $derived.by(() => {
    if (!themeRoot?.children) return [];
    const files = [];
    function collectCss(nodes) {
      for (const n of nodes) {
        if (n.type === 'file' && /\.css$/i.test(n.name)) {
          files.push(n);
        } else if (n.type === 'directory' && n.children) {
          collectCss(n.children);
        }
      }
    }
    collectCss(themeRoot.children);
    return files.sort((a, b) => a.path.localeCompare(b.path));
  });

  // ── Template files ────────────────────────────────────────────
  // .html at theme root that isn't a partial, plus anything in templates/.
  let templateFiles = $derived.by(() => {
    if (!themeRoot?.children) return [];
    const files = [];

    for (const child of themeRoot.children) {
      if (child.type === 'file'
          && /\.html?$/i.test(child.name)
          && !LAYOUT_ROOT_FILES.includes(child.name.toLowerCase())) {
        files.push(child);
      }
    }

    const templatesDir = findSubdir('templates');
    if (templatesDir?.children) {
      for (const child of templatesDir.children) {
        if (child.type === 'file' && /\.html?$/i.test(child.name)) {
          files.push(child);
        }
      }
    }

    return files.sort((a, b) => a.name.localeCompare(b.name));
  });

  // ── Config files (root-level JSON, etc.) ─────────────────────
  let configFiles = $derived.by(() => {
    if (!themeRoot?.children) return [];
    return themeRoot.children
      .filter(n => n.type === 'file' && /\.(json|yml|yaml)$/i.test(n.name))
      .sort((a, b) => a.name.localeCompare(b.name));
  });

  // ── Section toggle ────────────────────────────────────────────
  function toggleSection(key) {
    sections = { ...sections, [key]: !sections[key] };
  }

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

  // ── New Block ─────────────────────────────────────────────────
  function startNewBlock(e) {
    e?.stopPropagation();
    newBlockInput = true;
    newBlockName = '';
  }

  function cancelNewBlock() {
    newBlockInput = false;
    newBlockName = '';
  }

  async function commitNewBlock() {
    const name = newBlockName.trim();
    if (!name) { cancelNewBlock(); return; }
    if (onCreateBlock) {
      await onCreateBlock(name);
    }
    cancelNewBlock();
  }

  function handleNewBlockKey(e) {
    if (e.key === 'Enter')  { e.preventDefault(); commitNewBlock(); }
    if (e.key === 'Escape') { e.preventDefault(); cancelNewBlock(); }
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
</script>

<svelte:window onclick={closeCtx} />

<div class="ft-root">
  <div class="ft-header">
    <span class="ft-dev-label">DEVELOPER MODE</span>
    <div class="ft-header-actions">
      <button class="ft-icon-btn" onclick={() => startCreateFile(themeName, null)} title="New File">
        <FilePlus size={13} />
      </button>
      <button class="ft-icon-btn" onclick={() => startCreateFolder(themeName, null)} title="New Folder">
        <FolderPlus size={13} />
      </button>
      <button class="ft-icon-btn" onclick={onRefresh} title="Refresh">
        <RefreshCw size={13} />
      </button>
    </div>
  </div>

  <div class="ft-nav">
    {#if loading}
      <div class="ft-loading"><div class="spinner"></div></div>
    {:else if tree.length === 0}
      <div class="ft-empty">
        <p>No theme files found.</p>
        <p style="font-size:11px;margin-top:4px;color:var(--dim)">Create a <code>themes/</code> directory.</p>
      </div>
    {:else}

      <!-- ── LAYOUT section ──────────────────────────────────── -->
      <div class="dev-section">
        <button class="dev-section-header" onclick={() => toggleSection('layout')}>
          <ChevronRight size={12} class="dev-chevron {sections.layout ? 'open' : ''}" />
          <Layout size={13} />
          <span>Layout</span>
        </button>
        {#if sections.layout}
          <div class="dev-section-body">
            {#each layoutFiles as file}
              {@render fileRow(file, 1)}
            {/each}
            {#if layoutFiles.length === 0}
              <div class="ft-section-empty">No layout files</div>
            {/if}
          </div>
        {/if}
      </div>

      <!-- ── BLOCKS section ──────────────────────────────────── -->
      <div class="dev-section">
        <button class="dev-section-header" onclick={() => toggleSection('blocks')}>
          <ChevronRight size={12} class="dev-chevron {sections.blocks ? 'open' : ''}" />
          <Blocks size={13} />
          <span>Blocks</span>
        </button>
        {#if sections.blocks}
          <div class="dev-section-body">
            {#each blockFolders as folder}
              <div>
                <button
                  class="ft-row ft-dir"
                  style="padding-left:24px"
                  onclick={() => toggleDir(folder.path)}
                  oncontextmenu={(e) => showCtx(e, folder)}
                  onmouseenter={() => hoverPath = folder.path}
                  onmouseleave={() => hoverPath = null}
                >
                  <ChevronRight size={10} class="ft-chevron-icon {expanded[folder.path] ? 'open' : ''}" />
                  <span class="ft-name ft-block-name">{folder.name}/</span>
                  {#if hoverPath === folder.path}
                    <span class="ft-actions" onclick={(e) => e.stopPropagation()}>
                      <span class="ft-act" title="Rename" onclick={(e) => startRename(folder, e)}>
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                      </span>
                      <span class="ft-act ft-act-danger" title="Delete" onclick={(e) => confirmDelete(folder, e)}>
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
                      </span>
                    </span>
                  {/if}
                </button>
                {#if expanded[folder.path] && folder.children}
                  {#each folder.children as child}
                    {#if child.type === 'file'}
                      {@render fileRow(child, 2)}
                    {/if}
                  {/each}
                {/if}
              </div>
            {/each}
            {#if blockFolders.length === 0}
              <div class="ft-section-empty">No blocks yet</div>
            {/if}

            <!-- New Block inline input -->
            {#if newBlockInput}
              <div class="ft-inline-row" style="padding-left:24px">
                <Plus size={12} style="opacity:.5;flex-shrink:0" />
                <input
                  class="ft-inline-input"
                  bind:this={newBlockInputEl}
                  bind:value={newBlockName}
                  onkeydown={handleNewBlockKey}
                  onblur={cancelNewBlock}
                  placeholder="block-name"
                />
              </div>
            {/if}

            <!-- + New Block button -->
            <button class="ft-new-block-btn" onclick={startNewBlock}>
              <Plus size={11} />
              <span>New Block</span>
            </button>
          </div>
        {/if}
      </div>

      <!-- ── STYLES section ──────────────────────────────────── -->
      <div class="dev-section">
        <button class="dev-section-header" onclick={() => toggleSection('styles')}>
          <ChevronRight size={12} class="dev-chevron {sections.styles ? 'open' : ''}" />
          <Paintbrush size={13} />
          <span>Styles</span>
        </button>
        {#if sections.styles}
          <div class="dev-section-body">
            {#each styleFiles as file}
              {@render fileRow(file, 1)}
            {/each}
            {#if styleFiles.length === 0}
              <div class="ft-section-empty">No stylesheets</div>
            {/if}
          </div>
        {/if}
      </div>

      <!-- ── TEMPLATES section ───────────────────────────────── -->
      <div class="dev-section">
        <button class="dev-section-header" onclick={() => toggleSection('templates')}>
          <ChevronRight size={12} class="dev-chevron {sections.templates ? 'open' : ''}" />
          <FileCode size={13} />
          <span>Templates</span>
        </button>
        {#if sections.templates}
          <div class="dev-section-body">
            {#each templateFiles as file}
              {@render fileRow(file, 1)}
            {/each}
            {#if templateFiles.length === 0}
              <div class="ft-section-empty">No templates</div>
            {/if}
          </div>
        {/if}
      </div>

      <!-- ── CONFIG section ────────────────────────────────── -->
      {#if configFiles.length > 0}
      <div class="dev-section">
        <button class="dev-section-header" onclick={() => toggleSection('config')}>
          <ChevronRight size={12} class="dev-chevron {sections.config ? 'open' : ''}" />
          <FileCode size={13} />
          <span>Config</span>
        </button>
        {#if sections.config}
          <div class="dev-section-body">
            {#each configFiles as file}
              {@render fileRow(file, 1)}
            {/each}
          </div>
        {/if}
      </div>
      {/if}

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

{#snippet fileRow(node, depth)}
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

  .ft-dev-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--purple, #7c3aed);
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
  .ft-icon-btn:hover { background: var(--hover); color: var(--text); }

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

  /* ── Dev Mode Sections ──────────────────────────────── */
  .dev-section {
    border-bottom: 1px solid var(--border-light);
  }

  .dev-section-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    width: 100%;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--dim);
    cursor: pointer;
    user-select: none;
    background: none;
    border: none;
    font-family: var(--font);
    text-align: left;
    transition: color var(--transition-fast);
  }
  .dev-section-header:hover { color: var(--sec); }

  .dev-section-body {
    padding-bottom: 4px;
  }

  /* Lucide chevron rotation in section headers */
  .dev-section-header :global(.dev-chevron) {
    flex-shrink: 0;
    transition: transform .15s;
  }
  .dev-section-header :global(.dev-chevron.open) {
    transform: rotate(90deg);
  }

  .ft-section-empty {
    padding: 6px 16px 6px 40px;
    font-size: 11px;
    color: var(--dim);
    font-style: italic;
  }

  /* ── Tree rows ──────────────────────────────────────── */
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
    font-family: var(--font);
    white-space: nowrap;
    overflow: hidden;
    transition: background var(--transition-fast);
    position: relative;
  }
  .ft-row:hover { background: var(--hover); }
  .ft-file.active {
    background: var(--forest-light);
    color: var(--forest);
    font-weight: 500;
  }

  /* Chevron icon in block folder rows */
  .ft-row :global(.ft-chevron-icon) {
    flex-shrink: 0;
    transition: transform .15s;
    opacity: .45;
  }
  .ft-row :global(.ft-chevron-icon.open) {
    transform: rotate(90deg);
  }

  .ft-block-name {
    color: var(--text);
    font-weight: 500;
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
  .ft-act:hover { background: var(--hover); color: var(--text); }
  .ft-act-danger:hover { color: #ef4444; }

  /* + New Block button */
  .ft-new-block-btn {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 5px 16px 5px 24px;
    width: 100%;
    background: none;
    border: none;
    font-size: 11px;
    font-family: var(--font);
    color: var(--dim);
    cursor: pointer;
    transition: color var(--transition-fast);
  }
  .ft-new-block-btn:hover { color: var(--purple, #7c3aed); }

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
    font-family: var(--font);
    color: var(--text);
    background: var(--hover);
    border: 1px solid var(--forest);
    border-radius: 4px;
    outline: none;
  }

  /* Context menu */
  .ft-ctx {
    position: fixed;
    z-index: 1000;
    min-width: 160px;
    background: var(--bg);
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
    font-family: var(--font);
    color: var(--text);
    text-align: left;
    cursor: pointer;
    transition: background var(--transition-fast);
  }
  .ft-ctx-item:hover { background: var(--hover); }
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
    background: var(--bg);
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
    color: var(--sec);
    background: var(--raised);
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
