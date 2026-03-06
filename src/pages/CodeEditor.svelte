<script>
  import { onMount, onDestroy, untrack } from 'svelte';
  import { code as codeApi } from '$lib/api.js';
  import { addToast, darkMode } from '$lib/stores.js';
  import { setOutpostContext, outpostCompletionSource } from '$lib/outpost-completions.js';
  import { outpostHighlight } from '$lib/outpost-highlight.js';
  import FileTree    from '$components/ce/FileTree.svelte';
  import EditorTabs  from '$components/ce/EditorTabs.svelte';
  import StatusBar   from '$components/ce/StatusBar.svelte';
  import FindInFiles from '$components/ce/FindInFiles.svelte';

  // ── Tree ─────────────────────────────────────────────────────────
  let tree        = $state([]);
  let treeLoading = $state(true);

  // ── Tabs ─────────────────────────────────────────────────────────
  // Each tab: { path, name, content, originalContent, editorState: null, size: 0 }
  let tabs            = $state([]);
  let activeTabIndex  = $state(-1);
  let saving          = $state(false);

  let activeTab = $derived(tabs[activeTabIndex] ?? null);
  let isDirty   = $derived(!!activeTab && activeTab.content !== activeTab.originalContent);

  // ── Editor ───────────────────────────────────────────────────────
  let editorContainer = $state(null);
  let editorView      = $state(null);
  let cmModules       = $state(null);
  let langCompartment = null;
  let themeCompartment = null;
  let sharedExts       = null; // computed once after cmModules loads

  // ── Status bar ───────────────────────────────────────────────────
  let cursorLine = $state(1);
  let cursorCol  = $state(1);

  // ── Panels ───────────────────────────────────────────────────────
  let showFindInFiles    = $state(false);
  let showCmdPalette     = $state(false);
  let cmdQuery           = $state('');
  let cmdSelectedIndex   = $state(0);

  let cmdResults = $derived.by(() => {
    if (!cmdQuery.trim()) return getAllFiles(tree);
    const q = cmdQuery.toLowerCase();
    return getAllFiles(tree).filter(f => f.path.toLowerCase().includes(q)).slice(0, 30);
  });

  let isDark = $derived($darkMode);

  // ── Lang label ───────────────────────────────────────────────────
  let langLabel = $derived.by(() => {
    if (!activeTab) return '';
    const ext = activeTab.path.split('.').pop().toLowerCase();
    return { html:'HTML', htm:'HTML', css:'CSS', js:'JS', json:'JSON', php:'PHP', md:'MD', svg:'SVG', yml:'YAML', yaml:'YAML' }[ext] || ext.toUpperCase();
  });

  // ── Lifecycle ────────────────────────────────────────────────────
  onMount(() => {
    loadTree();
    loadCM();
    loadOutpostContext();
  });

  onDestroy(() => {
    if (editorView) editorView.destroy();
  });

  // Re-init editor after container is mounted if needed
  $effect(() => {
    if (cmModules && editorContainer && activeTabIndex >= 0 && !editorView) {
      untrack(() => mountEditor());
    }
  });

  // Dark mode: reconfigure theme compartment without destroying the view
  $effect(() => {
    const dark = isDark;
    untrack(() => {
      if (editorView && themeCompartment && cmModules) {
        editorView.dispatch({
          effects: themeCompartment.reconfigure(themeExts(dark)),
        });
        // Save updated state back to current tab
        if (activeTabIndex >= 0) {
          tabs[activeTabIndex] = { ...tabs[activeTabIndex], editorState: editorView.state };
        }
      }
    });
  });

  // ── CodeMirror init ──────────────────────────────────────────────
  async function loadCM() {
    try {
      const [cm, cmState, cmView, cmLangHtml, cmLangCss, cmLangJs, cmLangPhp, cmTheme, cmLang] = await Promise.all([
        import('codemirror'),
        import('@codemirror/state'),
        import('@codemirror/view'),
        import('@codemirror/lang-html'),
        import('@codemirror/lang-css'),
        import('@codemirror/lang-javascript'),
        import('@codemirror/lang-php'),
        import('@codemirror/theme-one-dark'),
        import('@codemirror/language'),
      ]);

      cmModules = { cm, cmState, cmView, cmLangHtml, cmLangCss, cmLangJs, cmLangPhp, cmTheme, cmLang };

      // Create compartments
      langCompartment  = new cmState.Compartment();
      themeCompartment = new cmState.Compartment();

      // Build shared extension set (sans language)
      sharedExts = buildSharedExts();

      // Mount editor if a tab is already waiting
      if (activeTabIndex >= 0 && editorContainer && !editorView) {
        mountEditor();
      }
    } catch (e) {
      console.error('CodeMirror load failed:', e);
    }
  }

  async function loadOutpostContext() {
    try {
      const ctx = await codeApi.context();
      setOutpostContext(ctx);
    } catch (_) {}
  }

  // ── Theme helpers ────────────────────────────────────────────────
  function themeExts(dark) {
    const { cmView, cmTheme, cmLang } = cmModules;
    if (dark) {
      return [
        cmView.EditorView.theme({
          '&':                        { background: '#1E1C1A', color: '#F5F3EF', fontSize: '13px', fontFamily: 'SF Mono,SFMono-Regular,Cascadia Code,Consolas,monospace', height: '100%' },
          '.cm-scroller':             { overflow: 'auto' },
          '.cm-content':              { padding: '16px 20px', lineHeight: '1.7', caretColor: '#F5F3EF' },
          '.cm-gutters':              { background: '#1E1C1A', borderRight: '1px solid rgba(255,255,255,.06)', color: '#4a4744', minWidth: '40px' },
          '.cm-lineNumbers .cm-gutterElement': { padding: '0 12px 0 8px' },
          '&.cm-focused':             { outline: 'none' },
          '&.cm-focused .cm-cursor': { borderLeftColor: '#F5F3EF' },
          '.cm-activeLine':           { background: 'rgba(255,255,255,.025)' },
          '.cm-activeLineGutter':     { background: 'rgba(255,255,255,.025)' },
          '.cm-selectionBackground':  { background: 'rgba(125,155,138,.3) !important' },
        }, true),
        cmLang.syntaxHighlighting(cmTheme.oneDarkHighlightStyle),
      ];
    }
    return [
      cmView.EditorView.theme({
        '&':                        { background: '#FAF8F5', color: '#2C2A27', fontSize: '13px', fontFamily: 'SF Mono,SFMono-Regular,Cascadia Code,Consolas,monospace', height: '100%' },
        '.cm-scroller':             { overflow: 'auto' },
        '.cm-content':              { padding: '16px 20px', lineHeight: '1.7', caretColor: '#2C2A27' },
        '.cm-gutters':              { background: '#F0EDE8', borderRight: '1px solid rgba(0,0,0,.07)', color: '#9A9590', minWidth: '40px' },
        '.cm-lineNumbers .cm-gutterElement': { padding: '0 12px 0 8px' },
        '&.cm-focused':             { outline: 'none' },
        '&.cm-focused .cm-cursor': { borderLeftColor: '#2C2A27' },
        '.cm-activeLine':           { background: 'rgba(0,0,0,.025)' },
        '.cm-activeLineGutter':     { background: 'rgba(0,0,0,.025)' },
        '.cm-selectionBackground':  { background: 'rgba(125,155,138,.25) !important' },
      }, false),
    ];
  }

  function getLang(path) {
    if (!cmModules) return [];
    const ext = path.split('.').pop().toLowerCase();
    const { cmView, cmState, cmLangHtml, cmLangCss, cmLangJs, cmLangPhp } = cmModules;
    switch (ext) {
      case 'html': case 'htm': {
        // Add Outpost completions + template tag highlighting
        const htmlExt = cmLangHtml.html();
        const outpostExt = cmLangHtml.htmlLanguage.data.of({ autocomplete: outpostCompletionSource });
        const highlightExt = outpostHighlight(cmView, cmState);
        return [htmlExt, outpostExt, highlightExt];
      }
      case 'css':              return [cmLangCss.css()];
      case 'js': case 'json': return [cmLangJs.javascript()];
      case 'php':              return [cmLangPhp.php()];
      default:                 return [];
    }
  }

  function buildSharedExts() {
    const { cm, cmState, cmView } = cmModules;
    return [
      cm.basicSetup,
      cmState.EditorState.tabSize.of(2),
      themeCompartment.of(themeExts(isDark)),
      cmView.EditorView.updateListener.of((update) => {
        if (update.docChanged && activeTabIndex >= 0) {
          tabs[activeTabIndex] = { ...tabs[activeTabIndex], content: update.state.doc.toString() };
        }
        if (update.selectionSet || update.docChanged) {
          const sel  = update.state.selection.main;
          const line = update.state.doc.lineAt(sel.head);
          cursorLine = line.number;
          cursorCol  = sel.head - line.from + 1;
        }
      }),
      cmView.keymap.of([
        { key: 'Mod-s',       run: () => { saveActiveTab(); return true; } },
        { key: 'Mod-w',       run: () => { closeTab(activeTabIndex); return true; } },
        { key: 'Mod-Shift-]', run: () => { nextTab(); return true; } },
        { key: 'Mod-Shift-[', run: () => { prevTab(); return true; } },
        { key: 'Mod-p',       run: () => { openCmdPalette(); return true; } },
        { key: 'Mod-Shift-f', run: () => { showFindInFiles = true; return true; } },
      ]),
    ];
  }

  function createTabState(content, path) {
    const { cmState } = cmModules;
    return cmState.EditorState.create({
      doc: content,
      extensions: [...sharedExts, langCompartment.of(getLang(path))],
    });
  }

  function mountEditor() {
    if (!cmModules || !editorContainer || editorView) return;
    const tab = tabs[activeTabIndex];
    if (!tab) return;
    const { cmView } = cmModules;
    const state = tab.editorState || createTabState(tab.content, tab.path);
    editorView = new cmView.EditorView({ state, parent: editorContainer });
    if (!tab.editorState) {
      tabs[activeTabIndex] = { ...tabs[activeTabIndex], editorState: state };
    }
    editorView.focus();
  }

  // ── Tab management ───────────────────────────────────────────────
  async function openFile(node, jumpLine = null) {
    const existingIdx = tabs.findIndex(t => t.path === node.path);
    if (existingIdx >= 0) {
      switchToTab(existingIdx);
      if (jumpLine && editorView) jumpToLine(jumpLine);
      return;
    }

    // Auto-close oldest clean tab if at limit
    if (tabs.length >= 20) {
      const firstClean = tabs.findIndex(t => t.content === t.originalContent);
      if (firstClean >= 0) {
        tabs.splice(firstClean, 1);
        if (activeTabIndex >= firstClean) activeTabIndex = Math.max(0, activeTabIndex - 1);
      }
    }

    try {
      const data  = await codeApi.read(node.path);
      const newTab = { path: node.path, name: node.name, content: data.content, originalContent: data.content, editorState: null, size: data.size || 0 };
      tabs = [...tabs, newTab];
      switchToTab(tabs.length - 1);
      if (jumpLine) setTimeout(() => jumpToLine(jumpLine), 50);
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function switchToTab(newIdx) {
    if (newIdx === activeTabIndex && editorView) return;

    // Save current editor state to current tab
    if (activeTabIndex >= 0 && activeTabIndex < tabs.length && editorView) {
      tabs[activeTabIndex] = { ...tabs[activeTabIndex], editorState: editorView.state };
    }

    activeTabIndex = newIdx;
    const tab = tabs[newIdx];
    if (!tab || !cmModules) return;

    if (!editorView && editorContainer) {
      mountEditor();
      return;
    }

    if (editorView) {
      const state = tab.editorState || createTabState(tab.content, tab.path);
      editorView.setState(state);
      // Always apply current theme + language (state may have been saved with old theme)
      editorView.dispatch({
        effects: [
          themeCompartment.reconfigure(themeExts(isDark)),
          langCompartment.reconfigure(getLang(tab.path)),
        ],
      });
      if (!tab.editorState) {
        tabs[newIdx] = { ...tabs[newIdx], editorState: editorView.state };
      }
      editorView.focus();
    }
  }

  async function closeTab(index) {
    const tab = tabs[index];
    if (!tab) return;

    if (tab.content !== tab.originalContent) {
      if (!confirm(`Discard unsaved changes to "${tab.name}"?`)) return;
    }

    const newTabs = tabs.filter((_, i) => i !== index);
    tabs = newTabs;

    if (newTabs.length === 0) {
      activeTabIndex = -1;
      return;
    }

    const newIdx = Math.min(index, newTabs.length - 1);
    activeTabIndex = -1; // force switchToTab to refresh
    switchToTab(newIdx);
  }

  function nextTab() {
    if (tabs.length < 2) return;
    switchToTab((activeTabIndex + 1) % tabs.length);
  }

  function prevTab() {
    if (tabs.length < 2) return;
    switchToTab((activeTabIndex - 1 + tabs.length) % tabs.length);
  }

  async function saveActiveTab() {
    if (!activeTab || saving) return;
    saving = true;
    try {
      await codeApi.write(activeTab.path, activeTab.content);
      tabs[activeTabIndex] = { ...tabs[activeTabIndex], originalContent: activeTab.content };
      addToast('File saved', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      saving = false;
    }
  }

  function jumpToLine(lineNum) {
    if (!editorView) return;
    const state = editorView.state;
    const line  = state.doc.line(Math.min(lineNum, state.doc.lines));
    editorView.dispatch({ selection: { anchor: line.from }, scrollIntoView: true });
    editorView.focus();
  }

  // ── File operations ──────────────────────────────────────────────
  async function loadTree() {
    treeLoading = true;
    try {
      const data = await codeApi.files();
      tree = data.tree || [];
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      treeLoading = false;
    }
  }

  async function handleCreateFile(path) {
    try {
      await codeApi.create(path, 'file');
      await loadTree();
      openFile({ path, name: path.split('/').pop(), type: 'file' });
    } catch (err) { addToast(err.message, 'error'); }
  }

  async function handleCreateFolder(path) {
    try {
      await codeApi.create(path, 'folder');
      await loadTree();
    } catch (err) { addToast(err.message, 'error'); }
  }

  async function handleRenameItem(oldPath, newPath) {
    try {
      await codeApi.rename(oldPath, newPath);
      await loadTree();
      // Update open tabs with old path
      const idx = tabs.findIndex(t => t.path === oldPath);
      if (idx >= 0) {
        const newName = newPath.split('/').pop();
        tabs[idx] = { ...tabs[idx], path: newPath, name: newName, editorState: null };
        if (idx === activeTabIndex && editorView && cmModules) {
          editorView.dispatch({ effects: langCompartment.reconfigure(getLang(newPath)) });
        }
      }
    } catch (err) { addToast(err.message, 'error'); }
  }

  async function handleDeleteItem(path) {
    try {
      await codeApi.delete(path);
      await loadTree();
      // Close tabs matching deleted path/prefix
      const before = tabs.length;
      tabs = tabs.filter(t => t.path !== path && !t.path.startsWith(path + '/'));
      if (tabs.length !== before) {
        activeTabIndex = Math.max(0, Math.min(activeTabIndex, tabs.length - 1));
        if (tabs.length === 0) activeTabIndex = -1;
      }
    } catch (err) { addToast(err.message, 'error'); }
  }

  // ── Command palette ──────────────────────────────────────────────
  function getAllFiles(nodes, result = []) {
    for (const node of nodes) {
      if (node.type === 'file') result.push(node);
      else if (node.children) getAllFiles(node.children, result);
    }
    return result;
  }

  function openCmdPalette() {
    showCmdPalette = true;
    cmdQuery       = '';
    cmdSelectedIndex = 0;
  }

  function closeCmdPalette() { showCmdPalette = false; }

  function cmdSelect(node) {
    closeCmdPalette();
    openFile(node);
  }

  let cmdInputEl = $state(null);
  $effect(() => { if (showCmdPalette && cmdInputEl) cmdInputEl.focus(); });

  function handleCmdKey(e) {
    if (e.key === 'Escape') { closeCmdPalette(); return; }
    if (e.key === 'ArrowDown') { e.preventDefault(); cmdSelectedIndex = Math.min(cmdSelectedIndex + 1, cmdResults.length - 1); }
    if (e.key === 'ArrowUp')   { e.preventDefault(); cmdSelectedIndex = Math.max(cmdSelectedIndex - 1, 0); }
    if (e.key === 'Enter' && cmdResults[cmdSelectedIndex]) { cmdSelect(cmdResults[cmdSelectedIndex]); }
  }

  // Reset selected on query change
  $effect(() => { cmdQuery; cmdSelectedIndex = 0; });

  // ── Window shortcuts ─────────────────────────────────────────────
  function handleWindowKeydown(e) {
    const mod = e.metaKey || e.ctrlKey;
    if (mod && e.key === 'p') { e.preventDefault(); openCmdPalette(); return; }
    if (mod && e.shiftKey && (e.key === 'F' || e.key === 'f')) { e.preventDefault(); showFindInFiles = !showFindInFiles; return; }
    if (e.key === 'Escape') {
      if (showCmdPalette) { closeCmdPalette(); return; }
      if (showFindInFiles) { showFindInFiles = false; return; }
    }
  }

  function langBadgeClass(name) {
    const ext = name.split('.').pop().toLowerCase();
    return 'ce-badge-' + ext;
  }

  function fileIcon(name) {
    const ext = name.split('.').pop().toLowerCase();
    return { php:'P', html:'H', htm:'H', css:'C', js:'J', json:'{}', svg:'S', md:'M', yml:'Y', yaml:'Y', xml:'X', txt:'T' }[ext] || 'F';
  }
</script>

<svelte:window onkeydown={handleWindowKeydown} />

<div class="ce-root">

  <!-- Column 1: File Tree -->
  <nav class="ce-col1">
    <FileTree
      {tree}
      activePath={activeTab?.path ?? ''}
      loading={treeLoading}
      onOpenFile={openFile}
      onRefresh={loadTree}
      onCreateFile={handleCreateFile}
      onCreateFolder={handleCreateFolder}
      onRenameItem={handleRenameItem}
      onDeleteItem={handleDeleteItem}
    />
  </nav>

  <!-- Column 2: Editor area -->
  <div class="ce-col2" class:dark={isDark}>

    <!-- Tab bar + toolbar -->
    <div class="ce-top">
      <div class="ce-tabs-wrap">
        {#if tabs.length > 0}
          <EditorTabs {tabs} activeIndex={activeTabIndex} onSwitch={switchToTab} onClose={closeTab} />
        {/if}
      </div>
      <div class="ce-toolbar">
        <button class="ce-tool-btn" onclick={() => { showFindInFiles = !showFindInFiles; }} title="Find in Files (⌘⇧F)">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </button>
        <button class="ce-tool-btn" onclick={openCmdPalette} title="Go to File (⌘P)">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 17H2a3 3 0 000 6h20a3 3 0 000-6zm0-8H2a3 3 0 000 6h20a3 3 0 000-6zm0-8H2a3 3 0 000 6h20a3 3 0 000-6z"/></svg>
        </button>
        {#if activeTab}
          <div class="ce-tool-sep"></div>
          {#if langLabel}
            <span class="ce-lang-badge">{langLabel}</span>
          {/if}
          <button class="ce-tool-btn" onclick={saveActiveTab} disabled={saving || !isDirty} title="Save (⌘S)">
            {saving ? 'Saving…' : 'Save'}
          </button>
        {/if}
      </div>
    </div>

    <!-- Editor wrap — always in DOM so editorView.dom stays attached -->
    <div class="ce-editor-wrap" class:hidden={!activeTab} bind:this={editorContainer}></div>

    <!-- Empty state shown when no file is open -->
    {#if !activeTab}
      <div class="ce-empty">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
        <p>Select a file to edit</p>
        <p class="ce-empty-hint">⌘P to search files · ⌘⇧F to search contents</p>
      </div>
    {/if}

    <!-- Find in Files panel -->
    <FindInFiles
      visible={showFindInFiles}
      onClose={() => showFindInFiles = false}
      onOpenFile={openFile}
    />

    <!-- Status bar -->
    {#if activeTab}
      <StatusBar
        line={cursorLine}
        col={cursorCol}
        language={langLabel}
        filepath={activeTab.path}
        fileSize={activeTab.size}
      />
    {/if}

  </div>
</div>

<!-- Command Palette overlay -->
{#if showCmdPalette}
  <div class="cp-overlay" onclick={closeCmdPalette}>
    <div class="cp-box" onclick={(e) => e.stopPropagation()}>
      <div class="cp-search-row">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;opacity:.4"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input
          class="cp-input"
          bind:this={cmdInputEl}
          bind:value={cmdQuery}
          onkeydown={handleCmdKey}
          placeholder="Go to file…"
          autocomplete="off"
        />
      </div>
      <div class="cp-results">
        {#if cmdResults.length === 0}
          <div class="cp-empty">No files found</div>
        {:else}
          {#each cmdResults as node, i}
            <button
              class="cp-item"
              class:selected={i === cmdSelectedIndex}
              onclick={() => cmdSelect(node)}
              onmouseenter={() => cmdSelectedIndex = i}
            >
              <span class="cp-item-badge {langBadgeClass(node.name)}">{fileIcon(node.name)}</span>
              <span class="cp-item-name">{node.name}</span>
              <span class="cp-item-path">{node.path}</span>
            </button>
          {/each}
        {/if}
      </div>
      <div class="cp-footer">
        <span>↑↓ navigate</span>
        <span>↵ open</span>
        <span>Esc close</span>
      </div>
    </div>
  </div>
{/if}

<style>
  .ce-root {
    display: flex;
    height: calc(100vh - 60px);
    overflow: hidden;
    background: var(--bg-primary);
  }

  /* ── Column 1: File Tree ─────────────────────────────── */
  .ce-col1 {
    width: 226px;
    flex-shrink: 0;
    border-right: 1px solid var(--border-light);
    background: var(--bg-primary);
    overflow: hidden;
  }

  /* ── Column 2: Editor ────────────────────────────────── */
  .ce-col2 {
    --ce-bg:              var(--bg-secondary);
    --ce-bar-border:      var(--border-light);
    --ce-filepath:        var(--text-secondary);
    --ce-muted:           var(--text-muted);
    --ce-text:            var(--text);
    --ce-btn-hover-bg:    var(--bg-hover);
    --ce-btn-hover-color: var(--text);
    --ce-badge-border:    var(--border);

    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: var(--ce-bg);
  }

  .ce-col2.dark {
    --ce-bg:              var(--code-bg);
    --ce-bar-border:      rgba(255,255,255,.12);
    --ce-filepath:        rgba(255,255,255,.5);
    --ce-muted:           var(--code-comment);
    --ce-text:            var(--code-text);
    --ce-btn-hover-bg:    rgba(255,255,255,.07);
    --ce-btn-hover-color: var(--code-text);
    --ce-badge-border:    rgba(255,255,255,.1);
  }

  /* ── Top bar: tabs + toolbar ─────────────────────────── */
  .ce-top {
    display: flex;
    align-items: stretch;
    height: 36px;
    flex-shrink: 0;
    border-bottom: 1px solid var(--ce-bar-border);
    overflow: hidden;
  }

  .ce-tabs-wrap {
    flex: 1;
    min-width: 0;
    overflow: hidden;
  }

  .ce-toolbar {
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 0 10px;
    flex-shrink: 0;
    border-left: 1px solid var(--ce-bar-border);
  }

  .ce-tool-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 8px;
    background: none;
    border: none;
    border-radius: 5px;
    font-family: var(--font-sans);
    font-size: 12px;
    color: var(--ce-muted);
    cursor: pointer;
    transition: background var(--transition-fast), color var(--transition-fast);
  }
  .ce-tool-btn:hover  { background: var(--ce-btn-hover-bg); color: var(--ce-btn-hover-color); }
  .ce-tool-btn:disabled { opacity: .4; cursor: default; }

  .ce-tool-sep {
    width: 1px;
    height: 18px;
    background: var(--ce-bar-border);
    margin: 0 4px;
  }

  .ce-lang-badge {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--ce-muted);
    padding: 2px 6px;
    border: 1px solid var(--ce-badge-border);
    border-radius: 4px;
  }

  /* ── Editor / empty ──────────────────────────────────── */
  .ce-editor-wrap {
    flex: 1;
    overflow: hidden;
    min-height: 0;
  }
  .ce-editor-wrap.hidden { display: none; }

  .ce-editor-wrap :global(.cm-editor) { height: 100%; }
  .ce-editor-wrap :global(.cm-scroller) { overflow: auto; }

  /* CM search panel styling */
  .ce-editor-wrap :global(.cm-search) {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: var(--ce-bg);
    border-top: 1px solid var(--ce-bar-border);
    flex-wrap: wrap;
  }
  .ce-editor-wrap :global(.cm-search input) {
    font-size: 12px;
    padding: 3px 7px;
    border-radius: 4px;
    border: 1px solid var(--ce-bar-border);
    background: var(--ce-btn-hover-bg);
    color: var(--ce-text);
    outline: none;
  }
  .ce-editor-wrap :global(.cm-search button) {
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 4px;
    border: 1px solid var(--ce-bar-border);
    background: none;
    color: var(--ce-filepath);
    cursor: pointer;
  }
  .ce-editor-wrap :global(.cm-search button:hover) {
    background: var(--ce-btn-hover-bg);
    color: var(--ce-text);
  }

  /* Outpost template tag highlighting — colorblind-safe palette */
  /* Dark: teal + blue + gray — all distinct from one-dark's red/orange/green HTML colors */
  .ce-editor-wrap :global(.cm-outpost-output)  { color: #56d4c8; background: rgba(86,212,200,.08); border-radius: 2px; }
  .ce-editor-wrap :global(.cm-outpost-block)   { color: #6cb6ff; background: rgba(108,182,255,.08); border-radius: 2px; }
  .ce-editor-wrap :global(.cm-outpost-comment) { color: #7a7e85; background: rgba(122,126,133,.06); border-radius: 2px; font-style: italic; }

  /* Light mode */
  .ce-col2:not(.dark) .ce-editor-wrap :global(.cm-outpost-output)  { color: #0e7c6b; background: rgba(14,124,107,.06); border-radius: 2px; }
  .ce-col2:not(.dark) .ce-editor-wrap :global(.cm-outpost-block)   { color: #1a56db; background: rgba(26,86,219,.06); border-radius: 2px; }
  .ce-col2:not(.dark) .ce-editor-wrap :global(.cm-outpost-comment) { color: #9ca3af; background: rgba(156,163,175,.06); border-radius: 2px; font-style: italic; }

  .ce-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    color: var(--ce-muted);
    font-size: 13px;
  }
  .ce-empty-hint {
    font-size: 11px;
    opacity: .6;
    margin: 0;
  }

  /* ── Command palette ─────────────────────────────────── */
  .cp-overlay {
    position: fixed;
    inset: 0;
    z-index: 600;
    background: rgba(0,0,0,.45);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding-top: 80px;
  }

  .cp-box {
    width: 540px;
    max-height: 420px;
    background: var(--bg-primary);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: 0 12px 48px rgba(0,0,0,.25);
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }

  .cp-search-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-light);
    flex-shrink: 0;
  }

  .cp-input {
    flex: 1;
    background: none;
    border: none;
    outline: none;
    font-size: 14px;
    font-family: var(--font-sans);
    color: var(--text);
  }
  .cp-input::placeholder { color: var(--text-muted); }

  .cp-results {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
  }

  .cp-empty {
    padding: 24px;
    text-align: center;
    font-size: 13px;
    color: var(--text-muted);
  }

  .cp-item {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 7px 16px;
    background: none;
    border: none;
    font-family: var(--font-sans);
    font-size: 13px;
    color: var(--text);
    text-align: left;
    cursor: pointer;
    transition: background var(--transition-fast);
  }
  .cp-item.selected { background: var(--bg-hover); }

  .cp-item-badge {
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
  .ce-badge-php  { background: #777bb3; }
  .ce-badge-html, .ce-badge-htm { background: #e34c26; }
  .ce-badge-css  { background: #1572b6; }
  .ce-badge-js   { background: #f7df1e; color: #000; }
  .ce-badge-json { background: #f7df1e; color: #000; }
  .ce-badge-svg  { background: #ffb13b; color: #000; }
  .ce-badge-md   { background: #083fa1; }
  .ce-badge-yml, .ce-badge-yaml { background: #cb171e; }

  .cp-item-name {
    font-weight: 500;
    white-space: nowrap;
  }

  .cp-item-path {
    font-size: 11px;
    color: var(--text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
    min-width: 0;
  }

  .cp-footer {
    display: flex;
    gap: 16px;
    padding: 8px 16px;
    border-top: 1px solid var(--border-light);
    flex-shrink: 0;
    font-size: 11px;
    color: var(--text-muted);
  }

  @media (max-width: 768px) {
    .ce-root {
      height: calc(100vh - 60px - var(--mobile-nav-height) - env(safe-area-inset-bottom, 0px));
    }

    .ce-col1 {
      display: none;
    }

    .cp-box {
      width: calc(100vw - 32px);
      max-width: 540px;
    }

    .cp-overlay {
      padding-top: 48px;
    }
  }
</style>
